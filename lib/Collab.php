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
        Events::on('workspace.connected', fn($p) => self::log((int) ($p['user_id'] ?? 0), self::nameOf((int) ($p['user_id'] ?? 0)), 'connected their Google Workspace', '', '/portal/'));
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
        /* Where a task is, not only whether it is done: started, blocked (and
           on what), finished — each with its time — and how big it is. A step
           that is "open" for three weeks and one begun yesterday looked the
           same; now one of them is flagged (self::flagOf). */
        foreach (['state' => "VARCHAR(8) NOT NULL DEFAULT ''", 'blocked_reason' => "VARCHAR(300) NOT NULL DEFAULT ''", 'size' => "VARCHAR(1) NOT NULL DEFAULT ''",
                  'started_at' => "VARCHAR(32) NOT NULL DEFAULT ''", 'state_at' => "VARCHAR(32) NOT NULL DEFAULT ''", 'done_at' => "VARCHAR(32) NOT NULL DEFAULT ''"] as $c => $decl) {
            try { if (!Database::columnExists('collab_tasks', $c)) $pdo->exec("ALTER TABLE collab_tasks ADD COLUMN $c $decl"); } catch (Throwable $e) {}
        }
        /* What makes a task one people finish (after CACENTRE's Goalspace):
             outcome       "done when" — what finished looks like, in a line
             first_due     the first date it was ever given
             agreed_due    the date giver and owner agreed; on time is measured
                           against this (TaskLedger::agreedDue), not a moved date
             accept_state  '' accepted (or never given to anyone) · 'pending'
                           a person gave it, waiting for a yes · 'proposed' the
                           planner suggested it · 'counter' the owner proposed
                           another date and the giver has not answered
             accepted_at   when they said yes (from assigned_at: how long it waited)
             proposed_due / proposed_why   the owner's counter-proposal
             review_state  after a given task is done: 'told' the giver was told,
                           'thanked', or 'sent_back' (review_note says why)
           Through execSchema, as db-portability.md asks, so a MySQL refusal is
           logged rather than swallowed. */
        foreach (['outcome' => "VARCHAR(300) NOT NULL DEFAULT ''", 'first_due' => "VARCHAR(10) NOT NULL DEFAULT ''", 'agreed_due' => "VARCHAR(10) NOT NULL DEFAULT ''",
                  'accept_state' => "VARCHAR(12) NOT NULL DEFAULT ''", 'accepted_at' => "VARCHAR(32) NOT NULL DEFAULT ''", 'proposed_due' => "VARCHAR(10) NOT NULL DEFAULT ''",
                  'proposed_why' => "VARCHAR(300) NOT NULL DEFAULT ''", 'review_state' => "VARCHAR(12) NOT NULL DEFAULT ''", 'review_note' => "VARCHAR(300) NOT NULL DEFAULT ''"] as $c => $decl) {
            if (!Database::columnExists('collab_tasks', $c)) Database::execSchema($pdo, "ALTER TABLE collab_tasks ADD COLUMN $c $decl");
        }
        /* TaskMatch adds these too; who gave a task is read on every list now (giverOf), so they are made here first. */
        foreach (['auto_assigned_at', 'assigned_at', 'assigned_by'] as $c) {
            if (!Database::columnExists('collab_tasks', $c)) Database::execSchema($pdo, "ALTER TABLE collab_tasks ADD COLUMN $c VARCHAR(32) NOT NULL DEFAULT ''");
        }
    }

    /** Accept states that are still waiting on somebody's answer. */
    public const WAITING = ['pending', 'proposed', 'counter'];

    /**
     * Who gave this task out: whoever assigned it, else whoever made it. The
     * person told when it is moved, declined, finished or late — one answer,
     * read the same way by every one of those.
     */
    public static function giverOf(array $r): int
    {
        return (int) (($r['assigned_by'] ?? '') !== '' ? $r['assigned_by'] : ($r['creator_id'] ?? 0));
    }

    /** The states a task moves through. Done is also kept in `done`, which everything else reads. */
    public const STATES = ['todo', 'doing', 'blocked', 'done'];

    /**
     * How much a step weighs toward its goal: S 1, M 2, L 4 (an unsized step
     * counts as M). Sizes double rather than add, because people estimate
     * relative size well and absolute effort badly — and a big step finished
     * should move a goal more than a small one.
     */
    public const POINTS = ['S' => 1, 'M' => 2, 'L' => 4];
    public static function points(string $size): int { return self::POINTS[strtoupper($size)] ?? 2; }
    private static function normSize(string $s): string { $s = strtoupper(trim($s)); return isset(self::POINTS[$s]) ? $s : ''; }

    /** Days after which work in a state is flagged: blocked is chased fastest. */
    public const STUCK_DAYS = ['blocked' => 2, 'doing' => 7, 'todo' => 14];

    /** Valid task priorities (low → normal → high). */
    private static function normPriority(string $p): string { $p = strtolower(trim($p)); return in_array($p, ['low','normal','high'], true) ? $p : 'normal'; }

    /**
     * May this person hand out work — give a task to somebody else?
     * Organisation members and volunteer coordinators. One rule for every
     * door that gives or creates a task for another person.
     */
    public static function mayAllocate(array $u): bool
    {
        return LmsAuth::isOrgMember($u) || (class_exists('Volunteers') && Volunteers::canRunTeams($u));
    }

    /** Why the last addTask()/assignTask() refused, in words for the person (empty when it did not). */
    public static string $lastError = '';

    /**
     * May $uid give a task to $assigneeId? '' when yes, else the reason, in
     * words for the person. ONE check, for a task given when it is made and
     * one given later: the somebody must be a real, active account, and
     * nobody is given new work while they are away (TaskLedger).
     *
     * WHO may give is mayAllocate(): addTask() refuses a task made for
     * somebody else by a person who may not hand out work, before this check
     * runs; a POOL task is handed out only by whoever hands out work
     * (assignTask). $mayAllocate is accepted for that reason and not used here.
     *
     * Made for somebody, a task used to skip even this: it could be given to
     * an account that did not exist or was suspended — a task nobody could
     * see but its creator — or to someone who had said they were away.
     */
    public static function canGiveTo(int $uid, int $assigneeId, bool $mayAllocate): string
    {
        if ($assigneeId <= 0 || $assigneeId === $uid) return '';
        $st = Database::pdo()->prepare("SELECT name FROM lms_users WHERE id = ? AND status = 'active'");
        $st->execute([$assigneeId]);
        $name = $st->fetchColumn();
        if ($name === false) return 'That person is not an active member any more. Choose someone else.';
        if (class_exists('TaskLedger')) {
            $away = TaskLedger::awayUntil($assigneeId);
            if ($away !== '' && $away >= gmdate('Y-m-d')) return trim((string) $name) . ' is away until ' . date('j M', strtotime($away)) . ' and is not taking new tasks. Choose someone else, or post it to the pool.';
        }
        return '';
    }

    /** A "done when" line: one line, trimmed, at most 300 characters. */
    private static function normOutcome(string $o): string
    {
        return trim(mb_substr(trim((string) preg_replace('/\s+/u', ' ', $o)), 0, 300));
    }

    /**
     * Tell somebody they have been given a task — as a request to answer, not
     * a fait accompli: accept, propose a date, or say why they can't. A
     * planner's suggestion says it is one.
     */
    private static function tellGiven(int $taskId, int $to, int $from, string $title, string $due, string $accept, string $outcome, string $key, bool $email, string $why = ''): void
    {
        try {
            $by = self::nameOf($from);
            $ask = 'Accept it, propose another date, or say why you can’t.';
            if ($accept === 'proposed') {
                $head = 'Suggested for you: ' . $title;
                $body = ($why !== '' ? $why . ' ' : 'The planner thinks this fits you. ') . $ask;
            } else {
                $head = 'New task: ' . $title;
                $body = ($why !== '' ? $why : $by . ' asks you to take this on') . ($due !== '' ? ' (by ' . $due . ')' : '') . '. ' . $ask;
            }
            if ($outcome !== '') $body .= ' Done when: ' . $outcome;
            Notifications::push($to, 'task', $head, $body, '/portal/#tasks', $key);
            /* The same email a task given at creation sends: given later, it used to arrive as an app notice only. */
            if ($email) Notifications::email($to, $accept === 'proposed' ? $head : 'You’ve been asked to take on a task: ' . $title,
                $by . ' gave you a task' . ($due !== '' ? ' (due ' . $due . ')' : '') . '. ' . $ask . ($outcome !== '' ? ' Done when: ' . $outcome : ''), '/portal/#tasks');
        } catch (Throwable $e) { error_log('[collab] notify: ' . $e->getMessage()); }
    }

    /** One notice, never fatal: a lost notice is not worth a failed click. */
    private static function tell(int $to, string $kind, string $title, string $body, string $key = '', bool $email = false): void
    {
        if ($to <= 0 || !class_exists('Notifications')) return;
        try {
            Notifications::push($to, $kind, $title, $body, '/portal/#tasks', $key);
            if ($email) Notifications::email($to, $title, $body, '/portal/#tasks');
        } catch (Throwable $e) { error_log('[collab] notify: ' . $e->getMessage()); }
    }

    /* ── Accept · propose a date · can't, because… ──────────────────────
       After Goalspace: work given to somebody is a request until they say
       yes. Before this, a task given was simply theirs — counted in their
       list, chased by the cron — whether or not they had seen it, agreed to
       the date, or could do it at all; the first the giver heard of a "no"
       was the date going by. */

    /** The task, if $uid is who it was given to and it still waits on them. */
    private static function waitingOn(int $uid, int $taskId): ?array
    {
        self::ensureTasks();
        $st = Database::pdo()->prepare('SELECT * FROM collab_tasks WHERE id = ? AND assignee_id = ? AND done = 0');
        $st->execute([$taskId, $uid]);
        $t = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        return $t && in_array((string) $t['accept_state'], self::WAITING, true) ? $t : null;
    }

    /** Yes: it is theirs, by the date it carries. Returns the shaped task or null. */
    public static function acceptTask(int $uid, int $taskId): ?array
    {
        self::$lastError = '';
        $t = self::waitingOn($uid, $taskId);
        if (!$t || (string) $t['accept_state'] === 'counter') { self::$lastError = 'There is nothing of yours to accept here.'; return null; }
        $up = Database::pdo()->prepare("UPDATE collab_tasks SET accept_state = '', accepted_at = ?, agreed_due = CASE WHEN agreed_due = '' THEN due ELSE agreed_due END, first_due = CASE WHEN first_due = '' THEN due ELSE first_due END WHERE id = ? AND accept_state = ?");
        $up->execute([gmdate('Y-m-d H:i:s'), $taskId, (string) $t['accept_state']]);
        if ($up->rowCount() === 0) return self::oneTask($uid, $taskId);
        if (class_exists('TaskLedger')) TaskLedger::record($taskId, $uid, 'accepted', (string) $t['title']);
        $g = self::giverOf($t);
        if ($g !== $uid) self::tell($g, 'task', 'Accepted: ' . $t['title'], self::nameOf($uid) . ' has taken it on' . ((string) $t['due'] !== '' ? ', by ' . $t['due'] : '') . '.', 'task-accepted:' . $taskId . ':' . $uid);
        return self::oneTask($uid, $taskId);
    }

    /** "I can, by this date": the giver is asked to agree. */
    public static function proposeDate(int $uid, int $taskId, string $date, string $why = ''): ?array
    {
        self::$lastError = '';
        $date = trim($date);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !checkdate((int) substr($date, 5, 2), (int) substr($date, 8, 2), (int) substr($date, 0, 4))) { self::$lastError = 'Pick the date you can do it by.'; return null; }
        $t = self::waitingOn($uid, $taskId);
        if (!$t) { self::$lastError = 'There is nothing of yours to answer here.'; return null; }
        $why = self::normOutcome($why);
        Database::pdo()->prepare("UPDATE collab_tasks SET accept_state = 'counter', proposed_due = ?, proposed_why = ? WHERE id = ?")->execute([$date, $why, $taskId]);
        self::tell(self::giverOf($t), 'task', 'New date asked: ' . $t['title'],
            self::nameOf($uid) . ' can do it by ' . $date . ((string) $t['due'] !== '' ? ' (you asked for ' . $t['due'] . ')' : '') . ($why !== '' ? ': ' . $why : '') . '. Agree, or keep your date.',
            'task-counter:' . $taskId . ':' . $date, true);
        return self::oneTask($uid, $taskId);
    }

    /** The giver answers a proposed date: agree (it is the new agreed date, and the task is accepted) or keep theirs. */
    public static function answerDate(int $uid, int $taskId, bool $agree): ?array
    {
        self::$lastError = '';
        self::ensureTasks();
        $st = Database::pdo()->prepare("SELECT * FROM collab_tasks WHERE id = ? AND done = 0 AND accept_state = 'counter'");
        $st->execute([$taskId]);
        $t = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$t || self::giverOf($t) !== $uid) { self::$lastError = 'There is no date waiting on you here.'; return null; }
        $owner = (int) $t['assignee_id']; $prop = (string) $t['proposed_due'];
        if ($agree) {
            Database::pdo()->prepare("UPDATE collab_tasks SET due = ?, agreed_due = ?, accept_state = '', accepted_at = ?, proposed_due = '', proposed_why = '', first_due = CASE WHEN first_due = '' THEN ? ELSE first_due END WHERE id = ?")
                ->execute([$prop, $prop, gmdate('Y-m-d H:i:s'), $prop, $taskId]);
            if (class_exists('TaskLedger')) { TaskLedger::logDueChange($taskId, $uid, (string) $t['due'], $prop, 'Agreed with ' . self::nameOf($owner)); TaskLedger::record($taskId, $owner, 'accepted', (string) $t['title']); }
            self::tell($owner, 'task', 'Date agreed: ' . $t['title'], self::nameOf($uid) . ' agreed: ' . $prop . '. It is yours.', 'task-agreed:' . $taskId . ':' . $prop);
        } else {
            Database::pdo()->prepare("UPDATE collab_tasks SET accept_state = 'pending', proposed_due = '', proposed_why = '' WHERE id = ?")->execute([$taskId]);
            self::tell($owner, 'task', 'Date kept: ' . $t['title'], self::nameOf($uid) . ' needs it by ' . ((string) $t['due'] !== '' ? $t['due'] : 'the date asked') . '. Accept it, or say why you can’t.', 'task-kept:' . $taskId . ':' . $prop);
        }
        return self::oneTask($uid, $taskId);
    }

    /**
     * "Can't, because…" — with a reason from TaskLedger::REASONS, the same list
     * a hand-back uses. A person's request goes back to the person who gave
     * it, who is told why (and can give it to somebody else); a planner's
     * suggestion goes back to the pool for somebody who fits better.
     */
    public static function declineTask(int $uid, int $taskId, string $reason, string $note = ''): ?array
    {
        self::$lastError = '';
        if (!class_exists('TaskLedger') || !isset(TaskLedger::REASONS[$reason])) { self::$lastError = 'Say why you can’t take it on.'; return null; }
        $t = self::waitingOn($uid, $taskId);
        if (!$t) { self::$lastError = 'There is nothing of yours to answer here.'; return null; }
        $note = self::normOutcome($note);
        $g = self::giverOf($t);
        $toPool = (string) $t['accept_state'] === 'proposed' || $g <= 0 || $g === $uid;
        $now = gmdate('Y-m-d H:i:s');
        if ($toPool) Database::pdo()->prepare("UPDATE collab_tasks SET assignee_id = 0, accept_state = '', proposed_due = '', proposed_why = '' WHERE id = ?")->execute([$taskId]);
        else Database::pdo()->prepare("UPDATE collab_tasks SET assignee_id = ?, accept_state = '', accepted_at = ?, assigned_at = ?, assigned_by = ?, proposed_due = '', proposed_why = '' WHERE id = ?")->execute([$g, $now, $now, (string) $g, $taskId]);
        TaskLedger::record($taskId, $uid, 'declined', (string) $t['title'], $reason);
        $why = TaskLedger::REASONS[$reason] . ($note !== '' ? ' — ' . $note : '');
        if ($g > 0 && $g !== $uid) self::tell($g, 'task', 'Can’t take it on: ' . $t['title'], self::nameOf($uid) . ': ' . $why . '. ' . ($toPool ? 'It is back in the pool.' : 'It is back with you — give it to someone else, or change it.'), 'task-declined:' . $taskId . ':' . $uid, true);
        self::log($uid, self::nameOf($uid), 'could not take on a task', (string) $t['title'], '/portal/#tasks');
        return self::oneTask($uid, $taskId) ?? [];
    }

    /** The giver, after a task they gave was finished: thanks. */
    public static function thankTask(int $uid, int $taskId): ?array
    {
        $t = self::reviewable($uid, $taskId);
        if (!$t) return null;
        Database::pdo()->prepare("UPDATE collab_tasks SET review_state = 'thanked' WHERE id = ?")->execute([$taskId]);
        self::tell((int) $t['assignee_id'], 'task', self::nameOf($uid) . ' says thanks', 'For: ' . $t['title'] . '.', 'task-thanks:' . $taskId);
        return self::oneTask($uid, $taskId);
    }

    /** The giver, after a task they gave was finished: not done yet, and why. It is open again. */
    public static function sendBack(int $uid, int $taskId, string $reason): ?array
    {
        self::$lastError = '';
        $reason = self::normOutcome($reason);
        if ($reason === '') { self::$lastError = 'Say what is missing, in a line.'; return null; }
        $t = self::reviewable($uid, $taskId);
        if (!$t) return null;
        Database::pdo()->prepare("UPDATE collab_tasks SET done = 0, state = 'doing', state_at = ?, done_at = '', review_state = 'sent_back', review_note = ? WHERE id = ? AND done = 1")
            ->execute([gmdate('Y-m-d H:i:s'), $reason, $taskId]);
        if (class_exists('TaskLedger')) TaskLedger::record($taskId, (int) $t['assignee_id'], 'sent_back', (string) $t['title']);
        self::tell((int) $t['assignee_id'], 'task', 'Sent back: ' . $t['title'], self::nameOf($uid) . ' says: ' . $reason, 'task-back:' . $taskId . ':' . substr(sha1($reason), 0, 8), true);
        if ((int) ($t['goal_id'] ?? 0) > 0 && class_exists('GoalPlanner')) GoalPlanner::syncProgress((int) $t['goal_id']);
        return self::oneTask($uid, $taskId);
    }

    /** A finished task $uid gave to somebody else. */
    private static function reviewable(int $uid, int $taskId): ?array
    {
        self::ensureTasks();
        $st = Database::pdo()->prepare('SELECT * FROM collab_tasks WHERE id = ? AND done = 1');
        $st->execute([$taskId]);
        $t = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        return $t && self::giverOf($t) === $uid && (int) $t['assignee_id'] !== $uid && (int) $t['assignee_id'] > 0 ? $t : null;
    }

    /**
     * What the escalation ladder (TaskLedger::chaseAll) runs over: every open
     * task somebody holds that has a date. The ladder reads the date they are
     * working to; whether it was on time is the agreed date's question.
     */
    public static function ladderItems(): array
    {
        self::ensureTasks();
        $out = [];
        foreach (Database::pdo()->query("SELECT * FROM collab_tasks WHERE done = 0 AND assignee_id > 0 AND due <> ''")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[] = ['kind' => 'task', 'id' => (int) $r['id'], 'title' => (string) $r['title'], 'due' => (string) $r['due'], 'owner' => (int) $r['assignee_id'],
                      'owner_name' => self::nameOf((int) $r['assignee_id']), 'giver' => self::giverOf($r), 'url' => '/portal/#tasks', 'giver_url' => '/portal/#tasks'];
        }
        return $out;
    }

    /**
     * Create a task. Assignee defaults to the creator. Returns the new id or 0.
     *
     * Creating one for somebody else is handing out work, and asks what
     * assignTask() asks: the right to ($mayAllocate) and an active account at
     * the other end. Without it any signed-in learner could put a notification
     * and an email of their own wording in front of any user id they typed.
     */
    public static function addTask(int $creatorId, string $title, int $assigneeId = 0, string $due = '', string $priority = 'normal', int $goalId = 0, string $size = '', string $via = 'person', ?bool $mayAllocate = false, string $outcome = ''): int
    {
        self::$lastError = '';
        $title = trim(mb_substr(trim($title), 0, 300));
        if ($creatorId <= 0 || $title === '') { self::$lastError = 'Enter a task.'; return 0; }
        self::ensureTasks();
        $assignee = $assigneeId > 0 ? $assigneeId : $creatorId;
        /* The person-facing routes pass a bool (and get the away check); the
           callers with rules of their own pass null explicitly. The default
           is false: a new caller that forgets to say gets no right to hand
           out work.
           Making a task for somebody else is handing out work: without the
           right to (mayAllocate) any signed-in learner could put a
           notification and an email of their own wording in front of any
           user id they typed. */
        if ($mayAllocate !== null && ($why = self::canGiveTo($creatorId, $assignee, $mayAllocate)) !== '') { self::$lastError = $why; return 0; }
        if ($mayAllocate === false && $assignee !== $creatorId) { self::$lastError = 'You cannot give a task to somebody else.'; return 0; }
        /* The planner and the goal mirror (no $mayAllocate) still never give work to an account that is gone. */
        if ($mayAllocate === null && $assignee !== $creatorId && str_contains(self::canGiveTo($creatorId, $assignee, true), 'not an active member')) { self::$lastError = 'That person is not an active member any more. Choose someone else.'; return 0; }
        $due = preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($due)) ? trim($due) : '';
        $now = gmdate('Y-m-d H:i:s');
        /* Given to somebody else: say who gave it and when, as a later assignment does. */
        $delegated = $assignee !== $creatorId;
        if (class_exists('TaskMatch')) TaskMatch::ensure();
        /* Given to somebody, it waits for their yes (accept_state): a person's
           request is 'pending', the planner's or the sweep's suggestion is
           'proposed' — a machine's guess is not an assignment until somebody
           accepts it. Made for yourself, it is accepted as it is made. */
        $accept = !$delegated ? '' : (in_array($via, ['ai', 'auto'], true) ? 'proposed' : 'pending');
        $outcome = self::normOutcome($outcome);
        Database::pdo()->prepare("INSERT INTO collab_tasks (creator_id, assignee_id, title, done, due, priority, goal_id, size, state, created_at, assigned_at, assigned_by, outcome, first_due, agreed_due, accept_state, accepted_at) VALUES (?,?,?,0,?,?,?,?,'todo',?,?,?,?,?,?,?,?)")
            ->execute([$creatorId, $assignee, $title, $due, self::normPriority($priority), max(0, $goalId), self::normSize($size), $now, $delegated ? $now : '', $delegated ? (string) $creatorId : '',
                       $outcome, $due, $due, $accept, $delegated ? '' : $now]);
        $newId = (int) Database::pdo()->lastInsertId();
        if ($delegated) self::log($creatorId, self::nameOf($creatorId), 'gave a task to ' . self::nameOf($assignee), $title, '/portal/#tasks');
        // Notify + email the assignee when a task is delegated to them (not self-assigned).
        if ($assignee !== $creatorId && class_exists('Notifications')) self::tellGiven($newId, $assignee, $creatorId, $title, $due, $accept, $outcome, 'task:' . $newId, true);
        /* Given to somebody else: kept, so what they do with it teaches the next assignment (TaskLedger). */
        if ($newId > 0 && $assignee !== $creatorId && class_exists('TaskLedger')) TaskLedger::record($newId, $assignee, 'assigned', $title, '', $via);
        return $newId;
    }

    /**
     * Post a task to the shared org pool — unassigned (assignee_id = 0), so any
     * member can claim it. Returns the new id or 0. Logs to the team activity
     * feed so the pool feels alive.
     */
    public static function addPoolTask(int $creatorId, string $title, string $due = '', string $priority = 'normal', int $goalId = 0, string $size = ''): int
    {
        $title = trim(mb_substr(trim($title), 0, 300));
        if ($creatorId <= 0 || $title === '') return 0;
        self::ensureTasks();
        $due = preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($due)) ? trim($due) : '';
        Database::pdo()->prepare("INSERT INTO collab_tasks (creator_id, assignee_id, title, done, due, priority, goal_id, size, state, created_at) VALUES (?,0,?,0,?,?,?,?,'todo',?)")
            ->execute([$creatorId, $title, $due, self::normPriority($priority), max(0, $goalId), self::normSize($size), gmdate('Y-m-d H:i:s')]);
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
        if (class_exists('TaskMatch')) TaskMatch::ensure();
        /* Taken by themselves: not "assigned to you" in the to-do pop-up. */
        /* Taken by themselves, so accepted as it is taken; its date as it stands is the one they took on. */
        $now = gmdate('Y-m-d H:i:s');
        $st = Database::pdo()->prepare("UPDATE collab_tasks SET assignee_id = ?, assigned_at = ?, assigned_by = ?, accept_state = '', accepted_at = ?, proposed_due = '', agreed_due = due, first_due = CASE WHEN first_due = '' THEN due ELSE first_due END WHERE id = ? AND assignee_id = 0 AND done = 0");
        $st->execute([$uid, $now, (string) $uid, $now, $taskId]);
        if ($st->rowCount() === 0) return null;   // already claimed / done / gone
        $task = self::oneTask($uid, $taskId);
        if ($task) self::log($uid, self::nameOf($uid), 'claimed a task', $task['title'], '/portal/#tasks');
        return $task;
    }

    /**
     * Release a task back to the pool (assignee_id = 0). Allowed for the current
     * assignee or the creator. Returns true if it went back.
     */
    public static function releaseTask(int $uid, int $taskId, string $reason = ''): bool
    {
        self::ensureTasks();
        if ($uid <= 0 || $taskId <= 0) return false;
        $q = Database::pdo()->prepare('SELECT assignee_id, title FROM collab_tasks WHERE id = ?');
        $q->execute([$taskId]);
        $was = $q->fetch(PDO::FETCH_ASSOC) ?: null;
        $st = Database::pdo()->prepare("UPDATE collab_tasks SET assignee_id = 0, accept_state = '', proposed_due = '' WHERE id = ? AND done = 0 AND (assignee_id = ? OR creator_id = ?)");
        $st->execute([$taskId, $uid, $uid]);
        if ($st->rowCount() === 0) return false;
        /* Handed back by the person holding it, and why (TaskLedger::REASONS):
           "not my kind of work" keeps the like of it from them next time;
           "no time" only for a while. Taken back by its creator, it is not theirs to explain. */
        if ($was && (int) $was['assignee_id'] === $uid && class_exists('TaskLedger')) TaskLedger::record($taskId, $uid, 'handed_back', (string) $was['title'], $reason);
        return true;
    }

    /**
     * Give a task to somebody: the person who created it may, and anybody who
     * may run teams may hand out a task from the pool. A finished task stays
     * where it is. The new assignee is told.
     */
    public static function assignTask(int $uid, int $taskId, int $assigneeId, bool $mayAllocate, string $why = '', string $via = 'person'): ?array
    {
        self::$lastError = '';
        self::ensureTasks();
        if ($uid <= 0 || $taskId <= 0 || $assigneeId <= 0) { self::$lastError = 'Choose who to give it to.'; return null; }
        $st = Database::pdo()->prepare('SELECT * FROM collab_tasks WHERE id = ? AND done = 0');
        $st->execute([$taskId]);
        $t = $st->fetch(PDO::FETCH_ASSOC);
        if (!$t) { self::$lastError = 'That task is finished or no longer exists.'; return null; }
        /* Its maker, or whoever gave it out (the giver, offered "reassign" when it is three days late), or — from the pool — anybody who hands out work. */
        if ((int) $t['creator_id'] !== $uid && self::giverOf($t) !== $uid && !((int) $t['assignee_id'] === 0 && $mayAllocate)) { self::$lastError = 'Only whoever made this task can give it to someone.'; return null; }
        $u = Database::pdo()->prepare("SELECT 1 FROM lms_users WHERE id = ? AND status = 'active'");
        $u->execute([$assigneeId]);
        if (!$u->fetchColumn()) { self::$lastError = 'That person is not an active member any more. Choose someone else.'; return null; }
        if (($no = self::canGiveTo($uid, $assigneeId, $mayAllocate)) !== '') { self::$lastError = $no; return null; }
        if (class_exists('TaskMatch')) TaskMatch::ensure();
        /* The sweep (TaskMatch::autoAssign) assigns as the task's creator, and
           the creator may take a task back from whoever holds it — but the
           sweep only ever means a task nobody took. A member who claimed it
           while the sweep was scoring keeps it: the UPDATE says so, and when
           it changes nothing there is no notice and no ledger row. */
        $auto = $via === 'auto';
        /* Given to somebody else, it waits for their yes, as a task given when
           it is made does; its date as it stands is what they are asked to agree. */
        $now = gmdate('Y-m-d H:i:s');
        $accept = $assigneeId === $uid ? '' : (in_array($via, ['ai', 'auto'], true) ? 'proposed' : 'pending');
        $up = Database::pdo()->prepare("UPDATE collab_tasks SET assignee_id = ?, assigned_at = ?, assigned_by = ?, accept_state = ?, accepted_at = ?, proposed_due = '', agreed_due = due, first_due = CASE WHEN first_due = '' THEN due ELSE first_due END WHERE id = ?" . ($auto ? ' AND assignee_id = 0 AND done = 0' : ''));
        $up->execute([$assigneeId, $now, (string) $uid, $accept, $accept === '' ? $now : '', $taskId]);
        if ($auto && $up->rowCount() === 0) return null;
        if ($assigneeId !== $uid && class_exists('Notifications')) self::tellGiven($taskId, $assigneeId, $uid, (string) $t['title'], (string) $t['due'], $accept, (string) ($t['outcome'] ?? ''), 'task:' . $taskId . ':' . $assigneeId, $via === 'person', $why);
        self::log($uid, self::nameOf($uid), 'assigned a task to ' . self::nameOf($assigneeId), (string) $t['title'], '/portal/#tasks');
        if (class_exists('TaskLedger')) TaskLedger::record($taskId, $assigneeId, 'assigned', (string) $t['title'], '', $via);
        return self::oneTask($uid, $taskId);
    }

    /* ────────────────────── AI: goal → tasks ─────────────────────── */

    /** True when ANY AI backend is wired up — Claude (AvBot) or Gemini. */
    public static function aiAvailable(): bool
    {
        // The Studio master switch wins over a configured key: leadership can
        // stop every AI call without pulling credentials out of the environment.
        if (class_exists('AvRules') && !AvRules::bool('ai.enabled')) return false;
        return class_exists('AvRouter') && AvRouter::available();
    }

    /**
     * One text completion, routed by AvRouter. Same ['ok','text','error'] shape
     * as before plus 'via', which now names whichever provider actually
     * answered rather than the one this method happened to try first.
     */
    private static function aiComplete(string $system, string $prompt, int $maxTokens = 900): array
    {
        $r = class_exists('AvAgent')
            ? AvAgent::complete($system, $prompt, ['max_tokens' => $maxTokens, 'temperature' => 0.3, 'actor' => 'collab.tasks'])
            : ['ok' => false, 'error' => 'Router unavailable.'];
        return ['ok' => (bool) ($r['ok'] ?? false), 'text' => (string) ($r['text'] ?? ''),
                'error' => $r['error'] ?? null, 'via' => (string) ($r['provider'] ?? '')];
    }

    /**
     * Ask the AI to break a goal into concrete, actionable tasks and drop them
     * into the shared pool (linked to the goal). Returns
     * ['ok'=>bool, 'created'=>[…shaped tasks…], 'error'=>?string, 'suggested'=>N].
     *
     * The tasks are posted UNCLAIMED so the team can divide the work — exactly
     * the "AI creates tasks from goals, anyone can take them up" flow.
     */
    public static function aiTasksFromGoal(int $uid, int $goalId, int $max = 6, bool $autoAssign = false): array
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
            . (class_exists('TaskMatch') && ($shape = TaskMatch::teamShape()) !== '' ? "\n\nWHO IS AVAILABLE: " . $shape . '.' : '')
            . (class_exists('TaskMatch') ? "\nAlso give each task a \"kind\" — one of: " . implode(', ', TaskMatch::kindNames()) . ' — so it can go to the right person.' : '')
            . "\nAnd a \"size\": S (an hour or two), M (a day or so) or L (several days). Prefer steps that move the goal's number over steps that only prepare for it."
            . (class_exists('GoalTrack') ? GoalTrack::planningContext($goalId) : '')
            . "\n\nBreak this goal into tasks now.";

        $res = self::aiComplete($system, $prompt, 900);
        if (empty($res['ok'])) return ['ok' => false, 'created' => [], 'error' => (string) ($res['error'] ?? 'AI request failed.')];
        $via = (string) ($res['via'] ?? '');

        $items = self::parseAiTasks((string) $res['text']);
        if (!$items) return ['ok' => false, 'created' => [], 'error' => 'The AI did not return usable tasks. Try again.'];

        $plan = [];
        foreach (array_slice($items, 0, $max) as $it) {
            $title = trim(mb_substr((string) ($it['title'] ?? ''), 0, 300));
            if ($title === '') continue;
            $kind = strtolower(trim((string) ($it['kind'] ?? '')));
            $days = (int) ($it['days'] ?? 0);
            /* What the matcher weighs besides the words: when it is due, how big, for which goal. */
            $plan[] = ['title' => $title, 'kind' => class_exists('TaskMatch') && in_array($kind, TaskMatch::kindNames(), true) ? $kind : '', 'it' => $it,
                       'due' => ($days > 0 && $days <= 400) ? gmdate('Y-m-d', strtotime($today . ' +' . $days . ' days') ?: time()) : '',
                       'size' => strtoupper(trim((string) ($it['size'] ?? ''))), 'priority' => self::normPriority((string) ($it['priority'] ?? 'normal')), 'goal' => $goal['title']];
        }
        /* Who each task fits — by AI when configured, reading their roles (several
           each) and what they say they do — spread so one person is not handed
           the lot. A step nobody fits stays in the pool. Suggested on every
           task; assigned only when asked. */
        $about = '';
        if (class_exists('GoalTrack') && ($row = GoalTrack::row($goalId))) {
            $ctx = GoalTrack::contextOf($row);
            $about = implode("\n", array_filter([$goal['title'], $ctx['audience'] !== '' ? 'Who it serves: ' . $ctx['audience'] : '',
                $ctx['skills'] !== '' ? 'Skills needed: ' . $ctx['skills'] : '', $ctx['resources'] !== '' ? 'Resources: ' . $ctx['resources'] : '']));
        }
        $fits = class_exists('TaskMatch') ? TaskMatch::assignMany($plan, [], $about) : [];
        $created = []; $assigned = 0;
        foreach ($plan as $k => $pl) {
            $due = $pl['due']; $pri = $pl['priority']; $size = $pl['size'];
            $fit = $fits[$k] ?? null;
            if ($autoAssign && $fit) { $id = self::addTask($uid, $pl['title'], (int) $fit['id'], $due, $pri, $goalId, $size, 'ai', true);   /* $autoAssign: the caller may allocate */ $assigned++; }
            else $id = self::addPoolTask($uid, $pl['title'], $due, $pri, $goalId, $size);
            if ($id > 0 && ($t = self::oneTask($uid, $id))) {
                if ($fit) $t['suggest'] = ['id' => (int) $fit['id'], 'name' => (string) $fit['name'], 'why' => (string) ($fit['why'][0] ?? $fit['summary'])];
                $t['kind'] = $pl['kind'];
                $created[] = $t;
            }
        }
        if (!$created) return ['ok' => false, 'created' => [], 'error' => 'Could not create tasks.'];
        return ['ok' => true, 'created' => $created, 'error' => null, 'suggested' => count($items), 'assigned' => $assigned, 'via' => $via];
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
        return self::setDone($uid, $taskId, null);
    }

    /**
     * Mark done, idempotently — what CACENTRE means by its tick. Already done
     * answers true and does nothing again: no second activity line, no second
     * ledger row, no call back to CACENTRE. A toggle cannot be retried safely
     * (the retry un-does the work it confirms); this can.
     */
    public static function completeTask(int $uid, int $taskId): ?bool
    {
        return self::setDone($uid, $taskId, true);
    }

    /**
     * Set done or not done, idempotently — what the portal's tick sends, so a
     * tick retried after a dropped connection (portal/tasks.js tickQueue)
     * lands where the person left it instead of flipping it back.
     */
    public static function setTaskDone(int $uid, int $taskId, bool $done): ?bool
    {
        return self::setDone($uid, $taskId, $done);
    }

    /** $to null flips; true marks done only if it is not, by compare-and-set,
     *  so two "done"s racing each other cannot flip it back. */
    private static function setDone(int $uid, int $taskId, ?bool $to): ?bool
    {
        self::ensureTasks();
        $t = self::ownedTask($uid, $taskId);
        if (!$t) return null;
        if ($to !== null && (bool) $t['done'] === $to) return $to;
        $new = $t['done'] ? 0 : 1;
        $now = gmdate('Y-m-d H:i:s');
        /* Ticked: done, and when — the finish times are what a goal's
           forecast is drawn from (GoalTrack::forecast). Put back: where it was
           before, or to do. */
        $up = Database::pdo()->prepare('UPDATE collab_tasks SET done = ?, state = ?, state_at = ?, done_at = ?, blocked_reason = ? WHERE id = ?' . ($to !== null ? ' AND done = ?' : ''));
        $up->execute(array_merge([$new, $new ? 'done' : ((string) ($t['started_at'] ?? '') !== '' ? 'doing' : 'todo'), $now, $new ? $now : '', '', $taskId], $to !== null ? [(int) $t['done']] : []));
        if ($to !== null && $up->rowCount() === 0) return $to;   /* another request finished it first */
        if ($new === 1) self::log($uid, self::nameOf($uid), 'completed a task', (string) $t['title'], '/portal/#tasks');
        if ($new === 1) {
            /* Finished while it still waited for a yes: doing it is the yes. */
            if (in_array((string) ($t['accept_state'] ?? ''), self::WAITING, true)) {
                Database::pdo()->prepare("UPDATE collab_tasks SET accept_state = '', accepted_at = ?, proposed_due = '' WHERE id = ?")->execute([$now, $taskId]);
            }
            /* Done is told: whoever gave it hears, and can say thanks or send it
               back. Without this the giver found out by looking — or did not,
               and asked again a week later about work long finished. */
            $g = self::giverOf($t); $owner = (int) ($t['assignee_id'] ?? 0);
            if ($owner > 0 && $g > 0 && $g !== $owner && $owner === $uid) {
                Database::pdo()->prepare("UPDATE collab_tasks SET review_state = 'told', review_note = '' WHERE id = ?")->execute([$taskId]);
                self::tell($g, 'task', 'Done: ' . $t['title'], self::nameOf($owner) . ' has finished it' . ((string) ($t['outcome'] ?? '') !== '' ? ' (done when: ' . $t['outcome'] . ')' : '') . '. Say thanks, or send it back with what is missing.',
                    'task-done:' . $taskId . ':' . $now);
            }
        }
        if ($new === 1 && class_exists('TaskLedger')) TaskLedger::record($taskId, (int) (($t['assignee_id'] ?? 0) ?: $uid), 'done', (string) $t['title']);
        /* A step towards a goal: the goal's progress follows. */
        if ((int) ($t['goal_id'] ?? 0) > 0 && class_exists('GoalPlanner')) GoalPlanner::syncProgress((int) $t['goal_id']);
        /* Counted toward a CACENTRE commitment: the tick counts there too. */
        if ((int) ($t['cac_commitment_id'] ?? 0) > 0) self::cacChanged($taskId);
        return (bool) $new;
    }

    /* CACENTRE's tick arrives over integrations/cacentre-tasks.php with a
       3-second timeout, and telling CACENTRE's commitment about it is a
       second round trip to the same site. Held, the door answers first and
       sends it after (holdCac/releaseCac); not held, it is sent at once. */
    private static ?array $cacHeld = null;
    public static function holdCac(): void { self::$cacHeld = []; }
    public static function releaseCac(): void
    {
        $q = self::$cacHeld ?? []; self::$cacHeld = null;
        foreach ($q as $id => $deleted) if (class_exists('CacWeek')) CacWeek::changed((int) $id, $deleted);
    }
    private static function cacChanged(int $taskId, bool $deleted = false): void
    {
        if (self::$cacHeld !== null) { self::$cacHeld[$taskId] = $deleted; return; }
        if (class_exists('CacWeek')) CacWeek::changed($taskId, $deleted);
    }

    /**
     * Move a task: to do, doing, blocked (saying on what), done. Done goes
     * through toggleTask, so a goal's progress and a CACENTRE commitment
     * follow as they always have. A block tells whoever gave the task out,
     * because a block nobody hears about is just a slower failure.
     * Returns the shaped task, or ['error'=>…].
     */
    public static function setState(int $uid, int $taskId, string $state, string $reason = ''): array
    {
        self::ensureTasks();
        $t = self::ownedTask($uid, $taskId);
        if (!$t) return ['error' => 'Task not found.'];
        $state = strtolower(trim($state));
        if (!in_array($state, self::STATES, true)) return ['error' => 'A task is to do, doing, blocked or done.'];
        $reason = trim(mb_substr(preg_replace('/\s+/u', ' ', $reason) ?? '', 0, 300));
        if ($state === 'blocked' && $reason === '') return ['error' => 'What is it blocked on? Whoever can unblock it needs to know.'];
        if (($state === 'done') !== (bool) $t['done']) {
            self::toggleTask($uid, $taskId);
            if ($state === 'done') return self::oneTask($uid, $taskId) ?? ['error' => 'Task not found.'];
        }
        $now = gmdate('Y-m-d H:i:s');
        Database::pdo()->prepare('UPDATE collab_tasks SET state = ?, state_at = ?, blocked_reason = ?, started_at = CASE WHEN started_at = \'\' AND ? = \'doing\' THEN ? ELSE started_at END WHERE id = ?')
            ->execute([$state, $now, $state === 'blocked' ? $reason : '', $state, $now, $taskId]);
        if ($state === 'blocked') {
            $to = (int) ($t['assigned_by'] ?? 0) ?: (int) $t['creator_id'];
            if ($to > 0 && $to !== $uid && class_exists('Notifications')) {
                try { Notifications::push($to, 'task', 'Blocked: ' . $t['title'], self::nameOf($uid) . ' is blocked on: ' . $reason, '/portal/#tasks', 'task-blocked:' . $taskId . ':' . substr(sha1($reason), 0, 8)); }
                catch (Throwable $e) { error_log('[collab] notify: ' . $e->getMessage()); }
            }
            self::log($uid, self::nameOf($uid), 'is blocked on a task', (string) $t['title'], '/portal/#tasks');
        }
        return self::oneTask($uid, $taskId) ?? ['error' => 'Task not found.'];
    }

    /** Size a task: S, M or L ('' to clear). */
    public static function setSize(int $uid, int $taskId, string $size): ?array
    {
        self::ensureTasks();
        if (!self::ownedTask($uid, $taskId)) return null;
        Database::pdo()->prepare('UPDATE collab_tasks SET size = ? WHERE id = ?')->execute([self::normSize($size), $taskId]);
        $t = self::oneTask($uid, $taskId);
        if ($t && $t['goal_id'] > 0 && class_exists('GoalPlanner')) GoalPlanner::syncProgress($t['goal_id']);
        return $t;
    }

    /**
     * Is this task stuck? Blocked over two days, doing over a week, or given
     * out and untouched over two weeks: the age of work in progress, which is
     * what tells you something is wrong before the due date does.
     * Returns ['flag' => '' | 'blocked' | 'stalled' | 'ageing', 'days' => n].
     */
    public static function flagOf(array $r, ?int $now = null): array
    {
        if (!empty($r['done'])) return ['flag' => '', 'days' => 0];
        $now = $now ?? time();
        $state = (string) ($r['state'] ?? '') ?: 'todo';
        $since = (string) (($r['state_at'] ?? '') ?: (($r['assigned_at'] ?? '') ?: ($r['created_at'] ?? '')));
        $days = $since === '' ? 0 : (int) floor(($now - (strtotime($since . ' UTC') ?: $now)) / 86400);
        if ($state === 'todo' && (int) ($r['assignee_id'] ?? 0) === 0) return ['flag' => '', 'days' => $days];   /* the pool has its own clock */
        $limit = self::STUCK_DAYS[$state] ?? 14;
        if ($days <= $limit) return ['flag' => '', 'days' => $days];
        return ['flag' => match ($state) { 'blocked' => 'blocked', 'doing' => 'stalled', default => 'ageing' }, 'days' => $days];
    }

    /**
     * Change a task's due date or priority.
     *
     * There was no way to do this: a task could be created, ticked and
     * deleted, and nothing in between. The portal worked around it by
     * deleting and re-adding, which loses who created it and when.
     *
     * Ownership is checked the same way toggleTask() checks it — against the
     * row, not against what the caller says — so this is safe to expose over
     * the CACENTRE bridge, where the caller is another site.
     *
     * @param array{due?:string, priority?:string} $in
     */
    public static function updateTask(int $uid, int $taskId, array $in): ?array
    {
        self::$lastError = '';
        self::ensureTasks();
        $t = self::ownedTask($uid, $taskId);
        /* Whoever gave it out may re-date it too (offered when it is three days late), though they did not make it. */
        if (!$t) { $st = Database::pdo()->prepare('SELECT * FROM collab_tasks WHERE id = ?'); $st->execute([$taskId]); $t = $st->fetch(PDO::FETCH_ASSOC) ?: null; if ($t && self::giverOf($t) !== $uid) $t = null; }
        if (!$t) return null;
        $giver = self::giverOf($t); $owner = (int) $t['assignee_id'];

        $set = []; $args = []; $moved = null;
        if (array_key_exists('due', $in)) {
            $due = trim((string) $in['due']);
            /* An empty date means "no date", which is a real answer. Anything
               that is not a date at all is not stored as one. */
            if ($due !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $due)) { self::$lastError = 'That is not a date.'; return null; }
            if ($due !== (string) $t['due']) {
                $why = self::normOutcome((string) ($in['reason'] ?? ''));
                /* Somebody else's request, moved by the person holding it: the
                   date was what they agreed to, so moving it says why, and the
                   giver hears it — moved quietly, the first they knew was the
                   old date going by. The agreed date stays (on time is against it). */
                $byOwner = $uid === $owner && $giver > 0 && $giver !== $uid;
                if ($byOwner && $why === '') { self::$lastError = 'Give a reason for the new date — ' . self::nameOf($giver) . ' is told.'; return null; }
                $set[] = 'due = ?'; $args[] = $due;
                $set[] = "first_due = CASE WHEN first_due = '' THEN ? ELSE first_due END"; $args[] = $due;
                /* The giver (or the owner of their own task) setting the date is the agreement. */
                if (!$byOwner) { $set[] = 'agreed_due = ?'; $args[] = $due; }
                elseif ((string) ($t['agreed_due'] ?? '') === '') { $set[] = 'agreed_due = ?'; $args[] = (string) $t['due']; }
                $moved = [$due, $why, $byOwner];
            }
        }
        if (array_key_exists('priority', $in)) {
            $set[] = 'priority = ?'; $args[] = self::normPriority((string) $in['priority']);
        }
        if (array_key_exists('outcome', $in)) {
            $set[] = 'outcome = ?'; $args[] = self::normOutcome((string) $in['outcome']);
        }
        if (!$set) return self::shapeTask($t, $uid);

        $args[] = $taskId;
        Database::pdo()->prepare('UPDATE collab_tasks SET ' . implode(', ', $set) . ' WHERE id = ?')
            ->execute($args);

        if ($moved !== null) {
            [$due, $why, $byOwner] = $moved;
            if (class_exists('TaskLedger')) TaskLedger::logDueChange($taskId, $uid, (string) $t['due'], $due, $why);
            $other = $byOwner ? $giver : ($uid === $giver && $owner > 0 && $owner !== $uid ? $owner : 0);
            if ($other > 0) self::tell($other, 'task', 'New date: ' . $t['title'],
                self::nameOf($uid) . ' moved it from ' . ((string) $t['due'] !== '' ? $t['due'] : 'no date') . ' to ' . ($due !== '' ? $due : 'no date') . ($why !== '' ? ': ' . $why : '') . '.',
                'task-due:' . $taskId . ':' . $due, $byOwner);
        }
        return self::oneTask($uid, $taskId);
    }

    /**
     * Why $uid may not delete this task, or '' when they may (or it is not
     * theirs at all — deleteTask answers that). What somebody finished is
     * read from this table (TaskLedger::history, TaskReport): a creator
     * deleting a task its assignee had already done erased that person's
     * work from their record and from what the matcher weighs next time.
     * Their own finished task stays theirs to delete.
     */
    public static function deleteRefused(int $uid, int $taskId): string
    {
        self::ensureTasks();
        $t = self::ownedTask($uid, $taskId);
        if ($t && (int) $t['done'] === 1 && (int) $t['assignee_id'] > 0 && (int) $t['assignee_id'] !== $uid) {
            return self::nameOf((int) $t['assignee_id']) . ' has finished this task, so it stays on their record.';
        }
        return '';
    }

    public static function deleteTask(int $uid, int $taskId): bool
    {
        self::ensureTasks();
        $t = self::ownedTask($uid, $taskId);
        /* "Drop it", offered to whoever gave it out when it is three days late. */
        if (!$t) { $st = Database::pdo()->prepare('SELECT * FROM collab_tasks WHERE id = ? AND done = 0'); $st->execute([$taskId]); $t = $st->fetch(PDO::FETCH_ASSOC) ?: null; if ($t && self::giverOf($t) !== $uid) $t = null; }
        if (!$t) return false;
        $owner = (int) $t['assignee_id'];
        if ($owner > 0 && $owner !== $uid && !(int) $t['done']) self::tell($owner, 'task', 'Dropped: ' . $t['title'], self::nameOf($uid) . ' dropped it — it is off your list.', 'task-dropped:' . $taskId);
        if (self::deleteRefused($uid, $taskId) !== '') return false;
        if ((int) ($t['cac_commitment_id'] ?? 0) > 0 && class_exists('CacWeek')) CacWeek::changed($taskId, true);
        Database::pdo()->prepare('DELETE FROM collab_tasks WHERE id = ?')->execute([$taskId]);
        /* A step gone from a goal moves it, as a tick or a resize does. */
        if ((int) ($t['goal_id'] ?? 0) > 0 && class_exists('GoalPlanner')) GoalPlanner::syncProgress((int) $t['goal_id']);
        return true;
    }

    /** The user's tasks (assigned to them or created by them), open first. */
    public static function myTasks(int $uid, int $limit = 50): array
    {
        self::ensureTasks();
        $limit = max(1, min(100, $limit));
        // Open first, then soonest due (undated last), newest first within.
        $st = Database::pdo()->prepare(
            "SELECT * FROM collab_tasks WHERE assignee_id = ? OR creator_id = ? OR assigned_by = ?
             ORDER BY done ASC, CASE WHEN due = '' THEN 1 ELSE 0 END ASC, due ASC, id DESC LIMIT " . $limit
        );
        $st->execute([$uid, $uid, (string) $uid]);
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
        $overdue = $due !== '' && !$r['done'] && $due < TaskReport::today();   /* Lagos's day, as the daily report and the reminders count it */
        $accept = in_array((string) ($r['accept_state'] ?? ''), self::WAITING, true) ? (string) $r['accept_state'] : '';
        $waiting = $accept !== '';
        $giver = self::giverOf($r);
        $agreed = class_exists('TaskLedger') ? TaskLedger::agreedDue($r) : $due;
        return [
            'id'       => (int) $r['id'],
            'title'    => (string) $r['title'],
            'done'     => (bool) $r['done'],
            'due'      => $due,
            'overdue'  => $overdue,
            'priority' => self::normPriority((string) ($r['priority'] ?? 'normal')),
            'open'     => $assignee === 0,                       // in the pool, unclaimed
            /* Theirs once they have said yes. Given and not yet accepted, it is
               "waiting for you": not counted as their work, nor in "My tasks". */
            'mine'     => $assignee === $uid && $assignee !== 0 && !$waiting,
            'waiting'  => $assignee === $uid && $assignee !== 0 && $waiting && !$r['done'],
            'accept_state' => $accept,
            'accepted_at'  => (string) ($r['accepted_at'] ?? ''),
            'proposed_due' => (string) ($r['proposed_due'] ?? ''),
            'proposed_why' => (string) ($r['proposed_why'] ?? ''),
            'outcome'      => (string) ($r['outcome'] ?? ''),
            'first_due'    => (string) ($r['first_due'] ?? ''),
            'agreed_due'   => $agreed,
            'moved'        => $agreed !== '' && $due !== $agreed,
            'review_state' => (string) ($r['review_state'] ?? ''),
            'review_note'  => (string) ($r['review_note'] ?? ''),
            /* I gave it to somebody else; and what, if anything, waits on my answer:
               a date they proposed, or their finished work to thank or send back. */
            'i_gave'       => $giver === $uid && $assignee !== $uid && $assignee !== 0,
            'needs_me'     => $giver === $uid && $assignee !== $uid && $assignee !== 0
                                ? ($accept === 'counter' ? 'date' : ($r['done'] && (string) ($r['review_state'] ?? '') === 'told' ? 'review' : '')) : '',
            'late_days'    => $giver === $uid && $assignee !== $uid && $assignee !== 0 && $overdue ? (int) round((strtotime(TaskReport::today()) - strtotime($due)) / 86400) : 0,
            // Allocation context for the UI.
            'assignee_id'   => $assignee,
            'assignee_name' => $assignee === 0 ? 'Unclaimed' : ($assignee === $uid ? 'You' : self::nameOf($assignee)),
            'assigned_out'  => $creator === $uid && $assignee !== $uid && $assignee !== 0, // I gave this to someone
            'creator_id'    => $creator,
            'created_at'    => (string) ($r['created_at'] ?? ''),
            /* When it was given to its assignee (a pool task given later), else when it was made. */
            'assigned_at'   => (string) (($r['assigned_at'] ?? '') !== '' ? $r['assigned_at'] : ($r['created_at'] ?? '')),
            /* Who gave it to them: whoever assigned it, else whoever made it. */
            'assigned_by'   => (int) (($r['assigned_by'] ?? '') !== '' ? $r['assigned_by'] : $creator),
            'assigned_by_name' => ($ab = (int) (($r['assigned_by'] ?? '') !== '' ? $r['assigned_by'] : $creator)) === $uid ? 'You' : self::nameOf($ab),
            'creator_name'  => $creator === $uid ? 'You' : self::nameOf($creator),
            'goal_id'       => $goalId,
            'goal_title'    => $goalId > 0 ? self::goalTitle($goalId) : '',
            'state'         => $r['done'] ? 'done' : (in_array((string) ($r['state'] ?? ''), self::STATES, true) && $r['state'] !== 'done' ? (string) $r['state'] : 'todo'),
            'blocked_reason'=> (string) ($r['blocked_reason'] ?? ''),
            'size'          => (string) ($r['size'] ?? ''),
            'started_at'    => (string) ($r['started_at'] ?? ''),
            'done_at'       => (string) ($r['done_at'] ?? ''),
            'stuck'         => self::flagOf($r),
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
    public static function roster(int $limit = 200, bool $withVolunteers = false): array
    {
        /* Volunteers too, with what they are labelled, for people who hand out
           work — lib/TaskMatch.php reads both from the same profiles. */
        if ($withVolunteers && class_exists('TaskMatch')) {
            try {
                $out = [];
                foreach (TaskMatch::profiles() as $p) $out[] = ['id' => $p['id'], 'name' => $p['name'], 'labels' => TaskMatch::summary($p), 'load' => $p['load']];
                usort($out, static fn($a, $b) => strcasecmp($a['name'], $b['name']));
                return array_slice($out, 0, max(1, min(500, $limit)));
            } catch (Throwable $e) { error_log('[collab] roster: ' . $e->getMessage()); }
        }
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
