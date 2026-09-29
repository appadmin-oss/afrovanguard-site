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
about it. That is what the takeaway field is for — it asks what they *changed*,
which is harder to fake from a summary — and why track leads are asked to
raise one book per participant in conversation. That last part is a programme
practice, not a feature, and it is the only thing that closes the gap.

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
- **`academy/ngv/members.php`** — "Books to check". Flagged claims sort first
  with a red edge and their reasons spelled out; each shows the full
  reflection, the takeaway, a required note field and Verify / Send back /
  Reject.

Verdicts email the participant (`notify()`) and write to `AdminAudit`.

## Schema

`ngv_book_claims`, in the NGV database, `UNIQUE(member_id, slot)`. Beyond the
claim fields: `fingerprint`, `flags`, `typed_ms`, `paste_count`, `reviewed_by`,
`reviewed_at`, `review_note`. `ensure()` runs both `execSchema()` and
`syncTablesFromDdl()` so columns added later reach installed databases — see
`docs/db-portability.md`.

## Tests

`tests/ngvreading.test.php`. The first assertion is the bypass regression; if
it ever fails, nothing else in the file means anything.
