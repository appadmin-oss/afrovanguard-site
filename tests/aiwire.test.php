<?php
/**
 * tests/aiwire.test.php — the AI layer's plumbing, as opposed to its policy.
 *
 * `aitools.test.php` covers what the AI is ALLOWED to do: tiers, the read/propose
 * split, the SSRF filter, the proposal lifecycle. All of it passed while three
 * separate defects made the features unusable, because none of it looks at what
 * actually goes out on the wire.
 *
 * This file covers that. Three bug classes, each of which shipped:
 *
 *   1. A rule key read in code and declared in no registry. `AvRules::int()`
 *      returns 0 for an unknown key, every call site then applies a max() floor,
 *      and the request SUCCEEDS — at 256 tokens instead of 2048. Nothing throws,
 *      nothing logs. `ai.max_tokens` and `meetings.transcript_char_limit` were
 *      both in this state.
 *
 *   2. A byte-wise cut through multi-byte UTF-8. `json_encode()` returns false on
 *      malformed UTF-8, and that false went into CURLOPT_POSTFIELDS unchecked, so
 *      the provider got an empty body. One curly quote on a fetched page did it.
 *
 *   3. Truncating a composed prompt from the end, where the question is.
 *
 * All three are testable with no provider and no network, which is the point:
 * the reason they survived is that nobody tried.
 *
 * Run via tests/run.php (provides ck() and reset_users()).
 */
declare(strict_types=1);

/* ══ 1. Every rule key the code reads is actually declared ═══════════════ */

/**
 * Walk the app's PHP source for AvRules::int|bool|str|list|get('some.key') and
 * assert each key resolves. An undeclared key returns null from get(), which
 * int() then casts to 0 — silently, at every call site.
 */
$ruleKeys = [];
$skipDir = ['vendor', 'tests', 'node_modules', '.git'];
$it = new RecursiveIteratorIterator(
    new RecursiveCallbackFilterIterator(
        new RecursiveDirectoryIterator(AV_ROOT, FilesystemIterator::SKIP_DOTS),
        static function ($f) use ($skipDir) {
            return $f->isDir() ? !in_array($f->getFilename(), $skipDir, true)
                               : substr($f->getFilename(), -4) === '.php';
        }
    )
);
$scanned = 0;
foreach ($it as $f) {
    $scanned++;
    $src = (string) @file_get_contents($f->getPathname());
    if (preg_match_all("~AvRules::(?:int|bool|str|list|get)\(\s*'([a-z0-9_.]+)'~", $src, $m)) {
        foreach ($m[1] as $k) $ruleKeys[$k] = ($ruleKeys[$k] ?? '') ?: $f->getFilename();
    }
}
ck('rules: the source scan actually found files', $scanned > 50);
ck('rules: and found rule keys to check', count($ruleKeys) >= 20);

$undeclared = [];
foreach ($ruleKeys as $k => $where) {
    if (AvRules::get($k) === null) $undeclared[] = $k . ' (' . $where . ')';
}
ck('rules: every key read in code is declared in DEFS' . ($undeclared ? ' — MISSING: ' . implode(', ', $undeclared) : ''),
   $undeclared === []);

// The two that were missing, pinned by name and by value so a later edit that
// removes or mis-bounds them fails here rather than in production.
ck('rules: ai.max_tokens is declared', AvRules::get('ai.max_tokens') !== null);
ck('rules: and defaults to something usable, not the 256 floor', AvRules::int('ai.max_tokens') >= 1024);
ck('rules: meetings.transcript_char_limit is declared', AvRules::get('meetings.transcript_char_limit') !== null);
ck('rules: and is a whole transcript, not a fragment', AvRules::int('meetings.transcript_char_limit') >= 10000);

// The registry's bounds are what make the max()/min() clamps at the call sites
// redundant rather than load-bearing.
$byKey = [];
foreach ((array) (AvRules::describe()['groups'] ?? []) as $rows) {
    foreach ($rows as $d) $byKey[$d['key']] = $d;
}
ck('rules: describe() exposes the registry', isset($byKey['ai.enabled']));
ck('rules: ai.max_tokens is bounded below the agent clamp', ($byKey['ai.max_tokens']['min'] ?? 0) >= 256);
ck('rules: ai.max_tokens is bounded above it too', ($byKey['ai.max_tokens']['max'] ?? 0) <= 8192);

