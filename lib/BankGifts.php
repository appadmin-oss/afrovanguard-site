<?php
/**
 * lib/BankGifts.php — a donation paid by bank transfer, recorded by one named
 * person and confirmed by another.
 *
 * It used to be one form on the donor dashboard that asked for the master
 * ADMIN_TOKEN and wrote the gift straight in: no name on who recorded it, no
 * second factor, nobody else looking, and anyone who had once seen the token
 * could add money to a campaign total and send a receipt in the
 * organisation's name. Money that arrives outside the card gateway is exactly
 * where a second pair of eyes earns its keep, so it now works like the dues
 * desk's offline payments (lib/OfflinePayments.php):
 *
 *   · submit()  — a Studio admin records what the bank statement shows. The
 *                 gift is HELD: no total moves and no receipt goes out.
 *   · verify()  — a DIFFERENT Studio admin (or a Super Admin) checks it
 *                 against the statement. Only then is it stored, through the
 *                 same Donations::storeIfNew() every other gift uses (so a
 *                 reference can never be counted twice), and receipted.
 *   · reject()  — with a reason; nothing was ever counted.
 *
 * Both names are kept on the row and in the audit log. The bank reference is
 * unique, so the same transfer cannot be submitted twice.
 */
declare(strict_types=1);

final class BankGifts
{
    public const STATUSES = ['pending', 'verified', 'rejected'];
    public const MIN_NGN = 100;
    public const MAX_NGN = 10000000;
    public const FREQUENCIES = ['One-time', 'Monthly', 'Annual'];

    private static bool $ready = false;

    public static function ensure(): void
    {
        if (self::$ready) return;
        Database::execSchema(Database::pdo(), "CREATE TABLE IF NOT EXISTS bank_gifts (
            id            INTEGER PRIMARY KEY AUTOINCREMENT,
            bank_ref      VARCHAR(80) NOT NULL DEFAULT '',
            name          VARCHAR(100) NOT NULL DEFAULT '',
            email         VARCHAR(191) NOT NULL DEFAULT '',
            amount        DECIMAL(14,2) NOT NULL DEFAULT 0,
            campaign      VARCHAR(80) NOT NULL DEFAULT '',
            frequency     VARCHAR(16) NOT NULL DEFAULT 'One-time',
            listed        INTEGER NOT NULL DEFAULT 0,
            paid_on       VARCHAR(10) NOT NULL DEFAULT '',
            note          VARCHAR(500) NOT NULL DEFAULT '',
            status        VARCHAR(12) NOT NULL DEFAULT 'pending',
            submitted_by  VARCHAR(191) NOT NULL DEFAULT '',
            decided_by    VARCHAR(191) NOT NULL DEFAULT '',
            decision_note VARCHAR(500) NOT NULL DEFAULT '',
            donation_ref  VARCHAR(64) NOT NULL DEFAULT '',
            created_at    VARCHAR(32) NOT NULL DEFAULT '',
            decided_at    VARCHAR(32) NOT NULL DEFAULT ''
        );
        CREATE UNIQUE INDEX IF NOT EXISTS ux_bank_gifts_ref ON bank_gifts (bank_ref);
        CREATE INDEX IF NOT EXISTS ix_bank_gifts_status ON bank_gifts (status)");
        self::$ready = true;
    }

