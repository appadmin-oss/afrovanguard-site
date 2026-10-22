# The Vanguard Quest — what this repository holds

> **Picking this up fresh?** Read `HANDOFF.md` in `appadmin-oss/van-quest`
> first — what is built, what is blocked, and four corrections already made
> that should not be made again.

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

`Quest::progress($memberId)` returns `['done' => n, 'total' => 70]`, and
`Quest::progressByValue($memberId)` the same per value. Both read
`QuestPoints::challengesDone()` — **one query**, not one per slot — and count
only ids the registry still has.

## A member's record

`QuestRecord::of($memberId)` assembles everything true of a member in the
Quest **except their rank** — points, points by value, progress against the
seventy, mentees, journey level and integrity standing. The rank is left out
because which ladder a member climbs is not settled: `Levels` runs `O`–`G` on
mentorship, tenure, attendance and commitment and uses no points at all, while
the Quest's pyramid runs the same eight letters on points and active direct
mentees.

### Two senses of "mentee", and the Quest uses the stricter

`Mentorship` already draws this line and it is the whole reason a quota is
worth anything:

| | Means | Gamed by |
| --- | --- | --- |
| `pairedMentees()` | A row with status `active` | Opening pairings |
| `activeMentees()` | A pairing actually being mentored | — |

Both count `vquest` pairings only. An Academy mentorship is valuable and is not
the relationship the pyramid's quota is about.

### The journey

Everybody is a **Visitor** until somebody says otherwise. `setJourney()` is a
person's decision, never arithmetic — the journey is a reading of how close
somebody stands to the culture, and no counter expresses that. Compliance or a
superadmin only, for now; see the open question below.

### Integrity findings

> A participant must not be able to compensate for serious integrity failures
> simply by accumulating points elsewhere.

`quest_findings` holds them, and `holdsIntegrity()` is a **gate, not a
deduction** — a fifty-point penalty for dishonesty says dishonesty costs fifty
points, and sets the price at which it is worth paying. Five hundred points do
not clear a finding; only resolving it does.

Resolving is reachable on purpose. A system nobody can recover from is not
incorruptible, it is unforgiving, and it teaches people to hide things. The
finding stays on the record; it stops standing.

`FINDING_KINDS` is a starting list, not a policy. The owner has not settled
what counts.

## The Pyramid Manual, 22 October 2026

The owner supplied the *Afrovanguard Leadership & Earning Pyramid Manual* and
decided where it contradicted the earlier brief (van-quest
`inventory/pyramid-manual.md`):

| Decision | What runs |
| --- | --- |
| Attendance counts | Punctuality and workshops are priced catalogue actions, decided at the desk like any other |
| Section 2 is the catalogue | `lib/quest/awardable-actions.json`; a mentor picks the deed, the catalogue prices it |
| Deduct, don't demote | Deductions (Scoring Board §3) post negative points through the desk; "loss of current level" is a **suggestion** |
| Build the money | Points against fines by income bracket, month by month (`QuestMoney`) |
| Every rule toggleable | `QuestRules` per line, AvRules group "Vanguard Quest" for the rest |
| AI to optimise the catalogue | `QuestAdvisor`, proposals a superadmin applies |

### The classes

