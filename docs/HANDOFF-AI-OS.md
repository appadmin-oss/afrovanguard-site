# HANDOFF — the AI accountability OS

Working memory for whoever picks this up next.

**Scope:** the "AI-Powered Meeting, Mentorship & Incorruptible Leadership
Operating System" concept report (30pp, 42 sections) and everything built
towards it.

**Branch:** `claude/new-session-8pjvx6` — all work is here, pushed.

> Not to be confused with `docs/HANDOFF.md`, which is a **different** programme
> of work (NGG × Afrovanguard sync, super admins, Anchor Journal) on the
> `vigilant` branch. The two are unrelated; don't merge their branches.

---

## 0. Read this first: the branch situation

There were briefly **two branches with the same feature built twice**.

`claude/afrovanguard-audit-3szkmu` rebuilt the rules engine from the roadmap
doc after its container was reclaimed, not knowing the original work had
already been pushed to `claude/new-session-8pjvx6`. Both implemented
`AvRules` / `AvKnowledge` / `AvPrompts` independently.

Resolved in `2b236bc` by merging with `-s ours`: the histories are joined so
the duplicate branch isn't left looking unmerged, and **this branch's version
was kept** — it fetches the whole mentorship graph in one query and walks it in
memory with cycle and diamond handling, where the other ran a query per mentee
and only looked one generation down.

**Do not resurrect the audit branch's implementation.** If you see
`Mentorship::multiplication()` or `verifiedActiveMentees()` referenced
anywhere, that is the superseded version — the live API is
`multiplicationSummary()` / `multiplicationDepth()` / `activeMenteeIds()`.

---

## 0a. The 2026-08-18 audit — read this before touching the AI layer

`CODEBASE-AUDIT.md` **§9B** audits everything below and tests the safety
properties this document claims rather than accepting them. Three things you need
from it:

1. **The claims hold.** The read/propose split, the SSRF filter (16 crafted
   bypasses, all refused), the settings crypto, the tier gating and the escaping in
   the new Studio panes were all verified against running code.
2. **§7's headline known-bug is not real** — see the correction there. Do not
   "fix" it.
3. **There is a High finding, and it is not in this work.** `cover_url` and
   `gradient` reach `admin/app.js:134,333` unescaped, so an **editor** can take
   over a **Super Admin** session — which now means the provider keys in Setup and
   the rules that drive promotions. Audit finding **H-2**, Priority 0. The same
   unvalidated `cover_url` also feeds `av_fetch_image_bytes()`, which has no SSRF
   guard at all (**M-5**) — a few files from the one that does.

Open against the AI layer specifically: **M-6** (`AvWeb` validates the resolved
address then lets cURL resolve again — pin it with `CURLOPT_RESOLVE`), **M-7** (no
prompt-injection boundary; the propose queue is what contains it), **L-5** (two of
thirteen tools return another subsystem's value unprojected) and **L-6** (the
suite really does reach the network).

---

## 1. Where things stand

| Commit | What |
|---|---|
| `a0edcff` | Gap analysis + roadmap (`AFROVANGUARD-AI-OS.md`) |
| `8bbb725` | Rules, knowledge and prompts as editable data |
| `b6059e3` | Review fixes on that layer |
| `2b236bc` | Merge of the duplicate branch (content superseded) |
| `aa2c73c` | The AI notetaker in Google Meet |
| `f29841d` | This handoff + the notetaker in the meetings guide |
| `a47160f` | `AvTools` (tool registry + proposal queue) and `AvWeb` (search/fetch) |
| `3f00882` | `AvAgent` — the tool-use loop over both providers |
| `e27385a` | Mentorship sessions get minutes and a notetaker |
| `fe4c055` | Studio: the AI bench, the chat console, the approval queue |
| `7c5ad50` | Tests for the tool layer, the gate and session minutes |
| `ebb4fb2` | Handoff covering the tool layer and Studio console |
| `556dac9` | `AvSettings` — set the AI up from the Studio |
| `47341fa` | Docs: the Setup screen and the three config layers |
| `3db4cc4` | OpenAI as a third provider, plus Whisper |
| `7242349` | Attendee — a free alternative to Recall — and transcript polling |

Suite green: **666 assertions across 7 files** (`php tests/run.php`).

