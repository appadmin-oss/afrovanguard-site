<?php
/**
 * lib/AiOps.php — is the AI layer actually running, and what is it costing?
 *
 * Everything built over the last several passes runs on a cron tick nobody
 * watches: accountability escalations, agenda drafts, the meeting clock,
 * promotion reviews, the leadership brief. Each one is idempotent, self-limiting
 * and silent by design — which is correct, and which means a dead cron looks
 * exactly like a healthy quiet week. That is the failure this board exists to
 * make impossible: an escalation ladder that stopped climbing three weeks ago
 * reports nothing at all.
 *
 * So the board leads with ALERTS, not numbers. It is the same principle §21 sets
 * for the leadership brief — manage exceptions, do not read a directory — turned
 * on the machinery itself. A page of green counters trains people to stop
 * looking; a page that is empty until something is wrong does not.
 *
 * Three sources feed it:
 *   • av_job_runs   — the cron heartbeat. Written by tasks/cron.php per rung,
 *                     so "when did this last run and what did it do" has an
 *                     answer rather than an inference.
 *   • av_ai_calls   — the model ledger AvRouter writes. Calls, failures,
 *                     fallbacks, tokens, latency, per provider and per job.
 *   • the feature tables — drafts pending, reviews waiting, escalations sent.
 *
 * ON COST. This reports TOKENS, not money. Converting one to the other needs a
 * per-model price list that changes without notice, and a dashboard that
 * confidently shows a wrong naira figure is worse than one that shows the number
 * it actually knows. Tokens per provider per week is enough to see a bill coming.
 */
declare(strict_types=1);

final class AiOps
{
    /** A cron tick is expected about this often; past this the heartbeat is stale. */
    private const TICK_GRACE_MIN = 90;

    /** A provider failing more than this share of its calls is worth saying out loud. */
    private const FAIL_ALERT_PCT = 25;

    /** Keep a few weeks of run history. It is a heartbeat, not an audit log. */
    private const RUN_RETAIN_DAYS = 30;

    private static bool $ready = false;

    /* ════════════════════════════════════════════════════════════════
       The cron heartbeat
       ════════════════════════════════════════════════════════════════ */

    /** The rungs the board expects to see, in the order cron runs them. */
    public const RUNGS = [
        'accountability' => 'Accountability sweep',
        'agendas'        => 'Agenda drafting',
        'meeting_clock'  => 'Meeting time warnings',
        'promotions'     => 'Promotion reviews',
        'brief'          => 'Leadership brief',
        'chat'           => 'Chat task bot',
    ];

    public static function ensure(): void
    {
        if (self::$ready) return;
        self::$ready = true;
        try {
            $db = Database::pdo();
            Database::execSchema($db, "CREATE TABLE IF NOT EXISTS av_job_runs (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                job        VARCHAR(32)  NOT NULL DEFAULT '',
                ok         INTEGER      NOT NULL DEFAULT 1,
                skipped    INTEGER      NOT NULL DEFAULT 0,
                ms         INTEGER      NOT NULL DEFAULT 0,
                detail     TEXT         NOT NULL DEFAULT '',
                error      VARCHAR(300) NOT NULL DEFAULT '',
                created_at VARCHAR(32)  NOT NULL DEFAULT ''
            );");
            try { $db->exec('CREATE INDEX IF NOT EXISTS idx_job_runs ON av_job_runs(job, id)'); }
            catch (Throwable $e) {}
        } catch (Throwable $e) { error_log('[aiops] ensure: ' . $e->getMessage()); }
    }

