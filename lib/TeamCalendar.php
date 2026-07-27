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
 *   • meetings + their Meet links   (Meetings::listFor)
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
        Database::execSchema($db, $ddl);
        // Google Calendar sync: the id of the mirrored event on the org calendar.
        try { if (!Database::columnExists('team_events', 'google_event_id')) $db->exec("ALTER TABLE team_events ADD COLUMN google_event_id VARCHAR(128) NOT NULL DEFAULT ''"); }
        catch (Throwable $e) { /* already there / driver quirk */ }
        $done = true;
    }

    /** Push a native event to the org Google Calendar (best-effort). Returns the event id or ''. */
    private static function gpush(string $title, string $date, string $start, string $end, string $location, string $note): string
    {
        if (!class_exists('GoogleWorkspace') || !GoogleWorkspace::calendarWriteEnabled()) return '';
        try {
            $ev = GoogleWorkspace::createEvent($title, $date, $start, $end, $location, $note);
            return $ev && !empty($ev['id']) ? (string) $ev['id'] : '';
        } catch (Throwable $e) { error_log('[calendar] gpush: ' . $e->getMessage()); return ''; }
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
        $location = trim(mb_substr(trim($location), 0, 200));
        $note     = trim(mb_substr(trim($note), 0, 500));
        Database::pdo()->prepare(
            'INSERT INTO team_events (author_id, title, event_date, start_time, end_time, location, note, created_at) VALUES (?,?,?,?,?,?,?,?)'
        )->execute([$authorId, $title, $date, $start, $end, $location, $note, gmdate('Y-m-d H:i:s')]);
        $id = (int) Database::pdo()->lastInsertId();
        // Mirror onto the org Google Calendar so it shows for everyone there too.
        $gid = self::gpush($title, $date, $start, $end, $location, $note);
        if ($gid !== '') { try { Database::pdo()->prepare('UPDATE team_events SET google_event_id = ? WHERE id = ?')->execute([$gid, $id]); } catch (Throwable $e) {} }
        return $id;
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
        $location = trim(mb_substr(trim($location), 0, 200));
        $note     = trim(mb_substr(trim($note), 0, 500));
        $st = Database::pdo()->prepare('UPDATE team_events SET title = ?, event_date = ?, start_time = ?, end_time = ?, location = ?, note = ? WHERE id = ? AND author_id = ?');
        $st->execute([$title, $date, $start, $end, $location, $note, $id, $uid]);
        if ($st->rowCount() === 0) return false;
        // Keep the mirrored Google Calendar event in step.
        try {
            $g = Database::pdo()->prepare('SELECT google_event_id FROM team_events WHERE id = ?'); $g->execute([$id]);
            $gid = (string) ($g->fetchColumn() ?: '');
            if ($gid !== '' && class_exists('GoogleWorkspace') && GoogleWorkspace::calendarWriteEnabled()) {
                $tz = class_exists('Config') ? Config::str('AV_WS_TZ', 'Africa/Lagos') : 'Africa/Lagos';
                $patch = ['summary' => $title, 'location' => $location, 'description' => $note];
                if ($start === '') { $patch['start'] = ['date' => $date]; $patch['end'] = ['date' => gmdate('Y-m-d', (strtotime($date . ' UTC') ?: time()) + 86400)]; }
                else { $endHm = $end !== '' ? $end : ($start); $patch['start'] = ['dateTime' => $date . 'T' . $start . ':00', 'timeZone' => $tz]; $patch['end'] = ['dateTime' => $date . 'T' . $endHm . ':00', 'timeZone' => $tz]; }
                GoogleWorkspace::updateCalendarEvent($gid, $patch);
            } elseif ($gid === '') {
                $ngid = self::gpush($title, $date, $start, $end, $location, $note);
                if ($ngid !== '') Database::pdo()->prepare('UPDATE team_events SET google_event_id = ? WHERE id = ?')->execute([$ngid, $id]);
            }
        } catch (Throwable $e) { error_log('[calendar] gsync update: ' . $e->getMessage()); }
        return true;
    }

    /** Delete a native event (author only). */
    public static function remove(int $uid, int $id): bool
    {
        self::ensure();
        if ($uid <= 0 || $id <= 0) return false;
        // Read the mirror id before deleting so we can remove it from Google too.
        $gid = '';
        try { $g = Database::pdo()->prepare('SELECT google_event_id FROM team_events WHERE id = ? AND author_id = ?'); $g->execute([$id, $uid]); $gid = (string) ($g->fetchColumn() ?: ''); } catch (Throwable $e) {}
        $st = Database::pdo()->prepare('DELETE FROM team_events WHERE id = ? AND author_id = ?');
        $st->execute([$id, $uid]);
        if ($st->rowCount() === 0) return false;
        if ($gid !== '' && class_exists('GoogleWorkspace') && GoogleWorkspace::calendarWriteEnabled()) {
            try { GoogleWorkspace::deleteCalendarEvent($gid); } catch (Throwable $e) { error_log('[calendar] gsync delete: ' . $e->getMessage()); }
        }
        return true;
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

        // 5) Meetings the user organises or is invited to — these carry the
        //    Google Meet join link, so the calendar becomes the one place to
        //    find "what's on and how to join".
        try {
            if (class_exists('Meetings')) {
                foreach (Meetings::listFor($uid, 60) as $m) {
                    if (($m['status'] ?? '') === 'cancelled') continue;
                    $at = (string) ($m['scheduled_at'] ?? '');   // UTC 'Y-m-d H:i:s'
                    $d  = substr($at, 0, 10);
                    if (!$inRange($d)) continue;
                    $t = substr($at, 11, 5);
                    $items[] = [
                        'kind' => 'meeting', 'id' => (int) $m['id'], 'title' => (string) ($m['title'] ?: 'Meeting'),
                        'date' => $d, 'time' => $t === '00:00' ? '' : $t, 'end' => '', 'all_day' => false,
                        'location' => (string) ($m['meet_url'] ?? '') !== '' ? 'Google Meet' : '',
                        'note' => trim((string) ($m['agenda'] ?? '')) !== '' ? mb_substr((string) $m['agenda'], 0, 140) : 'Meeting',
                        'url' => (string) ($m['meet_url'] ?? ''), 'who' => '', 'mine' => (int) ($m['creator_id'] ?? 0) === $uid,
                        'can_delete' => false, 'meet' => (string) ($m['meet_url'] ?? ''),
                    ];
                }
            }
        } catch (Throwable $e) { error_log('[calendar] meetings: ' . $e->getMessage()); }

        // 6) Personal reminders with a due.
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

        // 7) The org's Google Calendar (two-way sync). Pull upcoming events and
        //    merge any that aren't already represented locally — deduped by the
        //    mirrored event id (native team events + meetings we pushed) and, as
        //    a backstop for recurring-instance id drift, by title+date.
        try {
            if (class_exists('GoogleWorkspace') && GoogleWorkspace::configured()) {
                $knownIds = []; $knownKeys = [];
                foreach ($items as $it) { $knownKeys[strtolower((string) $it['title']) . '|' . $it['date']] = true; }
                try {
                    $st = Database::pdo()->prepare("SELECT google_event_id FROM team_events WHERE google_event_id <> '' AND event_date >= ? AND event_date <= ?");
                    $st->execute([$from, $to]);
                    foreach (($st->fetchAll(PDO::FETCH_COLUMN) ?: []) as $gid) $knownIds[(string) $gid] = true;
                } catch (Throwable $e) {}
                try {
                    $st = Database::pdo()->query("SELECT google_event_id FROM meetings WHERE google_event_id <> '' AND status <> 'cancelled'");
                    foreach (($st->fetchAll(PDO::FETCH_COLUMN) ?: []) as $gid) $knownIds[(string) $gid] = true;
                } catch (Throwable $e) {}
                foreach (GoogleWorkspace::calendarEvents(null, 40) as $ge) {
                    $gid = (string) ($ge['id'] ?? '');
                    if ($gid !== '' && isset($knownIds[$gid])) continue;
                    $iso = (string) ($ge['start'] ?? '');
                    $d = substr($iso, 0, 10);
                    if (!$inRange($d)) continue;
                    $key = strtolower((string) ($ge['title'] ?? '')) . '|' . $d;
                    if (isset($knownKeys[$key])) continue;
                    $knownKeys[$key] = true;
                    $meet = (string) ($ge['meet'] ?? '');
                    $t = !empty($ge['all_day']) ? '' : substr($iso, 11, 5);
                    $items[] = [
                        'kind' => $meet !== '' ? 'meeting' : 'gcal', 'id' => 0, 'title' => (string) ($ge['title'] ?? '(busy)'),
                        'date' => $d, 'time' => $t, 'end' => '', 'all_day' => !empty($ge['all_day']),
                        'location' => (string) ($ge['location'] ?? ($meet !== '' ? 'Google Meet' : '')),
                        'note' => 'Google Calendar', 'url' => $meet !== '' ? $meet : (string) ($ge['url'] ?? ''),
                        'who' => '', 'mine' => false, 'can_delete' => false, 'meet' => $meet,
                    ];
                }
            }
        } catch (Throwable $e) { error_log('[calendar] gcal pull: ' . $e->getMessage()); }

        usort($items, function ($a, $b) {
            if ($a['date'] !== $b['date']) return $a['date'] <=> $b['date'];
            $at = $a['time'] ?: '99:99'; $bt = $b['time'] ?: '99:99';
            return $at <=> $bt;
        });
        return $items;
    }
}