Current inventory: **23 live rules + 12 pending · 5 live prompts + 4 pending ·
13 tools · 6 testable capabilities · 25 Studio-managed settings in 6 groups ·
3 model providers · 4 notetaker backends.**

---

## 2. The headline finding, still true

The concept report is written as if Afrovanguard has dashboards that need an AI
layer bolted on. **That premise was already out of date when the report was
written.** Roughly 60% of what it proposes was already in the repo — including
the parts it treats as advanced Phase 4/5 work:

- `Meetings::structure()` already produced AI minutes (summary, highlights,
  decisions, action items) on Gemini Flash with an Anthropic fallback, fed by
  real Google Meet transcripts.
- `Mentorship.php` already tracked pairings, sessions, attendance, consistency
  rate and streak, with server-stamped meeting times reconciled against Google.
- `tasks/cron.php` + `Notifications::dispatchDue()` was already a working,
  deduped, per-user-timezone sweep.

`AFROVANGUARD-AI-OS.md` has the full file-by-file map. Read it before planning
anything — it will stop you rebuilding something that exists.

---

## 3. Architecture — the three stores

Nothing the AI depends on is hardcoded. Three stores, each with documented
defaults so a fresh install is fully operational:

```
AvRules      35 typed rules. Resolution: DB override → env/config pin → coded default.
             Validates on write; rejects rather than coerces. Reports cross-rule
             conflicts (individually valid, jointly nonsensical).

AvKnowledge  The doctrine the assistants are taught — what Afrovanguard is, the
             four dimensions, what the levels mean, where the AI's authority ends.
             Seeded once, then fully editable.

AvPrompts    9 system prompts as templates with declared {{variables}}. Each opts
             into the live rules and doctrine blocks, appended at render time.
```

The point of the appending: a prompt author **cannot forget** to include the
cadence or the "never score character" rule, because the renderer adds them.
Editing a template changes the task instructions; the constitution comes from
`AvRules` either way.

Edited in **Studio → Rules & AI**, admin-gated (editors can't change how the org
measures people), CSRF-protected, undoable through the audit trail. Rules and
prompts are Super Admin; knowledge is management-level.

Docs: `docs/rules-engine.md`.

### 3a. Tools, the agent loop, and the web

Four more classes turn the assistant from a text-completer into something that
can go and find out:

```
AvTools   13 tools in two kinds. READ returns organisational fact (member lookup,
          mentorship status, level assessment, quiet pairings, org stats, the live
          rules, the doctrine, its own prompts). PROPOSE changes nothing — it files
          into av_proposals for a human.
AvWeb     web_search (Brave / serper / Google CSE / Tavily — whichever key is set)
          and web_fetch (needs no key).
AvAgent   the loop: offer the specs, execute what the model asks for, feed the
          result back, ask again. One translation per provider — Claude tool-use,
          OpenAI tool-calling, Gemini function-calling — preferred in that order.
AvLab     the bench — runs any capability on demand and shows the rendered prompt,
          the raw output, the parsed result and every tool call.
```

**The read/propose split is the safety property, and it is the thing not to
weaken.** The AI can write a better doctrine entry, suggest a threshold, even
rewrite its own prompts — and none of it takes effect. `AvTools::approve()` is
the only path from a proposal to live configuration, and it is gated exactly as
editing a rule or prompt directly is. That is §23's division of labour applied to
the AI's own configuration: it recommends, a human decides.

Two privacy rules live in the tool layer rather than the prompts, deliberately —
a prompt is a request, this is a boundary:

- No tool returns a member's stated reason for missing something.
- No tool returns an email address or any contact detail. The AI can identify a
  member and report on their mentorship; it cannot harvest a directory.

**Capability is derived in one place.** `AvAgent::tiersFor()` reads the rules, so
switching `ai.tools` off removes every tier everywhere rather than in whichever
caller remembered to check. `ai.web_access` ships **off**: `web_fetch` needs no
API key, so a default of on would put arbitrary web fetching into every
deployment the moment it updated.

`AvWeb::fetch()` is the one genuinely dangerous surface — a URL chosen by a model
on a server with private network neighbours. It allows only http/https, resolves
the host and refuses private, loopback, link-local and reserved addresses
(including `169.254.169.254`, IPv4-mapped IPv6 and bracketed IPv6), and
re-validates **after every redirect** — redirects are followed by hand precisely
because letting cURL follow them checks only the first URL, which is how these
filters are normally walked past. Eleven of those cases are pinned in tests.

