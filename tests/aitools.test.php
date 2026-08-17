<?php
/**
 * tests/aitools.test.php — the AI's tools, its web access, and the gate between
 * what it suggests and what actually changes.
 *
 * No provider is configured in the suite, so nothing reaches a model or a search
 * vendor. What is pinned here is the part that must hold whether or not a model
 * is behaving well:
 *
 *   1. A tool the caller has no tier for cannot be run.
 *   2. A proposal changes NOTHING until a human approves it — this is the whole
 *      safety property of letting the AI improve itself.
 *   3. An invalid proposal is refused when it is filed, with the acceptable
 *      range, rather than blowing up at approval time.
 *   4. No tool returns a member's email address.
 *   5. Web fetching refuses private, loopback, link-local and reserved
 *      addresses — including the cloud metadata endpoint and bracketed IPv6.
 *
 * Run via tests/run.php (provides ck() and reset_users()).
 */
declare(strict_types=1);

foreach (['AvRules', 'AvKnowledge', 'AvPrompts', 'AvTools', 'AvWeb', 'AvAgent', 'AvLab'] as $c) {
    require_once AV_ROOT . "/lib/$c.php";
}

/** Rules back to defaults, proposal queue empty. */
$aireset = static function (): void {
    AvRules::ensure(); AvRules::resetAll('test');
    AvTools::ensure();
    try { Database::pdo()->exec('DELETE FROM av_proposals'); } catch (Throwable $e) {}
};

reset_users();
$aireset();

/* ---- The registry ---- */
ck('tools: read tier is offered', in_array('member_lookup', AvTools::available(['read']), true));
ck('tools: propose tier is offered', in_array('propose_rule', AvTools::available(['propose']), true));
// 'propose' implies 'read' — a model that can suggest a change must be able to
// look at what it is changing first.
ck('tools: propose implies read', in_array('rules_read', AvTools::available(['propose']), true));
ck('tools: read alone excludes propose', !in_array('propose_rule', AvTools::available(['read']), true));
ck('tools: specs carry a schema', !empty(AvTools::specs(['member_lookup'])[0]['input_schema']));
ck('tools: unknown names are dropped from specs', AvTools::specs(['no_such_tool']) === []);
ck('tools: describe covers every tool', count(AvTools::describe()) >= 13);

/* ---- Tier enforcement is in the runner, not just the offer list ---- */
$deny = AvTools::run('propose_rule', ['key' => 'mentorship.cadence_days', 'value' => '9', 'rationale' => 'x'], ['tiers' => ['read']]);
ck('tools: propose refused without the tier', isset($deny['error']));
$denyWeb = AvTools::run('web_fetch', ['url' => 'https://example.com'], ['tiers' => ['read']]);
ck('tools: web refused without the tier', isset($denyWeb['error']));
ck('tools: unknown tool refused', isset(AvTools::run('nope', [], ['tiers' => ['read']])['error']));

/* ---- Read tools return fact, and no contact details ---- */
$look = AvTools::run('member_lookup', ['query' => 'Ada'], ['tiers' => ['read']]);
ck('tools: member_lookup finds a member', ($look['count'] ?? 0) >= 1);
// The suite's fixture users are a@x.co / b@x.co / c@x.co.
ck('tools: member_lookup leaks no email', strpos(json_encode($look), '@x.co') === false);
ck('tools: member_lookup needs a query', isset(AvTools::run('member_lookup', ['query' => ''], ['tiers' => ['read']])['error']));
ck('tools: mentorship_status needs a user_id', isset(AvTools::run('mentorship_status', [], ['tiers' => ['read']])['error']));

$rules = AvTools::run('rules_read', ['group' => 'Levels'], ['tiers' => ['read']]);
ck('tools: rules_read returns rules', !empty($rules['rules']));
ck('tools: rules_read filters by group', count(array_filter($rules['rules'], fn($r) => $r['group'] !== 'Levels')) === 0);
// A rule nothing enforces yet must say so, or the model reasons about a
// threshold that changes nothing.
$hasEnforcedFlag = true;
foreach ($rules['rules'] as $r) if (!array_key_exists('enforced', $r)) $hasEnforcedFlag = false;
ck('tools: rules_read marks what is enforced', $hasEnforcedFlag);

$pr = AvTools::run('prompt_read', ['key' => 'meeting.minutes'], ['tiers' => ['read']]);
ck('tools: prompt_read returns a template', ($pr['key'] ?? '') === 'meeting.minutes');
$prBad = AvTools::run('prompt_read', ['key' => 'nope.nope'], ['tiers' => ['read']]);
ck('tools: prompt_read lists options when the key is wrong', isset($prBad['error']) && !empty($prBad['available']));

