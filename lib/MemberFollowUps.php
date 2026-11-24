<?php
/**
 * lib/MemberFollowUps.php — when a member needs attention, somebody follows up.
 *
 * The evaluation (lib/MemberEvaluation.php) says who needs attention and why.
 * On its own that is a list nobody owns: research on attendance early-warning
 * systems found a flag with no assigned follow-up changed nothing, and the
 * ones that worked kept a shared log of who acted and what happened. So:
 *
 *   open    a reason, an owner (an admin on the team), a day it is due by —
 *           one open follow-up per member at a time, so two admins do not
 *           each call the same family
 *   close   with the outcome, which is also written to the member's notes,
 *           dated and signed, so the record keeps it after the follow-up
 *   due     everything open and due by today, for the dashboard tile and the
 *           Overview's Today queue
 *
 * Every open and close is in the audit log.
 */
declare(strict_types=1);

final class MemberFollowUps
{
    public const MAX_DAYS = 60;   // a follow-up is due within two months, or it is not a follow-up

    public static function ensure(): void
    {
        static $done = false; if ($done) return; $done = true;
        Database::execSchema(Database::pdo(), "CREATE TABLE IF NOT EXISTS member_followups (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL DEFAULT 0,
            reason VARCHAR(500) NOT NULL DEFAULT '',
            owner VARCHAR(160) NOT NULL DEFAULT '',
            due_on VARCHAR(10) NOT NULL DEFAULT '',
            status VARCHAR(12) NOT NULL DEFAULT 'open',
            outcome VARCHAR(1000) NOT NULL DEFAULT '',
            created_by VARCHAR(160) NOT NULL DEFAULT '',
            created_at VARCHAR(32) NOT NULL DEFAULT '',
            closed_by VARCHAR(160) NOT NULL DEFAULT '',
            closed_at VARCHAR(32) NOT NULL DEFAULT ''
        )");
        try { Database::execSchema(Database::pdo(), 'CREATE INDEX IF NOT EXISTS ix_member_followups_open ON member_followups(status, due_on)'); } catch (Throwable $e) {}
        try { Database::execSchema(Database::pdo(), 'CREATE INDEX IF NOT EXISTS ix_member_followups_user ON member_followups(user_id, id)'); } catch (Throwable $e) {}
    }

    public static function today(): string
    {
        return function_exists('av_today_tz') ? av_today_tz() : gmdate('Y-m-d');
    }

    /** The member's follow-ups, open first, then the last few closed. */
    public static function forMember(int $uid): array
    {
        self::ensure();
        $st = Database::pdo()->prepare("SELECT * FROM member_followups WHERE user_id = ? ORDER BY CASE status WHEN 'open' THEN 0 ELSE 1 END, id DESC LIMIT 20");
        $st->execute([$uid]);
        $today = self::today();
        return array_map(static fn($r) => self::shape($r, $today), $st->fetchAll(PDO::FETCH_ASSOC));
    }

