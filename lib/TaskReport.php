<?php
/**
 * lib/TaskReport.php — the day's tasks in three lists: done, undone, unclaimed.
 *
 *   done       finished today, and by whom
 *   undone     held by somebody and still open: overdue first, then due
 *              today, then blocked, then the rest — each with who holds it,
 *              how late, and what it is blocked on
 *   unclaimed  in the pool with nobody on it: oldest first, with how long
 *
 * Plus each person's day (finished / still holding / overdue), so whoever
 * hands out work sees who is carrying it and who is not.
 *
 * Read on the Tasks page by whoever hands out work, and sent to them once a
 * day by the cron (tasks.daily_report, at tasks.daily_report_hour, Lagos
 * time) as a portal notification and an email.
 */
declare(strict_types=1);

final class TaskReport
{
    private const TZ = 'Africa/Lagos';
    /** Most rows a list shows; the counts are always whole. */
    private const SHOW = 40;

    /** Today in Lagos, YYYY-MM-DD. */
    public static function today(?int $now = null): string
    {
        return (new DateTimeImmutable('@' . ($now ?? time())))->setTimezone(new DateTimeZone(self::TZ))->format('Y-m-d');
    }

    /** The UTC span of a Lagos day, as the 'Y-m-d H:i:s' strings collab_tasks keeps. */
    private static function span(string $day): array
    {
        $a = new DateTimeImmutable($day . ' 00:00:00', new DateTimeZone(self::TZ));
        $u = new DateTimeZone('UTC');
        return [$a->setTimezone($u)->format('Y-m-d H:i:s'), $a->modify('+1 day')->setTimezone($u)->format('Y-m-d H:i:s')];
    }