/* ---- Proposals: the approval gate ---- */
$aireset();
$before = AvRules::int('mentorship.cadence_days');
$filed = AvTools::run('propose_rule',
    ['key' => 'mentorship.cadence_days', 'value' => '9', 'rationale' => 'Testing the gate.'],
    ['tiers' => ['read', 'propose'], 'actor' => 'test']);
ck('proposals: filing succeeds', !empty($filed['filed']) && ($filed['proposal_id'] ?? 0) > 0);
ck('proposals: filing states it has NOT taken effect', strpos((string) ($filed['note'] ?? ''), 'NOT taken effect') !== false);
// The safety property: nothing changed.
ck('proposals: the rule is untouched before approval', AvRules::int('mentorship.cadence_days') === $before);
ck('proposals: it shows as pending', AvTools::pendingCount() === 1);

$pid = (int) $filed['proposal_id'];
$ap = AvTools::approve($pid, 'admin-test');
ck('proposals: approval applies the change', !empty($ap['ok']) && AvRules::int('mentorship.cadence_days') === 9);
ck('proposals: nothing is left pending', AvTools::pendingCount() === 0);
ck('proposals: approving twice is refused', empty(AvTools::approve($pid, 'admin-test')['ok']));
ck('proposals: approving an unknown id is refused', empty(AvTools::approve(999999, 'admin-test')['ok']));

/* ---- A rejected proposal never applies ---- */
$aireset();
$f2 = AvTools::run('propose_rule',
    ['key' => 'mentorship.inactive_days', 'value' => '40', 'rationale' => 'Should not land.'],
    ['tiers' => ['read', 'propose'], 'actor' => 'test']);
ck('proposals: reject succeeds', AvTools::reject((int) $f2['proposal_id'], 'admin-test', 'Not now.'));
ck('proposals: a rejected rule never applied', AvRules::int('mentorship.inactive_days') === 21);
ck('proposals: rejected leaves nothing pending', AvTools::pendingCount() === 0);
ck('proposals: a rejected one cannot then be approved', empty(AvTools::approve((int) $f2['proposal_id'], 'admin-test')['ok']));

/* ---- Validation happens at file time, so the model learns immediately ---- */
$aireset();
$bad = AvTools::run('propose_rule',
    ['key' => 'mentorship.cadence_days', 'value' => '900', 'rationale' => 'Out of range.'],
    ['tiers' => ['read', 'propose']]);
ck('proposals: an out-of-range value is refused at file time', isset($bad['error']));
ck('proposals: the refusal states what is acceptable', strpos((string) $bad['error'], '90') !== false);
ck('proposals: nothing was queued', AvTools::pendingCount() === 0);

$noWhy = AvTools::run('propose_knowledge',
    ['title' => 'X', 'body' => 'Y', 'rationale' => ''],
    ['tiers' => ['read', 'propose']]);
ck('proposals: a rationale is required', isset($noWhy['error']));

$badScope = AvTools::run('propose_knowledge',
    ['title' => 'X', 'body' => 'Y', 'scope' => 'nonsense', 'rationale' => 'why'],
    ['tiers' => ['read', 'propose']]);
ck('proposals: an unknown knowledge scope is refused', isset($badScope['error']));

// A prompt rewrite that drops a declared placeholder must be refused — it would
// silently produce a prompt with no input.
$badPrompt = AvTools::run('propose_prompt',
    ['key' => 'goal.tasks', 'text' => 'Just do something useful.', 'rationale' => 'shorter'],
    ['tiers' => ['read', 'propose']]);
ck('proposals: a prompt dropping its placeholders is refused', isset($badPrompt['error']));

$okPrompt = AvTools::run('propose_prompt',
    ['key' => 'goal.tasks', 'text' => 'Return up to {{max}} tasks. Today is {{today}}. JSON array only.', 'rationale' => 'tighter'],
    ['tiers' => ['read', 'propose'], 'actor' => 'test']);
ck('proposals: a valid prompt rewrite is accepted', !empty($okPrompt['filed']));
$defaultBefore = AvPrompts::template('goal.tasks');
ck('proposals: the prompt is unchanged while pending', AvPrompts::template('goal.tasks') === $defaultBefore);
AvTools::approve((int) $okPrompt['proposal_id'], 'admin-test');
ck('proposals: approval applies the prompt', strpos(AvPrompts::template('goal.tasks'), 'Return up to') === 0);
AvPrompts::reset('goal.tasks');
$aireset();

/* ---- A knowledge proposal round trip ---- */
$kf = AvTools::run('propose_knowledge',
    ['title' => 'Test doctrine', 'body' => 'A fact the assistant should know.', 'scope' => 'mentorship', 'priority' => 42, 'rationale' => 'Testing.'],
    ['tiers' => ['read', 'propose'], 'actor' => 'test']);