    /** Record a transfer as the bank statement shows it. Held until somebody else verifies it. */
    public static function submit(array $in, string $by): array
    {
        self::ensure();
        $by = strtolower(trim($by));
        if (!self::person($by)) return self::noActor();
        $v = self::validate($in);
        if (empty($v['ok'])) return $v;
        $f = $v['fields'];
        $pdo = Database::pdo();
        $st = $pdo->prepare('SELECT id, status FROM bank_gifts WHERE bank_ref = ?');
        $st->execute([$f['bank_ref']]);
        if ($dup = $st->fetch(PDO::FETCH_ASSOC)) {
            return ['ok' => false, 'code' => 'duplicate', 'error' => 'That bank reference is already recorded (#' . $dup['id'] . ', ' . $dup['status'] . ').'];
        }
        $pdo->prepare('INSERT INTO bank_gifts (bank_ref, name, email, amount, campaign, frequency, listed, paid_on, note, status, submitted_by, created_at)
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, \'pending\', ?, ?)')
            ->execute([$f['bank_ref'], $f['name'], $f['email'], $f['amount'], $f['campaign'], $f['frequency'], $f['listed'] ? 1 : 0, $f['paid_on'], $f['note'], $by, date('c')]);
        $id = (int) $pdo->lastInsertId();
        self::audit('bank_gift.submitted', $f['email'], '#' . $id . ' · ₦' . number_format($f['amount'], 2) . ' · ' . $f['campaign'] . ' · ref ' . $f['bank_ref'], $by);
        return ['ok' => true, 'status' => 'pending', 'gift' => self::get($id)];
    }

    /** Check it against the statement and count it. Never the person who recorded it. */
    public static function verify(int $id, string $by, ?callable $receipt = null): array
    {
        self::ensure();
        $by = strtolower(trim($by));
        if (!self::person($by)) return self::noActor();
        $g = self::get($id);
        if (!$g) return ['ok' => false, 'code' => 'not_found', 'error' => 'No such gift.'];
        if ($g['status'] !== 'pending') return ['ok' => false, 'code' => 'decided', 'error' => 'This gift was already ' . $g['status'] . '.'];
        if ( $by === strtolower((string) $g['submitted_by'])) {
            return ['ok' => false, 'code' => 'own_submission', 'error' => 'Somebody other than the person who recorded it must verify it.'];
        }
        $pdo = Database::pdo();
        /* Claimed first: two people pressing Verify at once count it once. */
        $claim = $pdo->prepare("UPDATE bank_gifts SET status = 'verified', decided_by = ?, decided_at = ? WHERE id = ? AND status = 'pending'");
        $claim->execute([$by, date('c'), $id]);
        if ($claim->rowCount() !== 1) return ['ok' => false, 'code' => 'decided', 'error' => 'Somebody else decided it a moment ago.'];

        $ref = 'ZB_' . preg_replace('/[^A-Za-z0-9]/', '', (string) $g['bank_ref']);
        $ref = substr($ref, 0, 60);
        $name = (string) $g['name'];
        $parts = explode(' ', $name, 2);
        $listed = (int) $g['listed'] === 1;
        $entry = [
            'id' => $ref, 'type' => 'bank_static', 'reference' => $ref,
            'name' => $listed ? $name : 'Anonymous',
            'initials' => $listed ? strtoupper(mb_substr($parts[0] ?? '?', 0, 1) . mb_substr($parts[1] ?? '', 0, 1)) : 'AN',
            'email' => (string) $g['email'], 'anonymous' => !$listed, 'listed' => $listed,
            'amount' => (float) $g['amount'], 'currency' => 'NGN', 'campaign' => (string) $g['campaign'],
            'frequency' => (string) $g['frequency'], 'status' => 'confirmed', 'created_at' => date('c'),
            'paid_on' => (string) $g['paid_on'],
            'recorded_by' => (string) $g['submitted_by'], 'confirmed_by' => $by,
        ];
        $stored = Donations::storeIfNew($ref, $entry, (float) $g['amount'], (string) $g['campaign'], 'NGN');
        if (!$stored && !Donations::known($ref)) {
            /* Not counted, and not because it already was: put it back to be tried again. */
            $pdo->prepare("UPDATE bank_gifts SET status = 'pending', decided_by = '', decided_at = '' WHERE id = ?")->execute([$id]);
            return ['ok' => false, 'code' => 'not_stored', 'error' => 'The gift could not be saved to the donations record. Nothing was counted; try again.'];
        }
        $pdo->prepare('UPDATE bank_gifts SET donation_ref = ? WHERE id = ?')->execute([$ref, $id]);
        self::audit('bank_gift.verified', (string) $g['email'], '#' . $id . ' · ₦' . number_format((float) $g['amount'], 2) . ' · recorded by ' . $g['submitted_by'], $by);
        $sent = false;
        if ($stored) {
            $send = $receipt ?? [Donations::class, 'sendReceipt'];
            try {
                $sent = (bool) $send((string) $g['email'], [
                    'firstName' => $parts[0] ?? '', 'lastName' => $parts[1] ?? '', 'amount' => (float) $g['amount'], 'currency' => 'NGN',
                    'campaign' => ucwords(str_replace(['-', '_'], ' ', (string) $g['campaign'])), 'reference' => $ref, 'method' => 'Bank transfer',
                ]);
            } catch (Throwable $e) { error_log('[bankgifts] receipt: ' . $e->getMessage()); }
        }
        return ['ok' => true, 'status' => 'verified', 'receipted' => $sent, 'already' => !$stored, 'gift' => self::get($id)];
    }

