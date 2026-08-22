<?php
/**
 * tests/router.test.php — which model answers which job, and what got recorded.
 *
 * Two halves.
 *
 * The first is pure: routing order, rule parsing, the inventory, and the
 * history conversions each provider needs. No network, no keys.
 *
 * The second stands up a real HTTP server on loopback that speaks the
 * OpenAI /chat/completions dialect, points two support-tier presets at it, and
 * makes actual calls. That is the only way to test the thing that has broken
 * before in this codebase: what goes out on the wire and what comes back.
 * `aiwire.test.php` exists because three shipped defects were all invisible to
 * tests that stopped at the function boundary. A fallback chain and a token
 * ledger are exactly that kind of code — they look obviously correct and are
 * wrong in ways only a round trip shows.
 *
 * The server half skips itself cleanly if a port cannot be bound, so a locked
 * down CI box reports fewer assertions rather than a red suite.
 *
 * Run via tests/run.php (provides ck() and reset_users()).
 */
declare(strict_types=1);

/* ══════════════════════════════════════════════════════════════════════
   1. The shipped defaults ARE the stated policy
   ══════════════════════════════════════════════════════════════════════ */

/* This is the requirement written as an assertion. OpenAI leads the work that
   needs judgement; the cheap tier leads the volume work; Anthropic is second,
   not first, which is the thing that actually changed. A future edit that
   quietly reorders these has to come through here. */
$reason = AvRules::list('ai.route_reason');
$bulk   = AvRules::list('ai.route_bulk');
$tools  = AvRules::list('ai.route_tools');

ck('route/reason leads with openai',        ($reason[0] ?? '') === 'openai');
ck('route/reason has anthropic second',     ($reason[1] ?? '') === 'anthropic');
ck('route/bulk leads with groq',            ($bulk[0] ?? '') === 'groq');
ck('route/bulk puts anthropic last',        end($bulk) === 'anthropic');
ck('route/tools leads with openai',         ($tools[0] ?? '') === 'openai');
ck('route/tools excludes support tier',     !array_intersect($tools, ['groq', 'openrouter', 'together', 'deepseek', 'cerebras', 'local']));

/* ══════════════════════════════════════════════════════════════════════
   2. Order resolution — the declared list, filtered by reality
   ══════════════════════════════════════════════════════════════════════ */

$declared = static fn(string $job): array => AvRouter::order($job, false);

ck('order/declared reason matches the rule', $declared('reason') === $reason);
ck('order/unknown job falls back to reason', $declared('nonsense') === $declared('reason'));

/* A rule is a free-text box. A typo in it must narrow the list, never throw and
   never route to a provider this build cannot speak to. */
AvRules::save(['ai.route_bulk' => 'groq,notaprovider,gemini,groq'], 'test');
AvRules::invalidate();
ck('order/unknown handle dropped',   !in_array('notaprovider', $declared('bulk'), true));
ck('order/duplicate handle dropped', count(array_keys($declared('bulk'), 'groq', true)) === 1);
ck('order/survivors keep order',     $declared('bulk') === ['groq', 'gemini']);

/* Nothing in the test environment has an API key, so the configured-only view
   must be empty — and `available()` must agree with it rather than with the
   declared list. */
$anyConfigured = false;
foreach (AvRouter::known() as $h) { if (AvRouter::configured($h)) { $anyConfigured = true; break; } }
if (!$anyConfigured) {
    ck('order/nothing configured yields nothing', AvRouter::order('bulk') === []);
    ck('available() false with no keys',          AvRouter::available('bulk') === false);
    ck('AvAgent::provider() empty with no keys',  AvAgent::provider() === '');
    ck('AvAgent::available() false with no keys', AvAgent::available() === false);
}

AvRules::save(['ai.route_bulk' => 'groq,gemini,openai,anthropic'], 'test');
AvRules::invalidate();

/* ══════════════════════════════════════════════════════════════════════
   3. The inventory the ops board reads
   ══════════════════════════════════════════════════════════════════════ */

$inv = AvRouter::inventory();
$byHandle = [];
foreach ($inv as $row) $byHandle[$row['handle']] = $row;

ck('inventory covers every known provider', count($inv) === count(AvRouter::known()));
ck('inventory includes groq',               isset($byHandle['groq']));
ck('inventory marks natives native',        ($byHandle['openai']['native'] ?? null) === true);
ck('inventory marks presets non-native',    ($byHandle['groq']['native'] ?? null) === false);
ck('inventory reports a model per row',     ($byHandle['openai']['model'] ?? '') !== ''
                                         && ($byHandle['groq']['model'] ?? '') !== '');