| Class | Holds |
| --- | --- |
| `QuestRules` | Every catalogue line (awards, deductions, fines) switchable and re-priceable by a superadmin, logged, never losing the manual's price |
| `Conduct` | Now four kinds: `award` (by catalogue action), `deduction`, `fine` (manual's list, ledger reason), `challenge`. `withinCeiling()` holds an award to the level's monthly ceiling |
| `Levels` | With `levels.quest_pyramid` on, recommends on the next level's Quest points and direct mentees met (binding from B), 150 a month for 3 months, no open finding. Still only recommends. Titles follow the manual on screen (`levels.quest_titles`); the **ID card keeps its printed names** unless `levels.quest_titles_on_card` is switched on |
| `QuestLadder` | Suggestions — demotion, suspension, fine — accepted or dismissed by the committee; daily inactivity sweep; "mentee rises" +50 to the mentor on a recorded promotion |
| `QuestMoney` | Income brackets (₦ per point, fine scale); monthly preview and apply (offset posted as an NGV **waiver**); overdue sweep suggests suspension and the ₦2,000 fine; Reward Pool share of fines paid. Inert until `quest.money_enabled` and a bracket values a point |
| `QuestAdvisor` | Usage figures and signals in grouped queries; the model in four modes (prompt `quest.advisor`); proposals validated against the catalogue and applied only by a superadmin |
| `QuestDesk` | The desk's read model: one section registry, overview figures, the grouped register, promotion |

### The desk — /mentorship/compliance/

The portal's shell (avp.css/avp.js), one registry of sections
(`QuestDesk::SECTIONS`), a view file each under `mentorship/compliance/views/`:
Overview · Cases (one at a time or in bulk, count confirmed by a second press,
cap 200, per-case verdicts) · Suggestions · Promotions · Members (drawer:
journey, bracket, findings) · Money · Catalogue · Rules · AI advisor ·
Committee & kinds. Rules and the advisor are superadmin sections. Every form
is a plain post to `mentorship/conduct.php`; `desk.js` upgrades them in place.

Tests: `questrules`, `questdesk`, `questpyramid`, `questmoney`, `questadvisor`,
`questdeskui` (fixtures 8100–8599).

### Still waiting

| | |
| --- | --- |
| The seventy challenge texts | The sign-off path is inert without them |
| Income brackets and the value of a point | Money stays off until they are set on the desk |
| Earnings by level | The manual's share of CACENTRE service charges is recorded, not computed — the owner is sending more |
| What counts as an integrity finding | `FINDING_KINDS` is still a guess; the manual's theft and dishonesty row is the best candidate |
| The Reward Pool's percentage | 0 until leadership sets it |

## The member's page — Portal → Vanguard Quest → The Quest

`portal/views/quest.php`, in every account's menu, because everybody is a part
of the Quest. It reads `QuestRecord::of()` and nothing else decides anything.

| In order | Why |
| --- | --- |
| Journey level, Visitor (0) to Vanguard (4) | It leads. Most of the register is at zero points for a long time, and a page that opens on a number tells them the Quest is not about them |
| Quest points, challenges proved, people mentored | Quest points are named as separate from the gate pass's attendance points |
| An open integrity finding | Only while one stands — the doctrine says it is visible while it stands. Its kind and date, never its detail |
| The seven values | Points and challenges proved per value; says plainly that the challenge wording is not yet written |
| Recently recorded | The last six grants, with an empty state |

**The level sits second, beside the journey** — the Pyramid Manual settled the
ladder. The card shows the level held (a decision), what the next one asks in
points and mentees met, and this month against the level's ceiling.

`tests/questview.test.php` holds the order, the absent rank, and the finding's
visibility (fixtures 9800–9899).

## Bills and appeals — Portal → Vanguard Quest → Bills & appeals

*The owner, 22 October 2026: members can fine themselves; fines are proposed
like bills, approved by a committee, and can be appealed. Doctrine:
`van-quest/doctrine/06-awards-and-fines.md`.*

| Stage | Where | Code |
| --- | --- | --- |
| A mentor proposes a fine or deduction; the member is told at once | Mentor portal → Conduct | `Conduct::propose()` → `tellMember()` |
| The member accepts it or contests it with their side | `portal/views/bills.php` | `Conduct::respond()` |
| The committee decides; not before an answer or `quest.notice_days`; reject any time | Desk → Cases (shows the answer and their side) | `Conduct::decide()`, `noticeLeft()` |
| The member appeals once, within `quest.appeal_days`, on grounds | `portal/views/bills.php` | `Conduct::appeal()` |
| Somebody with no part in it hears it: upheld, overturned, reduced | Desk → Appeals | `Conduct::hearAppeal()` |
| A member reports themselves, from the catalogue, at its price | `portal/views/bills.php` | `Conduct::selfReport()` |

- Overturning a fine voids its NGV charge (`NgvFines::voidOne`); reducing voids
  it and issues the smaller one. Overturning a deduction removes its row
  (`QuestPoints::revoke`) and dismisses a demotion suggestion it raised;
  reducing re-posts it at the new size. Nothing is raised on appeal.
- A self-report is accepted on arrival, decided by somebody else, and not
  appealable. It cannot be an award.
- An open appeal holds the overdue-balance suspension and fine
  (`QuestMoney::sweep`).
- Switches, in Rules: `quest.self_report`, `quest.notice_days` (2),
  `quest.appeals_enabled`, `quest.appeal_days` (14).
- Tables: `conduct_cases` gains `response`, `response_note`, `responded_at`;
  `conduct_appeals` holds one appeal per case.
- `tests/questappeals.test.php` (fixtures 8700–8799).

## Screens still showing the gate's number

`gate-pass.php`, `portal/_attendance.php` and `Volunteers.php` read
`GateAttendance::points()`. That is attendance, and on an attendance screen it
is the right number.