    /** Not on the statement, or wrong: say why. Nothing was ever counted. */
    public static function reject(int $id, string $note, string $by): array
    {
        self::ensure();
        $by = strtolower(trim($by));
        if (!self::person($by)) return self::noActor();
        $note = mb_substr(trim($note), 0, 500);
        if ($note === '') return ['ok' => false, 'code' => 'reason_required', 'error' => 'Say why it is being rejected.'];
        $g = self::get($id);
        if (!$g) return ['ok' => false, 'code' => 'not_found', 'error' => 'No such gift.'];
        if ($g['status'] !== 'pending') return ['ok' => false, 'code' => 'decided', 'error' => 'This gift was already ' . $g['status'] . '.'];
        $st = Database::pdo()->prepare("UPDATE bank_gifts SET status = 'rejected', decided_by = ?, decision_note = ?, decided_at = ? WHERE id = ? AND status = 'pending'");
        $st->execute([$by, $note, date('c'), $id]);
        if ($st->rowCount() !== 1) return ['ok' => false, 'code' => 'decided', 'error' => 'Somebody else decided it a moment ago.'];
        self::audit('bank_gift.rejected', (string) $g['email'], '#' . $id . ' · ' . $note, $by);
        return ['ok' => true, 'status' => 'rejected', 'gift' => self::get($id)];
    }

    /** The submitter withdraws their own pending entry (a typo, a duplicate). */
    public static function withdraw(int $id, string $by): array
    {
        self::ensure();
        $by = strtolower(trim($by));
        $g = self::get($id);
        if (!$g || $g['status'] !== 'pending') return ['ok' => false, 'code' => 'not_pending', 'error' => 'Only a pending gift can be withdrawn.'];
        if ($by !== strtolower((string) $g['submitted_by'])) return ['ok' => false, 'code' => 'not_yours', 'error' => 'Only the person who recorded it can withdraw it; anybody else rejects it with a reason.'];
        Database::pdo()->prepare('DELETE FROM bank_gifts WHERE id = ? AND status = \'pending\'')->execute([$id]);
        self::audit('bank_gift.withdrawn', (string) $g['email'], '#' . $id, $by);
        return ['ok' => true];
    }

