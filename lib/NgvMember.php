<?php
/**
 * lib/NgvMember.php — NextGen Vanguard participant domain (over NgvDb).
 *
 * The real data behind the member dashboard and the staff console: enrolment
 * (one participant row per member), certifications, applications, and the
 * money. All reads/writes go to the SEPARATE NGV database (lib/NgvDb.php).
 *
 * MONEY LIVES IN lib/NgvLedger.php, not here. This file used to compute fee
 * status by comparing recorded payments against two constants, which answered
 * "have they paid this month" and nothing else — no charges, no fines, no
 * waivers, no arrears list, no audit trail, and two figures that quietly
 * disagreed with the ones on the public page. Everything money-shaped below is
 * now a thin, back-compatible pass-through to the ledger so the dashboard and
 * the staff console read one model.
 */
declare(strict_types=1);

final class NgvMember
{
    /* Kept only so anything still reading them gets a sane number. They are NOT
     * the source of truth any more: `NgvLedger::amounts()` reads the figures off
     * the public page, which is where an admin actually edits them, and pins an
     * override when the page cannot express what is charged. Two hard-coded
     * constants and an editable page are how a participant ends up holding a
     * receipt that disagrees with the website. */
    public const MEMBERSHIP_YEARLY   = NgvLedger::MEMBERSHIP_FALLBACK;
    public const COMMITMENT_MONTHLY  = NgvLedger::COMMITMENT_FALLBACK;

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
        if ($p) {
            /* Name and email are SNAPSHOTS taken at enrolment, and the email is
               where receipts, reminders and statements go. A member who changes
               it on the main site would otherwise keep a dead address here for
               good — every letter silently delivered nowhere. So the snapshot is
               refreshed whenever the member is present with a fresher one, which
               is the only moment we can be sure the newer value is theirs. */
            $freshName  = mb_substr(trim((string) ($seed['name'] ?? '')), 0, 120);
            $freshEmail = mb_substr(trim((string) ($seed['email'] ?? '')), 0, 160);
            $set = []; $args = [];
            if ($freshName !== '' && $freshName !== (string) $p['name'])   { $set[] = 'name = ?';  $args[] = $freshName; }
            if ($freshEmail !== '' && $freshEmail !== (string) $p['email']) { $set[] = 'email = ?'; $args[] = $freshEmail; }
            if ($set) {
                $args[] = $memberId;
                try {
                    NgvDb::pdo()->prepare('UPDATE ngv_participants SET ' . implode(', ', $set)
                        . ', updated_at = ' . NgvDb::nowExpr() . ' WHERE member_id = ?')->execute($args);
                    $p = self::participant($memberId) ?: $p;
                } catch (Throwable $e) { error_log('[ngv] snapshot refresh: ' . $e->getMessage()); }
            }
            return $p;
        }

