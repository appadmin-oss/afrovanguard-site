# The 24-book reading challenge — audit, 2 November 2026

Scope: `lib/NgvReading.php`, `academy/ngv/books.php`, the claim sheet
(`academy/ngv/reading.js`, `_book-modal.php`), the member surfaces
(`academy/ngv/_dashboard-body.php`, `portal/views/ngv.php`), the staff surfaces
(`academy/ngv/members.php`), `tests/ngvreading.test.php` and
`docs/ngv-reading.md`. Read against commit `5fbc4e7`.

Everything below was reproduced against a throwaway NGV database, not
production. No production data was read; this audit cannot say how many books
are on the live list or how many claims are waiting, and two of the findings
turn on exactly that (R6, R7).

---

> **Closed.** Every finding below was fixed on 2 November 2026 — see *What
> was done* at the foot of this page. The findings are kept as written,
> because what was wrong is the part worth reading later.

## The short version

**The design is sound and the implementation matches it.** The bitstring is
genuinely derived, the evidence floor is enforced where it says it is, the
flags are computed as described, the member side cannot reach a verdict, and
the spoken check's book is chosen server-side with `random_int` and never
leaves the server. Seventy-four assertions hold it there. This is a
well-built feature and most of what follows is at its edges.

**The one thing that is not working as the programme intends is the spoken
check.** It is described — correctly — as the only mechanism that reaches the
participant who read a summary and wrote well about it. A participant who
completes all twenty-four books gets **one** spoken check, not four, whenever
reviewing runs ahead of the conversations. That is the normal case.

| | Finding | Severity |
|---|---|---|
| R1 | A participant who finishes the challenge gets one spoken check instead of four | **High** · verified |
| R2 | Nobody is told there is anything to check | **High** · verified |
| R3 | A reviewer can verify their own book claims | **Medium** · verified |
| R4 | "Reject" and "send back" are the same thing from the member's side | **Medium** · verified |
| R5 | "Track lead" is copy, not a role — only full NGV admins can review | **Medium** · verified |
| R6 | A participant's book writing is in no export and no erase path | **Medium** · verified |
| R7 | Nothing reports on the challenge across the cohort | **Low** |
| R8 | A long reflection or takeaway is silently cut, with no word to the writer | **Low** · verified |
| R9 | One participant lookup per row in the review queue | **Low** · verified |

---

## R1 · High · Verified

### A participant who finishes the challenge gets one spoken check, not four

`SPOT_EVERY` is 6, so twenty-four books should mean four conversations — at 6,
12, 18 and 24. Only the first of them happens if the track lead verifies
faster than they talk, which is how the work actually goes: a batch of claims
is checked at a desk, the conversations happen later or not at all.

`spotMaybeOpen()` (`lib/NgvReading.php:787`) refuses to open a second check
while one is still open (`:799`), and that rule is right — the docstring's
reason is sound, and a track lead facing a backlog of them does none of them.
What is missing is the other half: **nothing ever comes back for the milestones
that were skipped while a check was open.** The only thing that opens a check
is a verification (`review()` at `:577`), and a participant on twenty-four
books has no verifications left to make.

Reproduced: one participant, twenty-four books, each verified immediately
after submission, no check ever recorded.

```
verified = 24
spoken checks opened: [{"milestone":6,"outcome":"open"}]
```

Recording the milestone-6 check afterwards opens nothing: milestones 12, 18
and 24 are gone for good.

The test suite encodes the gap rather than catching it.
`tests/ngvreading.test.php:279` takes a member from 6 to 12 verified with a
check still open and asserts `spotCount() === 1`. That assertion is correct
and should stay. The one beside it — that the skipped milestone arrives once
the queue drains — was never written.

**Fix:** have `spotRecord()` call `spotMaybeOpen()` after it records an
outcome, so the queue drains one at a time instead of silently dropping
milestones; and compute the milestone as the highest multiple of `SPOT_EVERY`
not yet served, rather than `intdiv($n, 6) * 6` (`:791`), so a participant who
jumped from 5 to 12 in one session is asked about 6 and then about 12, not
only about 12. Both are small. The second matters more than it looks: without
it, a lead who clears a backlog of claims in one evening retroactively excuses
the participant from every check in between.

---

## R2 · High · Verified

### Nobody is told there is anything to check

The whole scheme rests on a person doing two things: reading the claims, and
having the conversations. Nothing in the product asks them to.

- `NgvReading::queueCount()` (`:727`) and `NgvReading::spotCount()` (`:887`)
  exist and are called from nowhere outside the library and its tests.
- The NGV admin bar (`academy/ngv/index.php:74-84`) links to "Book list" and
  "Vanguards & applications" with no count on either.
- There is no email, no digest and no to-do entry for staff. The member's own
  to-do list does nudge them when a book is sent back (`lib/Todo.php:144`) —
  the other direction has nothing.

