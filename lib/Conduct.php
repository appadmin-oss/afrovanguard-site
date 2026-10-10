<?php
/**
 * lib/Conduct.php — a mentor's fines and awards for their mentee, and the
 * second person who must agree before either becomes real.
 *
 * THE RULE (owner, 2026-10-10)
 *   Any mentor may fine — or reward — their own mentee. Nothing happens on the
 *   mentor's word alone: a fine or an award is PROPOSED here, and becomes real
 *   only when the compliance committee or a superior (a superadmin) approves
 *   it. Until then it is a proposal the mentee does not see and nobody owes.
 *
 * TWO KINDS OF MENTOR
 *   A pairing is `academy` — the /mentorship community as it has always been —
 *   or `vquest`: a Vanguard Quest mentor, the real mentor who tracks the mentee
 *   throughout. Both may propose. The kind is recorded on every case so the
 *   committee sees who is asking; it is set on the pairing by the office.
 *
 * WHAT APPROVAL DOES
 *   · A fine is posted to the mentee's NGV account through NgvFines::issue, the
 *     one place fines are made, so it is owed, paid, waived and queried exactly
 *     like every other fine, and the mentee is told. A fine needs an account to
 *     be owed against: a mentee outside NGV cannot be fined money, and the form
 *     says so rather than recording a debt nothing can collect.
 *   · An award grants points (gate_points, period `award:<case>`, so it is
 *     granted once however often it is approved) and tells the mentee.
 *
 * WHO MAY DECIDE
 *   A member of the compliance committee (compliance_members, kept by
 *   superadmins) or a superadmin — and never the mentor who proposed it or the
 *   mentee it is about. Rejecting needs a reason; the mentor is told either way.
 */
declare(strict_types=1);

final class Conduct
{
    public const KINDS = ['fine', 'award'];
    public const MENTOR_KINDS = ['academy' => 'Academy mentor', 'vquest' => 'Vanguard Quest mentor'];
    /** What a mentor may fine for. A subset of NgvLedger::FINE_REASONS: lateness and absence are the gate's. */
    public const FINE_REASONS = ['conduct' => 'Conduct', 'uniform' => 'Uniform or ID card', 'equipment' => 'Equipment lost or damaged', 'other' => 'Other (say why)'];
    /** What an award is for: the values a mentor already observes (MentorPortal::VALUES), and work. */
    public const AWARD_REASONS = ['values' => 'Living the values', 'leadership' => 'Leadership', 'service' => 'Service to others', 'excellence' => 'Excellent work', 'growth' => 'Growth', 'other' => 'Other (say why)'];
    public const FINE_MAX = 20000;     // NGN, per proposal
    public const POINTS_MAX = 50;      // per award
    public const EVIDENCE_MIN = 30;    // characters: what was seen, not a verdict
    public const OPEN_PER_MENTEE = 5;  // proposals waiting at once, per mentor and mentee
    public const DAYS_BACK = 30;       // how old the thing that happened may be

    private static bool $ready = false;

    public static function ensure(): void
    {
        if (self::$ready) return; self::$ready = true;
        $db = Database::pdo();
        Database::execSchema($db, "CREATE TABLE IF NOT EXISTS conduct_cases (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            kind VARCHAR(8) NOT NULL,
            mentee_id INTEGER NOT NULL,
            mentor_id INTEGER NOT NULL,
            mentorship_id INTEGER NOT NULL,
            mentor_kind VARCHAR(12) NOT NULL DEFAULT 'academy',
            reason VARCHAR(24) NOT NULL,
            title VARCHAR(160) NOT NULL DEFAULT '',
            amount INTEGER NOT NULL DEFAULT 0,
            points INTEGER NOT NULL DEFAULT 0,
            occurred_on VARCHAR(10) NOT NULL,
            evidence TEXT NOT NULL,
            status VARCHAR(12) NOT NULL DEFAULT 'proposed',
            decided_by INTEGER NOT NULL DEFAULT 0,
            decided_at VARCHAR(32) NOT NULL DEFAULT '',
            decision_note TEXT,
            outcome VARCHAR(64) NOT NULL DEFAULT '',
            created_at VARCHAR(32) NOT NULL
        )");
        Database::ensureIndex($db, 'idx_conduct_status', 'conduct_cases', 'status, created_at');
        Database::ensureIndex($db, 'idx_conduct_mentee', 'conduct_cases', 'mentee_id, status');
        Database::ensureIndex($db, 'idx_conduct_mentor', 'conduct_cases', 'mentor_id, status');
        Database::execSchema($db, "CREATE TABLE IF NOT EXISTS compliance_members (
            user_id INTEGER PRIMARY KEY,
            added_by VARCHAR(190) NOT NULL DEFAULT '',
            created_at VARCHAR(32) NOT NULL
        )");
        if (class_exists('Mentorship')) Mentorship::ensure();
        if (!Database::columnExists('mentorships', 'kind')) {
            try { $db->exec("ALTER TABLE mentorships ADD COLUMN kind VARCHAR(12) NOT NULL DEFAULT 'academy'"); } catch (Throwable $e) { /* raced */ }
        }
    }

