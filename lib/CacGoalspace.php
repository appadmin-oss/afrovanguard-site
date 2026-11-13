<?php
/**
 * lib/CacGoalspace.php — CACENTRE's Goal workspaces, worked inline in the
 * portal's Tasks page (owner, 2026-11-13: "connect and work inline with the
 * Goalspace").
 *
 * A Goal workspace runs one measurable goal at CACENTRE: each task has an
 * owner, a date and a proof; people report in one tap. The people doing the
 * work are here. This reads a member's workspaces through CACENTRE's door
 * (api/afrovanguard-goalspace.php) and sends their actions back:
 *
 *   forMember($u)                 their workspaces, open tasks, and for a lead
 *                                 the team's open tasks and members
 *   act($u, $path, $body)         one of: workspaces/{id}/reports,
 *                                 workspaces/{id}/tasks (a lead), and
 *                                 tasks/{id}/(accept|complete|decline)
 *
 * The same signed door as lib/CacWeek.php (CacSso's token, for the
 * `goalspace` door only). CACENTRE acts as the member through Goalspace's own
 * rules, so nothing here decides who may do what: it shows, sends, and passes
 * the answer on in words. Fails soft: CACENTRE being down never stops the
 * rest of the Tasks page.
 */
declare(strict_types=1);

final class CacGoalspace
{
    public const PATH = '/api/afrovanguard-goalspace.php';
    private const TIMEOUT = 6;
    /** The requests the door accepts; anything else is not sent. */
    private const ROUTES = ['~^workspaces/\d+/(reports|tasks)$~', '~^tasks/\d+/(accept|complete|decline)$~'];

    public static function ready(): bool { return class_exists('CacSso') && CacSso::ready(); }

    /** Goal workspaces in CACENTRE's own screens: files, sign-offs, plans and a lead's tools live there. */
    public static function openUrl(): string { return CacSso::base() . '/crm/goalspace.php'; }

    private static function token(array $u): string
    {
        return CacSso::mint(['id' => (int) $u['id'], 'email' => (string) ($u['email'] ?? ''), 'name' => (string) ($u['name'] ?? '')], 'goalspace');
    }

    /** ['ok', 'linked', 'workspaces' => [...], 'why', 'error', 'url'] */
    public static function forMember(array $u): array
    {
        $base = ['ok' => false, 'linked' => false, 'workspaces' => [], 'why' => '', 'error' => '', 'url' => self::ready() ? self::openUrl() : ''];
        if (!self::ready()) return ['error' => 'The link to CACENTRE is not set up on this site.'] + $base;
        $d = self::read(self::http(CacSso::base() . self::PATH . '?t=' . rawurlencode(self::token($u)), null));
        if (!$d['ok']) return ['error' => $d['error']] + $base;
        $ws = self::clean((array) ($d['workspaces'] ?? []));
        $t = self::triage($ws, gmdate('Y-m-d'));
        return ['ok' => true, 'linked' => !empty($d['linked']), 'workspaces' => $ws, 'needs_you' => $t['mine'], 'needs_help' => $t['team'],
                'why' => (string) ($d['why'] ?? ''), 'error' => ''] + $base;
    }

    /** One action, as the member. ['ok', 'error']. */
    public static function act(array $u, string $path, array $body): array
    {
        if (!self::ready()) return ['ok' => false, 'error' => 'The link to CACENTRE is not set up on this site.'];
        $path = trim($path, '/');
        $allowed = false;
        foreach (self::ROUTES as $re) if (preg_match($re, $path)) { $allowed = true; break; }
        if (!$allowed) return ['ok' => false, 'error' => 'That is done in Goal workspaces itself.'];
        $body = array_map(static fn($v) => is_scalar($v) ? mb_substr(trim((string) $v), 0, 500) : '', array_slice($body, 0, 20, true));
        $d = self::read(self::http(CacSso::base() . self::PATH . '?t=' . rawurlencode(self::token($u)), ['path' => $path, 'body' => $body]));
        return ['ok' => $d['ok'], 'error' => $d['ok'] ? '' : $d['error']];
    }

