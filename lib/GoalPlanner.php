<?php
/**
 * lib/GoalPlanner.php — tasks come from goals.
 *
 * A goal (lib/Goals.php) is what the team means to achieve; a task is a step
 * somebody takes towards it. So the work is drawn from the goals, not typed in
 * beside them:
 *
 *   · An open goal with nothing open under it is broken into steps — by AI
 *     when it is configured (Collab::aiTasksFromGoal, the Studio's goal.tasks
 *     prompt) — and each step goes to the person whose role, course, centre
 *     and project fit it, spread so one willing person is not handed the lot
 *     (lib/TaskMatch.php). With no AI, the goal's author is given one task:
 *     agree the next step. A goal is never left with nothing to do.
 *   · It is planned again when every step is done, or when its steps have sat
 *     untouched for tasks.goal_replan_days.
 *   · Its progress follows its tasks: done over total, as each is ticked.
 *
 * Run from the cron (a few goals a tick, so AI cost is bounded) and when a
 * goal is created. Switched off with the Studio rule tasks.from_goals.
 */
declare(strict_types=1);

require_once __DIR__ . '/Goals.php';

final class GoalPlanner
{
    /** Goals planned per cron tick, so one tick never spends the day's AI. */
    private const PER_TICK = 3;

    public static function enabled(): bool
    {
        return !class_exists('AvRules') || AvRules::bool('tasks.from_goals');
    }

    public static function ensure(): void
    {
        static $done = false; if ($done) return; $done = true;
        Goals::ensure();
        try { if (!Database::columnExists('team_goals', 'planned_at')) Database::pdo()->exec("ALTER TABLE team_goals ADD COLUMN planned_at VARCHAR(32) NOT NULL DEFAULT ''"); } catch (Throwable $e) {}
        Collab::myTasks(0, 1);
    }

    /** Open and done task counts for a goal. */
    public static function counts(int $goalId): array
    {
        $st = Database::pdo()->prepare('SELECT SUM(CASE WHEN done = 0 THEN 1 ELSE 0 END), SUM(CASE WHEN done = 1 THEN 1 ELSE 0 END) FROM collab_tasks WHERE goal_id = ?');
        $st->execute([$goalId]);
        [$open, $done] = $st->fetch(PDO::FETCH_NUM) ?: [0, 0];
        return ['open' => (int) $open, 'done' => (int) $done];
    }

    /** Goals that need steps now: open, nothing open under them, and not planned too recently. */
    public static function due(?int $now = null): array
    {
        self::ensure();
        $now = $now ?? time();
        $days = class_exists('AvRules') ? max(1, AvRules::int('tasks.goal_replan_days')) : 7;
        $out = [];
        foreach (Database::pdo()->query('SELECT * FROM team_goals WHERE closed = 0 ORDER BY id ASC')->fetchAll(PDO::FETCH_ASSOC) as $g) {
            $c = self::counts((int) $g['id']);
            if ($c['open'] > 0) continue;                         /* steps are in hand */
            $planned = (string) $g['planned_at'];
            $age = $planned === '' ? PHP_INT_MAX : $now - (strtotime($planned . ' UTC') ?: 0);
            if ($planned === ''                                   /* never planned */
                || ($c['done'] > 0 && $age > 86400)               /* every step done: the next round, at most daily */
                || $age > $days * 86400) {                        /* steps were removed, and it has waited long enough */
                $out[] = $g;
            }
        }
        return $out;
    }

    /**
     * Plan one goal: steps from AI, given to whoever fits; else one step for its
     * author. Returns ['ok', 'created', 'assigned', 'via'].
     */
    public static function plan(int $goalId, ?int $now = null): array
    {
        self::ensure();
        $g = Goals::get($goalId);
        if (!$g || $g['closed']) return ['ok' => false, 'error' => 'No such open goal.', 'created' => 0];
        $now = $now ?? time();
        Database::pdo()->prepare('UPDATE team_goals SET planned_at = ? WHERE id = ?')->execute([gmdate('Y-m-d H:i:s', $now), $goalId]);
        $author = (int) $g['author_id'];
        if (Collab::aiAvailable()) {
            $r = Collab::aiTasksFromGoal($author, $goalId, 5, true);
            if (!empty($r['ok'])) return ['ok' => true, 'created' => count($r['created']), 'assigned' => (int) ($r['assigned'] ?? 0), 'via' => 'ai'];
        }
        /* No AI, or it failed: the author decides the next step, and is asked to. */
        $title = mb_substr('Agree the next step for “' . $g['title'] . '”', 0, 300);
        $id = Collab::addTask($author, $title, $author, gmdate('Y-m-d', $now + 2 * 86400), 'normal', $goalId);
        return ['ok' => $id > 0, 'created' => $id > 0 ? 1 : 0, 'assigned' => $id > 0 ? 1 : 0, 'via' => 'author'];
    }

    /** From the cron. */
    public static function sweep(?int $now = null): array
    {
        $out = ['planned' => 0, 'tasks' => 0, 'off' => !self::enabled()];
        if ($out['off']) return $out;
        foreach (array_slice(self::due($now), 0, self::PER_TICK) as $g) {
            try {
                $r = self::plan((int) $g['id'], $now);
                if ($r['ok']) { $out['planned']++; $out['tasks'] += (int) $r['created']; }
            } catch (Throwable $e) { error_log('[goal-planner] ' . $e->getMessage()); }
        }
        return $out;
    }

    /** A step was ticked or put back: the goal's progress is done over total. */
    public static function syncProgress(int $goalId): void
    {
        if ($goalId <= 0) return;
        try {
            self::ensure();
            $c = self::counts($goalId);
            $total = $c['open'] + $c['done'];
            if ($total === 0) return;
            Database::pdo()->prepare('UPDATE team_goals SET progress = ?, updated_at = ? WHERE id = ? AND closed = 0')
                ->execute([(int) round(100 * $c['done'] / $total), gmdate('Y-m-d H:i:s'), $goalId]);
        } catch (Throwable $e) { error_log('[goal-planner] progress: ' . $e->getMessage()); }
    }

    /**
     * The goals this person is working towards — those with a step assigned to
     * them, open — each with its progress and their open steps:
     * [['id','title','target','progress','open','done','tasks'=>[shaped…]]].
     */
    public static function forUser(int $uid): array
    {
        self::ensure();
        $st = Database::pdo()->prepare('SELECT * FROM collab_tasks WHERE goal_id > 0 AND assignee_id = ? AND done = 0 ORDER BY CASE WHEN due = \'\' THEN 1 ELSE 0 END, due ASC, id ASC');
        $st->execute([$uid]);
        $by = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $by[(int) $r['goal_id']][] = Collab::oneTask($uid, (int) $r['id']);
        $out = [];
        foreach ($by as $gid => $tasks) {
            $g = Goals::get($gid);
            if (!$g || $g['closed']) continue;
            $out[] = ['id' => $gid, 'title' => $g['title'], 'target' => $g['target'], 'progress' => $g['progress'], 'tasks' => array_values(array_filter($tasks))] + self::counts($gid);
        }
        return $out;
    }
}
