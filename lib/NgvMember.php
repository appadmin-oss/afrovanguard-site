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
        $phase = in_array((string) ($seed['phase'] ?? ''), self::PHASES, true) ? (string) $seed['phase'] : '';
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

    /** Certifications, newest first. */
    public static function certifications(int $memberId): array
    {
        $st = NgvDb::pdo()->prepare('SELECT * FROM ngv_certifications WHERE member_id = ? ORDER BY issued_on DESC, id DESC');
        $st->execute([$memberId]);
        return $st->fetchAll() ?: [];
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
        return ['total' => $total, 'by_status' => $byStatus, 'collected' => $collected, 'certs' => $certs];
    }

    /* ── validation ──────────────────────────────────────────────────── */
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