// Both feed AI paths, so both must be enforced (not 'pending' placeholders).
ck('rules: ai.max_tokens is enforced, not pending', ($byKey['ai.max_tokens']['pending'] ?? '') === '');
ck('rules: transcript_char_limit is enforced, not pending', ($byKey['meetings.transcript_char_limit']['pending'] ?? '') === '');


/* ══ 2. A bounded tool result stays encodable ════════════════════════════ */

// A fetched page is arbitrary text; non-ASCII is the norm, not the exception.
// 9,000 two-byte characters puts a character boundary either side of the cap.
$huge = ['text' => str_repeat('é', 9000)];
$bound = AvAgent::boundResult($huge);

ck('wire: an oversized tool result is truncated', strlen($bound) < strlen(json_encode($huge, JSON_UNESCAPED_UNICODE)));
ck('wire: and says so', strpos($bound, '[truncated]') !== false);
ck('wire: the truncated result is still valid UTF-8', mb_check_encoding($bound, 'UTF-8'));

// The real failure was one step later: the damaged string went into a payload,
// and json_encode() refused the WHOLE payload, returning false.
$payload = ['messages' => [['role' => 'user', 'content' => [
    ['type' => 'tool_result', 'tool_use_id' => 'toolu_x', 'content' => $bound],
]]]];
ck('wire: a payload carrying it encodes', is_string(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)));

