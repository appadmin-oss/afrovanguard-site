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
 *   · An award grants QUEST points (lib/QuestPoints.php, period `award:<case>`,
 *     so it is granted once however often it is approved) and tells the mentee.
 *     Not gate_points: the gate's points are for arriving on time, and the
 *     Quest's doctrine is that attendance is not evidence. Two currencies,
 *     two ledgers.
 *
 * WHO MAY DECIDE
 *   A member of the compliance committee (compliance_members, kept by
 *   superadmins) or a superadmin — and never the mentor who proposed it or the
 *   mentee it is about. Rejecting needs a reason; the mentor is told either way.
 *
 * A FINE IS PROPOSED LIKE A BILL (owner, 2026-10-22)
 *   A fine or a deduction is no longer kept from the member until it is
 *   decided. It is put to them the day it is proposed, with what the mentor
 *   saw, and they answer it: accept it, or contest it and give their side.
 *   The committee reads the answer beside the evidence, and may not approve
 *   before the member has answered or quest.notice_days has passed — it may
 *   always reject. Awards and challenges stay unseen until approved: a
 *   recognition is not a bill.
 *
 *   A member may also report themselves (selfReport): a fine or a deduction
 *   from the catalogue, against their own record. It is proposed and decided
 *   like any other, already accepted, and a different person decides it.
 *
 * APPEALS
 *   An approved fine or deduction may be appealed by the member it is about,
 *   once, within quest.appeal_days, on written grounds. It is heard by somebody
 *   who had no part in it — not the proposer, not the member, and not the
 *   person who approved it. The hearing upholds it, overturns it (the fine is
 *   voided, the deduction taken off the record, a demotion it raised is
 *   dismissed) or reduces it. While an appeal is open, an overdue balance does
 *   not raise a suspension (QuestMoney::sweep).
 */
declare(strict_types=1);

