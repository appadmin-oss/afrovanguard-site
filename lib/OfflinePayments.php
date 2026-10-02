<?php
/**
 * lib/OfflinePayments.php — money paid outside the gateway (bank transfer,
 * cash, POS, deposit), credited only once its receipt has been VERIFIED.
 *
 * Until this, an offline payment was whatever somebody typed: the office
 * recorded "₦12,000 by transfer" and the account said paid. Nothing checked
 * that the money existed, that it went to Afrovanguard, that it was the amount
 * claimed, or that the same receipt had not been used before. And a member
 * with no card and no gateway was told to "contact us".
 *
 * Now every offline payment arrives with EVIDENCE — a photo or PDF of the
 * receipt, transfer slip or bank alert — and is held until it is verified:
 *
 *   submit → store the evidence privately (never in the web root)
 *          → READ it: Gemini vision extracts what the receipt says
 *            (amount, date, reference, payee, payer, bank, signs of editing)
 *          → CHECK it here, in code, against the claim — the model reads,
 *            it does not decide
 *          → credit it (dues: Membership::grant · NGV: NgvLedger::payOnline,
 *            idempotent on the reference) only when every check passes
 *
 * THE CHECKS, each named in the result when it fails:
 *   evidence      it is a payment receipt at all
 *   amount        the amount on it is the amount claimed, to the naira
 *   payee         the money went to Afrovanguard — a configured account
 *                 number, or the organisation's name as payee
 *   date          it has a date, not in the future, not older than
 *                 AV_OFFLINE_MAX_AGE_DAYS (default 60), and within three days
 *                 of the date the payer gave
 *   reference     the reference on it matches the one given, if one was
 *   reused        neither this file nor the receipt's own reference has
 *                 credited money before
 *   tampering     the model saw no sign of editing
 *   confidence    the model's own reading is not "low"
 *
 * A payment that fails is HELD, not lost: it says why, the payer can upload
 * better evidence, staff can reject it. A Super Admin can approve one the
 * checks did not pass — when the reader is unavailable, or wrong — but only
 * with a written reason, and the approval says so for ever.
 *
 * Config: AV_OFFLINE_PAYEE_NAMES (comma list, default "Afrovanguard"),
 *         AV_OFFLINE_ACCOUNTS (comma list of account numbers),
 *         AV_OFFLINE_MAX_AGE_DAYS.
 */
declare(strict_types=1);

final class OfflinePayments
{
    public const METHODS = ['transfer', 'cash', 'pos', 'deposit', 'cheque', 'other'];
    public const PURPOSES = ['dues', 'ngv'];
    public const MAX_BYTES = 8 * 1024 * 1024;
    public const MIMES = ['image/jpeg', 'image/png', 'image/webp', 'image/heic', 'image/heif', 'application/pdf'];
    private const EXT = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/heic' => 'heic', 'image/heif' => 'heif', 'application/pdf' => 'pdf'];

    /** Tests replace the reader; production uses Gemini. fn(string $bytes, string $mime): array{ok:bool, data?:array, error?:string} */
    public static $reader = null;
    private static bool $ready = false;

    public static function ensure(): void
    {
        if (self::$ready) return; self::$ready = true;
        Database::execSchema(Database::pdo(), "CREATE TABLE IF NOT EXISTS offline_payments (
            id             INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id        INTEGER NOT NULL,
            purpose        VARCHAR(8) NOT NULL DEFAULT 'dues',
            months         INTEGER NOT NULL DEFAULT 0,
            line           VARCHAR(16) NOT NULL DEFAULT '',
            period         VARCHAR(40) NOT NULL DEFAULT '',
            amount_ngn     INTEGER NOT NULL DEFAULT 0,
            method         VARCHAR(16) NOT NULL DEFAULT '',
            reference      VARCHAR(80) NOT NULL DEFAULT '',
            paid_on        VARCHAR(10) NOT NULL DEFAULT '',
            evidence_file  VARCHAR(120) NOT NULL DEFAULT '',
            evidence_hash  VARCHAR(64) NOT NULL DEFAULT '',
            evidence_mime  VARCHAR(40) NOT NULL DEFAULT '',
            status         VARCHAR(12) NOT NULL DEFAULT 'pending',
            reading        TEXT,
            checks         TEXT,
            receipt_ref    VARCHAR(80) NOT NULL DEFAULT '',
            credited_ref   VARCHAR(80) NOT NULL DEFAULT '',
            submitted_by   VARCHAR(191) NOT NULL DEFAULT '',
            via            VARCHAR(8) NOT NULL DEFAULT 'member',
            decided_by     VARCHAR(191) NOT NULL DEFAULT '',
            decided_at     VARCHAR(32) NOT NULL DEFAULT '',
            decision_note  VARCHAR(500) NOT NULL DEFAULT '',
            created_at     VARCHAR(32) NOT NULL DEFAULT ''
        );
        CREATE INDEX IF NOT EXISTS idx_offpay_user ON offline_payments (user_id, id);
        CREATE INDEX IF NOT EXISTS idx_offpay_status ON offline_payments (status);
        CREATE INDEX IF NOT EXISTS idx_offpay_hash ON offline_payments (evidence_hash)");
    }

