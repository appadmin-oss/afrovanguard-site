<?php
/**
 * lib/NgvMember.php — NextGen Vanguard participant domain (over NgvDb).
 *
 * The real data behind the member dashboard and the staff console: enrolment
 * (one participant row per member), a fee-payment ledger, and certifications.
 * All reads/writes go to the SEPARATE NGV database (lib/NgvDb.php).
 *
 * Money model is intentionally simple and non-destructive: payments are append-
 * only rows (void, never delete). Fee *status* is computed by comparing recorded
 * payments against the programme's expected commitments.
 */
declare(strict_types=1);

final class NgvMember
{
    /* Expected commitments (naira). Kept here as the single source of truth for
     * fee status; mirrors the fee lines on the public NGV page. */
    public const MEMBERSHIP_YEARLY   = 10000;
    public const COMMITMENT_MONTHLY  = 1000;

    public const STATUSES = ['applicant', 'active', 'completed', 'paused', 'withdrawn'];
    public const PHASES   = ['', '1', '2', 'done'];
    public const KINDS    = ['membership', 'commitment', 'programme', 'other'];
    private const AMOUNT_MAX = 100000000; // ₦100m per row — a sane ceiling

    /* ── helpers ─────────────────────────────────────────────────────── */
    private static function today(string $fmt = 'Y-m-d'): string
    {
        if (function_exists('av_today_tz') && $fmt === 'Y-m-d') return av_today_tz();
        if (function_exists('av_now_tz')) return av_now_tz($fmt);
        return date($fmt);
    }
    private static function trackNames(): array
    {
        $out = [];
        if (class_exists('Ngv')) { foreach ((Ngv::get()['tracks'] ?? []) as $t) { if (!empty($t['name'])) $out[] = (string) $t['name']; } }
        return $out;
    }
    private static function money(int $n): string { return '₦' . number_format(max(0, $n)); }

    /* ── participants ────────────────────────────────────────────────── */
    public static function participant(int $memberId): ?array
    {
        if ($memberId <= 0) return null;
        $st = NgvDb::pdo()->prepare('SELECT * FROM ngv_participants WHERE member_id = ?');
        $st->execute([$memberId]);
        $r = $st->fetch();
        return $r ?: null;
    }

    /**
     * Return the participant row, creating it on first sight. $seed may carry
     * name/email (snapshot for staff lists) and initial self-tracked values
     * (track/phase/books/focus_note) — used to migrate a member's prior
     * Prefs-based state into the NGV DB exactly once, at enrolment.
     */
    public static function ensureParticipant(int $memberId, array $seed = []): array
    {
        $p = self::participant($memberId);
        if ($p) return $p;

        $track = self::validTrack((string) ($seed['track'] ?? ''));
        $phaseIn = (string) ($seed['phase'] ?? '');
        $phase = in_array($phaseIn, self::PHASES, true) ? $phaseIn : '';
        $books = self::validBooks((string) ($seed['books'] ?? ''));
        $note  = mb_substr(trim((string) ($seed['focus_note'] ?? '')), 0, 300);
        $now   = NgvDb::nowExpr();
        $sql = "INSERT INTO ngv_participants (member_id,name,email,track,phase,books,focus_note,status,start_date,created_at,updated_at)
                VALUES (?,?,?,?,?,?,?, 'active', ?, {$now}, {$now})";
        try {
            NgvDb::pdo()->prepare($sql)->execute([
                $memberId,
                mb_substr(trim((string) ($seed['name'] ?? '')), 0, 120),
                mb_substr(trim((string) ($seed['email'] ?? '')), 0, 160),
                $track, $phase, $books, $note, self::today(),
            ]);
        } catch (Throwable $e) {
            // Unique race: another request created it — just read it back.
            error_log('[ngv] ensureParticipant: ' . $e->getMessage());
        }
        return self::participant($memberId) ?? [
            'member_id' => $memberId, 'track' => $track, 'phase' => $phase,
            'books' => $books, 'focus_note' => $note, 'status' => 'active',
        ];
    }

