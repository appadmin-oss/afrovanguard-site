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

## The lifecycle

```
draft ──submit──> submitted ──verify──> verified   (counts; closed for good)
  ^                    │
  │                    ├──send back──> resubmit ──> (editable again)
  └────────────────────┘
                       └──reject────> rejected     (does not count)
```

A **draft** is a workspace and is not held to the evidence floor — a
participant can jot a title while still reading. A **submitted** claim is
locked to the participant: a claim somebody is reading must not change
underneath them. **Verified** is permanent. **Rejected** and **resubmit** both
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

## Surfaces

- **`academy/ngv/dashboard.php`** — the shelf. Each slot shows its state
  (empty / draft / with your track lead / sent back / verified) and opens the
  claim sheet. There is no verdict control anywhere on the member side; a test
  asserts the page never calls `review()`.
- **`academy/ngv/books.php`** — the book list (admins). Add, correct, retire.
- **`academy/ngv/members.php`** — "Books to check". Flagged claims sort first
  with a red edge and their reasons spelled out; each shows the full
  reflection, the takeaway, a required note field and Verify / Send back /
  Reject. A claim on a listed book shows its chapter summaries, numbered,
  before the reflection on the whole book.

Verdicts email the participant (`notify()`) and write to `AdminAudit`.

## The spoken check

Everything above can, in principle, be passed by somebody who reads well and
did not read the book. This is the part that cannot be, and it works for a
reason that has nothing to do with software: a two-minute conversation about a
book is very hard to fake, and always has been. Every viva and seminar has
run on this.

So the code does not try to *be* the check. It does the three things a person
is bad at:

1. **Remembering one is due.** Every sixth verified book (`SPOT_EVERY`), a
   check opens by itself in `spotMaybeOpen()`, called only after a
   verification — never by anything the participant can trigger.
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

`ngv_books`, `ngv_book_claims` and `ngv_book_spot_checks`, in the NGV database.
A claim names its book by `book_id`, with `chapters` and `chapter_notes`.
`ngv_book_claims` is `UNIQUE(member_id, slot)`, `ngv_book_spot_checks` is
`UNIQUE(member_id, milestone)` so a milestone cannot open twice. Beyond the
claim fields: `fingerprint`, `flags`, `typed_ms`, `paste_count`, `reviewed_by`,
`reviewed_at`, `review_note`. `ensure()` runs both `execSchema()` and
`syncTablesFromDdl()` so columns added later reach installed databases — see
`docs/db-portability.md`.

## Tests

`tests/ngvreading.test.php`. The first assertion is the bypass regression; if
it ever fails, nothing else in the file means anything.
