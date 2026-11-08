# The Academy: the gradebook, the study coach, and who may teach

`LmsCourseware` owns graded work and discussions — see
`tests/courseware.test.php`. This is what sits beside it.

---

## The instructor's gradebook

A learner could see their own grades (`/academy/<course>/grades`) and an
instructor could see nobody's. `LmsCourseware::gradebook()` is the roster
view: one row per enrolled learner, one column per graded item, the weighted
overall, and how many tries each quiz took.

**It is the same answer the learner sees.** Both go through one private
`score()`, so the roster and the learner cannot disagree about what somebody
scored. That matters more than it sounds: with two implementations, an
unweighted mean on one side and a weighted one on the other differ by the
width of a pass mark — on the test course, 75 against 63.

It is **three queries** whatever the size of the roster. Calling `grades()`
once per learner would be two queries and a lesson walk each, which on a
roster of two hundred is where a popular course stops loading.

The dashboard also shows the course's **unanswered forum threads** first. A
thread nobody with an answer reads is worse than no thread at all.

## Who may teach

`promoteToInstructor()` promoted only a row whose role was exactly
`'learner'`. An account is created as a learner and becomes a `'member'` the
moment somebody joins or pays — **25 of 28 accounts on this deployment** — so
assigning a course to a real person set `courses.instructor_id`, left the
role alone, reported success, and locked them out of the dashboard for the
course they had just been given. Nothing said so.

Now: anybody **below** instructor is promoted, and anybody at or above it is
left alone — so assigning a course to an admin cannot quietly demote them,
which the obvious fix would have done.

## The study coach

`lib/LmsCoach.php`. **Off by default** (`academy.coach`), and silent when no
AI provider is configured — a control that cannot act is not drawn.

A **drawer beside the lesson**, opened from a control that is always on
screen, not a box below the fold: a learner who is stuck is stuck *here*,
with the paragraph still in front of them.

A **conversation**. The turns so far travel with each message (bounded at
`MAX_HISTORY`), so "say that again, simpler" means something. Four quick
actions cover what people actually ask for:

| | |
|---|---|
| **Summarise it** | the lesson in four or five lines |
| **Explain it simply** | the same thing, plainer, with one everyday comparison |
| **Give an example** | one worked example, finished, in a recognisable setting |
| **Quiz me** | one practice question, then feedback on the answer |

A lesson also carries an **In short** box — the same summary, generated once
and cached on the lesson against a hash of its body, so editing a lesson
regenerates it and reading one costs nothing. Its class is `.avle-brief`,
not `.avle-keys`: that one is the keyboard-shortcut hint and is
`display:none` under 640px, which would have hidden the summary on every
phone.

### The three rules holding it up

1. **Grounded.** The lesson text is the only source. Asked something the
   lesson does not cover, the coach replies with a fixed sentence — *"This
   lesson does not cover that."* — which the screen reads to offer the
   course forum instead. That wording is a contract between the prompt and
   the code; `ask()` matches on it, so it cannot drift.
2. **It never grades.** Not as a promise in a prompt: `LmsCoach` cannot
   reach `gradeQuiz`, `markComplete`, `issueCertificate`, `LmsCourseware` or
   any of the tables behind them, and the test asserts those strings are
   absent from the file. **Quiz me is practice** — it records no attempt, no
   score and no progress, and the panel says so under every practice turn.
3. **It is labelled, every time, with a way to reach a person.** Every
   answer is footed with where it came from and a link to the course's own
   forum — the only discussion there is, never a second one of the coach's.

`mode` is matched against a server-side list; a mode the page invents is
treated as a plain question. The history is relabelled server-side, so a
`role` a browser invents cannot become a line the model reads as its own
instruction. The endpoint reads **published** courses only, like every other
learner action.

## Schema

`lessons.ai_summary` and `lessons.ai_summary_hash`, in `db/schema.sql` and in
the additive migration. `db/schema.mysql.sql` and `db/schema.pgsql.sql` are
GENERATED from `db/schema.sql` by `Database::translateDDL()` — they had gone
stale and are regenerated here; do not hand-edit them.

## Tests

`tests/academylearn.test.php` — the gradebook's agreement with the learner's
page and its query shape, the promotion in both directions, and the coach's
modes, history handling and **absence of any path to a grade**.
