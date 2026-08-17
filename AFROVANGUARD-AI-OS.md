# Afrovanguard AI Accountability OS — Gap Analysis & Build Roadmap

_Source document:_ "Afrovanguard AI-Powered Meeting, Mentorship & Incorruptible Leadership Operating System — Comprehensive Concept & Implementation Report" (30pp, 42 sections)
_Assessed against:_ `appadmin-oss/afrovanguard-site` @ `claude/new-session-8pjvx6` · 602 tracked files · 73 `lib/` classes
_Date:_ 2026-08-17

---

## 0. Headline finding

**The concept report is written as if Afrovanguard has dashboards that need an AI layer bolted on top. That premise is out of date.** Roughly 60% of what the report proposes is already implemented in this repository — including the parts the report treats as advanced (Phase 4 meeting intelligence, Phase 5 mentorship health).

The report's central instruction is right, but it applies more narrowly than the report thinks:

> §36: "Add an AI brain to the existing Afrovanguard body." Not: "Throw away everything and build another body."

The body is further along than the report assumes. What is genuinely missing is smaller, sharper, and cheaper than the report's Phase 1–8 framing implies. Three things account for almost all the real value:

1. **Commitments are extracted but never tracked.** The AI already reads meeting transcripts and produces `action_items` with owners — then writes them to a JSON blob and drops them. Nothing follows up. This is precisely the failure the report names in §11: *"We discussed it in the meeting, but nobody followed up."*
2. **The leadership ladder rewards headcount — the exact anti-pattern §17 warns against.** `lib/Levels.php` promotes O→A on **referral count**, stops at C, and has no concept of whether those referrals are actively mentored.
3. **Nothing escalates.** Inactive pairings are *already computed and already queryable* (`Mentorship::inactivePairs()`, exposed as `mentorship_inactive` in `admin/api.php:347`) — but an admin has to go and look. No one is ever told. There is no 1st/2nd/repeated ladder, and no leadership brief.

**Recommended entry point:** the report's Phase 3 (Accountability Engine), narrowed to commitments + escalation, riding on the cron and notification infrastructure that already exists. See §5.

---

## 1. What the report asks for

The report proposes an "Accountability Intelligence Layer" over Afrovanguard's existing systems, with a strict division of labour (§23):

| The AI does | Humans keep |
|---|---|
| Observe → Record → Analyse → Remind → Recommend → Escalate | Interpret → Discern → Counsel → Decide → Correct → Promote |

### Four development dimensions (§3)

| Dimension | What it measures |
|---|---|
| **A. Character & Incorruptibility** | Behavioural consistency, kept promises, owned failures. §3A is explicit that this must **not** be reduced to a score — collect behavioural evidence, show leaders patterns. |
| **B. Personal Growth** | Individual goals: education, career, business, skills, health, certifications. |
| **C. Organizational Contribution** | Assigned tasks, deliverables, deadlines, event participation. §3C: distinguish **activity from impact**. |
| **D. Leadership Multiplication** | Whether this person can raise people who can raise people. |

### The O–G multiplication ladder (§4)

Eight levels, `O` (Ordinary) → `A`…`F` → `G` (Grand), where each level represents a doubling of the multiplication tree:

```
O  participant → accountable individual
A  ≥2 active mentees                    1 → 2
B  mentees have their own mentees        1 → 2 → 4
C                                        1 → 2 → 4 → 8
D                                        … → 16
E                                        … → 32
F                                        … → 64
G  mature multiplication network         … → 128
```

The word doing the work is **active** (§4A): "Simply listing two names should not qualify someone for advancement." §17 makes this a hard design principle — a person with 10 registered mentees of whom 1 is active represents *weaker* leadership than a person with 4 who all meet weekly and have begun mentoring others.

> §17: **Measure multiplication quality, not merely multiplication quantity.**

### Component checklist

