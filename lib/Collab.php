<?php
/**
 * lib/Collab.php — the collaboration engine behind the portals.
 *
 * Gives the member portal a "collaborative workspace" feel (à la Google
 * Workspace / Zoom Workplace) without a realtime backend, on the plain
 * PHP+SQLite stack:
 *
 *   • Presence  — a heartbeat table; "who's online now" + a live count.
 *   • Activity  — a team feed fed by the existing Events seam.
 *   • Tasks     — lightweight personal/assignable action items.
 *
 * Everything is idempotent and best-effort: tables are created on first use
 * with driver-aware DDL, and nothing here can break a page render.
 */
declare(strict_types=1);

final class Collab
{
    const ONLINE_WINDOW = 180;   // seconds since last heartbeat to count as "online"

    /* ────────────────────────── presence ────────────────────────── */
    private static function ensurePresence(): void
    {
        static $d = false; if ($d) return; $d = true;
        $pdo = Database::pdo();
        $ddl = "CREATE TABLE IF NOT EXISTS presence (
            user_id INTEGER PRIMARY KEY,
            status VARCHAR(20) NOT NULL DEFAULT 'online',
            last_seen INTEGER NOT NULL DEFAULT 0
        )";
        $drv = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        Database::execSchema($pdo, $ddl);
    }

    /** Record a heartbeat for the user. status: online | away | busy. */
    public static function heartbeat(int $uid, string $status = 'online'): void
    {
        if ($uid <= 0) return;
        self::ensurePresence();
        $status = in_array($status, ['online', 'away', 'busy'], true) ? $status : 'online';
        $now = time();
        $pdo = Database::pdo();
        // portable upsert: try update, else insert
        $n = $pdo->prepare('UPDATE presence SET status = ?, last_seen = ? WHERE user_id = ?');
        $n->execute([$status, $now, $uid]);
        if ($n->rowCount() === 0) {
            try { $pdo->prepare('INSERT INTO presence (user_id, status, last_seen) VALUES (?,?,?)')->execute([$uid, $status, $now]); }
            catch (Throwable $e) { /* raced with another insert — fine */ }
        }
    }

    /** Members seen within the window: [{id,name,initials,status,ago}]. Org-only. */
    public static function onlineUsers(int $limit = 40): array
    {
        self::ensurePresence();
        $cut = time() - self::ONLINE_WINDOW;
        $limit = max(1, min(100, $limit));
        $sql = 'SELECT p.user_id, p.status, p.last_seen, u.name, u.email
                FROM presence p JOIN lms_users u ON u.id = p.user_id
                WHERE p.last_seen >= ? ORDER BY p.last_seen DESC LIMIT ' . $limit;
        $st = Database::pdo()->prepare($sql);
        $st->execute([$cut]);
        $now = time();
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[] = [
                'id'       => (int) $r['user_id'],
                'name'     => (string) $r['name'],
                'initials' => self::initials((string) $r['name'], (string) $r['email']),
                'status'   => (string) $r['status'],
                'ago'      => self::ago($now - (int) $r['last_seen']),
            ];
        }
        return $out;
    }

    public static function onlineCount(): int
    {
        self::ensurePresence();
        $st = Database::pdo()->prepare('SELECT COUNT(*) FROM presence WHERE last_seen >= ?');
        $st->execute([time() - self::ONLINE_WINDOW]);
        return (int) $st->fetchColumn();
    }

    /* ────────────────────────── activity ────────────────────────── */
    private static function ensureActivity(): void
    {
        static $d = false; if ($d) return; $d = true;
        $pdo = Database::pdo();
        $ddl = "CREATE TABLE IF NOT EXISTS activity (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            actor_id INTEGER NOT NULL DEFAULT 0,
            actor_name VARCHAR(160) NOT NULL DEFAULT '',
            verb VARCHAR(60) NOT NULL DEFAULT '',
            object VARCHAR(300) NOT NULL DEFAULT '',
            url VARCHAR(400) NOT NULL DEFAULT '',
            created_at TEXT NOT NULL DEFAULT ''
        )";
        $drv = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        Database::execSchema($pdo, $ddl);
    }

    /** Append a team-activity entry (best-effort). */
    public static function log(int $actorId, string $actorName, string $verb, string $object = '', string $url = ''): void
    {
        try {
            self::ensureActivity();
            Database::pdo()->prepare('INSERT INTO activity (actor_id, actor_name, verb, object, url, created_at) VALUES (?,?,?,?,?,?)')
                ->execute([$actorId, mb_substr($actorName, 0, 160), mb_substr($verb, 0, 60), mb_substr($object, 0, 300), mb_substr($url, 0, 400), gmdate('Y-m-d H:i:s')]);
        } catch (Throwable $e) { error_log('[collab] activity log: ' . $e->getMessage()); }
    }

    /** Most recent team activity: [{actor,initials,verb,object,url,ago}]. */
    public static function recentActivity(int $limit = 18): array
    {
        self::ensureActivity();
        $limit = max(1, min(50, $limit));
        $rows = Database::pdo()->query('SELECT * FROM activity ORDER BY id DESC LIMIT ' . $limit)->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $now = time();
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'actor'    => (string) $r['actor_name'] ?: 'Someone',
                'initials' => self::initials((string) $r['actor_name'], ''),
                'verb'     => (string) $r['verb'],
                'object'   => (string) $r['object'],
                'url'      => (string) $r['url'],
                'ago'      => self::ago($now - (int) (strtotime((string) $r['created_at'] . ' UTC') ?: $now)),
            ];
        }
        return $out;
    }

    /** Subscribe to domain events → team activity. Called once from bootstrap. */
    public static function bootEvents(): void
    {
        static $done = false; if ($done || !class_exists('Events')) return; $done = true;
        Events::on('member.created', fn($p) => self::log(0, (string) ($p['name'] ?? 'A new member'), 'joined Afrovanguard', '', ''));
        Events::on('workspace.connected', fn($p) => self::log((int) ($p['user_id'] ?? 0), self::nameOf((int) ($p['user_id'] ?? 0)), 'connected their Google Workspace', '', '/workspace'));
        Events::on('mentorship.session_scheduled', fn($p) => self::log(0, 'A mentor', 'scheduled a mentorship session', '', '/mentorship/'));
        Events::on('diary.published', fn($p) => self::log((int) ($p['author_id'] ?? 0), (string) ($p['author'] ?? 'A member'), 'published a Diary entry', (string) ($p['title'] ?? ''), (string) ($p['url'] ?? '/diary/')));
        Events::on('course.completed', fn($p) => self::log((int) ($p['user_id'] ?? 0), (string) ($p['name'] ?? 'A learner'), 'completed a course', (string) ($p['course'] ?? ''), ''));
    }

    /* ────────────────────────── tasks ────────────────────────── */
    private static function ensureTasks(): void
    {
        static $d = false; if ($d) return; $d = true;
        $pdo = Database::pdo();
        $ddl = "CREATE TABLE IF NOT EXISTS collab_tasks (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            creator_id INTEGER NOT NULL DEFAULT 0,
            assignee_id INTEGER NOT NULL DEFAULT 0,
            title VARCHAR(300) NOT NULL DEFAULT '',
            done INTEGER NOT NULL DEFAULT 0,
            due TEXT NOT NULL DEFAULT '',
            created_at TEXT NOT NULL DEFAULT ''
        )";
        $drv = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        Database::execSchema($pdo, $ddl);
        // Priority is a later addition — add it idempotently.
        try { if (!Database::columnExists('collab_tasks', 'priority')) $pdo->exec("ALTER TABLE collab_tasks ADD COLUMN priority VARCHAR(8) NOT NULL DEFAULT 'normal'"); }
        catch (Throwable $e) { /* already there / driver quirk */ }
        // goal_id links a task back to the goal it advances (AI-generated tasks
        // set this); 0 = standalone. Added idempotently.
        try { if (!Database::columnExists('collab_tasks', 'goal_id')) $pdo->exec("ALTER TABLE collab_tasks ADD COLUMN goal_id INTEGER NOT NULL DEFAULT 0"); }
        catch (Throwable $e) { /* already there / driver quirk */ }
    }

    /** Valid task priorities (low → normal → high). */
    private static function normPriority(string $p): string { $p = strtolower(trim($p)); return in_array($p, ['low','normal','high'], true) ? $p : 'normal'; }

    /** Create a task. Assignee defaults to the creator. Returns the new id or 0. */
    public static function addTask(int $creatorId, string $title, int $assigneeId = 0, string $due = '', string $priority = 'normal', int $goalId = 0): int
    {
        $title = trim(mb_substr(trim($title), 0, 300));
        if ($creatorId <= 0 || $title === '') return 0;
        self::ensureTasks();
        $assignee = $assigneeId > 0 ? $assigneeId : $creatorId;
        $due = preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($due)) ? trim($due) : '';
        Database::pdo()->prepare('INSERT INTO collab_tasks (creator_id, assignee_id, title, done, due, priority, goal_id, created_at) VALUES (?,?,?,0,?,?,?,?)')
            ->execute([$creatorId, $assignee, $title, $due, self::normPriority($priority), max(0, $goalId), gmdate('Y-m-d H:i:s')]);
        $newId = (int) Database::pdo()->lastInsertId();
        // Notify + email the assignee when a task is delegated to them (not self-assigned).
        if ($assignee !== $creatorId && class_exists('Notifications')) {
            try {
                Notifications::push($assignee, 'task', 'New task: ' . $title,
                    'Assigned to you by ' . self::nameOf($creatorId) . '.', '/portal/#tasks', 'task:' . $newId);
                Notifications::email($assignee, 'You’ve been assigned a task: ' . $title,
                    self::nameOf($creatorId) . ' assigned you a task' . ($due !== '' ? ' (due ' . $due . ')' : '') . '. Open the portal to pick it up.', '/portal/#tasks');
            } catch (Throwable $e) { error_log('[collab] notify: ' . $e->getMessage()); }
        }
        return $newId;
    }

    /**
     * Post a task to the shared org pool — unassigned (assignee_id = 0), so any
     * member can claim it. Returns the new id or 0. Logs to the team activity
     * feed so the pool feels alive.
     */
    public static function addPoolTask(int $creatorId, string $title, string $due = '', string $priority = 'normal', int $goalId = 0): int
    {
        $title = trim(mb_substr(trim($title), 0, 300));
        if ($creatorId <= 0 || $title === '') return 0;
        self::ensureTasks();
        $due = preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($due)) ? trim($due) : '';
        Database::pdo()->prepare('INSERT INTO collab_tasks (creator_id, assignee_id, title, done, due, priority, goal_id, created_at) VALUES (?,0,?,0,?,?,?,?)')
            ->execute([$creatorId, $title, $due, self::normPriority($priority), max(0, $goalId), gmdate('Y-m-d H:i:s')]);
        $newId = (int) Database::pdo()->lastInsertId();
        self::log($creatorId, self::nameOf($creatorId), 'posted a task to the pool', $title, '/portal/#tasks');
        return $newId;
    }

    /**
     * Claim an open pool task for a member. Atomic: only succeeds if the task is
     * still unassigned and not done, so two members can't grab the same one.
     * Returns the claimed task (shaped) or null.
     */
    public static function claimTask(int $uid, int $taskId): ?array
    {
        self::ensureTasks();
        if ($uid <= 0 || $taskId <= 0) return null;
        $st = Database::pdo()->prepare('UPDATE collab_tasks SET assignee_id = ? WHERE id = ? AND assignee_id = 0 AND done = 0');
        $st->execute([$uid, $taskId]);
        if ($st->rowCount() === 0) return null;   // already claimed / done / gone
        $task = self::oneTask($uid, $taskId);
        if ($task) self::log($uid, self::nameOf($uid), 'claimed a task', $task['title'], '/portal/#tasks');
        return $task;
    }

    /**
     * Release a task back to the pool (assignee_id = 0). Allowed for the current
     * assignee or the creator. Returns true if it went back.
     */
    public static function releaseTask(int $uid, int $taskId): bool
    {
        self::ensureTasks();
        if ($uid <= 0 || $taskId <= 0) return false;
        $st = Database::pdo()->prepare('UPDATE collab_tasks SET assignee_id = 0 WHERE id = ? AND done = 0 AND (assignee_id = ? OR creator_id = ?)');
        $st->execute([$taskId, $uid, $uid]);
        return $st->rowCount() > 0;
    }

    /* ────────────────────── AI: goal → tasks ─────────────────────── */

    /** True when ANY AI backend is wired up — Claude (AvBot) or Gemini. */
    public static function aiAvailable(): bool
    {
        // The Studio master switch wins over a configured key: leadership can
        // stop every AI call without pulling credentials out of the environment.
        if (class_exists('AvRules') && !AvRules::bool('ai.enabled')) return false;
        return (class_exists('AvBot') && AvBot::configured())
            || (class_exists('Gemini') && Gemini::configured());
    }

    /**
     * One text completion through whichever AI key is configured: Claude first
     * (best instruction-following), else Gemini. Same ['ok','text','error']
     * shape from either, plus 'via' for diagnostics. Callers don't care which.
     */
    private static function aiComplete(string $system, string $prompt, int $maxTokens = 900): array
    {
        if (class_exists('AvBot') && AvBot::configured()) {
            $r = AvBot::reply($prompt, [], ['system' => $system, 'max_tokens' => $maxTokens]);
            $r['via'] = 'claude';
            if (!empty($r['ok'])) return $r;
            // Fall through to Gemini if Claude errored AND Gemini is available.
            if (!(class_exists('Gemini') && Gemini::configured())) return $r;
        }
        if (class_exists('Gemini') && Gemini::configured()) {
            $r = Gemini::generate($prompt, ['system' => $system, 'max_tokens' => $maxTokens, 'temperature' => 0.3]);
            $r['via'] = 'gemini';
            return $r;
        }
        return ['ok' => false, 'text' => '', 'error' => 'AI is not configured (set ANTHROPIC_API_KEY or AV_GEMINI_API_KEY).', 'via' => ''];
    }

    /**
     * Ask the AI to break a goal into concrete, actionable tasks and drop them
     * into the shared pool (linked to the goal). Returns
     * ['ok'=>bool, 'created'=>[…shaped tasks…], 'error'=>?string, 'suggested'=>N].
     *
     * The tasks are posted UNCLAIMED so the team can divide the work — exactly
     * the "AI creates tasks from goals, anyone can take them up" flow.
     */
    public static function aiTasksFromGoal(int $uid, int $goalId, int $max = 6): array
    {
        if ($uid <= 0) return ['ok' => false, 'created' => [], 'error' => 'Sign in first.'];
        if (!self::aiAvailable()) return ['ok' => false, 'created' => [], 'error' => 'AI is not configured (set ANTHROPIC_API_KEY or AV_GEMINI_API_KEY).'];
        if (!class_exists('Goals')) return ['ok' => false, 'created' => [], 'error' => 'Goals are unavailable.'];
        $goal = Goals::get($goalId);
        if (!$goal || $goal['title'] === '') return ['ok' => false, 'created' => [], 'error' => 'Goal not found.'];
        $max = max(1, min(10, $max));

        $today = gmdate('Y-m-d');
        // Instructions live in the Studio (AvPrompts), which also appends the
        // organisation's live rules and knowledge — so the planner follows
        // Afrovanguard's current policy, not a policy frozen at deploy time.
        $system = class_exists('AvPrompts')
            ? AvPrompts::render('goal.tasks', ['max' => (string) $max, 'today' => $today])
            : "You are an operations planner for Afrovanguard, a Pan-African nonprofit. Turn the GOAL into 3–{$max} "
              . "concrete tasks, each starting with a verb, ordered sensibly, with a realistic \"days\" value from today ({$today}) "
              . "and a priority of high, normal or low. Do not invent external facts. Respond with ONLY a JSON array: "
              . '[{"title":"...","priority":"high|normal|low","days":<integer 1-60>}]';

        $prompt = 'GOAL: ' . $goal['title']
            . ($goal['target'] !== '' ? "\nTARGET / SUCCESS METRIC: " . $goal['target'] : '')
            . "\n\nBreak this goal into tasks now.";

        $res = self::aiComplete($system, $prompt, 900);
        if (empty($res['ok'])) return ['ok' => false, 'created' => [], 'error' => (string) ($res['error'] ?? 'AI request failed.')];
        $via = (string) ($res['via'] ?? '');

        $items = self::parseAiTasks((string) $res['text']);
        if (!$items) return ['ok' => false, 'created' => [], 'error' => 'The AI did not return usable tasks. Try again.'];

        $created = [];
        foreach (array_slice($items, 0, $max) as $it) {
            $title = trim(mb_substr((string) ($it['title'] ?? ''), 0, 300));
            if ($title === '') continue;
            $days = (int) ($it['days'] ?? 0);
            $due  = ($days > 0 && $days <= 400) ? gmdate('Y-m-d', strtotime($today . ' +' . $days . ' days') ?: time()) : '';
            $pri  = self::normPriority((string) ($it['priority'] ?? 'normal'));
            $id = self::addPoolTask($uid, $title, $due, $pri, $goalId);
            if ($id > 0) { $t = self::oneTask($uid, $id); if ($t) $created[] = $t; }
        }
        if (!$created) return ['ok' => false, 'created' => [], 'error' => 'Could not create tasks.'];
        return ['ok' => true, 'created' => $created, 'error' => null, 'suggested' => count($items), 'via' => $via];
    }

    /** Tolerantly parse the AI's JSON task array (handles stray prose / code fences). */
    private static function parseAiTasks(string $text): array
    {
        $text = trim($text);
        // Strip ```json … ``` fences if the model added them.
        $text = (string) preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $text);
        $decoded = json_decode($text, true);
        if (!is_array($decoded)) {
            // Last resort: grab the first [...] block.
            if (preg_match('/\[.*\]/s', $text, $m)) $decoded = json_decode($m[0], true);
        }
        if (!is_array($decoded)) return [];
        // Accept either a bare array or {"tasks":[...]}.
        if (isset($decoded['tasks']) && is_array($decoded['tasks'])) $decoded = $decoded['tasks'];
        $out = [];
        foreach ($decoded as $row) {
            if (is_string($row)) { $out[] = ['title' => $row, 'priority' => 'normal', 'days' => 0]; continue; }
            if (is_array($row) && isset($row['title'])) $out[] = $row;
        }
        return $out;
    }

    /** Toggle done — only the assignee or creator may. Returns the new state (or null). */
    public static function toggleTask(int $uid, int $taskId): ?bool
    {
        self::ensureTasks();
        $t = self::ownedTask($uid, $taskId);
        if (!$t) return null;
        $new = $t['done'] ? 0 : 1;
        Database::pdo()->prepare('UPDATE collab_tasks SET done = ? WHERE id = ?')->execute([$new, $taskId]);
        if ($new === 1) self::log($uid, self::nameOf($uid), 'completed a task', (string) $t['title'], '/portal/#tasks');
        return (bool) $new;
    }

    public static function deleteTask(int $uid, int $taskId): bool
    {
        self::ensureTasks();
        if (!self::ownedTask($uid, $taskId)) return false;
        Database::pdo()->prepare('DELETE FROM collab_tasks WHERE id = ?')->execute([$taskId]);
        return true;
    }

    /** The user's tasks (assigned to them or created by them), open first. */
    public static function myTasks(int $uid, int $limit = 50): array
    {
        self::ensureTasks();
        $limit = max(1, min(100, $limit));
        // Open first, then soonest due (undated last), newest first within.
        $st = Database::pdo()->prepare(
            "SELECT * FROM collab_tasks WHERE assignee_id = ? OR creator_id = ?
             ORDER BY done ASC, CASE WHEN due = '' THEN 1 ELSE 0 END ASC, due ASC, id DESC LIMIT " . $limit
        );
        $st->execute([$uid, $uid]);
        return array_map(fn($r) => self::shapeTask($r, $uid), $st->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /**
     * The shared org task pool — unclaimed, still-open tasks anyone can pick up.
     * High priority and soonest deadlines first. This is the org-wide view, not
     * scoped to one member.
     */
    public static function poolTasks(int $limit = 60): array
    {
        self::ensureTasks();
        $limit = max(1, min(200, $limit));
        // Priority weight high→normal→low, then soonest due (undated last).
        $st = Database::pdo()->prepare(
            "SELECT * FROM collab_tasks WHERE assignee_id = 0 AND done = 0
             ORDER BY CASE priority WHEN 'high' THEN 0 WHEN 'normal' THEN 1 ELSE 2 END ASC,
                      CASE WHEN due = '' THEN 1 ELSE 0 END ASC, due ASC, id DESC LIMIT " . $limit
        );
        $st->execute();
        return array_map(fn($r) => self::shapeTask($r, 0), $st->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /** One shaped task, visible to $uid (pool tasks are visible to everyone). */
    public static function oneTask(int $uid, int $taskId): ?array
    {
        self::ensureTasks();
        $st = Database::pdo()->prepare('SELECT * FROM collab_tasks WHERE id = ?');
        $st->execute([$taskId]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ? self::shapeTask($r, $uid) : null;
    }

    /** Turn a raw collab_tasks row into the shape the portal UI expects. */
    private static function shapeTask(array $r, int $uid): array
    {
        $assignee = (int) $r['assignee_id'];
        $creator  = (int) $r['creator_id'];
        $due = (string) $r['due'];
        $goalId = (int) ($r['goal_id'] ?? 0);
        $overdue = $due !== '' && !$r['done'] && $due < gmdate('Y-m-d');
        return [
            'id'       => (int) $r['id'],
            'title'    => (string) $r['title'],
            'done'     => (bool) $r['done'],
            'due'      => $due,
            'overdue'  => $overdue,
            'priority' => self::normPriority((string) ($r['priority'] ?? 'normal')),
            'open'     => $assignee === 0,                       // in the pool, unclaimed
            'mine'     => $assignee === $uid && $assignee !== 0,
            // Allocation context for the UI.
            'assignee_id'   => $assignee,
            'assignee_name' => $assignee === 0 ? 'Unclaimed' : ($assignee === $uid ? 'You' : self::nameOf($assignee)),
            'assigned_out'  => $creator === $uid && $assignee !== $uid && $assignee !== 0, // I gave this to someone
            'creator_id'    => $creator,
            'creator_name'  => $creator === $uid ? 'You' : self::nameOf($creator),
            'goal_id'       => $goalId,
            'goal_title'    => $goalId > 0 ? self::goalTitle($goalId) : '',
        ];
    }

    /** Title of a linked goal (cached per request). */
    private static function goalTitle(int $goalId): string
    {
        static $cache = [];
        if (isset($cache[$goalId])) return $cache[$goalId];
        try {
            $st = Database::pdo()->prepare('SELECT title FROM team_goals WHERE id = ?');
            $st->execute([$goalId]);
            return $cache[$goalId] = (string) ($st->fetchColumn() ?: '');
        } catch (Throwable $e) { return $cache[$goalId] = ''; }
    }

    /** Members who can be assigned a task (org members), for the allocation picker. */
    public static function roster(int $limit = 200): array
    {
        try {
            $domain = strtolower((string) (defined('AV_ORG_DOMAIN') ? AV_ORG_DOMAIN : 'afrovanguard.org.ng'));
            $pdo = Database::pdo();
            $st = $pdo->prepare("SELECT id, name, email FROM lms_users WHERE LOWER(email) LIKE ? ORDER BY name ASC LIMIT " . max(1, min(500, $limit)));
            $st->execute(['%@' . $domain]);
            $out = [];
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $out[] = ['id' => (int) $r['id'], 'name' => (string) ($r['name'] ?: explode('@', (string) $r['email'])[0])];
            }
            return $out;
        } catch (Throwable $e) { return []; }
    }

    private static function ownedTask(int $uid, int $taskId): ?array
    {
        $st = Database::pdo()->prepare('SELECT * FROM collab_tasks WHERE id = ? AND (assignee_id = ? OR creator_id = ?)');
        $st->execute([$taskId, $uid, $uid]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /* ────────────────────────── helpers ────────────────────────── */
    private static function nameOf(int $uid): string
    {
        if ($uid <= 0) return 'A member';
        try { $st = Database::pdo()->prepare('SELECT name FROM lms_users WHERE id = ?'); $st->execute([$uid]); return (string) ($st->fetchColumn() ?: 'A member'); }
        catch (Throwable $e) { return 'A member'; }
    }

    private static function initials(string $name, string $email): string
    {
        $name = trim($name);
        if ($name === '' && $email !== '') $name = explode('@', $email)[0];
        $p = preg_split('/\s+/', $name) ?: [];
        $s = strtoupper(substr((string) ($p[0] ?? ''), 0, 1) . substr((string) ($p[1] ?? ''), 0, 1));
        return $s !== '' ? $s : 'A';
    }

    private static function ago(int $sec): string
    {
        if ($sec < 45) return 'just now';
        if ($sec < 3600) return floor($sec / 60) . 'm ago';
        if ($sec < 86400) return floor($sec / 3600) . 'h ago';
        return floor($sec / 86400) . 'd ago';
    }
}