    /**
     * Record one cron rung. Never throws — the heartbeat must not be able to
     * stop the heart.
     *
     * $detail is whatever the rung returned; a rung that reports `skipped` is
     * recorded as a run that happened and chose to do nothing, which is a
     * different thing from a rung that did not run at all. Conflating those is
     * how a self-limiting job hides the fact that it has stopped.
     */
    public static function recordRun(string $job, bool $ok, $detail = null, int $ms = 0, string $error = ''): void
    {
        try {
            self::ensure();
            $skipped = is_array($detail) && !empty($detail['skipped']) ? 1 : 0;
            $json = is_array($detail) || is_scalar($detail) ? json_encode($detail, JSON_UNESCAPED_SLASHES) : '';
            Database::pdo()->prepare(
                'INSERT INTO av_job_runs (job, ok, skipped, ms, detail, error, created_at) VALUES (?,?,?,?,?,?,?)'
            )->execute([
                mb_substr($job, 0, 32), $ok ? 1 : 0, $skipped, max(0, $ms),
                mb_substr(is_string($json) ? $json : '', 0, 2000),
                mb_substr($error, 0, 300), gmdate('Y-m-d H:i:s'),
            ]);
            if (random_int(1, 40) === 1) {
                $cut = gmdate('Y-m-d H:i:s', time() - self::RUN_RETAIN_DAYS * 86400);
                Database::pdo()->prepare('DELETE FROM av_job_runs WHERE created_at < ?')->execute([$cut]);
            }
        } catch (Throwable $e) { error_log('[aiops] recordRun: ' . $e->getMessage()); }
    }

    /**
     * Run one cron rung with the heartbeat wrapped around it.
     *
     * Put here rather than repeated in cron.php so that a rung added later
     * cannot be added WITHOUT its heartbeat — the wrapper is the only way to
     * call one. Exceptions are caught and recorded, matching what cron.php
     * already did, because one broken rung must not stop the rest of the tick.
     */
    public static function run(string $job, callable $fn)
    {
        $t0 = microtime(true);
        try {
            $r = $fn();
            self::recordRun($job, true, $r, (int) round((microtime(true) - $t0) * 1000));
            return $r;
        } catch (Throwable $e) {
            self::recordRun($job, false, null, (int) round((microtime(true) - $t0) * 1000), $e->getMessage());
            error_log('[cron] ' . $job . ': ' . $e->getMessage());
            return null;
        }
    }

    /** Last run per rung: when, whether it worked, and what it did. */
    public static function heartbeat(): array
    {
        self::ensure();
        $out = [];
        $now = time();
        foreach (self::RUNGS as $job => $label) {
            $row = null;
            try {
                $st = Database::pdo()->prepare('SELECT * FROM av_job_runs WHERE job = ? ORDER BY id DESC LIMIT 1');
                $st->execute([$job]);
                $row = $st->fetch(PDO::FETCH_ASSOC) ?: null;
            } catch (Throwable $e) {}

            $at  = $row ? (string) $row['created_at'] : '';
            $age = $at !== '' ? max(0, (int) round(($now - strtotime($at . ' UTC')) / 60)) : null;
            $out[] = [
                'job'      => $job,
                'label'    => $label,
                'last_at'  => $at,
                'age_min'  => $age,
                'ok'       => $row ? ((int) $row['ok'] === 1) : null,
                'skipped'  => $row ? ((int) $row['skipped'] === 1) : null,
                'error'    => $row ? (string) $row['error'] : '',
                'detail'   => $row ? json_decode((string) $row['detail'], true) : null,
                'ever_ran' => $row !== null,
            ];
        }
        return $out;
    }

    /** Minutes since ANY rung last ran — the single "is cron alive" number. */
    public static function lastTickAgeMin(): ?int
    {
        self::ensure();
        try {
            $at = Database::pdo()->query('SELECT MAX(created_at) FROM av_job_runs')->fetchColumn();
            if (!is_string($at) || $at === '') return null;
            return max(0, (int) round((time() - strtotime($at . ' UTC')) / 60));
        } catch (Throwable $e) { return null; }
    }

    /* ════════════════════════════════════════════════════════════════
       The model ledger
       ════════════════════════════════════════════════════════════════ */

    private static function since(int $days): string
    {
        return gmdate('Y-m-d H:i:s', time() - max(1, $days) * 86400);
    }

