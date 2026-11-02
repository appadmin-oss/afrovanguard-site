<?php
/**
 * lib/PaymentWebhook.php — one front door for the gateway's webhook.
 *
 * A payment gateway posts to ONE address. This site had two money handlers,
 * each of which understood only its own kind:
 *
 *   /academy/pay.php        courses and dues — and it treated anything with a
 *                           plan on it as a dues renewal, so every recurring
 *                           DONATION would have bought somebody a month of
 *                           membership.
 *   /process-donation.php   donations — and it filed anything that was not an
 *                           NGV fee as one, so a dues or course charge landed
 *                           on a campaign total, could appear on the public
 *                           donor wall, and left the learner unenrolled.
 *
 * Whichever address was in the Paystack dashboard, the other kinds were
 * mis-recorded or lost, and which one it was is not visible from the code.
 * Both addresses now hand the event here, and the KIND decides who owns it:
 *
 *   ngv           metadata.ngv_member — a participant's fee → NgvLedger
 *   lms           a local `payments` row for the reference → LmsRepository
 *                 (a course, or dues bought from the portal: the row was
 *                 written when the checkout started, including the FIRST
 *                 charge of a dues subscription)
 *   dues_renewal  a later charge on AV_DUES_PLAN_CODE → a payments row and a
 *                 month, through the same finalizePayment as everything else
 *   donation      a charge on an appeal's plan, or anything else → Donations
 *   subscription  subscription.* events → Appeals
 *
 * The order matters and is not arbitrary: the reference is the strongest
 * signal (we created the row), the dues plan code is a positive signal rather
 * than "has a plan", and the donation is the FALLBACK — money whose owner we
 * cannot name is recorded as given, never granted as bought.
 */
declare(strict_types=1);

final class PaymentWebhook
{
    public const NGV = 'ngv';
    public const LMS = 'lms';
    public const DUES_RENEWAL = 'dues_renewal';
    public const DONATION = 'donation';
    public const SUBSCRIPTION = 'subscription';
    public const IGNORED = 'ignored';

    /**
     * The dues subscription's Paystack plan, if this deployment has one.
     * The constant where bootstrap promoted it, the environment otherwise —
     * one deployment setting, readable without a constant having been fixed
     * at boot (which is also what lets a test set one).
     */
    public static function duesPlanCode(): string
    {
        if (defined('AV_DUES_PLAN_CODE')) return trim((string) AV_DUES_PLAN_CODE);
        $v = getenv('AV_DUES_PLAN_CODE');
        return $v === false ? '' : trim((string) $v);
    }

    /**
     * Who owns this event. PURE: the event and the three facts only the
     * database knows, in; a kind and the reason for it, out. The reason is
     * logged, so "why did this charge go there" has an answer without a
     * reproduction.
     *
     * $facts: has_payment_row (bool), appeal_plan (string slug or ''),
     *         dues_member (int user id or 0)
     */
    public static function classify(array $event, array $facts): array
    {
        $name = (string) ($event['event'] ?? '');
        if (str_starts_with($name, 'subscription.')) return ['kind' => self::SUBSCRIPTION, 'why' => $name];
        if ($name !== 'charge.success') return ['kind' => self::IGNORED, 'why' => 'event "' . $name . '" is not one this site acts on'];

        $tx  = is_array($event['data'] ?? null) ? $event['data'] : [];
        $ref = (string) ($tx['reference'] ?? '');
        if ($ref === '') return ['kind' => self::IGNORED, 'why' => 'charge.success with no reference'];

        $meta = is_array($tx['metadata'] ?? null) ? $tx['metadata'] : [];
        if ((int) ($meta['ngv_member'] ?? 0) > 0) return ['kind' => self::NGV, 'why' => 'metadata.ngv_member'];
        if (!empty($facts['has_payment_row'])) return ['kind' => self::LMS, 'why' => 'a payments row for ' . $ref];

        $plan = Donations::planCode($tx);
        $dues = self::duesPlanCode();
        if ($plan !== '' && $dues !== '' && hash_equals($dues, $plan)) {
            return ['kind' => self::DUES_RENEWAL, 'why' => 'the dues plan, and no payments row — a renewal'];
        }
        if ((string) ($facts['appeal_plan'] ?? '') !== '') {
            return ['kind' => self::DONATION, 'why' => 'a recurring gift on appeal "' . $facts['appeal_plan'] . '"'];
        }
        return ['kind' => self::DONATION, 'why' => 'no other owner claims it'];
    }

    /** The three facts, read from the database. */
    public static function facts(array $event): array
    {
        $tx  = is_array($event['data'] ?? null) ? $event['data'] : [];
        $ref = (string) ($tx['reference'] ?? '');
        $has = false;
        if ($ref !== '') {
            try {
                $st = Database::pdo()->prepare('SELECT 1 FROM payments WHERE reference = ?');
                $st->execute([$ref]);
                $has = (bool) $st->fetchColumn();
            } catch (Throwable $e) { error_log('[webhook] payments lookup: ' . $e->getMessage()); }
        }
        return [
            'has_payment_row' => $has,
            'appeal_plan'     => Donations::appealForPlan(Donations::planCode($tx)),
        ];
    }

