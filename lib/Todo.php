<?php
/**
 * lib/Todo.php — what the to-do pop-up shows a member: one list across both
 * sites.
 *
 *   assigned   tasks given to them by somebody else, newest first — the pop-up
 *              marks the ones they have not seen yet
 *   due        their own open tasks due today or already overdue
 *   cac        their open CACENTRE tasks due today or earlier (lib/CacTasks)
 *   week       their CACENTRE commitments still open this week (lib/CacWeek)
 *   suggested  pool tasks their labels fit (lib/TaskMatch::forUser)
 *
 * Read on demand by portal/todo.js after the page has loaded, so a slow
 * CACENTRE never holds up the page; each source fails soft to empty.
 */
declare(strict_types=1);

final class Todo
{
    /** How long a task given by somebody else still counts as "just assigned". */
    private const FRESH_DAYS = 7;

    public static function enabled(): bool
    {
        return !class_exists('AvRules') || AvRules::bool('tasks.todo_popup');
    }

    /** May this person take a pool task from the pop-up: members and volunteers. */
    public static function mayTake(array $u): bool
    {
        if (class_exists('LmsAuth') && LmsAuth::isOrgMember($u)) return true;
        return class_exists('Volunteers') && Volunteers::isVolunteer($u);
    }

    public static function for(array $u): array
    {
        $uid = (int) ($u['id'] ?? 0);
        $out = ['ok' => true, 'enabled' => self::enabled(), 'today' => gmdate('Y-m-d'),
                'assigned' => [], 'due' => [], 'cac' => [], 'week' => [], 'suggested' => [], 'cac_url' => ''];
        if ($uid <= 0 || !$out['enabled']) return $out;
        $today = $out['today'];
        $fresh = gmdate('Y-m-d H:i:s', time() - self::FRESH_DAYS * 86400);

        try {
            foreach (Collab::myTasks($uid, 100) as $t) {
                if ($t['done'] || (int) $t['assignee_id'] !== $uid) continue;
                $row = ['id' => (int) $t['id'], 'title' => (string) $t['title'], 'due' => (string) $t['due'],
                        'priority' => (string) $t['priority'], 'overdue' => (bool) $t['overdue'],
                        'from' => (int) $t['assigned_by'] !== $uid ? (string) $t['assigned_by_name'] : ''];
                $created = (string) ($t['assigned_at'] ?? '');
                if ($row['from'] !== '' && $created >= $fresh) $out['assigned'][] = $row + ['at' => $created];
                elseif ($row['due'] !== '' && $row['due'] <= $today) $out['due'][] = $row;
            }
        } catch (Throwable $e) { error_log('[todo] tasks: ' . $e->getMessage()); }
        usort($out['assigned'], static fn($a, $b) => strcmp($b['at'], $a['at']));
        usort($out['due'], static fn($a, $b) => strcmp($a['due'], $b['due']));

        if (class_exists('CacTasks')) {
            try {
                foreach (CacTasks::openFor($uid) as $t) {
                    if ($t['due'] === '' || $t['due'] > $today) continue;
                    $out['cac'][] = ['id' => $t['id'], 'title' => $t['title'], 'due' => $t['due'], 'priority' => $t['priority']];
                }
                $out['cac_url'] = CacTasks::consoleUrl();
            } catch (Throwable $e) { error_log('[todo] cac: ' . $e->getMessage()); }
        }
        if (class_exists('CacWeek') && CacWeek::ready()) {
            try {
                $w = CacWeek::forMember($u);
                foreach ($w['commitments'] as $c) {
                    if ($c['status'] !== 'open') continue;
                    $out['week'][] = ['id' => $c['id'], 'statement' => $c['statement'], 'delivered' => $c['delivered'],
                                      'target' => $c['target'], 'unit' => $c['unit'], 'end' => $c['end'],
                                      'goal' => $c['goal']['title'] ?? ''];
                }
            } catch (Throwable $e) { error_log('[todo] week: ' . $e->getMessage()); }
        }
        if (class_exists('TaskMatch') && self::mayTake($u)) {
            try { $out['suggested'] = TaskMatch::forUser($uid, 3); } catch (Throwable $e) { error_log('[todo] suggest: ' . $e->getMessage()); }
        }
        return $out;
    }

    /**
     * The list as the pop-up draws it (portal/todo.js, which CACENTRE's console
     * uses too): sections of items, each item carrying the action its button
     * posts — so the script knows nothing about either site.
     */
    public static function sections(array $d): array
    {
        $when = static function (string $due, string $today): string {
            if ($due === '') return '';
            if ($due < $today) return 'Overdue · ' . $due;
            return $due === $today ? 'Today' : $due;
        };
        $t = $d['today'];
        $mine = static fn(array $x, string $meta) => ['id' => 'av' . $x['id'], 'title' => $x['title'], 'meta' => $meta,
            'urgent' => !empty($x['overdue']) || $x['priority'] === 'high',
            'tick' => ['action' => 'task_toggle', 'body' => ['id' => $x['id']], 'label' => 'Done']];
        $sec = [];
        if ($d['assigned']) $sec[] = ['key' => 'assigned', 'title' => 'Just assigned to you', 'items' => array_map(
            static fn($x) => $mine($x, trim('From ' . $x['from'] . ($x['due'] !== '' ? ' · ' . $when($x['due'], $t) : ''))) + ['fresh' => $x['at']], $d['assigned'])];
        if ($d['due']) $sec[] = ['key' => 'due', 'title' => 'Due', 'items' => array_map(static fn($x) => $mine($x, $when($x['due'], $t)), $d['due'])];
        if ($d['cac']) $sec[] = ['key' => 'cac', 'title' => 'At CACENTRE', 'items' => array_map(static fn($x) => [
            'id' => 'cac' . $x['id'], 'title' => $x['title'], 'meta' => $when($x['due'], $t), 'urgent' => $x['due'] < $t,
            'tick' => ['action' => 'cac_toggle', 'body' => ['id' => $x['id']], 'label' => 'Done']], $d['cac'])];
        if ($d['week']) $sec[] = ['key' => 'week', 'title' => 'This week at CACENTRE', 'items' => array_map(static fn($c) => [
            'id' => 'wk' . $c['id'], 'title' => $c['statement'],
            'meta' => rtrim(rtrim(number_format((float) $c['delivered'], 1), '0'), '.') . ' of ' . rtrim(rtrim(number_format((float) $c['target'], 1), '0'), '.') . ' ' . $c['unit']
                      . ' · by ' . $c['end'] . ($c['goal'] !== '' ? ' · feeds ' . $c['goal'] : ''),
            'progress' => $c['target'] > 0 ? min(1, $c['delivered'] / $c['target']) : 0,
            'href' => '/portal/#tasks'], $d['week'])];
        if ($d['suggested']) $sec[] = ['key' => 'suggested', 'title' => 'Suggested for you', 'items' => array_map(static fn($x) => [
            'id' => 'sg' . $x['id'], 'title' => $x['title'], 'meta' => $x['why'] . ($x['due'] !== '' ? ' · ' . $when($x['due'], $t) : ''),
            'take' => ['action' => 'todo_take', 'body' => ['id' => $x['id']], 'label' => 'Take it']], $d['suggested'])];
        return $sec;
    }
}
