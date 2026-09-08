# NGV · Fees, dues, fines, damage and receipts

What a NextGen Vanguard participant owes, what has been received, who decided
each of those things, and what the participant has been told.

## Why this exists

NGV already recorded payments: an append-only `ngv_payments` table, and a
`feeStatus()` that compared what had arrived against two constants in
`lib/NgvMember.php`. That answered exactly one question — "has this person paid
this month?" — and got several things wrong on the way:

- **Nothing was ever charged.** There was no record of an obligation, only of
  money arriving, so "outstanding" was an inference rather than a fact. Nobody
  could be shown what they owed, or why.
- **The figures were duplicated.** `MEMBERSHIP_YEARLY = 10000` sat in PHP while
  `/academy/ngv/` advertised "₦10,000 / year" from an admin-editable document.
  Edit the page and the ledger silently kept charging the old number — which is
  how somebody ends up holding a receipt that disagrees with the website.
- **There was no fine, no waiver, no arrears list, no reminder and no audit
  trail.** Voiding a payment took one click, no reason, and nobody's name.

`lib/NgvLedger.php` is the ledger those gaps needed. It is modelled on the NGG
fees subsystem (`api/_lib/domain/fees.php` in the nextgengen repo) and departs
from it wherever NGV is a different programme — every departure is listed under
[What NGV does differently](#what-ngv-does-differently-from-ngg).

## A ledger, not a payment processor

Nothing here takes money. It records what is owed and what staff confirm
arrived, by transfer or cash to the account printed on the programme schedule.
There is no gateway, no webhook and no card field anywhere in it, deliberately:
the moment this subsystem holds card data it becomes a different piece of
software with a different threat model, and the ledger has to exist and be
trusted first.

```
        the public page                          the ledger
  /academy/ngv/  (Studio-editable)
    fees[]  "₦10,000 / year" ──┐
    fees[]  "₦1,000 / month" ──┼─▶ amounts() ─▶ accrual ──▶ ngv_charges
    plans[] "₦240,000"      ───┘   (pinnable)      ▲             │
                                                   │             │
                    staff: fine · adjustment · training schedule │
                                                                 ▼
   staff console ─ payment / waiver / write-off ─▶ ngv_payments ─▶ account()
                                     payment ──▶ receipt (derived, emailed)
   members.php                                                       │
       ▲          ngv_damages ─ charged ─▶ fine ─▶ ngv_charges        │
       │               │  ▲                                          ▼
       │        status email                          ┌──── member dashboard
       │               ▼  │                           ├──── arrears list
       │          the participant ── reports damage ──┤
       └──── ngv_fee_requests ◀── "I need consideration this month"
                    │                                 ├──── reminders   (owing only)
                    └── answer, written back ─────────┴──── statements  (everyone)
```

## The four charges

| kind | cadence | posted by | page label |
|---|---|---|---|
| `membership` | yearly, while enrolled | accrual | Membership fee |
| `commitment` | monthly, while enrolled | accrual | Commitment fee |
| `programme` | the agreed instalment schedule | **staff** agree it; accrual walks it | the plans table |
| `fine` | never automatic | staff, with a reason — or a damage record | — |

…plus `adjustment`, for the correction that is neither a fine nor a mistake
worth erasing. Credits are `payment`, `waiver` and `writeoff`.

## Six decisions, not implementation details

**1. The amounts are read off the public page.** `NgvLedger::amounts()` parses
the live `Ngv` content — the fee rows by *name* (so reordering them on the page
cannot swap the yearly and the monthly charge) and each plan's price. An admin
who needs a figure the page cannot express pins it in the settings, and every
amount reports its `source` — `page`, `pinned` or `fallback` — so the console
shows where a number came from rather than asserting it.

**2. The training fee is agreed with the participant, not accrued from their
plan — and it has a shape.** Two problems, one answer.

A participant picks their own plan on their dashboard. If accrual priced from
that pick, a school leaver clicking "Full Programme" out of curiosity would give
themselves a ₦240,000 debt and the ledger would be right to insist on it.

And ₦240,000 posted as one charge is a wall: the dashboard reads ₦240,000
outstanding from the first day to the last, the arrears list pins that person to
the top, and a reminder quotes a figure nobody could pay this month. That is not
information — it is the same fact, shouted every fortnight.

So the fee is a **commitment**: a total, a start month, and a number of monthly
instalments (default 12; "in full" is one instalment of the same machinery).
Staff agree it once, prefilled from the plan catalogue; each instalment then
becomes an ordinary charge as its month arrives, through the same idempotent
accrual as everything else. What the participant sees is "instalment 4 of 12,
₦20,000, this month" — and an account of ₦27,000, not ₦247,000.

`training_total` is **frozen** when the commitment is made. A later edit to the
plan price on the public page changes what the next person is quoted and moves
nothing for somebody already paying; the remainder of an uneven division rides
on the **last** instalment, because a bigger opening bill is exactly backwards
for somebody deciding whether they can start at all.

Stopping a schedule halts future instalments and keeps the past — somebody who
paid four instalments and left paid four instalments. Whether the unpaid ones
should still be asked for is a separate decision with its own name: waive it, or
write it off. `trainingAuto` lets an organisation skip the agreeing step; it
ships **off**, and even then it starts a schedule rather than posting a lump
sum.

**3. Switching fees on does not back-charge history.** `accrueFrom` is stamped
with the month the ledger is first enabled and no charge is posted for a period
beginning before it. Without it, the first cron tick after this shipped would
have handed every existing participant a year of arrears nobody had discussed
with them. Back-dating is possible and is an explicit edit.

**4. Payments are allocated to a line, and paying ahead never hides arrears.**
This is NGV's own idea, kept from the code that preceded the ledger and *not*
taken from NGG, which nets one account-wide total. `payable` is the sum of the
per-line shortfalls; a surplus on a settled line is reported as `paidAhead`
instead of quietly cancelling something else out. "Square on membership, two
months behind on commitment" is actionable where "you owe ₦2,000" is a number
somebody has to come and ask about.

A credit with no line — every payment recorded before this ledger existed, and
any payment staff genuinely cannot allocate — goes to an unallocated pool that
reduces the account rather than a line.

**5. "No one is turned away for lack" is on the page, so the ledger can act on
it — and the participant can start the conversation.** Two halves.

`waive()` is a first-class operation: it keeps the charge visible and records who
set it aside and why. Voiding says "that charge should never have existed",
which is a different and usually untrue thing; a ledger that can only void loses
the reason the moment somebody edits history to be kind. Waivers are clamped to
what is actually outstanding, and what a waiver settled is never reported back
to the participant as money they paid. `writeOff()` is the same arithmetic and a
different sentence — the programme has stopped carrying the balance — so it is
stored as its own kind.

The page also says to "speak to your track lead or send a letter requesting
consideration". A dashboard that repeats that and offers nothing makes the
promise a dead end: the person who most needs it is the one least likely to walk
up to staff and start the conversation, and "send a letter" is a real barrier to
a nineteen-year-old who is already embarrassed. So a participant can raise a
**request** — "I need consideration this month" or "a figure here looks wrong" —
from their own account card. It is a message, never a decision: nothing they
write moves a figure. Staff see the queue **above** the arrears list, because
somebody who wrote to say they cannot pay is not a debtor to chase, and the
answer is written back to them in-app. Answering with nothing is refused; a
request that disappears teaches somebody that asking does not work, and next
time they stop coming instead. One open request at a time — a second does not
get anybody helped faster, it buries the first.

**6. Nothing on a cron path can change an amount.** NGG uprates its figures on a
schedule because they live in a settings row nobody looks at. NGV's live on a
public page an admin edits by hand, and a cron that rewrote it would change what
the programme advertises without anyone deciding to. Instead `reviewDue()` says
out loud, at most once a month, that the amounts have gone a year unreviewed —
and a human goes and looks.

## Damage

`lib/NgvDamage.php`. A damage report is an **incident**, not a charge.

Before it, the only way to record damage was a fine with reason `equipment` and
a note. That collapses three separate facts into one row:

| | |
|---|---|
| what happened, and when | a laptop screen cracked on 2 September |
| what it turned out to cost | ₦45,000, from a repairer, eight days later |
| what is being asked for | ₦15,000, agreed with them — or nothing at all |

Those are different numbers and they arrive days apart. A fine posted on the day
has to guess the cost; a fine posted when the quote lands loses the date it
happened; and one `charged` figure cannot say that the programme decided to
carry two thirds of it. So the incident is recorded first and **costs nothing**,
and a charge — if there is ever one — is a later, separate, audited decision
that the record points at by `entry_id`.

### The status is the product

| status | what it means | what is on the account |
|---|---|---|
| `reported` | it happened | nothing — not even priced |
| `assessing` | somebody is finding out what it costs | nothing |
| `charged` | an amount has been agreed | a `fine` with reason `equipment` |
| `waived` | assessed, and the programme is not asking | nothing (any charge is waived too) |
| `closed` | nothing owed — wear and tear, not their fault, already fixed | nothing |

**Every move emails the participant, and the first email matters most.** The
natural fear on hearing "damage to a laptop has been recorded" is a bill, and
the worst possible figure. So that email says in as many words that nothing has
been charged, that the cost is not yet known, and that they will be told what it
is before anything reaches their account. A process nobody can see is
indistinguishable from a threat.

Only real transitions send: re-saving the same status, or correcting a typo in an
assessment, does not send a second letter. `notify` turns the emails off per
record, for the case where the conversation is already happening face to face.

### Four decisions worth arguing with

**The charged figure has to be typed.** It is *not* defaulted to the assessed
cost — not even when they are the same number. Billing a nineteen-year-old the
full retail price of a laptop screen is a decision, and a decision that happens
by leaving a box empty is one nobody made. `totals()['absorbed']` is
`assessed − charged` across the programme, named for what it is: a programme that
never absorbs anything is a programme charging young people retail for accidents.

**The charge is an ordinary fine.** Not a private damage balance. It lands on
the fines line of their account, carries reason `equipment`, and is voided,
waived and reported by exactly the same paths as every other charge. A second
one is refused — the money moves once.

**Waiving a charged record settles the charge.** Otherwise the account keeps
asking for money the programme has just said it is not asking for, which is the
single most corrosive thing a ledger can do.

**A participant can report their own, and the record says so.** This is a
leadership programme: somebody who breaks something and says so has done the
thing the programme exists to teach, and a system whose only path is "staff
notice" quietly teaches the opposite. The console shows it as a credit —
*told us themselves* — not a confession.

An assessment that has had no update in 14 days is a stalled process, and the
person waiting on it has no way to chase. `NgvDamage::noteStale()` runs on the
cron and says so on their behalf.

## Receipts

A coordinator takes ₦5,000 in cash, types it into the console, and the payer
walks away with nothing. A transfer at least leaves a bank record on their side;
cash leaves them with no evidence at all, and the only copy of the fact lives in
a database they cannot read. Being asked to trust that a payment was recorded is
exactly the thing a ledger exists to remove.

**There is no receipts table.** A receipt is not a new entity — it *is* the
payment row, viewed a certain way, and a second table holding a copy of the
amount and the date is a second place for those to be wrong. Nothing is stored
that can be derived:

| | |
|---|---|
| the number | from the row id and its year — `NGV/2026/000042` |
| the code | an HMAC of the row id — `NGVR-A1B2C3D4E5` |
| cancelled | the payment row already says so |

Only `receipt_at` — when one was last emailed — is a real column, because that
is the one fact the payment row does not already contain.

**The number is the row id**, not a separate counter. A counter needs a table, a
lock, and a story about gaps, and it can hand two staff members the same number
on a Monday morning. The autoincrement id is already unique and monotonic on
every engine NGV runs on, so a receipt number can never point at the wrong
payment or at no payment.

**Verification is the certificate pattern**, deliberately: `receipt.php?id&c`,
an HMAC compared with `hash_equals`, and a failure state that reveals nothing —
not even whether that id exists. Anyone holding the link can check the receipt
is genuine without an account.

The page shows **one payment**: amount, what it was for, when, and to whom. Not
the balance, not the arrears, not the other payments, not any fine. Anybody with
the link sees the whole page — a guardian, a bank, a landlord being shown proof
of enrolment — and what somebody still owes is nobody's business but theirs.
There is no PDF library: the print stylesheet is the deliverable, which is also
the only thing that installs cleanly on shared cPanel.

**Voiding is the case that matters.** A payment entered twice gets voided, and
somebody is holding a receipt for money no longer on their account. Silence is
worse than never issuing one: they believe they have paid, and find out when a
reminder arrives. So a voided payment's receipt renders **cancelled** rather
than "not verified" — it was real, and then it was reversed — and voiding emails
them to say so. An *unsent* receipt sends no cancellation; there is nothing to
correct.

Only `payment` credits get one. A waiver is the programme deciding not to ask,
and receipting it would tell somebody they had paid money they never handed
over. The email can be suppressed per payment (`receipt => false`) for the one
case that needs it — staff typing in a backlog, where forty emails at once is a
fault, not a feature. The receipt still exists; only the letter is held.

## Statements are not reminders

Conflating them was the gap. A **reminder** chases money: it only goes to
somebody who owes, it is gated on a cadence, and it exists to produce a payment.
Which meant a participant who was square with the programme — or waived, or
three instalments into a schedule and exactly on track — could never be told any
of that. The only letter the system could send was a demand.

A **statement** says where you stand: every fee line including the settled ones,
the training instalments month by month, **each fine with the reason it was
issued and the date**, anything set aside, anything paid ahead, and every damage
report with its status. It goes to anybody, owing or not.

A fines *total* is the one figure on an account nobody accepts — "₦17,000 of
fines" starts an argument that "late arrival, 12 August · ₦2,000" settles. That
is why the statement itemises them.

Staff send statements per person or across the cohort; the batch run is
deliberately **not** cadence-gated, because a statement run is somebody choosing
to tell the cohort where they stand and a "too soon" skip would silently drop
people from a run staff believe went out. A participant can also ask for their
own from their dashboard — rate-limited to one a day so a nervous tap on the
button four times does not send four letters. That self-service path is the
point: the answer arrives without anybody having to start a conversation about
money in a corridor.

## Safety rails

- **Idempotent accrual.** `UNIQUE (member_id, kind, period)` on `ngv_charges`,
  plus a check-then-insert-ignore in `postCharge()`. A cron that overlaps a
  staff button cannot charge August twice.
- **A ceiling.** `balanceCap` (₦500,000 by default) stops the *accrual*, not a
  staff decision — the cap exists to stop an unattended process running somebody
  into a number nobody will pay, not to overrule the person standing in front of
  them. A fine posted past the ceiling succeeds and the response says it did.
- **Bounded batches.** Every sweep has a limit: accrual, the reminder run, the
  candidate scan, the arrears page and the lookup.
- **The sweeps group in SQL.** `NgvLedger::sweep()` answers "who is behind" for
  the whole roster in four queries. Building a balance per head is three round
  trips each, so a roster of three hundred cost nine hundred queries — the
  difference between a page that loads and a page nobody opens. The arithmetic
  lives once, in `reduce()`, which takes individual rows or pre-summed groups
  interchangeably: two copies of that sum is how an arrears list comes to
  disagree with the account it links to.
- **A reminder cadence floor** of 7 days, enforced on save rather than in the
  form, plus a per-participant off switch. Anything tighter is how a programme
  gets its sender blocked and its people to stop reading anything it sends.
- **Everything mutating is audited** under `AdminAudit`'s `ngv` area, with the
  amount and the reason in the detail.
- **Outbound mail has a kill switch.** `AV_MAIL_DISABLED=1` (env or constant)
  suppresses every send process-wide and reports failure rather than pretending
  to deliver. Receipts made this necessary: a staging or restored copy of the
  site carries the databases with it, so the first payment somebody records
  would email a real participant from a server nobody meant to be live. The test
  suite sets it too.
- **Nothing financial gates anything.** No balance reaches the public NGV page,
  the certificate page or the registration page, and no fee state gates a
  certification. `tests/ngvledger.test.php` asserts all of this, and
  `tests/ngvdamage.test.php` asserts the same for damage.
- **The member side reads and says; it never prices.** A participant can report
  damage, raise a request and ask for a statement. Every path that moves a
  figure — charge, waive, void, assess, agree a training fee — is staff-only,
  and both test files assert the dashboard source contains none of them.

## Only the enrolled accrue

An applicant has not started; somebody paused, withdrawn or completed has
stopped. Charging either is charging for a place nobody is taking up, so
accrual skips any participant whose status is not `active` and says which
reason it skipped for.

## Where it lives

| | |
|---|---|
| Domain | `lib/NgvLedger.php` (money) · `lib/NgvDamage.php` (incidents) |
| Schema | `lib/NgvDb.php` — `ngv_charges`, `ngv_fee_requests`, `ngv_damages` (new), `ngv_payments` and `ngv_participants` (extended) |
| Staff console | `/academy/ngv/members.php` |
| Member view | `/academy/ngv/dashboard.php` — read-only, always |
| Receipt | `/academy/ngv/receipt.php?id&c` — public, HMAC-verified, printable |
| Cron | `NgvLedger::cronTick()` from `tasks/cron.php` — accrue, remind, review nudge, stalled-assessment nudge |
| Settings | `app_meta` key `ngv_fees` |
| Tests | `tests/ngvledger.test.php`, `tests/ngvdamage.test.php`, `tests/ngvreceipt.test.php`, plus the NGV rows in `tests/drift.test.php` |

`lib/NgvMember.php` keeps `recordPayment()`, `payments()`, `feeStatus()` and
`account()` as thin pass-throughs, so nothing that called them had to change.

### Two tables, not one signed table

The accrual's safety property lives in a unique index that credits cannot share
— two payments in one month are two real events. NGV also has no migration
runner that could backfill a discriminator column onto the payment rows already
deployed. Separate tables give each side the constraint it needs and cost one
union in the domain layer.

`kind` and `period` are declared `VARCHAR`, not `TEXT`: MySQL cannot index a
`TEXT` column without a prefix length, and declared `TEXT` the unique index is
silently dropped there by `execSchema()`'s benign-error path — the accrual would
quietly lose its idempotency on exactly one engine.

Two more traps in the same function, both found the hard way. **No comment in
`NgvDb::ddl()` may contain a semicolon** — the DDL is split into statements by
exploding on it, so a semicolon in prose cuts a `CREATE` in half and both halves
fail silently down the benign-error path. And **no double quote or `$`** either:
`ddl()` is one double-quoted PHP string, so either ends it or interpolates.

## Rolling it out

1. Open **/academy/ngv/members.php → Fees & dues**. Check the three amounts and
   where each says it came from.
2. Set **Charge nothing before** to the month you are starting from. It is
   pre-filled with the month you switch fees on, which is almost always right.
3. Tick **Charge membership and monthly commitment** and save.
4. Press **Run accrual** once and read what it says it did.
5. For anybody on a paid plan, open their record and **agree the training fee**
   — the total is prefilled from their plan; pick how many months to spread it
   over. Nothing is charged beyond the instalments whose month has arrived.
6. Leave reminders off until the first accrual looks right. Then turn them on
   and use **Preview** before **Send them** — a message to sixty people cannot
   be recalled, and the preview names every exclusion and accounts for everybody
   on the roster.
7. Once the figures are right, **send statements** to the cohort. That is the
   letter that tells everybody where they stand, including the people who owe
   nothing — and it is the one worth sending first, before anybody is chased.

Existing `ngv_payments` rows need no migration: the additive schema sync gives
them `credit_kind = 'payment'`, which is what they are.

## What NGV does differently from NGG

| | NGG | NGV |
|---|---|---|
| monthly charge | "dues" | "commitment" — the page's own word |
| training fee | per programme session, accrued | per plan, **agreed with the participant** as monthly instalments |
| earn-off | written down as the member serves | **none** — Phase 2 is a *paid* internship with weekly stipends, so writing the fee down as well would be paying twice. Where a fee should not be collected, that is a waiver |
| who is chased | the child's guardian | the participant — NGV members are adults with accounts here, so a reminder goes in-app as well as by email |
| hardship | the coordinator notices, or nobody does | the participant can **ask**, from their own dashboard, and gets a written answer back |
| damage | a fine with reason `equipment` and a note | an **incident record** with its own status, emailed at every step; the fine is one possible outcome |
| letters | reminders only, to people who owe | reminders, **statements** (to anybody, including somebody who owes nothing) and **receipts** (per payment, verifiable by a third party) |
| amounts | admin settings | read off the public page, pinnable |
| price rises | automatic uprate, proposed then applied | no uprate; a review nudge only |
| allocation | one netted account total | per fee line, with `paidAhead` reported |
| rollout | greenfield | `accrueFrom` guard, because NGV has history |

## What is not built yet

- **No self-service payment.** Deliberate, and the first thing to argue about
  rather than the first thing to add: see "a ledger, not a payment processor".
  `lib/Payments.php` (Paystack) exists for donations and is not wired here.
- **No bulk receipt back-fill.** Payments recorded before receipts existed have
  numbers and links already — nothing needs generating — but sending them is one
  press per payment. Fine for a cohort, tedious for a year of history.
- **No photos on a damage record.** A cracked screen is a thing you would
  photograph, and a description is what has to stand in for it. `lib/Storage.php`
  and Cloudinary both exist; nothing is wired.
- **No email or push when a request is raised.** Staff see the queue when they
  open the console, and the count sits on a tile; nothing pages them. Fine for a
  cohort programme, wrong the day the console is only opened weekly.
- **No fines from attendance.** NGG derives lateness fines from a policy engine
  reading check-ins. NGV has no attendance capture, so a fine is a staff
  judgement with a reason from a fixed vocabulary. That is the honest version
  until attendance exists.
- **No statement to send or print.** The account is legible on screen and in a
  reminder; there is no PDF.