final class Conduct
{
    public const KINDS = ['fine', 'award', 'challenge', 'deduction'];
    public const MENTOR_KINDS = ['academy' => 'Academy mentor', 'vquest' => 'Vanguard Quest mentor', 'self' => 'Self-reported'];
    /** The kinds that cost the member something: put to them as a bill, and appealable. */
    public const ADVERSE = ['fine', 'deduction'];
    public const RESPONSES = ['accepted' => 'Accepted', 'contested' => 'Contested'];
    public const APPEAL_OUTCOMES = ['upheld' => 'Upheld', 'overturned' => 'Overturned', 'reduced' => 'Reduced'];
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
        /* A challenge sign-off names one of the seventy. Added with the kind
           column widened in the same breath, because 'challenge' does not fit
           the VARCHAR(8) the table shipped with and MySQL and Postgres enforce
           that where SQLite does not. Once only: if the column is there, the
           widening already happened. */
        if (!Database::columnExists('conduct_cases', 'challenge_id')) {
            $drv = Database::driver();
            $widen = $drv === 'mysql' ? 'ALTER TABLE conduct_cases MODIFY kind VARCHAR(16) NOT NULL'
                   : ($drv === 'pgsql' ? 'ALTER TABLE conduct_cases ALTER COLUMN kind TYPE VARCHAR(16)' : '');
            if ($widen !== '') { try { $db->exec($widen); } catch (Throwable $e) { error_log('[conduct] widen kind: ' . $e->getMessage()); } }
            try { $db->exec("ALTER TABLE conduct_cases ADD COLUMN challenge_id VARCHAR(40) NOT NULL DEFAULT ''"); }
            catch (Throwable $e) { /* raced */ }
        }
        /* The catalogue line a case was proposed from (the Pyramid Manual's
           awards, deductions and fines, via QuestRules). '' for a challenge or
           a custom award. Its own column: the ids outgrow `reason`. */
        if (!Database::columnExists('conduct_cases', 'action_id')) {
            try { $db->exec("ALTER TABLE conduct_cases ADD COLUMN action_id VARCHAR(40) NOT NULL DEFAULT ''"); }
            catch (Throwable $e) { /* raced */ }
        }
        /* The member's answer to a bill: accepted or contested, in their words. */
        if (!Database::columnExists('conduct_cases', 'response')) {
            foreach (["response VARCHAR(12) NOT NULL DEFAULT ''", 'response_note TEXT', "responded_at VARCHAR(32) NOT NULL DEFAULT ''"] as $col) {
                try { $db->exec('ALTER TABLE conduct_cases ADD COLUMN ' . $col); } catch (Throwable $e) { /* raced */ }
            }
        }
        /* One appeal per case, ever. */
        Database::execSchema($db, "CREATE TABLE IF NOT EXISTS conduct_appeals (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            case_id INTEGER NOT NULL UNIQUE,
            member_id INTEGER NOT NULL,
            grounds TEXT NOT NULL,
            status VARCHAR(12) NOT NULL DEFAULT 'open',
            heard_by INTEGER NOT NULL DEFAULT 0,
            heard_at VARCHAR(32) NOT NULL DEFAULT '',
            hearing_note TEXT,
            new_value INTEGER NOT NULL DEFAULT 0,
            outcome VARCHAR(64) NOT NULL DEFAULT '',
            created_at VARCHAR(32) NOT NULL
        )");
        Database::ensureIndex($db, 'idx_conduct_appeal_status', 'conduct_appeals', 'status, created_at');
        Database::ensureIndex($db, 'idx_conduct_appeal_member', 'conduct_appeals', 'member_id, status');
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
        return self::file($mentorUid, $menteeId, (string) $p['mentee_name'], (int) $p['id'],
            isset(self::MENTOR_KINDS[(string) ($p['kind'] ?? '')]) && $p['kind'] !== 'self' ? (string) $p['kind'] : 'academy', $in);
    }

    /**
     * A member reports themselves: a fine or a deduction from the catalogue,
     * against their own record. Proposed and decided like any other case, by
     * somebody else; it arrives already accepted, so nobody waits on a notice.
     */
    public static function selfReport(int $uid, array $in): array
    {
        self::ensure();
        if (!AvRules::bool('quest.self_report')) return ['ok' => false, 'error' => 'Reporting yourself is switched off.'];
        /* One picker carries both: "<kind>:<catalogue id>". */
        [$kind, $action] = array_pad(explode(':', trim((string) ($in['action'] ?? '')), 2), 2, '');
        if (!in_array($kind, self::ADVERSE, true)) return ['ok' => false, 'error' => 'You can report a fine or a deduction against yourself — not an award.'];
        if ($action === '') return ['ok' => false, 'error' => 'Choose what it is from the list.'];
        $in['kind'] = $kind; $in['action'] = $action;
        /* Priced by the catalogue, never by the member. */
        $line = QuestRules::usable($kind, $action);
        if ($kind === 'fine' && $line !== null && $line['value'] === null) return ['ok' => false, 'error' => 'That fine is priced each time; the office raises it.'];
        unset($in['amount'], $in['points'], $in['reason']);
        $st = Database::pdo()->prepare('SELECT name FROM lms_users WHERE id = ?');
        $st->execute([$uid]);
        $name = (string) ($st->fetchColumn() ?: '');
        if ($name === '') return ['ok' => false, 'error' => 'Sign in again.'];
        return self::file($uid, $uid, $name, 0, 'self', $in);
    }

    /** The checks and the write every proposal shares, whoever proposes it. */
    private static function file(int $mentorUid, int $menteeId, string $menteeName, int $pairingId, string $mentorKind, array $in): array
    {
        $p = ['mentee_name' => $menteeName === '' ? 'This member' : $menteeName];
        $self = $mentorKind === 'self';

        $kind = (string) ($in['kind'] ?? '');
        if (!in_array($kind, self::KINDS, true)) return ['ok' => false, 'error' => 'Choose a fine, an award, a deduction or a challenge.'];
        $actionId = trim((string) ($in['action'] ?? ''));
        /* One picker on the form carries both: a catalogue id, or
           "reason:<old reason>" for a line outside the catalogue. */
        if (str_starts_with($actionId, 'reason:')) { $in['reason'] = substr($actionId, 7); $actionId = ''; }
        $line = null;

        /* A challenge sign-off is not priced by the mentor: it is one of the
           seventy, it is worth what the registry says, and it is proved once
           ever. The reason it records is the value it belongs to. */
        $challengeId = '';
        if ($kind === 'challenge') {
            $challengeId = trim((string) ($in['challenge_id'] ?? ''));
            $ch = Quest::challenge($challengeId);
            if (!$ch) return ['ok' => false, 'error' => 'That is not one of the seventy challenges.'];
            if (!Quest::isOfficial($challengeId)) {
                return ['ok' => false, 'error' => 'That challenge has no official wording yet, so nothing can be signed off against it.'];
            }
            if (QuestPoints::granted($menteeId, 'challenge:' . $menteeId . ':' . $challengeId)) {
                return ['ok' => false, 'error' => $p['mentee_name'] . ' has already proved that one. A challenge is proved once, ever.'];
            }
            $reason = Quest::valueOf($challengeId);
        } elseif ($kind === 'deduction') {
            /* The manual's penalty table. Priced by the catalogue, decided like
               an award, and never a demotion by itself. */
            if (!AvRules::bool('quest.deductions_enabled')) return ['ok' => false, 'error' => 'Point deductions are switched off.'];
            $line = QuestRules::usable('deduction', $actionId);
            if (!$line) return ['ok' => false, 'error' => 'Choose a deduction from the list.'];
            $reason = 'deduction';
        } elseif ($actionId !== '') {
            /* A priced line: an award from the catalogue, or a fine from the
               manual's list. The mentor names the deed; the catalogue prices it. */
            if ($kind === 'award') {
                if (!AvRules::bool('quest.awards_enabled')) return ['ok' => false, 'error' => 'Awards are switched off.'];
                $line = QuestRules::usable('award', $actionId);
                if (!$line) return ['ok' => false, 'error' => 'Choose an action from the catalogue.'];
                if ($actionId === 'mentee_rises') {
                    return ['ok' => false, 'error' => 'That one is given automatically when a mentee\'s promotion is confirmed.'];
                }
                $reason = (string) $line['group'];
            } else {
                $line = QuestRules::usable('fine', $actionId);
                if (!$line) return ['ok' => false, 'error' => 'Choose a fine from the list.'];
                $reason = (string) $line['ledger_reason'];
            }
        } else {
            if ($kind === 'award' && !AvRules::bool('quest.awards_custom')) {
                return ['ok' => false, 'error' => 'Pick the action from the catalogue — awards are priced there, not by the mentor.'];
            }
            $reasons = $kind === 'fine' ? self::FINE_REASONS : self::AWARD_REASONS;
            $reason = (string) ($in['reason'] ?? '');
            if (!isset($reasons[$reason])) return ['ok' => false, 'error' => 'Choose what it is for.'];
        }
        $title = mb_substr(trim((string) ($in['title'] ?? '')), 0, 160);
        if ($reason === 'other' && $line === null && $title === '') return ['ok' => false, 'error' => 'Say in a few words what it is for.'];

        $evidence = trim((string) ($in['evidence'] ?? ''));
        if (mb_strlen($evidence) < self::EVIDENCE_MIN) {
            return ['ok' => false, 'error' => $self
                ? 'Say what happened — when, where, and what you did. The committee decides on this.'
                : 'Write what you saw — when, where, and what was said or done. The committee decides on this, and the member reads it.'];
        }
        $evidence = mb_substr($evidence, 0, 3000);

        $on = trim((string) ($in['occurred_on'] ?? ''));
        $today = self::today();
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $on) || $on > $today) return ['ok' => false, 'error' => 'Give the day it happened — not a future day.'];
        if ($on < date('Y-m-d', strtotime($today . ' -' . self::DAYS_BACK . ' days'))) {
            return ['ok' => false, 'error' => 'That is more than ' . self::DAYS_BACK . ' days ago. Raise it with the office instead.'];
        }

        $amount = 0; $points = 0;
        if ($kind === 'challenge') {
            $points = (int) (Quest::challenge($challengeId)['points'] ?? 1);
            $db0 = Database::pdo();
            $openCh = $db0->prepare("SELECT 1 FROM conduct_cases WHERE mentee_id = ? AND challenge_id = ? AND status IN ('proposed','approved')");
            $openCh->execute([$menteeId, $challengeId]);
            if ($openCh->fetchColumn()) return ['ok' => false, 'error' => 'That challenge is already put forward for ' . $p['mentee_name'] . '.'];
        } elseif ($kind === 'deduction') {
            $points = (int) $line['value'];
        } elseif ($kind === 'fine') {
            if (!self::fineable($menteeId)) {
                return ['ok' => false, 'error' => $self
                    ? 'You have no NextGen Vanguard account, so there is nothing a fine could be owed on. Report a deduction instead.'
                    : $p['mentee_name'] . ' has no NextGen Vanguard account, so there is nothing a fine could be owed on. Propose an award, or raise the behaviour with the office.'];
            }
            if ($line !== null && $line['value'] !== null) {
                /* The manual's price, scaled to the member's income bracket
                   once brackets are set (QuestMoney); the base until then. */
                $amount = (int) $line['value'];
                if (class_exists('QuestMoney')) $amount = QuestMoney::scaleFine($menteeId, $amount);
            } else {
                $amount = (int) preg_replace('/\D+/', '', (string) ($in['amount'] ?? ''));
                if ($amount <= 0 || $amount > self::FINE_MAX) return ['ok' => false, 'error' => 'A fine is between ₦1 and ₦' . number_format(self::FINE_MAX) . '.'];
            }
        } elseif ($line !== null) {
            $points = (int) $line['value'];
        } else {
            $points = (int) ($in['points'] ?? 0);
            if ($points <= 0 || $points > self::POINTS_MAX) return ['ok' => false, 'error' => 'An award is between 1 and ' . self::POINTS_MAX . ' points.'];
        }

        $db = Database::pdo();
        $open = $db->prepare("SELECT COUNT(*) FROM conduct_cases WHERE mentor_id = ? AND mentee_id = ? AND status = 'proposed'");
        $open->execute([$mentorUid, $menteeId]);
        if ((int) $open->fetchColumn() >= self::OPEN_PER_MENTEE) {
            return ['ok' => false, 'error' => $self ? 'You already have ' . self::OPEN_PER_MENTEE . ' reports waiting. Let the committee catch up.'
                : 'You already have ' . self::OPEN_PER_MENTEE . ' proposals waiting for this mentee. Let the committee catch up.'];
        }
        $dup = $db->prepare("SELECT 1 FROM conduct_cases WHERE mentor_id = ? AND mentee_id = ? AND kind = ? AND reason = ? AND action_id = ? AND occurred_on = ? AND status IN ('proposed','approved')");
        $dup->execute([$mentorUid, $menteeId, $kind, $reason, $actionId, $on]);
        if ($dup->fetchColumn()) return ['ok' => false, 'error' => 'You have already put this forward for that day.'];

        $db->prepare('INSERT INTO conduct_cases (kind, mentee_id, mentor_id, mentorship_id, mentor_kind, reason, action_id, title, amount, points, challenge_id, occurred_on, evidence, status, response, responded_at, created_at)
                      VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
           ->execute([$kind, $menteeId, $mentorUid, $pairingId, $mentorKind,
                      $reason, $line !== null ? $actionId : '', $title, $amount, $points, $challengeId, $on, $evidence, 'proposed',
                      $self ? 'accepted' : '', $self ? self::now() : '', self::now()]);
        $id = (int) $db->lastInsertId();
        $what = $kind === 'fine' ? ' ₦' . $amount : ($kind === 'challenge' ? ' ' . $challengeId : ($kind === 'deduction' ? ' −' : ' ') . $points . ' pts');
        self::audit($self ? 'self_report' : 'propose', $id, $kind . ' for member #' . $menteeId . $what, $mentorUid);
        self::tellDeciders($id);
        if (!$self && in_array($kind, self::ADVERSE, true)) self::tellMember($id, $kind, $kind === 'fine' ? '₦' . number_format($amount) : $points . ' points');
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

    /* ── The bill: the member answers ─────────────────────────────────── */

    /**
     * The day the member's notice runs to, if it is still running on a case
     * that has not been answered; '' when the committee may approve.
     */
    public static function noticeLeft(array $c): string
    {
        if (!in_array((string) $c['kind'], self::ADVERSE, true) || (string) ($c['response'] ?? '') !== '') return '';
        $days = AvRules::int('quest.notice_days');
        if ($days <= 0) return '';
        $until = strtotime((string) $c['created_at'] . ' UTC +' . $days . ' days');
        return $until !== false && $until > time() ? gmdate('Y-m-d H:i', $until) . ' UTC' : '';
    }

    /** The member answers a fine or deduction proposed against them, before it is decided. */
    public static function respond(int $uid, int $caseId, string $answer, string $note): array
    {
        self::ensure();
        $c = self::find($caseId);
        if (!$c || (int) $c['mentee_id'] !== $uid || !in_array((string) $c['kind'], self::ADVERSE, true)) return ['ok' => false, 'error' => 'That is not a bill of yours.'];
        if ($c['status'] !== 'proposed') return ['ok' => false, 'error' => 'This was already ' . $c['status'] . '. ' . ($c['status'] === 'approved' ? 'You may appeal it instead.' : '')];
        if (!isset(self::RESPONSES[$answer])) return ['ok' => false, 'error' => 'Accept it, or contest it.'];
        $note = mb_substr(trim($note), 0, 2000);
        if ($answer === 'contested' && mb_strlen($note) < self::EVIDENCE_MIN) {
            return ['ok' => false, 'error' => 'Give your side — what happened, as you saw it. The committee reads it beside the mentor\'s.'];
        }
        $st = Database::pdo()->prepare("UPDATE conduct_cases SET response = ?, response_note = ?, responded_at = ? WHERE id = ? AND status = 'proposed'");
        $st->execute([$answer, $note, self::now(), $caseId]);
        if ($st->rowCount() !== 1) return ['ok' => false, 'error' => 'It was decided a moment ago.'];
        self::audit('respond', $caseId, $answer, $uid);
        return ['ok' => true, 'response' => $answer];
    }

    /* ── Appeals ──────────────────────────────────────────────────────── */

    /** Until when this case may be appealed; '' if it cannot be (not adverse, not approved, self-reported, switched off, late). */
    public static function appealOpenUntil(array $c): string
    {
        if (!AvRules::bool('quest.appeals_enabled')) return '';
        if (!in_array((string) $c['kind'], self::ADVERSE, true) || (string) $c['status'] !== 'approved' || (string) $c['mentor_kind'] === 'self') return '';
        $until = strtotime((string) $c['decided_at'] . ' UTC +' . max(1, AvRules::int('quest.appeal_days')) . ' days');
        return $until !== false && $until > time() ? gmdate('Y-m-d', $until) : '';
    }

    public static function appealOf(int $caseId): ?array
    {
        self::ensure();
        $st = Database::pdo()->prepare('SELECT * FROM conduct_appeals WHERE case_id = ?');
        $st->execute([$caseId]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public static function findAppeal(int $id): ?array
    {
        self::ensure();
        $st = Database::pdo()->prepare('SELECT * FROM conduct_appeals WHERE id = ?');
        $st->execute([$id]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public static function hasOpenAppeal(int $memberId): bool
    {
        self::ensure();
        $st = Database::pdo()->prepare("SELECT 1 FROM conduct_appeals WHERE member_id = ? AND status = 'open'");
        $st->execute([$memberId]);
        return (bool) $st->fetchColumn();
    }

    /** The member appeals an approved fine or deduction: once, in time, on grounds. */
    public static function appeal(int $uid, int $caseId, string $grounds): array
    {
        self::ensure();
        $c = self::find($caseId);
        if (!$c || (int) $c['mentee_id'] !== $uid) return ['ok' => false, 'error' => 'That is not a case of yours.'];
        if (!AvRules::bool('quest.appeals_enabled')) return ['ok' => false, 'error' => 'Appeals are switched off.'];
        if ((string) $c['mentor_kind'] === 'self') return ['ok' => false, 'error' => 'You reported this yourself, so there is nothing to appeal. Speak to the committee if it was priced wrongly.'];
        if (self::appealOf($caseId)) return ['ok' => false, 'error' => 'This has been appealed already. A case is appealed once.'];
        if (self::appealOpenUntil($c) === '') {
            return ['ok' => false, 'error' => $c['status'] === 'approved' ? 'The ' . AvRules::int('quest.appeal_days') . ' days to appeal this have passed.' : 'Only an approved fine or deduction can be appealed.'];
        }
        $grounds = mb_substr(trim($grounds), 0, 3000);
        if (mb_strlen($grounds) < self::EVIDENCE_MIN) return ['ok' => false, 'error' => 'Give your grounds — why the decision is wrong, or the price too high. Somebody new hears it.'];
        $db = Database::pdo();
        try {
            $db->prepare('INSERT INTO conduct_appeals (case_id, member_id, grounds, status, created_at) VALUES (?,?,?,?,?)')
               ->execute([$caseId, $uid, $grounds, 'open', self::now()]);
        } catch (Throwable $e) { return ['ok' => false, 'error' => 'This has been appealed already. A case is appealed once.']; }
        $id = (int) $db->lastInsertId();
        self::audit('appeal', $caseId, 'appeal #' . $id, $uid);
        if (class_exists('Notifications')) {
            foreach (self::members() as $m) {
                if (in_array((int) $m['user_id'], [(int) $c['mentor_id'], (int) $c['mentee_id'], (int) $c['decided_by']], true)) continue;
                try { Notifications::push((int) $m['user_id'], 'conduct', 'An appeal is waiting to be heard', '', '/mentorship/compliance/?s=appeals#appeals', 'appeal:' . $id . ':' . $m['user_id']); }
                catch (Throwable $e) { /* best-effort */ }
            }
        }
        return ['ok' => true, 'id' => $id];
    }

    /**
     * Hear an appeal. Somebody who had no part in the case: not the member,
     * not the proposer, not the person who approved it. A reduction is below
     * the original, never above it; nothing is raised on appeal.
     */
    public static function hearAppeal(int $appealId, array $by, string $outcome, string $note, int $newValue = 0): array
    {
        self::ensure();
        if (!self::canDecide($by)) return ['ok' => false, 'error' => 'Only the compliance committee or a superadmin hears appeals.'];
        $a = self::findAppeal($appealId);
        if (!$a) return ['ok' => false, 'error' => 'That appeal does not exist.'];
        if ($a['status'] !== 'open') return ['ok' => false, 'error' => 'This appeal was already heard: ' . $a['status'] . '.'];
        $c = self::find((int) $a['case_id']);
        if (!$c) return ['ok' => false, 'error' => 'The case is gone.'];
        $bid = (int) $by['id'];
        if ($bid === (int) $c['mentee_id']) return ['ok' => false, 'error' => 'This is your own appeal, so somebody else must hear it.'];
        if ($bid === (int) $c['mentor_id']) return ['ok' => false, 'error' => 'You proposed this, so somebody else must hear the appeal.'];
        if ($bid === (int) $c['decided_by']) return ['ok' => false, 'error' => 'You approved this, so somebody else must hear the appeal.'];
        if (!isset(self::APPEAL_OUTCOMES[$outcome])) return ['ok' => false, 'error' => 'Uphold it, overturn it, or reduce it.'];
        $note = mb_substr(trim($note), 0, 2000);
        if ($note === '') return ['ok' => false, 'error' => 'Give the reason for the decision — the member reads it.'];
        $fine = $c['kind'] === 'fine';
        $was = $fine ? (int) $c['amount'] : (int) $c['points'];
        if ($outcome === 'reduced' && ($newValue <= 0 || $newValue >= $was)) {
            return ['ok' => false, 'error' => 'A reduction is more than nothing and less than the ' . ($fine ? '₦' . number_format($was) : $was . ' points') . ' it was.'];
        }

        $db = Database::pdo();
        $claim = $db->prepare("UPDATE conduct_appeals SET status = ?, heard_by = ?, heard_at = ?, hearing_note = ?, new_value = ? WHERE id = ? AND status = 'open'");
        $claim->execute([$outcome, $bid, self::now(), $note, $outcome === 'reduced' ? $newValue : 0, $appealId]);
        if ($claim->rowCount() !== 1) return ['ok' => false, 'error' => 'Somebody else heard this a moment ago.'];

        $mid = (int) $c['mentee_id'];
        $result = 'upheld';
        if ($outcome !== 'upheld') {
            /* Undo what the approval did, then — for a reduction — do it again at the new size. */
            if ($fine) {
                $charge = preg_match('/^ngv_charge:(\d+)/', (string) $c['outcome'], $m) ? (int) $m[1] : 0;
                $v = $charge > 0 ? NgvFines::voidOne($charge, 'Appeal #' . $appealId . ' ' . $outcome . ': ' . $note, $bid) : ['ok' => false, 'error' => 'no charge recorded'];
                if (empty($v['ok'])) {
                    $db->prepare("UPDATE conduct_appeals SET status = 'open', heard_by = 0, heard_at = '', hearing_note = NULL, new_value = 0 WHERE id = ?")->execute([$appealId]);
                    return ['ok' => false, 'error' => 'The fine could not be voided: ' . ($v['error'] ?? 'unknown') . ' The appeal is still open.'];
                }
                $result = 'voided:' . $charge;
                if ($outcome === 'reduced') {
                    $r = NgvFines::issue($mid, (string) $c['reason'], $newValue, 'Reduced on appeal #' . $appealId . ' from ₦' . number_format($was) . ': ' . self::caseLabel($c), (string) $c['occurred_on'], true, $bid);
                    $result .= empty($r['ok']) ? ' reissue-failed' : ' ngv_charge:' . (int) ($r['entryId'] ?? 0);
                }
            } else {
                QuestPoints::revoke($mid, 'deduction:' . (int) $c['id']);
                $result = 'restored:' . $was;
                if ($outcome === 'reduced') {
                    QuestPoints::deduct($mid, $newValue, 'deduction:' . (int) $c['id'] . ':appeal', (string) $c['action_id'],
                        'Deduction, reduced on appeal: ' . self::caseLabel($c), $bid, (string) $c['occurred_on']);
                    $result = 'deducted:' . $newValue;
                }
                /* The loss of level the deduction raised goes with it when it is overturned. */
                if ($outcome === 'overturned' && class_exists('QuestLadder')) {
                    QuestLadder::ensure();
                    $st = $db->prepare("SELECT id FROM quest_suggestions WHERE member_id = ? AND source = ? AND status = 'open'");
                    $st->execute([$mid, 'deduction:' . (int) $c['id']]);
                    $sid = (int) $st->fetchColumn();
                    if ($sid > 0) QuestLadder::dismiss($sid, $by, 'The deduction behind it was overturned on appeal #' . $appealId . '.');
                }
            }
        }
        $db->prepare('UPDATE conduct_appeals SET outcome = ? WHERE id = ?')->execute([$result, $appealId]);
        self::audit('appeal_' . $outcome, (int) $c['id'], 'appeal #' . $appealId . ' ' . $result, $bid);
        if (class_exists('Notifications')) {
            $verdict = ['upheld' => 'was upheld', 'overturned' => 'succeeded — it is cancelled', 'reduced' => 'reduced it to ' . ($fine ? '₦' . number_format($newValue) : $newValue . ' points')][$outcome];
            try { Notifications::push($mid, 'conduct', 'Your appeal ' . $verdict, $note, '/portal/#bills', 'appeal-heard:' . $appealId); }
            catch (Throwable $e) { /* best-effort */ }
        }
        return ['ok' => true, 'status' => $outcome, 'outcome' => $result];
    }

    /** Appeals waiting, oldest first, with the case and the names — one query. */
    public static function appeals(string $status = 'open', int $limit = 200): array
    {
        self::ensure();
        $st = Database::pdo()->prepare("SELECT a.*, c.kind, c.reason, c.action_id, c.title, c.amount, c.points, c.occurred_on, c.evidence,
                c.mentor_id, c.mentor_kind, c.mentee_id, c.decided_by, c.decided_at, c.decision_note, c.response, c.response_note, c.challenge_id,
                me.name AS mentee_name, mt.name AS mentor_name, d.name AS decider_name, h.name AS hearer_name
            FROM conduct_appeals a JOIN conduct_cases c ON c.id = a.case_id
            LEFT JOIN lms_users me ON me.id = c.mentee_id
            LEFT JOIN lms_users mt ON mt.id = c.mentor_id
            LEFT JOIN lms_users d ON d.id = c.decided_by
            LEFT JOIN lms_users h ON h.id = a.heard_by
            WHERE " . ($status === 'heard' ? "a.status <> 'open'" : 'a.status = ?') . ' ORDER BY ' . ($status === 'open' ? 'a.id ASC' : 'a.heard_at DESC') . ' LIMIT ' . max(1, min(500, $limit)));
        $st->execute($status === 'heard' ? [] : [$status]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * A member's bills: every fine and deduction proposed against them or
     * reported by them, with its appeal — newest first, one query.
     */
    public static function billsFor(int $memberId, int $limit = 50): array
    {
        self::ensure();
        $st = Database::pdo()->prepare("SELECT c.*, mt.name AS mentor_name, a.id AS appeal_id, a.status AS appeal_status, a.grounds AS appeal_grounds,
                a.hearing_note AS appeal_note, a.new_value AS appeal_value, a.heard_at AS appeal_heard_at
            FROM conduct_cases c
            LEFT JOIN lms_users mt ON mt.id = c.mentor_id
            LEFT JOIN conduct_appeals a ON a.case_id = c.id
            WHERE c.mentee_id = ? AND c.kind IN ('fine','deduction') AND c.status <> 'withdrawn'
            ORDER BY c.id DESC LIMIT " . max(1, min(200, $limit)));
        $st->execute([$memberId]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
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
        if ($approve && ($wait = self::noticeLeft($c)) !== '') {
            return ['ok' => false, 'error' => 'The member has until ' . $wait . ' to answer this. It can be approved once they have, or then — or rejected now.'];
        }

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
            $label = self::caseLabel($c);
            $r = NgvFines::issue((int) $c['mentee_id'], $c['reason'], (int) $c['amount'],
                ($c['mentor_kind'] === 'self' ? 'Self-reported fine, approved by compliance: ' : 'Mentor fine (' . (self::MENTOR_KINDS[$c['mentor_kind']] ?? 'mentor') . '), approved by compliance: ')
                . ($c['title'] !== '' ? $c['title'] . ' — ' : $label . ' — ') . mb_substr((string) $c['evidence'], 0, 300),
                (string) $c['occurred_on'], true, $aid);
            if (empty($r['ok'])) {
                $db->prepare("UPDATE conduct_cases SET status = 'proposed', decided_by = 0, decided_at = '', decision_note = NULL WHERE id = ?")->execute([$caseId]);
                return ['ok' => false, 'error' => 'Approved, but the fine could not be posted: ' . ($r['error'] ?? 'unknown') . ' It is still waiting.'];
            }
            $outcome = 'ngv_charge:' . (int) ($r['entryId'] ?? 0);
        } elseif ($c['kind'] === 'challenge') {
            $cid = (string) $c['challenge_id'];
            $vals = Quest::values();
            $label = (string) ($vals[(string) $c['reason']]['name'] ?? 'Challenge');
            $text  = (string) (Quest::challenge($cid)['text'] ?? $cid);
            QuestPoints::grant((int) $c['mentee_id'], (int) $c['points'], 'challenge:' . (int) $c['mentee_id'] . ':' . $cid,
                'challenge', $label . ' — ' . mb_substr($text, 0, 120), $aid, (string) $c['occurred_on'], (string) $c['reason']);
            $outcome = 'challenge:' . $cid;
            if (class_exists('Notifications')) {
                Notifications::push((int) $c['mentee_id'], 'award', 'Challenge proved: ' . mb_substr($text, 0, 90),
                    $label . '. Signed off by your mentor and confirmed by the compliance committee.', '/portal/', 'challenge:' . $cid);
            }
        } elseif ($c['kind'] === 'deduction') {
            $label = self::caseLabel($c);
            QuestPoints::deduct((int) $c['mentee_id'], (int) $c['points'], 'deduction:' . $caseId, (string) $c['action_id'],
                'Deduction: ' . $label, $aid, (string) $c['occurred_on']);
            $outcome = 'deducted:' . (int) $c['points'];
            if (class_exists('Notifications')) {
                Notifications::push((int) $c['mentee_id'], 'award', 'Points deducted: ' . $label,
                    (int) $c['points'] . ' points, ' . ($c['mentor_kind'] === 'self' ? 'which you reported' : 'proposed by your mentor') . ' and confirmed by the compliance committee.'
                    . (self::appealOpenUntil(['status' => 'approved', 'decided_at' => self::now()] + $c) !== '' ? ' You may appeal it.' : ''), '/portal/#bills', 'deduction:' . $caseId);
            }
            /* "Loss of current level" is the manual's; here it is only ever a
               suggestion for a person to decide. */
            $line = Quest::deductions()[(string) $c['action_id']] ?? null;
            if ($line && ($line['suggests'] ?? '') === 'demotion' && class_exists('QuestLadder')) {
                QuestLadder::suggest((int) $c['mentee_id'], 'demotion', $label . ' — approved by compliance (case #' . $caseId . '). The Pyramid Manual: loss of current level.', 'deduction:' . $caseId);
            }
        } else {
            $label = self::caseLabel($c);
            $points = self::withinCeiling((int) $c['mentee_id'], (int) $c['points'], (string) $c['occurred_on']);
            if ($points > 0) {
                QuestPoints::grant((int) $c['mentee_id'], $points, 'award:' . $caseId, (string) $c['reason'],
                    'Award: ' . ($c['title'] !== '' ? $c['title'] : $label), $aid, (string) $c['occurred_on']);
            }
            $outcome = 'points:' . $points . ($points < (int) $c['points'] ? ' (monthly ceiling)' : '');
            if (class_exists('Notifications')) {
                Notifications::push((int) $c['mentee_id'], 'award', 'You have been recognised: ' . ($c['title'] !== '' ? $c['title'] : $label),
                    $points . ' points from your mentor, confirmed by the compliance committee.'
                    . ($points < (int) $c['points'] ? ' Your level\'s monthly ceiling held it to that.' : ''), '/portal/#quest', 'award:' . $caseId);
            }
        }
        $db->prepare('UPDATE conduct_cases SET outcome = ? WHERE id = ?')->execute([$outcome, $caseId]);
        self::audit('approve', $caseId, $outcome, $aid);
        self::tellMentor($c, true, $note);
        return ['ok' => true, 'status' => 'approved', 'outcome' => $outcome];
    }

    /**
     * How much of an award fits under the member's monthly ceiling (the
     * Pyramid Manual: O–C 499, D–E 999, F–G none), for the month the deed
     * happened in. The whole award when the ceiling is switched off.
     */
    public static function withinCeiling(int $memberId, int $points, string $day): int
    {
        if ($points <= 0) return 0;
        if (!AvRules::bool('quest.monthly_ceiling')) return $points;
        $level = class_exists('Levels') ? Levels::of($memberId) : 'O';
        $ceiling = Quest::rank($level)['monthly_ceiling'] ?? null;
        if ($ceiling === null) return $points;
        $month = preg_match('/^\d{4}-\d{2}/', $day) ? substr($day, 0, 7) : gmdate('Y-m');
        $room = (int) $ceiling - QuestPoints::earnedInMonth($memberId, $month);
        return max(0, min($points, $room));
    }

    /** What a case is for, in words: the catalogue line, else the old reason. */
    public static function caseLabel(array $c): string
    {
        $aid = (string) ($c['action_id'] ?? '');
        $kind = (string) ($c['kind'] ?? '');
        if ($aid !== '' && isset(QuestRules::KINDS[$kind])) {
            $line = QuestRules::item($kind, $aid);
            if ($line) return (string) $line['label'];
        }
        if ($kind === 'challenge') return 'Challenge ' . (string) ($c['challenge_id'] ?? '');
        $map = $kind === 'fine' ? self::FINE_REASONS : self::AWARD_REASONS;
        $r = (string) ($c['reason'] ?? '');
        if (isset($map[$r])) return $map[$r];
        if (class_exists('NgvLedger') && isset(NgvLedger::FINE_REASONS[$r])) return (string) NgvLedger::FINE_REASONS[$r];
        return ucfirst($r);
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
        if (!isset(self::MENTOR_KINDS[$kind]) || $kind === 'self') return ['ok' => false, 'error' => 'A pairing is an Academy or a Vanguard Quest mentorship.'];
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

    /** A bill is put to the member the day it is proposed. */
    private static function tellMember(int $caseId, string $kind, string $what): void
    {
        if (!class_exists('Notifications')) return;
        $c = self::find($caseId);
        if (!$c) return;
        $days = AvRules::int('quest.notice_days');
        try {
            Notifications::push((int) $c['mentee_id'], 'conduct', ($kind === 'fine' ? 'A fine of ' : 'A deduction of ') . $what . ' has been proposed',
                self::caseLabel($c) . '. Read what was seen, then accept it or give your side'
                . ($days > 0 ? ' within ' . $days . ' day' . ($days === 1 ? '' : 's') : '') . '. The compliance committee decides.', '/portal/#bills', 'bill:' . $caseId);
        } catch (Throwable $e) { error_log('[conduct] notify member: ' . $e->getMessage()); }
    }

    private static function tellMentor(array $c, bool $approved, string $note): void
    {
        if (!class_exists('Notifications')) return;
        if ((string) $c['mentor_kind'] === 'self') {
            try {
                Notifications::push((int) $c['mentor_id'], 'conduct', 'What you reported was ' . ($approved ? 'confirmed' : 'not taken forward'),
                    $note, '/portal/#bills', 'conduct-decided:' . (int) $c['id']);
            } catch (Throwable $e) { error_log('[conduct] notify self: ' . $e->getMessage()); }
            return;
        }
        /* Name the mentee: "Your award was approved" reads as an award TO the
           mentor, not one they proposed. */
        $who = 'your mentee';
        try {
            $st = Database::pdo()->prepare('SELECT name FROM lms_users WHERE id = ?');
            $st->execute([(int) $c['mentee_id']]);
            $n = trim((string) $st->fetchColumn());
            if ($n !== '') $who = $n;
        } catch (Throwable $e) {}
        $what = $c['kind'] === 'fine' ? 'The fine you proposed for ' : 'The award you proposed for ';
        try {
            Notifications::push((int) $c['mentor_id'], 'conduct', $what . $who . ' was ' . ($approved ? 'approved' : 'not approved'),
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
