<?php
/**
 * lib/TeamCalendar.php — the integrated team calendar.
 *
 * Members create native team events; the calendar feed then MERGES those with
 * everything else the portal already knows about a person's time:
 *   • native team events          (this table)
 *   • AFG published events         (AvEvents — the org's public events calendar)
 *   • mentorship sessions          (Mentorship::upcomingSessions)
 *   • tasks with a due date        (Collab::myTasks)          — org members
 *   • personal reminders with a due (Reminders::listFor)
 *
 * so one grid shows the whole picture. All dates are stored/compared in UTC as
 * 'Y-m-d', matching the rest of the app. Portable DB layer.
 */
declare(strict_types=1);

final class TeamCalendar
{
    public static function ensure(): void
    {
        static $done = false;
        if ($done) return;
        $db = Database::pdo();
        $ddl = "CREATE TABLE IF NOT EXISTS team_events (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            author_id INTEGER NOT NULL DEFAULT 0,
            title VARCHAR(300) NOT NULL DEFAULT '',
            event_date VARCHAR(10) NOT NULL DEFAULT '',
            start_time VARCHAR(5) NOT NULL DEFAULT '',
            end_time VARCHAR(5) NOT NULL DEFAULT '',
            location VARCHAR(200) NOT NULL DEFAULT '',
            note VARCHAR(500) NOT NULL DEFAULT '',
            created_at VARCHAR(32) NOT NULL DEFAULT ''
        );
        CREATE INDEX IF NOT EXISTS idx_events_date ON team_events(event_date);";
        $drv = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
        $db->exec($drv === 'sqlite' ? $ddl : Database::translateDDL($ddl, $drv));
        $done = true;
    }

    private static function normDate(string $d): string
    {
        $d = trim($d);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) ? $d : '';
    }
    private static function normTime(string $t): string
    {
        $t = trim($t);
        return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $t) ? $t : '';
    }

    /** Create a native team event. Returns id or 0. */
    public static function create(int $authorId, string $title, string $date, string $start = '', string $end = '', string $location = '', string $note = ''): int
    {
        self::ensure();
        $title = trim(mb_substr(trim($title), 0, 300));
        $date  = self::normDate($date);
        if ($authorId <= 0 || $title === '' || $date === '') return 0;
        $start = self::normTime($start);
        $end   = self::normTime($end);
        if ($end !== '' && $start !== '' && $end < $start) $end = '';
        Database::pdo()->prepare(
            'INSERT INTO team_events (author_id, title, event_date, start_time, end_time, location, note, created_at) VALUES (?,?,?,?,?,?,?,?)'
        )->execute([$authorId, $title, $date, $start, $end,
            trim(mb_substr(trim($location), 0, 200)), trim(mb_substr(trim($note), 0, 500)), gmdate('Y-m-d H:i:s')]);
        return (int) Database::pdo()->lastInsertId();
    }

    /** Edit a native event (author only). */
    public static function update(int $uid, int $id, string $title, string $date, string $start = '', string $end = '', string $location = '', string $note = ''): bool
    {
        self::ensure();
        $title = trim(mb_substr(trim($title), 0, 300));
        $date  = self::normDate($date);
        if ($uid <= 0 || $id <= 0 || $title === '' || $date === '') return false;
        $start = self::normTime($start);
        $end   = self::normTime($end);
        if ($end !== '' && $start !== '' && $end < $start) $end = '';
        $st = Database::pdo()->prepare('UPDATE team_events SET title = ?, event_date = ?, start_time = ?, end_time = ?, location = ?, note = ? WHERE id = ? AND author_id = ?');
        $st->execute([$title, $date, $start, $end, trim(mb_substr(trim($location), 0, 200)), trim(mb_substr(trim($note), 0, 500)), $id, $uid]);
        return $st->rowCount() > 0;
    }

    /** Delete a native event (author only). */
    public static function remove(int $uid, int $id): bool
    {
        self::ensure();
        if ($uid <= 0 || $id <= 0) return false;
        $st = Database::pdo()->prepare('DELETE FROM team_events WHERE id = ? AND author_id = ?');
        $st->execute([$id, $uid]);
        return $st->rowCount() > 0;
    }

    /** Native events in [from, to] (inclusive, 'Y-m-d'). */
    private static function nativeRange(int $viewerId, string $from, string $to): array
    {
        self::ensure();
        try {
            $st = Database::pdo()->prepare(
                'SELECT e.*, u.name AS author FROM team_events e LEFT JOIN lms_users u ON u.id = e.author_id
                 WHERE e.event_date >= ? AND e.event_date <= ? ORDER BY e.event_date ASC, e.start_time ASC'
            );
            $st->execute([$from, $to]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) { return []; }
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'kind'     => 'event',
                'id'       => (int) $r['id'],
                'title'    => (string) $r['title'],
                'date'     => (string) $r['event_date'],
                'time'     => (string) $r['start_time'],
                'end'      => (string) $r['end_time'],
                'all_day'  => (string) $r['start_time'] === '',
                'location' => (string) $r['location'],
                'note'     => (string) $r['note'],
                'url'      => '',
                'who'      => (string) ($r['author'] ?: 'A member'),
                'mine'     => (int) $r['author_id'] === $viewerId,
                'can_delete' => (int) $r['author_id'] === $viewerId,
            ];
        }
        return $out;
    }

    /**
     * The merged calendar feed for [from, to]. $isOrg controls whether team
     * events + assigned tasks are included. Always safe — a failing source is
     * skipped, never fatal.
     */
    public static function feed(int $uid, string $from, string $to, bool $isOrg): array
    {
        $from = self::normDate($from); $to = self::normDate($to);
        if ($from === '' || $to === '') return [];
        if ($to < $from) { $t = $from; $from = $to; $to = $t; }
        $inRange = fn(string $d) => $d !== '' && $d >= $from && $d <= $to;
        $items = [];

        // 1) Native team events (org-shared).
        if ($isOrg) {
            foreach (self::nativeRange($uid, $from, $to) as $it) $items[] = $it;
        }

        // 2) AFG published events.
        try {
            if (class_exists('AvEvents')) {
                foreach (AvEvents::latest(12) as $e) {
                    $iso = (string) ($e['iso'] ?? '');
                    $ts  = $iso ? strtotime($iso) : 0;
                    if (!$ts) continue;
                    $d = gmdate('Y-m-d', $ts);
                    if (!$inRange($d)) continue;
                    $items[] = [
                        'kind' => 'afg', 'id' => 0, 'title' => (string) $e['title'],
                        'date' => $d, 'time' => gmdate('H:i', $ts) === '00:00' ? '' : gmdate('H:i', $ts), 'end' => '',
                        'all_day' => gmdate('H:i', $ts) === '00:00',
                        'location' => (string) ($e['location'] ?? ''), 'note' => (string) ($e['excerpt'] ?? ''),
                        'url' => (string) ($e['url'] ?? ''), 'who' => 'Afrovanguard', 'mine' => false, 'can_delete' => false,
                    ];
                }
            }
        } catch (Throwable $e) { error_log('[calendar] afg: ' . $e->getMessage()); }

        // 3) Mentorship sessions for this user.
        try {
            if (class_exists('Mentorship')) {
                foreach (Mentorship::upcomingSessions($uid, 30) as $s) {
                    $when = (string) ($s['when'] ?? '');
                    $d = substr($when, 0, 10);
                    if (!$inRange($d)) continue;
                    $t = substr($when, 11, 5);
                    $items[] = [
                        'kind' => 'session', 'id' => (int) $s['id'], 'title' => (string) ($s['title'] ?: 'Mentorship session'),
                        'date' => $d, 'time' => $t === '00:00' ? '' : $t, 'end' => '', 'all_day' => false,
                        'location' => '', 'note' => trim((string) ($s['role'] ?? '') . (($s['with'] ?? '') ? ' · ' . $s['with'] : '')),
                        'url' => (string) ($s['meet_url'] ?? ''), 'who' => (string) ($s['with'] ?? ''), 'mine' => true, 'can_delete' => false,
                    ];
                }
            }
        } catch (Throwable $e) { error_log('[calendar] sessions: ' . $e->getMessage()); }

        // 4) Tasks with a due date (org members).
        try {
            if ($isOrg && class_exists('Collab')) {
                foreach (Collab::myTasks($uid, 100) as $tk) {
                    $d = (string) ($tk['due'] ?? '');
                    if (!$inRange($d)) continue;
                    $items[] = [
                        'kind' => 'task', 'id' => (int) $tk['id'], 'title' => (string) $tk['title'],
                        'date' => $d, 'time' => '', 'end' => '', 'all_day' => true, 'location' => '',
                        'note' => $tk['done'] ? 'Done' : ($tk['overdue'] ? 'Overdue' : 'Due'),
                        'url' => '', 'who' => (string) ($tk['assignee_name'] ?? ''), 'mine' => (bool) ($tk['mine'] ?? false),
                        'can_delete' => false, 'done' => (bool) ($tk['done'] ?? false),
                    ];
                }
            }
        } catch (Throwable $e) { error_log('[calendar] tasks: ' . $e->getMessage()); }

        // 5) Personal reminders with a due.
        try {
            if (class_exists('Reminders')) {
                foreach (Reminders::listFor($uid, 100) as $r) {
                    $due = (string) ($r['due'] ?? '');
                    $d = substr($due, 0, 10);
                    if (!$inRange($d)) continue;
                    $t = substr($due, 11, 5);
                    $items[] = [
                        'kind' => 'reminder', 'id' => (int) $r['id'], 'title' => (string) $r['text'],
                        'date' => $d, 'time' => $t === '00:00' ? '' : $t, 'end' => '', 'all_day' => $t === '00:00',
                        'location' => '', 'note' => $r['done'] ? 'Done' : ($r['overdue'] ? 'Overdue' : 'Reminder'),
                        'url' => '', 'who' => 'You', 'mine' => true, 'can_delete' => false, 'done' => (bool) ($r['done'] ?? false),
                    ];
                }
            }
        } catch (Throwable $e) { error_log('[calendar] reminders: ' . $e->getMessage()); }

        usort($items, function ($a, $b) {
            if ($a['date'] !== $b['date']) return $a['date'] <=> $b['date'];
            $at = $a['time'] ?: '99:99'; $bt = $b['time'] ?: '99:99';
            return $at <=> $bt;
        });
        return $items;
    }
}