        $track = self::validTrack((string) ($seed['track'] ?? ''));
        $plan  = self::validPlan((string) ($seed['plan'] ?? ''));
        $phaseIn = (string) ($seed['phase'] ?? '');
        $phase = in_array($phaseIn, self::PHASES, true) ? $phaseIn : '';
        $books = self::validBooks((string) ($seed['books'] ?? ''));
        $note  = mb_substr(trim((string) ($seed['focus_note'] ?? '')), 0, 300);
        $now   = NgvDb::nowExpr();
        $sql = "INSERT INTO ngv_participants (member_id,name,email,track,plan,phase,books,focus_note,status,start_date,created_at,updated_at)
                VALUES (?,?,?,?,?,?,?,?, 'active', ?, {$now}, {$now})";
        try {
            NgvDb::pdo()->prepare($sql)->execute([
                $memberId,
                mb_substr(trim((string) ($seed['name'] ?? '')), 0, 120),
                mb_substr(trim((string) ($seed['email'] ?? '')), 0, 160),
                $track, $plan, $phase, $books, $note, self::today(),
            ]);
        } catch (Throwable $e) {
            // Unique race: another request created it — just read it back.
            error_log('[ngv] ensureParticipant: ' . $e->getMessage());
        }
        return self::participant($memberId) ?? [
            'member_id' => $memberId, 'track' => $track, 'plan' => $plan, 'phase' => $phase,
            'books' => $books, 'focus_note' => $note, 'status' => 'active',
        ];
    }

    /** Member-editable self fields: track, plan, phase, books, focus_note. */
    public static function saveSelf(int $memberId, array $patch): void
    {
        self::ensureParticipant($memberId);
        $set = [];
        $args = [];
        if (array_key_exists('track', $patch))      { $set[] = 'track = ?';      $args[] = self::validTrack((string) $patch['track']); }
        if (array_key_exists('plan', $patch))       { $set[] = 'plan = ?';       $args[] = self::validPlan((string) $patch['plan']); }
        if (array_key_exists('phase', $patch))      { $v = (string) $patch['phase']; if (in_array($v, self::PHASES, true)) { $set[] = 'phase = ?'; $args[] = $v; } }
        if (array_key_exists('books', $patch))      { $set[] = 'books = ?';      $args[] = self::validBooks((string) $patch['books']); }
        if (array_key_exists('focus_note', $patch)) { $set[] = 'focus_note = ?'; $args[] = mb_substr(trim((string) $patch['focus_note']), 0, 300); }
        if (!$set) return;
        $set[] = 'updated_at = ' . NgvDb::nowExpr();
        $args[] = $memberId;
        NgvDb::pdo()->prepare('UPDATE ngv_participants SET ' . implode(', ', $set) . ' WHERE member_id = ?')->execute($args);
    }

    /**
     * Staff-editable fields: status, cohort, track, phase, plan.
     *
     * `plan` is here because the participant picks their own on the dashboard
     * and the plan is what prices the training fee. Somebody has to be able to
     * correct a wrong pick without asking the participant to do it, and staff
     * confirming the plan is the step the fee is raised from.
     */
    public static function setAdmin(int $memberId, array $patch): void
    {
        self::ensureParticipant($memberId);
        $set = []; $args = [];
        if (isset($patch['status']) && in_array((string) $patch['status'], self::STATUSES, true)) { $set[] = 'status = ?'; $args[] = (string) $patch['status']; }
        if (array_key_exists('plan', $patch))   { $set[] = 'plan = ?';   $args[] = self::validPlan((string) $patch['plan']); }
        if (array_key_exists('cohort', $patch)) { $set[] = 'cohort = ?'; $args[] = mb_substr(trim((string) $patch['cohort']), 0, 60); }
        if (array_key_exists('track', $patch))  { $set[] = 'track = ?';  $args[] = self::validTrack((string) $patch['track']); }
        if (array_key_exists('phase', $patch) && in_array((string) $patch['phase'], self::PHASES, true)) { $set[] = 'phase = ?'; $args[] = (string) $patch['phase']; }
        /* The enrolment date. It decides what the accrual charges from, when the
           training schedule starts, and which side of the rollout guard somebody
           falls — and until this was here, a wrong one at enrolment could not be
           corrected at all. Charges already posted keep their figures, which is
           the ledger's rule everywhere: fixing the date changes what happens
           NEXT, and the console says so. */
        if (array_key_exists('start_date', $patch)) {
            $sd = substr(trim((string) $patch['start_date']), 0, 10);
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $sd) && $sd <= self::today()) { $set[] = 'start_date = ?'; $args[] = $sd; }
        }
        if (!$set) return;
        $set[] = 'updated_at = ' . NgvDb::nowExpr();
        $args[] = $memberId;
        NgvDb::pdo()->prepare('UPDATE ngv_participants SET ' . implode(', ', $set) . ' WHERE member_id = ?')->execute($args);
    }

    /* ── money (delegated to NgvLedger) ──────────────────────────────────
     * These keep their old names and shapes so existing callers do not change,
     * but every one of them now goes through the ledger — which means a payment
     * recorded here is allocated to a fee line, audited with the amount and the
     * line, and reflected in the arrears list and the reminder sweep. None of
     * that was true when this file owned the money. */

    /** Record money received, allocated to the fee line it pays. */
    public static function recordPayment(int $memberId, array $p, int $byUid): bool
    {
        if ($memberId <= 0) return false;
        self::ensureParticipant($memberId);
        $line = in_array((string) ($p['kind'] ?? ''), self::KINDS, true) ? (string) $p['kind'] : 'commitment';
        $r = NgvLedger::payment($memberId, $line, $p['amount'] ?? 0, [
            'period' => $p['period'] ?? '', 'method' => $p['method'] ?? '',
            'reference' => $p['reference'] ?? '', 'note' => $p['note'] ?? '',
        ], $byUid);
        return !empty($r['ok']);
    }

    /**
     * Void a payment. A reason is REQUIRED — the old signature let a payment
     * disappear from the arithmetic with nothing on the row saying who removed
     * it or why, which is exactly the question an account has to answer.
     */
    public static function voidPayment(int $id, string $reason, int $byUid): array
    {
        return NgvLedger::void('credit', $id, $reason, $byUid);
    }

    /** Non-void payment rows, newest first. */
    public static function payments(int $memberId): array
    {
        return NgvLedger::credits($memberId);
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
    public static function certifications(int $memberId, bool $withRevoked = false): array
    {
        $sql = 'SELECT * FROM ngv_certifications WHERE member_id = ?';
        if (!$withRevoked) $sql .= " AND (revoked_at IS NULL OR revoked_at = '')";
        $sql .= ' ORDER BY issued_on DESC, id DESC';
        $st = NgvDb::pdo()->prepare($sql);
        $st->execute([$memberId]);
        $rows = $st->fetchAll() ?: [];
        foreach ($rows as &$r) {
            $r['code'] = self::certCode((int) $r['id']);
            $r['revoked'] = trim((string) ($r['revoked_at'] ?? '')) !== '';
        }
        return $rows;
    }

    /**
     * Withdraw a certificate. Revoked, never deleted.
     *
     * The link is public and somebody may already have given it to an employer.
     * Deleting the row turns that working link into "not verified", which reads
     * as a forgery rather than as a withdrawal — so the link keeps working and
     * says it was revoked, with the reason, exactly like a cancelled receipt.
     *
     * A reason is required for the same reason it is on a void: without one this
     * is a deletion with extra steps, and "why is my certificate gone" has to be
     * answerable from the record.
     */
    public static function revokeCertification(int $certId, string $reason, int $byUid): array
    {
        $reason = trim($reason);
        if ($reason === '') return ['ok' => false, 'error' => 'Say why — the holder can see this.'];
        $st = NgvDb::pdo()->prepare('SELECT * FROM ngv_certifications WHERE id = ?');
        $st->execute([$certId]);
        $c = $st->fetch();
        if (!$c) return ['ok' => false, 'error' => 'No such certificate.'];
        if (trim((string) ($c['revoked_at'] ?? '')) !== '') return ['ok' => false, 'error' => 'That certificate is already revoked.'];
        NgvDb::pdo()->prepare('UPDATE ngv_certifications SET revoked_at = ?, revoked_by = ?, revoke_reason = ? WHERE id = ?')
            ->execute([gmdate('Y-m-d H:i:s'), max(0, $byUid), mb_substr($reason, 0, 240), $certId]);
        if (class_exists('AdminAudit')) {
            try {
                AdminAudit::log('ngv', 'ngv_cert_revoke', 'ngv:member:' . (int) $c['member_id'],
                    'Revoked "' . mb_substr((string) $c['title'], 0, 100) . '" — ' . mb_substr($reason, 0, 160));
            } catch (Throwable $e) { error_log('[ngv] cert revoke audit: ' . $e->getMessage()); }
        }
        return ['ok' => true];
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
        /* Returned even when revoked, flagged. "Not verified" would tell somebody
           holding a real link that it was a forgery; the truth is that it was
           issued and then withdrawn, and the page can say so. */
        $cert['revoked'] = trim((string) ($cert['revoked_at'] ?? '')) !== '';
        return ['cert' => $cert, 'revoked' => $cert['revoked'],
                'revokeReason' => (string) ($cert['revoke_reason'] ?? ''),
                'name' => $p ? (string) ($p['name'] ?: '') : '', 'cohort' => $p ? (string) ($p['cohort'] ?? '') : ''];
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
     * Fee status in the shape the older callers read: display-ready lines plus
     * the total received. Now computed from the ledger rather than from two
     * constants, so what it reports is what has actually been charged.
     */
    public static function feeStatus(int $memberId): array
    {
        $a = NgvLedger::account($memberId);
        return ['lines' => $a['lines'], 'total' => (int) $a['total'],
                'count' => (int) $a['count'], 'has_any' => (bool) $a['has_any']];
    }

    /* ── staff roster + overview ─────────────────────────────────────── */
    /**
     * The roster, filterable.
     *
     * A flat list of everybody was fine for one cohort and unusable at three:
     * "who in 2026 Alpha is paused" was a Ctrl-F on a 300-row table. Filtering
     * happens in SQL rather than in the template so the row cap applies to the
     * MATCHES — a limit that truncates before the filter shows an empty result
     * for somebody who is definitely on the roster.
     */
    public static function roster(string $status = '', int $limit = 200, string $q = '', string $cohort = ''): array
    {
        $limit = max(1, min(1000, $limit));
        /* `payment` only. A waiver and a write-off are credits too, and counting
           them here reported money the programme never received as money it did
           — on the one column staff scan down. */
        $sql = "SELECT p.*,
                  (SELECT COALESCE(SUM(x.amount),0) FROM ngv_payments x
                    WHERE x.member_id = p.member_id AND x.voided = 0 AND x.credit_kind = 'payment') AS paid_total,
                  (SELECT COUNT(*) FROM ngv_certifications c WHERE c.member_id = p.member_id
                     AND (c.revoked_at IS NULL OR c.revoked_at = '')) AS cert_count
                FROM ngv_participants p";
        $args = []; $where = [];
        if ($status !== '' && in_array($status, self::STATUSES, true)) { $where[] = 'p.status = ?'; $args[] = $status; }
        if (trim($cohort) !== '') { $where[] = 'p.cohort = ?'; $args[] = mb_substr(trim($cohort), 0, 60); }
        $q = trim($q);
        if ($q !== '') {
            /* Wildcards stripped rather than escaped: ESCAPE needs a different
               literal on each of the three engines NGV runs on, and a name with
               a % in it is not a thing anybody searches for. */
            $like = '%' . str_replace(['%', '_'], '', $q) . '%';
            $where[] = '(p.name LIKE ? OR p.email LIKE ? OR p.track LIKE ?)';
            array_push($args, $like, $like, $like);
        }
        if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
        $sql .= ' ORDER BY p.updated_at DESC, p.id DESC LIMIT ' . $limit;
        $st = NgvDb::pdo()->prepare($sql);
        $st->execute($args);
        return $st->fetchAll() ?: [];
    }

    /** Cohorts actually in use, for the roster filter. Read from the data rather
     *  than from a list somebody has to remember to maintain. */
    public static function cohorts(): array
    {
        try {
            $out = [];
            foreach (NgvDb::pdo()->query("SELECT DISTINCT cohort FROM ngv_participants WHERE cohort <> '' ORDER BY cohort")->fetchAll() ?: [] as $r) {
                $out[] = (string) $r['cohort'];
            }
            return $out;
        } catch (Throwable $e) { return []; }
    }

    /** How many match a filter, so the console can say "showing 50 of 214"
     *  instead of quietly truncating. */
    public static function rosterCount(string $status = '', string $q = '', string $cohort = ''): int
    {
        return count(self::roster($status, 1000, $q, $cohort));
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
        $certs     = (int) $pdo->query("SELECT COUNT(*) FROM ngv_certifications WHERE revoked_at IS NULL OR revoked_at = ''")->fetchColumn();
        $appsNew   = (int) $pdo->query("SELECT COUNT(*) FROM ngv_applications WHERE status IN ('new','reviewing')")->fetchColumn();
        $appsTotal = (int) $pdo->query('SELECT COUNT(*) FROM ngv_applications')->fetchColumn();
        /* `collected` is every credit ever recorded, which is what the console
           has always shown. The ledger's own totals separate money RECEIVED from
           money WAIVED and written off — three very different facts that one
           "collected" figure was hiding. */
        $money = NgvLedger::totals();
        return ['total' => $total, 'by_status' => $byStatus, 'collected' => $collected, 'certs' => $certs,
                'apps_pending' => $appsNew, 'apps_total' => $appsTotal, 'money' => $money];
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

    /* ── plans (the programme / training fee) ────────────────────────────
     * A participant's plan is the single fact that says whether they owe a
     * training fee at all: "Training Only" and "Internship Only" are free,
     * "Full Programme" carries the tuition. The catalogue and its prices are
     * parsed from the live public page so the dashboard and the page can never
     * quote different money — the parser lives in NgvLedger, which is also what
     * prices a charge, so there is exactly one of it. */
    public static function planNames(): array { return array_keys(NgvLedger::planCatalogue()); }
    /** Plan options for the dashboard picker: name + price label + one-line desc. */
    public static function planOptions(): array { return array_values(NgvLedger::planCatalogue()); }

    /**
     * The member's account: one plain figure of what is outstanding, the fee
     * lines it is made of, and the full ledger behind it.
     *
     * Read-only and non-destructive. Unlike NGG there is no earn-off here —
     * NGV's Phase 2 is a *paid* internship with weekly stipends, so writing the
     * training fee down as service is served would be paying twice. Where a fee
     * should not be collected that is a WAIVER: somebody's decision, with their
     * name and their reason on it.
     */
    public static function account(int $memberId): array
    {
        return NgvLedger::account($memberId);
    }
}