    private static function now(): string { return gmdate('Y-m-d H:i:s'); }
    private static function today(): string
    {
        try { return (new DateTimeImmutable('now', new DateTimeZone('Africa/Lagos')))->format('Y-m-d'); }
        catch (Throwable $e) { return date('Y-m-d'); }
    }

    /** The mentor's own active pairing, or null. Ownership is checked here, every time. */
    public static function pairing(int $mentorUid, int $pairingId): ?array
    {
        self::ensure();
        $st = Database::pdo()->prepare("SELECT m.id, m.mentor_id, m.mentee_id, m.kind, u.name AS mentee_name, u.email AS mentee_email
            FROM mentorships m JOIN lms_users u ON u.id = m.mentee_id
            WHERE m.id = ? AND m.mentor_id = ? AND m.status = 'active'");
        $st->execute([$pairingId, $mentorUid]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    }

    /** Can this mentee be fined money? Only against an NGV account. */
    public static function fineable(int $menteeId): bool
    {
        try { return class_exists('NgvMember') && NgvMember::participant($menteeId) !== null; }
        catch (Throwable $e) { return false; }
    }

    /**
     * A mentor proposes a fine or an award for their own mentee.
     * Validated whole before anything is written.
     */
    public static function propose(int $mentorUid, int $pairingId, array $in): array
    {
        self::ensure();
        $p = self::pairing($mentorUid, $pairingId);
        if (!$p) return ['ok' => false, 'error' => 'Not your mentee.'];
        $menteeId = (int) $p['mentee_id'];
        if ($menteeId === $mentorUid) return ['ok' => false, 'error' => 'You cannot fine or reward yourself.'];

        $kind = (string) ($in['kind'] ?? '');
        if (!in_array($kind, self::KINDS, true)) return ['ok' => false, 'error' => 'Choose a fine or an award.'];
        $reasons = $kind === 'fine' ? self::FINE_REASONS : self::AWARD_REASONS;
        $reason = (string) ($in['reason'] ?? '');
        if (!isset($reasons[$reason])) return ['ok' => false, 'error' => 'Choose what it is for.'];
        $title = mb_substr(trim((string) ($in['title'] ?? '')), 0, 160);
        if ($reason === 'other' && $title === '') return ['ok' => false, 'error' => 'Say in a few words what it is for.'];

        $evidence = trim((string) ($in['evidence'] ?? ''));
        if (mb_strlen($evidence) < self::EVIDENCE_MIN) {
            return ['ok' => false, 'error' => 'Write what you saw — when, where, and what was said or done. The committee decides on this, and so may the mentee if they query it.'];
        }
        $evidence = mb_substr($evidence, 0, 3000);

        $on = trim((string) ($in['occurred_on'] ?? ''));
        $today = self::today();
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $on) || $on > $today) return ['ok' => false, 'error' => 'Give the day it happened — not a future day.'];
        if ($on < date('Y-m-d', strtotime($today . ' -' . self::DAYS_BACK . ' days'))) {
            return ['ok' => false, 'error' => 'That is more than ' . self::DAYS_BACK . ' days ago. Raise it with the office instead.'];
        }

        $amount = 0; $points = 0;
        if ($kind === 'fine') {
            if (!self::fineable($menteeId)) {
                return ['ok' => false, 'error' => $p['mentee_name'] . ' has no NextGen Vanguard account, so there is nothing a fine could be owed on. Propose an award, or raise the behaviour with the office.'];
            }
            $amount = (int) preg_replace('/\D+/', '', (string) ($in['amount'] ?? ''));
            if ($amount <= 0 || $amount > self::FINE_MAX) return ['ok' => false, 'error' => 'A fine is between ₦1 and ₦' . number_format(self::FINE_MAX) . '.'];
        } else {
            $points = (int) ($in['points'] ?? 0);
            if ($points <= 0 || $points > self::POINTS_MAX) return ['ok' => false, 'error' => 'An award is between 1 and ' . self::POINTS_MAX . ' points.'];
        }

        $db = Database::pdo();
        $open = $db->prepare("SELECT COUNT(*) FROM conduct_cases WHERE mentor_id = ? AND mentee_id = ? AND status = 'proposed'");
        $open->execute([$mentorUid, $menteeId]);
        if ((int) $open->fetchColumn() >= self::OPEN_PER_MENTEE) {
            return ['ok' => false, 'error' => 'You already have ' . self::OPEN_PER_MENTEE . ' proposals waiting for this mentee. Let the committee catch up.'];
        }
        $dup = $db->prepare("SELECT 1 FROM conduct_cases WHERE mentor_id = ? AND mentee_id = ? AND kind = ? AND reason = ? AND occurred_on = ? AND status IN ('proposed','approved')");
        $dup->execute([$mentorUid, $menteeId, $kind, $reason, $on]);
        if ($dup->fetchColumn()) return ['ok' => false, 'error' => 'You have already put this forward for that day.'];

        $db->prepare('INSERT INTO conduct_cases (kind, mentee_id, mentor_id, mentorship_id, mentor_kind, reason, title, amount, points, occurred_on, evidence, status, created_at)
                      VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)')
           ->execute([$kind, $menteeId, $mentorUid, (int) $p['id'], isset(self::MENTOR_KINDS[(string) ($p['kind'] ?? '')]) ? (string) $p['kind'] : 'academy',
                      $reason, $title, $amount, $points, $on, $evidence, 'proposed', self::now()]);
        $id = (int) $db->lastInsertId();
        self::audit('propose', $id, $kind . ' for member #' . $menteeId . ($kind === 'fine' ? ' ₦' . $amount : ' ' . $points . ' pts'), $mentorUid);
        self::tellDeciders($id);
        return ['ok' => true, 'id' => $id];
    }

