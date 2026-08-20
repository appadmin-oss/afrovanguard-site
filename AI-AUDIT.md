# Afrovanguard AI Implementation Audit

_Repository:_ `appadmin-oss/afrovanguard-site` · _Audit date:_ **2026-08-20** · _Scope:_ the AI layer only — provider clients, the agent loop, the tool registry, the rules/prompt/knowledge stores, and every HTTP surface that reaches a model.

This is a follow-on to `CODEBASE-AUDIT.md` §9B (2026-08-18), which audited the AI layer's **declared safety properties** and found they hold. This pass audits the layer's **correctness and operational fitness** instead: does it send what it thinks it sends, does it survive the network, and what does it cost. The two passes reach opposite conclusions about different things, and both are right.

> **Headline.** The safety architecture is sound and the model IDs are current. But **four defects break the AI features in normal use**, and three of them are silent — no exception, no log line, no failed test. Two undefined rule keys cap the entire agent loop at **256 output tokens** and reduce mentorship minutes to a **1000-character transcript answered in 64 tokens**. The suite is green at 905 assertions and catches none of it.

**Files reviewed:** `lib/AvBot.php`, `lib/Gemini.php`, `lib/OpenAi.php`, `lib/AvAgent.php`, `lib/AvTools.php`, `lib/AvWeb.php`, `lib/AvRules.php`, `lib/AvPrompts.php`, `lib/AiKnowledge.php`, `lib/AvKnowledge.php`, `lib/AvLab.php`, `lib/AvSettings.php`, `lib/Chioma.php`, `lib/Meetings.php`, `lib/Mentorship.php`, `lib/Community.php`, `lib/ErrorPoem.php`, `lib/Tts.php`, `lib/Config.php`, `admin/api.php`, `integrations/api.php`, `community/api.php`, `search.php`, `chioma.php`, `assets/site/chioma.js`, `admin/app.js`, `tests/*`.

---

## 1. Findings at a glance

| ID | Severity | Class | Location | Summary |
|---|---|---|---|---|
| **A-1** | **Critical** | Silent misconfiguration | `lib/AvRules.php` DEFS; `lib/AvAgent.php:139,217,290` | `ai.max_tokens` is read everywhere and **defined nowhere** → every agent call is capped at **256** output tokens. |
| **A-2** | **Critical** | Silent misconfiguration | `lib/Mentorship.php:860-872` | `meetings.transcript_char_limit` also undefined → session minutes see **1000 chars** of transcript and get **64** tokens to answer. The feature cannot succeed. |
| **A-3** | **High** | Encoding / correctness | `lib/AvAgent.php:363-364` | Byte-wise `substr()` on UTF-8 JSON splits a character → `json_encode()` returns `false` → the request body is destroyed. Reachable from any non-ASCII `web_fetch`. |
| **A-4** | **High** | Prompt construction | `lib/AvBot.php:109-116` | The composed prompt is truncated **from the end**, so a long thread silently deletes the member's actual question. |
| **A-5** | **High** | Cost exhaustion | `search.php:135-140` | `GET /search.php?q=…&ai=1` makes a paid Opus call with **no auth, no origin check and no rate limit**. |
| **A-6** | **High** | Resilience | all three provider clients | **No retry or backoff** on 429 / 5xx / `overloaded_error`. One transient blip is a hard user-visible failure. |
| **A-7** | **Medium** | Correctness | `lib/AvAgent.php:150-175` | `stop_reason: "max_tokens"` is never checked, so a **truncated `tool_use` block** is executed as if complete. Amplified by A-1. |
| **A-8** | **Medium** | Cost | all Anthropic call sites | **No prompt caching**, despite `AvBot.php:53` documenting the system prompt as "Stable (good for prompt caching)". |
| **A-9** | **Medium** | Observability | whole layer | **No token or cost accounting.** Every provider's `usage` block is discarded. |
| **A-10** | **Medium** | Safety switch gap | `lib/Chioma.php:82-83` | The `ai.enabled` master switch **does not cover** Chioma's external-agent path. |
| **A-11** | **Medium** | Config reachability | `lib/Chioma.php:26,113` | `AV_CHIOMA_AGENT_URL`/`_KEY` use bare `getenv()` and are absent from the `AvSettings` registry → unreachable from `config.php` **and** from the Studio. |
| **A-12** | **Medium** | Runtime limits | `admin/api.php:484` (`ai_chat`) | No `set_time_limit()`. Six tool turns can exceed shared-host `max_execution_time` and die mid-loop. |
| **A-13** | **Low** | Consistency | `lib/AvLab.php:286-300` et al. | Provider preference is **Anthropic-first** in `AvAgent` and **Gemini-first** in five other call sites; `AV_AGENT_PROVIDER` steers only the former. |
| **A-14** | **Low** | Error handling | `admin/api.php:356` | Reads `$ai['__error']`, a key `AvBot::reply()` never returns → the real error is always swallowed. `guide_ask` is also the one AI endpoint with no rate limit. |
| **A-15** | **Low** | Latent | `lib/AvAgent.php:398-420` | `geminiSchema()` drops `items` and `enum`. No current tool uses them; the first array-typed tool argument will be rejected by Gemini. |
| **A-16** | **Low** | Config reachability | `lib/Tts.php:33-65` | TTS reads bare `getenv()` only and has **no `AvSettings` entries at all** — a paid provider outside both the Studio and the master switch. |
| **A-17** | **Low** | Divergence | `lib/Chioma.php:39` vs `lib/AvBot.php:57` | `AV_ORG_DOMAIN` via `defined()` in one and `Config::` in the other. Diverges on any rebranded/franchise deployment. |
| **A-18** | **Low** | Test coverage | `tests/` | Nothing asserts that a referenced rule key exists, and nothing exercises the agent loop — which is precisely why A-1 and A-2 survived a green suite. |