    /**
     * Verify, classify, and hand the charge to whoever owns it.
     *
     * Returns ['ok', 'kind', 'why', 'status'] where `status` is the HTTP code
     * the caller should answer. 200 means "we have it, stop retrying"; 500 is
     * for a failure a retry could fix, because answering 200 to a failure told
     * Paystack it was delivered and the payment went silently missing.
     */
    public static function handle(array $event): array
    {
        $c = self::classify($event, self::facts($event));
        $kind = $c['kind'];
        $tx   = is_array($event['data'] ?? null) ? $event['data'] : [];
        $ref  = (string) ($tx['reference'] ?? '');
        error_log('[webhook] ' . $ref . ' → ' . $kind . ' (' . $c['why'] . ')');

        try {
            switch ($kind) {
                case self::SUBSCRIPTION: return self::subscription($event) + $c;
                case self::NGV:          return self::ngv($tx) + $c;
                case self::LMS:          return self::lms($tx) + $c;
                case self::DUES_RENEWAL: return self::duesRenewal($tx) + $c;
                case self::DONATION:     return self::donation($tx) + $c;
            }
        } catch (Throwable $e) {
            error_log('[webhook] ' . $kind . ' ' . $ref . ': ' . $e->getMessage());
            return ['ok' => false, 'status' => 500, 'error' => 'handler failed'] + $c;
        }
        return ['ok' => true, 'status' => 200] + $c;
    }

    /* ══ The owners ═════════════════════════════════════════════════════════ */

    private static function subscription(array $event): array
    {
        if (!class_exists('Appeals')) return ['ok' => true, 'status' => 200];
        $name = (string) ($event['event'] ?? '');
        $sd   = is_array($event['data'] ?? null) ? $event['data'] : [];
        $meta = is_array($sd['metadata'] ?? null) ? $sd['metadata'] : [];
        if ($name === 'subscription.create') {
            Appeals::recordSubscription([
                'sub_code'   => (string) ($sd['subscription_code'] ?? ''),
                'email'      => (string) ($sd['customer']['email'] ?? ''),
                'name'       => trim(((string) ($sd['customer']['first_name'] ?? '')) . ' ' . ((string) ($sd['customer']['last_name'] ?? ''))),
                'amount_ngn' => (int) round(((float) ($sd['amount'] ?? 0)) / 100),
                'interval'   => (string) ($sd['plan']['interval'] ?? 'monthly'),
                'plan_code'  => (string) ($sd['plan']['plan_code'] ?? ''),
                'appeal_id'  => (int) ($meta['appeal_id'] ?? 0),
                'slug'       => (string) ($meta['appeal'] ?? ''),
            ]);
        } elseif ($name === 'subscription.disable' || $name === 'subscription.not_renew') {
            try {
                Database::pdo()->prepare('UPDATE av_appeal_subs SET status = ?, ended_at = ? WHERE sub_code = ?')
                    ->execute(['cancelled', gmdate('Y-m-d H:i:s'), (string) ($sd['subscription_code'] ?? '')]);
            } catch (Throwable $e) { error_log('[webhook] sub disable: ' . $e->getMessage()); }
        }
        return ['ok' => true, 'status' => 200];
    }

    /**
     * A participant's fee. NAIRA ONLY: the ledger is kept in naira and
     * amount/100 is only naira when the charge was. A non-naira charge is
     * answered 200 so Paystack does not retry for days, and logged for a
     * person to reconcile.
     */
    private static function ngv(array $tx): array
    {
        $ref = (string) ($tx['reference'] ?? '');
        $mid = (int) ($tx['metadata']['ngv_member'] ?? 0);
        if (!class_exists('NgvLedger')) return ['ok' => false, 'status' => 500, 'error' => 'no ledger'];
        $cur = strtoupper((string) ($tx['currency'] ?? ''));
        if ($cur !== 'NGN') {
            error_log('[webhook] ngv: refused ' . $ref . ' — currency "' . $cur . '" is not NGN; not posted, reconcile by hand');
            return ['ok' => false, 'status' => 200, 'error' => 'currency'];
        }
        $r = NgvLedger::payOnline($mid, $ref, (int) round(((float) ($tx['amount'] ?? 0)) / 100),
            ['method' => 'card', 'note' => 'Paid online by card']);
        if (empty($r['ok'])) {
            error_log('[webhook] ngv: ' . ($r['error'] ?? 'not recorded') . ' for ' . $ref);
            return ['ok' => false, 'status' => 500, 'error' => (string) ($r['error'] ?? 'not recorded')];
        }
        return ['ok' => true, 'status' => 200];
    }