- **AI Meeting Assistant** (§7–11): agenda proposal from prior minutes + open actions, pre-meeting pack distribution, in-meeting capture, duration warnings at 20/10/5 min, post-meeting minutes → action items into the task system.
- **Mentorship Accountability Engine** (§12–16): structured relationships, weekly cycle, missed-meeting ladder, Green/Amber/Red health, the mentorship tree.
- **Individual profile + scorecard** (§18–19): six dimensions, not one score.
- **Promotion recommendations** (§20): AI recommends, leadership approves — never blind automation.
- **Leadership alerts + NL Q&A** (§21–22): exception-based briefs; "Who has not met their mentor this month?"
- **Daily/weekly/monthly cycle** (§37).

---

## 2. What already exists (file-by-file map)

### 2.1 Mentorship — substantially built

`lib/Mentorship.php` (1,153 lines) is the most complete piece. It already implements most of §12–13.

| Report requirement | Status | Where |
|---|---|---|
| Structured mentor/mentee relationship | ✅ | `mentorships` table — `mentor_id`, `mentee_id`, `status` (pending/active/declined/ended), `segment`, `cohort_id`, `origin` |
| Mentor profile + capacity | ✅ | `mentor_profiles` — `focus`, `capacity`, `accepting`, `approval` |
| Goals per relationship | ✅ | `mentorships.goals` (free text) · `Mentorship::setGoals()` |
| Meeting frequency / cadence | ✅ | via `lib/Meetings.php` `FREQ` → real Google RRULE |
| Last meeting / next meeting | ✅ | `Mentorship::consistency()` returns `next`; `upcomingSessions()` |
| Attendance | ✅ | `mentor_sessions.attendance` = scheduled\|attended\|missed\|cancelled · `markAttendance()` |
| **Auto-logged, server-stamped meeting times** | ✅ *(beyond the report)* | `started_at`, `ended_at`, `last_ping`, `hours_source` (heartbeat → provisional, `meet`/`reports` → confirmed by Google), `reconcile()` |
| Structured session types | ✅ | `SESSION_TYPES`: kickoff / checkin / skills / review / wrapup — maps closely to §13's weekly cycle |
| Recorded outcome per session | ✅ | `mentor_sessions.outcome` · `recordOutcome()` |
| Transcript per session | ✅ | `transcript_url` · `attachTranscript()` |
| Consistency rate + streak | ✅ | `consistency()` → `held`, `attended`, `rate`, `streak`, `minutes` · `memberConsistency()` per member |
| Inactivity detection | ⚠️ **computed, pull-only** | `inactivePairs(21)` — a count in `adminStats()`, full list via `admin/api.php:347` (`mentorship_inactive`). An admin must go looking; nothing pushes it. |
| Escalation history | ❌ | no table, no ladder |
| Green/Amber/Red health | ❌ | raw materials all present (`rate`, `streak`, `last_session`) — no classifier |
| Mentorship **tree** / generations | ❌ | `mentorships` is a flat edge list. No recursive walk, no depth, no descendant count |

### 2.2 Meetings — substantially built, including the AI

`lib/Meetings.php` (823 lines) covers §7 and §11 better than the report anticipates.

| Report requirement | Status | Where |
|---|---|---|
| Google Calendar + Meet integration | ✅ | `provisionLink()`, `schedule()` — a real Calendar event, so invites and syncing are free |
| Recurrence | ✅ | `FREQ` → `FREQ=WEEKLY` etc. as a real Google recurrence rule |
| Agenda storage | ✅ | `meetings.agenda` column |
| **Agenda auto-proposal (§7)** | ❌ | column exists; nothing generates it |
| Pre-meeting pack distribution (§8) | ❌ | — |
| Transcript capture | ✅ | `pullGoogleTranscript()` — Meet REST API, then Drive Doc fallback; `RecallBot` recorder bots; manual paste |
| **AI minutes: summary / highlights / decisions / action items (§11)** | ✅ | `Meetings::structure()` — Gemini Flash preferred, Anthropic fallback, strict-JSON prompt, `do not invent facts` |
| Minutes distribution | ⚠️ partial | stored + viewable; not pushed to participants |
| **Action items → task system (§11)** | ❌ **the critical gap** | `structure()` returns `[{task, owner}]`, written to `meeting_transcripts.action_items` as a **JSON blob** and rendered read-only in `portal/meetings.js:60`. Never becomes a `collab_tasks` row. No `owner_id`, no deadline, no follow-up, no reminder. The owner is a **name string**, not a member. |
| Duration warnings 20/10/5 (§10) | ❌ | `meetings.duration_min` is stored but never used for warnings |

