# Academy instructors: learners' feedback, teaching observations and the scorecard

For the people who run the Academy. How learners give feedback on a course, how
Academy staff observe teaching, and how every figure on an instructor's
scorecard is worked out. The first half — teaching teams, graded items,
marking and certificates — is in [academy-grading.md](academy-grading.md).

Code: `lib/LearnerFeedback.php`, `lib/Observations.php`, `lib/Scorecard.php`;
permissions in `lib/CourseTeam.php` (`RIGHTS`). Screens: the instructor console
`/academy/teach/` (*My scorecard*, *Observations*, and for staff *Instructors*
and *Feedback to moderate*), the learner's `/academy/<course>/grades/?feedback=1`,
and the public course page. The Academy Studio links to the instructor list
(*Instructors & feedback*); those pages need an Academy account with the role
**admin** or **coordinator**, because the Studio's admin token is not a person.

---

## 1. Learners give feedback on the course

**There are no ratings.** Learners do not give stars or scores to anybody — not
to instructors and not to courses — and nothing a learner writes is addressed
to, or attributed to, one instructor. Feedback is on the **course**, and the
whole course team reads it.

**What a learner writes:** answers to three optional prompts —
*What helped you learn on this course?*, *What could be clearer?* and
*Anything else the course team should know?* At least one box must have
something in it.

**Who may give feedback:** a learner enrolled on the course (not on its team)
who has **completed at least half its lessons**, or **had at least one grade
released** on it.

- **One per learner per course.** Giving feedback again edits the same entry;
  the database refuses a second one too.
- **Editable for 30 days** from when it was first given, then fixed.
- **The invitation:** once a learner qualifies, their grades page and the
  course page invite them. The web cron sends **one** reminder email per learner
  per course, ever (`feedback_prompts`), and no more.

### Anonymity, approval and the threshold of three

- **The team never sees who wrote it.** No screen a team member sees, and no
  export, carries a learner's name, email, id or even a date. The moderation
  queue does not show who wrote it either.
- **Staff approve first.** Every piece waits in *Feedback to moderate* until an
  admin or coordinator approves it. Hiding needs a reason: **abusive**,
  **identifying**, or **off-topic**. Nobody moderates feedback on a course they
  teach (it is not in their queue). If the learner changes their words, it goes
  back to the queue.
- **Three before anything is shown.** Approved feedback reaches the team only
  once **at least 3 learners on that course have given feedback** (approved or
  not), so a single comment cannot be traced to the one learner who wrote it.
  Until then the scorecard says how many have given feedback so far.

### Testimonials on the course page

The course page shows **no stars and no averages**. It may quote a learner's
answer to the first prompt (*What helped you learn…?*) as a testimonial —
marked *Shared with permission*, with their first name and last initial
("Ada O.") — **only** if the learner ticked the box allowing it (it starts
unticked) **and** staff approved the feedback. What they wrote for the team
about what could be clearer is never public. Unticking the box takes the quote
down at once.

## 2. Teaching observations

An admin or coordinator records an observation of a member of a course's team:

- the **date**, and what was observed — a **live session** of the course, a
  **lesson**, or **other** (described in words);
- a score of **1 (not yet) to 4 (excellent)** against each **teaching
  criterion**;
- **strengths**, **areas to develop**, an **agreed action** and its
  **follow-up date**.

**The criteria are one list**, edited on the *Observations* page (default:
preparation, clarity, engagement, assessment and feedback, inclusion). A final
observation keeps the criteria it was scored against, so editing the list
never changes an old one.

**Draft, then final.** A draft is the observer's notes: the instructor cannot
see it and it is not on any scorecard. *Mark final* shares it with the
instructor (they are emailed), puts it on their scorecard, and fixes it — a
final observation is never edited or deleted. To be final, every criterion
must be scored, and an agreed action needs a follow-up date. Nobody observes
themselves.

The instructor can add their **own reflection** to a final observation, and
change it; nobody else writes it. Colleagues cannot see another person's
observations.

**The follow-up** is emailed to both the observer and the instructor the day
before its date (Lagos time), once — by the web cron.

## 3. The instructor scorecard