### 3b. Setup — credentials live in the Studio now

`AvSettings` (25 keys across 6 groups) moves provider configuration out of `.env` and into
**Studio → Rules & AI → Setup**. This matters more than it sounds: on shared
cPanel hosting the alternative is editing a file above the web root, which most
administrators will never do, so the AI stays switched off on exactly the
deployments that most need it on.

Three properties to preserve:

- **Secrets never reach the browser.** `describe()` returns a masked tail
  (`AIza-s…3456`) and `value: ''` for anything marked secret. There is
  deliberately no endpoint that reads a stored key back out, signed in or not.
- **No plaintext fallback.** Without `APP_KEY`, or without sodium/openssl, a
  secret save is *refused* with an explanation rather than downgraded.
- **Only registry keys are published.** `apply()` putenv()s stored settings so
  bare-`getenv()` consumers (`Meetings::botProvider()`) work untouched — but it
  skips anything not in `DEFS`, so a row written by another route cannot inject
  an arbitrary environment variable. Pinned in a test.

**Precedence is Studio → constant → env → default**, which is the *opposite* of
the `.env` loader's "real environment always wins". That is intentional: an
administrator editing a key now is expressing current intent. The UI shows a
`shadowing` badge whenever a Studio value is overriding something from
`config.php` or the environment, so the override is visible rather than
mysterious. If you change this precedence, change the badge with it.

`Config::get()` consults `AvSettings` first. It is re-entrancy guarded and fully
try/caught, because it runs on early paths where the database may not exist.

**Connection tests** (`AvSettings::test()`) make the cheapest real call each
provider offers — one token for Claude, a lookup for an impossible bot id for
Recall so nothing is billed. Failures are translated into something actionable
(`"The key was rejected. Check it was pasted whole, with no spaces."`) and never
echo a raw response body, which can contain the key.

### 3b-ii. Providers, and what each costs

Three model providers, chosen by whichever key is set. All three are
interchangeable — `configured()` / `model()` / `generate()` — so a fallback chain
is a branch, not an integration.

| Provider | Used for | Note |
|---|---|---|
| Gemini | minutes, session minutes | Cheapest per transcript; tried first |
| OpenAI | minutes fallback, **Whisper transcription** | `gpt-4o-mini` default. `AV_OPENAI_BASE_URL` points at anything OpenAI-compatible — Azure, Groq, OpenRouter, or a model you host, which is the cheapest route of all |
| Claude | the community bot, the Studio assistant | Best multi-step tool chains, so `AvAgent` prefers it |

`AvAgent` has a separate tool-calling translation per provider because the wire
formats genuinely differ: OpenAI sends tool arguments as a **JSON string** where
the other two send an object, Gemini refuses a schema with no properties, and
each has its own way of echoing the assistant's tool turn back. `AvTools` stays
provider-neutral above all three.

**Notetaker cost, honestly ordered** — the code's auto-detection follows this:

| Backend | Cost | When |
|---|---|---|
| `google` | **free** | Already on Workspace. Meet transcribes itself, no bot. **Start here.** |
| `attendee` | **free self-hosted** | Need Zoom/Teams too, or no Workspace scopes. Open source, Whisper bundled; you pay for a container |
| `webhook` | **free self-hosted** | Your own recorder |
| `recall` | **per meeting-hour** | You would rather pay than operate a container |

Attendee is preferred over Recall when both are configured, because between
equivalent capabilities the free one should win by default. One trap worth
knowing: **`AV_ATTENDEE_BASE_URL` left blank uses Attendee's hosted service,
which is billed** — that quietly defeats the point of choosing it. The Studio
test reports which of the two you are actually talking to.

`Meetings::pollBotTranscripts()` means **no public webhook is required** for
either bot. The cron asks each bot in flight whether it has finished and ingests
when it has, so shared hosting, a staging domain or a site that just moved all
work with no inbound callback.

### 3c. What an administrator can now do in Studio

Four panes under **Rules & AI**, alongside Rules / Knowledge / Prompts:

- **Setup** — connect the providers (above). Encrypted, masked, with a Test
  button per provider.
- **Test the AI** — run any capability with sample or real input. Shows the system
  prompt *as rendered* (so a rule edit is visibly reaching the model), the raw
  output, the parsed result, and every tool call. Nothing writes; `goal.tasks`
  deliberately does not create tasks, because a test that files real work into
  somebody's queue is not a test.