    /** Member-editable self fields: track, phase, books, focus_note. */
    public static function saveSelf(int $memberId, array $patch): void
    {
        self::ensureParticipant($memberId);
        $set = [];
        $args = [];
        if (array_key_exists('track', $patch))      { $set[] = 'track = ?';      $args[] = self::validTrack((string) $patch['track']); }
        if (array_key_exists('phase', $patch))      { $v = (string) $patch['phase']; if (in_array($v, self::PHASES, true)) { $set[] = 'phase = ?'; $args[] = $v; } }
        if (array_key_exists('books', $patch))      { $set[] = 'books = ?';      $args[] = self::validBooks((string) $patch['books']); }
        if (array_key_exists('focus_note', $patch)) { $set[] = 'focus_note = ?'; $args[] = mb_substr(trim((string) $patch['focus_note']), 0, 300); }
        if (!$set) return;
        $set[] = 'updated_at = ' . NgvDb::nowExpr();
        $args[] = $memberId;
        NgvDb::pdo()->prepare('UPDATE ngv_participants SET ' . implode(', ', $set) . ' WHERE member_id = ?')->execute($args);
    }

    /** Staff-editable fields: status, cohort, track, phase. */
    public static function setAdmin(int $memberId, array $patch): void
    {
        self::ensureParticipant($memberId);
        $set = []; $args = [];
        if (isset($patch['status']) && in_array((string) $patch['status'], self::STATUSES, true)) { $set[] = 'status = ?'; $args[] = (string) $patch['status']; }
        if (array_key_exists('cohort', $patch)) { $set[] = 'cohort = ?'; $args[] = mb_substr(trim((string) $patch['cohort']), 0, 60); }
        if (array_key_exists('track', $patch))  { $set[] = 'track = ?';  $args[] = self::validTrack((string) $patch['track']); }
        if (array_key_exists('phase', $patch) && in_array((string) $patch['phase'], self::PHASES, true)) { $set[] = 'phase = ?'; $args[] = (string) $patch['phase']; }
        if (!$set) return;
        $set[] = 'updated_at = ' . NgvDb::nowExpr();
        $args[] = $memberId;
        NgvDb::pdo()->prepare('UPDATE ngv_participants SET ' . implode(', ', $set) . ' WHERE member_id = ?')->execute($args);
    }

    /* ── payments ────────────────────────────────────────────────────── */
    public static function recordPayment(int $memberId, array $p, int $byUid): bool
    {
        if ($memberId <= 0) return false;
        self::ensureParticipant($memberId);
        $kind = in_array((string) ($p['kind'] ?? ''), self::KINDS, true) ? (string) $p['kind'] : 'commitment';
        $amount = (int) round((float) ($p['amount'] ?? 0));
        if ($amount < 0) $amount = 0;
        if ($amount > self::AMOUNT_MAX) $amount = self::AMOUNT_MAX;
        $period = (string) ($p['period'] ?? '');
        if ($period !== '' && !preg_match('/^\d{4}(-\d{2})?$/', $period)) $period = '';
        $now = NgvDb::nowExpr();
        NgvDb::pdo()->prepare(
            "INSERT INTO ngv_payments (member_id,kind,amount,currency,period,method,reference,note,recorded_by,created_at)
             VALUES (?,?,?,?,?,?,?,?,?,{$now})"
        )->execute([
            $memberId, $kind, $amount, 'NGN', $period,
            mb_substr(trim((string) ($p['method'] ?? '')), 0, 40),
            mb_substr(trim((string) ($p['reference'] ?? '')), 0, 80),
            mb_substr(trim((string) ($p['note'] ?? '')), 0, 200),
            max(0, $byUid),
        ]);
        return true;
    }

    public static function voidPayment(int $id, int $byUid): bool
    {
        if ($id <= 0) return false;
        NgvDb::pdo()->prepare('UPDATE ngv_payments SET voided = 1 WHERE id = ?')->execute([$id]);
        return true;
    }

    /** Non-void payments, newest first. */
    public static function payments(int $memberId): array
    {
        $st = NgvDb::pdo()->prepare('SELECT * FROM ngv_payments WHERE member_id = ? AND voided = 0 ORDER BY created_at DESC, id DESC');
        $st->execute([$memberId]);
        return $st->fetchAll() ?: [];
    }

    /** Secret backing the unforgeable, storage-free certificate verification code. */
    private static function certSecret(): string
    {
        if (function_exists('av_secret')) { $s = (string) av_secret(); if ($s !== '') return $s; }
        return 'ngv-cert-fallback';
    }