    /**
     * A course or dues charge we started: the `payments` row exists, so
     * finalizePayment owns the amount check, the one-claim-one-grant update
     * and the grant. Re-verified with the gateway first, as the academy route
     * always did — defence in depth against a forged body.
     */
    private static function lms(array $tx): array
    {
        $ref = (string) ($tx['reference'] ?? '');
        $v = Payments::paystackVerify($ref);
        if (empty($v['paid'])) {
            error_log('[webhook] lms: ' . $ref . ' did not verify as paid');
            return ['ok' => false, 'status' => 200, 'error' => 'not verified'];
        }
        $granted = (new LmsRepository())->finalizePayment($ref, (int) ($v['amount'] ?? 0));
        return ['ok' => true, 'status' => 200, 'granted' => $granted];
    }

    /**
     * A LATER charge on the dues subscription. There is no local payments row
     * for it — the gateway raised it on its own schedule — so this writes one,
     * keyed on the gateway's reference, and then goes through the same
     * finalizePayment as every other dues charge.
     *
     * Before this, a renewal called grantMembershipByEmail and added a month:
     * no de-duplication, so every redelivery of the webhook added another; no
     * amount or currency check, so a subscription still running at an old,
     * lower price bought a month at today's; no `payments` row, so renewal
     * money was missing from every dues total and from the member's own
     * history; and no audit. The row and finalizePayment answer all four at
     * once — `payments.reference` is UNIQUE, so the second delivery cannot
     * even insert, and the amount test is finalizePayment's own.
     */
    private static function duesRenewal(array $tx): array
    {
        $ref   = (string) ($tx['reference'] ?? '');
        $email = strtolower(trim((string) ($tx['customer']['email'] ?? '')));
        $cur   = strtoupper((string) ($tx['currency'] ?? 'NGN'));
        if ($cur !== 'NGN') {
            error_log('[webhook] dues renewal: refused ' . $ref . ' — currency "' . $cur . '" is not NGN; reconcile by hand');
            return ['ok' => false, 'status' => 200, 'error' => 'currency'];
        }
        if ($email === '') return ['ok' => false, 'status' => 200, 'error' => 'no customer email'];

        $pdo = Database::pdo();
        $st = $pdo->prepare('SELECT id FROM lms_users WHERE LOWER(email) = ? LIMIT 1');
        $st->execute([$email]);
        $uid = (int) ($st->fetchColumn() ?: 0);
        if ($uid <= 0) {
            /* The month used to go to whoever now holds the email; with no
               account it went nowhere at all, silently. */
            error_log('[webhook] dues renewal: ' . $ref . ' — no account for ' . $email . '; nothing granted, reconcile by hand');
            return ['ok' => false, 'status' => 200, 'error' => 'no account'];
        }

        Membership::ensure();
        $priceKobo = Membership::price(1) * 100;
        $paidKobo  = (int) round((float) ($tx['amount'] ?? 0));

        /* Insert-or-ignore on the gateway's reference. UNIQUE(reference) is
           the gate: a redelivered webhook cannot create a second row, and
           finalizePayment below sees the first one, already paid. */
        try {
            $pdo->prepare("INSERT INTO payments (reference, user_id, provider, kind, amount_kobo, currency, status, months, note, recorded_by)
                           VALUES (?, ?, 'paystack', 'membership', ?, 'NGN', 'pending', 1, ?, 'gateway')")
                ->execute([$ref, $uid, $priceKobo, 'Dues subscription renewal']);
        } catch (Throwable $e) {
            if (!(new LmsRepository())->paymentByRef($ref)) {
                error_log('[webhook] dues renewal: could not record ' . $ref . ': ' . $e->getMessage());
                return ['ok' => false, 'status' => 500, 'error' => 'not recorded'];
            }
        }

        $granted = (new LmsRepository())->finalizePayment($ref, $paidKobo);
        if (!$granted && $paidKobo < $priceKobo) {
            /* Short, so no month — and said out loud rather than left in the
               error log, because a subscription locked at last year's price
               will keep arriving every month until somebody acts on it. */
            if (class_exists('AdminAudit')) {
                try {
                    AdminAudit::log('payments', 'dues_renewal_short', $email,
                        'A dues renewal of ₦' . number_format($paidKobo / 100) . ' arrived for ' . $email
                        . ' (' . $ref . '), and dues for a month are ₦' . number_format($priceKobo / 100)
                        . '. No month was granted; the payment is recorded as pending. The subscription is on an old price and needs reissuing.',
                        null, 'gateway');
                } catch (Throwable $e) {}
            }
            return ['ok' => true, 'status' => 200, 'granted' => false, 'error' => 'short'];
        }
        if ($granted && class_exists('AdminAudit')) {
            try { AdminAudit::log('payments', 'dues_renewal', $email, 'Dues renewed by subscription — ₦' . number_format($paidKobo / 100) . ' (' . $ref . '), one month', null, 'gateway'); }
            catch (Throwable $e) {}
        }
        return ['ok' => true, 'status' => 200, 'granted' => $granted];
    }

    private static function donation(array $tx): array
    {
        $r = Donations::recordCharge($tx);
        return ['ok' => !empty($r['ok']), 'status' => 200, 'new' => !empty($r['new'])];
    }
}