ck('proposals: knowledge can be proposed', !empty($kf['filed']));
$kbBefore = count(AvKnowledge::listAll());
ck('proposals: doctrine is not added while pending', count(AvKnowledge::listAll()) === $kbBefore);
ck('proposals: approving adds the entry', !empty(AvTools::approve((int) $kf['proposal_id'], 'admin-test')['ok'])
    && count(AvKnowledge::listAll()) === $kbBefore + 1);

/* ---- Agent tiers follow the rules ---- */
$aireset();
$t = AvAgent::tiersFor('admin');
ck('agent: read is granted by default', in_array('read', $t, true));
// Web ships off, because fetching needs no API key and would otherwise be live.
ck('agent: web is NOT granted by default', !in_array('web', $t, true));
ck('agent: propose is granted to an admin', in_array('propose', $t, true));
// A member-facing assistant has no business suggesting constitutional changes.
ck('agent: propose is withheld outside admin', !in_array('propose', AvAgent::tiersFor('member'), true));

AvRules::save(['ai.web_access' => '1'], 'test');
ck('agent: web is granted once switched on', in_array('web', AvAgent::tiersFor('admin'), true));
AvRules::save(['ai.self_improve' => '0'], 'test');
ck('agent: propose is withdrawn when self-improve is off', !in_array('propose', AvAgent::tiersFor('admin'), true));
$aireset();
AvRules::save(['ai.tools' => '0'], 'test');
ck('agent: the tools switch removes every tier', AvAgent::tiersFor('admin') === []);
$aireset();

/* ---- Web access is gated, and fetching refuses the private network ---- */
ck('web: search is unavailable with no key', !AvWeb::available('web_search'));
ck('web: fetch is unavailable while the rule is off', !AvWeb::available('web_fetch'));
ck('web: the reason names the rule', strpos(AvWeb::whyUnavailable('web_fetch'), 'switched off') !== false);
ck('web: fetch refuses while the rule is off', isset(AvWeb::fetch('https://example.com')['error']));

AvRules::save(['ai.web_access' => '1'], 'test');
ck('web: fetch becomes available once switched on', AvWeb::available('web_fetch'));
ck('web: search still needs a provider key', !AvWeb::available('web_search'));
ck('web: search reports why it cannot run', isset(AvWeb::search('anything')['error']));

// The SSRF surface. Each of these must be refused BEFORE any request is made.
foreach ([
    'file:///etc/passwd'                       => 'a non-http scheme',
    'gopher://example.com/'                    => 'an exotic scheme',
    'http://127.0.0.1/'                        => 'loopback',
    'http://localhost/'                        => 'localhost',
    'http://169.254.169.254/latest/meta-data/' => 'the cloud metadata address',
    'http://10.0.0.5/'                         => 'a private range',
    'http://192.168.1.1/'                      => 'a home network range',
    'http://[::1]/'                            => 'bracketed IPv6 loopback',
    'http://user:pw@example.com/'              => 'embedded credentials',
    'http://example.com:22/'                   => 'a disallowed port',
    'not-a-url'                                => 'a malformed URL',
] as $url => $label) {
    ck('web: refuses ' . $label, isset(AvWeb::fetch($url)['error']));
}
$aireset();

/* ---- The bench ---- */
$caps = AvLab::capabilities();
ck('lab: capabilities are listed', count($caps) >= 6);
$keys = array_column($caps, 'key');
foreach (['meeting.minutes', 'session.minutes', 'goal.tasks', 'assistant.console', 'knowledge.distil', 'tool'] as $k) {
    ck('lab: covers ' . $k, in_array($k, $keys, true));
}
// With no provider configured, every model-backed capability must say so rather
// than failing obscurely when run.
$modelBacked = array_filter($caps, fn($c) => $c['key'] !== 'tool');
$allExplain = true;
foreach ($modelBacked as $c) if ($c['ready'] === '') $allExplain = false;
ck('lab: model capabilities explain why they cannot run', $allExplain);
ck('lab: running a single tool needs no provider', AvLab::capabilities()[5]['ready'] === '');

$run = AvLab::run('tool', ['tool' => 'org_stats', 'args' => []], 'test');
ck('lab: a tool run succeeds', !empty($run['ok']));
ck('lab: the run is timed', ($run['ms'] ?? -1) >= 0);
ck('lab: the run records its step', count($run['steps']) === 1);
ck('lab: an unknown capability is refused', !empty(AvLab::run('nope', [], 'test')['error']));
ck('lab: an unknown tool is refused', !empty(AvLab::run('tool', ['tool' => 'nope'], 'test')['error']));

$aireset();
reset_users();