*My scorecard* in the instructor console. **Who sees whose:**

| | Own | A team member's | Everyone's |
|---|---|---|---|
| Instructor or assistant | ✓ | | |
| Course lead | ✓ | only on the courses they lead | |
| Admin or coordinator | ✓ | ✓ | ✓ (and the instructor list) |

Choose a **period** (last 3, 6 or 12 months, or all time) and a course, or all
the courses you may see.

**Every figure is worked out from the record when the page loads — nothing is
stored as a total — and every figure shows what it was worked out from** ("from
6 marks"). A figure from **fewer than 5** is flagged *Small sample*: it is
shown, but read it as such.

| Figure | How it is worked out |
|---|---|
| **Course feedback** (per course) | *Received*: feedback learners gave on the course in the period. *Approved*: of those, approved by staff. *Shared*: approved and readable by the team (the course has feedback from at least 3 learners). Then the approved feedback itself. This is **the course's**, shared by everyone who teaches it — never attributed to the instructor. |
| **Observations** | Final observations in the period only. The latest (each criterion), the average of every score, and the average per criterion (each with its own count). |
| **Median days to mark** | For each mark this instructor made on an assignment or peer item: days from the learner submitting to this instructor's first mark on it. The median of those. Live assessments are left out — the panel scores in the room. |
| **90th percentile** | The same days, by nearest rank: sort them, take the value at position ⌈0.9 × n⌉. (For 0.5, 1, 2, 3, 4 and 10 days: median 2.5, 90th percentile 10.) |
| **Marked within 7 days** | The share of those marks made within 7 days. |
| **Moderation rate** | The share of all this instructor's marks (live panels included) on work that was flagged for moderation or settled by an agreed score. |
| **Release lag** | For releases this instructor pressed: days from a submission's final score to its release. The median. |
| **Course outcomes** | The courses' results for learners who enrolled in the period: completion rate (every lesson done), pass rate and average course grade (among learners whose grade is complete; graded courses only). Labelled as **course outcomes, not personal blame** — teams share courses. |

### The instructor list (staff)

*Instructors* lists everyone on a teaching team with the headline figures:
course feedback on their courses (received / approved / shared), observation
average, median days to mark, share within 7 days, moderation rate. Filter by
name, course, role and **needs attention** (median marking above 7 days, or an
observation average below 2.5). **Export CSV** is safe to open in Excel
(formula-looking cells are neutralised) and holds counts only — no feedback
text and no learner.

## 4. Permissions

Added to the one rights table, `CourseTeam::RIGHTS`:

| Right | Who |
|---|---|
| `view_team_scorecard` | a course **lead**, for the members of that course's team |
| `view_all_scorecards` | Academy staff only |
| `observe` | Academy staff only |
| `moderate_feedback` | Academy staff only |

Course feedback is read under `view` — every member of the course's team. An
empty list in `RIGHTS` means staff only; `CourseTeam::canAnywhere()` answers the
questions that are not about one course from the same table.

## 5. Scheduled work and schema

The web cron (`tasks/cron.php`) runs `LearnerFeedback::remind()` and
`Observations::remindFollowUps()`. Each claims its row before sending
(`feedback_prompts` per learner per course; `teaching_observations.followup_reminded_at`),
so a cron every five minutes still sends each once.

Tables: `course_feedback` (UNIQUE on course + learner), `feedback_prompts`,
`teaching_observations`. Created at runtime by `LearnerFeedback::ensure()` and
`Observations::ensure()` through `Database::execSchema()`, and in
`db/schema.sql`, `db/schema.mysql.sql` and `db/schema.pgsql.sql`;
`tests/instructorpages.test.php` fails if they drift, and `tests/mysql.test.php`
proves the tables, indexes and the duplicate refusal on a real MySQL server. The
observation criteria list is in `app_meta` (`academy.observation_criteria`).

## 6. Known limits

- The threshold of three counts learners who gave feedback on the course, at
  any time; feedback is not shown with dates, but a team that knows exactly
  who was in a very small course can still guess. Staff should hide anything
  *identifying*.
- Release lag uses the time a final score was last settled; a re-mark after
  release can make an old release look quicker. Negative lags are ignored.
