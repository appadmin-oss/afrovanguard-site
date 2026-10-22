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

## What is deliberately not here

No rank engine. `QuestPoints` stores and totals; what a total means is the
ladder's business, and the ladder is not built. `lib/Levels.php` runs a
different ladder on different criteria — see `van-quest/inventory/conflicts.md`,
finding 2 — and reconciling them is blocked on the owner.

## Screens still showing the gate's number

`gate-pass.php`, `portal/_attendance.php` and `Volunteers.php` read
`GateAttendance::points()`. That is attendance, and on an attendance screen it
is the right number. Nothing shows a Quest total yet.