### 2.3 Levels — exists, but built on the wrong signal

`lib/Levels.php` (154 lines):

```php
public const ORDER = ['O', 'A', 'B', 'C'];   // report wants O..G (8 levels)
public const REFERRALS_FOR_A = 2;            // report wants 2 ACTIVE MENTEES
```

- `eligibleForNext()` returns true when a member has **introduced 2 people** — regardless of whether either is mentored, meeting, or progressing.
- B and C are pure leadership grants with no computed signal at all.
- D, E, F, G do not exist.
- Referrals are captured by an `av_ref` cookie on signup (`boot()`).

This is a **direct collision with §17**: the current engine rewards exactly the headcount the report says must not be rewarded. Promotion is driven by *recruitment*, not *multiplication quality*.

Also flagged for repair: `Levels::ensure()` writes raw SQLite-only DDL (`datetime('now')`, bare `ALTER TABLE … ADD COLUMN TEXT`) instead of going through `Database::execSchema()` / `translateDDL()` like every other class. It will misbehave on the MySQL/Postgres targets the repo otherwise supports (`db/schema.mysql.sql`, `db/schema.pgsql.sql`).

### 2.4 Goals — exists, wrong scope

`lib/Goals.php` (133 lines) is **org-wide team OKRs** (`team_goals`: `author_id`, `title`, `target`, `progress` 0–100, `closed`). Shared list, visible to everyone.

The report's §3B/§18 needs **per-member personal goals** across categories (career / education / business / personal development), reviewed on a cycle, with an explanation required when incomplete. That does not exist.

Notably, `Collab::aiTasksFromGoal()` **already does AI goal→task decomposition** with realistic day offsets and priorities — the exact pattern needed to turn meeting action items into tracked commitments. The plumbing is written; it is simply not wired to meetings.

### 2.5 The reminder / cron / notification spine — ready to build on

This is the most valuable existing asset for Phase 3, and the report doesn't know it exists.

| Piece | Where | What it does |
|---|---|---|
| Web-triggerable cron | `tasks/cron.php` | Designed for shared cPanel with no SSH. Key-authenticated (`AV_CRON_KEY`), rate-limited, CLI-capable. Runs webhooks, notifications, birthday emails. **This is the hook point for the daily accountability sweep.** |
| Notification inbox + delivery | `lib/Notifications.php` | `user_notifications` with a **`dedupe_key`** — so a 5-minute cron can't spam. `push()` + `email()` + portal bell. |
| Time-based dispatch | `Notifications::dispatchDue()` | Already sweeps three things: due reminders (**timezone-correct per user** — fires on *their* clock, not UTC), mentorship sessions starting within the hour, and task deadlines due-today/overdue (deduped per task per day). |
| Event bus → actions | `lib/Events.php`, `lib/AvAutomation.php` | `Events::emit()` / `on()`; automation subscribes and acts. Every action wrapped so automation can never break its trigger. Already handles `mentorship.session_scheduled`. |
| Org task pool | `lib/Collab.php` | `collab_tasks`: `creator_id`, `assignee_id`, `title`, `due`, `done`, `priority`, `goal_id`. Claim/release pool model. |
| Personal reminders | `lib/Reminders.php` | Manual, per-user. |
| AI clients | `lib/Gemini.php` (`gemini-2.0-flash`), `lib/AvBot.php` (Anthropic, `AV_AI_MODEL`), `lib/Chioma.php` | Both configured, both env-gated, graceful when absent. |

**The report's Phase 3 is mostly a new sweep function inside an existing, working, deduped, timezone-aware cron.** That is a very different cost profile from "build an automation platform."

### 2.6 Everything else already present