    /**
     * What needs this member now, across every goal, most urgent first, each
     * with the reason in words — and, for a lead, the team's tasks that need
     * their help. Pure: the same workspaces and day give the same answer.
     *
     * Ranked: asked of you and not yet accepted; overdue (longer first); last
     * said blocked; due within two days; on a goal running behind with nothing
     * said in a week; waiting for its proof. A task with none of these is
     * fine and is not listed — the point is to show what to do next, not
     * everything there is.
     *
     * @return array{mine: list<array>, team: list<array>}
     */
    public static function triage(array $workspaces, string $today): array
    {
        $day = static fn(string $d): ?int => preg_match('/^\d{4}-\d{2}-\d{2}/', $d) ? (int) floor((strtotime(substr($d, 0, 10) . ' 00:00:00 UTC') - strtotime($today . ' 00:00:00 UTC')) / 86400) : null;
        $when = static fn(string $at): string => preg_match('/^\d{4}-\d{2}-\d{2}/', $at) ? date('j M', strtotime(substr($at, 0, 10))) : '';
        $mine = []; $team = [];
        foreach ($workspaces as $w) {
            $behind = self::behind($w);
            foreach ($w['my_tasks'] as $t) {
                $d = $day((string) $t['due_on']); $lr = $t['last_report']; $since = $lr ? $day((string) $lr['at']) : null;
                $score = 0; $why = '';
                if ($t['is_request'] && !$t['accepted']) { $score = 95; $why = 'Asked of you: accept it, or say you can’t.'; }
                elseif ($t['status'] === 'open' && $d !== null && $d < 0) { $score = 100 + min(60, -$d); $why = (-$d) . ' day' . ($d === -1 ? '' : 's') . ' overdue.'; }
                elseif ($t['status'] === 'open' && $lr && $lr['status'] === 'blocked') { $score = 85; $why = 'You said it was blocked on ' . $when((string) $lr['at']) . '. Still?'; }
                elseif ($t['status'] === 'open' && $d !== null && $d <= 2) { $score = 80 - $d; $why = $d === 0 ? 'Due today.' : ($d === 1 ? 'Due tomorrow.' : 'Due in 2 days.'); }
                elseif ($t['status'] === 'open' && $behind && ($since === null || $since <= -7)) {
                    $score = 60; $why = 'The goal should be about ' . (int) round(100 * (float) $w['elapsed']) . '% done by now and is at ' . (int) round(100 * (float) $w['progress']) . '%. You haven’t said how this is going' . ($lr ? ' since ' . $when((string) $lr['at']) : ' yet') . '.';
                }
                elseif ($t['status'] === 'awaiting_proof') { $score = 50; $why = 'Waiting for its proof.'; }
                if ($score > 0) $mine[] = ['ws' => (int) $w['id'], 'ws_title' => (string) $w['title'], 'task' => $t, 'why' => $why, 'score' => $score];
            }
            if (($w['role'] ?? '') !== 'lead') continue;
            $myIds = array_column($w['my_tasks'], 'id');
            foreach ($w['tasks'] as $t) {
                if (in_array($t['id'], $myIds, true)) continue;
                $d = $day((string) $t['due_on']); $lr = $t['last_report'];
                if ($t['status'] === 'awaiting_review') $team[] = ['ws' => (int) $w['id'], 'ws_title' => (string) $w['title'], 'task' => $t, 'why' => $t['owner_name'] . ' is waiting for you to check the proof.', 'score' => 90];
                elseif ($lr && $lr['status'] === 'blocked') $team[] = ['ws' => (int) $w['id'], 'ws_title' => (string) $w['title'], 'task' => $t, 'why' => $t['owner_name'] . ' said it was blocked on ' . $when((string) $lr['at']) . '.', 'score' => 85];
                elseif ($d !== null && $d < 0) $team[] = ['ws' => (int) $w['id'], 'ws_title' => (string) $w['title'], 'task' => $t, 'why' => $t['owner_name'] . '’s task is ' . (-$d) . ' day' . ($d === -1 ? '' : 's') . ' overdue.', 'score' => 80 + min(60, -$d)];
            }
        }
        $by = static fn($a, $b) => [$b['score'], $a['task']['due_on'] ?: '9999'] <=> [$a['score'], $b['task']['due_on'] ?: '9999'];
        usort($mine, $by); usort($team, $by);
        return ['mine' => array_slice($mine, 0, 6), 'team' => array_slice($team, 0, 6)];
    }

    /**
     * Is the goal really behind? Not in its first 15% of time (one day into a
     * month, "nothing yet" is not news), and only by a real gap: at least 10
     * points short of where the time gone says it should be. Goalspace's
     * behind_pct alone read 100 an hour into a new goal.
     */
    public static function behind(array $w): bool
    {
        $e = $w['elapsed'] ?? null;
        if ($e === null) return (float) ($w['behind_pct'] ?? 0) > 25;
        return $e >= 0.15 && ($e - (float) ($w['progress'] ?? 0)) >= 0.10;
    }

    /**
     * Who among a goal's members fits a task a lead is giving: the portal's own
     * matcher (TaskMatch, by people's roles and skills), limited to the members
     * CACENTRE says are in that goal and who are Afrovanguard members here.
     * Only for a lead of that goal, checked with CACENTRE, not taken from the page.
     */
    public static function fits(array $u, int $wsId, string $title): array
    {
        if (!class_exists('TaskMatch') || mb_strlen(trim($title)) < 6) return [];
        $w = null;
        foreach (self::forMember($u)['workspaces'] as $x) if ($x['id'] === $wsId && $x['role'] === 'lead') { $w = $x; break; }
        if ($w === null) return [];
        $actorOf = [];
        foreach ($w['members'] as $m) if ($m['av'] > 0 && $m['role'] !== 'outside') $actorOf[$m['av']] = $m['actor'];
        if (!$actorOf) return [];
        try {
            $exclude = array_values(array_diff(array_map(static fn($p) => (int) $p['id'], TaskMatch::profiles()), array_keys($actorOf)));
            $short = TaskMatch::shortlist($title, 3, '', $exclude);
        } catch (Throwable $e) { error_log('[goalspace] fits: ' . $e->getMessage()); return []; }
        $out = [];
        foreach ($short as $p) {
            $id = (int) ($p['id'] ?? 0);
            if (!isset($actorOf[$id])) continue;
            $why = (array) ($p['why'] ?? []);
            $out[] = ['actor' => $actorOf[$id], 'name' => (string) ($p['name'] ?? ''), 'why' => (string) ($why[0] ?? ($p['summary'] ?? ''))];
        }
        return $out;
    }

