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

## 1. Where things stand

| Commit | What |
|---|---|
| `a0edcff` | Gap analysis + roadmap (`AFROVANGUARD-AI-OS.md`) |
| `8bbb725` | Rules, knowledge and prompts as editable data |
| `b6059e3` | Review fixes on that layer |
| `2b236bc` | Merge of the duplicate branch (content superseded) |
| `aa2c73c` | The AI notetaker in Google Meet |

Suite green: **474 assertions across 5 files** (`php tests/run.php`).

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
AvRules      31 typed rules. Resolution: DB override → env/config pin → coded default.
             Validates on write; rejects rather than coerces. Reports cross-rule
             conflicts (individually valid, jointly nonsensical).

AvKnowledge  The doctrine the assistants are taught — what Afrovanguard is, the
             four dimensions, what the levels mean, where the AI's authority ends.
             Seeded once, then fully editable.

AvPrompts    6 system prompts as templates with declared {{variables}}. Each opts
             into the live rules and doctrine blocks, appended at render time.
```

The point of the appending: a prompt author **cannot forget** to include the
cadence or the "never score character" rule, because the renderer adds them.
Editing a template changes the task instructions; the constitution comes from
`AvRules` either way.

Edited in **Studio → Rules & AI**, admin-gated (editors can't change how the org
measures people), CSRF-protected, undoable through the audit trail.

Docs: `docs/rules-engine.md`.

---

## 4. What is LIVE vs what is SCAFFOLDED

This is the most important section. **19 rules are live. 12 are scaffolded** and
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

All documented in `.env.example`.

**Two cost levers worth knowing.** Recall.ai is the one genuinely usage-priced
service (per meeting-hour) — Google's own Meet transcripts are free and already
wired, so prefer those unless you need Zoom/Teams. And `AV_AI_MODEL` defaults to
an Opus-class model in `AvBot.php`, which is wrong for a nightly sweep; set a
cheaper tier and reserve the expensive model for member-facing conversation.

---

## 7. Known issues NOT fixed

**`lib/Mentorship.php:384` — the retry cap doesn't work.**
`s.reconcile_tries < ?` binds an int constant through `execute()`, which PDO
sends as a string. SQLite's type affinity makes `INTEGER < TEXT` always true, so
the cap never fires and sessions reconcile indefinitely. Left alone deliberately:
it's pre-existing, unrelated to this work, and changing reconciliation behaviour
is a judgement call. Fix with `bindValue(..., PDO::PARAM_INT)`.

This is a **bug class, not one bug** — the same silent-wrong-answer pattern hit
the active-mentee query during this work. Any bound parameter compared against
an integer column needs `PARAM_INT`. Date/string comparisons are fine.

**Mentorship's own `addSession()` path has no notetaker.** Those sessions store a
transcript *URL*, not text, and have no structured-minutes pipeline — a bot there
would capture something with nowhere to land. Sessions booked through the
Meetings surface with `context=mentorship` already work. Giving `addSession()`
the full chain is real work: transcript text storage, structuring, and
bot→session mapping in the ingest path.

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

**Do G-12 (natural-language Q&A) last.** It's the most demo-friendly feature and
the least useful without the data underneath — a NL interface over incomplete
records produces confident wrong answers about real people's character.

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
php tests/run.php          # 474 assertions, 5 files
```

| File | Pins |
|---|---|
| `tests/rules.test.php` | resolution order, rejection-not-coercion, batch atomicity, cross-rule coherence, prompt interpolation, the dynamic ladder, active-vs-scheduled mentorship, diamond-vs-chain depth |
| `tests/meetbot.test.php` | notetaker availability, participant gating, idempotency, retry-after-failure, join-time clamping, the sweep window |
| `tests/meetings.test.php` | the earlier meeting defects, pinned so they stay fixed |

No test reaches an external vendor — no provider is configured in the suite, by
design.

Reference docs: `AFROVANGUARD-AI-OS.md` (gap analysis + roadmap),
`docs/rules-engine.md` (the rules layer), `docs/meetings.md` (the meeting
system), `CODEBASE-AUDIT.md` (whole-repo state).