- **Talk to the AI** — the tool-using assistant. Every look-up it made is shown
  under the reply, so a claim about a member can be checked against the records
  it actually read.
- **Proposals** — the approval queue. Approve applies; reject takes a private note
  for your own record (the AI is not told — a rejection reason is not training
  data).

---

## 4. What is LIVE vs what is SCAFFOLDED

This is the most important section. **23 rules are live. 12 are scaffolded** and
labelled *awaiting …* in the Studio, so nobody tunes a setting that has no
effect. The pending list *is* the roadmap, made concrete:

| Pending rules | Waiting on |
|---|---|
| `health.amber_attendance_pct`, `health.red_attendance_pct`, `health.red_missed_streak` | relationship health (G-3) |
| `escalation.cooldown_days` | the escalation ladder (G-2) |
| `commitments.*` (4 rules), `levels.min_commitment_pct` | commitment tracking (G-1) |
| `meetings.ai_agenda` | agenda drafting (G-7) |
| `meetings.warn_minutes` | in-meeting timing (G-11) |
| `ai.tone` | assistant tone |

Same for prompts — `meeting.minutes` and `goal.tasks` are live; `meeting.agenda`,
`accountability.nudge`, `leadership.brief` and `promotion.recommendation` are
written and waiting for their callers.

**So the prompts and thresholds for G-1…G-7 already exist.** Building those
features is mostly writing the subsystem that reads them, not designing the
policy surface again.

---

## 5. What was actually fixed along the way

Real defects, not just new features:

- **Levels rewarded referrals.** Advancement ran on referral count — recruitment,
  not leadership, and the exact anti-pattern §17 names. Now driven by *verified
  active* mentorship via `Mentorship::multiplicationSummary()`. Referrals are
  still recorded; they just don't buy a level.
- **`Levels::ensure()` emitted SQLite-only DDL.** Would have misbehaved on the
  MySQL/Postgres targets the rest of the app supports.
- **The Meet notetaker had no join time.** A bot for next Tuesday's meeting was
  created immediately and sat in an empty room until it gave up. Now sent
  `join_at`, clamped so it's never in the past.
- **A failed bot dispatch was never retried** — it just never arrived, silently.
  `Meetings::dispatchDueBots()` now sweeps on every cron tick.
- **`RecallBot::http()` treated 204 as an error**, so every successful bot
  removal would have reported failure.

---

## 6. Turning it on

Nothing here needs a new vendor. The report's §25 suggestion of Zapier + OpenAI
+ Google Sheets is wrong for this codebase — all seven connections it lists
already exist.

| To enable | Set |
|---|---|
| AI minutes | `AV_GEMINI_API_KEY` (preferred) or `ANTHROPIC_API_KEY` |
| Meet notetaker (bot in the room) | `AV_RECALL_API_KEY` + **`AV_RECALL_WEBHOOK_TOKEN`** |
| Meet notetaker (free path) | nothing — Google's own transcripts are already wired |
| The cron sweep | `AV_CRON_KEY`, hit `tasks/cron.php` every few minutes |
| Tools + the chat console | nothing beyond an AI key — on by default |
| Web **reading** | switch on `ai.web_access` in Studio (no key needed) |
| Web **search** | one of `AV_BRAVE_API_KEY` / `AV_SERPER_API_KEY` / `AV_GOOGLE_CSE_KEY`+`_CX` / `AV_TAVILY_API_KEY` |

All documented in `.env.example` — but **none of it has to go in a file any
more**. Studio → Rules & AI → **Setup** holds the same keys, encrypted, with a
Test button per provider. `.env` remains supported and is the right place for a
deployment that manages config as code; the Studio is for the ones that don't.

Three layers, and it is worth keeping them straight:

| Layer | Question it answers | Where |
|---|---|---|
| `AvSettings` | *Which* provider, and with whose key | Studio → Setup (or `.env`) |
| `AvRules` | *Whether* the AI may do a thing, and within what thresholds | Studio → Rules |
| `AvPrompts` / `AvKnowledge` | *How* it should behave, and what it knows | Studio → Prompts / Knowledge |

