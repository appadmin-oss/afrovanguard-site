# The 24-book challenge: how a book gets counted

## What this can and cannot promise

It cannot be made fraud-free, and nothing in this document should be read as
claiming otherwise. No software can tell the difference between a participant
who read a book and a participant who read a good summary of it and wrote
thoughtfully about that. Any system that claims it can is either wrong or
measuring something else.

What it does instead, and does reliably:

1. **Every claim carries evidence.** A slot cannot be marked without a title,
   an author, dates, a reflection of real length and a specific takeaway.
2. **A human decides.** Nothing counts until a track lead verifies it. The
   automatic checks never approve anything — they only sort the queue.
3. **The cheap fakes are caught.** Copied text, impossible dates, a book
   finished before the participant enrolled, four-plus books in a week — these
   are surfaced with the claim, in plain words.
4. **There is a trail.** Who verified what, when, and on what note.

The gap this leaves is the participant who reads a summary and writes well
about it. Two things narrow it. The takeaway field asks what they *changed*,
which is harder to produce from a summary than a description of the argument
is. And every sixth verified book the system opens a **spoken check**: it
picks one of their books at random and asks their track lead to raise it in
conversation. That is the only mechanism here that reaches the case, and it
is described in full below.

## Why the old version recorded nothing

`ngv_participants.books` is twenty-four characters, one per slot. It used to be
a field the participant edited: the dashboard rendered twenty-four toggles and
POSTed the string back through `NgvMember::saveSelf()`. A tap wrote a `1`.

So the column recorded that somebody tapped a box. Any check added anywhere
else would have been theatre, because the thing the checks protect could be
written around with a single request.

`books` is a **derived column** now. `NgvReading::syncBitstring()` is its only
writer, and it writes only what a reviewer has verified. `saveSelf()` drops the
key. `tests/ngvreading.test.php` asserts this first, before anything else,
because every other assertion in that file depends on it holding.

Rows that carried ticks from the old system keep them — they are not evidence,
but deleting somebody's record is worse than an honest note, so the dashboard
shows one saying those ticks predate verification.

## The book list, and chapter summaries

Admins prepare the books at **`/academy/ngv/books.php`**: title, author and
the number of chapters (1–80). A vanguard chooses from that list — there is no
title to type — and a claim takes its title and author from the list.

A claim carries **a summary of every chapter** (at least `MIN_CHAPTER`, 80
characters each, in `chapter_notes` as a JSON list) **and** the reflection and
takeaway on the whole book. Submitting names the first chapter that is short.

- A claim keeps the chapter count it was started with (`ngv_book_claims.chapters`),
  so correcting a book's count reshapes new claims, not one half written.
- A book is **retired**, never deleted: it leaves the list for new claims and
  every claim already on it keeps it.
- One book cannot fill two slots for the same vanguard (a rejected claim aside).
- Claims recorded before the list (`book_id` 0 with a title) keep the book
  they named and can still be put right in their own words.
- With everybody reading from one list, the likely copy is a chapter summary:
  a summary in the same words as another claim on the same book is flagged
  (`same-chapter-summary-as-another-participant`).

## The e-version

A book nobody can get hold of is a book nobody reads. So every book on the
list may carry an **e-version** — a copy somebody can actually open — and the
office adds it from the same screen, per book:

| | What it is | Stored as |
|---|---|---|
| **A file** | a PDF or an EPUB this office holds | `ebook_kind` `pdf` / `epub`, `ebook_url` the stored file |
| **A link** | where the book is free and legitimate — Project Gutenberg, a publisher's own page | `ebook_kind` `link` |

Both, because the office has some of each, and a field that took only one of
them would push the other into the note where nothing can open it.
`ebook_note` is for what neither can say: "chapters 1–8 only", "the 1994
edition", "free on Gutenberg". The note can be corrected on its own, without
re-uploading.

**It is never required.** Most of the list will have neither, which is fine: a
book with no e-version is one somebody borrows or buys. Nothing about the
claim, the floor or the checking changes.