    /** A short, shareable verification code derived from the cert id (HMAC). */
    public static function certCode(int $id): string
    {
        return 'NGV-' . strtoupper(substr(hash_hmac('sha256', 'ngv-cert:' . $id, self::certSecret()), 0, 10));
    }

    /** Certifications, newest first — each row carries its verification `code`. */
    public static function certifications(int $memberId): array
    {
        $st = NgvDb::pdo()->prepare('SELECT * FROM ngv_certifications WHERE member_id = ? ORDER BY issued_on DESC, id DESC');
        $st->execute([$memberId]);
        $rows = $st->fetchAll() ?: [];
        foreach ($rows as &$r) $r['code'] = self::certCode((int) $r['id']);
        return $rows;
    }

    /**
     * Verify + fetch a certificate for the public certificate page. Returns the
     * cert row + the holder's name only when the code matches the id (constant
     * time). Null otherwise — an invalid or tampered link reveals nothing.
     */
    public static function certForVerify(int $id, string $code): ?array
    {
        if ($id <= 0) return null;
        if (!hash_equals(self::certCode($id), strtoupper(trim($code)))) return null;
        $st = NgvDb::pdo()->prepare('SELECT * FROM ngv_certifications WHERE id = ?');
        $st->execute([$id]);
        $cert = $st->fetch();
        if (!$cert) return null;
        $p = self::participant((int) $cert['member_id']);
        $cert['code'] = self::certCode($id);
        return ['cert' => $cert, 'name' => $p ? (string) ($p['name'] ?: '') : '', 'cohort' => $p ? (string) ($p['cohort'] ?? '') : ''];
    }

    public static function addCertification(int $memberId, array $c): bool
    {
        if ($memberId <= 0) return false;
        $title = mb_substr(trim((string) ($c['title'] ?? '')), 0, 120);
        if ($title === '') return false;
        self::ensureParticipant($memberId);
        $now = NgvDb::nowExpr();
        NgvDb::pdo()->prepare(
            "INSERT INTO ngv_certifications (member_id,title,issued_on,issued_by,reference,created_at) VALUES (?,?,?,?,?,{$now})"
        )->execute([
            $memberId, $title,
            mb_substr(trim((string) ($c['issued_on'] ?? '')), 0, 20),
            mb_substr(trim((string) ($c['issued_by'] ?? '')), 0, 80),
            mb_substr(trim((string) ($c['reference'] ?? '')), 0, 80),
        ]);
        return true;
    }

    /**
     * Fee status: compare recorded payments to the expected commitments for the
     * current year (membership) and month (commitment). Returns display-ready
     * lines plus the total collected — real numbers, straight from the ledger.
     */
    public static function feeStatus(int $memberId): array
    {
        $year  = self::today('Y');
        $month = self::today('Y-m');
        $rows  = self::payments($memberId);

        $sum = static function (array $rows, string $kind, ?string $period) {
            $t = 0;
            foreach ($rows as $r) {
                if (($r['kind'] ?? '') !== $kind) continue;
                if ($period !== null && (string) ($r['period'] ?? '') !== $period) continue;
                $t += (int) ($r['amount'] ?? 0);
            }
            return $t;
        };

        $memberPaid = $sum($rows, 'membership', $year);
        $commPaid   = $sum($rows, 'commitment', $month);
        $total = 0; foreach ($rows as $r) $total += (int) ($r['amount'] ?? 0);

        $lines = [
            [
                'key' => 'membership', 'label' => 'Membership (' . $year . ')',
                'expected' => self::MEMBERSHIP_YEARLY, 'paid' => $memberPaid,
                'ok' => $memberPaid >= self::MEMBERSHIP_YEARLY,
                'detail' => self::money($memberPaid) . ' of ' . self::money(self::MEMBERSHIP_YEARLY) . ' this year',
            ],
            [
                'key' => 'commitment', 'label' => 'Commitment (this month)',
                'expected' => self::COMMITMENT_MONTHLY, 'paid' => $commPaid,
                'ok' => $commPaid >= self::COMMITMENT_MONTHLY,
                'detail' => self::money($commPaid) . ' of ' . self::money(self::COMMITMENT_MONTHLY) . ' for ' . $month,
            ],
        ];
        return ['lines' => $lines, 'total' => $total, 'count' => count($rows), 'has_any' => $rows !== []];
    }