    /**
     * The report for one day (today by default):
     * ['day','counts'=>['done','undone','overdue','blocked','unclaimed'],'done'=>[…],'undone'=>[…],'unclaimed'=>[…],'people'=>[…]].
     */
    public static function build(?string $day = null, ?int $now = null): array
    {
        $now = $now ?? time();
        $day = $day ?? self::today($now);
        [$from, $to] = self::span($day);
        $pdo = Database::pdo();
        Collab::myTasks(0, 1);   /* makes sure the table and its columns exist */
        $names = [];
        $name = static function (int $id) use ($pdo, &$names): string {
            if ($id <= 0) return '';
            if (!isset($names[$id])) { $st = $pdo->prepare('SELECT name, email FROM lms_users WHERE id = ?'); $st->execute([$id]); $u = $st->fetch(PDO::FETCH_ASSOC); $names[$id] = $u ? ((string) $u['name'] ?: explode('@', (string) $u['email'])[0]) : 'Someone'; }
            return $names[$id];
        };
        $goal = static function (int $gid) use ($pdo): string {
            if ($gid <= 0) return '';
            try { $st = $pdo->prepare('SELECT title FROM team_goals WHERE id = ?'); $st->execute([$gid]); return (string) ($st->fetchColumn() ?: ''); } catch (Throwable $e) { return ''; }
        };

        $st = $pdo->prepare('SELECT * FROM collab_tasks WHERE done = 1 AND done_at >= ? AND done_at < ? ORDER BY done_at DESC');
        $st->execute([$from, $to]);
        $done = array_map(static fn($t) => ['id' => (int) $t['id'], 'title' => (string) $t['title'], 'by' => $name((int) $t['assignee_id']), 'by_id' => (int) $t['assignee_id'],
            'at' => (string) $t['done_at'], 'late' => (string) $t['due'] !== '' && substr(self::local((string) $t['done_at']), 0, 10) > (string) $t['due'], 'goal' => $goal((int) ($t['goal_id'] ?? 0))], $st->fetchAll(PDO::FETCH_ASSOC));

        $undone = [];
        foreach ($pdo->query('SELECT * FROM collab_tasks WHERE done = 0 AND assignee_id > 0')->fetchAll(PDO::FETCH_ASSOC) as $t) {
            $due = (string) $t['due']; $state = (string) ($t['state'] ?? '') ?: 'todo';
            $late = $due !== '' && $due < $day ? (int) round((strtotime($day) - strtotime($due)) / 86400) : 0;
            $undone[] = ['id' => (int) $t['id'], 'title' => (string) $t['title'], 'who' => $name((int) $t['assignee_id']), 'who_id' => (int) $t['assignee_id'],
                'due' => $due, 'late_days' => $late, 'due_today' => $due === $day, 'state' => $state, 'blocked' => $state === 'blocked' ? (string) ($t['blocked_reason'] ?? '') : '',
                'goal' => $goal((int) ($t['goal_id'] ?? 0)), 'priority' => (string) ($t['priority'] ?? 'normal')];
        }
        /* Overdue (latest first), due today, blocked, then the rest by date. */
        $rank = static fn($x) => $x['late_days'] > 0 ? 0 : ($x['due_today'] ? 1 : ($x['state'] === 'blocked' ? 2 : 3));
        usort($undone, static fn($a, $b) => [$rank($a), -$a['late_days'], $a['due'] === '' ? 1 : 0, $a['due']] <=> [$rank($b), -$b['late_days'], $b['due'] === '' ? 1 : 0, $b['due']]);

        $unclaimed = [];
        foreach ($pdo->query('SELECT * FROM collab_tasks WHERE done = 0 AND assignee_id = 0 ORDER BY id ASC')->fetchAll(PDO::FETCH_ASSOC) as $t) {
            $age = (string) $t['created_at'] !== '' ? max(0, (int) floor(($now - (strtotime((string) $t['created_at'] . ' UTC') ?: $now)) / 86400)) : 0;
            $unclaimed[] = ['id' => (int) $t['id'], 'title' => (string) $t['title'], 'age_days' => $age, 'due' => (string) $t['due'],
                'priority' => (string) ($t['priority'] ?? 'normal'), 'posted_by' => $name((int) $t['creator_id']), 'goal' => $goal((int) ($t['goal_id'] ?? 0))];
        }

        $people = [];
        foreach ($done as $d) { $p = &$people[$d['by_id']]; $p['name'] = $d['by']; $p['done'] = ($p['done'] ?? 0) + 1; unset($p); }
        foreach ($undone as $u) { $p = &$people[$u['who_id']]; $p['name'] = $u['who']; $p['open'] = ($p['open'] ?? 0) + 1; if ($u['late_days'] > 0) $p['overdue'] = ($p['overdue'] ?? 0) + 1; unset($p); }
        $people = array_values(array_map(static fn($p) => ['name' => $p['name'], 'done' => $p['done'] ?? 0, 'open' => $p['open'] ?? 0, 'overdue' => $p['overdue'] ?? 0], $people));
        usort($people, static fn($a, $b) => [$b['done'], -$b['overdue'], $a['name']] <=> [$a['done'], -$a['overdue'], $b['name']]);

        return [
            'day' => $day,
            'counts' => ['done' => count($done), 'undone' => count($undone), 'overdue' => count(array_filter($undone, static fn($x) => $x['late_days'] > 0)),
                         'blocked' => count(array_filter($undone, static fn($x) => $x['state'] === 'blocked')), 'unclaimed' => count($unclaimed)],
            'done' => array_slice($done, 0, self::SHOW), 'undone' => array_slice($undone, 0, self::SHOW), 'unclaimed' => array_slice($unclaimed, 0, self::SHOW),
            'people' => array_slice($people, 0, 30),
        ];
    }

