<?php
/**
 * lib/TaskMatch.php — who a task should go to, from what people are labelled.
 *
 * Every person who can be given work has a profile of LABELS, read from what
 * the site already knows and nobody has to type again:
 *
 *   · their member role and level (coordinator, member…)
 *   · their WORK ROLES, as many as they hold (Media, Events, Instructor —
 *     set on the member desk), and what they do, in their own words
 *   · their self-declared skills, and their mentor headline
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
    /** Open work, in size points (S 1, M 2, L 4), beyond which a person is busy. */
    private const EASY_POINTS = 4;
    /** Days without signing in after which somebody is not counted on. */
    private const QUIET_DAYS = 30;

    private static ?array $profiles = null;

    /**
     * How the model is told to weigh people — the same evidence the scores
     * use, in the order that matters: what somebody has done beats what they
     * are labelled; "handed back as not my work" is an answer; somebody
     * overloaded, overdue or long absent is a poor pick however well they fit.
     */
    private const AI_WEIGH = 'Weigh, in this order: (1) their RECORD — work like this they finished counts more than any label, and a task like it they handed back as "not my kind of work" counts against them; '
        . '(2) fit of their roles (a person may hold several) and what they say they do, then course, faculty and centre; (3) whether they can: their open work in points and anything overdue, '
        . 'what they were given this week, and when they were last seen — somebody not seen for weeks is a poor pick, however good the fit. Spread the work when the fit is close. '
        . 'Give a "confidence" from 0 to 1 that the person will take it and finish it; when nobody reaches 0.35, choose nobody.';

    /** Below this, the model's pick is not acted on: the task waits for somebody to take it. */
    private const AI_MIN_CONFIDENCE = 0.35;
    /** And the sweep, which gives without asking, wants more. */
    private const AUTO_MIN_CONFIDENCE = 0.5;

    /** Tests only: stands in for the model in assignMany() — fn(system, prompt, opts) → ['ok','text']. */
    public static ?Closure $aiForTests = null;

    /* ══ Profiles ═══════════════════════════════════════════════════════════ */

    /**
     * Everybody work can go to — every member (MemberRoster's definition),
     * staff on the organisation's domain, and volunteers — with their labels,
     * what they do, and open load: id => ['id','name','labels'=>[…],'about','centre','load','role'].
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
        if (class_exists('MemberRoster')) {
            try { foreach ($pdo->query('SELECT u.id FROM lms_users u WHERE u.status = \'active\' AND ' . MemberRoster::memberWhere() . ' ORDER BY u.id DESC LIMIT 800')->fetchAll(PDO::FETCH_COLUMN) as $i) $ids[(int) $i] = true; } catch (Throwable $e) {}
        }
        unset($ids[0]);
        if (!$ids) return self::$profiles = [];
        $in = implode(',', array_map('intval', array_keys($ids)));
        $out = [];
        foreach ($pdo->query("SELECT * FROM lms_users WHERE id IN ($in) AND status = 'active'")->fetchAll(PDO::FETCH_ASSOC) as $u) {
            $out[(int) $u['id']] = ['id' => (int) $u['id'], 'name' => (string) ($u['name'] ?: explode('@', (string) $u['email'])[0]),
                'email' => strtolower((string) $u['email']), 'role' => (string) $u['role'], 'labels' => [], 'about' => '', 'centre' => '', 'load' => 0,
                /* The size of what they hold (S 1, M 2, L 4 — Collab::points), what of it is overdue, when they were last here. */
                'points' => 0, 'overdue' => 0, 'last_seen' => (string) ($u['last_login'] ?? ''), 'away_until' => '',
                'history' => ['done' => [], 'handed_back' => [], 'on_time' => 0, 'late' => 0, 'given_week' => 0]];
            if (!in_array((string) $u['role'], ['', 'learner', 'member'], true)) $out[(int) $u['id']]['labels'][] = (string) $u['role'];
        }
        $add = static function (int $id, string $label) use (&$out): void {
            $label = trim($label);
            if ($label !== '' && isset($out[$id]) && !in_array($label, $out[$id]['labels'], true)) $out[$id]['labels'][] = $label;
        };
        /* Their work roles — several each — and what they do, from the member desk and their own card. */
        try {
            if (class_exists('MemberRoster')) MemberRoster::ensure();
            foreach ($pdo->query("SELECT user_id, work_roles, about FROM member_profiles WHERE user_id IN ($in) AND (work_roles <> '' OR about <> '')")->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $id = (int) $r['user_id'];
                $desc = class_exists('MemberRoles') ? MemberRoles::descriptions() : [];
                foreach (class_exists('MemberRoster') ? MemberRoster::workRoles((string) $r['work_roles']) : [] as $role) {
                    $add($id, $role);
                    /* What the role covers, as the office describes it (lib/MemberRoles.php). */
                    if (isset($out[$id]) && isset($desc[mb_strtolower($role)])) $out[$id]['roles_desc'][$role] = $desc[mb_strtolower($role)];
                }
                if (isset($out[$id])) $out[$id]['about'] = mb_substr((string) $r['about'], 0, 300);
            }
        } catch (Throwable $e) {}
        /* Skills they declared, and a mentor's headline */
        try {
            foreach ($pdo->query("SELECT user_id, pref_value FROM user_prefs WHERE pref_key = 'skills' AND user_id IN ($in)")->fetchAll(PDO::FETCH_NUM) as [$id, $v]) {
                foreach (array_slice(array_filter(array_map('trim', explode(',', (string) $v))), 0, 8) as $sk) $add((int) $id, $sk);
            }
        } catch (Throwable $e) {}
        try {
            foreach ($pdo->query("SELECT user_id, headline FROM mentor_profiles WHERE headline <> '' AND user_id IN ($in)")->fetchAll(PDO::FETCH_NUM) as [$id, $h]) {
                if (isset($out[(int) $id]) && $out[(int) $id]['about'] === '') $out[(int) $id]['about'] = mb_substr((string) $h, 0, 300);
            }
        } catch (Throwable $e) {}
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
        /* Open load: how many, how big, and how many past their date. */
        try {
            $today = gmdate('Y-m-d');
            foreach ($pdo->query("SELECT assignee_id, size, due FROM collab_tasks WHERE done = 0 AND assignee_id IN ($in)")->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $id = (int) $r['assignee_id'];
                if (!isset($out[$id])) continue;
                $out[$id]['load']++;
                $out[$id]['points'] += class_exists('Collab') ? Collab::points((string) ($r['size'] ?? '')) : 2;
                if ((string) $r['due'] !== '' && (string) $r['due'] < $today) $out[$id]['overdue']++;
            }
        } catch (Throwable $e) {}
        /* What they did with work before, and who is away (lib/TaskLedger.php). */
        if (class_exists('TaskLedger')) {
            foreach (TaskLedger::history(array_keys($out)) as $id => $h) if (isset($out[$id])) $out[$id]['history'] = $h;
            foreach (TaskLedger::away(array_keys($out)) as $id => $d) if (isset($out[$id])) $out[$id]['away_until'] = $d;
        }
        return self::$profiles = $out;
    }

    /** One line a person would recognise: "Instructor — Catering · NGG Summer School · Egbeda". */
    public static function summary(array $p): string
    {
        $bits = array_slice($p['labels'], 0, 6);
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

    /** A word's root, so "photograph", "photography" and "photos" meet: its first five letters, for longer words. */
    private static function stem(string $w): string { return mb_strlen($w) >= 6 ? mb_substr($w, 0, 5) : $w; }

    /**
     * What two tasks share, in words — a kind of work, or a word (by its
     * root) — or '' when they are not alike.
     */
    public static function alike(string $a, string $b): string
    {
        $ka = self::kinds($a);
        foreach (self::kinds($b) as $k) if (in_array($k, $ka, true)) return $k;
        $wa = array_map([self::class, 'stem'], array_filter(self::words($a), static fn($w) => mb_strlen($w) >= 4));
        foreach (self::words($b) as $w) if (mb_strlen($w) >= 4 && in_array(self::stem($w), $wa, true)) return $w;
        return '';
    }

    /**
     * Score one person for a task. Returns [score, reasons[], fit]: score
     * ranks, fit (the part before whether they can) says whether they fit at all. $hint is an
     * optional kind of work the planner named ("teaching", "admin"…); $task
     * may carry the task's due date, size and priority.
     *
     * Fit (labels, what they say they do) says who could; the record says
     * who does: work like it finished (+), the same kind handed back as not
     * theirs (−), finishing on time or not. Then whether they can: the size of
     * what they hold, anything overdue, what they were given this week, and
     * whether they have been seen lately. A task due within three days leans
     * harder on can. Somebody away is not scored here — shortlist() leaves
     * them out.
     */
    public static function score(array $p, string $title, string $hint = '', array $task = [], ?int $now = null): array
    {
        $now = $now ?? time();
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
            elseif (mb_strlen($w) >= 6 && str_contains($labels, self::stem($w))) { $score += 1.5; $why[] = '“' . $w . '” is close to ' . self::labelWith($p, self::stem($w)); }
        }
        /* What their roles cover, as the office describes them: "Media — photos, video, posters". */
        foreach ((array) ($p['roles_desc'] ?? []) as $role => $d) {
            $dl = mb_strtolower((string) $d); $hit = '';
            foreach (self::words($title) as $w) if (mb_strlen($w) >= 4 && (str_contains($dl, $w) || (mb_strlen($w) >= 6 && str_contains($dl, self::stem($w))))) { $hit = $w; break; }
            if ($hit !== '') { $score += 1.5; $why[] = '“' . $hit . '” is part of their ' . $role . ' role'; break; }
        }
        /* What they say they do: a word of the task in it, or a kind of work it names. */
        $about = mb_strtolower((string) ($p['about'] ?? ''));
        if ($about !== '') {
            $hit = '';
            foreach (self::words($title) as $w) if (mb_strlen($w) >= 4 && !str_contains($labels, $w) && str_contains($about, $w)) { $hit = $w; break; }
            if ($hit === '') foreach ($kinds as $k) { foreach (self::KINDS[$k][0] as $kw) if (mb_strlen($kw) >= 4 && str_contains($about, $kw)) { $hit = $kw; break 2; } }
            if ($hit !== '') { $score += 1.5; $why[] = 'Says they do “' . $hit . '”'; }
        }
        if (in_array('children', $kinds, true) && str_contains($labels, 'safeguarding cleared')) { $score += 1; $why[] = 'Safeguarding cleared'; }

        /* The record: what they did with work like this. */
        $h = (array) ($p['history'] ?? []);
        $like = [];
        foreach ((array) ($h['done'] ?? []) as $d) if (self::alike($title, (string) $d) !== '') { $like[] = (string) $d; if (count($like) >= 3) break; }
        if ($like) { $score += min(3.0, 1.2 * count($like)); $why[] = 'Finished ' . count($like) . ' like it, e.g. “' . mb_substr($like[0], 0, 60) . '”'; }
        foreach ((array) ($h['handed_back'] ?? []) as $b) {
            if (self::alike($title, (string) $b['title']) === '') continue;
            $ago = (int) floor(($now - (strtotime((string) $b['at'] . ' UTC') ?: $now)) / 86400);
            if ($b['reason'] === 'not_mine') { $score -= 3; $why[] = 'Handed back “' . mb_substr((string) $b['title'], 0, 50) . '” as not their kind of work'; break; }
            if ($b['reason'] === 'busy' && $ago <= 14) { $score -= 1; $why[] = 'Handed one like it back ' . ($ago <= 0 ? 'today' : $ago . ' days ago') . ': no time'; break; }
            if ($b['reason'] === '' || $b['reason'] === 'other') { $score -= 0.5; $why[] = 'Handed one like it back'; break; }
        }
        $timed = (int) ($h['on_time'] ?? 0) + (int) ($h['late'] ?? 0);
        if ($timed >= 3) {
            $rate = (int) ($h['on_time'] ?? 0) / $timed;
            if ($rate >= 0.8) { $score += 0.5; $why[] = 'Finishes on time (' . (int) $h['on_time'] . ' of ' . $timed . ')'; }
            elseif ($rate < 0.5) { $score -= 0.5; $why[] = 'Often late (' . (int) $h['late'] . ' of ' . $timed . ')'; }
        }

        /* That is the fit. What follows is whether they can, which ranks the
           fitting people but does not make somebody fit or not. */
        $fit = $score;

        /* Whether they can: the size of what they hold, what is overdue, what they were given this week. */
        $urgent = (string) ($task['due'] ?? '') !== '' && (string) $task['due'] <= gmdate('Y-m-d', $now + 3 * 86400);
        $weight = $urgent ? 2.0 : 1.0;
        $pts = (int) ($p['points'] ?? 0);
        if ($pts > self::EASY_POINTS) { $score -= $weight * 0.35 * ($pts - self::EASY_POINTS); $why[] = $p['load'] . ' open tasks already (' . $pts . ' points of work)'; }
        elseif ($p['load'] === 0) { $score += 0.3; $why[] = 'No open tasks'; }
        if ((int) ($p['overdue'] ?? 0) > 0) { $score -= $weight * 0.8 * min(3, (int) $p['overdue']); $why[] = (int) $p['overdue'] . ' overdue'; }
        if (($g = (int) ($h['given_week'] ?? 0)) > 2) { $score -= 0.4 * ($g - 2); $why[] = 'Given ' . $g . ' tasks this week'; }
        /* Whether they are here: somebody not seen for a month is not counted on. */
        $seen = (string) ($p['last_seen'] ?? '');
        if ($seen === '') { $score -= $urgent ? 2 : 1; $why[] = 'Has not signed in yet'; }
        elseif (($days = (int) floor(($now - (strtotime($seen . ' UTC') ?: $now)) / 86400)) > self::QUIET_DAYS) { $score -= $urgent ? 3 : 2; $why[] = 'Not seen for ' . $days . ' days'; }
        return [round($score, 2), array_values(array_unique($why)), round($fit, 2)];
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
    public static function shortlist(string $title, int $n = 5, string $hint = '', array $exclude = [], array $task = []): array
    {
        $rows = [];
        foreach (self::profiles() as $p) {
            if (in_array($p['id'], $exclude, true)) continue;
            /* Away until a date (TaskLedger::setAway): nothing is put their way until then. */
            if ((string) ($p['away_until'] ?? '') !== '') continue;
            [$s, $why, $fit] = self::score($p, $title, $hint, $task);
            $rows[] = ['id' => $p['id'], 'name' => $p['name'], 'summary' => self::summary($p), 'score' => $s, 'fit' => $fit, 'why' => $why, 'load' => $p['load']];
        }
        usort($rows, static fn($a, $b) => [$b['score'], $a['load'], $a['name']] <=> [$a['score'], $b['load'], $b['name']]);
        $fit = array_values(array_filter($rows, static fn($r) => $r['fit'] >= 1));
        return array_slice($fit ?: $rows, 0, max(1, $n));
    }

    /**
     * Suggestions for one task, with AI's pick when it is available. Returns
     * ['ok', 'suggestions' => [...], 'via' => 'ai'|'labels'].
     */
    public static function suggest(string $title, int $n = 3, string $hint = '', array $task = []): array
    {
        $title = trim(mb_substr($title, 0, 300));
        if ($title === '') return ['ok' => false, 'error' => 'Describe the task first.', 'suggestions' => []];
        $short = self::shortlist($title, 10, $hint, [], $task);
        if (!$short) return ['ok' => true, 'suggestions' => [], 'via' => 'labels'];
        if (self::aiOn() && count($short) > 1) {
            $picked = self::aiPick($title, $short, $n, $task);
            if ($picked) return ['ok' => true, 'suggestions' => $picked, 'via' => 'ai'];
        }
        return ['ok' => true, 'suggestions' => array_slice($short, 0, $n), 'via' => 'labels'];
    }

    private static function aiOn(): bool { return self::$aiForTests !== null || (class_exists('Collab') && Collab::aiAvailable() && class_exists('AvAgent')); }

    /** The model, or the test's stand-in. */
    private static function ask(string $system, string $prompt, int $max): array
    {
        if (self::$aiForTests !== null) return (array) (self::$aiForTests)($system, $prompt, ['max_tokens' => $max]);
        return class_exists('AvAgent') ? AvAgent::complete($system, $prompt, ['max_tokens' => $max, 'temperature' => 0.1, 'actor' => 'tasks.match']) : ['ok' => false];
    }

    /** "due 2026-10-22 · size M · high priority" — what about the task bears on who should take it. */
    private static function taskLine(array $task): string
    {
        $b = [];
        if ((string) ($task['due'] ?? '') !== '') $b[] = 'due ' . $task['due'];
        if ((string) ($task['size'] ?? '') !== '') $b[] = 'size ' . strtoupper((string) $task['size']) . ' (S an hour or two, M a day, L several days)';
        if ((string) ($task['priority'] ?? '') === 'high') $b[] = 'high priority';
        if ((string) ($task['goal'] ?? '') !== '') $b[] = 'towards the goal “' . mb_substr((string) $task['goal'], 0, 120) . '”';
        return implode(' · ', $b);
    }

    /** Whether the last aiPick() got no usable answer (as opposed to an answer naming nobody). */
    private static bool $aiFailed = false;

    /** AI chooses from the shortlist only — names, labels and record, nothing private. */
    private static function aiPick(string $title, array $short, int $n, array $task = [], float $min = self::AI_MIN_CONFIDENCE): array
    {
        $lines = [];
        foreach ($short as $s) $lines[] = self::candidateLine($s);
        $system = 'You assign work in Afrovanguard, a Nigerian nonprofit run by volunteers. Choose the best people for the TASK from the CANDIDATES only. ' . self::AI_WEIGH . ' '
            . 'Respond with ONLY a JSON array, best first, at most ' . $n . ' items: [{"id":<candidate number>,"confidence":<0 to 1>,"why":"<one short reason, under 15 words>"}]';
        $tl = self::taskLine($task);
        $prompt = "TASK: " . $title . ($tl !== '' ? "\n(" . $tl . ')' : '') . "\n\nCANDIDATES:\n" . implode("\n", $lines);
        self::$aiFailed = true;
        $r = self::ask($system, $prompt, 300);
        if (empty($r['ok'])) return [];
        $text = (string) preg_replace('/^```(?:json)?\s*|\s*```$/i', '', trim((string) ($r['text'] ?? '')));
        $j = json_decode($text, true);
        if (!is_array($j) && preg_match('/\[.*\]/s', $text, $m)) $j = json_decode($m[0], true);
        if (!is_array($j)) return [];
        self::$aiFailed = false;
        $by = [];
        foreach ($short as $s) $by[$s['id']] = $s;
        $out = [];
        foreach ($j as $row) {
            $id = (int) (is_array($row) ? ($row['id'] ?? 0) : 0);
            if (!isset($by[$id]) || isset($out[$id])) continue;   /* only the shortlist, once each */
            $conf = self::confidence($row);
            if ($conf !== null && $conf < $min) continue;          /* not sure enough to put to anybody */
            $why = trim(mb_substr((string) ($row['why'] ?? ''), 0, 140));
            $out[$id] = $by[$id] + ['confidence' => $conf];
            if ($why !== '') array_unshift($out[$id]['why'], $why);
            if (count($out) >= $n) break;
        }
        return array_values($out);
    }

    /** The model's confidence, 0–1, or null when it gave none (an older prompt, or it forgot). */
    private static function confidence($row): ?float
    {
        if (!is_array($row) || !isset($row['confidence']) || !is_numeric($row['confidence'])) return null;
        $c = (float) $row['confidence'];
        if ($c > 1 && $c <= 100) $c /= 100;   /* "80" meaning 80% */
        return max(0.0, min(1.0, $c));
    }

    /**
     * A candidate as the model sees them: number, name, roles and labels, what
     * they do, their record, their load and when they were last here. Never
     * an email or a phone.
     */
    private static function candidateLine(array $s): string
    {
        $p = self::$profiles[$s['id']] ?? [];
        $about = trim((string) ($p['about'] ?? ''));
        $h = (array) ($p['history'] ?? []);
        $rec = [];
        if ($d = array_slice((array) ($h['done'] ?? []), 0, 3)) $rec[] = 'recently finished: ' . implode('; ', array_map(static fn($t) => '"' . mb_substr(str_replace('"', "'", (string) $t), 0, 60) . '"', $d));
        if ($b = array_slice((array) ($h['handed_back'] ?? []), 0, 2)) $rec[] = 'handed back: ' . implode('; ', array_map(static fn($x) => '"' . mb_substr(str_replace('"', "'", (string) $x['title']), 0, 50) . '"' . ($x['reason'] !== '' ? ' (' . (TaskLedger::REASONS[$x['reason']] ?? $x['reason']) . ')' : ''), $b));
        $timed = (int) ($h['on_time'] ?? 0) + (int) ($h['late'] ?? 0);
        if ($timed >= 3) $rec[] = (int) $h['on_time'] . ' of ' . $timed . ' on time';
        $seen = (string) ($p['last_seen'] ?? '');
        $seenTxt = $seen === '' ? 'never signed in' : 'last seen ' . max(0, (int) floor((time() - (strtotime($seen . ' UTC') ?: time())) / 86400)) . ' days ago';
        $rd = [];
        foreach (array_slice((array) ($p['roles_desc'] ?? []), 0, 3, true) as $role => $d) $rd[] = $role . ': ' . mb_substr(str_replace('"', "'", (string) $d), 0, 90);
        return '#' . $s['id'] . ' ' . $s['name'] . ' — ' . ($s['summary'] ?: 'no labels')
            . ($rd ? ' — roles cover: ' . implode('; ', $rd) : '')
            . ($about !== '' ? ' — says: "' . str_replace(["\n", '"'], [' ', "'"], mb_substr($about, 0, 200)) . '"' : '')
            . ($rec ? ' — RECORD: ' . implode(' · ', $rec) : ' — RECORD: none yet')
            . ' — ' . $s['load'] . ' open tasks (' . (int) ($p['points'] ?? 0) . ' points' . ((int) ($p['overdue'] ?? 0) ? ', ' . (int) $p['overdue'] . ' overdue' : '') . ')'
            . ((int) ($h['given_week'] ?? 0) ? ', given ' . (int) $h['given_week'] . ' this week' : '')
            . ' — ' . $seenTxt;
    }

    /**
     * Several tasks — a goal's steps — given out at once, by AI when it is
     * configured: it reads each step against the people whose roles (several
     * each) and own description fit it, and spreads the work. A step it finds
     * nobody for is null, and stays in the pool for whoever takes it. Its
     * answer is held to each step's own shortlist; without AI, or if it fails,
     * the labels decide (spread()). Returns taskKey => suggestion|null.
     *
     * @param array<int|string, array{title:string, kind?:string}> $tasks
     */
    public static function assignMany(array $tasks, array $exclude = [], string $about = ''): array
    {
        if (!$tasks || !self::aiOn()) return self::spread($tasks, $exclude);
        self::profiles();
        $shorts = []; $cands = []; $lines = []; $n = 0; $keyOf = [];
        foreach ($tasks as $k => $t) {
            $n++; $keyOf[$n] = $k;
            $shorts[$k] = [];
            foreach (self::shortlist((string) $t['title'], 6, (string) ($t['kind'] ?? ''), $exclude, $t) as $r) { $shorts[$k][$r['id']] = $r; $cands[$r['id']] = $r; }
            $tl = self::taskLine($t);
            $lines[] = $n . '. ' . $t['title'] . ((string) ($t['kind'] ?? '') !== '' ? ' [' . $t['kind'] . ']' : '') . ($tl !== '' ? ' (' . $tl . ')' : '');
        }
        if (!$cands) return self::spread($tasks, $exclude);
        $system = 'You assign the steps of a goal in Afrovanguard, a Nigerian nonprofit run by volunteers. For each STEP choose one person from the CANDIDATES. ' . self::AI_WEIGH . ' '
            . 'Do not give one person most of the steps when another fits nearly as well. If nobody really fits a step, answer null for it — '
            . 'it will wait in the pool for a volunteer to take. Respond with ONLY a JSON array: [{"step":<number>,"id":<candidate number or null>,"confidence":<0 to 1>,"why":"<under 15 words>"}]';
        /* The goal the steps serve: who it is for and the skills it needs say who fits. */
        $prompt = (trim($about) !== '' ? "GOAL:\n" . trim($about) . "\n\n" : '') . "STEPS:\n" . implode("\n", $lines) . "\n\nCANDIDATES:\n" . implode("\n", array_map([self::class, 'candidateLine'], array_values($cands)));
        $r = self::ask($system, $prompt, 700);
        $text = (string) preg_replace('/^```(?:json)?\s*|\s*```$/i', '', trim((string) ($r['text'] ?? '')));
        $j = empty($r['ok']) ? null : json_decode($text, true);
        if (!is_array($j) && !empty($r['ok']) && preg_match('/\[.*\]/s', $text, $m)) $j = json_decode($m[0], true);
        if (!is_array($j)) return self::spread($tasks, $exclude);

        $out = []; $answered = [];
        foreach ($j as $row) {
            if (!is_array($row)) continue;
            $k = $keyOf[(int) ($row['step'] ?? 0)] ?? null;
            if ($k === null || array_key_exists($k, $answered)) continue;
            $answered[$k] = true;
            $id = $row['id'] ?? null;
            if ($id === null || $id === '' || $id === 'null' || (int) $id <= 0) { $out[$k] = null; continue; }   /* nobody fits: the pool */
            $fit = $shorts[$k][(int) $id] ?? null;                                                              /* only that step's shortlist */
            if (!$fit) { unset($answered[$k]); continue; }
            $conf = self::confidence($row);
            if ($conf !== null && $conf < self::AI_MIN_CONFIDENCE) { $out[$k] = null; continue; }              /* not sure enough: the pool */
            $why = trim(mb_substr((string) ($row['why'] ?? ''), 0, 140));
            if ($why !== '') array_unshift($fit['why'], $why);
            $out[$k] = $fit + ['confidence' => $conf];
            self::took($fit['id'], $tasks[$k]);
        }
        /* A step the model skipped, or answered with someone off its list: the labels decide it. */
        $rest = array_diff_key($tasks, $answered);
        return $rest ? $out + self::spread($rest, $exclude) : $out;
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
            $best = self::shortlist((string) $t['title'], 1, (string) ($t['kind'] ?? ''), $exclude, $t)[0] ?? null;
            $out[$k] = $best && $best['fit'] >= 1 ? $best : null;
            if ($out[$k]) self::took($out[$k]['id'], $t);
        }
        return $out;
    }

    /** A task handed out in this run counts toward the next one: its count, size and this week's tally. */
    private static function took(int $id, array $t): void
    {
        if (!isset(self::$profiles[$id])) return;
        self::$profiles[$id]['load']++;
        self::$profiles[$id]['points'] += class_exists('Collab') ? Collab::points((string) ($t['size'] ?? '')) : 2;
        self::$profiles[$id]['history']['given_week'] = (int) (self::$profiles[$id]['history']['given_week'] ?? 0) + 1;
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

    /* ══ For one person: what in the pool fits them ════════════════════════ */

    /**
     * Pool tasks that fit this person, best first — what the to-do pop-up
     * offers as "Suggested for you". Labels only (no AI call): it is asked on
     * every page load. [['id','title','due','priority','why','score']…]
     */
    public static function forUser(int $uid, int $n = 3): array
    {
        $p = self::profiles()[$uid] ?? null;
        if (!$p || !class_exists('Collab')) return [];
        $out = [];
        foreach (Collab::poolTasks(60) as $t) {
            if ((int) ($t['creator_id'] ?? 0) === $uid) continue;   /* they posted it: they know it is there */
            [$score, $why, $fit] = self::score($p, (string) $t['title'], '', $t);
            if ($fit < 2) continue;   /* their own choice: whether they can is theirs to judge */
            $out[] = ['id' => (int) $t['id'], 'title' => (string) $t['title'], 'due' => (string) $t['due'],
                      'priority' => (string) $t['priority'], 'score' => $score, 'why' => (string) ($why[0] ?? '')];
        }
        usort($out, static fn($a, $b) => $b['score'] <=> $a['score']);
        return array_slice($out, 0, max(1, $n));
    }

    /* ══ The sweep: a task nobody took goes to whoever fits ═══════════════ */

    /** How sure the labels must be before a task is given rather than suggested. */
    private const ASSIGN_MIN_SCORE = 2.0;
    /** Nobody is handed a task while they hold this many open. */
    private const ASSIGN_MAX_LOAD = 6;

    public static function ensure(): void
    {
        static $done = false; if ($done) return; $done = true;
        Collab::myTasks(0, 1);
        foreach (['auto_assigned_at', 'assigned_at', 'assigned_by'] as $c) {
            try { if (!Database::columnExists('collab_tasks', $c)) Database::pdo()->exec("ALTER TABLE collab_tasks ADD COLUMN $c VARCHAR(32) NOT NULL DEFAULT ''"); } catch (Throwable $e) {}
        }
    }

    /**
     * From the cron. A pool task unclaimed for tasks.auto_assign_hours goes to
     * the best fit — labels make the shortlist, AI (when configured) picks from
     * it — unless nobody fits well or the best fit is already loaded. A task
     * is only ever given once this way: handed back, it stays in the pool for
     * a person to decide. Returns what it did.
     */
    public static function autoAssign(?int $now = null, int $max = 10): array
    {
        $hours = class_exists('AvRules') ? AvRules::int('tasks.auto_assign_hours') : 0;
        $out = ['assigned' => 0, 'left' => 0, 'off' => $hours <= 0];
        if ($hours <= 0) return $out;
        self::ensure();
        $now = $now ?? time();
        $st = Database::pdo()->prepare("SELECT * FROM collab_tasks WHERE assignee_id = 0 AND done = 0 AND auto_assigned_at = '' AND created_at <> '' AND created_at <= ? ORDER BY id ASC LIMIT " . max(1, $max));
        $st->execute([gmdate('Y-m-d H:i:s', $now - $hours * 3600)]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $t) {
            $title = (string) $t['title'];
            /* Given without asking, so only to somebody who fits well, is not loaded, and has been here lately. */
            $short = array_values(array_filter(self::shortlist($title, 10, '', [(int) $t['creator_id']], $t),
                static fn($r) => $r['fit'] >= self::ASSIGN_MIN_SCORE && $r['load'] < self::ASSIGN_MAX_LOAD && self::present((int) $r['id'], $now)));
            $pick = $short[0] ?? null;
            if ($pick && count($short) > 1 && self::aiOn()) {
                $ai = self::aiPick($title, $short, 1, $t, self::AUTO_MIN_CONFIDENCE);
                /* The model answered and is not sure of anybody: it stays in the pool. The
                   model did not answer: the labels decide, as without AI. */
                if (!self::$aiFailed) $pick = $ai[0] ?? null;
            }
            /* Marked either way: a task nobody fits is not re-scored every tick. */
            Database::pdo()->prepare('UPDATE collab_tasks SET auto_assigned_at = ? WHERE id = ?')->execute([gmdate('Y-m-d H:i:s', $now), (int) $t['id']]);
            if (!$pick) { $out['left']++; continue; }
            $why = 'Nobody had taken it in ' . $hours . ' hours, and it fits you: ' . ($pick['why'][0] ?? $pick['summary']) . '. Not for you? Hand it back to the pool from Tasks.';
            if (Collab::assignTask((int) $t['creator_id'], (int) $t['id'], (int) $pick['id'], true, $why, 'auto')) {
                $out['assigned']++;
                self::took((int) $pick['id'], $t);
            }
        }
        return $out;
    }

    /** Signed in within QUIET_DAYS. */
    private static function present(int $id, int $now): bool
    {
        $seen = (string) (self::$profiles[$id]['last_seen'] ?? '');
        return $seen !== '' && (strtotime($seen . ' UTC') ?: 0) >= $now - self::QUIET_DAYS * 86400;
    }

    /** For tests: forget the cached profiles. */
    public static function forget(): void { self::$profiles = null; }
}