// Negative control. Without this the test above could pass for the wrong reason
// (e.g. if the cap stopped being reached), so prove the old cut really did break
// and that this fixture reaches the boundary.
$oldWay = substr(json_encode($huge, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 0, 16000);
ck('wire: the fixture does straddle a character boundary', !mb_check_encoding($oldWay, 'UTF-8'));

// Small results pass through untouched and stay parseable.
$small = AvAgent::boundResult(['ok' => true, 'name' => 'Adaeze Okonkwo', 'note' => 'café — naïve']);
ck('wire: a small result is not truncated', strpos($small, '[truncated]') === false);
ck('wire: and is still real JSON', is_array(json_decode($small, true)));
ck('wire: an unserialisable result degrades to an error object',
   is_array(json_decode(AvAgent::boundResult(['bad' => "\xB1\x31"]), true)));

// The clients must refuse an unencodable payload rather than hand false to cURL.
// Comments are stripped first: each one explains the bug being prevented, and
// matching that text would make these pass for the wrong reason.
foreach (['AvBot', 'Gemini', 'OpenAi'] as $cls) {
    $src = (string) preg_replace('~^\s*//.*$~m', '', (string) file_get_contents(AV_ROOT . '/lib/' . $cls . '.php'));
    ck("wire: {$cls} checks json_encode before the request", strpos($src, 'if (!is_string($json))') !== false);
    ck("wire: {$cls} never passes a raw json_encode to POSTFIELDS",
       strpos($src, 'CURLOPT_POSTFIELDS     => json_encode(') === false);
}


/* ══ 3. The question survives a long thread ══════════════════════════════ */

$question = 'WHAT IS THE LCASP PROGRAMME?';

// The documented worst case: 40 turns at the 1200-char per-turn cap = 48,000
// characters, against a 12,000-character prompt budget.
$long = [];
for ($i = 0; $i < 40; $i++) $long[] = ['role' => 'member', 'name' => 'M' . $i, 'text' => str_repeat('a', 1200)];
$p = AvBot::composePrompt($question, $long);

ck('prompt: the member\'s question survives a full thread', strpos($p, $question) !== false);
ck('prompt: and so does the instruction to answer it', strpos($p, 'Reply to the latest message') !== false);
ck('prompt: the whole thing stays inside the budget', mb_strlen($p) <= AvBot::MAX_PROMPT_CHARS);
ck('prompt: some thread context still gets through', strpos($p, 'Here is the community thread so far') !== false);

// It must drop the OLDEST turns, not the newest — recency is what the reply
// needs. The newest turn is the one immediately before the question.
$mixed = [];
for ($i = 0; $i < 40; $i++) $mixed[] = ['role' => 'member', 'name' => 'M' . $i, 'text' => 'OLDEST' . $i . str_repeat('b', 1190)];
$mixed[] = ['role' => 'member', 'name' => 'Recent', 'text' => 'THE-NEWEST-TURN'];
$p2 = AvBot::composePrompt($question, $mixed);
ck('prompt: the newest turn is kept', strpos($p2, 'THE-NEWEST-TURN') !== false);
ck('prompt: the oldest turn is the one dropped', strpos($p2, 'OLDEST0') === false);
ck('prompt: the question still survives that too', strpos($p2, $question) !== false);

// An oversized question is capped to its reserve — never dropped to make room
// for thread. It is still reserved FIRST, so the thread shrinks around it.
$p3 = AvBot::composePrompt(str_repeat('q', 6000), $long);
ck('prompt: an oversized question is capped to its reserve',
   strpos($p3, str_repeat('q', AvBot::MAX_QUESTION_CHARS)) !== false);
ck('prompt: and capped, not merely truncated somewhere arbitrary',
   strpos($p3, str_repeat('q', AvBot::MAX_QUESTION_CHARS + 1)) === false);
ck('prompt: the thread shrinks around it instead', strpos($p3, 'Here is the community thread so far') !== false);
ck('prompt: and the total still fits', mb_strlen($p3) <= AvBot::MAX_PROMPT_CHARS);

// With the current constants a 4,000-char question still leaves ~7,900 for
// thread, so the "no room for any thread at all" branch is unreachable — it is
// defensive, for a future where the reserve grows. Assert the invariant that
// actually holds instead of a branch that cannot fire: whatever the question's
// length, the question is never the part that gets dropped.
foreach ([1, 500, 4000, 6000, 20000] as $qlen) {
    $out = AvBot::composePrompt(str_repeat('z', $qlen), $long);
    $kept = min($qlen, AvBot::MAX_QUESTION_CHARS);
    ck("prompt: a {$qlen}-char question survives intact", strpos($out, str_repeat('z', $kept)) !== false);
    ck("prompt: a {$qlen}-char question stays within budget", mb_strlen($out) <= AvBot::MAX_PROMPT_CHARS);
}

// The question reserve must apply ONLY when a thread is competing for budget.
// Meetings::structure(), Mentorship::structureSession() and AvLab::complete()
// all pass an ~11,000-character transcript as the user text with NO history;
// applying the reserve to those would truncate a meeting to its opening minutes
// — the same silent shortening this whole method exists to prevent.
$transcript = str_repeat('T', 11000);
$p5 = AvBot::composePrompt($transcript, []);
ck('prompt: a transcript with no thread keeps its full length', mb_strlen($p5) === 11000);
ck('prompt: and is not cut to the question reserve', mb_strlen($p5) > AvBot::MAX_QUESTION_CHARS);
ck('prompt: the no-thread cap is still the prompt cap',
   mb_strlen(AvBot::composePrompt(str_repeat('T', 30000), [])) === AvBot::MAX_PROMPT_CHARS);

// Ordinary cases still read the way they always did.
ck('prompt: no history means the bare question', AvBot::composePrompt('Hello?') === 'Hello?');
$p4 = AvBot::composePrompt('And now?', [['role' => 'bot', 'text' => 'Welcome!'], ['role' => 'member', 'name' => 'Ada', 'text' => 'Hi']]);
ck('prompt: the bot is labelled as itself', strpos($p4, 'Afrovanguard (you): Welcome!') !== false);
ck('prompt: a named member is labelled', strpos($p4, 'Member Ada: Hi') !== false);
ck('prompt: a short thread keeps every turn', strpos($p4, 'And now?') !== false);
ck('prompt: empty turns are skipped', strpos(AvBot::composePrompt('X', [['role' => 'member', 'text' => '  ']]), 'Member:') === false);


/* ══ 4. The two minute-writers agree ═════════════════════════════════════ */

// Meetings::structure() hardcoded 20000/2048 while Mentorship::structureSession()
// read them from rules. That is how one copy stayed correct and the other broke.
// Neither may hardcode them again.
foreach (['Meetings' => 'structure', 'Mentorship' => 'structureSession'] as $cls => $fn) {
    $src = (string) preg_replace('~^\s*//.*$~m', '', (string) file_get_contents(AV_ROOT . '/lib/' . $cls . '.php'));
    ck("minutes: {$cls}::{$fn}() reads the transcript limit from rules",
       strpos($src, "AvRules::int('meetings.transcript_char_limit')") !== false);
    ck("minutes: {$cls}::{$fn}() reads max_tokens from rules",
       strpos($src, "AvRules::int('ai.max_tokens')") !== false);
}
