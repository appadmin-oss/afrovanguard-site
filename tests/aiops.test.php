<?php
/**
 * tests/aiops.test.php — the board that says whether the machinery is running.
 *
 * The thing this has to get right is the distinction the whole page exists for:
 * a rung that ran and found nothing to do, versus a rung that did not run. Every
 * feature built over the last several passes is idempotent, self-limiting and
 * silent, so those two states produce identical output everywhere else in the
 * system. If the heartbeat conflates them, the board is decoration.
 *
 * The alert rules get the same treatment: each one is driven to fire and then
 * driven back to silence, because an alert that cannot turn off is noise and an
 * alert that never turns on is worse than none.
 *
 * Run via tests/run.php (provides ck() and reset_users()).
 */
declare(strict_types=1);

AiOps::ensure();
AvRouter::ensure();
$db = Database::pdo();
$wipe = static function () use ($db) {
    foreach (['av_job_runs', 'av_ai_calls', 'av_agenda_drafts', 'av_escalations', 'av_promotion_reviews'] as $t) {
        try { $db->exec('DELETE FROM ' . $t); } catch (Throwable $e) {}
    }
};
$keys = static function (array $alerts): array {
    return array_column($alerts, 'key');
};
$wipe();

/* ══════════════════════════════════════════════════════════════════════
   1. The heartbeat: ran-and-did-nothing is not the same as never-ran
   ══════════════════════════════════════════════════════════════════════ */

ck('heartbeat/no runs means no tick', AiOps::lastTickAgeMin() === null);

$beats = AiOps::heartbeat();
ck('heartbeat/lists every rung', count($beats) === count(AiOps::RUNGS));
ck('heartbeat/a rung that never ran says so', $beats[0]['ever_ran'] === false && $beats[0]['age_min'] === null);
ck('heartbeat/never-ran has no verdict',      $beats[0]['ok'] === null);

AiOps::recordRun('accountability', true, ['reminded' => 2, 'escalated' => 1, 'skipped' => false], 300);
AiOps::recordRun('meeting_clock', true, ['warned' => 0, 'skipped' => true], 12);
AiOps::recordRun('promotions', false, null, 40, 'disk I/O error');

$by = [];
foreach (AiOps::heartbeat() as $b) $by[$b['job']] = $b;

ck('heartbeat/a run is recorded',        $by['accountability']['ever_ran'] === true);
ck('heartbeat/a good run reports ok',    $by['accountability']['ok'] === true);
ck('heartbeat/a good run is not skipped', $by['accountability']['skipped'] === false);
ck('heartbeat/detail round-trips',       ($by['accountability']['detail']['escalated'] ?? null) === 1);

/* The distinction the page exists for. Both of these "ok", only one did work. */
ck('heartbeat/nothing-due is a run',      $by['meeting_clock']['ever_ran'] === true && $by['meeting_clock']['ok'] === true);
ck('heartbeat/nothing-due is flagged skipped', $by['meeting_clock']['skipped'] === true);
ck('heartbeat/never-ran is NOT skipped',  $by['brief']['skipped'] === null && $by['brief']['ever_ran'] === false);

ck('heartbeat/a failure reports not-ok',  $by['promotions']['ok'] === false);
ck('heartbeat/a failure keeps its error', strpos($by['promotions']['error'], 'disk I/O') !== false);
ck('heartbeat/tick age now exists',       AiOps::lastTickAgeMin() !== null && AiOps::lastTickAgeMin() < 5);

/* Only the LATEST run per rung is shown — a board that showed the last good run
   next to a newer failure would report health that no longer holds. */
AiOps::recordRun('promotions', true, ['reviewed' => 4, 'skipped' => false], 90);
$by2 = [];
foreach (AiOps::heartbeat() as $b) $by2[$b['job']] = $b;
ck('heartbeat/shows the latest run, not the best', $by2['promotions']['ok'] === true);
ck('heartbeat/the stale error is gone',            $by2['promotions']['error'] === '');

/* ══════════════════════════════════════════════════════════════════════
   2. run() wraps a rung: heartbeat on success AND on a throw
   ══════════════════════════════════════════════════════════════════════ */