What it is, not what it is called:

- A **link** must be `https://`. An `http://` link to a book is one somebody
  else can rewrite in transit.
- A **PDF** is accepted on its sniffed type. An **EPUB** cannot be: fileinfo on
  a shared host often calls one `application/zip`, so the sniff alone rejects
  real books and the `.epub` name alone accepts anything at all. The format
  settles it — the spec puts the string `mimetypeapplication/epub+zip` at byte
  30 of the file, and `isEpub()` reads it there. `/uploads` is under the web
  root, so the bytes are the only thing worth asking.
- `Storage::safeExt` knows `epub`, or an EPUB would be stored as `.bin` and no
  phone would open it.

**The size limit is the server's own.** PHP refuses an upload over
`upload_max_filesize` or `post_max_size` *before any of our code runs* —
`$_FILES` comes back empty, and a page that claims 25MB on a host capped at
2M can only say "no file arrived", which sends an operator hunting for a
problem with the file. `uploadLimitBytes()` reads the real number, the screen
states it, and the browser refuses an oversized file before the upload rather
than after it. Anything larger goes in as a link. Also: a file PHP rejected is
reported as a failed upload, never quietly read as "they must have meant to
edit the note".

Where somebody opens it: the **shelf row** (an Open button beside Continue)
and the **claim sheet**, next to the book they have just chosen — sending a
reader off to find the book is how a reading challenge stops at the people who
already own books.

Only a copy the organisation has the right to share should be uploaded; the
screen says so, and the choice is the office's.

## The sittings

A book is read over days, and the record used to keep only what was written
at the end. So the programme could see that somebody had finished twenty-four
books and nothing at all about the reading: not when it happened, not how long
it took, not what they made of it as they went.

**Every sitting is logged** (`ngv_reading_sessions`), and **a book cannot be
sent with none against it**. A sitting records:

