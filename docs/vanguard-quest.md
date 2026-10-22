# The Vanguard Quest — what this repository holds

The Quest's rules live in `appadmin-oss/van-quest`: the seven values, the
seventy challenges, the ranks, the journey levels, the awardable actions and
the fines registry, as documents and as JSON. This file covers only what runs
here, and it is currently one thing.

## Two points ledgers, on purpose

> Points are awarded only for real evidence of change — not for attendance,
> knowledge, or talking. *(owner, 21 October 2026)*

| Ledger | Class | Granted for |
| --- | --- | --- |
| `gate_points` | `GateAttendance` | Arriving on time, three- and five-day runs, a perfect week, a birthday |
| `quest_points` | `QuestPoints` | Evidence somebody saw and a second person approved |

They were one table until October 2026, and `GateAttendance::points()` summed
both — so the number a rank would sit on was mostly punctuality, and nothing
could tell a point earned for evidence from a point earned for turning up
except by parsing the period string.

`Conduct` now grants an approved award through `QuestPoints::grant()`. The gate
is untouched: its points, its streaks and its gate pass work exactly as before.

## Granting

```php
QuestPoints::grant($memberId, $points, $period, $reason, $note, $byUid, $day, $valueKey);
```

`$period` names **the decision that caused the grant**, and the table is unique
on `(member_id, period)`. Approving the same case twice, replaying a log or
recomputing a rank grants nothing the second time. A period without a colon is
refused, because a bare label is not a decision.

| Period | Meaning |
| --- | --- |
| `award:<case>` | A compliance-approved award. **In use.** |
| `challenge:<member>:<challenge>` | One of the seventy, signed off. Not built. |
| `mentee-rank:<mentee>:<rank>` | A mentee reaching a rank credits their mentor. Not built. |

`grant()` returns `false` when nothing was written — including when the period
had already granted, which is not an error.

## Awards granted before the split

They are still in `gate_points` under `award:<case>`, counting toward members'
attendance totals.

```php
QuestPoints::adoptLegacyAwards();      // dry run: what would move
QuestPoints::adoptLegacyAwards(true);  // move it
```

**It is a dry run by default and nothing calls it.** Applying takes points off
the number shown on members' gate passes, which is the correct outcome and is
still a decision somebody should make rather than have happen on deploy. It is
idempotent: running it twice moves nothing the second time.

## The registry, vendored

`lib/quest/*.json` is a copy of van-quest's registry, and `lib/Quest.php` is
the only thing here that reads it. A copy because this site runs on shared
hosting with no shell: a rule the Quest depends on cannot sit behind a network
call that must never fail.

`tests/quest.test.php` holds the copy to the same invariants the source
validator enforces — seven values with the ids `MentorPortal::VALUES` already
stores against, seventy challenges ten to a value, rank bands meeting without
a gap, mentee floors that never fall, five journey levels with Visitor at
zero. A copy that has drifted into nonsense fails there rather than scoring
somebody wrongly.

Refresh it by copying the files across and running the test. Nothing restates
a registry number in PHP; if a threshold appears in both, one of them is
already wrong.

## Signing off one of the seventy

A challenge goes through the desk that already exists. `Conduct` gained a third
kind beside `fine` and `award`:

```php
Conduct::propose($mentorUid, $pairingId, [
    'kind'         => 'challenge',
    'challenge_id' => 'diligence.4',
    'occurred_on'  => '2026-10-22',
    'evidence'     => 'What was promised, when it was due, and when it landed.',
]);
```

Then compliance approves it, as for anything else, and the point lands under
`challenge:<member>:<challenge>`.

What the mentor does **not** do is price it. A challenge is worth what the
registry says — one point — and the case records the value it belongs to.

Four refusals worth knowing:

| Refused | Why |
| --- | --- |
| A challenge id outside the seventy | There is no such challenge |
| A challenge still `awaiting-official-text` | Nobody has written it, so nobody can be scored against it |
| The same challenge twice for one mentee | A challenge is proved once, ever |
| A second proposal while one waits | One at a time per mentee per challenge |

**The whole path is inert today**, because all seventy slots are empty. That is
correct: it is built, tested end to end against a registry the test supplies,
and will start working the moment the wording arrives — the ids are already
stable.

`Quest::progress($memberId)` returns `['done' => n, 'total' => 70]`.

## What is deliberately not here

No rank engine. `QuestPoints` stores and totals; what a total means is the
ladder's business, and the ladder is not built. `lib/Levels.php` runs a
different ladder on different criteria — see `van-quest/inventory/conflicts.md`,
finding 2 — and reconciling them is blocked on the owner.

## Screens still showing the gate's number

`gate-pass.php`, `portal/_attendance.php` and `Volunteers.php` read
`GateAttendance::points()`. That is attendance, and on an attendance screen it
is the right number. Nothing shows a Quest total yet.
