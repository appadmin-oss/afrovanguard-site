<?php
/**
 * lib/AcademyPricing.php — what an Academy membership costs, set in the Studio.
 *
 * The yearly Academy membership (access to every course marked "Members only")
 * was the AV_MEMBERSHIP_NGN constant: a figure in config.php or the
 * environment, which on shared hosting nobody but a developer can change. Now
 * the Studio sets it (Academy Studio → Overview → Pricing) and the saved figure
 * is the price everywhere it appears: the catalogue's plan card, a members-only
 * course page, the lesson player's "Become a member" button, the assistant's
 * answer and — the one that matters — what Paystack is asked to charge.
 *
 * Not the organisation's dues. Those are lib/Membership.php (monthly and yearly
 * figures, the arrears ladder) and stay where they are; an Academy membership
 * buys courses, dues are the movement's contribution.
 *
 * The rules:
 *  • AV_MEMBERSHIP_NGN is the starting price until somebody saves one.
 *  • A price is a whole number of naira, ₦100 to ₦10,000,000 (Paystack's
 *    smallest card charge is ₦100; anything above is a typing slip).
 *  • A change applies to payments STARTED after it. A payment already started
 *    is held to the amount it was started at (payments.amount_kobo, checked by
 *    LmsRepository::markPaid), so nobody who opened checkout at ₦5,000 is
 *    refused for paying ₦5,000 after a rise, or let in for less after a cut.
 *  • Every change is audited (who, from, to) and the last ten are kept, so
 *    "what did a year cost in March?" has an answer.
 *  • Admins and above set it (admin/api.php $managementOnly), like the NGV
 *    page whose fees it sits beside; content editors see it but cannot change it.
 */
declare(strict_types=1);

final class AcademyPricing
{
    public const KEY = 'av_academy_pricing';
    public const MIN_NGN = 100;
    public const MAX_NGN = 10000000;
    private const HISTORY = 10;

    private static ?array $cache = null;

    /** The deployment's starting price: AV_MEMBERSHIP_NGN, else ₦5,000. */
    public static function shipped(): int
    {
        return defined('AV_MEMBERSHIP_NGN') ? max(0, (int) AV_MEMBERSHIP_NGN) : 5000;
    }

    /** @return array{membership_ngn:int, source:string, updated_at:string, updated_by:string, history:list<array>} */
    public static function settings(): array
    {
        if (self::$cache !== null) return self::$cache;
        $d = ['membership_ngn' => self::shipped(), 'updated_at' => '', 'updated_by' => '', 'history' => []];
        $saved = null;
        try { $raw = Database::metaGet(self::KEY); $saved = is_string($raw) ? json_decode($raw, true) : null; } catch (Throwable $e) {}
        $s = $d;
        if (is_array($saved) && isset($saved['membership_ngn']) && (int) $saved['membership_ngn'] >= self::MIN_NGN) {
            $s = array_merge($d, array_intersect_key($saved, $d));
            $s['membership_ngn'] = min(self::MAX_NGN, (int) $s['membership_ngn']);
            $s['history'] = is_array($s['history']) ? array_values(array_slice($s['history'], 0, self::HISTORY)) : [];
            $s['source'] = 'studio';
        } else {
            $s['source'] = 'config';
            /* After a reset the history is still worth showing. */
            if (is_array($saved) && is_array($saved['history'] ?? null)) $s['history'] = array_values(array_slice($saved['history'], 0, self::HISTORY));
        }
        return self::$cache = $s;
    }

    /** The price of a year's Academy membership, in naira. */
    public static function membershipNgn(): int { return (int) self::settings()['membership_ngn']; }

    /** Set the price. Returns ['ok'=>bool, 'error'?, 'settings'?, 'unchanged'?]. */
    public static function save(array $in, string $by): array
    {
        $v = trim(str_replace([',', '₦', ' '], '', (string) ($in['membership_ngn'] ?? '')));
        if (!preg_match('/^\d+$/', $v) || (int) $v < self::MIN_NGN || (int) $v > self::MAX_NGN) {
            return ['ok' => false, 'error' => 'A year’s membership is a whole number of naira, from ₦' . number_format(self::MIN_NGN) . ' to ₦' . number_format(self::MAX_NGN) . '.', 'fields' => ['membership_ngn' => true]];
        }
        $cur = self::settings();
        $new = (int) $v;
        if ($new === $cur['membership_ngn']) return ['ok' => true, 'unchanged' => true, 'settings' => $cur];
        $now = gmdate('Y-m-d H:i:s');
        $by = mb_substr($by, 0, 191);
        $history = $cur['history'];
        array_unshift($history, ['from' => $cur['membership_ngn'], 'to' => $new, 'at' => $now, 'by' => $by]);
        $s = ['membership_ngn' => $new, 'updated_at' => $now, 'updated_by' => $by, 'history' => array_slice($history, 0, self::HISTORY)];
        Database::metaSet(self::KEY, (string) json_encode($s));
        self::$cache = null;
        try { (new LmsRepository())->audit('academy.membership_price', 'academy', '₦' . number_format($cur['membership_ngn']) . ' → ₦' . number_format($new), $by); }
        catch (Throwable $e) { error_log('[academy pricing] audit: ' . $e->getMessage()); }
        return ['ok' => true, 'settings' => self::settings()];
    }

    /** Go back to the deployment's starting price (keeps the history). */
    public static function reset(string $by): array
    {
        $cur = self::settings();
        if ($cur['source'] === 'config') return ['ok' => true, 'unchanged' => true, 'settings' => $cur];
        $history = $cur['history'];
        array_unshift($history, ['from' => $cur['membership_ngn'], 'to' => self::shipped(), 'at' => gmdate('Y-m-d H:i:s'), 'by' => mb_substr($by, 0, 191), 'reset' => true]);
        Database::metaSet(self::KEY, (string) json_encode(['membership_ngn' => 0, 'updated_at' => '', 'updated_by' => '', 'history' => array_slice($history, 0, self::HISTORY)]));
        self::$cache = null;
        try { (new LmsRepository())->audit('academy.membership_price', 'academy', 'reset to ₦' . number_format(self::shipped()), $by); } catch (Throwable $e) {}
        return ['ok' => true, 'settings' => self::settings()];
    }

    /** For tests. */
    public static function forget(): void { self::$cache = null; }
}
