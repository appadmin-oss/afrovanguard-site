# NGV · Fees, dues and fines

What a NextGen Vanguard participant owes, what has been received, and who
decided each of those things.

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
        the public page                         the ledger
  /academy/ngv/  (Studio-editable)
    fees[] ── "₦10,000 / year" ──┐
    fees[] ── "₦1,000 / month" ──┼─▶ NgvLedger::amounts() ─▶ accrual ─▶ ngv_charges
    plans[] ─ "₦240,000"  ───────┘        (pinnable)              ▲          │
                                                                  │          │
                                             staff: fine, adjustment,        │
                                                    training fee             │
                                                                             ▼
   staff console ── payment / waiver / write-off ──▶ ngv_payments ──▶ account()
   /academy/ngv/members.php                                              │
                                                                         ├─▶ member dashboard
                                                                         ├─▶ arrears list
                                                                         └─▶ reminders (cron)
```

## The four charges

| kind | cadence | posted by | page label |
|---|---|---|---|
| `membership` | yearly, while enrolled | accrual | Membership fee |
| `commitment` | monthly, while enrolled | accrual | Commitment fee |
| `programme` | per the plan | **staff**, one press | the plans table |
| `fine` | never automatic | staff, with a reason | — |

…plus `adjustment`, for the correction that is neither a fine nor a mistake
worth erasing. Credits are `payment`, `waiver` and `writeoff`.

## Six decisions, not implementation details

**1. The amounts are read off the public page.** `NgvLedger::amounts()` parses
the live `Ngv` content — the fee rows by *name* (so reordering them on the page
cannot swap the yearly and the monthly charge) and each plan's price. An admin
who needs a figure the page cannot express pins it in the settings, and every
amount reports its `source` — `page`, `pinned` or `fallback` — so the console
shows where a number came from rather than asserting it.

**2. The training fee is raised by staff, not accrued.** A participant picks
their own plan on their dashboard. If accrual priced from that pick, a school
leaver clicking "Full Programme" out of curiosity would give themselves a
₦240,000 debt and the ledger would be right to insist on it. So it is one
audited press on their record, prefilled from the catalogue and idempotent for
the period. `trainingAuto` turns automatic accrual on for an organisation that
wants it; it ships **off**.

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

**5. "No one is turned away for lack" is on the page, so `waive()` is a
first-class operation.** A waiver keeps the charge visible and records who set
it aside and why. Voiding says "that charge should never have existed", which is
a different and usually untrue thing; a ledger that can only void loses the
reason the moment somebody edits history to be kind. Waivers are clamped to what
is actually outstanding, and what a waiver settled is never reported back to the
participant as money they paid.

**6. Nothing on a cron path can change an amount.** NGG uprates its figures on a
schedule because they live in a settings row nobody looks at. NGV's live on a
public page an admin edits by hand, and a cron that rewrote it would change what
the programme advertises without anyone deciding to. Instead `reviewDue()` says
out loud, at most once a month, that the amounts have gone a year unreviewed —
and a human goes and looks.

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
- **A reminder cadence floor** of 7 days, enforced on save rather than in the
  form, plus a per-participant off switch. Anything tighter is how a programme
  gets its sender blocked and its people to stop reading anything it sends.
- **Everything mutating is audited** under `AdminAudit`'s `ngv` area, with the
  amount and the reason in the detail.
- **Nothing financial gates anything.** No balance reaches the public NGV page,
  the certificate page or the registration page, and no fee state gates a
  certification. `tests/ngvledger.test.php` asserts all of this.

## Only the enrolled accrue

An applicant has not started; somebody paused, withdrawn or completed has
stopped. Charging either is charging for a place nobody is taking up, so
accrual skips any participant whose status is not `active` and says which
reason it skipped for.

## Where it lives

| | |
|---|---|
| Domain | `lib/NgvLedger.php` |
| Schema | `lib/NgvDb.php` — `ngv_charges` (new), `ngv_payments` (extended) |
| Staff console | `/academy/ngv/members.php` |
| Member view | `/academy/ngv/dashboard.php` — read-only, always |
| Cron | `NgvLedger::cronTick()` from `tasks/cron.php` — accrue, remind, nudge |
| Settings | `app_meta` key `ngv_fees` |
| Tests | `tests/ngvledger.test.php`, plus the NGV rows in `tests/drift.test.php` |

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
quietly lose its idempotency on exactly one engine. For the same class of
reason, **no comment in `NgvDb::ddl()` may contain a semicolon**: the DDL is
split into statements by exploding on it.

## Rolling it out

1. Open **/academy/ngv/members.php → Fees & dues**. Check the three amounts and
   where each says it came from.
2. Set **Charge nothing before** to the month you are starting from. It is
   pre-filled with the month you switch fees on, which is almost always right.
3. Tick **Charge membership and monthly commitment** and save.
4. Press **Run accrual** once and read what it says it did.
5. Leave reminders off until the first accrual looks right. Then turn them on
   and use **Preview** before **Send them** — a message to sixty people cannot
   be recalled, and the preview names every exclusion.

Existing `ngv_payments` rows need no migration: the additive schema sync gives
them `credit_kind = 'payment'`, which is what they are.

## What NGV does differently from NGG

| | NGG | NGV |
|---|---|---|
| monthly charge | "dues" | "commitment" — the page's own word |
| training fee | per programme session, accrued | per plan, **raised by staff** |
| earn-off | written down as the member serves | **none** — Phase 2 is a *paid* internship with weekly stipends, so writing the fee down as well would be paying twice. Where a fee should not be collected, that is a waiver |
| who is chased | the child's guardian | the participant — NGV members are adults with accounts here, so a reminder goes in-app as well as by email |
| amounts | admin settings | read off the public page, pinnable |
| price rises | automatic uprate, proposed then applied | no uprate; a review nudge only |
| allocation | one netted account total | per fee line, with `paidAhead` reported |
| rollout | greenfield | `accrueFrom` guard, because NGV has history |

## What is not built yet

- **No self-service payment.** Deliberate, and the first thing to argue about
  rather than the first thing to add: see "a ledger, not a payment processor".
  `lib/Payments.php` (Paystack) exists for donations and is not wired here.
- **No instalment plan.** A ₦240,000 yearly tuition realistically gets paid in
  parts, and today that is several `payment` rows against one `programme`
  charge. A named schedule would let the dashboard say "3 of 12" instead of a
  running total.
- **No fines from attendance.** NGG derives lateness fines from a policy engine
  reading check-ins. NGV has no attendance capture, so a fine is a staff
  judgement with a reason from a fixed vocabulary. That is the honest version
  until attendance exists.
- **No statement to send or print.** The account is legible on screen and in a
  reminder; there is no PDF.