So a claim is seen when somebody happens to open `members.php#bookclaims`, and
a spoken check is remembered when somebody happens to scroll past
`#spotchecks`. A participant waiting on a verification has no way to tell the
difference between "nobody has looked" and "nobody is going to".

**Fix:** put the two counts where the work is — a badge on the console's own
links, and a weekly digest to whoever holds the programme. The counts are
already written; this is wiring, not building.

---

## R3 · Medium · Verified

### A reviewer can verify their own book claims

`review()` (`:577`) takes `$byUid` and never compares it with the claim's
`member_id`. An NGV admin who is also a participant — which the programme's
own shape makes likely, since leads come up through it — can verify their own
twenty-four.

Reproduced:

```
review(claim of member 7701, 'verified', byUid 7701) → {"ok":true,"verified":1}
bits: 100000000000000000000000
```

Nothing else about the feature is self-serve: the whole design is "a human
other than the claimant decides", and this is the one place it is not
enforced. The same shape was closed on the money side last week
(`NgvLedger::selfDealing()`, commit `f1d1d7c`), and the helper is already
there to reuse.

**Fix:** refuse `review()` and `spotRecord()` where the actor is the subject,
with the ledger's wording. A `byUid` of 0 stays exempt, as it does there.

---

## R4 · Medium · Verified

### "Reject" and "send back" are the same thing from the member's side

The console offers three verdicts and the documented lifecycle
(`docs/ngv-reading.md`) draws `rejected` as terminal — "does not count", with
no arrow back. In the code, `save()` (`:336`) refuses only `submitted` and
`verified`, so a **rejected claim is fully editable and can be resubmitted**.

```
review → rejected ;  status: rejected
member edits and submits again → {"ok":true,"status":"submitted"}
```

The member-facing pages already behave as the code does — `rejected` renders
with the same "back" styling as `resubmit` on both the dashboard
(`_dashboard-body.php:572`) and the portal (`portal/views/ngv.php:33`) — so it
is the documentation that is wrong, not the screens. But that leaves a real
question for the programme rather than for the code: the console presents
"Send back to rewrite" and "Reject claim" as two different decisions, and in
effect they differ only in the word in the audit trail, the wording of the
email, and whether that book is freed up to be claimed in another slot.

**Fix:** decide which it is, then make all three agree. If a rejection is
meant to be final, `save()` should refuse it and the member should be told to
use another slot. If it is not, the console should stop offering two verdicts
that do the same thing. Either way `docs/ngv-reading.md`'s lifecycle diagram
needs correcting.

---

## R5 · Medium · Verified

### "Track lead" is copy, not a role — only full NGV admins can review

Every member-facing string says the track lead checks the book: the dashboard,
the portal, the verdict emails (`notify()`), the fee pages. There is no
track-lead role in the code. `academy/ngv/members.php` gates its JSON actions
on `in_array($role, ['admin','superadmin'])`, and that is the only door to
`review()` and `spotRecord()`.

So either a track lead is given full NGV admin — which also reaches the member
roster, the fines desk, the ledger and the offline payments queue — or the
reading work funnels to the two or three people who have it. Both readings
point at the same consequence, and it is the most likely cause of R1 and R2
being invisible until now.

