# Courses

Reading is half of the learning record. The other half is **courses**, and
most of them are taken somewhere else — freeCodeCamp, Google, Microsoft, or a
tutor at the centre. `lib/NgvCourses.php` owns them; `docs/ngv-reading.md`
owns the books. They are deliberately the same shape, because somebody who
has learned one sheet should not have to learn the other.

## Who, and how many

| | Target | Who sets it |
|---|---|---|
| **NextGen Vanguard** | 12 a year (`NgvCourses::TARGET_NGV`) | the office, `admin_` on `/academy/ngv/courses.php` |
| **A verified member or a volunteer** | none | themselves, optionally, on the card |

`targetFor()` is the one answer to "how many does this person owe", and the
asymmetry is the point. A progress bar reading **3 of 12** to somebody who
never agreed to twelve is a reproach, not information — so a member has **no
target and no clock** until they set one, and the card says so in those
words. Who may take a course at all is `NgvReading::mayRead()`: one grant for
the whole learning record, not two.

## The list is a menu with a required start

`GROUPS` is an order, not a filing system:

    Foundation · Business · Art · Media · Tech

**Only Foundation is asked of every vanguard** (`REQUIRED_GROUP`).
Communication, Google Workspace, AI and Automation are what the programme
expects of everybody. Business, Art, Media and Tech are a **menu** somebody
picks from with their track in mind, and the twelve are met with whatever they
choose. Drawing all eighteen as if they were compulsory tells an Art-track
vanguard they are fourteen courses behind, which is not true and is the kind
of thing that makes somebody stop. `progress()` therefore counts the
foundation separately (`foundation` / `foundation_total`), and the card marks
only that group.

The office adds, renames, re-links, covers and retires courses from
`/academy/ngv/courses.php`. Retire rather than delete: somebody who already
took it keeps it.

## What a course rests on

The same floor as a book, and for the same reason:

1. **At least one sitting** — when, for how long, which modules, where, what
   it covered, where they stand on it, what they will do. Without one,
   `save(..., submit: true)` refuses with `no_sessions`.
2. **A reflection on the whole course** — `MIN_REFLECTION` characters.
3. **One thing they have done or will do** — `MIN_TAKEAWAY`.
4. **A person checks it.** Nothing counts until a checker says so, and
   `NgvReading::selfDealing()` means nobody signs off their own.

**A certificate is evidence BESIDE the claim, never the claim itself.** It
says a provider's system recorded a completion; it does not say what the
person did. `setCertificate()` changes no status, a claim with a certificate
and no sittings still cannot be sent, and the sheet's own copy says so —
because somebody who thinks the upload is the point will stop writing.

### Logging a sitting starts the course

The sheet offers **Log a sitting** the moment it opens. It used to refuse the
save with "Start the course first — save it as a draft", which was true of the
data and false of the screen: people wrote three paragraphs about an evening's
work and lost them to a sentence telling them to have done something they had
no way of knowing about. `logSession()` opens the draft itself now, from the
course they are logging against. Reading does the same, from `book_id`.

## Where it is on screen

| Screen | What |
|---|---|
| `portal/_courses-card.php` | the card, on the Programme view and the Reading page — ONE partial, drawn from both |
| `academy/ngv/_course-modal.php` + `courses-take.js` | the sheet, drawn once per page by the card |
| `academy/ngv/courses.php` | the office's list: add, cover, retire, and the programme's target |
| `academy/ngv/_reading-review.php` | the desk — courses are checked beside the books, by the same people |
| `portal/notebooks.php`, `diary/export.php` | the sittings, as pages of the diary, read from the one place that owns them |

The sheet posts to `/academy/ngv/dashboard.php`, not `location.pathname`: a
member sees the card in the portal, which has no handler of its own. The
certificate is multipart and is handled **above** the `php://input` read, for
the same reason the damage photos are.

`NgvCourses::action()` is the one handler, returning null for "not mine", and
it gates on who may learn rather than on enrolment — a verified member taking
a course is not a vanguard and must not be told to apply.

## A name on every row of the desk

The queues join `ngv_participants`, which answers for a vanguard and for
nobody else. Since reading and courses are a member's and a volunteer's too,
every one of them read **"Member #412"** on the desk — not somebody a checker
can go and talk to. `NgvReading::nameRows()` fills those in with ONE query
against the main table, never one per row, and both queues use it.

## Tests

`tests/ngvcourses.test.php` — the progression and which part of it is
required, the target asymmetry, the no-sittings floor, self-dealing, the first
sitting opening the draft, the diary rows, and that the screens are wired to
all of it.
