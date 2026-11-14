# Academy grading: teaching teams, graded items, marking and certificates

For the people who run the Academy. How a course is taught by a team, how work
is graded, who can do what, and when a learner gets a certificate.

Code: `lib/CourseTeam.php` (teams and permissions), `lib/Grading.php` (items,
weights, pass mark, a learner's standing), `lib/GradingWork.php` (submissions,
marking, moderation, release, the digest), `lib/PeerReview.php`,
`lib/LiveAssess.php`. Screens: `/academy/teach/<course>/` (instructor console),
`/academy/<course>/grades/` (the learner's grades), the Studio's course editor
(teams).

---

## 1. Teaching teams

A course has a **team**. Each member holds one role:

| | Lead | Instructor | Assistant |
|---|---|---|---|
| See the course's teaching pages | ✓ | ✓ | ✓ |
| Mark work (record rubric scores and feedback) | ✓ | ✓ | ✓ |
| Create, edit, publish and retire graded items | ✓ | ✓ | |
| Change weights | ✓ | ✓ | |
| Agree a final score where marks disagree (moderation) | ✓ | ✓ | |
| Release grades to learners | ✓ | ✓ | |
| Excuse an absence from a live assessment | ✓ | ✓ | |
| Edit the course, manage the team, set the pass mark | ✓ | | |

- **A course always keeps at least one lead.** The first person put on a team
  must be its lead; the last lead cannot be removed or demoted — make someone
  else lead first.
- **Academy staff** — accounts with the LMS role *admin* or *coordinator* — can
  do everything on every course without being on its team.
- **Where teams are managed:** admins in the Studio (course editor → *Teaching
  team*); a lead (or staff) in the instructor console (*Team* tab). Adding
  someone needs their Academy account email.
- Courses that had a single instructor before teams existed were moved onto a
  team automatically, with that instructor as lead. `courses.instructor_id`
  still exists and always names the (first) lead, so older screens stay right.
- The course page lists every member of the team, lead first.

For developers: every permission check is `CourseTeam::can($courseId, $userId,
$action)` with an action from `CourseTeam::RIGHTS`. Never write a role check of
your own; add a right to the table instead. Unknown actions are refused.

## 2. Graded items

A course may have graded items. Four kinds:

- **Quiz** — an existing lesson quiz counted towards the grade. Machine-marked:
  the best counted attempt is the score, and it counts as soon as it is taken.
- **Assignment** — the learner submits a written answer, a link and/or one file
  (images, PDF, office documents, zip; up to 10 MB). Instructors mark it
  against a rubric.
- **Peer-reviewed assignment** — learners review each other's work with the
  rubric (section 4).
- **Live assessment** — learners book a slot in a scheduled session and a panel
  scores them there with the rubric (section 5).

Each item has: a **weight**, a **passing score**, an optional **due date** (Lagos
time) with a **late window** (hours) and **late penalty** (%), a number of
**attempts**, and a **moderation threshold** (default 15% of the maximum).

**Rubrics** are written one criterion per line:

    Argument | 10 | 0: missing; 5: present but thin; 10: clear and evidenced
    Evidence | 10 | 0: none; 10: sourced

(name | maximum points | level descriptors). Markers see the descriptors and
score each criterion from 0 to its maximum.

**Rules:**

- **Drafts are invisible to learners.** Items are published together with
  *Publish*, which is refused unless all the course's items' weights add to
  exactly **100**. Once anything is published, weights can only be changed
  together (the *Weights* form) and must still add to 100.
- **Once learners have used an item** (submitted, booked, or taken its quiz),
  changing its rubric or its weight needs a **reason**, which is recorded in the
  audit trail like a regrade.
- **Used items are retired, never deleted.** A retired item leaves the grade but
  keeps its submissions and marks. Only an unused draft can be deleted.

## 3. Submitting, marking, moderation and release

**Submitting.** Inside the window and within the attempts. After the due date
but inside the late window, the submission is accepted and marked **late**;
after the window, it is refused. Files are stored outside the web root and
only the learner, the course team, staff and the peers assigned to review it
can download them.

**Marking.** The *Marking* tab is a queue, oldest first, filterable by item and
by state (needs marking, needs moderation, marked but not released,
everything). Only each learner's latest attempt is listed. Each marker records
their own rubric scores and feedback — **one mark per marker per submission**
(marking again edits your own mark). Marking is blind: you see other markers'
scores once you have marked, or if you can moderate.

**The final score:**

- one mark → it stands;
- two or more marks within the item's moderation threshold → their average;
- two or more marks further apart than the threshold → **flagged**. A lead or
  instructor agrees the final score, with a reason (recorded).

**Release.** Nothing a marker types is shown to the learner. A lead or
instructor presses *Release* for an item; every final, unflagged score is then
shown to its learner with the feedback, and the learner is emailed. A mark
changed after release is not shown until the item is released again. Flagged
work waits for moderation. Quizzes are not released: their scores count at
once, because the learner saw them on the lesson.

**Late penalty** comes off the final score at release and is shown separately
("marked 90%, less a 10% late penalty").

**Resubmission.** Within the attempts and before the window closes. **The
latest marked (released) attempt counts — not the best.** The learner's page
says so.

Who marked what and when is in `grade_marks` (marker, time) and in the audit
trail (`grading.mark`, `grading.agree`, `grading.release`, …).

## 4. Peer review

- A learner who has submitted is given `N` other submissions to review (the
  item's *peer reviews per submission*). The system chooses them, never the
  learner's own, and balances them so every submission gets its reviews.
- **A reviewer must have submitted themselves.** Reviewers do not see whose work
  it is; authors do not see who reviewed them.
- The score is the **median** of the peer scores once a submission has `N`
  reviews. It is released like any other grade.
- An instructor may mark any peer submission; their mark **overrides** the peer
  median.
- A submission still short of reviews when the **grace period** after the due
  date ends appears in the instructor's marking queue.
- A peer item takes one attempt (reviews attach to one submission).

## 5. Live assessments

- An instructor schedules one or more **sessions** for a live item: date, start
  and end (Lagos time), a place and/or meeting link, and a capacity.
- A learner **books a slot** from their grades page, or anyone on the team seats
  them from the session screen. **One booking per learner per item**; booking
  another session moves the booking. A full session refuses the booking — for
  the team too. A session that has started cannot be booked.
- Learners are **reminded the day before**, once.
- **On the day**, open *Graded items → the item → Open panel*. The roster is in
  booking order; mark each learner present or absent. Each panelist scores the
  learner in front of them with the rubric and a note — **one mark per
  panelist**, so the moderation threshold applies exactly as for assignments.
  Saving the same scores again (a retry on a weak connection) changes nothing.
- **Absent** uses an attempt and scores nothing. A lead or instructor may
  **excuse** it with a reason; the attempt is given back and the learner can
  book again. A learner absent for every attempt has missed the item (0).
- Results are seen only when **released**, as for every item.

## 6. Grades and the gradebook

- **The course grade** is the weighted average of the item scores (released
  scores, and 0 for anything missed). The learner's *Grades* page shows each
  item with its due date (or session), status — not started, submitted,
  marked, released, late, missed — score and feedback, their grade so far, and
  the average they still need on what remains to reach the pass mark.
- **The pass mark** defaults to **70** and is set by the lead (*Graded items*
  tab). A learner passes when **every item is passed and the course grade meets
  the pass mark**.
- **The gradebook** (*Gradebook* tab) lists every learner, each item's released
  score (unreleased finals in brackets, for the team only), the grade and the
  result, and exports as CSV (safe to open in Excel).

## 7. Certificates

- A course **without** graded items keeps the original rule: every lesson
  complete.
- A course **with** published graded items issues the certificate only when the
  learner has **passed every item and reached the pass mark**. Finishing the
  lessons alone is not enough. The certificate shows the **grade** it was
  issued on. It is issued automatically when the release that completes a pass
  goes out (and the learner is emailed).
- **An issued certificate is never revoked** by anything grading does — a later
  regrade, a retired item or a higher pass mark leaves it, and its recorded
  grade, as they were.

## 8. Notices and scheduled work

Sent through the site's mailer (best effort):

- to a learner when a grade is released, or re-released with new feedback;
- to a learner when their certificate is issued;
- to a learner the day before a booked live session;
- to every member of a course team, once a day, a **digest** of work waiting
  more than 3 days to be marked or moderated.

The digest and the reminders run from the web cron (`tasks/cron.php`, via
`GradingWork::cronTick()`), and each is recorded before it sends
(`grading_digests` per course per Lagos day; `live_bookings.reminded_at`), so a
cron every five minutes still sends each once.

## 9. Schema

Tables: `course_instructors`, `course_grading`, `graded_items`,
`grade_submissions`, `grade_marks`, `peer_reviews`, `live_sessions`,
`live_bookings`, `grading_digests`, plus `certificates.grade_pct`. They are
created at runtime by `CourseTeam::ensure()` / `Grading::ensure()` through
`Database::execSchema()` (no shell step), and the same definitions are in
`db/schema.sql`, `db/schema.mysql.sql` and `db/schema.pgsql.sql`;
`tests/grading.test.php` fails if the two drift. `live_bookings` holds "one
active booking per learner per item" in the database itself: it is UNIQUE on
(item, learner, `live_key`), and `live_key` is `live` only while a booking is
active.

## 10. Building on this

The next pieces (learners rating instructors, coordinator observations, an
instructor scorecard) should key on `CourseTeam::members()` /
`CourseTeam::coursesFor()` for who taught what, and add any new permission as
a right in `CourseTeam::RIGHTS` checked through `CourseTeam::can()`. Marks
(`grade_marks.marker_id`, timestamps) and the audit trail already record which
team member marked, moderated and released what.
