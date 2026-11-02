<?php
/**
 * lib/MemberStatement.php — one statement of a member's money.
 *
 * ── WHAT THIS IS ────────────────────────────────────────────────────────────
 * A READER. It owns no table, writes nothing, and decides nothing. Every row
 * it shows already exists somewhere else; this file is the only place they are
 * read together.
 *
 * Before it, a member's money lived in places that did not know about each
 * other, and no screen could answer "what do I owe, and what have I paid?":
 *
 *   dues      memberships + payments        (Membership)
 *   NGV       ngv_charges + ngv_payments    (NgvLedger, a SEPARATE database)
 *   offline   payments awaiting review      (OfflinePayments)
 *
 * It PROJECTS those ledgers rather than copying them. Membership and
 * NgvLedger stay authoritative for their own charges; nothing here may write
 * back. A second copy of a figure is a figure that will disagree with the
 * first one, and the first anybody learns of it is a member holding a receipt
 * the site contradicts.
 *
 * ── KOBO, EVERYWHERE, AND WHY THAT NEEDED SAYING ────────────────────────────
 * The two ledgers do not agree on a unit, and nothing warned anybody:
 *
 *   payments.amount_kobo    KOBO   (Membership divides by 100 to show naira)
 *   ngv_charges.amount      NAIRA  (NgvLedger::money_text formats it directly)
 *
 * Added together as they stand, ₦10,000 of NGV charges and ₦10,000 of dues
 * read as 1,010,000 — of nothing in particular. So this file works in KOBO,
 * the finer of the two, so no precision is lost on the way in, and every
 * variable that holds money says `_kobo` in its name. The conversion happens
 * once, at the edge, in ngvRows().
 *
 * ── AN UNREADABLE SOURCE IS SAID, NOT SKIPPED ───────────────────────────────
 * NGV is a different database and can be absent, switched off, or unreachable.
 * A statement that quietly omitted it would show a SMALLER balance than the
 * truth — the one failure mode a money page must not have. Every source that
 * could not be read is named in `problems`, and `complete` is false, so the
 * screen can say "this is not all of it" instead of implying it is.
 */
declare(strict_types=1);

final class MemberStatement
{
    /** Pending money is shown but never counted — it has not arrived yet. */
    public const PENDING = 'pending';

    /**
     * Everything recorded about one member's money, oldest entry first.
     *
     * @return array{
     *   entries: list<array<string,mixed>>,
     *   charged_kobo: int, paid_kobo: int, balance_kobo: int, pending_kobo: int,
     *   sources: list<string>, problems: list<string>, complete: bool
     * }
     */
    public static function for(int $userId): array
    {
        $entries  = [];
        $problems = [];
        $sources  = [];

        foreach (['dues' => 'duesRows', 'ngv' => 'ngvRows', 'offline' => 'offlineRows'] as $source => $fn) {
            try {
                $rows = self::$fn($userId);
                if ($rows === null) continue;          // not applicable to this member
                $sources[] = $source;
                foreach ($rows as $r) $entries[] = $r;
            } catch (Throwable $e) {
                /* Named, not swallowed. See the file docblock. */
                error_log('[statement] ' . $source . ' for user ' . $userId . ': ' . $e->getMessage());
                $problems[] = $source;
            }
        }

        /* Same timestamp: the charge comes before the money that pays it.
           Sorted on the reference instead, an NGV payment recorded in the
           same second as its charge sorted first and the statement showed a
           credit balance for one line — a member paying something they did
           not yet appear to owe. */
        usort($entries, static function (array $a, array $b): int {
            $chargeFirst = static fn(array $e): int => $e['charge_kobo'] > 0 ? 0 : 1;
            return [$a['at'], $chargeFirst($a), $a['source'], $a['ref']]
               <=> [$b['at'], $chargeFirst($b), $b['source'], $b['ref']];
        });

        $charged = 0; $paid = 0; $pending = 0; $running = 0;
        foreach ($entries as $i => $e) {
            if (!empty($e['pending'])) {
                $pending += (int) $e['credit_kobo'];
                $entries[$i]['balance_kobo'] = $running;   // unchanged: it has not arrived
                continue;
            }
            $charged += (int) $e['charge_kobo'];
            $paid    += (int) $e['credit_kobo'];
            $running += (int) $e['charge_kobo'] - (int) $e['credit_kobo'];
            $entries[$i]['balance_kobo'] = $running;
        }

        return [
            'entries'      => $entries,
            'charged_kobo' => $charged,
            'paid_kobo'    => $paid,
            'balance_kobo' => $running,          // positive = owed, negative = in credit
            'pending_kobo' => $pending,
            'sources'      => $sources,
            'problems'     => $problems,
            'complete'     => $problems === [],
        ];
    }

    /** ₦ for a kobo figure. The only place this file divides by 100. */
    public static function naira(int $kobo): string
    {
        $neg = $kobo < 0;
        $n   = abs($kobo);
        $s   = '₦' . number_format(intdiv($n, 100)) . ($n % 100 ? '.' . str_pad((string) ($n % 100), 2, '0', STR_PAD_LEFT) : '');

        return $neg ? '−' . $s : $s;
    }

    /* ══ Sources ═════════════════════════════════════════════════════════ */