**Two cost levers worth knowing.** Recall.ai is the one genuinely usage-priced
service (per meeting-hour) — Google's own Meet transcripts are free and already
wired, so prefer those unless you need Zoom/Teams. And `AV_AI_MODEL` defaults to
an Opus-class model in `AvBot.php`, which is wrong for a nightly sweep; set a
cheaper tier and reserve the expensive model for member-facing conversation.

---

## 7. Known issues NOT fixed

~~**`lib/Mentorship.php:384` — the retry cap doesn't work.**~~ **Refuted
2026-08-18 — there is no bug here. Don't "fix" it.**

The claim was that `s.reconcile_tries < ?` (now `:389`) binds an int through
`execute()`, PDO sends it as a string, and SQLite's affinity makes
`INTEGER < TEXT` always true, so the cap never fires.

Reproduced in a standalone harness — the same join, the same bound constant,
under `ATTR_EMULATE_PREPARES` both `false` and `true` — and **the cap fires
correctly**. SQLite applies **NUMERIC affinity to the parameter** when the other
operand has INTEGER affinity, so the comparison is numeric, which is what was
wanted all along. `RECONCILE_MAX_TRIES = 8` is enforced.

The generalisation that followed — "a **bug class**, not one bug … any bound
parameter compared against an integer column needs `PARAM_INT`" — was therefore
wrong, and acting on it would mean a sweep of pointless edits. **The correct
rule** is narrower and worth keeping:

> Affinity conversion happens because the *column* has INTEGER affinity. Where
> the column is **TEXT**, a bound int is compared as a string and the answer is
> silently wrong — verified: `v < 3` over a TEXT column holding `'0','3','12'`
> returns `'0','12'`. That is the case that needs `PARAM_INT`, or a schema fix.

`LIMIT ?` is safe here for a related reason worth not losing: `Database.php:44`
sets `ATTR_EMULATE_PREPARES => false`, so the seven `LIMIT ?` sites bind natively
instead of being quoted into `LIMIT '4'`. `tests/meetings.test.php:18` records it.

See `CODEBASE-AUDIT.md` §9B for the harness and the full result.

~~**Two bugs worth remembering as a pattern**, both found by verifying rather than
by a test failing, both now pinned:

- `AvSettings::apply()` only ever ADDED to the environment, so clearing a key
  left the old value live for the rest of the request — the Setup screen said
  "unset" while the provider still reported itself configured. Anything that
  publishes into a process needs a matching withdrawal.
- The first Attendee connection test reported "reachable" for a rejected key and
  an unreachable instance alike. A test that cannot fail is worse than no test,
  because it manufactures confidence. `ping()` now distinguishes a 404 (answered,
  no such bot — the pass) from everything else.

**Mentorship's own `addSession()` path has no notetaker.**~~ **Fixed in
`e27385a`.** Sessions now store transcript text and structured minutes
(`mentor_session_minutes`), carry the same bot columns as meetings, and have
`inviteSessionBot()` / `removeSessionBot()` / `dispatchDueSessionBots()`. Recall
posts every bot to one webhook, so a bot id matching no meeting falls through to
the session lookup. `session.minutes` is deliberately a *record*, not an
assessment — no field judges anyone, and personal difficulty is captured only as
far as it explains a blockage, because people who were not in the room read it.

---

## 8. Next steps, in order

From `AFROVANGUARD-AI-OS.md` §5, still the right sequence:

1. **Leadership fixes the numbers.** The §27 task. Every threshold in G-2/G-3/G-5
   reads from rules that now exist and are editable — but the shipped defaults
   are *guesses*. An hour of leadership time in Studio → Rules & AI, costing no
   development, determines whether everything after it is built on the right
   constants.
2. **G-1 Commitments (3–4 days).** The keystone. AI-extracted action items are
   still written to a JSON blob and dropped — nothing follows up, which is
   verbatim the failure §11 names. `Collab::aiTasksFromGoal()` is the working
   template; `collab_tasks` is the destination. Owner resolution must be
   confirm-before-assign (`commitments.auto_assign_owner` already ships off).
3. **G-3 Health + G-2 Escalation (5–6 days).** Health first — it's pure
   derivation and makes escalation legible. Rules and the `accountability.nudge`
   prompt are already written.
4. **G-6 Leadership brief (2–3 days).** Cheap once 2–3 exist; it's a read-model.
   `leadership.brief` prompt is written.
5. **Pilot (6–8 weeks, no new development).** 20 mentors, 50–100 mentees. Its job
   is to tell you which Step-1 thresholds were wrong. They will be.