**Carried forward, still open** (raised in `CODEBASE-AUDIT.md` §9B, re-confirmed present): **M-6** SSRF TOCTOU in `AvWeb::guard()` (validated address is not pinned for the connection) and **M-7** no injection-awareness framing around tool output. Both re-verified in this pass; neither is re-litigated here.

---

## 2. The critical pair: two rule keys that were never defined

`AvRules` resolves a key through DB override → `Config` → the declared default in `DEFS`. An **unknown** key has no declared default, so `get()` returns `null` and `int()` casts it to `0` (`lib/AvRules.php:343-350`). Two keys are read in production paths and appear in no registry:

```
$ grep -rhoE "AvRules::(int|bool|str|list|get)\('[a-z_.]+'\)" --include="*.php" . | sort -u   # 37 keys used
$ grep -oE "^        '[a-z_.]+' => \[" lib/AvRules.php | sort -u                             # 35 keys defined

USED BUT NOT DEFINED:
  ai.max_tokens
  meetings.transcript_char_limit
  nope.not.a.rule          ← test fixture, fine
```

Measured on this checkout:

```
AvRules::int('ai.max_tokens')                  = 0
AvRules::int('meetings.transcript_char_limit') = 0
AvRules::get('ai.max_tokens')                  = NULL

--- what each call site therefore sends ---
AvAgent::runAnthropic  max_tokens      = 256   (intended 2048)
AvAgent::runOpenAi     max_tokens      = 256   (intended 2048)
AvAgent::runGemini     maxOutputTokens = 256   (intended 2048)
Mentorship::structureSession:
   transcript truncated to             = 1000 chars  (intended 20000)
   Gemini::generate max_tokens         = 64    (intended 2048)
   AvBot::reply     max_tokens         = 64    (intended 1500)
```

### A-1 — every agent answer is capped at 256 tokens

`AvAgent.php:139`, `:217` and `:290` all read the missing key and then clamp with `max(256, min(8192, $maxTok))`. The floor rescues the call from `max_tokens: 0` (which would be a 400) and in doing so **hides the bug**: the request succeeds, and the Studio chat console simply stops mid-sentence. Roughly 190 words. The `ai.max_tokens` rule the code is reaching for was never added to `DEFS` alongside `ai.max_tool_turns`.

