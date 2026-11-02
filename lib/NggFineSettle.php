<?php
/**
 * lib/NggFineSettle.php — Afrovanguard fines taken off NGG volunteer pay.
 *
 * A person who volunteers with NGG and owes Afrovanguard a fine (an NGV or
 * Vanguard Quest fine, NgvFines) has it taken off their NGG pay when NGG pays
 * a period (domain/volunteer-pay.php there), down to ₦0; the rest stays owed
 * here. NGG asks over integrations/ngg-fines.php:
 *
 *   owed      {emails}  → {owing: {email: naira}}       what each still owes in fines
 *   settle    {items: [{email, amount, reference, note}]} → {settled: {reference: naira}}
 *   unsettle  {reference, reason} → {voided}            NGG voided that payout
 *
 * A settlement is a `payment` on the person's fines line, method `ngg_pay`,
 * reference the NGG payout id: the same ledger call every fine payment makes,
 * so the person's statement, their receipt and the desk all see it. NGG now
 * holds that money for Afrovanguard; the payment's note says so.
 *
 * IDEMPOTENT BY REFERENCE. NGG may send the same settlement twice (a timeout,
 * a retry): a reference already on the ledger answers with what it recorded
 * then and records nothing more. Never more than is owing is taken.
 */
declare(strict_types=1);

final class NggFineSettle
{
    public const METHOD = 'ngg_pay';
    public const MAX_ITEMS = 500;
    public const REF_RE = '/^VP-[0-9A-F]{10}$/';

    /** sha256=HMAC(secret, "fines." + ts + "." + body), within five minutes. */
    public static function signatureOk(string $secret, string $ts, string $raw, string $sig, ?int $now = null): bool
    {
        if ($secret === '' || !ctype_digit($ts) || abs(($now ?? time()) - (int) $ts) > 300) return false;
        return hash_equals('sha256=' . hash_hmac('sha256', 'fines.' . $ts . '.' . $raw, $secret), trim($sig));
    }

    /** The active member with this email, or 0. */
    public static function memberOf(string $email): int
    {
        $email = strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return 0;
        $st = Database::pdo()->prepare("SELECT id FROM lms_users WHERE LOWER(TRIM(email)) = ? AND status = 'active' ORDER BY id LIMIT 1");
        $st->execute([$email]);
        return (int) ($st->fetchColumn() ?: 0);
    }

    /** What each email still owes in fines here. Somebody unknown owes nothing. */
    public static function owed(array $emails): array
    {
        $out = [];
        foreach (array_slice(array_values(array_unique(array_map(static fn($e) => strtolower(trim((string) $e)), $emails))), 0, self::MAX_ITEMS) as $e) {
            $id = self::memberOf($e);
            $out[$e] = $id > 0 ? max(0, (int) (NgvFines::forMember($id)['owing'] ?? 0)) : 0;
        }
        return ['ok' => true, 'owing' => (object) $out];
    }

    /** Record each settlement, at most what is owing, once per reference. */
    public static function settle(array $items): array
    {
        $settled = [];
        foreach (array_slice($items, 0, self::MAX_ITEMS) as $it) {
            if (!is_array($it)) continue;
            $ref = (string) ($it['reference'] ?? '');
            if (!preg_match(self::REF_RE, $ref)) continue;
            $seen = NgvLedger::paymentsByReference($ref);
            if ($seen) {
                $settled[$ref] = array_sum(array_map(static fn($r) => empty($r['voided']) ? (int) ($r['amount'] ?? 0) : 0, $seen));
                continue;
            }
            $id = self::memberOf((string) ($it['email'] ?? ''));
            if ($id <= 0) { $settled[$ref] = 0; continue; }
            $want = min(max(0, (int) ($it['amount'] ?? 0)), max(0, (int) (NgvFines::forMember($id)['owing'] ?? 0)));
            if ($want <= 0) { $settled[$ref] = 0; continue; }
            $note = mb_substr(trim((string) ($it['note'] ?? '')) ?: 'Taken from NGG volunteer pay', 0, 160) . '. Held by NGG for Afrovanguard.';
            $r = NgvLedger::payment($id, 'fine', $want, ['method' => self::METHOD, 'reference' => $ref, 'note' => $note], 0);
            $settled[$ref] = !empty($r['ok']) ? $want : 0;
        }
        return ['ok' => true, 'settled' => (object) $settled];
    }

    /** NGG voided the payout: its settlement here is voided too, so the fine is owed again. */
    public static function unsettle(string $ref, string $reason): array
    {
        if (!preg_match(self::REF_RE, $ref)) return ['ok' => false, 'error' => 'bad_reference'];
        $n = 0;
        foreach (NgvLedger::paymentsByReference($ref) as $r) {
            if (!empty($r['voided']) || (int) ($r['id'] ?? 0) <= 0) continue;
            $v = NgvLedger::void('credit', (int) $r['id'], 'NGG voided the payout' . (trim($reason) !== '' ? ': ' . mb_substr(trim($reason), 0, 150) : ''), 0);
            if (!empty($v['ok'])) $n++;
        }
        return ['ok' => true, 'voided' => $n];
    }

    /** Every fine taken off NGG pay and not voided: what NGG holds for Afrovanguard. */
    public static function heldByNgg(): int
    {
        try {
            $st = NgvDb::pdo()->prepare("SELECT COALESCE(SUM(amount), 0) FROM ngv_payments WHERE method = ? AND COALESCE(voided, 0) = 0");
            $st->execute([self::METHOD]);
            return (int) $st->fetchColumn();
        } catch (Throwable $e) { error_log('[nggfines] held: ' . $e->getMessage()); return 0; }
    }
}