    private static function local(string $utc): string
    {
        if ($utc === '') return '';
        return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone(self::TZ))->format('Y-m-d H:i');
    }

    /** One line: "Done 4 · Undone 9 (2 overdue) · Unclaimed 3". */
    public static function headline(array $r): string
    {
        $c = $r['counts'];
        return 'Done ' . $c['done'] . ' · Undone ' . $c['undone'] . ($c['overdue'] ? ' (' . $c['overdue'] . ' overdue)' : '') . ' · Unclaimed ' . $c['unclaimed'];
    }

    /** The report as the email's paragraphs (HTML, escaped). */
    public static function htmlLines(array $r): array
    {
        $e = static fn($s) => htmlspecialchars((string) $s, ENT_QUOTES);
        $list = static function (array $rows, callable $line, int $total) use ($e): string {
            if (!$rows) return '<em>None.</em>';
            $out = '<ul>' . implode('', array_map(static fn($x) => '<li>' . $line($x) . '</li>', array_slice($rows, 0, 15))) . '</ul>';
            return $total > 15 ? $out . '<em>…and ' . ($total - 15) . ' more on the Tasks page.</em>' : $out;
        };
        $c = $r['counts'];
        return [
            '<strong>' . $e(self::headline($r)) . '</strong>',
            '<strong>Done today (' . $c['done'] . ')</strong>' . $list($r['done'], static fn($x) => $e($x['title']) . ' — ' . $e($x['by']) . ($x['late'] ? ' <em>(late)</em>' : ''), $c['done']),
            '<strong>Undone (' . $c['undone'] . ')</strong>' . $list($r['undone'], static fn($x) => $e($x['title']) . ' — ' . $e($x['who'])
                . ($x['late_days'] > 0 ? ' · <strong>' . $x['late_days'] . ' day' . ($x['late_days'] === 1 ? '' : 's') . ' overdue</strong>' : ($x['due_today'] ? ' · due today' : ($x['due'] !== '' ? ' · due ' . $e($x['due']) : '')))
                . ($x['blocked'] !== '' ? ' · blocked: ' . $e($x['blocked']) : ''), $c['undone']),
            '<strong>Unclaimed (' . $c['unclaimed'] . ')</strong>' . $list($r['unclaimed'], static fn($x) => $e($x['title']) . ' — in the pool ' . ($x['age_days'] === 0 ? 'since today' : $x['age_days'] . ' day' . ($x['age_days'] === 1 ? '' : 's'))
                . ($x['priority'] === 'high' ? ' · <strong>high priority</strong>' : ''), $c['unclaimed']),
        ];
    }

    /** Whoever hands out work: coordinators and above (Volunteers::canRunTeams). */
    public static function recipients(): array
    {
        $out = [];
        try {
            foreach (Database::pdo()->query("SELECT id, name, email, role FROM lms_users WHERE status = 'active'")->fetchAll(PDO::FETCH_ASSOC) as $u) {
                if (LmsAuth::rank((string) $u['role']) >= LmsAuth::ROLE_RANK['coordinator']) $out[] = ['id' => (int) $u['id'], 'email' => (string) $u['email'], 'name' => (string) $u['name']];
            }
        } catch (Throwable $e) {}
        return $out;
    }

    /**
     * From the cron: once a day, from tasks.daily_report_hour (Lagos time),
     * the report to each recipient — in the portal and by email. A day with
     * no tasks at all sends nothing.
     */
    public static function sendIfDue(?int $now = null): ?array
    {
        $now = $now ?? time();
        if (class_exists('AvRules') && !AvRules::bool('tasks.daily_report')) return null;
        $hour = class_exists('AvRules') ? AvRules::int('tasks.daily_report_hour') : 18;
        $local = (new DateTimeImmutable('@' . $now))->setTimezone(new DateTimeZone(self::TZ));
        if ((int) $local->format('G') < $hour) return null;
        $day = $local->format('Y-m-d');
        if ((string) (Database::metaGet('task_report_day') ?? '') === $day) return null;
        Database::metaSet('task_report_day', $day);
        $r = self::build($day, $now);
        $c = $r['counts'];
        if ($c['done'] + $c['undone'] + $c['unclaimed'] === 0) return ['sent' => 0, 'empty' => true];
        $title = 'Tasks today: ' . self::headline($r);
        $sent = 0;
        foreach (self::recipients() as $p) {
            if (class_exists('Notifications')) Notifications::push($p['id'], 'report', $title, 'Done, undone and unclaimed — the day on the Tasks page.', '/portal/#tasks', 'taskreport:' . $day . ':' . $p['id']);
            if (class_exists('Mailer') && $p['email'] !== '') {
                try { Mailer::send($p['email'], $title, Mailer::shell('Tasks — ' . $local->format('l j F'), self::htmlLines($r), ['text' => 'Open Tasks', 'url' => (defined('SITE_URL') ? rtrim(SITE_URL, '/') : '') . '/portal/#tasks'])); }
                catch (Throwable $e) { error_log('[task-report] mail: ' . $e->getMessage()); }
            }
            $sent++;
        }
        return ['sent' => $sent] + $c;
    }
}