This also degrades tool use specifically. A `tool_use` block costs tokens; at 256 the model has very little room to emit one, and what it does emit is at risk of truncation — see **A-7**.

### A-2 — mentorship session minutes cannot succeed

`Mentorship::structureSession()` (`:857-878`) is the worse case, because **both** missing keys land in it:

- `mb_substr($raw, 0, max(1000, $chars))` → an hour-long transcript is cut to **1000 characters** (~150 words).
- `max_tokens` → **64** on Gemini and OpenAI, **64** on the Claude fallback (`min($maxTok, 1500)` = `min(0, 1500)` = `0`, then floored to 64).

The prompt demands strict JSON with `summary`, `progress`, `obstacles`, `commitments` and `next_focus`. That does not fit in 64 tokens, so the response is always truncated, `extractJson()` always fails, and the caller always returns **"Could not parse the AI minutes."** The feature has never worked on any deployment that did not set `AV_AI_MAX_TOKENS` in the environment by coincidence.

The sibling implementation, `Meetings::structure()` (`:362-395`), hardcodes `20000` and `2048` and is **correct**. Two implementations of one job diverged, and only the copy that read the rules broke.

**Fix.** Add both keys to `AvRules::DEFS`:

```php
'ai.max_tokens' => [
    'type' => 'int', 'default' => 2048, 'min' => 256, 'max' => 8192, 'group' => 'AI',
    'label' => 'Maximum tokens per AI response',
    'help'  => 'The output ceiling for one model call. Higher costs more and allows longer answers.',
],
'meetings.transcript_char_limit' => [
    'type' => 'int', 'default' => 20000, 'min' => 1000, 'max' => 200000, 'group' => 'Meetings',
    'label' => 'Transcript characters sent for summarising',
    'help'  => 'How much of a raw transcript reaches the model. Longer costs more per meeting.',
],
```

Then point `Meetings::structure()` at the rules too, so the two paths cannot drift again.

---

## 3. A-3 — byte-wise truncation corrupts the request body

`AvAgent::execute()` bounds a tool result before feeding it back:

```php
$json = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if (strlen($json) > self::MAX_RESULT_CHARS) {                       // bytes
    $json = substr($json, 0, self::MAX_RESULT_CHARS) . '… [truncated]';   // bytes
}
```

`JSON_UNESCAPED_UNICODE` emits raw multi-byte UTF-8, and `substr()` counts bytes, so the cut lands mid-character whenever the 16,000th byte falls inside one. The damaged string becomes a `tool_result`, and `AvBot::http()` then calls `json_encode($payload, …)` (`:166`) — which **returns `false` on malformed UTF-8**. `false` goes straight into `CURLOPT_POSTFIELDS`, unchecked. Demonstrated:

```
encoded bytes: 18011
valid UTF-8 after substr? NO
AvBot::http json_encode(payload) => false
json_last_error_msg: Malformed UTF-8 characters, possibly incorrectly encoded
=> CURLOPT_POSTFIELDS receives false
```

`web_fetch` returns up to 12,000 characters of arbitrary page text, so accented characters, curly quotes, em dashes, CJK and emoji are all routine. The same unchecked `json_encode` is in `Gemini.php:143` and `OpenAi.php:207`.

**Fix.** Truncate with `mb_strcut()` (byte-bounded, character-safe), and treat a `json_encode` failure as an error rather than a payload:

```php
if (strlen($json) > self::MAX_RESULT_CHARS) {
    $json = mb_strcut($json, 0, self::MAX_RESULT_CHARS, 'UTF-8') . '… [truncated]';
}
```

```php
$body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
if (!is_string($body)) return ['__error' => 'Could not encode the request: ' . json_last_error_msg()];
```

Note the truncated JSON is deliberately no longer valid JSON — that is fine, the model reads it as text — but it must remain valid **UTF-8**.

---

## 4. A-4 — a long thread deletes the member's question

`AvBot::reply()` folds history and question into one string, then caps it:

```php
$prompt = "Here is the community thread so far:\n\n" . $context
        . "\nReply to the latest message:\n" . $userText;
// …
'messages' => [['role' => 'user', 'content' => mb_substr($prompt, 0, 12000)]],
```