Member directory (`lib/MemberDirectory.php`), portal surfaces (`portal/goals.php`, `meetings.php`, `reminders.php`, `standup.php`, `calendar.php`, `notifications.php`, `collab.php`, `polls.php`, `boards.php`), admin console (`admin/`), audit trail with undo (`lib/AdminAudit.php`), Google Workspace + push/watch channels (`lib/GoogleWorkspace.php`, `lib/GoogleWatch.php`, `webhooks/google.php`), NGV programme on its own DB, IQ quiz hub, Academy LMS.

---

## 3. Gap table

Effort is **estimated dev-days for one developer familiar with this codebase**, assuming the existing patterns are followed (portable DDL, fail-safe reads, `Events`, `Notifications` dedupe). Treat as planning figures, not quotes.

| # | Gap | Report § | Value | Effort | Notes |
|---|---|---|---|---|---|
| **G-1** | **Commitments are never tracked.** AI-extracted `action_items` sit in a JSON blob. | §11, §39.2 | 🔴 Critical | **3–4d** | Highest value-to-effort ratio in the whole report. `Collab::aiTasksFromGoal()` is the template; `collab_tasks` is the destination. Needs owner-name → `user_id` resolution (fuzzy match against `lms_users`, confirm-before-assign). |
| **G-2** | **No escalation ladder.** Missed meetings notify nobody. | §14 | 🔴 Critical | **3–4d** | 1st = gentle nudge, 2nd = firmer, 3rd+ = notify mentor's mentor. Needs a `mentorship_escalations` table for history. Rides `Notifications::push()` + `dedupe_key`. |
| **G-3** | **No relationship health (Green/Amber/Red).** | §15 | 🟠 High | **2d** | Pure derivation over data that already exists: `consistency().rate`, `.streak`, `inactivePairs()`, goal freshness. Thresholds must be config, not hardcoded (§27). |
| **G-4** | **No mentorship tree.** Flat edge list; no depth, generations, or descendants. | §16 | 🟠 High | **3–4d** | Recursive walk over `mentorships` with cycle protection + depth cap. Prerequisite for G-5. Cache per sweep; do not compute per page view. |
| **G-5** | **Levels stop at C and reward headcount.** | §4, §17, §20 | 🟠 High | **4–5d** | Extend `ORDER` to `O..G`; replace `REFERRALS_FOR_A` with verified-active-mentee criteria; add `recommendation()` returning evidence, **not** an auto-promotion. Also fix the SQLite-only DDL. |
| **G-6** | **No leadership exception brief.** | §21, §37, §38 | 🟠 High | **2–3d** | Composed from G-1…G-5 outputs. Weekly digest + an admin panel. Cheap once the engine exists — this is a read-model over the sweep. |
| **G-7** | **No agenda auto-proposal.** | §7 | 🟡 Medium | **2d** | `meetings.agenda` column already there. Inputs: previous minutes + open commitments for these attendees. Chair approves/edits — never auto-publish. |
| **G-8** | **No per-member personal goals.** `Goals` is org-wide. | §3B, §18 | 🟡 Medium | **3d** | New `member_goals` (category, target, due, status, review cadence). Don't overload `team_goals` — different owner semantics. |
| **G-9** | **No pre-meeting pack.** | §8 | 🟡 Medium | **1–2d** | Trivial once G-1 exists (the pack is mostly "open commitments for these people"). |
| **G-10** | **No four-dimension scorecard.** | §19 | 🟡 Medium | **2–3d** | Read-model over G-1…G-5. **§3A constraint: show behavioural evidence, not a character number.** |
| **G-11** | **No in-meeting duration warnings.** | §10 | 🟢 Low | **2–3d** | Needs a live client-side timer in `portal/meetings.js` against `duration_min`. Low value; the report itself calls the warning points configurable. |
| **G-12** | **No NL Q&A over accountability data.** | §22 | 🟢 Low | **4–5d+** | `AvBot`/`Chioma` exist but have no structured accountability context. Do this **last** — it's only as good as G-1…G-5. Needs care: never let the model invent a fact about a real person. |
| **G-13** | `Levels.php` uses SQLite-only DDL | — | 🔧 Debt | **0.5d** | Fold into G-5. Breaks MySQL/Postgres portability the rest of the app maintains. |

