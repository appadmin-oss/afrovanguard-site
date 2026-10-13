<?php
/**
 * lib/TaskMatch.php — who a task should go to, from what people are labelled.
 *
 * Every person who can be given work has a profile of LABELS, read from what
 * the site already knows and nobody has to type again:
 *
 *   · their member role and level (coordinator, member…)
 *   · each volunteer placement: the project and the type (Mentor on NGG,
 *     Media on MediaPro — lib/VolunteerProjects.php)
 *   · their NGG record, synced or seeded: role, programme, centre, faculty
 *     and courses (Instructor — Catering, Summer School, Egbeda)
 *   · their volunteer teams, and their role in each
 *   · their centre on the member profile
 *
 * A task's words are matched against those labels — directly ("catering",
 * "Egbeda") and through a small vocabulary of kinds of work ("record the
 * attendance" is admin work; "teach", "lesson", "class" is an instructor's) —
 * and each person's open load counts against them, so the same willing
 * person is not handed everything. That is the shortlist, and it works with
 * no AI at all.
 *
 * With AI configured (Collab::aiAvailable), the shortlist — names and labels
 * only, never an email or a phone — is handed to the model with the task, and
 * it picks and explains. Its answer is accepted only for ids on the
 * shortlist; anything else falls back to the scores. AI suggests; a person
 * assigns.
 */
declare(strict_types=1);

final class TaskMatch
{
    /** A kind of work, the words that signal it, and the labels that fit it. */
    private const KINDS = [
        'teaching'   => [['teach', 'lesson', 'class', 'course', 'train', 'workshop', 'curriculum', 'instruct', 'tutor', 'practical', 'demonstrat', 'syllabus'],
                         ['instructor', 'facilitator', 'teacher', 'tutor']],
        'mentoring'  => [['mentor', 'coach', 'guide', 'counsel', 'mentee', 'check in with', 'pastoral', 'career'],
                         ['mentor', 'coach']],
        'admin'      => [['register', 'attendance', 'record', 'admin', 'file', 'report', 'spreadsheet', 'sheet', 'schedule', 'roster', 'minutes', 'data', 'list', 'collate', 'reconcile', 'form', 'letter', 'email'],
                         ['admin', 'operations', 'secretary', 'coordinator']],
        'events'     => [['event', 'venue', 'hall', 'logistic', 'setup', 'set up', 'chairs', 'transport', 'catering for', 'refreshment', 'book the', 'programme day', 'ceremony'],
                         ['events', 'operations', 'admin']],
        'media'      => [['post', 'video', 'photo', 'media', 'design', 'flyer', 'poster', 'social', 'instagram', 'caption', 'content', 'film', 'edit', 'publicity', 'graphic'],
                         ['media', 'photography', 'design', 'content']],
        'outreach'   => [['outreach', 'school visit', 'visit', 'community', 'parents', 'recruit', 'enrol', 'sensitis', 'door', 'church', 'mosque'],
                         ['outreach', 'mentor', 'coordinator']],
        'tech'       => [['code', 'website', 'site', 'tech', 'laptop', 'computer', 'google workspace', 'app', 'software', 'internet', 'printer', 'projector', 'ai '],
                         ['tech', 'ai & technology', 'google workspace', 'coding', 'computer']],
        'fundraising'=> [['fund', 'sponsor', 'partner', 'grant', 'donor', 'donation', 'proposal', 'budget'],
                         ['fundraising', 'coordinator', 'entrepreneurship']],
        'music'      => [['music', 'choir', 'piano', 'drum', 'guitar', 'band', 'sing', 'rehears'],
                         ['music', 'piano', 'drums', 'talking drums', 'guitar', 'choir', 'vocal']],
        'children'   => [['children', 'kids', 'teen', 'students', 'pupils', 'learners', 'camp'],
                         ['mentor', 'instructor', 'facilitator']],
    ];

    private const STOP = ['the', 'and', 'for', 'with', 'from', 'into', 'onto', 'our', 'all', 'this', 'that', 'each', 'every', 'a', 'an', 'to', 'of', 'on', 'in', 'at', 'by', 'it', 'is', 'be', 'up', 'out', 'new', 'next', 'week', 'today', 'tomorrow'];

    /** Open tasks beyond which a person starts to count as busy. */
    private const EASY_LOAD = 2;

    private static ?array $profiles = null;

    /* ══ Profiles ═══════════════════════════════════════════════════════════ */