    private static function now(): string { return gmdate('Y-m-d H:i:s'); }
    private static function cfg(string $k, string $def = ''): string
    {
        if (class_exists('Config') && Config::has($k)) return Config::str($k, $def);
        $v = getenv($k); return $v === false || $v === '' ? $def : (string) $v;
    }
    public static function payeeNames(): array { return array_values(array_filter(array_map(static fn($x) => strtolower(trim($x)), explode(',', self::cfg('AV_OFFLINE_PAYEE_NAMES', 'Afrovanguard'))))); }
    public static function accounts(): array { return array_values(array_filter(array_map(static fn($x) => preg_replace('/\D/', '', $x), explode(',', self::cfg('AV_OFFLINE_ACCOUNTS', ''))))); }
    public static function maxAgeDays(): int { return max(1, (int) self::cfg('AV_OFFLINE_MAX_AGE_DAYS', '60')); }

    /** What dues for N months cost, the same prices the checkout charges. */
    public static function duesPrice(int $months): int
    {
        $annual = defined('AV_DUES_ANNUAL_NGN') ? (int) AV_DUES_ANNUAL_NGN : 12000;
        $monthly = defined('AV_DUES_MONTHLY_NGN') ? (int) AV_DUES_MONTHLY_NGN : 1000;
        return intdiv($months, 12) * $annual + ($months % 12) * $monthly;
    }

    /* ══ Submitting ═════════════════════════════════════════════════════════ */