    /**
     * Model usage over a window: totals, and a breakdown per provider and per
     * job. `fallbacks` counts attempts that were not the first choice — a high
     * number means the primary is failing quietly, which is the exact thing the
     * fallback chain is designed to hide from users and must not hide from ops.
     */
    public static function usage(int $days = 7): array
    {
        if (class_exists('AvRouter')) AvRouter::ensure();
        $since = self::since($days);
        $blank = ['calls' => 0, 'ok' => 0, 'failed' => 0, 'fallbacks' => 0,
                  'tokens_in' => 0, 'tokens_out' => 0, 'ms_total' => 0];
        $out = ['days' => $days, 'total' => $blank, 'providers' => [], 'jobs' => [], 'daily' => [], 'recent_errors' => []];

        try {
            $st = Database::pdo()->prepare(
                'SELECT provider, model, job, ok, ms, tokens_in, tokens_out, depth, error, created_at
                 FROM av_ai_calls WHERE created_at >= ? ORDER BY id DESC'
            );
            $st->execute([$since]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) { return $out; }

        $add = static function (array &$bucket, array $r): void {
            $bucket['calls']++;
            if ((int) $r['ok'] === 1) $bucket['ok']++; else $bucket['failed']++;
            if ((int) $r['depth'] > 0) $bucket['fallbacks']++;
            $bucket['tokens_in']  += (int) $r['tokens_in'];
            $bucket['tokens_out'] += (int) $r['tokens_out'];
            $bucket['ms_total']   += (int) $r['ms'];
        };

        foreach ($rows as $r) {
            $add($out['total'], $r);

            $p = (string) $r['provider'];
            if (!isset($out['providers'][$p])) $out['providers'][$p] = $blank + ['provider' => $p, 'models' => []];
            $add($out['providers'][$p], $r);
            $m = (string) $r['model'];
            if ($m !== '') $out['providers'][$p]['models'][$m] = true;

            $j = (string) $r['job'];
            if (!isset($out['jobs'][$j])) $out['jobs'][$j] = $blank + ['job' => $j];
            $add($out['jobs'][$j], $r);

            $d = substr((string) $r['created_at'], 0, 10);
            if (!isset($out['daily'][$d])) $out['daily'][$d] = $blank + ['date' => $d];
            $add($out['daily'][$d], $r);

            if ((int) $r['ok'] === 0 && count($out['recent_errors']) < 12) {
                $out['recent_errors'][] = ['provider' => $p, 'model' => $m, 'job' => $j,
                                           'error' => (string) $r['error'], 'at' => (string) $r['created_at']];
            }
        }

        foreach ($out['providers'] as &$p) {
            $p['models'] = array_keys($p['models']);
            $p['fail_pct'] = $p['calls'] > 0 ? (int) round($p['failed'] * 100 / $p['calls']) : 0;
            $p['avg_ms']   = $p['calls'] > 0 ? (int) round($p['ms_total'] / $p['calls']) : 0;
        }
        unset($p);
        $out['providers'] = array_values($out['providers']);
        usort($out['providers'], static fn($a, $b) => $b['calls'] <=> $a['calls']);
        $out['jobs'] = array_values($out['jobs']);
        ksort($out['daily']);
        $out['daily'] = array_values($out['daily']);
        $out['total']['fail_pct'] = $out['total']['calls'] > 0
            ? (int) round($out['total']['failed'] * 100 / $out['total']['calls']) : 0;
        $out['total']['avg_ms'] = $out['total']['calls'] > 0
            ? (int) round($out['total']['ms_total'] / $out['total']['calls']) : 0;
        return $out;
    }

    /* ════════════════════════════════════════════════════════════════
       What the features have actually produced
       ════════════════════════════════════════════════════════════════ */

    private static function count(string $sql, array $args = []): int
    {
        try { $st = Database::pdo()->prepare($sql); $st->execute($args); return (int) $st->fetchColumn(); }
        catch (Throwable $e) { return 0; }
    }

    /**
     * Counters per shipped feature, over the window and outstanding right now.
     *
     * "Outstanding" is the half that matters. Twelve agenda drafts produced is
     * activity; twelve agenda drafts nobody has looked at is a feature people
     * have started ignoring, and only one of those is worth a leader's attention.
     */
    public static function pipeline(int $days = 7): array
    {
        foreach (['Accountability', 'Agenda', 'Promotion', 'Brief', 'MeetingClock'] as $c) {
            if (class_exists($c) && method_exists($c, 'ensure')) { try { $c::ensure(); } catch (Throwable $e) {} }
        }
        $since = self::since($days);

        return [
            'days' => $days,
            'escalations' => [
                'window'  => self::count('SELECT COUNT(*) FROM av_escalations WHERE created_at >= ?', [$since]),
                'top_rung'=> self::count('SELECT COUNT(*) FROM av_escalations WHERE created_at >= ? AND step >= 3', [$since]),
                'total'   => self::count('SELECT COUNT(*) FROM av_escalations'),
            ],
            'agendas' => [
                'window'    => self::count('SELECT COUNT(*) FROM av_agenda_drafts WHERE created_at >= ?', [$since]),
                'pending'   => self::count("SELECT COUNT(*) FROM av_agenda_drafts WHERE status = 'pending'"),
                'applied'   => self::count("SELECT COUNT(*) FROM av_agenda_drafts WHERE status = 'applied'"),
                'dismissed' => self::count("SELECT COUNT(*) FROM av_agenda_drafts WHERE status = 'dismissed'"),
                'stale'     => self::count("SELECT COUNT(*) FROM av_agenda_drafts WHERE status = 'pending' AND created_at < ?",
                                           [self::since(7)]),
            ],
            'promotions' => [
                'window'    => self::count('SELECT COUNT(*) FROM av_promotion_reviews WHERE created_at >= ?', [$since]),
                'open'      => self::count("SELECT COUNT(*) FROM av_promotion_reviews WHERE status = 'open'"),
                'disagreed' => self::count("SELECT COUNT(*) FROM av_promotion_reviews WHERE status = 'open' AND agrees = 0"),
                'deferred'  => self::count("SELECT COUNT(*) FROM av_promotion_reviews WHERE status = 'deferred'"),
            ],
            'briefs' => [
                'window' => self::count('SELECT COUNT(*) FROM av_briefs WHERE created_at >= ?', [$since]),
                'total'  => self::count('SELECT COUNT(*) FROM av_briefs'),
                'last'   => self::scalar('SELECT MAX(created_at) FROM av_briefs'),
            ],
            'clock' => [
                'tracked' => self::count('SELECT COUNT(*) FROM av_meeting_clock'),
                'warned'  => self::count("SELECT COUNT(*) FROM av_meeting_clock WHERE fired <> ''"),
            ],
            'commitments' => [
                'open'      => self::count("SELECT COUNT(*) FROM commitments WHERE status = 'open'"),
                'unassigned'=> self::count("SELECT COUNT(*) FROM commitments WHERE status = 'open' AND member_id = 0"),
                'from_chat' => self::count("SELECT COUNT(*) FROM commitments WHERE source_kind = 'chat'"),
                'window'    => self::count('SELECT COUNT(*) FROM commitments WHERE created_at >= ?', [$since]),
            ],
        ];
    }

    private static function scalar(string $sql): string
    {
        try { $v = Database::pdo()->query($sql)->fetchColumn(); return is_string($v) ? $v : ''; }
        catch (Throwable $e) { return ''; }
    }

    /* ════════════════════════════════════════════════════════════════
       Alerts — what a human should actually do something about
       ════════════════════════════════════════════════════════════════ */

    /**
     * Derive the exception list. Severity is 'crit' | 'warn' | 'info', and the
     * board shows nothing else when this is empty.
     *
     * Every alert names the thing to do, not just the thing that is true. "AI
     * calls are failing" is a fact; "every call is falling back, so the primary
     * provider is down — check its key" is an instruction.
     */
    public static function alerts(?array $usage = null, ?array $pipeline = null, ?array $beats = null): array
    {
        $usage    = $usage    ?? self::usage(7);
        $pipeline = $pipeline ?? self::pipeline(7);
        $beats    = $beats    ?? self::heartbeat();
        $out = [];

        /* ── Is the machinery running at all? ── */
        $tick = self::lastTickAgeMin();
        if ($tick === null) {
            $out[] = ['level' => 'crit', 'key' => 'cron.never',
                      'title' => 'The scheduled tasks have never run',
                      'body'  => 'Nothing has recorded a run. Every feature on this page depends on tasks/cron.php being called on a schedule — until it is, escalations, agendas, warnings, reviews and briefs all do nothing. Set up the cron job in System.'];
        } elseif ($tick > self::TICK_GRACE_MIN) {
            $out[] = ['level' => 'crit', 'key' => 'cron.stale',
                      'title' => 'The scheduled tasks stopped ' . self::humanMin($tick) . ' ago',
                      'body'  => 'A silent accountability system and a broken one look identical from the outside. Nothing has escalated, drafted or briefed since then.'];
        }
        foreach ($beats as $b) {
            if ($b['ever_ran'] && $b['ok'] === false) {
                $out[] = ['level' => 'crit', 'key' => 'rung.' . $b['job'],
                          'title' => 'Last run of “' . $b['label'] . '” failed',
                          'body'  => $b['error'] !== '' ? $b['error'] : 'No error was recorded. Check the server log.'];
            }
        }

        /* ── Are the models answering? ── */
        $configured = [];
        if (class_exists('AvRouter')) {
            foreach (AvRouter::known() as $h) if (AvRouter::configured($h)) $configured[] = $h;
        }
        if (!$configured) {
            $out[] = ['level' => 'warn', 'key' => 'provider.none',
                      'title' => 'No AI provider is configured',
                      'body'  => 'The engine still tracks, reminds and escalates deterministically — the wording just stays templated. Set a key in System to turn the model layer on: ' . (class_exists('AvRouter') ? AvRouter::keyHint() : '') . '.'];
        }
        $t = $usage['total'];
        if ($t['calls'] >= 10 && $t['fail_pct'] >= self::FAIL_ALERT_PCT) {
            $out[] = ['level' => 'warn', 'key' => 'provider.failing',
                      'title' => $t['fail_pct'] . '% of model calls failed this week',
                      'body'  => 'Check the per-provider table below. A run of failures on one provider is most often a renamed or retired model id.'];
        }
        if ($t['calls'] >= 10 && $t['fallbacks'] >= (int) ceil($t['calls'] * 0.5)) {
            $out[] = ['level' => 'warn', 'key' => 'provider.fallback',
                      'title' => 'Most calls are being answered by a fallback provider',
                      'body'  => 'The first provider in the routing rule is failing and the chain is covering for it — which is what it is for, and why nobody has noticed. Whatever leads your ai.route_* rules needs looking at.'];
        }
        // Per provider, not just in aggregate. A provider failing 40% of its
        // calls is invisible in a total that healthy providers dominate — which
        // is exactly the case that matters, because the usual cause is a model
        // id the vendor has retired, and it affects one provider at a time.
        foreach ($usage['providers'] as $p) {
            if ($p['calls'] < 5 || $p['fail_pct'] < self::FAIL_ALERT_PCT) continue;
            $name  = class_exists('AvRouter') ? AvRouter::label($p['provider']) : $p['provider'];
            $dead  = $p['fail_pct'] >= 90;
            $out[] = [
                'level' => $dead ? 'warn' : 'info',
                'key'   => 'provider.failing.' . $p['provider'],
                'title' => $dead
                    ? $name . ' is failing almost every call'
                    : $name . ' is failing ' . $p['fail_pct'] . '% of its calls',
                'body'  => 'Model: ' . (implode(', ', $p['models']) ?: 'unknown')
                         . '. Check the key and the model name first — vendors retire model ids without much notice, '
                         . 'and a renamed model is the most common cause of a provider failing while the others are fine. '
                         . 'The recent failures table below has the exact error.',
            ];
        }

        /* ── Is anyone acting on what it produces? ── */
        if (($pipeline['agendas']['stale'] ?? 0) >= 3) {
            $out[] = ['level' => 'info', 'key' => 'agenda.stale',
                      'title' => self::plural((int) $pipeline['agendas']['stale'], 'agenda draft')
                               . ' more than a week old, unreviewed',
                      'body'  => 'Chairs are not reading them. A draft nobody reviews is worse than no draft: it costs a model call and trains people to ignore the feature.'];
        }
        if (($pipeline['promotions']['disagreed'] ?? 0) > 0) {
            $out[] = ['level' => 'info', 'key' => 'promotion.disagree',
                      'title' => self::plural((int) $pipeline['promotions']['disagreed'], 'promotion review')
                               . ' where the engine and the model disagree',
                      'body'  => 'These are the ones worth reading first — the numbers and the record are telling different stories.'];
        }
        if (($pipeline['escalations']['top_rung'] ?? 0) > 0) {
            $out[] = ['level' => 'warn', 'key' => 'escalation.top',
                      'title' => self::plural((int) $pipeline['escalations']['top_rung'], 'pairing')
                               . ' reached the top of the escalation ladder',
                      'body'  => 'The ladder has run out. These need a person now, not another notification.'];
        }
        if (($pipeline['commitments']['unassigned'] ?? 0) >= 10) {
            $out[] = ['level' => 'info', 'key' => 'commitments.unassigned',
                      'title' => self::plural((int) $pipeline['commitments']['unassigned'], 'open commitment')
                               . ' with no owner',
                      'body'  => 'Extraction proposes an owner; a human confirms it. That queue is not being worked.'];
        }

        $rank = ['crit' => 0, 'warn' => 1, 'info' => 2];
        usort($out, static fn($a, $b) => ($rank[$a['level']] ?? 3) <=> ($rank[$b['level']] ?? 3));
        return $out;
    }

    /** "1 pairing" / "3 pairings". The (s) form reads like a form letter. */
    private static function plural(int $n, string $one, string $many = ''): string
    {
        return $n . ' ' . ($n === 1 ? $one : ($many !== '' ? $many : $one . 's'));
    }

    private static function humanMin(int $m): string
    {
        if ($m < 60)   return $m . ' minute' . ($m === 1 ? '' : 's');
        if ($m < 1440) { $h = (int) round($m / 60); return $h . ' hour' . ($h === 1 ? '' : 's'); }
        $d = (int) round($m / 1440);
        return $d . ' day' . ($d === 1 ? '' : 's');
    }

    /* ════════════════════════════════════════════════════════════════
       The whole board in one call
       ════════════════════════════════════════════════════════════════ */

    public static function snapshot(int $days = 7): array
    {
        $days     = max(1, min(90, $days));
        $usage    = self::usage($days);
        $pipeline = self::pipeline($days);
        $beats    = self::heartbeat();

        return [
            'at'        => gmdate('c'),
            'days'      => $days,
            'alerts'    => self::alerts($usage, $pipeline, $beats),
            'heartbeat' => ['tick_age_min' => self::lastTickAgeMin(), 'grace_min' => self::TICK_GRACE_MIN, 'rungs' => $beats],
            'providers' => class_exists('AvRouter') ? AvRouter::inventory() : [],
            'routes'    => self::routes(),
            'usage'     => $usage,
            'pipeline'  => $pipeline,
            'ai_enabled'=> !class_exists('AvRules') || AvRules::bool('ai.enabled'),
        ];
    }

    /** The declared routing, and what of it is live, for display. */
    public static function routes(): array
    {
        if (!class_exists('AvRouter')) return [];
        $out = [];
        foreach (AvRouter::JOBS as $job) {
            $out[] = [
                'job'      => $job,
                'declared' => AvRouter::order($job, false),
                'live'     => AvRouter::order($job),
            ];
        }
        return $out;
    }
}