    /** The proposer takes it back, while it is still waiting. */
    public static function withdraw(int $mentorUid, int $caseId): array
    {
        self::ensure();
        $st = Database::pdo()->prepare("UPDATE conduct_cases SET status = 'withdrawn', decided_at = ? WHERE id = ? AND mentor_id = ? AND status = 'proposed'");
        $st->execute([self::now(), $caseId, $mentorUid]);
        if ($st->rowCount() !== 1) return ['ok' => false, 'error' => 'Only a proposal of yours that is still waiting can be withdrawn.'];
        self::audit('withdraw', $caseId, '', $mentorUid);
        return ['ok' => true];
    }

    /* ── Who decides ──────────────────────────────────────────────────── */

    public static function isCommittee(int $uid): bool
    {
        self::ensure();
        $st = Database::pdo()->prepare('SELECT 1 FROM compliance_members WHERE user_id = ?');
        $st->execute([$uid]);
        return (bool) $st->fetchColumn();
    }

    /** A superior: a superadmin of the Studio, matched on the signed-in member's email. */
    public static function isSuperior(?array $user): bool
    {
        if (!$user || !class_exists('AdminRoles')) return false;
        try { return AdminRoles::roleForEmail((string) ($user['email'] ?? '')) === 'superadmin'; }
        catch (Throwable $e) { return false; }
    }

    public static function canDecide(?array $user): bool
    {
        return $user !== null && ((int) ($user['id'] ?? 0) > 0) && (self::isCommittee((int) $user['id']) || self::isSuperior($user));
    }