**Total for G-1…G-6 (the accountability core): ~17–22 dev-days.**

---

## 4. Schema changes needed

All additive. Follow the house pattern: `Database::execSchema()` for `CREATE TABLE`, `self::addCol()` for idempotent column adds, portable across SQLite/MySQL/Postgres.

### 4.1 New: `commitments` (G-1) — the keystone table

The report's §39.2 principle — *"No commitment should disappear"* — needs a first-class entity. `collab_tasks` is close but has no provenance, no explanation-on-miss, and no mentorship context.

```
commitments
  id, owner_id, source ('meeting'|'mentorship'|'self'|'admin'),
  source_id           -- meeting_id or mentorship_id
  session_id          -- mentor_sessions.id where applicable
  title, detail,
  due,                -- date, member's tz
  status              -- open | done | missed | deferred | cancelled
  completed_at,
  miss_reason,        -- §13 step 5: "What did you fail to accomplish? Why?"
  self_reported       -- §3A prizes voluntarily reported misses
  created_at, updated_at
  idx (owner_id, status, due), idx (source, source_id)
```

Two fields carry real weight: `miss_reason` implements §13's "Why?" step, and `self_reported` lets the scorecard surface the §3A behaviour that a bare completion rate hides — *"voluntarily reported two missed commitments"* is a **positive** character signal, not a negative one.

### 4.2 New: `mentorship_escalations` (G-2)

```
mentorship_escalations
  id, mentorship_id, level (1|2|3), reason ('missed_meeting'|'inactive'|'no_goals'),
  notified_user_id,          -- who was told
  session_id,                -- the miss that triggered it, if any
  resolved_at, resolved_by, note,
  created_at
  idx (mentorship_id, created_at)
```

`mentorships.escalation_level` + `last_escalated_at` as denormalised columns so the sweep doesn't aggregate on every tick.

### 4.3 New: `member_goals` (G-8)

```
member_goals
  id, user_id, category ('career'|'education'|'business'|'personal'|'health'|'service'),
  title, target, progress (0-100),
  due, status, review_cadence ('weekly'|'monthly'),
  last_reviewed_at, created_at, updated_at
  idx (user_id, status)
```

### 4.4 Column additions

| Table | Column | For |
|---|---|---|
| `mentorships` | `health` VARCHAR(8) — `green`\|`amber`\|`red` | G-3, cached per sweep |
| `mentorships` | `health_at`, `health_reason` | G-3 — always store *why*, for §23 human interpretation |
| `mentorships` | `escalation_level` INT, `last_escalated_at` | G-2 |
| `mentorships` | `cadence_days` INT DEFAULT 7 | G-2 — "weekly" must be configurable per §27, not assumed |
| `lms_users` | `level` — **widen the allowed set to `O..G`** | G-5 (column exists; `Levels::ORDER` is the limit) |
| `lms_users` | `level_at`, `level_by` | G-5 audit trail — who promoted, when |
| `meetings` | `agenda_source` VARCHAR(8) — `human`\|`ai` | G-7 — never hide that the agenda was machine-drafted |
| `meeting_transcripts` | `commitments_created` INT | G-1 idempotency — never double-create on re-structure |

### 4.5 Derived, not stored

The multiplication tree (G-4) and the scorecard (G-10) should be **computed and cached per sweep**, never stored as truth. Storing tree depth invites drift when a pairing ends. Cache in a `member_metrics` table with a `computed_at` stamp, and treat it as disposable.

---

## 5. Build order

The report's Phase 1 (audit) is **complete — this document is its deliverable**, alongside the existing `CODEBASE-AUDIT.md`. Phase 2 (rules) is a leadership task, not an engineering one. Phases 4 and 5 are largely already built (§2.2, §2.1).

So the real sequence starts at Phase 3 and is shorter than the report's eight phases.

### Step 1 — Rules first, in config (report §27) · ~1d + leadership time

> §27: "AI should not invent Afrovanguard's constitution. Afrovanguard defines the rules. AI enforces and monitors them."

Before code: get leadership to fix the numbers. Meeting cadence. Reminder lead time. How many misses before escalation, and to whom. What "active mentee" means. Level A→G criteria. Minimum tenure per level.