    /* ── staff roster + overview ─────────────────────────────────────── */
    public static function roster(string $status = '', int $limit = 200): array
    {
        $limit = max(1, min(1000, $limit));
        $sql = 'SELECT p.*,
                  (SELECT COALESCE(SUM(x.amount),0) FROM ngv_payments x WHERE x.member_id = p.member_id AND x.voided = 0) AS paid_total,
                  (SELECT COUNT(*) FROM ngv_certifications c WHERE c.member_id = p.member_id) AS cert_count
                FROM ngv_participants p';
        $args = [];
        if ($status !== '' && in_array($status, self::STATUSES, true)) { $sql .= ' WHERE p.status = ?'; $args[] = $status; }
        $sql .= ' ORDER BY p.updated_at DESC, p.id DESC LIMIT ' . $limit;
        $st = NgvDb::pdo()->prepare($sql);
        $st->execute($args);
        return $st->fetchAll() ?: [];
    }

    public static function stats(): array
    {
        $pdo = NgvDb::pdo();
        $byStatus = [];
        foreach ($pdo->query('SELECT status, COUNT(*) c FROM ngv_participants GROUP BY status')->fetchAll() ?: [] as $r) {
            $byStatus[(string) $r['status']] = (int) $r['c'];
        }
        $total     = (int) $pdo->query('SELECT COUNT(*) FROM ngv_participants')->fetchColumn();
        $collected = (int) $pdo->query('SELECT COALESCE(SUM(amount),0) FROM ngv_payments WHERE voided = 0')->fetchColumn();
        $certs     = (int) $pdo->query('SELECT COUNT(*) FROM ngv_certifications')->fetchColumn();
        $appsNew   = (int) $pdo->query("SELECT COUNT(*) FROM ngv_applications WHERE status IN ('new','reviewing')")->fetchColumn();
        $appsTotal = (int) $pdo->query('SELECT COUNT(*) FROM ngv_applications')->fetchColumn();
        return ['total' => $total, 'by_status' => $byStatus, 'collected' => $collected, 'certs' => $certs,
                'apps_pending' => $appsNew, 'apps_total' => $appsTotal];
    }

    /* ── applications (public registration intake) ───────────────────── */
    public const APP_STATUSES = ['new', 'reviewing', 'accepted', 'rejected', 'enrolled'];