    /**
     * Approve or reject. The fine or award becomes real only here.
     */
    public static function decide(int $caseId, array $approver, bool $approve, string $note): array
    {
        self::ensure();
        if (!self::canDecide($approver)) return ['ok' => false, 'error' => 'Only the compliance committee or a superadmin decides these.'];
        $c = self::find($caseId);
        if (!$c) return ['ok' => false, 'error' => 'That case does not exist.'];
        if ($c['status'] !== 'proposed') return ['ok' => false, 'error' => 'This was already ' . $c['status'] . '.'];
        $aid = (int) $approver['id'];
        if ($aid === (int) $c['mentor_id']) return ['ok' => false, 'error' => 'You proposed this, so somebody else must decide it.'];
        if ($aid === (int) $c['mentee_id']) return ['ok' => false, 'error' => 'This is about you, so somebody else must decide it.'];
        $note = mb_substr(trim($note), 0, 1000);
        if (!$approve && $note === '') return ['ok' => false, 'error' => 'Say why it is rejected — the mentor is told.'];

        /* Claim it first, so two deciders pressing at once cannot both act. */
        $db = Database::pdo();
        $claim = $db->prepare("UPDATE conduct_cases SET status = ?, decided_by = ?, decided_at = ?, decision_note = ? WHERE id = ? AND status = 'proposed'");
        $claim->execute([$approve ? 'approved' : 'rejected', $aid, self::now(), $note, $caseId]);
        if ($claim->rowCount() !== 1) return ['ok' => false, 'error' => 'Somebody else decided this a moment ago.'];

        if (!$approve) {
            self::audit('reject', $caseId, $note, $aid);
            self::tellMentor($c, false, $note);
            return ['ok' => true, 'status' => 'rejected'];
        }

        $outcome = '';
        if ($c['kind'] === 'fine') {
            if (!self::fineable((int) $c['mentee_id'])) {
                $db->prepare("UPDATE conduct_cases SET status = 'proposed', decided_by = 0, decided_at = '', decision_note = NULL WHERE id = ?")->execute([$caseId]);
                return ['ok' => false, 'error' => 'The mentee no longer has an NGV account, so the fine cannot be posted. Reject it with that reason.'];
            }
            $label = self::FINE_REASONS[$c['reason']] ?? $c['reason'];
            $r = NgvFines::issue((int) $c['mentee_id'], $c['reason'], (int) $c['amount'],
                'Mentor fine (' . (self::MENTOR_KINDS[$c['mentor_kind']] ?? 'mentor') . '), approved by compliance: '
                . ($c['title'] !== '' ? $c['title'] . ' — ' : $label . ' — ') . mb_substr((string) $c['evidence'], 0, 300),
                (string) $c['occurred_on'], true, $aid);
            if (empty($r['ok'])) {
                $db->prepare("UPDATE conduct_cases SET status = 'proposed', decided_by = 0, decided_at = '', decision_note = NULL WHERE id = ?")->execute([$caseId]);
                return ['ok' => false, 'error' => 'Approved, but the fine could not be posted: ' . ($r['error'] ?? 'unknown') . ' It is still waiting.'];
            }
            $outcome = 'ngv_charge:' . (int) ($r['entryId'] ?? 0);
        } else {
            $label = self::AWARD_REASONS[$c['reason']] ?? 'Award';
            GateAttendance::grant((int) $c['mentee_id'], (string) $c['occurred_on'], 'award:' . $caseId, (int) $c['points'],
                'Award: ' . ($c['title'] !== '' ? $c['title'] : $label));
            $outcome = 'points:' . (int) $c['points'];
            if (class_exists('Notifications')) {
                Notifications::push((int) $c['mentee_id'], 'award', 'You have been recognised: ' . ($c['title'] !== '' ? $c['title'] : $label),
                    (int) $c['points'] . ' points from your mentor, confirmed by the compliance committee.', '/portal/', 'award:' . $caseId);
            }
        }
        $db->prepare('UPDATE conduct_cases SET outcome = ? WHERE id = ?')->execute([$outcome, $caseId]);
        self::audit('approve', $caseId, $outcome, $aid);
        self::tellMentor($c, true, $note);
        return ['ok' => true, 'status' => 'approved', 'outcome' => $outcome];
    }

    /* ── Reading ──────────────────────────────────────────────────────── */