**Fix:** this is a programme decision before it is a code change. If track
leads are to verify, they need a capability that grants reading review and
nothing else — the shape exists in the sister site (`curriculum`, held per
person, in NGG's `admin_role_caps()`). If they are not, the copy should stop
saying they do.

---

## R6 · Medium · Verified

### A participant's book writing is in no export and no erase path

`ngv_book_claims` holds, per participant, up to twenty-four reflections of
320–6,000 characters, a takeaway each, and a summary of every chapter of every
book — long-form personal writing by young people, some of them minors. Grep
for the table across the repository returns `lib/NgvReading.php` and its test
and nothing else.

That means it is absent from whatever is built for the member-management
audit's H4 ("no way to export or delete a person's data, though the privacy
notice promises both"), and absent today from every other path that handles a
departing member. A subject access request answered from the roster and the
ledger would miss the single largest body of personal text the programme
holds about that person.

**Fix:** register the table with the export and erase work when it is built,
and decide now what erasure means for it — the reflections are also the
evidence behind a verified count that appears on a certificate, so "delete the
row" and "keep the count" have to be reconciled deliberately rather than
discovered later. The sister site hit exactly this shape with volunteer
records a week ago and chose explicit, audited retention.

---

## R7 · Low

### Nothing reports on the challenge across the cohort

There is no view of who has stalled, no distribution of verified counts, no
"claims waiting longest", no per-track comparison. `NgvJourney` reads one
participant's verified count for their own page; that is the whole of it.

A reading challenge is a thing a programme manages, and at the moment the only
way to ask "how is it going" is to open the console and count tiles. A claim
sent back and never touched again (`resubmit`) appears in no queue at all —
not the member's, beyond a to-do line, and not staff's.

**Fix:** one page: verified distribution, claims waiting and for how long,
checks open, and the participants who have not recorded a book in N weeks.

---

## R8 · Low · Verified

### A long reflection or takeaway is silently cut

`save()` truncates the reflection to `MAX_REFLECTION` (6,000) and the takeaway
to 600 with `mb_substr` (`:367`) and says nothing.

```
16,000 characters submitted → 6,000 stored
1,800 characters of takeaway → 600 stored
```

The floor is reported in careful, specific words ("Your reflection is 212
characters. We need at least 320…"). The ceiling is not reported at all, and
somebody who writes a long, genuine reflection loses the end of it without
being told — then may be asked in a spoken check about a part the record no
longer holds.

**Fix:** refuse over-length with the same voice as the floor, or warn in the
claim sheet as they type. The ceiling itself is right; silence about it is not.

---

## R9 · Low · Verified

### One participant lookup per row in the review queue

`academy/ngv/members.php:938` calls `NgvMember::participant()` inside the loop
over `NgvReading::queue(40)`, and `participant()` (`lib/NgvMember.php:101`) is
an uncached query each time — forty queries to put forty names on the page.

Measured cost is small today and this is not urgent. It is listed because the
spot-check queue two sections below already does it correctly, with a join
(`spotQueue()`, `:871`), so the fix is to copy the query that is already there.

---

## What was checked and found correct

Reproduced and confirmed working as documented:

- `ngv_participants.books` is derived. `saveSelf()` drops the key, only
  `syncBitstring()` writes it, and it follows a verification both up and down.
- The evidence floor is enforced on submit only, with the first short chapter
  named; a draft can be saved incomplete.
- A submitted claim is locked to the member; a verified one is closed for good.
- A retired book cannot start a new claim but is kept by claims already on it;
  a claim keeps the chapter count it was started with when the list changes.
- The same book cannot fill two slots, and a rejected one is excluded from
  that check as documented.
- Fingerprints normalise case and punctuation; a chapter summary copied from
  another participant on the same book is flagged.
- The spoken check's book is chosen with `random_int` from the claims nobody
  has asked about yet, is never sent to the member's page (`progress()`
  returns a boolean), and a failed check sends back exactly one book.
- A failed check requires a note; so do "reject" and "send back".
- Performance at realistic scale: 300 claims on one book, one further
  submission including the full duplicate scan took **6 ms**; `queue(50)` over
  301 submitted claims, **1 ms**. The chapter-duplicate scan reads every claim
  on the book, which looks alarming and is not.

---

---

## What was done, 2 November 2026

All nine are closed. Commits `ec9c89b`, `5145d00`, `30976cb`.

| | Fix | Where it is held |
|---|---|---|
| R1 | The milestone is the lowest **unserved** one, and `spotRecord()` re-opens after recording, so the queue drains in order one at a time instead of dropping what it could not hold. Twenty-four books now give four conversations. | `ngvreading.test.php` — a whole shelf verified in one sitting, then 6, 12, 18, 24 and no fifth |
| R2 | `queueCount()` and `spotCount()` on the NGV admin bar and on the new desk. | source assertions on the bar |
| R3 | `NgvReading::selfDealing()`, mirroring the ledger's, on `review()` and `spotRecord()`. | refusals asserted; uid 0 still exempt |
| R4 | A rejection closes the claim. The way back is a reviewer reopening it from a "Not accepted" list, with a reason. | closed, listed, reopened, rewritten |
| R5 | A grant that is exactly the reading (`ngv_reading_reviewers`), and `academy/ngv/reading-review.php` — the desk without the roster, the fines desk or the ledger. One partial, shared with the console. | granted, used, taken away |
| R6 | The reading is in the diary's **Reading** section and in "download my diary", read from `NgvReading::sessionsFor()` rather than copied. | the endpoint, the export, and the flow over HTTP |
| R7 | `NgvReading::cohort()` on the desk: who stalled, who has not begun, and what has waited longest. | stalled told apart from not-begun |
| R8 | Over the ceiling is a refusal with the number in it — reflection, takeaway and chapter summary. | refused, with nothing saved |
| R9 | `queue()` joins for the name, as `spotQueue()` always did. | the name rides on the row |

Beyond the audit, because the programme asked for it: **a book is read a
sitting at a time now** — the day, the minutes, how far, where, what it
covered, where the reader stands on it and what they will do differently. A
sitting was briefly asked which of the seven values it grew; that was taken
back out, and the values stay on the book list as curation. See
`docs/ngv-reading.md`.

## Suggested order

1. R1 and R2 together — they are the same failure seen from two sides, and
   both are small.
2. R3, with the ledger's existing helper.
3. R5, as a programme decision; it unblocks the people R1 and R2 are for.
4. R4 and R8 — a day between them.
5. R6 with the export and erase work, not before it.
6. R7 and R9 when the above are done.