**G-12 (natural-language Q&A) is now partly built** — the Studio chat console is
that interface, and it is tool-backed rather than guessing. But the original
caution stands and has simply moved: it is only as good as the data underneath,
and the data underneath is still missing commitments (G-1), health (G-3) and
escalation history (G-2). Ask it about mentorship consistency today and it
answers well; ask it who is keeping their commitments and there is nothing to
read. Build G-1 before leaning on the chat console for accountability questions.

---

## 9. Two constraints to defend

Both are one Studio toggle away from being lost, and both change the character
of the system rather than its configuration:

- **`ai.character_scores` ships off.** §3A is explicit that character must not be
  reduced to a number. The scorecard shows behavioural evidence — *"attended 11
  of 12, completed 92%, voluntarily reported 2 misses"* — which is both more
  useful to a leader and more honest than `85%`. The `self_reported` flag exists
  so that honesty registers as a positive signal rather than a dent in a rate.
- **`levels.auto_promote` ships off.** §20: the AI recommends, leadership
  decides. `Levels::recommend()` returns evidence and gaps, never a promotion.
- **`ai.web_access` ships off**, and `AvWeb::fetch()`'s SSRF guards are load-
  bearing. If you refactor them, keep the per-redirect re-validation — checking
  only the first URL is the standard bypass, and the tests will catch a
  regression but only if you keep them.
- **Propose never applies.** If a future caller is tempted to let the AI write
  directly "just for knowledge entries", that is the whole property gone. The
  queue is cheap; a self-editing constitution is not.

And one the report never raises: **this is a surveillance system pointed at
volunteers.** `miss_reason` will collect disclosures about illness, money and
family. The seeded doctrine already tells the assistants to treat those as
confidential, and `leadership.brief` is instructed never to include them. Before
G-1 ships, leadership should decide explicitly who may see a member's health
status and miss history — the mentor, the mentor's mentor, any admin — because
the repo has two divergent authorization models (`CODEBASE-AUDIT.md` M-1) and
new code will otherwise inherit whichever one it happens to touch.

---

## 10. Verifying

```
php tests/run.php          # 666 assertions, 7 files (~21s)
```

| File | Pins |
|---|---|
| `tests/rules.test.php` | resolution order, rejection-not-coercion, batch atomicity, cross-rule coherence, prompt interpolation, the dynamic ladder, active-vs-scheduled mentorship, diamond-vs-chain depth |
| `tests/aitools.test.php` | tier enforcement in the runner, the approval gate (filed changes nothing · approve applies · reject never does · neither twice), file-time validation with the acceptable range, no email in tool output, tier derivation from rules, 11 SSRF cases, the bench |
| `tests/meetbot.test.php` | notetaker availability, participant gating, idempotency, retry-after-failure, join-time clamping, the sweep window — and the same for mentorship sessions, plus either party being able to save minutes and remove the bot |
| `tests/settings.test.php` | secrets never in `describe()` or the database, no plaintext fallback, the masked placeholder not wiping a key, Studio-over-env precedence and shadow reporting, only registry keys published, per-key errors in a mixed batch |
| `tests/meetings.test.php` | the earlier meeting defects, pinned so they stay fixed |

No test reaches a **paid** vendor API — no billable provider is configured in the
suite. **But the suite is not network-free**, and the 2026-08-18 audit flagged it
(`CODEBASE-AUDIT.md` §9B, L-6): `tests/meetbot.test.php:320-378` sets an Attendee
key and `AV_ATTENDEE_BASE_URL=https://meetbot.example.org`, then exercises
dispatch and polling — so the suite really does attempt outbound HTTP, visible in
the run as `[meetings] attendee: transport: CONNECT tunnel failed, response 502`.
Two assertions ("a failed dispatch is recorded", "an unreachable bot ingests
nothing") pass *because* the host does not resolve. They test the network, not the
code. Point the base URL at a reserved-for-failure address or inject the
transport — and note `AttendeeBot`'s `CURLOPT_CONNECTTIMEOUT` is 10 s per attempt
on a host without a fast-failing proxy.

Reference docs: `AFROVANGUARD-AI-OS.md` (gap analysis + roadmap),
`docs/rules-engine.md` (the rules layer), `docs/meetings.md` (the meeting
system), `CODEBASE-AUDIT.md` (whole-repo state).
