# The Academy: grades, discussions and the study coach

Three things a learner needs that the Academy did not have, and one that
looked like it worked.

---

## Grades

`quiz_attempts` has been written on every submission since the LMS shipped
and **read back by nothing**. A learner saw a score for the two seconds the
page held it; an instructor could not see a score at all. That is what "the
grades aren't working" was.

| Where | What |
|---|---|
| Course page → **Grades** tab | one row per graded lesson: best, pass mark, attempts, status |
| Instructor → **Gradebook** | one row per learner, one column per graded lesson, plus the course average |
| `LmsRepository::attempts()` | every attempt, newest first — the working behind a grade |

Two rules decide what a number means:

- **The BEST attempt counts, never the last.** The quiz copy invites a retake
  ("you can retake it"), and a scheme that then reads the most recent attempt
  punishes somebody for trying again.
- **The average is over what has been ATTEMPTED**, and the screen says so.
  Averaging an unreached quiz as a nought tells a learner three lessons in
  that they are failing a course they have not got to the end of.

A lesson is graded only if it carries a quiz; a pass mark is the lesson's
own. `gradesFor()` is two queries and `gradebook()` is two, whatever the size
of the roster — a per-learner query here is the shape that turns a popular
course into an outage.

## Discussions

There were none. A learner reading alone at eleven at night had nowhere to
put the one question standing between them and the next lesson, and an
instructor had no way to see that nine people were stuck on the same
paragraph.

`lib/LmsDiscussion.php`. One thread per **lesson**, replies one level deep,
newest question first and replies oldest first underneath. No votes, no
deeper nesting, no private messages: five affordances people use beat twenty
they do not.

- **Who may read and write is exactly who may read the lesson** —
  `LmsRepository::canAccess`. A discussion is part of its lesson, so it
  cannot be a looser gate: a paid course's questions are not public, and a
  preview lesson is as open as the lesson itself.
- **Moderation** belongs to the course's own instructor and to an Academy
  admin (`canModerate`). A moderator must give a reason; it goes on the
  record for the next moderator, never to the room.
- **A removal keeps the row and darkens the body.** "Removed by the
  instructor" where the words were is honest; a row that silently disappears
  makes a reply to it read as a reply to nothing. An author deleting their
  own is a different act and says so.
- **The instructor sees unanswered questions first** (`unanswered()`), on the
  course dashboard. A thread nobody with an answer ever reads is worse than
  no thread — it is a room where asking visibly does nothing.
- Posts render as **text**, never markup: it is somebody else's words on your
  page. Rate-limited per person.

## The study coach

`lib/LmsCoach.php`. **Off by default** (`academy.coach`), and silent when no
AI provider is configured — a control that cannot act is not drawn.

It is a **drawer beside the lesson**, opened from a control that is always on
screen, not a box below the fold: a learner who is stuck is stuck *here*,
with the paragraph still in front of them.

It is a **conversation**. The turns so far travel with each message (bounded
at `MAX_HISTORY`), so "say that again, simpler" means something. Four quick
actions cover what people actually ask for:

| | |
|---|---|
| **Summarise it** | the lesson in four or five lines |
| **Explain it simply** | the same thing, plainer, with one everyday comparison |
| **Give an example** | one worked example, finished, in a recognisable setting |
| **Quiz me** | one practice question, then feedback on the answer |

A lesson also carries an **In short** box — the same summary, generated once
and cached on the lesson against a hash of its body, so editing a lesson
regenerates it and reading one costs nothing.

### The three rules holding it up

1. **Grounded.** The lesson text is the only source. Asked something the
   lesson does not cover, the coach replies with a fixed sentence — *"This
   lesson does not cover that."* — which the screen reads to offer the
   discussion instead. That wording is a contract between the prompt and the
   code; `ask()` matches on it, so it cannot drift.
2. **It never grades.** Not as a promise in a prompt: `LmsCoach` cannot reach
   `gradeQuiz`, `markComplete`, `issueCertificate`, or any of the three
   tables behind them, and the test asserts those strings are absent from the
   file. **Quiz me is practice** — it records no attempt, no score and no
   progress, and the panel says so under every practice turn.
3. **It is labelled, every time, with a way to reach a person.** Every answer
   is footed with where it came from and a link to the discussion.

`mode` is matched against a server-side list; a mode the page invents is
treated as a plain question. The history is relabelled server-side, so a
`role` a browser invents cannot become a line the model reads as its own
instruction.

## Instructors could not reach their own dashboard

`promoteToInstructor()` promoted only a row whose role was exactly
`'learner'`. An account is created as a learner and becomes a `'member'` the
moment somebody joins or pays — **25 of 28 accounts on this deployment** — so
assigning a course to a real person set `courses.instructor_id`, left the
role alone, reported success, and locked them out of the dashboard for the
course they had just been given. Nothing said so.

Now: anybody **below** instructor is promoted, and anybody at or above it is
left alone — so assigning a course to an admin cannot quietly demote them,
which the obvious fix would have done.

## Schema

`lesson_posts` (new), and `lessons.ai_summary` + `lessons.ai_summary_hash`,
in all three schema files and in the additive migration, so an installed
database gets them without a hand-run.

## Tests

`tests/academylearn.test.php` — the grade arithmetic, the gradebook's query
shape, the promotion in both directions, the discussion's gating, nesting,
moderation and removal semantics, and the coach's modes, history handling and
**absence of any path to a grade**.