    public static function open(int $uid, string $reason, string $owner, string $due, string $by): array
    {
        self::ensure();
        $reason = trim(mb_substr(str_replace("\r", '', $reason), 0, 500));
        $owner = trim(mb_substr($owner, 0, 160));
        $due = trim($due);
        $today = self::today();
        $e = [];
        if (mb_strlen($reason) < 3) $e['reason'] = 'Say what the follow-up is for.';
        if ($owner === '') $e['owner'] = 'Choose who follows up.';
        elseif (!self::isTeam($owner)) $e['owner'] = 'The owner must be an admin on the Studio team.';
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $due) || !checkdate((int) substr($due, 5, 2), (int) substr($due, 8, 2), (int) substr($due, 0, 4))) $e['due_on'] = 'Due is a date, like 2026-12-01.';
        elseif ($due < $today) $e['due_on'] = 'Due cannot be in the past.';
        elseif ($due > gmdate('Y-m-d', strtotime($today . ' +' . self::MAX_DAYS . ' days'))) $e['due_on'] = 'A follow-up is due within ' . self::MAX_DAYS . ' days.';
        if ($e) return ['ok' => false, 'code' => 'invalid', 'errors' => $e, 'error' => implode(' ', $e)];
        if (!self::memberExists($uid)) return ['ok' => false, 'code' => 'not_found', 'error' => 'No such member.'];
        $pdo = Database::pdo();
        $st = $pdo->prepare("SELECT owner, due_on FROM member_followups WHERE user_id = ? AND status = 'open' LIMIT 1");
        $st->execute([$uid]);
        if ($o = $st->fetch(PDO::FETCH_ASSOC)) return ['ok' => false, 'code' => 'already_open', 'error' => 'There is already a follow-up open: ' . $o['owner'] . ', due ' . $o['due_on'] . '. Close it first.'];
        $pdo->prepare("INSERT INTO member_followups (user_id, reason, owner, due_on, status, created_by, created_at) VALUES (?, ?, ?, ?, 'open', ?, ?)")
            ->execute([$uid, $reason, $owner, $due, mb_substr($by, 0, 160), gmdate('Y-m-d H:i:s')]);
        self::audit('member.followup_open', $uid, $owner . ' by ' . $due . ': ' . $reason);
        return ['ok' => true, 'followups' => self::forMember($uid)];
    }

    /** Close it with what happened; the outcome goes into the member's notes too. */
    public static function close(int $id, string $outcome, string $status, string $by): array
    {
        self::ensure();
        $outcome = trim(mb_substr(str_replace("\r", '', $outcome), 0, 1000));
        if (!in_array($status, ['done', 'cancelled'], true)) return ['ok' => false, 'code' => 'invalid', 'error' => 'A follow-up is closed as done or cancelled.'];
        if (mb_strlen($outcome) < 3) return ['ok' => false, 'code' => 'invalid', 'error' => 'Say what happened — that is the point of following up.'];
        $pdo = Database::pdo();
        $st = $pdo->prepare('SELECT * FROM member_followups WHERE id = ?'); $st->execute([$id]);
        $f = $st->fetch(PDO::FETCH_ASSOC);
        if (!$f) return ['ok' => false, 'code' => 'not_found', 'error' => 'No such follow-up.'];
        if ((string) $f['status'] !== 'open') return ['ok' => false, 'code' => 'closed', 'error' => 'That follow-up is already closed.'];
        $pdo->prepare("UPDATE member_followups SET status = ?, outcome = ?, closed_by = ?, closed_at = ? WHERE id = ? AND status = 'open'")
            ->execute([$status, $outcome, mb_substr($by, 0, 160), gmdate('Y-m-d H:i:s'), $id]);
        $uid = (int) $f['user_id'];
        MemberDesk::addNote($uid, 'Follow-up ' . ($status === 'done' ? 'done' : 'cancelled') . ' (' . $f['reason'] . '): ' . $outcome, $by);
        self::audit('member.followup_' . $status, $uid, $outcome);
        return ['ok' => true, 'followups' => self::forMember($uid)];
    }

    /** Open follow-ups due by today (late ones included), oldest first. */
    public static function due(int $limit = 200): array
    {
        self::ensure();
        $st = Database::pdo()->prepare("SELECT f.*, u.name FROM member_followups f LEFT JOIN lms_users u ON u.id = f.user_id WHERE f.status = 'open' AND f.due_on <= ? ORDER BY f.due_on, f.id LIMIT " . max(1, min(500, $limit)));
        $st->execute([self::today()]);
        $today = self::today();
        return array_map(static fn($r) => self::shape($r, $today) + ['name' => (string) ($r['name'] ?? '')], $st->fetchAll(PDO::FETCH_ASSOC));
    }

    public static function dueCount(): int
    {
        self::ensure();
        $st = Database::pdo()->prepare("SELECT COUNT(*) FROM member_followups WHERE status = 'open' AND due_on <= ?");
        $st->execute([self::today()]);
        return (int) $st->fetchColumn();
    }

    /** For the roster's segments: [where, params] on lms_users u. */
    public static function segmentSql(bool $dueOnly): array
    {
        self::ensure();
        return $dueOnly
            ? ["u.id IN (SELECT user_id FROM member_followups WHERE status = 'open' AND due_on <= ?)", [self::today()]]
            : ["u.id IN (SELECT user_id FROM member_followups WHERE status = 'open')", []];
    }

    /** Who may own one: the Studio's admins, by the address they sign in with. */
    public static function team(): array
    {
        /* Admins and up: an editor cannot open the member desk, so cannot follow up from it. */
        $out = [];
        foreach (AdminRoles::list() as $a) {
            if (empty($a['valid']) || (int) (AdminRoles::RANK[(string) $a['role']] ?? 0) < (int) AdminRoles::RANK['admin']) continue;
            $out[] = (string) $a['email'];
        }
        return $out;
    }

    private static function isTeam(string $owner): bool
    {
        return in_array(strtolower($owner), array_map('strtolower', self::team()), true);
    }

    private static function shape(array $r, string $today): array
    {
        $open = (string) $r['status'] === 'open';
        return ['id' => (int) $r['id'], 'user_id' => (int) $r['user_id'], 'reason' => (string) $r['reason'], 'owner' => (string) $r['owner'],
                'due_on' => (string) $r['due_on'], 'status' => (string) $r['status'], 'outcome' => (string) $r['outcome'],
                'created_by' => (string) $r['created_by'], 'created_at' => (string) $r['created_at'],
                'closed_by' => (string) $r['closed_by'], 'closed_at' => (string) $r['closed_at'],
                'overdue' => $open && (string) $r['due_on'] < $today, 'due' => $open && (string) $r['due_on'] <= $today];
    }

    private static function memberExists(int $uid): bool
    {
        $st = Database::pdo()->prepare('SELECT 1 FROM lms_users WHERE id = ?'); $st->execute([$uid]);
        return (bool) $st->fetchColumn();
    }

    private static function audit(string $action, int $uid, string $what): void
    {
        try { if (class_exists('AdminAudit')) AdminAudit::log('members', $action, 'member:' . $uid, mb_substr($what, 0, 300)); } catch (Throwable $e) { /* best effort */ }
    }
}