    public static function find(int $id): ?array
    {
        self::ensure();
        $st = Database::pdo()->prepare('SELECT * FROM conduct_cases WHERE id = ?');
        $st->execute([$id]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    }

    /** The committee's queue, oldest first, with names — one query, no per-row lookups. */
    public static function queue(string $status = 'proposed', int $limit = 100): array
    {
        self::ensure();
        $st = Database::pdo()->prepare("SELECT c.*, mt.name AS mentor_name, me.name AS mentee_name, d.name AS decider_name
            FROM conduct_cases c
            LEFT JOIN lms_users mt ON mt.id = c.mentor_id
            LEFT JOIN lms_users me ON me.id = c.mentee_id
            LEFT JOIN lms_users d ON d.id = c.decided_by
            WHERE c.status = ? ORDER BY " . ($status === 'proposed' ? 'c.id ASC' : 'c.id DESC') . ' LIMIT ' . max(1, min(500, $limit)));
        $st->execute([$status]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** A mentor's own proposals for one pairing, newest first. */
    public static function forPairing(int $mentorUid, int $pairingId): array
    {
        self::ensure();
        $st = Database::pdo()->prepare('SELECT * FROM conduct_cases WHERE mentor_id = ? AND mentorship_id = ? ORDER BY id DESC LIMIT 50');
        $st->execute([$mentorUid, $pairingId]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** What a mentee sees: approved awards only (approved fines are on their NGV account, with the rest). */
    public static function awardsFor(int $menteeId): array
    {
        self::ensure();
        $st = Database::pdo()->prepare("SELECT reason, title, points, occurred_on FROM conduct_cases WHERE mentee_id = ? AND kind = 'award' AND status = 'approved' ORDER BY occurred_on DESC, id DESC LIMIT 20");
        $st->execute([$menteeId]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /* ── The committee ────────────────────────────────────────────────── */

    public static function members(): array
    {
        self::ensure();
        return Database::pdo()->query('SELECT c.user_id, c.created_at, u.name, u.email FROM compliance_members c LEFT JOIN lms_users u ON u.id = c.user_id ORDER BY u.name')->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** Superadmins keep the list. By email, of somebody who has signed in at least once. */
    public static function addMember(string $email, array $by): array
    {
        self::ensure();
        if (!self::isSuperior($by)) return ['ok' => false, 'error' => 'Only a superadmin keeps the committee list.'];
        $st = Database::pdo()->prepare('SELECT id, name FROM lms_users WHERE LOWER(email) = ?');
        $st->execute([mb_strtolower(trim($email))]);
        $u = $st->fetch(PDO::FETCH_ASSOC);
        if (!$u) return ['ok' => false, 'error' => 'Nobody with that email has signed in yet.'];
        Database::pdo()->prepare(Database::insertIgnore('compliance_members', ['user_id', 'added_by', 'created_at']))
            ->execute([(int) $u['id'], (string) ($by['email'] ?? ''), self::now()]);
        self::audit('committee_add', 0, (string) $u['name'], (int) $by['id']);
        return ['ok' => true, 'name' => (string) $u['name']];
    }

    public static function removeMember(int $userId, array $by): array
    {
        self::ensure();
        if (!self::isSuperior($by)) return ['ok' => false, 'error' => 'Only a superadmin keeps the committee list.'];
        Database::pdo()->prepare('DELETE FROM compliance_members WHERE user_id = ?')->execute([$userId]);
        self::audit('committee_remove', 0, 'member #' . $userId, (int) $by['id']);
        return ['ok' => true];
    }

    /** The office sets whether a pairing is a Vanguard Quest mentorship. */
    public static function setPairingKind(int $pairingId, string $kind, array $by): array
    {
        self::ensure();
        if (!self::canDecide($by)) return ['ok' => false, 'error' => 'Only the compliance committee or a superadmin sets this.'];
        if (!isset(self::MENTOR_KINDS[$kind])) return ['ok' => false, 'error' => 'A pairing is an Academy or a Vanguard Quest mentorship.'];
        $st = Database::pdo()->prepare('UPDATE mentorships SET kind = ? WHERE id = ?');
        $st->execute([$kind, $pairingId]);
        if ($st->rowCount() < 1) return ['ok' => false, 'error' => 'That pairing does not exist, or is already that kind.'];
        self::audit('pairing_kind', $pairingId, $kind, (int) $by['id']);
        return ['ok' => true];
    }

    /* ── Telling people ───────────────────────────────────────────────── */

    private static function tellDeciders(int $caseId): void
    {
        if (!class_exists('Notifications')) return;
        foreach (self::members() as $m) {
            try { Notifications::push((int) $m['user_id'], 'conduct', 'A mentor’s fine or award is waiting for you', '', '/mentorship/compliance/', 'conduct:' . $caseId . ':' . $m['user_id']); }
            catch (Throwable $e) { error_log('[conduct] notify: ' . $e->getMessage()); }
        }
    }

    private static function tellMentor(array $c, bool $approved, string $note): void
    {
        if (!class_exists('Notifications')) return;
        $what = $c['kind'] === 'fine' ? 'fine' : 'award';
        try {
            Notifications::push((int) $c['mentor_id'], 'conduct', 'Your ' . $what . ' was ' . ($approved ? 'approved' : 'not approved'),
                $note, '/mentorship/mentor/?v=conduct&id=' . (int) $c['mentorship_id'], 'conduct-decided:' . (int) $c['id']);
        } catch (Throwable $e) { error_log('[conduct] notify mentor: ' . $e->getMessage()); }
    }

    private static function audit(string $action, int $caseId, string $detail, int $byUid): void
    {
        if (!class_exists('AdminAudit')) return;
        try { AdminAudit::log('conduct', $action, $caseId ? 'case#' . $caseId : '', mb_substr($detail, 0, 300), null, 'user#' . $byUid); }
        catch (Throwable $e) { error_log('[conduct] audit: ' . $e->getMessage()); }
    }
}
