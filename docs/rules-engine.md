# The rules engine, knowledge base and prompt templates

Three small subsystems make the Afrovanguard AI **dynamic** — driven by data the
organisation edits, rather than constants a developer compiled in:

| Class | Table | What it holds |
|---|---|---|
| `lib/AvRules.php` | `av_rules` | Every threshold the accountability engine obeys — cadence, escalation, health bands, the O–G ladder, promotion criteria. |
| `lib/AvKnowledge.php` | `av_knowledge` | What the assistants should know that no table can tell them, scoped per assistant. |
| `lib/AvPrompts.php` | `av_prompts` | The instructions each AI job follows, with the rules and knowledge appended automatically. |

All three are edited in **Studio → Rules & AI** (Super Admin for rules and
prompts; management level for knowledge).

The design principle comes from the concept report:

> AI should not invent Afrovanguard's constitution. Afrovanguard defines the
> rules. AI enforces and monitors them.

---

## 1. Rules

### Resolution order

```
Studio override (av_rules)  →  AV_* config constant / env var  →  built-in default
```

The middle step is what makes this additive: a deployment already setting `AV_*`
keeps working untouched, and the Studio simply takes precedence once someone
uses it. `AvRules::describe()` reports which of the three a value came from, and
the Studio shows it as a badge — *set here* / *from config·env* / *default* —
because "why is this rule not behaving as I expect" is the question the screen
exists to answer.

### Reading a rule

```php
AvRules::int('mentorship.cadence_days');     // 7
AvRules::bool('levels.auto_promote');        // false
AvRules::str('escalation.tone');             // 'supportive'
AvRules::list('levels.ladder');              // ['O','A','B','C','D','E','F','G']
AvRules::all();                              // everything, resolved
```

Reads are memoised per request and fail safe: an unreachable database yields the
declared defaults rather than throwing into the request.

### What stays in code

The **registry** — each rule's type, bounds, group, help text and documented
default — lives in `AvRules::DEFS`. That is a *schema*, not a policy. It is what
lets the Studio render an editor and reject nonsense before it reaches the
engine. The *values* are data.

To add a rule, add one entry to `DEFS`; the Studio, validation, env fallback and
prompt block pick it up with no further work.

### Validation

Values are **rejected, never coerced**. A cadence of `-3` does not become `0`
(which would put every pairing permanently overdue) — it is refused with a
message saying what the field accepts.

Two further guarantees:

- **A batch is atomic.** If any value in a save fails, *nothing* is written.
  Half-applied policy is worse than a rejected form.
- **Cross-rule coherence is checked** against what the set would *become*, not
  what it currently is. `AvRules::conflicts()` catches combinations no per-field
  check can see:
  - a Red attendance threshold at or above Amber (nothing would ever be Amber);
  - an "active mentee" window shorter than the required cadence (a pair meeting
    exactly on schedule would still read as inactive);
  - an inactivity threshold shorter than the cadence (every pairing permanently
    inactive);
  - a ladder with fewer than two levels, or duplicates.

A stored value that *later* fails validation — because bounds were tightened in a
release — falls back to the default instead of poisoning the engine.

### Undo

Rule changes are audited and reversible from **Studio → Activity**. Undoing a
change that had no previous override *removes* the override rather than writing a
value, so an undo can never turn a default into a pin.

---

## 2. Knowledge

Entries have a **scope**, a **priority** and an **active** flag.

- `all` reaches every assistant; `mentorship`, `meetings`, `levels`,
  `commitments` and `assistant` reach only their own prompts.
- Requesting a scope always includes the `all` entries alongside it.
- Priority orders what survives the size bound, so the most important entries are
  never the ones truncated away.
- Truncation drops **whole entries**, never half of one — half an instruction is
  worse than none, because the model will still act on it.

```php
AvKnowledge::asPromptBlock('mentorship');   // bounded text block
AvKnowledge::countActive('meetings');       // how many entries that scope feeds
```

The block is capped at 4,000 characters per scope. A long knowledge base cannot
quietly inflate the token cost of every AI call.