$wipe();
$r = AiOps::run('agendas', static fn() => ['drafted' => 3, 'skipped' => false]);
ck('run/returns the rung value',  ($r['drafted'] ?? 0) === 3);
$row = $db->query("SELECT * FROM av_job_runs WHERE job='agendas' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
ck('run/records on success',      $row && (int) $row['ok'] === 1);
ck('run/records elapsed time',    $row && (int) $row['ms'] >= 0);

/* A rung that throws must still leave a heartbeat, and must not take the tick
   down with it — this is what cron.php's try/catch used to do by hand, and the
   reason it lives here is so a rung added later cannot arrive without it. */
$threw = false;
try {
    $r = AiOps::run('promotions', static function () { throw new RuntimeException('kaboom'); });
    ck('run/a throwing rung returns null', $r === null);
} catch (Throwable $e) { $threw = true; }
ck('run/a throwing rung does not propagate', $threw === false);
$row = $db->query("SELECT * FROM av_job_runs WHERE job='promotions' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
ck('run/records the failure',  $row && (int) $row['ok'] === 0);
ck('run/records the message',  $row && strpos((string) $row['error'], 'kaboom') !== false);

/* Never throws, whatever it is handed. */
AiOps::recordRun('', true, null);
AiOps::recordRun('x', true, "a plain string");
AiOps::recordRun('y', true, ['deep' => ['nested' => true]]);
ck('recordRun/survives odd input', (int) $db->query('SELECT COUNT(*) FROM av_job_runs')->fetchColumn() >= 5);

/* ══════════════════════════════════════════════════════════════════════
   3. Usage from the ledger
   ══════════════════════════════════════════════════════════════════════ */

$wipe();
$call = static function (string $prov, string $model, string $job, bool $ok, int $depth, int $in, int $out, int $agoSec, string $err = '') use ($db) {
    $db->prepare('INSERT INTO av_ai_calls (job,actor,provider,model,ok,ms,tokens_in,tokens_out,depth,error,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
       ->execute([$job, 'test', $prov, $model, $ok ? 1 : 0, 500, $in, $out, $depth, $err, gmdate('Y-m-d H:i:s', time() - $agoSec)]);
};
for ($i = 0; $i < 8; $i++) $call('groq', 'llama-x', 'bulk', true, 0, 100, 20, 60 * $i);
for ($i = 0; $i < 2; $i++) $call('groq', 'llama-x', 'bulk', false, 0, 10, 0, 60 * $i, 'model_not_found');
for ($i = 0; $i < 3; $i++) $call('openai', 'gpt-x', 'reason', true, 1, 1000, 200, 60 * $i);
/* Outside the window — must not be counted. */
$call('openai', 'gpt-x', 'reason', true, 0, 99999, 99999, 86400 * 40);

$u = AiOps::usage(7);
ck('usage/counts calls in the window',   $u['total']['calls'] === 13);
ck('usage/excludes calls outside it',    $u['total']['tokens_in'] === 8 * 100 + 2 * 10 + 3 * 1000);
ck('usage/splits ok and failed',         $u['total']['ok'] === 11 && $u['total']['failed'] === 2);
ck('usage/computes a failure rate',      $u['total']['fail_pct'] === 15);
ck('usage/counts fallback attempts',     $u['total']['fallbacks'] === 3);
ck('usage/breaks down per provider',     count($u['providers']) === 2);
ck('usage/orders providers by volume',   $u['providers'][0]['provider'] === 'groq');
ck('usage/per-provider failure rate',    $u['providers'][0]['fail_pct'] === 20);
ck('usage/lists the models seen',        $u['providers'][0]['models'] === ['llama-x']);
ck('usage/breaks down per job',          count($u['jobs']) === 2);
ck('usage/collects recent errors',       count($u['recent_errors']) === 2);
ck('usage/error carries its provider',   ($u['recent_errors'][0]['provider'] ?? '') === 'groq');
ck('usage/caps the error list',          count($u['recent_errors']) <= 12);

$u1 = AiOps::usage(1);
ck('usage/window is honoured',           $u1['total']['calls'] === 13);
ck('usage/empty window is safe',         AiOps::usage(7)['total']['calls'] === 13);

/* ══════════════════════════════════════════════════════════════════════
   4. Alerts fire, and — as importantly — stop firing
   ══════════════════════════════════════════════════════════════════════ */

$wipe();

/* Cold install: nothing has ever run. This is the loudest state the board has,
   because every feature depends on the scheduler and none of them will say so. */
$a = AiOps::alerts();
ck('alert/cold install warns the cron never ran', in_array('cron.never', $keys($a), true));
ck('alert/cold install is critical',
   ($a[0]['level'] ?? '') === 'crit' && ($a[0]['key'] ?? '') === 'cron.never');

/* A recent run silences it. */
AiOps::recordRun('accountability', true, ['skipped' => true]);
ck('alert/a recent run clears it', !in_array('cron.never', $keys(AiOps::alerts()), true));
ck('alert/and does not claim staleness', !in_array('cron.stale', $keys(AiOps::alerts()), true));

/* An old run raises the other half of the same alarm. Backdated directly,
   because the point is what the board says about a system that stopped. */
$db->exec('DELETE FROM av_job_runs');
$db->prepare('INSERT INTO av_job_runs (job,ok,skipped,ms,detail,error,created_at) VALUES (?,1,1,0,?,?,?)')
   ->execute(['accountability', '{}', '', gmdate('Y-m-d H:i:s', time() - 86400 * 3)]);
$a = AiOps::alerts();
ck('alert/a stale scheduler is critical', in_array('cron.stale', $keys($a), true));
ck('alert/stale says how long',           strpos($a[0]['title'], '3 days') !== false);

/* A failed rung is called out by name. */
AiOps::recordRun('agendas', false, null, 5, 'boom');
ck('alert/a failed rung alerts',     in_array('rung.agendas', $keys(AiOps::alerts()), true));
AiOps::recordRun('agendas', true, ['skipped' => true]);
ck('alert/a recovered rung clears',  !in_array('rung.agendas', $keys(AiOps::alerts()), true));

/* Provider health. Nine good calls and one bad is not an alert; the same
   provider failing four of ten is. */
$wipe();
AiOps::recordRun('accountability', true, ['skipped' => true]);
for ($i = 0; $i < 9; $i++) $call('groq', 'llama-x', 'bulk', true, 0, 10, 5, $i);
$call('groq', 'llama-x', 'bulk', false, 0, 1, 0, 1, 'blip');
ck('alert/one bad call in ten is not an alert',
   !in_array('provider.failing.groq', $keys(AiOps::alerts()), true));

$wipe();
AiOps::recordRun('accountability', true, ['skipped' => true]);
for ($i = 0; $i < 6; $i++) $call('groq', 'llama-x', 'bulk', true, 0, 10, 5, $i);
for ($i = 0; $i < 4; $i++) $call('groq', 'llama-x', 'bulk', false, 0, 1, 0, $i, 'model_not_found');
/* Healthy volume elsewhere, so the AGGREGATE rate stays under the threshold —
   this is the case a total-only check misses, and the usual real one: a single
   vendor retires a model id while everything else keeps working. */
for ($i = 0; $i < 40; $i++) $call('openai', 'gpt-x', 'reason', true, 0, 10, 5, $i);
$a = AiOps::alerts();
ck('alert/aggregate rate stays quiet',      !in_array('provider.failing', $keys($a), true));
ck('alert/one bad provider is still named', in_array('provider.failing.groq', $keys($a), true));
$one = null;
foreach ($a as $x) if ($x['key'] === 'provider.failing.groq') $one = $x;
ck('alert/names the failing provider',  $one && strpos($one['title'], 'Groq') !== false);
ck('alert/quotes the rate',             $one && strpos($one['title'], '40%') !== false);
ck('alert/names the model to check',    $one && strpos($one['body'], 'llama-x') !== false);

/* Everything falling back means the head of the routing rule is down —
   invisible to users by design, and the exact thing ops must be told. */
$wipe();
AiOps::recordRun('accountability', true, ['skipped' => true]);
for ($i = 0; $i < 12; $i++) $call('anthropic', 'claude-x', 'reason', true, 1, 10, 5, $i);
ck('alert/all-fallback is called out', in_array('provider.fallback', $keys(AiOps::alerts()), true));

$wipe();
AiOps::recordRun('accountability', true, ['skipped' => true]);
for ($i = 0; $i < 12; $i++) $call('openai', 'gpt-x', 'reason', true, 0, 10, 5, $i);
ck('alert/first-choice traffic is quiet', !in_array('provider.fallback', $keys(AiOps::alerts()), true));

/* ══════════════════════════════════════════════════════════════════════
   5. Pipeline counters, and the alerts they drive
   ══════════════════════════════════════════════════════════════════════ */

$wipe();
AiOps::recordRun('accountability', true, ['skipped' => true]);
$old = gmdate('Y-m-d H:i:s', time() - 86400 * 12);
$new = gmdate('Y-m-d H:i:s', time() - 3600);
for ($i = 1; $i <= 3; $i++) {
    $db->prepare("INSERT INTO av_agenda_drafts (meeting_id,items,note,source,status,created_at) VALUES (?,'[]','','ai','pending',?)")
       ->execute([$i, $old]);
}
$db->prepare("INSERT INTO av_agenda_drafts (meeting_id,items,note,source,status,created_at) VALUES (9,'[]','','ai','applied',?)")
   ->execute([$new]);

$p = AiOps::pipeline(7);
ck('pipeline/counts pending drafts',   $p['agendas']['pending'] === 3);
ck('pipeline/counts applied drafts',   $p['agendas']['applied'] === 1);
ck('pipeline/counts STALE separately', $p['agendas']['stale'] === 3);
ck('pipeline/window excludes the old', $p['agendas']['window'] === 1);
ck('alert/stale drafts are raised',    in_array('agenda.stale', $keys(AiOps::alerts()), true));

/* Two stale drafts is a quiet week, not a problem — the threshold has to hold
   or the board becomes noise and people stop reading it. */
$db->exec("DELETE FROM av_agenda_drafts WHERE meeting_id = 3");
ck('alert/two stale drafts stay quiet', !in_array('agenda.stale', $keys(AiOps::alerts()), true));

$db->prepare("INSERT INTO av_escalations (mentorship_id,session_id,step,reason,notified,created_at) VALUES (1,1,3,'x','[]',?)")
   ->execute([$new]);
$db->prepare("INSERT INTO av_escalations (mentorship_id,session_id,step,reason,notified,created_at) VALUES (2,2,1,'x','[]',?)")
   ->execute([$new]);
$p = AiOps::pipeline(7);
ck('pipeline/counts escalations',        $p['escalations']['window'] === 2);
ck('pipeline/counts only the top rung',  $p['escalations']['top_rung'] === 1);
$a = AiOps::alerts();
ck('alert/top of the ladder is a warning', in_array('escalation.top', $keys($a), true));
$top = null; foreach ($a as $x) if ($x['key'] === 'escalation.top') $top = $x;
ck('alert/singular reads correctly',       $top && strpos($top['title'], '1 pairing reached') === 0);

$db->prepare("INSERT INTO av_promotion_reviews (user_id,from_level,to_level,engine_ok,engine_reasons,engine_gaps,metrics,ai_ok,ai_confidence,ai_reasons,ai_gaps,agrees,source,status,created_at) VALUES (1,'O','A',1,'[]','[]','{}',0,50,'[]','[]',0,'ai','open',?)")
   ->execute([$new]);
$db->prepare("INSERT INTO av_promotion_reviews (user_id,from_level,to_level,engine_ok,engine_reasons,engine_gaps,metrics,ai_ok,ai_confidence,ai_reasons,ai_gaps,agrees,source,status,created_at) VALUES (2,'O','A',1,'[]','[]','{}',1,90,'[]','[]',1,'ai','open',?)")
   ->execute([$new]);
$p = AiOps::pipeline(7);
ck('pipeline/counts open reviews',    $p['promotions']['open'] === 2);
ck('pipeline/counts disagreements',   $p['promotions']['disagreed'] === 1);
ck('alert/a disagreement is surfaced', in_array('promotion.disagree', $keys(AiOps::alerts()), true));

/* ══════════════════════════════════════════════════════════════════════
   6. The snapshot the Studio actually fetches
   ══════════════════════════════════════════════════════════════════════ */

$snap = AiOps::snapshot(7);
foreach (['at', 'days', 'alerts', 'heartbeat', 'providers', 'routes', 'usage', 'pipeline', 'ai_enabled'] as $k) {
    ck('snapshot/has ' . $k, array_key_exists($k, $snap));
}
ck('snapshot/clamps a silly window',   AiOps::snapshot(9999)['days'] === 90 && AiOps::snapshot(-4)['days'] === 1);
ck('snapshot/routes cover every job',  count($snap['routes']) === count(AvRouter::JOBS));
ck('snapshot/a route shows declared and live',
   isset($snap['routes'][0]['declared']) && isset($snap['routes'][0]['live']));
ck('snapshot/heartbeat carries the grace window', ($snap['heartbeat']['grace_min'] ?? 0) > 0);

/* The renderer reads every one of these; a missing key shows as "undefined" on
   the board rather than as an error anywhere. */
$prov = $snap['providers'][0] ?? [];
foreach (['handle', 'label', 'native', 'configured', 'model', 'ranks'] as $k) {
    ck('snapshot/provider row has ' . $k, array_key_exists($k, $prov));
}
$pl = $snap['pipeline'];
foreach (['escalations', 'agendas', 'promotions', 'briefs', 'clock', 'commitments'] as $k) {
    ck('snapshot/pipeline has ' . $k, isset($pl[$k]) && is_array($pl[$k]));
}

/* ══════════════════════════════════════════════════════════════════════
   7. The board is management-only
   ══════════════════════════════════════════════════════════════════════ */

$api = (string) file_get_contents(AV_ROOT . '/admin/api.php');
$mgmt = '';
if (preg_match('/\$managementOnly = \[(.*?)\];/s', $api, $m)) $mgmt = $m[1];
ck('api/aiops is denied to editors',      strpos($mgmt, "'aiops'") !== false);
ck('api/aiops_cron is denied to editors', strpos($mgmt, "'aiops_cron'") !== false);
/* Running the tasks changes state — notifications go out, escalations are
   written. That must not be reachable with a GET. */
ck('api/running the tasks requires POST',
   preg_match("/case 'aiops_cron':.*?\\\$method !== 'POST'/s", $api) === 1);

/* ══════════════════════════════════════════════════════════════════════
   8. Every cron rung records a heartbeat — no rung may be added without one
   ══════════════════════════════════════════════════════════════════════ */

$cron = (string) file_get_contents(AV_ROOT . '/tasks/cron.php');
foreach (['Accountability::sweep', 'Agenda::sweep', 'MeetingClock::sweep', 'Promotion::sweep', 'Brief::generate'] as $call2) {
    /* Every call site must sit inside an AiOps::run() wrapper. A bare call is a
       feature that silently stops without the board ever noticing — which is
       precisely the failure this whole file is about.

       Checked by looking back from each call for the opening of a wrapper,
       rather than by matching the whole wrapper: the brief rung's closure has
       statements (and semicolons) between the two, so a single expression
       cannot span it — and a pattern that only worked for one-liners would
       silently stop protecting a rung the moment it grew a body. */
    $sites = [];
    $off = 0;
    while (($at = strpos($cron, $call2 . '(', $off)) !== false) { $sites[] = $at; $off = $at + 1; }
    ck('cron/' . $call2 . ' appears in cron at all', $sites !== []);
    $wrapped = $sites !== [];
    foreach ($sites as $at) {
        $before = substr($cron, max(0, $at - 500), min(500, $at));
        if (strpos($before, 'AiOps::run(') === false) $wrapped = false;
    }
    ck('cron/' . $call2 . ' is wrapped in a heartbeat', $wrapped);
}
$rungNames = [];
if (preg_match_all("/AiOps::run\('([a-z_]+)'/", $cron, $mm)) $rungNames = array_unique($mm[1]);
foreach ($rungNames as $rn) {
    ck('cron/rung "' . $rn . '" is one the board knows about', isset(AiOps::RUNGS[$rn]));
}
ck('cron/no bare sweep survives',
   preg_match('/^\s*(?:\$result\[[^\]]+\]\s*=\s*)?(?:Accountability|Agenda|MeetingClock|Promotion)::sweep\(\)/m', $cron) === 0);

$wipe();