    /**
     * A claim and its evidence. Validated, stored, read and checked at once;
     * credited only if it passes.
     *
     * $claim: purpose (dues|ngv), months (dues), amount_ngn, method, reference, paid_on
     * $via:   'member' (the payer) or 'staff' (the office, on their behalf)
     */
    public static function submit(int $userId, array $claim, string $bytes, string $mime, string $by, string $via = 'member'): array
    {
        self::ensure();
        $pdo = Database::pdo();
        $st = $pdo->prepare('SELECT id, email FROM lms_users WHERE id = ?'); $st->execute([$userId]);
        if (!$st->fetch()) return ['ok' => false, 'error' => 'No such account.'];

        $purpose = (string) ($claim['purpose'] ?? 'dues');
        if (!in_array($purpose, self::PURPOSES, true)) return ['ok' => false, 'error' => 'Say what the payment is for.'];
        $amount = (int) round((float) ($claim['amount_ngn'] ?? 0));
        if ($amount <= 0) return ['ok' => false, 'error' => 'Give the amount you paid.'];
        $method = strtolower(trim((string) ($claim['method'] ?? '')));
        if (!in_array($method, self::METHODS, true)) return ['ok' => false, 'error' => 'Say how it was paid: ' . implode(', ', self::METHODS) . '.'];
        $months = 0;
        if ($purpose === 'dues') {
            $months = (int) ($claim['months'] ?? 12);
            if ($months < 1 || $months > Membership::MAX_MONTHS) return ['ok' => false, 'error' => 'Dues are for between 1 and ' . Membership::MAX_MONTHS . ' months.'];
            $price = self::duesPrice($months);
            if ($amount < $price) return ['ok' => false, 'code' => 'short', 'error' => $months . ' month' . ($months === 1 ? '' : 's') . ' of dues is ₦' . number_format($price) . '. This says ₦' . number_format($amount) . '.'];
        } elseif (!NgvMember::participant($userId)) {
            return ['ok' => false, 'error' => 'NGV payments are for NextGen Vanguard participants.'];
        }
        /* NGV: the office may name the line it pays (the console's per-line
           record); a payer's own payment is allocated oldest-debt-first. */
        $line = $purpose === 'ngv' ? (string) ($claim['line'] ?? '') : '';
        if ($line !== '' && !in_array($line, NgvLedger::CREDIT_LINES, true)) return ['ok' => false, 'error' => 'Unknown account line.'];
        $period = mb_substr(trim((string) ($claim['period'] ?? '')), 0, 40);
        $paidOn = trim((string) ($claim['paid_on'] ?? ''));
        if ($paidOn !== '' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $paidOn) || $paidOn > gmdate('Y-m-d', time() + 86400))) return ['ok' => false, 'error' => 'The day you paid is a date, today or earlier.'];
        $reference = mb_substr(trim((string) ($claim['reference'] ?? '')), 0, 80);

        /* The evidence. */
        if ($bytes === '') return ['ok' => false, 'code' => 'evidence_required', 'error' => 'Upload the receipt, transfer slip or bank alert. An offline payment is credited only once its evidence is checked.'];
        if (strlen($bytes) > self::MAX_BYTES) return ['ok' => false, 'error' => 'That file is larger than 8 MB. A photo or PDF of the receipt is enough.'];
        $mime = strtolower(trim($mime));
        if (!in_array($mime, self::MIMES, true)) return ['ok' => false, 'error' => 'The evidence is a photo (JPEG, PNG, WebP, HEIC) or a PDF.'];
        $hash = hash('sha256', $bytes);
        $st = $pdo->prepare("SELECT id, status FROM offline_payments WHERE evidence_hash = ? AND status <> 'rejected' LIMIT 1"); $st->execute([$hash]);
        if ($prev = $st->fetch(PDO::FETCH_ASSOC)) return ['ok' => false, 'code' => 'duplicate_evidence', 'error' => 'This receipt has already been submitted (payment #' . $prev['id'] . ', ' . $prev['status'] . '). One receipt pays once.'];

        $file = 'offline-' . substr($hash, 0, 24) . '.' . self::EXT[$mime];
        $path = av_private_path($file);
        if (@file_put_contents($path, $bytes) === false) return ['ok' => false, 'error' => 'The evidence could not be stored. Try again.'];
        @chmod($path, 0600);

        $pdo->prepare('INSERT INTO offline_payments (user_id, purpose, months, line, period, amount_ngn, method, reference, paid_on, evidence_file, evidence_hash, evidence_mime, status, submitted_by, via, created_at)
                       VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([$userId, $purpose, $months, $line, $period, $amount, $method, $reference, $paidOn, $file, $hash, $mime, 'pending', mb_substr($by, 0, 191), $via === 'staff' ? 'staff' : 'member', self::now()]);
        $id = (int) $pdo->lastInsertId();
        self::audit('offline.submit', $userId, '#' . $id . ' · ₦' . number_format($amount) . ' by ' . $method . ' for ' . ($purpose === 'dues' ? $months . ' months of dues' : 'NGV'), $by);
        return self::verify($id, $by);
    }

    /* ══ Reading and checking ═══════════════════════════════════════════════ */

    private const PROMPT = "You are reading a photo or scan of PAYMENT EVIDENCE: a bank transfer receipt, a deposit slip, a POS slip, a bank SMS/app alert, or a cash receipt.\n"
        . "Report ONLY what is visibly on it. Do not guess, do not fill in, do not judge whether it should be accepted.\n"
        . "Return STRICT JSON, no prose, with exactly these keys:\n"
        . "{\"is_payment_evidence\": true|false, \"amount\": number|null (in naira, no symbols), \"currency\": \"NGN\"|string|null,\n"
        . " \"date\": \"YYYY-MM-DD\"|null, \"reference\": string|null (transaction/session/receipt number), \"payee_name\": string|null,\n"
        . " \"payee_account\": string|null (digits), \"payer_name\": string|null, \"bank\": string|null, \"status\": \"successful\"|\"pending\"|\"failed\"|null,\n"
        . " \"tampering_signs\": [string] (visible signs of editing: mismatched fonts, overwritten digits, cropped totals; empty if none),\n"
        . " \"confidence\": \"high\"|\"medium\"|\"low\" (how legible and complete the evidence is)}";

    /** What the receipt says, from the reader. */
    public static function read(string $bytes, string $mime): array
    {
        if (is_callable(self::$reader)) return (self::$reader)($bytes, $mime);
        if (!class_exists('Gemini') || !Gemini::configured()) return ['ok' => false, 'error' => 'Receipt reading is unavailable (Gemini is not configured).'];
        $r = Gemini::generate(self::PROMPT, ['temperature' => 0.0, 'max_tokens' => 800,
            'parts' => [['inline_data' => ['mime_type' => $mime === 'image/heif' ? 'image/heic' : $mime, 'data' => base64_encode($bytes)]]]]);
        if (empty($r['ok'])) return ['ok' => false, 'error' => 'The receipt could not be read: ' . ($r['error'] ?? 'no answer')];
        $txt = trim((string) $r['text']);
        $txt = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $txt) ?? $txt;
        $d = json_decode($txt, true);
        if (!is_array($d) && preg_match('/\{.*\}/s', $txt, $m)) $d = json_decode($m[0], true);
        return is_array($d) ? ['ok' => true, 'data' => $d] : ['ok' => false, 'error' => 'The reader did not return a readable answer.'];
    }

    /**
     * Compare what the receipt says with what was claimed. Pure: the reading
     * and the claim in, every check's verdict out. $usedRefs is the set of
     * receipt references that have already credited money.
     */
    public static function check(array $claim, array $reading, array $usedRefs = []): array
    {
        $c = [];
        $norm = static fn($s) => strtolower(preg_replace('/[^a-z0-9]/i', '', (string) $s) ?? '');
        $c['evidence'] = !empty($reading['is_payment_evidence']) && strtolower((string) ($reading['status'] ?? 'successful')) !== 'failed'
            ? null : 'This does not look like evidence of a completed payment.';
        $amt = isset($reading['amount']) && is_numeric($reading['amount']) ? (float) $reading['amount'] : null;
        $cur = strtoupper((string) ($reading['currency'] ?? 'NGN'));
        $c['amount'] = $amt === null ? 'No amount could be read on it.'
            : ($cur !== '' && $cur !== 'NGN' && $cur !== '₦' ? 'It is in ' . $cur . ', not naira.'
            : ((int) round($amt) !== (int) $claim['amount_ngn'] ? 'It shows ₦' . number_format($amt) . ', not the ₦' . number_format((int) $claim['amount_ngn']) . ' claimed.' : null));

        $names = self::payeeNames(); $accts = self::accounts();
        $pn = strtolower((string) ($reading['payee_name'] ?? '')); $pa = preg_replace('/\D/', '', (string) ($reading['payee_account'] ?? '')) ?? '';
        $nameOk = $pn !== '' && (bool) array_filter($names, static fn($n) => str_contains($pn, $n));
        $acctOk = $pa !== '' && (bool) array_filter($accts, static fn($a) => strlen($a) >= 6 && (str_ends_with($pa, substr($a, -6)) || str_ends_with($a, $pa) && strlen($pa) >= 4));
        $c['payee'] = ($nameOk || $acctOk) ? null
            : 'It does not show the money going to Afrovanguard' . ($pn !== '' ? ' (payee: ' . $reading['payee_name'] . ')' : '') . '.';

        $day = (string) ($reading['date'] ?? '');
        $ts = preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) ? strtotime($day . ' 12:00 UTC') : false;
        if (!$ts) $c['date'] = 'No date could be read on it.';
        elseif ($ts > time() + 86400) $c['date'] = 'Its date, ' . $day . ', is in the future.';
        elseif ($ts < time() - self::maxAgeDays() * 86400) $c['date'] = 'It is dated ' . $day . ' — older than ' . self::maxAgeDays() . ' days.';
        elseif (($claim['paid_on'] ?? '') !== '' && abs($ts - strtotime($claim['paid_on'] . ' 12:00 UTC')) > 3 * 86400) $c['date'] = 'It is dated ' . $day . ', not ' . $claim['paid_on'] . ' as given.';
        else $c['date'] = null;

        $rr = $norm($reading['reference'] ?? '');
        $c['reference'] = ($claim['reference'] ?? '') !== '' && $rr !== '' && !str_contains($rr, $norm($claim['reference'])) && !str_contains($norm($claim['reference']), $rr)
            ? 'Its reference is ' . $reading['reference'] . ', not ' . $claim['reference'] . '.' : null;
        $c['reused'] = $rr !== '' && in_array($rr, $usedRefs, true) ? 'A payment with this receipt\'s reference (' . $reading['reference'] . ') has already been credited.' : null;
        $tamper = array_values(array_filter((array) ($reading['tampering_signs'] ?? []), static fn($x) => trim((string) $x) !== ''));
        $c['tampering'] = $tamper ? 'Possible editing: ' . implode('; ', array_slice($tamper, 0, 3)) . '.' : null;
        $c['confidence'] = strtolower((string) ($reading['confidence'] ?? '')) === 'low' ? 'The evidence is too hard to read to be sure of it.' : null;
        $failed = array_filter($c);
        return ['passed' => !$failed, 'failed' => $failed, 'checks' => array_map(static fn($v) => $v === null ? 'ok' : $v, $c)];
    }

    private static function usedRefs(int $exceptId): array
    {
        $st = Database::pdo()->prepare("SELECT receipt_ref FROM offline_payments WHERE status = 'verified' AND receipt_ref <> '' AND id <> ?");
        $st->execute([$exceptId]);
        return array_map('strval', $st->fetchAll(PDO::FETCH_COLUMN));
    }

    /** Read and check one payment; credit it if it passes. Safe to repeat. */
    public static function verify(int $id, string $by = 'system'): array
    {
        self::ensure();
        $p = self::get($id);
        if (!$p) return ['ok' => false, 'error' => 'No such payment.'];
        if ($p['status'] === 'verified') return ['ok' => true, 'status' => 'verified', 'payment' => $p, 'unchanged' => true];
        if ($p['status'] === 'rejected') return ['ok' => false, 'code' => 'rejected', 'error' => 'This payment was rejected. Submit it again with better evidence.'];
        $bytes = (string) @file_get_contents(av_private_path($p['evidence_file']));
        $read = $bytes === '' ? ['ok' => false, 'error' => 'The evidence file is missing.'] : self::read($bytes, (string) $p['evidence_mime']);
        $pdo = Database::pdo();
        if (!$read['ok']) {
            $pdo->prepare("UPDATE offline_payments SET status = 'held', checks = ? WHERE id = ?")->execute([json_encode(['reader' => $read['error']]), $id]);
            self::audit('offline.held', (int) $p['user_id'], '#' . $id . ' · ' . $read['error'], $by);
            return ['ok' => true, 'status' => 'held', 'reasons' => [$read['error']], 'payment' => self::get($id)];
        }
        $reading = $read['data'];
        $norm = strtolower(preg_replace('/[^a-z0-9]/i', '', (string) ($reading['reference'] ?? '')) ?? '');
        $res = self::check($p, $reading, self::usedRefs($id));
        $pdo->prepare('UPDATE offline_payments SET reading = ?, checks = ?, receipt_ref = ? WHERE id = ?')
            ->execute([json_encode($reading, JSON_UNESCAPED_UNICODE), json_encode($res['checks'], JSON_UNESCAPED_UNICODE), mb_substr($norm, 0, 80), $id]);
        if (!$res['passed']) {
            $pdo->prepare("UPDATE offline_payments SET status = 'held' WHERE id = ?")->execute([$id]);
            self::audit('offline.held', (int) $p['user_id'], '#' . $id . ' · ' . implode(' ', $res['failed']), $by);
            return ['ok' => true, 'status' => 'held', 'reasons' => array_values($res['failed']), 'payment' => self::get($id)];
        }
        return self::credit($id, $by, 'verified by receipt');
    }

    /**
     * Credit a verified payment through the path that owns the money — never
     * a second implementation of it. Once only: the status flips first, and
     * both ledgers are idempotent on the reference given here.
     */
    private static function credit(int $id, string $by, string $how): array
    {
        $pdo = Database::pdo();
        $st = $pdo->prepare("UPDATE offline_payments SET status = 'verified', decided_by = ?, decided_at = ? WHERE id = ? AND status <> 'verified'");
        $st->execute([mb_substr($by, 0, 191), self::now(), $id]);
        if ($st->rowCount() === 0) return ['ok' => true, 'status' => 'verified', 'unchanged' => true, 'payment' => self::get($id)];
        $p = self::get($id);
        $ref = 'OFFLINE-' . $id;
        $note = 'Offline ' . $p['method'] . ' payment #' . $id . ', ' . $how;
        if ($p['purpose'] === 'dues') {
            $r = Membership::grant((int) $p['user_id'], ['months' => (int) $p['months'], 'amount_ngn' => (int) $p['amount_ngn'],
                'method' => in_array($p['method'], Membership::METHODS, true) ? $p['method'] : ($p['method'] === 'deposit' ? 'transfer' : 'other'),
                'reference' => 'P' . $id, 'note' => $note, 'actor' => $by, 'verified_offline' => $id]);
        } elseif ((string) $p['line'] !== '') {
            $r = NgvLedger::payment((int) $p['user_id'], (string) $p['line'], (int) $p['amount_ngn'],
                ['period' => (string) $p['period'], 'method' => $p['method'], 'reference' => $ref, 'note' => $note, 'receipt' => true], 0);
        } else {
            $r = NgvLedger::payOnline((int) $p['user_id'], $ref, (int) $p['amount_ngn'], ['method' => $p['method'], 'note' => $note]);
        }
        if (empty($r['ok'])) {
            /* Not credited: say so, and leave it to be looked at — never "verified" with no money moved. */
            $pdo->prepare("UPDATE offline_payments SET status = 'held', checks = ? WHERE id = ?")->execute([json_encode(['credit' => $r['error'] ?? 'not credited']), $id]);
            return ['ok' => false, 'status' => 'held', 'error' => 'Verified, but it could not be credited: ' . ($r['error'] ?? 'unknown') . '.', 'payment' => self::get($id)];
        }
        $pdo->prepare('UPDATE offline_payments SET credited_ref = ? WHERE id = ?')->execute([$p['purpose'] === 'dues' ? (string) ($r['reference'] ?? '') : $ref, $id]);
        self::audit('offline.credited', (int) $p['user_id'], '#' . $id . ' · ₦' . number_format((int) $p['amount_ngn']) . ' · ' . $how, $by);
        if (class_exists('Notifications')) {
            try { Notifications::push((int) $p['user_id'], 'payment', 'Payment confirmed', 'Your ₦' . number_format((int) $p['amount_ngn']) . ' ' . $p['method'] . ' payment has been checked and credited.', '/portal/', 'offline:' . $id); }
            catch (Throwable $e) { error_log('[offline] notify: ' . $e->getMessage()); }
        }
        return ['ok' => true, 'status' => 'verified', 'payment' => self::get($id)];
    }

    /* ══ Staff decisions ════════════════════════════════════════════════════ */

    /** Reject a held payment, with a reason the payer is shown. */
    public static function reject(int $id, string $note, string $by): array
    {
        self::ensure();
        $p = self::get($id);
        if (!$p) return ['ok' => false, 'error' => 'No such payment.'];
        if ($p['status'] === 'verified') return ['ok' => false, 'code' => 'credited', 'error' => 'This payment has been credited. Reverse it on the ledger instead.'];
        $note = mb_substr(trim($note), 0, 500);
        if ($note === '') return ['ok' => false, 'code' => 'reason_required', 'error' => 'Say why — the payer is shown this.'];
        Database::pdo()->prepare("UPDATE offline_payments SET status = 'rejected', decided_by = ?, decided_at = ?, decision_note = ? WHERE id = ?")
            ->execute([mb_substr($by, 0, 191), self::now(), $note, $id]);
        self::audit('offline.rejected', (int) $p['user_id'], '#' . $id . ' · ' . $note, $by);
        return ['ok' => true, 'status' => 'rejected', 'payment' => self::get($id)];
    }

    /**
     * Approve a payment the checks did not pass. A Super Admin's, with a
     * written reason, recorded on the payment and in the audit for ever — the
     * way through when the reader is unavailable or misread a real receipt.
     * Never for a receipt already used: that check is not overridable.
     */
    public static function override(int $id, string $note, string $by, string $studioRole): array
    {
        self::ensure();
        if ($studioRole !== 'superadmin') return ['ok' => false, 'code' => 'superadmin_only', 'error' => 'Only a Super Admin can approve a payment its evidence did not verify.'];
        $p = self::get($id);
        if (!$p) return ['ok' => false, 'error' => 'No such payment.'];
        if ($p['status'] !== 'held') return ['ok' => false, 'error' => 'Only a held payment can be approved this way.'];
        $note = mb_substr(trim($note), 0, 500);
        if (mb_strlen($note) < 10) return ['ok' => false, 'code' => 'reason_required', 'error' => 'Write down why you are approving it against the check — at least a sentence.'];
        $checks = is_array($p['checks'] ?? null) ? $p['checks'] : [];   // get() has decoded them
        if (($checks['reused'] ?? 'ok') !== 'ok') return ['ok' => false, 'code' => 'reused', 'error' => 'This receipt has already credited money. That is not overridden.'];
        Database::pdo()->prepare('UPDATE offline_payments SET decision_note = ? WHERE id = ?')->execute(['OVERRIDE: ' . $note, $id]);
        self::audit('offline.override', (int) $p['user_id'], '#' . $id . ' · ' . $note, $by);
        return self::credit($id, $by, 'approved by a Super Admin against the check: ' . $note);
    }

    /* ══ Reading the record ═════════════════════════════════════════════════ */

    public static function get(int $id): ?array
    {
        self::ensure();
        $st = Database::pdo()->prepare('SELECT * FROM offline_payments WHERE id = ?'); $st->execute([$id]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r) return null;
        $r['reading'] = json_decode((string) ($r['reading'] ?? ''), true);
        $r['checks'] = json_decode((string) ($r['checks'] ?? ''), true);
        return $r;
    }

    /** For staff: every offline payment, held first. */
    public static function queue(string $status = '', int $limit = 200): array
    {
        self::ensure();
        $w = $status !== '' ? 'WHERE o.status = ?' : '';
        $st = Database::pdo()->prepare("SELECT o.id, o.user_id, o.purpose, o.months, o.amount_ngn, o.method, o.reference, o.paid_on, o.status, o.checks, o.via, o.submitted_by, o.decided_by, o.decision_note, o.created_at, u.name, u.email
              FROM offline_payments o LEFT JOIN lms_users u ON u.id = o.user_id $w
             ORDER BY CASE o.status WHEN 'held' THEN 0 WHEN 'pending' THEN 1 ELSE 2 END, o.id DESC LIMIT " . max(1, min(500, $limit)));
        $st->execute($status !== '' ? [$status] : []);
        return array_map(static fn($r) => ['checks' => json_decode((string) ($r['checks'] ?? ''), true)] + $r, $st->fetchAll(PDO::FETCH_ASSOC));
    }

    /** For the payer: their own, newest first, with what the checks said. */
    public static function forUser(int $userId): array
    {
        self::ensure();
        $st = Database::pdo()->prepare('SELECT id, purpose, months, amount_ngn, method, reference, paid_on, status, checks, decision_note, created_at FROM offline_payments WHERE user_id = ? ORDER BY id DESC LIMIT 50');
        $st->execute([$userId]);
        return array_map(static function ($r) {
            $c = json_decode((string) ($r['checks'] ?? ''), true) ?: [];
            $r['reasons'] = array_values(array_filter($c, static fn($v) => $v !== 'ok'));
            unset($r['checks']);
            if (str_starts_with((string) $r['decision_note'], 'OVERRIDE:')) $r['decision_note'] = '';   // staff's note, not the payer's
            return $r;
        }, $st->fetchAll(PDO::FETCH_ASSOC));
    }

    /** The evidence file, for staff to look at. [bytes, mime] or null. */
    public static function evidence(int $id): ?array
    {
        $p = self::get($id);
        if (!$p) return null;
        $b = @file_get_contents(av_private_path((string) $p['evidence_file']));
        return $b === false ? null : [$b, (string) $p['evidence_mime']];
    }

    public static function counts(): array
    {
        self::ensure();
        return Database::pdo()->query('SELECT status, COUNT(*) FROM offline_payments GROUP BY status')->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
    }

    private static function audit(string $action, int $userId, string $detail, string $by): void
    {
        $st = Database::pdo()->prepare('SELECT email FROM lms_users WHERE id = ?'); $st->execute([$userId]);
        (new LmsRepository())->audit($action, strtolower((string) $st->fetchColumn()), $detail, $by);
    }
}