/* Rank is 1-based and per job, so the board can say "primary" rather than "0". */
ck('inventory ranks openai first for reason', ($byHandle['openai']['ranks']['reason'] ?? 0) === 1);
ck('inventory ranks groq first for bulk',     ($byHandle['groq']['ranks']['bulk'] ?? 0) === 1);
ck('inventory gives groq no tools rank',      !isset($byHandle['groq']['ranks']['tools']));

/* ══════════════════════════════════════════════════════════════════════
   4. AiCompat presets — configuration detection
   ══════════════════════════════════════════════════════════════════════ */

ck('compat/knows groq',            AiCompat::knows('groq'));
ck('compat/rejects unknown',       !AiCompat::knows('definitely-not'));
ck('compat/unknown has no model',  AiCompat::model('definitely-not') === '');
ck('compat/unknown not configured', !AiCompat::configured('definitely-not'));
ck('compat/base has no trailing slash', substr(AiCompat::base('groq'), -1) !== '/');

putenv('AV_GROQ_MODEL=llama-test-42');
ck('compat/model override honoured', AiCompat::model('groq') === 'llama-test-42');
putenv('AV_GROQ_MODEL');
ck('compat/model default restored',  AiCompat::model('groq') !== 'llama-test-42');

/* 'local' takes no API key, so key-presence cannot be its readiness test. It has
   to be an explicit opt-in, or every deployment would report a loopback model
   server it does not have. */
ck('compat/local off by default', !AiCompat::configured('local'));
putenv('AV_LOCAL_MODEL=llama3.1');
ck('compat/local on once named',  AiCompat::configured('local'));
putenv('AV_LOCAL_MODEL');
ck('compat/local off again',      !AiCompat::configured('local'));

/* An unconfigured provider must fail as data, not as an exception, because the
   router calls this inside a fallback loop. */
$r = AiCompat::generate('groq', 'hello');
ck('compat/unconfigured returns ok=false', ($r['ok'] ?? true) === false);
ck('compat/unconfigured names itself',     strpos((string) $r['error'], 'Groq') !== false);
ck('compat/unconfigured still has usage',  ($r['usage']['in'] ?? null) === 0);

$r = AvRouter::callOne('definitely-not', 'hello');
ck('callOne/unknown provider is an error, not a throw', ($r['ok'] ?? true) === false
    && strpos((string) $r['error'], 'Unknown provider') !== false);

/* ══════════════════════════════════════════════════════════════════════
   5. History conversion — three wire formats, one input shape
   ══════════════════════════════════════════════════════════════════════ */

$hist = [
    ['role' => 'user',      'text' => 'first'],
    ['role' => 'assistant', 'text' => 'second'],
    ['role' => 'bot',       'text' => 'third'],     // an alias the codebase uses
    ['role' => 'user',      'text' => '   '],       // blank: dropped
    'not an array',                                  // junk: dropped
];

$m = OpenAi::historyMessages($hist);
ck('history/openai drops blanks and junk', count($m) === 3);
ck('history/openai maps user',      $m[0] === ['role' => 'user', 'content' => 'first']);
ck('history/openai maps assistant', $m[1]['role'] === 'assistant');
ck('history/openai maps bot alias', $m[2]['role'] === 'assistant');

$c = Gemini::historyContents($hist);
ck('history/gemini uses the model role', $c[1]['role'] === 'model');
ck('history/gemini keeps user as user',  $c[0]['role'] === 'user');
ck('history/gemini nests text in parts', ($c[0]['parts'][0]['text'] ?? '') === 'first');

/* A long thread must be bounded before it reaches a provider — this is the
   A-2 class of bug (unbounded prompt assembly) in a new place. */
$long = [];
for ($i = 0; $i < 120; $i++) $long[] = ['role' => 'user', 'text' => 'turn ' . $i];
ck('history/openai caps the thread', count(OpenAi::historyMessages($long)) === 40);
ck('history/gemini caps the thread', count(Gemini::historyContents($long)) === 40);
ck('history/keeps the NEWEST turns', OpenAi::historyMessages($long)[39]['content'] === 'turn 119');

ck('history/non-array input is safe', OpenAi::historyMessages('nope') === []
                                   && Gemini::historyContents(null) === []);

/* ══════════════════════════════════════════════════════════════════════
   6. The ledger, on its own terms
   ══════════════════════════════════════════════════════════════════════ */