Land these as **config with documented defaults** (`config.example.php` + `.env.example`), never hardcoded constants. Every threshold in G-2/G-3/G-5 reads from here. This is the single highest-leverage hour in the project and it costs no development.

### Step 2 — G-1 Commitments · 3–4d

The keystone. Wire `Meetings::structure()` → `commitments`, following `Collab::aiTasksFromGoal()`. Owner resolution must be **confirm-before-assign**: propose the match, let the chair approve. An AI silently assigning work to the wrong person is worse than no automation.

Add the daily sweep to `tasks/cron.php` — due today, overdue, needs follow-up — via `Notifications::push()` with a per-day `dedupe_key`, exactly as the existing task-deadline sweep does.

**This step alone delivers the report's §28 claim: *"This alone could produce a major improvement."*** It is worth shipping and living with before building anything else.

### Step 3 — G-3 Health + G-2 Escalation · 5–6d

Health first (it's a pure derivation and makes escalation legible), then the ladder. Escalation targets come from Step 1's rules.

Two constraints from the report, both worth honouring literally:
- §14: *"The objective is not punishment. The objective is early intervention."* Word every notification accordingly.
- §23: the AI escalates; it does not judge. Every escalation carries its evidence so a human can disagree with it.

### Step 4 — G-4 Tree + G-5 O–G levels · 7–9d

Tree first, then rebuild `Levels` on top of it. Promotion becomes `recommendation()` — evidence and a suggested level, surfaced to leadership in `admin/`, **never** an automatic write. Per §20:

> "Promotion should be automated as a recommendation, not blindly automated."

Fix the portable-DDL debt (G-13) in the same pass.

### Step 5 — G-6 Leadership brief · 2–3d

The weekly digest of §21/§38. Cheap now, because it's a read-model over Steps 2–4. This is what converts leadership from chasing people to managing exceptions — and it's what makes the whole investment visible to the people who approved it.

### Step 6 — Pilot (report §32) · 6–8 weeks, no new development

20 mentors, 50–100 mentees. Measure what §32 lists: meeting completion, reminder response, goal completion, mentor/mentee activity, missed meetings, escalations, admin hours saved.

**Do not build Steps 7–8 during the pilot.** The pilot's job is to tell you which thresholds from Step 1 were wrong. They will be wrong — that's expected, and it's much cheaper to discover before more surface area is built on them.

### Step 7 — Everything else, in value order · as needed

G-7 agenda → G-9 pre-meeting pack → G-8 personal goals → G-10 scorecard → G-11 timer → G-12 NL Q&A.

G-12 deliberately last. It is the most demo-friendly feature and the least useful one: a natural-language interface over incomplete data produces confident wrong answers about real people's character. It needs G-1…G-5 underneath it to be worth anything.

### Step 8 — Org-wide rollout (report §33)

Levels verified by leadership, calendars connected, escalation live, briefs on.

---

## 6. Cost reality check (vs report §34–35)

The report's three-level cost framing (§35) is sound. Its cost *drivers* need correcting against what's actually installed.

### What the report gets right

§36 is the correct principle, and this repo is unusually well-positioned for it: no new platform is needed. Google Workspace, Calendar, Meet, Drive, transcripts, two AI providers, a working cron, a deduped notification system, and an event bus are **already integrated and env-gated**.

### What the report gets wrong

| Report assumption (§25, §34) | Reality |
|---|---|
| "Zapier or Make" for automation | **Not needed.** `tasks/cron.php` + `lib/Events.php` + `AvAutomation` already do this, in-repo, with no per-task subscription fee. Adding Zapier would mean paying to move data the app already owns. |
| "ChatGPT/OpenAI" | Already on **Gemini Flash** (minutes) + **Anthropic** (bot), both configured. No new vendor. |
| "Google Sheets" as a data store | Everything is already in a portable PDO database. Introducing Sheets would be a regression. |
| "A developer may be required to connect existing database / dashboard / Calendar / Meet / AI / email / task system" | **All seven connections already exist.** This was the expensive line item and it is largely spent. |
| "Approximately $1,000" (§34) | Reasonable order of magnitude for the *AI/API + hosting* year, but it was never the real cost. The real cost is the ~17–22 dev-days for G-1…G-6. |

### Revised cost shape

**Recurring (low, and mostly already being paid):**
- Gemini Flash for minutes — cheap per meeting; volume scales with meetings, not members.
- Anthropic for the bot/agenda — controllable via `AV_AI_MODEL`. `lib/AvBot.php` defaults to an Opus-class model; **for the sweep and agenda drafting, set a cheaper tier.** Reserve the expensive model for member-facing conversation. This one env var is the largest single lever on recurring AI spend.
- Google Workspace — already paid.
- Hosting — already paid; shared cPanel is sufficient, which is why `tasks/cron.php` exists in its web-triggerable form.
- Optional: Recall.ai recorder bots — **the one genuinely usage-priced external service.** Per-meeting-hour. Make it opt-in per meeting, not default-on; Google's own Meet transcripts are free and already wired.

**One-off:** the ~17–22 dev-days for the accountability core. Then reassess after the pilot.

**Level 1 in §35's terms is mostly already built.** The gap analysis in §3 above *is* Level 2, and it is ~17–22 days of work, not a platform build. Level 3 remains a genuine platform project and should stay explicitly out of scope until the pilot has run — per §36, and per the report's own warning: *"Do not start by building Level 3."*

---

## 7. Two design cautions

**7.1 The report's own §3A warning is the one most likely to be ignored under delivery pressure.**

> "The AI should never pretend that character can be perfectly reduced to a number."

A `character_score` column would be easy to add and would quietly undermine the entire premise. The schema in §4 deliberately has no such column. The scorecard (G-10) should render evidence — *"attended 11 of 12, completed 92%, voluntarily reported 2 misses"* — because that sentence is both more useful to a leader and more honest than `85%`. The `self_reported` flag exists precisely so that honesty registers as a positive signal rather than a dent in a completion rate.

**7.2 An accountability system is a surveillance system pointed at volunteers.**

The report doesn't raise this, and it should be decided by leadership before Step 2 ships, not after:

- Who can see a member's health status, miss reasons, and escalation history? The mentor? The mentor's mentor? Any admin? The repo already has divergent authorization models (`CODEBASE-AUDIT.md` M-1) — this data needs one clear, deliberate policy, not whichever model the new code happens to inherit.
- Members should be able to see their own record. A system that judges people without showing them what it recorded will not survive contact with a volunteer organisation.
- `miss_reason` will collect genuinely sensitive personal disclosures — illness, money, family. It deserves the same treatment as the PII already moved out of the web root via `av_private_path()`, and it should not be casually exposed through the integrations API.
- Escalation notification wording matters more than escalation logic. §14's "early intervention, not punishment" has to survive into the actual email text, or the system will read to members as a snitch.

---

## 8. Summary

| | |
|---|---|
| **Report's premise** | Afrovanguard has dashboards; add an AI accountability layer. |
| **Actual state** | ~60% built, including the parts the report treats as advanced (AI minutes, Google Meet integration, consistency tracking, mentorship sessions with server-stamped attendance). |
| **The three real gaps** | Commitments aren't tracked · nothing escalates · the level ladder rewards headcount (the exact §17 anti-pattern) and stops at C. |
| **Recommended first build** | G-1 Commitments (3–4d), after leadership fixes the §27 rules. |
| **Accountability core** | G-1…G-6, ~17–22 dev-days. |
| **Cost correction** | No Zapier, no OpenAI, no Sheets, no new platform. Recurring AI spend is controlled largely by `AV_AI_MODEL`. |
| **Do last** | G-12 NL Q&A — most demo-friendly, least useful without the data underneath. |
| **Do not build yet** | Level 3 "full AI OS" (§35), per the report's own §36. |

The report's closing question is the right one to hold the build to:

> §42: *Is Afrovanguard actually producing the incorruptible generation it exists to raise?*

Nothing in G-1…G-6 answers that question. What they do is make it answerable — by ensuring that responsibility, mentorship and multiplication stop disappearing unnoticed.
