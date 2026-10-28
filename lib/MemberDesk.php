<?php
/**
 * lib/MemberDesk.php — what the office does with members day to day, beside
 * the record itself (lib/MemberRoster.php), as NGG's admin does it:
 *
 *   notes     dated, signed notes on a member — "called about dues, will pay
 *             Friday" — instead of one box everybody overwrote
 *   activity  what they have been doing: last sign-in, tasks held and finished,
 *             gate visits — read on the record, not hunted for
 *   export    the roster as a CSV, the filtered list or the chosen members,
 *             recorded in the audit log (it names people and their phones)
 *   message   a portal notification to the chosen members, and an email if
 *             asked — capped, and recorded
 */
declare(strict_types=1);

final class MemberDesk
{
    /** Most members one message goes to. */
    public const MESSAGE_MAX = 500;
    /** Most rows one export holds. */
    public const EXPORT_MAX = 5000;

    public static function ensure(): void
    {
        static $done = false; if ($done) return; $done = true;
        Database::execSchema(Database::pdo(), "CREATE TABLE IF NOT EXISTS member_notes (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL DEFAULT 0,
            body VARCHAR(2000) NOT NULL DEFAULT '',
            author VARCHAR(160) NOT NULL DEFAULT '',
            created_at VARCHAR(32) NOT NULL DEFAULT ''
        )");
        try { Database::pdo()->exec('CREATE INDEX IF NOT EXISTS ix_member_notes_user ON member_notes(user_id, id)'); } catch (Throwable $e) {}
    }

    /* ── Notes ─────────────────────────────────────────────────────────── */

    public static function notes(int $uid): array
    {
        self::ensure();
        $st = Database::pdo()->prepare('SELECT id, body, author, created_at FROM member_notes WHERE user_id = ? ORDER BY id DESC LIMIT 100');
        $st->execute([$uid]);
        return array_map(static fn($r) => ['id' => (int) $r['id'], 'body' => (string) $r['body'], 'author' => (string) $r['author'], 'at' => (string) $r['created_at']],
            $st->fetchAll(PDO::FETCH_ASSOC));
    }

    public static function addNote(int $uid, string $body, string $author): array
    {
        self::ensure();
        $body = trim(mb_substr(str_replace("\r", '', $body), 0, 2000));
        if ($body === '') return ['ok' => false, 'error' => 'Write the note first.'];
        if (!self::exists($uid)) return ['ok' => false, 'error' => 'No such member.'];
        Database::pdo()->prepare('INSERT INTO member_notes (user_id, body, author, created_at) VALUES (?,?,?,?)')
            ->execute([$uid, $body, mb_substr($author !== '' ? $author : 'admin', 0, 160), gmdate('Y-m-d H:i:s')]);
        return ['ok' => true, 'notes' => self::notes($uid)];
    }

    /** A note is taken back by whoever wrote it, within a day; after that it is the record. */
    public static function deleteNote(int $noteId, string $author): array
    {
        self::ensure();
        $st = Database::pdo()->prepare('SELECT user_id, author, created_at FROM member_notes WHERE id = ?');
        $st->execute([$noteId]);
        $n = $st->fetch(PDO::FETCH_ASSOC);
        if (!$n) return ['ok' => false, 'error' => 'No such note.'];
        if ((string) $n['author'] !== $author) return ['ok' => false, 'error' => 'Only whoever wrote a note can take it back.'];
        if ((strtotime((string) $n['created_at'] . ' UTC') ?: 0) < time() - 86400) return ['ok' => false, 'error' => 'A note older than a day is part of the record. Add another to correct it.'];
        Database::pdo()->prepare('DELETE FROM member_notes WHERE id = ?')->execute([$noteId]);
        return ['ok' => true, 'notes' => self::notes((int) $n['user_id'])];
    }

    /* ── Activity ──────────────────────────────────────────────────────── */

    /**
     * What a member has been doing: last sign-in, tasks open / finished in 90
     * days, gate visits in 30 days and the last one. Each source fails soft.
     */
    public static function activity(int $uid): array
    {
        $pdo = Database::pdo();
        $out = ['last_login' => '', 'tasks_open' => 0, 'tasks_done_90' => 0, 'visits_30' => 0, 'last_visit' => '', 'notes' => 0];
        try { $st = $pdo->prepare('SELECT last_login FROM lms_users WHERE id = ?'); $st->execute([$uid]); $out['last_login'] = (string) ($st->fetchColumn() ?: ''); } catch (Throwable $e) {}
        try {
            $st = $pdo->prepare('SELECT SUM(CASE WHEN done = 0 THEN 1 ELSE 0 END), SUM(CASE WHEN done = 1 AND done_at >= ? THEN 1 ELSE 0 END) FROM collab_tasks WHERE assignee_id = ?');
            $st->execute([gmdate('Y-m-d H:i:s', time() - 90 * 86400), $uid]);
            [$o, $d] = $st->fetch(PDO::FETCH_NUM) ?: [0, 0];
            $out['tasks_open'] = (int) $o; $out['tasks_done_90'] = (int) $d;
        } catch (Throwable $e) {}
        try {
            if (class_exists('GateAttendance')) GateAttendance::ensure();
            $st = $pdo->prepare("SELECT COUNT(*), MAX(day) FROM gate_attendance WHERE member_id = ? AND day >= ? AND status <> 'absent'");
            $st->execute([$uid, gmdate('Y-m-d', time() - 30 * 86400)]);
            [$n, ] = $st->fetch(PDO::FETCH_NUM) ?: [0, null];
            $out['visits_30'] = (int) $n;
            $st = $pdo->prepare("SELECT MAX(day) FROM gate_attendance WHERE member_id = ? AND status <> 'absent'");
            $st->execute([$uid]);
            $out['last_visit'] = (string) ($st->fetchColumn() ?: '');
        } catch (Throwable $e) {}
        try { self::ensure(); $st = $pdo->prepare('SELECT COUNT(*) FROM member_notes WHERE user_id = ?'); $st->execute([$uid]); $out['notes'] = (int) $st->fetchColumn(); } catch (Throwable $e) {}
        return $out;
    }