    /**
     * Everybody work can go to — organisation members and volunteers — with
     * their labels and open load: id => ['id','name','labels'=>[…],'centre','load','role'].
     */
    public static function profiles(bool $fresh = false): array
    {
        if (self::$profiles !== null && !$fresh) return self::$profiles;
        $pdo = Database::pdo();
        $ids = [];
        $domain = strtolower((string) (defined('AV_ORG_DOMAIN') ? AV_ORG_DOMAIN : 'afrovanguard.org.ng'));
        try {
            $st = $pdo->prepare("SELECT id FROM lms_users WHERE LOWER(email) LIKE ? AND status = 'active' LIMIT 500");
            $st->execute(['%@' . $domain]);
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $i) $ids[(int) $i] = true;
        } catch (Throwable $e) {}
        if (class_exists('Volunteers')) { try { $ids += Volunteers::volunteerIds(); } catch (Throwable $e) {} }
        unset($ids[0]);
        if (!$ids) return self::$profiles = [];
        $in = implode(',', array_map('intval', array_keys($ids)));
        $out = [];
        foreach ($pdo->query("SELECT id, name, email, role FROM lms_users WHERE id IN ($in) AND status = 'active'")->fetchAll(PDO::FETCH_ASSOC) as $u) {
            $out[(int) $u['id']] = ['id' => (int) $u['id'], 'name' => (string) ($u['name'] ?: explode('@', (string) $u['email'])[0]),
                'email' => strtolower((string) $u['email']), 'role' => (string) $u['role'], 'labels' => [], 'centre' => '', 'load' => 0];
            if (!in_array((string) $u['role'], ['', 'learner', 'member'], true)) $out[(int) $u['id']]['labels'][] = (string) $u['role'];
        }
        $add = static function (int $id, string $label) use (&$out): void {
            $label = trim($label);
            if ($label !== '' && isset($out[$id]) && !in_array($label, $out[$id]['labels'], true)) $out[$id]['labels'][] = $label;
        };
        /* Volunteer placements */
        try {
            foreach ($pdo->query("SELECT a.user_id, a.type, p.name FROM vol_assignments a JOIN vol_projects p ON p.id = a.project_id WHERE a.status = 'active' AND a.user_id IN ($in)")->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $t = class_exists('VolunteerProjects') ? VolunteerProjects::typeLabel((string) $r['type']) : (string) $r['type'];
                $add((int) $r['user_id'], $t . ' · ' . $r['name']);
            }
        } catch (Throwable $e) {}
        /* NGG (synced or seeded) */
        try {
            foreach ($pdo->query("SELECT n.*, u.id AS uid FROM ngg_volunteers n JOIN lms_users u ON (n.user_id = u.id OR (n.email <> '' AND n.email = LOWER(u.email))) WHERE n.status = 'active' AND u.id IN ($in)")->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $id = (int) $r['uid'];
                $add($id, (string) ($r['role_label'] ?: ucfirst((string) $r['role'])));
                $prog = (string) ($r['programme'] ?? '');
                $add($id, $prog === 'summer' ? 'NGG Summer School' : 'NextGen Genius');
                if ((string) ($r['faculty'] ?? '') !== '') $add($id, (string) $r['faculty']);
                if ((int) $r['safeguarding_cleared'] === 1) $add($id, 'safeguarding cleared');
                if ((string) $r['centre'] !== '' && isset($out[$id])) $out[$id]['centre'] = (string) $r['centre'];
            }
        } catch (Throwable $e) {}
        /* Teams */
        try {
            foreach ($pdo->query("SELECT m.user_id, m.role, t.name FROM vol_team_members m JOIN vol_teams t ON t.id = m.team_id WHERE t.archived = 0 AND m.user_id IN ($in)")->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $add((int) $r['user_id'], $r['name'] . ((string) $r['role'] !== '' && $r['role'] !== 'member' ? ' (' . $r['role'] . ')' : ''));
            }
        } catch (Throwable $e) {}
        /* Centre on the member profile, when NGG gave none */
        try {
            foreach ($pdo->query("SELECT user_id, centre FROM member_profiles WHERE centre <> '' AND user_id IN ($in)")->fetchAll(PDO::FETCH_ASSOC) as $r) {
                if (isset($out[(int) $r['user_id']]) && $out[(int) $r['user_id']]['centre'] === '') $out[(int) $r['user_id']]['centre'] = (string) $r['centre'];
            }
        } catch (Throwable $e) {}
        /* Open load */
        try {
            foreach ($pdo->query("SELECT assignee_id, COUNT(*) FROM collab_tasks WHERE done = 0 AND assignee_id IN ($in) GROUP BY assignee_id")->fetchAll(PDO::FETCH_NUM) as [$id, $n]) {
                if (isset($out[(int) $id])) $out[(int) $id]['load'] = (int) $n;
            }
        } catch (Throwable $e) {}
        return self::$profiles = $out;
    }

    /** One line a person would recognise: "Instructor — Catering · NGG Summer School · Egbeda". */
    public static function summary(array $p): string
    {
        $bits = array_slice($p['labels'], 0, 3);
        if ($p['centre'] !== '') $bits[] = $p['centre'];
        return implode(' · ', $bits);
    }

    /* ══ Scoring ════════════════════════════════════════════════════════════ */

    /** The kinds of work a task's words point to. */
    public static function kinds(string $text): array
    {
        $t = ' ' . mb_strtolower($text) . ' ';
        $out = [];
        foreach (self::KINDS as $k => [$words]) {
            foreach ($words as $w) { if (str_contains($t, $w)) { $out[] = $k; break; } }
        }
        return $out;
    }

    private static function words(string $text): array
    {
        $w = preg_split('/[^\p{L}\p{N}&]+/u', mb_strtolower($text)) ?: [];
        return array_values(array_unique(array_filter($w, static fn($x) => mb_strlen($x) >= 3 && !in_array($x, self::STOP, true))));
    }

    /**
     * Score one person for a task. Returns [score, reasons[]]. $hint is an
     * optional kind of work the planner named ("teaching", "admin"…).
     */
    public static function score(array $p, string $title, string $hint = ''): array
    {
        $labels = mb_strtolower(implode(' | ', $p['labels']) . ' | ' . $p['centre']);
        $score = 0.0; $why = [];
        $kinds = self::kinds($title);
        if ($hint !== '' && isset(self::KINDS[$hint]) && !in_array($hint, $kinds, true)) $kinds[] = $hint;
        foreach ($kinds as $k) {
            foreach (self::KINDS[$k][1] as $fit) {
                if (!str_contains($labels, $fit)) continue;
                $l = self::labelWith($p, $fit);
                /* What they volunteer as outweighs their account's role: a
                   coordinator can file a register, the admin volunteer is the one who does. */
                $score += mb_strtolower($l) === mb_strtolower($p['role']) ? 1.5 : 3;
                $why[] = ucfirst($k) . ' fits “' . $l . '”';
                break;
            }
        }
        /* A word of the task that is one of their labels: a course, a faculty, a centre, a project. */
        foreach (self::words($title) as $w) {
            if (mb_strlen($w) < 4) continue;
            if ($p['centre'] !== '' && mb_strtolower($p['centre']) === $w) { $score += 2.5; $why[] = 'At the ' . $p['centre'] . ' centre'; continue; }
            if (str_contains($labels, $w)) { $score += 2; $why[] = '“' . $w . '” is in ' . self::labelWith($p, $w); }
        }
        if (in_array('children', $kinds, true) && str_contains($labels, 'safeguarding cleared')) { $score += 1; $why[] = 'Safeguarding cleared'; }
        /* Load: the willing are not handed everything. */
        if ($p['load'] > self::EASY_LOAD) { $score -= 0.6 * ($p['load'] - self::EASY_LOAD); $why[] = $p['load'] . ' open tasks already'; }
        elseif ($p['load'] === 0) { $score += 0.3; $why[] = 'No open tasks'; }
        return [round($score, 2), array_values(array_unique($why))];
    }

    private static function labelWith(array $p, string $needle): string
    {
        foreach ($p['labels'] as $l) if (str_contains(mb_strtolower($l), $needle)) return $l;
        return $p['centre'];
    }

    /**
     * The best people for a task, best first:
     * [['id','name','summary','score','why'=>[…],'load']…]. People with no
     * relevant label are left out unless nobody fits, then the least busy.
     */
    public static function shortlist(string $title, int $n = 5, string $hint = '', array $exclude = []): array
    {
        $rows = [];
        foreach (self::profiles() as $p) {
            if (in_array($p['id'], $exclude, true)) continue;
            [$s, $why] = self::score($p, $title, $hint);
            $rows[] = ['id' => $p['id'], 'name' => $p['name'], 'summary' => self::summary($p), 'score' => $s, 'why' => $why, 'load' => $p['load']];
        }
        usort($rows, static fn($a, $b) => [$b['score'], $a['load'], $a['name']] <=> [$a['score'], $b['load'], $b['name']]);
        $fit = array_values(array_filter($rows, static fn($r) => $r['score'] >= 1));
        return array_slice($fit ?: $rows, 0, max(1, $n));
    }

    /**
     * Suggestions for one task, with AI's pick when it is available. Returns
     * ['ok', 'suggestions' => [...], 'via' => 'ai'|'labels'].
     */
    public static function suggest(string $title, int $n = 3, string $hint = ''): array
    {
        $title = trim(mb_substr($title, 0, 300));
        if ($title === '') return ['ok' => false, 'error' => 'Describe the task first.', 'suggestions' => []];
        $short = self::shortlist($title, 10, $hint);
        if (!$short) return ['ok' => true, 'suggestions' => [], 'via' => 'labels'];
        if (class_exists('Collab') && Collab::aiAvailable() && count($short) > 1) {
            $picked = self::aiPick($title, $short, $n);
            if ($picked) return ['ok' => true, 'suggestions' => $picked, 'via' => 'ai'];
        }
        return ['ok' => true, 'suggestions' => array_slice($short, 0, $n), 'via' => 'labels'];
    }

    /** AI chooses from the shortlist only — names and labels, nothing private. */
    private static function aiPick(string $title, array $short, int $n): array
    {
        $lines = [];
        foreach ($short as $s) $lines[] = '#' . $s['id'] . ' ' . $s['name'] . ' — ' . ($s['summary'] ?: 'no labels') . ' — ' . $s['load'] . ' open tasks';
        $system = 'You assign work in Afrovanguard, a Nigerian nonprofit run by volunteers. Choose the best people for the TASK from the CANDIDATES only, '
            . 'by fit of role, course, faculty and centre, and spread the work: prefer someone with fewer open tasks when the fit is close. '
            . 'Respond with ONLY a JSON array, best first, at most ' . $n . ' items: [{"id":<candidate number>,"why":"<one short reason, under 15 words>"}]';
        $prompt = "TASK: " . $title . "\n\nCANDIDATES:\n" . implode("\n", $lines);
        $r = class_exists('AvAgent') ? AvAgent::complete($system, $prompt, ['max_tokens' => 300, 'temperature' => 0.1, 'actor' => 'tasks.match']) : ['ok' => false];
        if (empty($r['ok'])) return [];
        $text = (string) preg_replace('/^```(?:json)?\s*|\s*```$/i', '', trim((string) ($r['text'] ?? '')));
        $j = json_decode($text, true);
        if (!is_array($j) && preg_match('/\[.*\]/s', $text, $m)) $j = json_decode($m[0], true);
        if (!is_array($j)) return [];
        $by = [];
        foreach ($short as $s) $by[$s['id']] = $s;
        $out = [];
        foreach ($j as $row) {
            $id = (int) (is_array($row) ? ($row['id'] ?? 0) : 0);
            if (!isset($by[$id]) || isset($out[$id])) continue;   /* only the shortlist, once each */
            $why = trim(mb_substr((string) ($row['why'] ?? ''), 0, 140));
            $out[$id] = $by[$id];
            if ($why !== '') array_unshift($out[$id]['why'], $why);
            if (count($out) >= $n) break;
        }
        return array_values($out);
    }

    /**
     * Spread several tasks over people: each goes to the best fit, and each
     * assignment adds to that person's load, so the next task leans to
     * somebody else when the fit is close. Returns taskKey => suggestion|null.
     *
     * @param array<int|string, array{title:string, kind?:string}> $tasks
     */
    public static function spread(array $tasks, array $exclude = []): array
    {
        self::profiles();
        $out = [];
        foreach ($tasks as $k => $t) {
            $best = self::shortlist((string) $t['title'], 1, (string) ($t['kind'] ?? ''), $exclude)[0] ?? null;
            $out[$k] = $best && $best['score'] >= 1 ? $best : null;
            if ($out[$k] && isset(self::$profiles[$out[$k]['id']])) self::$profiles[$out[$k]['id']]['load']++;
        }
        return $out;
    }

    /** The kinds of work this vocabulary knows, for the planner's prompt. */
    public static function kindNames(): array { return array_keys(self::KINDS); }

    /** Who is available, as role counts — what the planner should plan for. */
    public static function teamShape(): string
    {
        $c = [];
        foreach (self::profiles() as $p) {
            $first = $p['labels'][0] ?? '';
            $k = $first !== '' ? preg_replace('/\s+[—·].*$/u', '', $first) : 'member';
            $c[$k] = ($c[$k] ?? 0) + 1;
        }
        arsort($c);
        $bits = [];
        foreach (array_slice($c, 0, 8, true) as $k => $n) $bits[] = $n . ' ' . $k;
        return implode(', ', $bits);
    }

    /** For tests: forget the cached profiles. */
    public static function forget(): void { self::$profiles = null; }
}