| | |
|---|---|
| the day | not in the future, not before the day they started the book |
| how long | 1–`MAX_SESSION_MINUTES` (600) |
| how far | a chapter range (inside the book's own count) **or** the pages |
| where | a word is enough — at the centre, at home, on the bus |
| what it covered | ≥ `MIN_SESSION_NOTES` (120) in their own words |
| where they stand | what they **agree with or argue against, and why** — ≥ `MIN_SESSION_STANCE` (80) |
| what they will do | the **life application** of this reading — ≥ `MIN_SESSION_APPLICATION` (60) |
| how it felt | `MOODS` — **the only optional field** |

A claim with eight dated sittings behind it is also a far harder thing to
invent than one with none, which is the second reason the floor includes
them. Once the book is sent, its sittings stop moving too: the evidence under
a claim a reviewer is reading must not change any more than the claim does.

### The values are on the list, not on the sitting

A sitting is **not** asked which of the seven values it grew, and neither is
the book or the claim. What a given book grew in a given person is a
judgement for a conversation — the spoken check is where that conversation
already happens — and a dropdown at the end of every sitting would record a
guess as a fact and then report on it.

The values stay where they are useful: **a book on the list names the ones it
is there for** (`ngv_books.value_keys`, from `MentorPortal::VALUES`, never a
second copy). That is curation. The book list page shows, per value, what it
is taught as and how many books on the list speak to it, so a gap is visible
before a cohort reads its way into it. It says so in the page's own words: a
value with no books is one the list cannot speak to, and the table is for
choosing what to add next, not a measure of anybody's reading.

### In the diary

The diary's library has a **Reading** section: every sitting, newest first.
It is **read, not copied** —
`portal/notebooks.php?action=reading` reads `NgvReading::sessionsFor()`, so the
record a track lead checks and the page the member reads back are one thing.
It is read-only there for the same reason: a sitting is edited where it was
written, on the book itself.

"Download my diary" (`diary/export.php?scope=mine`) carries the reading with
it, interleaved in date order. It used to answer with everything except the
part a participant had written most of.

## The lifecycle

```
draft ──submit──> submitted ──verify──> verified   (counts; closed for good)
  ^                    │
  │                    ├──send back──> resubmit ──> (editable again)
  │                    │
  │                    └──reject────> rejected     (closed; does not count)
  │                                       │
  └───────────────────reopen──────────────┘        (a reviewer, with a reason)
```

A **rejection closes the claim**: the member cannot edit it or send it again.
That is what makes it a different decision from "send back to rewrite" —
which it was not, for a while, because a rejected claim was fully editable and
the only difference between the two verdicts was the word in the email. The
door back is a reviewer reopening it, from the "Not accepted" list on the
review desk, with a reason, on the record.

A **draft** is a workspace and is not held to the evidence floor — a
participant can jot a title while still reading, and it is where the sittings
are logged. A **submitted** claim is locked to the participant: a claim
somebody is reading must not change underneath them, and nor may the sittings
under it. **Verified** is permanent. **Rejected** and **resubmit** both
require the reviewer to write a reason; a refusal with no reason is how a
programme loses somebody who was telling the truth and never found out what
was wrong.

## The evidence floor

Enforced in `whyNotReady()` on submit only:

| Field | Requirement |
|---|---|
| Title, author | Both present |
| `started_on`, `finished_on` | Both present, start ≤ finish, finish not in the future |
| `reflection` | ≥ 320 characters of substance (`MIN_REFLECTION`), ≤ 6,000 |
| `takeaway` | ≥ 60 characters (`MIN_TAKEAWAY`) — what they did differently |
| sittings | **at least one** logged against the claim (see above) |

Over the ceiling is a **refusal with the number in it**, not a silent cut —
for the reflection (`MAX_REFLECTION`, 6,000), the takeaway (`MAX_TAKEAWAY`,
600) and each chapter summary. It used to truncate and say nothing, so
somebody who wrote at length lost the end of it and could then be asked in a
spoken check about a part the record no longer held.

"Substance" strips whitespace runs and repeated characters, so 320 spaces or
`aaaa…` does not clear the floor.

## The flags

`flag()` returns machine keys; `flagLabel()` turns each into a sentence a
reviewer can act on. **A flag is not a verdict** — the console says so, and a
flagged claim can still be verified (the tests assert this).

| Key | Means |
|---|---|
| `same-words-as-another-participant` | The reflection fingerprint matches another claim. Fingerprints normalise case, punctuation and spacing, so re-capitalising a copy does not hide it. |
| `started-and-finished-same-day` | Possible, common in fabrications. |
| `finished-before-they-enrolled` | Finish date precedes `start_date`. |
| `more-than-four-in-a-week` | Above `PACE_PER_WEEK`; a burst of backdated claims looks like this. |
| `pasted-rather-than-typed` | Browser signal. |
| `written-implausibly-fast` | Browser signal. |

The last two come from `typed_ms` and `paste_count`, collected by
`academy/ngv/reading.js`. **These are triage, not evidence.** They are client
values and anyone who can open devtools can send whatever they like. Their
labels say "not proof" in the reviewer's own words, and a test asserts that
wording stays there — a signal a reviewer trusts more than it deserves is worse
than no signal.

## How the challenge is going

`NgvReading::cohort()` answers the three questions a programme actually asks,
on the review desk, in four queries rather than one per participant:

- **Who has stalled** — started and logged nothing for four weeks, with an
  unfinished shelf. Somebody who has never logged a sitting is *not* stalled,
  they have not begun, and the table says which: a different conversation.
- **What has waited longest** — the oldest claim still unchecked, in days.

## Who may READ: not only a vanguard

The challenge began as a NextGen Vanguard thing and the code said so — the
only door was the NGV dashboard, gated on being enrolled. But reading is not
something the programme does TO vanguards; it is what the organisation asks
of the people in it, and a member or a volunteer who wanted to read
twenty-four books and have somebody check them was turned away by an
`isVanguard()` that had nothing to do with reading.

`NgvReading::mayRead()` answers for three people:

| | Why |
|---|---|
| a **NextGen Vanguard** | their programme includes it |
| a **verified member** | somebody established they are a member (`MemberVerification`) |
| a **volunteer** | explicitly one (`Volunteers::isVolunteer`) |

A learner nobody has verified is **not** included. The reading ends in a
conversation with somebody at the organisation, and there has to BE a
relationship for that conversation to happen.

### One handler, one card

`NgvReading::action($uid, $in)` is every reading action in one place, so the
NGV dashboard and the portal's Reading page cannot drift into doing them
differently. It returns `null` when `book_action` is not one of ours, so a
route calls it first and carries on with its own. The member id is the
caller's; it is never read from the body.

On the NGV dashboard it runs **above** the enrolment gate — a member or a
volunteer reading on that route is not enrolled and must not be told to
apply. The multipart actions (damage reports) are gated by name further up,
because `php://input` is empty on a multipart request and they have to be
handled before it is read.

`portal/_reading-card.php` is the card, drawn by the Programme view for a
vanguard and by `portal/views/reading.php` for everybody else. A vanguard
does not get the Reading section as well: one shelf, in one place per person
(`portal/index.php` computes `$mayRead` as *may read AND is not a vanguard*).

What varies between them is the **words**, not the machinery. A vanguard's
books are checked by their track lead; nobody else has one, and saying "your
track lead" to somebody with no track sends them looking for a person who
does not exist. The page names the checker (`window.NGV_CHECKER`), and the
default keeps the vanguard wording for any page that does not.

## Who may check the reading

Every member-facing string says the **track lead** checks the book. For a
while there was no track lead in the code: the only door to `review()` was the
NGV console, gated on `admin`/`superadmin`. So either a lead was handed the
roster, the fines desk, the ledger and the offline payments queue as well, or
the reading funnelled to the two or three people who had them — and it was
the second, which is why nobody noticed that three spoken checks in four were
never opening.

`NgvReading::canReview()` answers for an **NGV admin** and for a **member
granted the reading**, added on the book list page (`ngv_reading_reviewers`).
The grant is exactly that: it opens `academy/ngv/reading-review.php` and no
other NGV screen.

**Nobody signs off their own reading.** `NgvReading::selfDealing()` refuses
`review()` and `spotRecord()` where the actor is the subject, mirroring
`NgvLedger::selfDealing()` deliberately — one sentence, in the two places
money and merit are signed off. A `byUid` of 0 is the system and is exempt.

## Surfaces

- **`academy/ngv/dashboard.php`** — the shelf. Each slot shows its state
  (empty / draft / with your track lead / sent back / not accepted / verified)
  and opens the claim sheet, where the sittings are logged. There is no
  verdict control anywhere on the member side; a test asserts the page never
  calls `review()`.
- **`academy/ngv/books.php`** — the book list (admins). Add, correct, retire,
  name the values a book is on the list for, see what the list covers, and
  say who may check the reading.
- **`academy/ngv/reading-review.php`** — the review desk, for anybody who may
  check the reading. How the challenge is going across the cohort, the claims
  waiting, the rejections that can be reopened, and the conversations due,
  with counts.
- **`academy/ngv/members.php`** — the NGV console shows the same desk. Both
  include `academy/ngv/_reading-review.php`, so there is one of it and not two
  that drift; its script is self-contained and posts back to whichever page
  included it.
- **`portal/`** — the diary's **Reading** section, and the export.

A claim in the queue leads with its **sittings** — how many, how many minutes,
the span of days, with "No reading logged against
this book" in red when there are none — then the chapter summaries, then the
reflection and the takeaway. Flagged claims sort first with a red edge and
their reasons spelled out; each has a required note field and Verify / Send
back / Reject.

Verdicts email the participant (`notify()`) and write to `AdminAudit`.
`queueCount()` and `spotCount()` are on the NGV admin bar, because for a year
they existed and were called from nowhere: a claim was seen when somebody
happened to open the console, and a conversation was remembered when somebody
happened to scroll past it.

## The spoken check

Everything above can, in principle, be passed by somebody who reads well and
did not read the book. This is the part that cannot be, and it works for a
reason that has nothing to do with software: a two-minute conversation about a
book is very hard to fake, and always has been. Every viva and seminar has
run on this.

So the code does not try to *be* the check. It does the three things a person
is bad at:

1. **Remembering one is due.** Every sixth verified book (`SPOT_EVERY`), a
   check opens by itself in `spotMaybeOpen()`, called after a verification and
   again after a check is **recorded** — never by anything the participant can
   trigger. The milestone is the **lowest one still unserved**, not the one
   the current count sits on. Both halves matter: with only the first, a
   participant who finished all twenty-four books got ONE conversation
   instead of four, because the one-at-a-time rule below held milestone 6
   open while the count went past 12 and 18 and nothing ever came back for
   them. That is the normal case — a lead verifies a batch at a desk and has
   the conversations later — and it quietly removed three quarters of the
   only mechanism that reaches somebody who read a summary and wrote well.
2. **Choosing the book.** At random, with `random_int`, server-side,
   preferring one nobody has asked about yet. **The participant is never told
   which.** This is the load-bearing part: somebody who knows which book is
   coming can read that one properly and summarise the rest; they cannot
   prepare six. Their dashboard says a conversation is due and deliberately
   nothing more — `progress()` returns `spot_pending` as a **boolean** so the
   title is not even in scope on the page that could leak it, and a test
   asserts the dashboard never calls `spotOpen()`.
3. **Keeping the result.** Outcome, who asked, when, and the note, in
   `ngv_book_spot_checks` and the audit log.

Only one check is open per participant at a time. A track lead facing a
backlog of them does none of them.

The console shows the book, three prompts to open with, and — collapsed —
what the participant wrote they would change, so the answer can be weighed
against the claim.

**A failed check sends that one book back for resubmission and touches
nothing else.** It is one data point from one conversation: it may mean
somebody did not read the book, or that they were nervous, or read it eight
months ago. Treating it as proof of dishonesty would be the same overreach as
treating a paste count as proof, and a programme that voids a participant's
record over one awkward exchange earns the reputation that follows. The note
records what happened; a person decides what it means.

## Schema

`ngv_books`, `ngv_book_claims`, `ngv_book_spot_checks`, `ngv_reading_sessions`
and `ngv_reading_reviewers`, in the NGV database. A sitting names its claim,
its book and its slot; `ngv_books.value_keys` is the CSV of the values a book
is on the list for, and is the only place a value is stored.
A claim names its book by `book_id`, with `chapters` and `chapter_notes`.
`ngv_books` also carries `cover_url` and the e-version — `ebook_url`,
`ebook_kind` (`pdf` | `epub` | `link`) and `ebook_note`.
`ngv_book_claims` is `UNIQUE(member_id, slot)`, `ngv_book_spot_checks` is
`UNIQUE(member_id, milestone)` so a milestone cannot open twice. Beyond the
claim fields: `fingerprint`, `flags`, `typed_ms`, `paste_count`, `reviewed_by`,
`reviewed_at`, `review_note`. `ensure()` runs both `execSchema()` and
`syncTablesFromDdl()` so columns added later reach installed databases — see
`docs/db-portability.md`.

## Tests

`tests/ngvreading.test.php` — the library. The first assertion is the bypass
regression; if it ever fails, nothing else in the file means anything. Claims
are sent there the way a member sends them (`$rdSend`: draft, log a sitting,
send), because that is the flow now.

`tests/readingflow.test.php` — the ROUTES, driven over HTTP as a signed-in
vanguard: the claim sheet's contract, a book refused with no reading behind
it, sittings logged and taken back out, the book sent, the sittings frozen
with it, and the diary's Reading section reading the same rows back.
