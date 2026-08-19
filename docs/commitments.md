# G-1 · Commitments

A commitment is a promise somebody made in a room, tracked until it is kept.

## Why this exists

`Meetings::structure()` and `Mentorship::structureSession()` already asked the
model for action items, and already stored them — as a JSON blob on the transcript
that nothing ever read again. So the system recorded that somebody promised
something and then forgot, which is verbatim the failure the concept report's §11
names: accountability that stops at minute-taking.

Nothing here invents work. It takes the action items the AI already extracts from
what people actually said, and gives them an owner, a deadline and a status so
they can be chased.

```
transcript → AI minutes → action_items ──▶ commitments ──▶ chair confirms owner
                                                │                    │
                                        (unassigned queue)      notification
                                                                     │
                                          cron sweep ──▶ overdue nudge, once a day
                                                                     │
                                     done / missed(+reason) ──▶ completion rate
                                                                     │
                                                        Levels::recommend()
```

## Four decisions, not implementation details

**1. Extraction never assigns.** `commitments.auto_assign_owner` ships **off**. The
model matches an owner *name* it heard in a transcript; silently assigning work to
the wrong Ada is worse than assigning it to nobody. A commitment arrives with
`owner_hint` set and `member_id` 0, and a human confirms — §23's division of
labour. The unassigned queue carries `suggested_member_id`, so confirming is a
decision about a name already on screen rather than a search: the human step is
made cheap, not absent.

A mentorship session names its owner by **role** (`mentor` / `mentee`), which
resolves from the pairing with certainty rather than by guessing at a name. That
certainty is spent on pre-filling the confirmation, not on skipping it — whether
to auto-assign is leadership's toggle to interpret, not this class's.

**2. Re-extraction is idempotent.** Minutes get re-structured: a bot re-uploads, an
admin re-runs the bench. Commitments key on `(kind, source, normalised title)`,
normalised hard enough that a model rephrasing its own wording still matches, with
a UNIQUE index as the backstop. A re-run refreshes the wording and never touches
status, owner or deadline — a human may have acted since, and a re-structure must
not undo their decision. A duplicated commitment is worse than a missed one,
because it makes the whole record untrustworthy.

**3. A miss reason is confidential.** `commitments.require_miss_reason` implements
§13 step 5 ("what did you fail to accomplish? why?"), which means this column will
collect disclosures about illness, money and family. `redactedFor()` removes it and
replaces it with a `has_miss_reason` flag, so a caller can tell a reason exists
without reading it. Every path that shows a commitment to anyone but its owner goes
through that. Pinned by a test that asserts the text never reaches an AI tool —
`level_check` hands `Levels::recommend()` through unprojected, and G-1 added fields
to exactly that method.

**4. Honesty is not punished.** A member who says "I did not do this" before being
asked sets `self_reported`, and `completion()` reports it *next to* the rate rather
than folded into it. A system that scores candour the same as silence teaches
people to stay silent, which is the opposite of the point.

## Rules (Studio → Rules & AI → Commitments)

| Rule | Default | What it does |
|---|---|---|
| `commitments.default_due_days` | 7 | Deadline for an action item the meeting did not date. |
| `commitments.overdue_grace_days` | 1 | How long past the deadline before it is chased. |
| `commitments.require_miss_reason` | on | Refuse a miss with no reason. |
| `commitments.auto_assign_owner` | **off** | Leave off. See decision 1. |
| `levels.min_commitment_pct` | 90 | Completion needed for a promotion recommendation. |

All five were labelled *awaiting commitment tracking* until now; G-1 is the
consumer, so the labels are gone. Seven rules remain pending — health (3),
escalation, agenda drafting, in-meeting timing, assistant tone — which is the
roadmap's remaining work, still made concrete in the Studio.

## Promotions

`Levels::recommend()` now reports `commitment_pct`, `commitments_settled`,
`commitments_kept` and `self_reported_misses`, and treats a rate below
`levels.min_commitment_pct` as a gap.

**Below five settled commitments it adds no gap at all.** `recommend` is "no gaps",
so treating *not yet observed* as a failure would have frozen every promotion in
the movement until commitments accumulated — punishing members for a hole in the
record rather than in their work. It reports `commitment_sample_short` so a UI can
say so, and nothing else. (This was written as a blocking gap first; the existing
promotion tests caught it.)

## Follow-up

`Commitments::sweepOverdue()` runs on every cron tick and nudges each overdue
commitment's owner, deduped per commitment per day by `Notifications`' own key — so
one that stays overdue for a week nags daily, not every few minutes.

## The portal surface

Two cards in the **Tasks** view — commitments sit beside tasks because both answer
"what do I owe", and the card copy draws the distinction: a task is work you took
on, a commitment is a promise you made out loud in a room.

**My commitments** lists what you promised, flags overdue against
`commitments.overdue_grace_days`, and offers *Kept it* / *Missed it*. Marking one
missed asks why, and that answer is shown back only to you — the card says so, in
those words, because a member typing a sentence about their mother's health should
be told where it goes before they type it. Below the list, the completion rate,
with self-reported misses called out as counting *in your favour*.

**Confirm who owns these** is the chair's side, and appears only when there is
something you may act on. It pre-selects the assistant's suggestion, so confirming
is one click when the model guessed right and a dropdown when it did not. Confirming
with nobody chosen is refused.

`portal/commitments.php` has two authorisation rules, not one:

| Actions | Rule |
|---|---|
| `mine`, `done`, `miss` | The caller's **own** commitments only. Their own miss reason is returned in full; nothing else is. |
| `queue`, `assign`, `cancel` | `Commitments::canManage()` — the meeting's creator or an invited attendee, or either party to a mentorship pairing. |

Not "any org member". Reassigning a promise made in a meeting you were not part of
is not an administrative convenience, and the chair who ran the meeting is the
person who actually knows which Ada was meant. Every queue row goes through
`redactedFor()` on the way out.

Reporting your own miss through this endpoint always sets `self_reported` — nobody
else can set that flag here, which is what makes it mean anything.

## What is not built yet

- **No escalation.** A commitment nudges its owner and stops there. Escalating to a
  mentor is G-2, whose `escalation.cooldown_days` rule and `accountability.nudge`
  prompt are both already written and still waiting for a caller.
- **No admin console view.** `Commitments::unassigned()` returns the whole queue
  unscoped for exactly that purpose; nothing in the Studio calls it yet.
- **No commitment surfaced on the meeting itself.** A meeting's minutes do not yet
  show what was filed from them, so the loop is visible from the member's side and
  not from the meeting's.