AvRouter::ensure();
$pdo = Database::pdo();
$pdo->exec('DELETE FROM av_ai_calls');

$ledger = static function (): array {
    return Database::pdo()->query('SELECT * FROM av_ai_calls ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
};

/* A diagnostic that can break the thing it diagnoses is worse than none, so
   record() has to swallow anything. */
AvRouter::record([]);
AvRouter::record(['job' => str_repeat('x', 400), 'provider' => str_repeat('p', 400),
                  'error' => str_repeat('e', 4000), 'ms' => -5, 'in' => -1]);
$rows = $ledger();
ck('ledger/empty row accepted',     count($rows) === 2);
ck('ledger/job truncated',          strlen((string) $rows[1]['job']) === 16);
ck('ledger/provider truncated',     strlen((string) $rows[1]['provider']) === 32);
ck('ledger/error truncated',        strlen((string) $rows[1]['error']) === 300);
ck('ledger/negative ms floored',    (int) $rows[1]['ms'] === 0);
ck('ledger/negative tokens floored', (int) $rows[1]['tokens_in'] === 0);
ck('ledger/stamps a time',          (string) $rows[1]['created_at'] !== '');

$pdo->exec('DELETE FROM av_ai_calls');

/* ══════════════════════════════════════════════════════════════════════
   7. The wire — a real HTTP round trip against a local fake
   ══════════════════════════════════════════════════════════════════════ */

$srvDir  = sys_get_temp_dir() . '/av-router-srv-' . getmypid();
@mkdir($srvDir, 0777, true);
file_put_contents($srvDir . '/router.php', <<<'PHPSRV'
<?php
/* A minimal OpenAI-compatible /chat/completions endpoint.
   The requested MODEL selects the behaviour, so one server can play both a
   healthy provider and a broken one inside a single fallback chain. */
$raw  = (string) file_get_contents('php://input');
$body = json_decode($raw, true);
$model = (string) ($body['model'] ?? '');
header('Content-Type: application/json');

if (strpos($model, 'boom') !== false) {
    http_response_code(500);
    echo json_encode(['error' => ['message' => 'upstream exploded']]);
    exit;
}
if (strpos($model, 'empty') !== false) {
    echo json_encode(['choices' => [['message' => ['content' => '', 'refusal' => 'not allowed']]],
                      'usage' => ['prompt_tokens' => 3, 'completion_tokens' => 0]]);
    exit;
}
// Echo back enough of the request that the test can prove what was sent.
$msgs = $body['messages'] ?? [];
$sys  = '';
$turns = 0;
foreach ($msgs as $m) {
    if (($m['role'] ?? '') === 'system') { $sys = (string) ($m['content'] ?? ''); continue; }
    $turns++;
}
echo json_encode([
    'choices' => [['message' => ['content' => 'ANSWER model=' . $model . ' sys=' . $sys . ' turns=' . $turns]]],
    'usage'   => ['prompt_tokens' => 11, 'completion_tokens' => 7],
]);
PHPSRV);

/* Bind a port by asking the OS for a free one, then handing it to the server. */
$port = 0;
$probe = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
if ($probe) {
    $name = stream_socket_get_name($probe, false);
    $port = (int) substr((string) $name, strrpos((string) $name, ':') + 1);
    fclose($probe);
}

$php  = PHP_BINARY ?: 'php';
$proc = null;
if ($port > 0) {
    $desc = [0 => ['pipe', 'r'], 1 => ['file', $srvDir . '/out.log', 'a'], 2 => ['file', $srvDir . '/err.log', 'a']];
    $proc = @proc_open(
        escapeshellarg($php) . ' -S 127.0.0.1:' . $port . ' -t ' . escapeshellarg($srvDir) . ' ' . escapeshellarg($srvDir . '/router.php'),
        $desc, $pipes
    );
}

/* Wait for it to accept connections rather than sleeping a fixed amount. */
$up = false;
if (is_resource($proc)) {
    for ($i = 0; $i < 100; $i++) {
        $c = @fsockopen('127.0.0.1', $port, $e1, $e2, 0.2);
        if ($c) { fclose($c); $up = true; break; }
        usleep(50000);
    }
}

if (!$up) {
    echo "  (skipped: could not start a loopback HTTP server)\n";
} else {
    $base = 'http://127.0.0.1:' . $port;

    // Two support-tier presets pointed at the same fake, distinguished by model.
    putenv('GROQ_API_KEY=test-key');
    putenv('AV_GROQ_BASE_URL=' . $base);
    putenv('CEREBRAS_API_KEY=test-key');
    putenv('AV_CEREBRAS_BASE_URL=' . $base);

    /* ── 7a. one successful call, end to end ── */
    putenv('AV_GROQ_MODEL=good-1');
    AvRules::save(['ai.route_bulk' => 'groq,cerebras'], 'test');
    AvRules::invalidate();

    ck('wire/groq now reports configured', AvRouter::configured('groq'));
    ck('wire/bulk order is groq then cerebras', AvRouter::order('bulk') === ['groq', 'cerebras']);

    $pdo->exec('DELETE FROM av_ai_calls');
    $res = AvRouter::complete('bulk', 'Say something.', ['system' => 'BE-TERSE', 'actor' => 'unit']);

    ck('wire/call succeeds',        ($res['ok'] ?? false) === true);
    ck('wire/answer comes back',    strpos((string) $res['text'], 'ANSWER model=good-1') === 0);
    ck('wire/system prompt sent',   strpos((string) $res['text'], 'sys=BE-TERSE') !== false);
    ck('wire/one turn sent',        strpos((string) $res['text'], 'turns=1') !== false);
    ck('wire/names the provider',   ($res['provider'] ?? '') === 'groq');
    ck('wire/reports the model',    ($res['model'] ?? '') === 'good-1');
    ck('wire/tried just the first', $res['tried'] === ['groq']);

    $rows = $ledger();
    ck('ledger/one row per attempt',   count($rows) === 1);
    ck('ledger/records the provider',  (string) $rows[0]['provider'] === 'groq');
    ck('ledger/records the model',     (string) $rows[0]['model'] === 'good-1');
    ck('ledger/records the job',       (string) $rows[0]['job'] === 'bulk');
    ck('ledger/records the actor',     (string) $rows[0]['actor'] === 'unit');
    ck('ledger/records success',       (int) $rows[0]['ok'] === 1);
    ck('ledger/records input tokens',  (int) $rows[0]['tokens_in'] === 11);
    ck('ledger/records output tokens', (int) $rows[0]['tokens_out'] === 7);
    ck('ledger/records latency',       (int) $rows[0]['ms'] >= 0);
    ck('ledger/first attempt is depth 0', (int) $rows[0]['depth'] === 0);

    /* ── 7b. history actually reaches the provider ── */
    $res = AvRouter::complete('bulk', 'And now?', [
        'system'  => 'S',
        'history' => [['role' => 'user', 'text' => 'a'], ['role' => 'assistant', 'text' => 'b']],
    ]);
    ck('wire/history is sent as turns', strpos((string) $res['text'], 'turns=3') !== false);

    /* ── 7c. the first provider fails; the second answers ── */
    putenv('AV_GROQ_MODEL=boom-1');
    putenv('AV_CEREBRAS_MODEL=good-2');
    $pdo->exec('DELETE FROM av_ai_calls');
    $res = AvRouter::complete('bulk', 'Say something.', ['actor' => 'unit']);

    ck('fallback/second provider answers', ($res['ok'] ?? false) === true);
    ck('fallback/names the one that won',  ($res['provider'] ?? '') === 'cerebras');
    ck('fallback/reports both attempts',   $res['tried'] === ['groq', 'cerebras']);

    $rows = $ledger();
    ck('fallback/both attempts recorded',  count($rows) === 2);
    ck('fallback/failure recorded as ok=0', (int) $rows[0]['ok'] === 0);
    ck('fallback/failure keeps its error', strpos((string) $rows[0]['error'], 'exploded') !== false);
    ck('fallback/depth increments',        (int) $rows[0]['depth'] === 0 && (int) $rows[1]['depth'] === 1);
    ck('fallback/success recorded second', (int) $rows[1]['ok'] === 1);

    /* ── 7d. fallback switched off means the failure IS the answer ──
       The point of the switch is diagnosing one provider without another
       silently covering for it, so it has to stop after the first. */
    AvRules::save(['ai.fallback' => '0'], 'test');
    AvRules::invalidate();
    $pdo->exec('DELETE FROM av_ai_calls');
    $res = AvRouter::complete('bulk', 'Say something.');

    ck('nofallback/call fails',        ($res['ok'] ?? true) === false);
    ck('nofallback/only one attempt',  $res['tried'] === ['groq']);
    ck('nofallback/only one ledger row', count($ledger()) === 1);
    ck('nofallback/error names the provider', strpos((string) $res['error'], 'Groq') !== false);

    AvRules::save(['ai.fallback' => '1'], 'test');
    AvRules::invalidate();

    /* ── 7e. a refusal is a failure, and still costs input tokens ── */
    putenv('AV_GROQ_MODEL=empty-1');
    putenv('AV_CEREBRAS_MODEL=boom-2');
    $pdo->exec('DELETE FROM av_ai_calls');
    $res = AvRouter::complete('bulk', 'Say something.');
    $rows = $ledger();

    ck('refusal/reported as failure',   ($res['ok'] ?? true) === false);
    ck('refusal/surfaces the reason',   strpos((string) $res['error'], 'not allowed') !== false);
    ck('refusal/tokens still recorded', (int) $rows[0]['tokens_in'] === 3);
    ck('refusal/both providers tried',  count($rows) === 2);
    ck('all-fail/error names both',     strpos((string) $res['error'], 'Groq') !== false
                                     && strpos((string) $res['error'], 'Cerebras') !== false);

    /* ── 7f. the master switch stops the call before the wire ── */
    putenv('AV_GROQ_MODEL=good-1');
    AvRules::save(['ai.enabled' => '0'], 'test');
    AvRules::invalidate();
    $pdo->exec('DELETE FROM av_ai_calls');
    $res = AvRouter::complete('bulk', 'Say something.');

    ck('killswitch/call refused',      ($res['ok'] ?? true) === false);
    ck('killswitch/nothing attempted', $res['tried'] === []);
    ck('killswitch/nothing recorded',  count($ledger()) === 0);
    ck('killswitch/available() false', AvRouter::available('bulk') === false);

    AvRules::save(['ai.enabled' => '1'], 'test');
    AvRules::invalidate();

    /* ── 7g. AvAgent::complete() reaches the same wire, with a job class ── */
    $pdo->exec('DELETE FROM av_ai_calls');
    $res = AvAgent::complete('SYS', 'Question?', ['job' => 'bulk', 'actor' => 'agent-test']);
    $rows = $ledger();
    ck('agent/complete routes through the router', ($res['ok'] ?? false) === true);
    ck('agent/complete keeps the old return shape',
        array_key_exists('ok', $res) && array_key_exists('text', $res)
        && array_key_exists('provider', $res) && array_key_exists('error', $res));
    ck('agent/complete honours the job class', (string) ($rows[0]['job'] ?? '') === 'bulk');
    ck('agent/complete passes the actor',      (string) ($rows[0]['actor'] ?? '') === 'agent-test');

    /* ── 7h. a completion-only provider must never be chosen for the tool loop ──
       The routing rule is a text box. If someone types groq into ai.route_tools,
       AvAgent must not hand a completion-only endpoint to a branch written for
       Anthropic's tool_use blocks. */
    AvRules::save(['ai.route_tools' => 'groq,cerebras'], 'test');
    AvRules::invalidate();
    ck('tools/router still offers what the rule said', AvRouter::order('tools') === ['groq', 'cerebras']);
    ck('tools/AvAgent refuses a completion-only provider',
        !in_array(AvAgent::provider(), ['groq', 'cerebras'], true));

    AvRules::save(['ai.route_tools' => 'openai,anthropic,gemini'], 'test');
    AvRules::invalidate();

    /* ── 7i. AV_AGENT_PROVIDER still wins, and keeps the declared fallbacks ── */
    AvRules::save(['ai.route_bulk' => 'groq,cerebras'], 'test');
    AvRules::invalidate();
    putenv('AV_AGENT_PROVIDER=cerebras');
    ck('forced/named provider goes first', AvRouter::order('bulk') === ['cerebras', 'groq']);
    putenv('AV_AGENT_PROVIDER=notreal');
    ck('forced/unknown name is ignored',   AvRouter::order('bulk') === ['groq', 'cerebras']);
    putenv('AV_AGENT_PROVIDER');

    /* ── teardown ── */
    foreach (['GROQ_API_KEY', 'AV_GROQ_BASE_URL', 'AV_GROQ_MODEL',
              'CEREBRAS_API_KEY', 'AV_CEREBRAS_BASE_URL', 'AV_CEREBRAS_MODEL'] as $k) putenv($k);
    proc_terminate($proc);
    proc_close($proc);
}

@unlink($srvDir . '/router.php');
@unlink($srvDir . '/out.log');
@unlink($srvDir . '/err.log');
@rmdir($srvDir);

AvRules::save(['ai.route_bulk' => 'groq,gemini,openai,anthropic'], 'test');
AvRules::invalidate();
$pdo->exec('DELETE FROM av_ai_calls');