    /* ── Export ────────────────────────────────────────────────────────── */

    public const EXPORT_COLUMNS = ['id' => 'ID', 'name' => 'Name', 'email' => 'Email', 'phone' => 'Phone', 'centre' => 'Centre', 'role' => 'Access level',
        'level' => 'Level', 'status' => 'Status', 'work_roles' => 'Work roles', 'about' => 'What they do', 'card' => 'Gate card', 'membership' => 'Dues',
        'paid_through' => 'Dues paid through', 'owes_months' => 'Months of dues owed', 'owes_ngn' => 'Dues owed (₦)', 'volunteer' => 'Volunteer', 'ngv' => 'NextGen Vanguard', 'joined_on' => 'Joined', 'created_at' => 'Account made', 'last_login' => 'Last sign-in'];

    /**
     * The roster as CSV rows: the members a filter matches, or just $ids when
     * given (still within the filter). Returns ['csv','count','capped'].
     */
    public static function export(array $filter, array $ids = []): array
    {
        $want = $ids ? array_flip(array_map('intval', $ids)) : null;
        $rows = [];
        $capped = false;
        for ($page = 1; ; $page++) {
            $r = MemberRoster::roster(['page' => $page, 'page_size' => 200] + $filter);
            foreach ($r['members'] as $m) {
                if ($want !== null && !isset($want[(int) $m['id']])) continue;
                if (count($rows) >= self::EXPORT_MAX) { $capped = true; break 2; }
                $rows[] = $m + ['owes_months' => $m['owes']['months'] ?? '', 'owes_ngn' => $m['owes']['amount_ngn'] ?? ''];
            }
            if ($page * 200 >= (int) $r['total'] || !$r['members']) break;
        }
        $fh = fopen('php://temp', 'w+');
        fwrite($fh, "\xEF\xBB\xBF");   /* so Excel reads names with accents as UTF-8 */
        Csv::put($fh, array_values(self::EXPORT_COLUMNS));
        foreach ($rows as $m) {
            $line = [];
            foreach (array_keys(self::EXPORT_COLUMNS) as $k) $line[] = $m[$k] ?? '';
            /* The guard used to live here, inline and correct, which is
               exactly why three other exports did not have it. It is
               Csv:: now, and it also catches a leading tab. */
            Csv::put($fh, $line);
        }
        rewind($fh);
        $csv = (string) stream_get_contents($fh);
        fclose($fh);
        return ['csv' => $csv, 'count' => count($rows), 'capped' => $capped];
    }

    /* ── Message ───────────────────────────────────────────────────────── */

    /**
     * A notification to each chosen member, in the portal, and an email if
     * asked. Suspended accounts are skipped. Returns counts.
     */
    public static function message(array $ids, string $title, string $body, bool $email, string $actor): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn($i) => $i > 0)));
        $title = trim(mb_substr($title, 0, 160)); $body = trim(mb_substr($body, 0, 2000));
        if (!$ids) return ['ok' => false, 'error' => 'Choose who it goes to.'];
        if (count($ids) > self::MESSAGE_MAX) return ['ok' => false, 'error' => 'At most ' . self::MESSAGE_MAX . ' members at once — this is ' . count($ids) . '.'];
        if ($title === '') return ['ok' => false, 'error' => 'Give the message a title.'];
        if ($body === '') return ['ok' => false, 'error' => 'Write the message.'];
        $st = Database::pdo()->prepare("SELECT id FROM lms_users WHERE id = ? AND status = 'active'");
        $sent = 0; $skipped = 0; $mailed = 0;
        $key = 'desk:' . substr(hash('sha256', $title . '|' . $body . '|' . gmdate('Y-m-d H')), 0, 16);
        foreach ($ids as $id) {
            $st->execute([$id]);
            if (!$st->fetchColumn()) { $skipped++; continue; }
            if (class_exists('Notifications')) Notifications::push($id, 'announce', $title, $body, '/portal/', $key . ':' . $id);
            if ($email && class_exists('Notifications')) { Notifications::email($id, $title, $body, '/portal/'); $mailed++; }
            $sent++;
        }
        try { (new LmsRepository())->audit('member.message', '', sprintf('"%s" · %d sent · %d skipped%s', mb_substr($title, 0, 80), $sent, $skipped, $email ? ' · emailed' : ''), $actor); } catch (Throwable $e) {}
        return ['ok' => true, 'sent' => $sent, 'skipped' => $skipped, 'emailed' => $mailed];
    }

    private static function exists(int $uid): bool
    {
        $st = Database::pdo()->prepare('SELECT 1 FROM lms_users WHERE id = ?');
        $st->execute([$uid]);
        return (bool) $st->fetchColumn();
    }
}