History is bounded at 40 turns × 1200 chars = **up to 48,000 characters**, and the question sits at the *end*. `mb_substr(…, 0, 12000)` therefore cuts from the wrong end. Demonstrated at the documented maxima:

```
full prompt chars: 48606, sent: 12000
does the model still see the question? NO — silently dropped
does it even see 'Reply to the latest message'? NO
```

The model receives a wall of thread and no instruction, and answers something plausible about the last thing it can see. Nothing logs it. The community bot (`community/api.php:164`) passes a full thread — root post plus every reply — so a busy thread reaches this.

**Fix.** Budget the parts instead of the whole: reserve room for `$userText` and the instruction, and trim `$context` from the *oldest* end to fit.

```php
$tail   = "\nReply to the latest message:\n" . mb_substr($userText, 0, 4000);
$budget = 12000 - mb_strlen($tail) - 40;                       // 40 ≈ the header
if (mb_strlen($context) > $budget) $context = mb_substr($context, -$budget);
$prompt = "Here is the community thread so far:\n\n" . $context . $tail;
```

---

## 5. A-5 — an open, paid endpoint

Compare the two public AI surfaces:

| | `chioma.php` | `search.php?ai=1` |
|---|---|---|
| Method | POST | **GET** |
| Same-origin check | `require_same_origin()` | **none** |
| Rate limit | `av_rate_ok('chioma', 30, 300)` | **none** |
| Input cap | 1500 chars | none (AvBot's 12,000 is the only bound) |
| Model call | Claude | Claude, with the full `AiKnowledge` block in the system prompt |

`search.php` has no guard of any kind before `AvBot::reply()` at `:140`. Anyone can run `curl 'https://…/search.php?q=…&ai=1'` in a loop and bill the organisation at Opus rates ($5 / $25 per MTok). Being a **GET** compounds it: link prefetchers, crawlers and scanners reach it without a form submission.

The client is well-behaved — `nav.js:303` debounces typing through `search(false)` and only sends `ai=1` on submit — so this is a server-side gap, not a UI one.

**Fix.** Apply the guards `chioma.php` already has, and cap the query:

```php
if ((string) ($_GET['ai'] ?? '') === '1' && class_exists('AvBot')) {
    if (function_exists('require_same_origin')) require_same_origin();
    if (function_exists('av_rate_ok') && !av_rate_ok('search_ai', 12, 300)) {
        $ai = ['ok' => false, 'text' => '', 'rate_limited' => true];
    } elseif (AvBot::configured()) {
        $r = AvBot::reply(mb_substr($q, 0, 500), [], ['system' => $sys]);
        …
```

While there: `av_rate_ok('integrations_api', 120, 60)` in `integrations/api.php:49` is one shared per-IP bucket for the whole API, so `bot.ask` and `chioma.ask` inherit **120 model calls per minute**. Give the model-calling actions their own, much tighter bucket, keyed by token rather than IP.

---

## 6. A-6, A-7 — the network and the stop reason

### A-6 — no retries anywhere

None of `AvBot::http()`, `Gemini::http()` or `OpenAi::http()` retries anything. A single `429`, `500`, `502` or Anthropic `529 overloaded_error` surfaces to the member as a hard failure. The official SDKs retry 408/409/429/5xx twice by default; this hand-rolled cURL layer chose the same job and skipped that behaviour.

The multi-provider fallback in `Meetings::structure()` and `AvLab::complete()` is **not** a substitute: it tries a *different* provider, so a deployment with only `ANTHROPIC_API_KEY` set has no recovery at all, and one transient 529 loses the meeting minutes.

**Fix.** One shared helper — 2 retries, exponential backoff with jitter, honouring `retry-after`, only for 408/409/429/5xx and connection errors. Never retry a 400/401/403.

### A-7 — a truncated `tool_use` is treated as a real one

`runAnthropic()` checks `stop_reason` for `"refusal"` (`:150`) but never for `"max_tokens"`. When the response is cut mid-`tool_use`, the block arrives with partial or empty `input`; the loop collects it, executes the tool with wrong arguments, and then echoes the incomplete block back as the assistant turn — which the API can reject outright on the next request. At A-1's effective 256-token ceiling this is not a corner case.

**Fix.** After parsing the response:

```php
if (($res['stop_reason'] ?? '') === 'max_tokens' && $calls) {
    $out['error'] = 'The model ran out of room mid tool call. Raise ai.max_tokens.';
    return $out;
}
```

Fixing A-1 makes it rare; this makes it safe.

---

## 7. A-8, A-9 — cost

### A-8 — prompt caching is documented but not implemented

`AvBot.php:53` says of the system prompt: *"Stable (good for prompt caching)."* There is no `cache_control` breakpoint anywhere in the repository, and no `anthropic-beta` header. Every request re-sends and re-pays for the full prefix.

The prefix is substantial and genuinely stable: the AvBot persona plus `AiKnowledge::asPromptBlock()` — programmes, recent Diary entries, dues, upcoming events — cached under a version stamp with a 6-hour TTL, so it changes only when site content changes. The community bot, the Studio guide and the search assistant all resend it verbatim on every call. This is the textbook case for a cache breakpoint, worth up to a 90% reduction on the cached portion.

Two caveats to respect when implementing it:

- The cacheable prefix must be **≥ ~1024 tokens** or it silently will not cache. Measure it with `/v1/messages/count_tokens` before assuming the AvBot prompt qualifies; add the knowledge block first if it is short.
- **Chioma's prompt is not cacheable as written.** `Chioma::systemPrompt()` interpolates the visitor's current page title and path (`:41-43`) *before* the knowledge block, so every page view is a different prefix and would never hit. Move the page context out of `system` and into the user message — or place it after the last breakpoint — before enabling caching there.

Verify with `usage.cache_read_input_tokens`; if it stays zero across repeats, something upstream is still varying.

### A-9 — nothing counts the spend

Every provider returns a `usage` block. All three clients discard it: `AvBot::reply()` returns only `ok/text/error`, and the raw decoded body is dropped after the content blocks are read. There is no per-request, per-feature or per-day token record anywhere in the app.

For a nonprofit running Opus on public endpoints with A-5 open, the first signal of a problem is the invoice. Recording `usage.input_tokens`, `usage.output_tokens`, `cache_read_input_tokens` and the model per call — a small `av_ai_usage` table, surfaced on the System page — is a few hours' work and pairs naturally with the fix for A-5.

---

## 8. A-10 … A-17 — switches, config and consistency

### A-10 — the master switch has a hole

`ai.enabled` is enforced inside `AvBot::reply()`, `AvBot::rawMessages()`, `Gemini::generate()`, `Gemini::rawGenerate()`, `OpenAi::generate()`, `OpenAi::rawChat()` and `OpenAi::transcribeAudio()` — deliberately at the network call, so that (per the comment at `AvBot.php:99-101`) it *"covers EVERY caller rather than only the ones that remember to ask."*

`Chioma::reply()` is the caller it does not cover. `lib/Chioma.php` contains no reference to `AvRules` at all, and reply source **#1** is the external agent:

```php
if (self::agentConfigured()) {
    $r = self::delegate($message, $history, $ctx);      // ← no ai.enabled check
    if ($r !== null && trim($r) !== '') return [… 'source' => 'agent'];
}
```

With `AV_CHIOMA_AGENT_URL` set and `ai.enabled` switched off, every visitor message — plus the full persona and page context — is still POSTed to the third-party webhook. An operator who switches AI off to stop data leaving the site does not get that.

**Fix.** Gate `Chioma::reply()` on `ai.enabled` before the delegate branch, and have `aiAvailable()` (`:33`) reflect it.

### A-11, A-17 — settings the operator cannot reach

`Config::get()` resolves **AvSettings (encrypted DB) → `config.php` constant → environment**. `AvSettings::apply()` additionally `putenv()`s registry keys at bootstrap, so a bare `getenv()` still sees anything *in the registry*.

`AV_CHIOMA_AGENT_URL` and `AV_CHIOMA_AGENT_KEY` are read with bare `getenv()` (`:26`, `:113`) **and** are absent from the `AvSettings` registry. The consequence is not theoretical: they are the only AI settings that cannot be set from the Studio *and* cannot be set in `config.php` — leaving real environment variables as the sole path, on a platform whose own `config.example.php` is the documented route for shared cPanel hosting. `.env.example:105` advertises the setting as though it were ordinary.

Same shape at `Chioma.php:39`: `AV_ORG_DOMAIN` is read via `defined()` only, while `AvBot.php:57` reads it via `Config::str()`. Both currently produce `afrovanguard.org.ng`, so nothing is visibly wrong today — but on a rebranded or franchise deployment (and `franchise.php` exists) Chioma would tell visitors the wrong domain for member accounts while AvBot used the right one.

**Fix.** Route all three through `Config::`, and add the two Chioma keys to the `AvSettings` registry (`AV_CHIOMA_AGENT_KEY` as `secret => true`).

### A-12 — the agent loop outlives the request

`ai_chat` runs a synchronous loop of up to `ai.max_tool_turns` (default 6, max 20) round-trips. Each turn is a model call with `CURLOPT_TIMEOUT => 45` (Anthropic) or `90` (OpenAI), and a `web_fetch` inside a turn adds up to 20s more. Worst case at the default is well past three minutes; at 20 turns, past ten.

Only `diary/audio.php:66` calls `set_time_limit()` anywhere in the repo, and no `php_value max_execution_time` is set in `.htaccess`, the `Dockerfile` or `deploy/`. On typical cPanel PHP-FPM (30s) or LiteSpeed (60–120s) the process is killed mid-loop: the admin sees a bare 500, the tool work already done is lost, and the model calls already made are still billed.

**Fix.** `@set_time_limit(0)` (or a generous explicit budget) on `ai_chat` and `ai_run`, plus a wall-clock budget inside `AvAgent::run()` that stops cleanly and returns the steps taken so far rather than being killed.

### A-13, A-14, A-15, A-16 — smaller things

- **A-13.** `AvAgent::provider()` prefers **Anthropic → OpenAI → Gemini**; `AvLab::complete()`, `Meetings::structure()`, `Mentorship::structureSession()` and `Community` all prefer **Gemini → OpenAI → Anthropic**. `AV_AGENT_PROVIDER` — labelled "Provider for tool use" in the Studio, which is honest — steers only the first. There is no global preference, so an operator cannot express "use Gemini for the cheap bulk work and Claude for members" in one place. Promote it to a rule with a per-subsystem override.
- **A-14.** `admin/api.php:356` builds its failure message from `$ai['__error']`. `AvBot::reply()` returns `error`, never `__error` (`__error` is the *internal* shape of `AvBot::http()`), so the operator always gets `"Sorry — the assistant couldn't answer just now. "` with the diagnosis stripped off. One-character class of fix; also give `guide_ask` the rate limit its two AI siblings have.
- **A-15.** `AvAgent::geminiSchema()` (`:398`) keeps only `type` and `description` per property, dropping `items` and `enum`. Correct for today's 13 tools, none of which use them — but Gemini rejects `type: "array"` without `items`, so the first array-valued tool argument fails on Gemini only, with a wire error that will not obviously point back here. Add a comment at the registry, or carry `items`/`enum` through.
- **A-16.** `lib/Tts.php` reads `getenv()` exclusively across 14 call sites, and no TTS key appears in the `AvSettings` registry. So the ElevenLabs/OpenAI voice keys are the one set of paid-provider credentials outside the encrypted store, outside the Studio, and outside `ai.enabled`. It also cannot reuse a Studio-stored `OPENAI_API_KEY` even though `Tts::key()` looks for exactly that name.

---

## 9. A-18 — why a green suite missed all of this

The suite is genuinely good where it aims: 905 assertions, 13 files, and `tests/aitools.test.php` alone pins the read/propose split, tier gating, the proposal lifecycle, the email-leak invariant and 16 SSRF bypasses. That is why §9B could verify the safety properties and be right.

The gap is that **nothing tests the layer's plumbing**:

1. **No rule-key coverage test.** The exact three-line check that found A-1 and A-2 is missing. Add it — it is cheap and it never goes stale:

   ```php
   $used = []; // grep AvRules::(int|bool|str|list|get)('key') across *.php
   foreach ($used as $k) {
       if ($k === 'nope.not.a.rule') continue;                    // rules.test.php fixture
       ck("rules: '$k' is declared", AvRules::get($k) !== null);
   }
   ```

2. **No agent-loop test.** `AV_AI_BASE_URL`, `AV_GEMINI_BASE_URL` and `AV_OPENAI_BASE_URL` all exist and are documented as being for a *"gateway/Bedrock/test mock"* — and no test uses them. A tiny local mock returning a canned `tool_use` → `tool_result` → text exchange would have caught A-3, A-4 and A-7, and would pin the three wire formats against provider drift.

3. **No prompt-construction test.** A-4 is a pure string-assembly bug, testable with no network at all: build a 40-turn history and assert the question survives.

---

## 10. What is right

Worth stating plainly, because it is most of the layer and it shaped which findings above are Medium rather than High:

- **The model IDs are current and correct.** `claude-opus-4-8`, and `claude-haiku-4-5` / `claude-sonnet-4-6` as the documented cheaper tiers, are all real, correctly formatted, and correctly un-suffixed. `gpt-4o-mini`, `whisper-1` and `gemini-2.0-flash` are likewise right. Nothing carries an invented date suffix.
- **`stop_reason: "refusal"` is handled** in both `AvBot::reply()` and `AvAgent::runAnthropic()`, and OpenAI's `message.refusal` is handled distinctly from an empty completion — an easy thing to get wrong and a sign someone read the current docs.
- **The read/propose split holds.** No tool mutates state; `propose_*` writes to `av_proposals` and nothing applies without an administrator. Tested from both directions, including that a rejected proposal cannot later be approved.
- **The SSRF filter is careful** — scheme, port, embedded credentials, bracketed IPv6, every A/AAAA answer, per-hop re-validation with manual redirect following, and an explicit `169.254.` / `::ffff:` guard. Only the TOCTOU pin (§9B M-6) is missing.
- **Secrets are handled properly.** `AvSettings` encrypts at rest, refuses to store a secret when there is no `APP_KEY`, returns only a masked preview, and publishes only registry keys to the environment.
- **AI output is escaped at every render site checked** — `escapeHtml()` in `admin/app.js` for chat text, tool arguments and previews, and `esc()`-before-linkify in `assets/site/chioma.js`. No AI-driven XSS found.
- **The master switch is enforced at the network call**, not at the call sites — the right architectural choice, with the one hole at A-10.
- **`AvAgent` returns its `steps`** so the Studio can show which records the assistant actually read. That is a real accountability feature, not decoration.

---

## 11. Suggested order of work

**Priority 0 — correctness, ~half a day.** A-1 and A-2 (add both rules; point `Meetings::structure()` at them). A-3 (`mb_strcut` + guard `json_encode` in all three clients). A-4 (budget the prompt parts). Then A-18.1 and A-18.3, so none of them can come back.

**Priority 1 — exposure and resilience.** A-5 (guard `search.php`, split the integrations rate bucket). A-6 (one shared retry helper). A-12 (`set_time_limit` + a wall-clock budget). A-7.

**Priority 2 — cost visibility.** A-9 first — the accounting tells you whether A-8 is worth doing and whether A-5 was already being abused. Then A-8, respecting the Chioma caveat.

**Priority 3 — configuration hygiene.** A-10, A-11, A-17, A-16, A-13, A-14, A-15.

**Still open from §9B.** M-6 (pin the validated IP with `CURLOPT_RESOLVE`) and M-7 (delimit tool output; flag proposals whose turn touched the web).

---

_Method: static reading of every AI file listed in the header, plus executable proofs for A-1 through A-4 run against this checkout on PHP 8.4.19; `tests/run.php` executed green at 905 assertions; provider model IDs and Messages API parameters checked against the current Anthropic API reference rather than recalled._