    public static function get(int $id): ?array
    {
        self::ensure();
        $st = Database::pdo()->prepare('SELECT * FROM bank_gifts WHERE id = ?');
        $st->execute([$id]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ? self::shape($r) : null;
    }

    /** The desk's list: pending first (oldest first — that is the queue), then the latest decided. */
    public static function list(string $status = 'pending', int $limit = 100): array
    {
        self::ensure();
        $limit = max(1, min(500, $limit));
        if (!in_array($status, self::STATUSES, true) && $status !== 'all') $status = 'pending';
        $sql = 'SELECT * FROM bank_gifts' . ($status === 'all' ? '' : ' WHERE status = ?')
             . ($status === 'pending' ? ' ORDER BY id ASC' : ' ORDER BY id DESC') . ' LIMIT ' . $limit;
        $st = Database::pdo()->prepare($sql);
        $st->execute($status === 'all' ? [] : [$status]);
        return array_map([self::class, 'shape'], $st->fetchAll(PDO::FETCH_ASSOC));
    }

    public static function pendingCount(): int
    {
        try { self::ensure(); return (int) Database::pdo()->query("SELECT COUNT(*) FROM bank_gifts WHERE status = 'pending'")->fetchColumn(); }
        catch (Throwable $e) { return 0; }
    }

    /** The whole payload checked before anything is written. */
    public static function validate(array $in): array
    {
        $name = mb_substr(trim((string) ($in['name'] ?? '')), 0, 100);
        $email = strtolower(trim((string) ($in['email'] ?? '')));
        $amount = round((float) ($in['amount'] ?? 0), 2);
        $camp = preg_replace('/[^a-z0-9_-]/', '', strtolower((string) ($in['campaign'] ?? '')));
        $bankRef = mb_substr(trim((string) ($in['bank_ref'] ?? '')), 0, 80);
        $paid = trim((string) ($in['paid_on'] ?? ''));
        $freq = (string) ($in['frequency'] ?? 'One-time');
        if ($name === '') return ['ok' => false, 'code' => 'name', 'error' => 'Give the donor\'s name as the bank shows it.'];
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return ['ok' => false, 'code' => 'email', 'error' => 'Give an email the receipt can go to.'];
        if ($amount < self::MIN_NGN) return ['ok' => false, 'code' => 'amount', 'error' => 'The minimum is ₦' . number_format(self::MIN_NGN) . '.'];
        if ($amount > self::MAX_NGN) return ['ok' => false, 'code' => 'amount', 'error' => 'Over ₦' . number_format(self::MAX_NGN) . ' is recorded by finance directly, not here.'];
        if ($camp === '') return ['ok' => false, 'code' => 'campaign', 'error' => 'Say which appeal it is for.'];
        if ($bankRef === '') return ['ok' => false, 'code' => 'bank_ref', 'error' => 'Give the reference on the bank statement, so the same transfer cannot be counted twice.'];
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $paid) || strtotime($paid) === false || $paid > date('Y-m-d')) {
            return ['ok' => false, 'code' => 'paid_on', 'error' => 'Give the date the money arrived (not in the future).'];
        }
        if (!in_array($freq, self::FREQUENCIES, true)) $freq = 'One-time';
        return ['ok' => true, 'fields' => ['name' => $name, 'email' => $email, 'amount' => $amount, 'campaign' => $camp, 'bank_ref' => $bankRef,
            'paid_on' => $paid, 'frequency' => $freq, 'listed' => !empty($in['listed']), 'note' => mb_substr(trim((string) ($in['note'] ?? '')), 0, 500)]];
    }

    /**
     * A person, not a credential. The break-glass token signs in as
     * "superadmin (token)": letting it record or verify would let one shared
     * secret be both of the two people this desk exists to need.
     */
    private static function person(string $by): bool { return (bool) filter_var($by, FILTER_VALIDATE_EMAIL); }

    private static function noActor(): array
    {
        return ['ok' => false, 'code' => 'no_actor', 'error' => 'Sign in with your own account to record or verify money; the emergency token cannot.'];
    }

    private static function shape(array $r): array
    {
        return ['id' => (int) $r['id'], 'bank_ref' => (string) $r['bank_ref'], 'name' => (string) $r['name'], 'email' => (string) $r['email'],
            'amount' => (float) $r['amount'], 'campaign' => (string) $r['campaign'], 'frequency' => (string) $r['frequency'],
            'listed' => (int) $r['listed'] === 1, 'paid_on' => (string) $r['paid_on'], 'note' => (string) $r['note'],
            'status' => (string) $r['status'], 'submitted_by' => (string) $r['submitted_by'], 'decided_by' => (string) $r['decided_by'],
            'decision_note' => (string) $r['decision_note'], 'donation_ref' => (string) $r['donation_ref'],
            'created_at' => (string) $r['created_at'], 'decided_at' => (string) $r['decided_at']];
    }

    private static function audit(string $action, string $email, string $detail, string $by): void
    {
        try { (new LmsRepository())->audit($action, strtolower($email), $detail, $by); }
        catch (Throwable $e) { error_log('[bankgifts] audit: ' . $e->getMessage()); }
    }
}
