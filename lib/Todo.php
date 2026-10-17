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
        $out = ['ok' => true, 'enabled' => self::enabled(), 'today' => gmdate('Y-m-d'), 'role' => self::role($u),
                'assigned' => [], 'due' => [], 'cac' => [], 'week' => [], 'suggested' => [], 'cac_url' => '',
                'member' => [], 'volunteer' => []];
        if ($uid <= 0 || !$out['enabled']) return $out;
        $out['member']    = self::memberItems($u, $out['role']);
        $out['volunteer'] = $out['role']['volunteer'] ? self::volunteerItems($u) : [];
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
     * Who is looking, in the words the pop-up uses for them:
     * ['member','ngv','volunteer','label','first'].
     */
    public static function role(array $u): array
    {
        $uid = (int) ($u['id'] ?? 0);
        $member = class_exists('LmsAuth') && LmsAuth::isOrgMember($u);
        $ngv = false; $vol = false; $volLabel = '';
        try { $ngv = class_exists('NgvMember') && NgvMember::isVanguard($uid); } catch (Throwable $e) {}
        try {
            $vol = class_exists('Volunteers') && Volunteers::canOpenDashboard($u);
            if ($vol) {
                $id = Volunteers::identity($u)[0] ?? null;
                /* "NGG Summer School volunteer", "Afrovanguard volunteer"; a team is named as one. */
                $volLabel = !$id ? 'Volunteer' : ((string) $id['source'] === 'team' ? 'Volunteer · ' . $id['label'] : (string) $id['label']);
            }
        } catch (Throwable $e) {}
        $parts = array_values(array_filter([$member ? 'Member' : '', $ngv ? 'NextGen Vanguard' : '', $volLabel]));
        return ['member' => $member, 'ngv' => $ngv, 'volunteer' => $vol, 'label' => $parts ? implode(' · ', $parts) : 'Learner',
                'first' => trim(explode(' ', (string) ($u['name'] ?? ''))[0])];
    }

    /**
     * Membership: dues, the NGV account and book reports, the gate, the next
     * mentorship session, and the member's own commitments (a volunteer sees
     * those under Volunteering, where they were made).
     * Each item: [kind, title, meta, href, urgent, nudge, (acts)].
     */
    private static function memberItems(array $u, array $role): array
    {
        $uid = (int) $u['id'];
        $items = [];
        if ($role['member'] && class_exists('LmsRepository')) {
            try {
                $d = (new LmsRepository())->duesStatus($uid);
                $st = (string) ($d['state'] ?? 'active');
                if (empty($d['lifetime']) && in_array($st, ['none', 'overdue', 'due_soon'], true)) {
                    $items[] = ['kind' => 'dues', 'title' => $st === 'due_soon' ? 'Your membership dues are due soon' : ($st === 'none' ? 'Pay your membership dues' : 'Your membership dues have lapsed'),
                                'meta' => 'Renew in a minute, by card or transfer', 'href' => '#membership', 'urgent' => $st === 'overdue', 'nudge' => $st === 'overdue'];
                }
            } catch (Throwable $e) {}
        }
        if ($role['ngv']) {
            try {
                $owed = class_exists('NgvLedger') ? (int) (NgvLedger::balance($uid)['payable'] ?? 0) : 0;
                if ($owed > 0) $items[] = ['kind' => 'ngv', 'title' => '₦' . number_format($owed) . ' outstanding on your NGV account', 'meta' => 'See what it is for, pay, or ask about it', 'href' => '#ngv-account', 'urgent' => true, 'nudge' => true];
                $read = class_exists('NgvReading') ? NgvReading::progress($uid) : [];
                if ((int) ($read['needs_work'] ?? 0) > 0) $items[] = ['kind' => 'ngv', 'title' => (int) $read['needs_work'] . ' book report' . ((int) $read['needs_work'] === 1 ? '' : 's') . ' sent back to you', 'meta' => 'Fix and resubmit', 'href' => '#ngv', 'urgent' => false, 'nudge' => true];
            } catch (Throwable $e) {}
        }
        try {
            /* Only for somebody the gate is for — the same rule as the portal's own warning. */
            if (class_exists('GatePass') && GatePass::ready() && in_array(strtolower((string) ($u['role'] ?? '')), GatePass::roles(), true)
                && ($why = (string) (GatePass::whyNot($u) ?? '')) !== '') {
                $items[] = ['kind' => 'gate', 'title' => 'The CACENTRE gate cannot let you in', 'meta' => $why, 'href' => '#attendance', 'urgent' => true, 'nudge' => true];
            }
        } catch (Throwable $e) {}
        try {
            foreach (class_exists('Mentorship') ? Mentorship::upcomingSessions($uid, 3) : [] as $m) {
                $ts = strtotime((string) $m['when'] . ' UTC') ?: 0;
                if (empty($m['live']) && ($ts === 0 || $ts > time() + 36 * 3600)) continue;
                $items[] = ['kind' => 'mentor', 'title' => (!empty($m['live']) ? 'On now: ' : 'Mentorship: ') . (string) $m['title'],
                            'meta' => trim((string) $m['role'] . ' ' . (string) $m['with']) . ($ts ? ' · ' . gmdate('D j M, g:ia', $ts) : ''),
                            'href' => '#mentorship', 'urgent' => !empty($m['live']), 'nudge' => true];
            }
        } catch (Throwable $e) {}
        if (!$role['volunteer']) foreach (self::commitments($uid) as $c) $items[] = $c;
        return $items;
    }

    /**
     * Volunteering: meetings to answer, a meeting on now, minutes to write or
     * approve, shifts in the next two days, and the actions they agreed to.
     */
    private static function volunteerItems(array $u): array
    {
        $uid = (int) $u['id'];
        $items = [];
        $now = time();
        try {
            $h = class_exists('MeetingMinutes') ? MeetingMinutes::forHub($u) : ['upcoming' => [], 'to_write' => [], 'to_approve' => []];
            foreach ($h['upcoming'] as $m) {
                if (!empty($m['live'])) {
                    $items[] = ['kind' => 'live', 'title' => 'On now: ' . $m['title'], 'meta' => (string) $m['team'], 'href' => '#volunteer', 'urgent' => true, 'nudge' => true];
                } elseif ((string) $m['rsvp'] === '' && (int) $m['ts'] <= $now + 7 * 86400) {
                    $rsvp = static fn(string $a, string $label) => ['action' => 'rsvp', 'url' => '/portal/volunteer.php', 'label' => $label,
                        'body' => ['meeting_id' => (int) $m['id'], 'occurrence' => (string) $m['occurrence'], 'answer' => $a]];
                    $items[] = ['kind' => 'rsvp', 'title' => 'Are you coming? ' . $m['title'], 'meta' => (string) $m['when'] . ((string) $m['team'] !== '' ? ' · ' . $m['team'] : ''),
                                'urgent' => false, 'nudge' => true, 'acts' => [$rsvp('yes', 'Coming'), $rsvp('no', "Can't come")]];
                }
            }
            foreach ($h['to_write'] as $m) $items[] = ['kind' => 'minutes', 'title' => 'Write the minutes: ' . $m['title'], 'meta' => (string) $m['when'], 'href' => '#volunteer', 'urgent' => false, 'nudge' => true];
            foreach ($h['to_approve'] as $m) $items[] = ['kind' => 'minutes', 'title' => 'Approve the minutes: ' . $m['title'], 'meta' => (string) $m['when'], 'href' => '#volunteer', 'urgent' => false, 'nudge' => true];
        } catch (Throwable $e) { error_log('[todo] meetings: ' . $e->getMessage()); }
        try {
            foreach (class_exists('VolunteerWork') ? VolunteerWork::list($u) : [] as $o) {
                if (empty($o['mine']) || !empty($o['past']) || (int) $o['ts'] > $now + 48 * 3600) continue;
                $items[] = ['kind' => 'shift', 'title' => 'Your shift: ' . $o['title'], 'meta' => (string) $o['when'] . ((string) $o['location'] !== '' ? ' · ' . $o['location'] : ''),
                            'href' => '#volunteer', 'urgent' => false, 'nudge' => true];
            }
        } catch (Throwable $e) { error_log('[todo] shifts: ' . $e->getMessage()); }
        foreach (self::commitments($uid) as $c) $items[] = $c;
        return $items;
    }

    /** Their own commitments (lib/Commitments), due by today: tick from the pop-up. */
    private static function commitments(int $uid): array
    {
        $out = [];
        try {
            if (!class_exists('Commitments')) return [];
            $today = gmdate('Y-m-d');
            foreach (Commitments::forMember($uid, 'open', 30) as $c) {
                $due = (string) $c['due'];
                if ($due === '' || $due > $today) continue;
                $out[] = ['kind' => 'action', 'id' => (int) $c['id'], 'title' => (string) $c['title'],
                          'meta' => ($due < $today ? 'Overdue · ' . $due : 'Today') . (class_exists('MeetingMinutes') && ($from = MeetingMinutes::sourceTitle($c)) !== '' ? ' · from ' . $from : ''),
                          'urgent' => $due < $today, 'nudge' => true,
                          'acts' => [['action' => 'done', 'url' => '/portal/commitments.php', 'label' => 'Done', 'body' => ['id' => (int) $c['id']]]]];
            }
        } catch (Throwable $e) { error_log('[todo] commitments: ' . $e->getMessage()); }
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
        $flat = static fn(array $x, string $pre) => ['id' => $pre . ($x['id'] ?? substr(md5($x['title']), 0, 8)), 'title' => $x['title'], 'meta' => $x['meta'],
            'urgent' => (bool) $x['urgent'], 'href' => $x['href'] ?? null, 'acts' => $x['acts'] ?? null];
        if ($d['volunteer']) $sec[] = ['key' => 'volunteer', 'ctx' => 'volunteer', 'title' => 'Volunteering', 'nudge' => (bool) array_filter(array_column($d['volunteer'], 'nudge')),
            'items' => array_map(static fn($x) => $flat($x, 'v'), $d['volunteer'])];
        if ($d['member']) $sec[] = ['key' => 'member', 'ctx' => 'member', 'title' => !empty($d['role']['member']) ? 'Your membership' : 'Your account', 'nudge' => (bool) array_filter(array_column($d['member'], 'nudge')),
            'items' => array_map(static fn($x) => $flat($x, 'm'), $d['member'])];
        if ($d['assigned']) $sec[] = ['key' => 'assigned', 'ctx' => 'work', 'nudge' => true, 'title' => 'Just assigned to you', 'items' => array_map(
            static fn($x) => $mine($x, trim('From ' . $x['from'] . ($x['due'] !== '' ? ' · ' . $when($x['due'], $t) : ''))) + ['fresh' => $x['at']], $d['assigned'])];
        if ($d['due']) $sec[] = ['key' => 'due', 'ctx' => 'work', 'nudge' => true, 'title' => 'Due', 'items' => array_map(static fn($x) => $mine($x, $when($x['due'], $t)), $d['due'])];
        if ($d['cac']) $sec[] = ['key' => 'cac', 'ctx' => 'work', 'nudge' => true, 'title' => 'At CACENTRE', 'items' => array_map(static fn($x) => [
            'id' => 'cac' . $x['id'], 'title' => $x['title'], 'meta' => $when($x['due'], $t), 'urgent' => $x['due'] < $t,
            'tick' => ['action' => 'cac_toggle', 'body' => ['id' => $x['id']], 'label' => 'Done']], $d['cac'])];
        if ($d['week']) $sec[] = ['key' => 'week', 'ctx' => 'work', 'nudge' => false, 'title' => 'This week at CACENTRE', 'items' => array_map(static fn($c) => [
            'id' => 'wk' . $c['id'], 'title' => $c['statement'],
            'meta' => rtrim(rtrim(number_format((float) $c['delivered'], 1), '0'), '.') . ' of ' . rtrim(rtrim(number_format((float) $c['target'], 1), '0'), '.') . ' ' . $c['unit']
                      . ' · by ' . $c['end'] . ($c['goal'] !== '' ? ' · feeds ' . $c['goal'] : ''),
            'progress' => $c['target'] > 0 ? min(1, $c['delivered'] / $c['target']) : 0,
            'href' => '/portal/#tasks'], $d['week'])];
        if ($d['suggested']) $sec[] = ['key' => 'suggested', 'ctx' => 'work', 'nudge' => false, 'title' => 'Suggested for you', 'items' => array_map(static fn($x) => [
            'id' => 'sg' . $x['id'], 'title' => $x['title'], 'meta' => $x['why'] . ($x['due'] !== '' ? ' · ' . $when($x['due'], $t) : ''),
            'take' => ['action' => 'todo_take', 'body' => ['id' => $x['id']], 'label' => 'Take it']], $d['suggested'])];
        /* The order a day reads them; the pop-up puts the section that
           matches the screen somebody is on first (the Volunteer dashboard
           leads with Volunteering). */
        $order = ['assigned' => 0, 'due' => 1, 'volunteer' => 2, 'member' => 3, 'cac' => 4, 'week' => 5, 'suggested' => 6];
        usort($sec, static fn($a, $b) => $order[$a['key']] <=> $order[$b['key']]);
        return $sec;
    }
}