---

## 3. Prompts

Each template carries a documented default in code, so a fresh install works
with no setup and an operator can always get back to something sane.

```php
AvPrompts::render('goal.tasks', ['max' => '6', 'today' => '2026-08-17']);
```

Rendering does three things:

1. replaces `{{placeholders}}` with the supplied values;
2. **strips any placeholder left unfilled** — a model shown a raw
   `{{open_commitments}}` will cheerfully hallucinate its contents;
3. appends the live `AvRules` block and the relevant `AvKnowledge` block.

Step 3 is the point: a prompt author *cannot forget* to include Afrovanguard's
cadence or its "never score character" rule, because the renderer adds them.
Editing a template changes the **task** instructions; the constitution comes
from `AvRules` either way.

Saving text identical to the default clears the override instead of storing a
duplicate, so the *default* badge stays meaningful. A template that drops a
required variable is refused — otherwise the AI would receive a prompt with no
input, and the failure would surface at 3am in the cron rather than at edit time.

Current templates: `meeting.minutes`, `meeting.agenda`, `goal.tasks`,
`accountability.nudge`, `leadership.brief`, `promotion.recommendation`.

---

## 4. The levels ladder

`lib/Levels.php` reads its ladder and criteria from the rules, so `O..G` (or any
other progression leadership prefers) needs no code change.

The substantive change is **what earns advancement**. The original engine
promoted on **referral count**, which is recruitment, not leadership — it
rewarded exactly the headcount the report says must not be rewarded. Advancement
now tests *active, verified* mentorship:

- **Active mentee** — an active pairing with an **attended** session inside
  `mentorship.active_mentee_requires_days`. A merely *scheduled* session does not
  count, or a mentor could earn advancement by filling a calendar.
- **Multiplying mentee** — an active mentee who is themselves actively mentoring.
- **Depth** — generations of *distinct* people below a member, walked
  breadth-first with a visited set (so a cycle in hand-created pairings
  terminates) and an independent depth cap.

Referrals are still recorded — introducing people matters — but they no longer
buy a level on their own.

`Levels::recommend($userId)` returns `recommend`, `reasons`, `gaps` and
`metrics`. It **never writes**. `promoteIfEligible()` only promotes when
`levels.auto_promote` is deliberately switched on; with it off (the default, and
what the report asks for) it emits a `member.level.recommended` event and leaves
the decision to a human.

### A note on depth semantics

Depth is **shortest-path**, so a "diamond" does not inflate it: if a member
mentors both B and C, and B also mentors C, the network below them is still one
generation deep, because C is already reached directly. Counting the diamond as a
second generation would inflate a leader's apparent reach whenever one of their
mentees also mentors a peer — the same headcount inflation the model exists to
prevent. This is pinned in `tests/rules.test.php`.

---

## 5. The AI master switch

`ai.enabled` stops every model call without pulling credentials out of the
environment. With it off, the engine still tracks, reminds and escalates
deterministically — it just stops asking a model anything. `Collab::aiAvailable()`
and `Meetings::structure()` both honour it.

Two switches are deliberately **off** by default and documented as such in the
Studio, because turning them on changes the character of the system rather than
its configuration:

| Rule | Why it ships off |
|---|---|
| `levels.auto_promote` | The report is explicit (§20, §23): the AI recommends, leadership decides. |
| `ai.character_scores` | §3A forbids reducing character to a number. The scorecard shows behavioural evidence instead. |
| `commitments.auto_assign_owner` | The AI matches an action item's owner *name* to a member. Silently assigning work to the wrong person is worse than no automation. |

---

## 6. Testing

`tests/rules.test.php` pins the behaviour that would silently corrupt policy:
resolution order, rejection-not-coercion, batch atomicity, cross-rule coherence,
fallback on a now-invalid stored value, prompt interpolation and placeholder
stripping, required-variable enforcement, the dynamic ladder, active-vs-scheduled
mentorship, diamond-vs-chain depth, and the master switch.

```
php tests/run.php
```