    /** The door's answer, or why there is none, in words for the member. */
    private static function read(?string $raw): array
    {
        if ($raw === null) return ['ok' => false, 'error' => 'CACENTRE could not be reached just now. Try again in a moment.'];
        $d = json_decode($raw, true);
        if (!is_array($d)) return ['ok' => false, 'error' => 'CACENTRE answered with something unexpected. Try again in a moment.'];
        if (empty($d['ok'])) {
            $e = (string) ($d['error'] ?? '');
            $words = ['not-configured' => 'The link to CACENTRE is not set up on CACENTRE’s side.', 'bad-signature' => 'This site and CACENTRE do not share the same link secret.',
                      'expired' => 'The clocks on this site and CACENTRE disagree. Ask whoever runs the sites.', 'wrong-door' => 'CACENTRE does not know this kind of request yet: it needs updating.'];
            return ['ok' => false, 'error' => $words[$e] ?? ($e !== '' ? $e : 'CACENTRE refused that.')];
        }
        return $d;
    }

    /** Only the fields the page draws, typed. */
    private static function clean(array $list): array
    {
        $task = static fn($t) => ['id' => (int) ($t['id'] ?? 0), 'title' => (string) ($t['title'] ?? ''), 'status' => (string) ($t['status'] ?? ''),
            'due_on' => (string) ($t['due_on'] ?? ''), 'proof_type' => (string) ($t['proof_type'] ?? ''), 'owner_name' => (string) ($t['owner_name'] ?? ''),
            'is_request' => !empty($t['is_request']), 'accepted' => !empty($t['accepted']),
            'last_report' => is_array($t['last_report'] ?? null) ? ['status' => (string) ($t['last_report']['status'] ?? ''), 'at' => (string) ($t['last_report']['at'] ?? '')] : null];
        $out = [];
        foreach (array_slice($list, 0, 20) as $w) {
            if (!is_array($w) || (int) ($w['id'] ?? 0) <= 0) continue;
            $out[] = ['id' => (int) $w['id'], 'title' => (string) ($w['title'] ?? ''), 'role' => (string) ($w['role'] ?? ''),
                'unit' => (string) ($w['unit'] ?? ''), 'target' => is_numeric($w['target'] ?? null) ? (float) $w['target'] : null,
                'value' => is_numeric($w['value'] ?? null) ? (float) $w['value'] : null, 'due_on' => (string) ($w['due_on'] ?? ''),
                'progress' => max(0.0, min(1.0, (float) ($w['progress'] ?? 0))), 'behind_pct' => (float) ($w['behind_pct'] ?? 0),
                'elapsed' => is_numeric($w['elapsed'] ?? null) ? max(0.0, min(1.0, (float) $w['elapsed'])) : null,
                'my_tasks' => array_map($task, array_filter((array) ($w['my_tasks'] ?? []), 'is_array')),
                'tasks' => array_map($task, array_filter((array) ($w['tasks'] ?? []), 'is_array')),
                'members' => array_values(array_map(static fn($m) => ['actor' => (string) ($m['actor'] ?? ''), 'name' => (string) ($m['name'] ?? ''), 'role' => (string) ($m['role'] ?? ''), 'av' => (int) ($m['av'] ?? 0)],
                    array_filter((array) ($w['members'] ?? []), static fn($m) => is_array($m) && preg_match('/^[a-z]+:\d+$/', (string) ($m['actor'] ?? ''))))),
            ];
        }
        return $out;
    }

    /** GET when $body is null, else POST JSON. The body comes back on a refusal too. */
    private static function http(string $url, ?array $body): ?string
    {
        if (!function_exists('curl_init')) return null;
        $ch = curl_init($url);
        $opts = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => self::TIMEOUT, CURLOPT_CONNECTTIMEOUT => 3,
                 CURLOPT_FOLLOWLOCATION => false, CURLOPT_USERAGENT => 'Afrovanguard/1.0 (+goalspace)'];
        if (str_starts_with($url, 'https://')) { $opts[CURLOPT_SSL_VERIFYPEER] = true; $opts[CURLOPT_SSL_VERIFYHOST] = 2; }
        if ($body !== null) {
            $opts[CURLOPT_POST] = true;
            $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_SLASHES);
            $opts[CURLOPT_HTTPHEADER] = ['Content-Type: application/json'];
        }
        curl_setopt_array($ch, $opts);
        $out = curl_exec($ch);
        if (class_exists('CacBridge')) CacBridge::see($ch, $out, 'goalspace');
        curl_close($ch);
        return $out === false || $out === '' ? null : (string) $out;
    }
}