    /**
     * Dues. Already kobo in the table, so nothing is converted.
     *
     * A dues payment is a credit with no matching charge: the dues model is a
     * standing, not an invoice, so there is nothing to owe. Showing it as a
     * credit against zero would read as the member being in credit, so these
     * carry neither — they are recorded, with their own running total left
     * alone, by charging and crediting the same amount.
     */
    private static function duesRows(int $userId): ?array
    {
        if (!class_exists('Membership')) return null;
        /* history() answers ['memberships' => …, 'payments' => …], not a flat
           list. Iterated as one, it yielded nothing at all and the statement
           showed no dues — found by running it against real rows, not by
           reading it. */
        $out = [];
        foreach ((array) (Membership::history($userId)['payments'] ?? []) as $p) {
            if ((string) ($p['status'] ?? '') !== 'paid') continue;
            $kobo = (int) ($p['amount_kobo'] ?? 0);
            $out[] = [
                'at'          => (string) ($p['paid_at'] ?: $p['created_at'] ?? ''),
                'source'      => 'dues',
                'kind'        => 'payment',
                'label'       => 'Dues' . (!empty($p['months']) ? ' — ' . (int) $p['months'] . ' month' . ((int) $p['months'] === 1 ? '' : 's') : ''),
                'charge_kobo' => $kobo,     // the dues it paid for
                'credit_kobo' => $kobo,     // and the money that paid it
                'ref'         => (string) ($p['reference'] ?? ''),
                'method'      => (string) ($p['provider'] ?? ''),
                'pending'     => false,
            ];
        }

        return $out;
    }

    /**
     * NextGen Vanguard charges and payments.
     *
     * NAIRA in the table — converted here, once. Returns null when the member
     * is not in NGV at all, which is most of them: an empty section on a
     * statement invites the question "where is my NGV money", and the answer
     * "you have none" is better not asked.
     */
    private static function ngvRows(int $userId): ?array
    {
        /* NOT gated on NgvLedger::enabled(). That setting means "nothing
           accrues, nothing is chased" — it governs whether NEW charges appear,
           not whether the existing ones are real. Gated on it, a member who
           owes ₦7,500 would watch the debt vanish the day an administrator
           switched the programme off, and reappear when they switched it back.
           A charge already raised is a fact about the member. */
        if (!class_exists('NgvLedger')) return null;

        $charges = NgvLedger::charges($userId);
        $credits = NgvLedger::credits($userId);
        if (!$charges && !$credits) return null;

        $label = [
            'membership' => 'NGV membership fee',
            'commitment' => 'NGV commitment fee',
            'programme'  => 'NGV training fee',
            'fine'       => 'Fine',
            'adjustment' => 'Adjustment',
        ];

        $out = [];
        foreach ($charges as $c) {
            $kind = (string) ($c['kind'] ?? '');
            $out[] = [
                'at'          => (string) ($c['created_at'] ?? ''),
                'source'      => 'ngv',
                'kind'        => $kind,
                'label'       => ($label[$kind] ?? ucfirst($kind))
                               . (($c['period'] ?? '') !== '' ? ' — ' . (string) $c['period'] : '')
                               . (($c['note'] ?? '') !== '' ? ' (' . (string) $c['note'] . ')' : ''),
                'charge_kobo' => ((int) ($c['amount'] ?? 0)) * 100,
                'credit_kobo' => 0,
                'ref'         => 'ngvc:' . (int) ($c['id'] ?? 0),
                'method'      => '',
                'pending'     => false,
            ];
        }
        foreach ($credits as $p) {
            $out[] = [
                'at'          => (string) ($p['created_at'] ?? ''),
                'source'      => 'ngv',
                'kind'        => 'payment',
                /* A fine taken off their NGG volunteer pay says so, not a method code. */
                'label'       => (string) ($p['method'] ?? '') === 'ngg_pay' ? 'Fine paid from your NGG volunteer pay'
                               : 'Payment received' . (($p['method'] ?? '') !== '' ? ' — ' . (string) $p['method'] : ''),
                'charge_kobo' => 0,
                'credit_kobo' => ((int) ($p['amount'] ?? 0)) * 100,
                'ref'         => (string) ($p['reference'] ?? ('ngvp:' . (int) ($p['id'] ?? 0))),
                'method'      => (string) ($p['method'] ?? ''),
                'pending'     => false,
            ];
        }

        return $out;
    }

    /**
     * Money the member says they have sent, which staff have not confirmed.
     *
     * Shown so somebody who paid an hour ago can see that it is known about,
     * and NOT counted, because it has not arrived. Counting it would let the
     * statement say a debt is cleared on the strength of an unverified claim.
     */
    private static function offlineRows(int $userId): ?array
    {
        if (!class_exists('OfflinePayments') || !method_exists('OfflinePayments', 'forUser')) return null;
        $rows = OfflinePayments::forUser($userId);
        if (!$rows) return null;

        /* NAIRA in this table (amount_ngn), like NGV and unlike dues. Third
           source, third unit boundary — converted here, once. */
        $out = [];
        foreach ($rows as $r) {
            /* 'pending' and 'held' are both undecided: held is a claim a check
               stopped, and it has still not arrived. 'verified' is already in
               the ledger it paid into, so counting it here would double it;
               'rejected' never happened. */
            if (!in_array((string) ($r['status'] ?? ''), ['pending', 'held'], true)) continue;
            $out[] = [
                'at'          => (string) ($r['created_at'] ?? ''),
                'source'      => 'offline',
                'kind'        => self::PENDING,
                'label'       => 'Payment reported, awaiting confirmation'
                               . ((string) ($r['status'] ?? '') === 'held' ? ' (being checked)' : ''),
                'charge_kobo' => 0,
                'credit_kobo' => ((int) ($r['amount_ngn'] ?? 0)) * 100,
                'ref'         => (string) ($r['reference'] ?? ''),
                'method'      => (string) ($r['method'] ?? ''),
                'pending'     => true,
            ];
        }

        return $out;
    }
}