    /**
     * Store a public registration. Returns the new id, or 0 on invalid input
     * (missing name/email). Callers own spam/rate defenses; this validates and
     * sanitises. track/plan are accepted only when they match live content.
     */
    public static function submitApplication(array $d): int
    {
        $name  = mb_substr(trim((string) ($d['name'] ?? '')), 0, 120);
        $email = mb_substr(trim((string) ($d['email'] ?? '')), 0, 160);
        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) return 0;
        $now = NgvDb::nowExpr();
        $st = NgvDb::pdo()->prepare(
            "INSERT INTO ngv_applications (name,email,phone,age,gender,location,track,plan,education,message,status,source,created_at)
             VALUES (?,?,?,?,?,?,?,?,?,?, 'new', ?, {$now})"
        );
        $st->execute([
            $name, $email,
            mb_substr(trim((string) ($d['phone'] ?? '')), 0, 40),
            mb_substr(trim((string) ($d['age'] ?? '')), 0, 12),
            mb_substr(trim((string) ($d['gender'] ?? '')), 0, 24),
            mb_substr(trim((string) ($d['location'] ?? '')), 0, 120),
            self::validTrack((string) ($d['track'] ?? '')),
            self::validPlan((string) ($d['plan'] ?? '')),
            mb_substr(trim((string) ($d['education'] ?? '')), 0, 80),
            mb_substr(trim((string) ($d['message'] ?? '')), 0, 1500),
            mb_substr(trim((string) ($d['source'] ?? 'web')), 0, 24),
        ]);
        return (int) NgvDb::pdo()->lastInsertId();
    }

    public static function applications(string $status = '', int $limit = 300): array
    {
        $limit = max(1, min(1000, $limit));
        $sql = 'SELECT * FROM ngv_applications';
        $args = [];
        if ($status !== '' && in_array($status, self::APP_STATUSES, true)) { $sql .= ' WHERE status = ?'; $args[] = $status; }
        $sql .= ' ORDER BY created_at DESC, id DESC LIMIT ' . $limit;
        $st = NgvDb::pdo()->prepare($sql); $st->execute($args);
        return $st->fetchAll() ?: [];
    }

    public static function application(int $id): ?array
    {
        $st = NgvDb::pdo()->prepare('SELECT * FROM ngv_applications WHERE id = ?');
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    /**
     * If the applicant already has a member (lms_users) account with the same
     * email, link the application to it — without enrolling. Reduces later
     * friction: staff can enrol in one click and the "no account yet" case is
     * pre-resolved. Keeps status 'new' so a human still reviews. Returns whether
     * a link was made.
     */
    public static function autolinkApplication(int $id): bool
    {
        $app = self::application($id);
        if (!$app || (int) ($app['member_id'] ?? 0) > 0) return false;
        $email = trim((string) ($app['email'] ?? ''));
        if ($email === '' || !class_exists('Database')) return false;
        try {
            $st = Database::pdo()->prepare('SELECT id FROM lms_users WHERE email = ?');
            $st->execute([$email]);
            $m = $st->fetch();
        } catch (Throwable $e) { return false; }
        if (!$m) return false;
        NgvDb::pdo()->prepare('UPDATE ngv_applications SET member_id = ? WHERE id = ?')->execute([(int) $m['id'], $id]);
        return true;
    }

    public static function setApplicationStatus(int $id, string $status, int $byUid): bool
    {
        if ($id <= 0 || !in_array($status, self::APP_STATUSES, true)) return false;
        NgvDb::pdo()->prepare('UPDATE ngv_applications SET status = ?, reviewed_by = ? WHERE id = ?')
            ->execute([$status, max(0, $byUid), $id]);
        return true;
    }

    /**
     * Turn an application into an enrolled participant. Requires the applicant to
     * already have a member (lms_users) account with the same email — that's the
     * only cross-database lookup, done against the MAIN db. If none exists yet we
     * mark the application 'accepted' and report back so staff can ask them to
     * create an account. On success the participant is created/activated in the
     * NGV db and the application is linked + marked 'enrolled'.
     */
    public static function enrollApplication(int $id, int $byUid): array
    {
        $app = self::application($id);
        if (!$app) return ['ok' => false, 'error' => 'Application not found.'];
        $email = (string) $app['email'];
        $member = null;
        if (class_exists('Database') && $email !== '') {
            try {
                $st = Database::pdo()->prepare('SELECT id, name, email FROM lms_users WHERE email = ?');
                $st->execute([$email]);
                $member = $st->fetch() ?: null;
            } catch (Throwable $e) { error_log('[ngv] enrollApplication lookup: ' . $e->getMessage()); }
        }
        if (!$member) {
            self::setApplicationStatus($id, 'accepted', $byUid);
            return ['ok' => false, 'error' => 'No member account for ' . $email . ' yet — accepted. Ask them to create an account with this email, then enrol.'];
        }
        $mid = (int) $member['id'];
        self::ensureParticipant($mid, ['name' => (string) $member['name'], 'email' => $email]);
        self::setAdmin($mid, ['status' => 'active', 'track' => (string) $app['track']]);
        NgvDb::pdo()->prepare('UPDATE ngv_applications SET status = ?, member_id = ?, reviewed_by = ? WHERE id = ?')
            ->execute(['enrolled', $mid, max(0, $byUid), $id]);
        return ['ok' => true, 'member_id' => $mid];
    }

    /* ── validation ──────────────────────────────────────────────────── */
    private static function validPlan(string $p): string
    {
        $p = trim($p);
        if ($p === '') return '';
        $names = [];
        if (class_exists('Ngv')) { foreach ((Ngv::get()['plans'] ?? []) as $pl) { if (!empty($pl['name'])) $names[] = (string) $pl['name']; } }
        return ($names === [] || in_array($p, $names, true)) ? mb_substr($p, 0, 80) : '';
    }

    private static function validTrack(string $t): string
    {
        $t = trim($t);
        if ($t === '') return '';
        $names = self::trackNames();
        return ($names === [] || in_array($t, $names, true)) ? mb_substr($t, 0, 80) : '';
    }
    private static function validBooks(string $b): string
    {
        if (!preg_match('/^[01]{0,24}$/', $b)) return str_repeat('0', 24);
        return str_pad(substr($b, 0, 24), 24, '0');
    }
}
