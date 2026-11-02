# Membership classes: what kind of member, and what that kind pays

Dues used to be one price for everybody. A student on an allowance and a
working professional owed the same figure, and the only way to charge them
differently was for somebody at the office to waive one of them by hand, every
month, for ever. A **class** is the answer to "what kind of member is this",
and the monthly price hangs off it.

## What a class is not

| | Says | Lives in |
|---|---|---|
| **Role** (learner, member, coordinator…) | what they may DO in the software | `lms_users.role` |
| **Level** (O, A, B…) | how far they have COME | `lms_users.level`, `lib/Levels.php` |
| **Class** (Student, Working, Patron…) | what they PAY | `member_profiles.member_class` |

They are three different questions and they are kept apart on purpose. Tying
the price to the role or the level would mean a promotion changed somebody's
bill, which is how an organisation ends up not promoting people.

## Nothing changes until the office uses it

A member in **no class** is charged the single dues price in Studio → Members →
Dues, exactly as before classes existed. A class with **no price of its own**
is on that same price — it is not free. Only a class with a price of its own,
or one marked *owes nothing*, changes what anybody pays.

`tests/memberclasses.test.php` asserts this first, before anything else in the
file. A money change nobody asked for is the worst kind, and it is the one a
feature like this causes if nobody pins it.

## The class in force, not the class today

`member_class_log` is one row per change: the class, **the day it takes
effect**, who set it and why. Arrears walk the months owed and price **each
month at the class the member was in on the day it fell due**
(`Membership::pricedMonths()`), grouping consecutive months in one class so a
year owed in one class is still priced at that class's yearly rate.

This is the whole reason the change is dated rather than overwritten. Pricing
arrears at today's class would mean moving a student onto the working rate
silently re-priced every month they had already fallen behind — the office
would type one correction and somebody's arrears would treble, with nothing on
the screen saying why.

The effective day may be **backdated**, which is how the office corrects a
record from the beginning of the year. It may not be in the future: a price
nobody is on yet is a plan, not a record.

## Prices are the Super Admin's; the person is management's

- `member_class_save` — adding a class and setting its prices — is
  **superadmin-only**, the same trust as the dues settings themselves.
- `member_classes` and `member_class_assign` — reading the list and putting a
  member in a class — are management's.

An admin who is not a Super Admin sees the prices and cannot change them: the
fields are disabled and the pane says why. A field that silently throws away
what you typed is worse than one that says it is not yours.

## A class that owes nothing

`owes = 0` (Patron, Honorary as seeded) takes the member out of
`Membership::obligedSql()` entirely, and `obligation()` answers with the class
name as the reason. The office says it once on the class instead of waiving
every month. Whatever is typed in such a class's price fields costs nothing —
the flag wins.

## Where it is

| | |
|---|---|
| The model | `lib/MemberClasses.php` |
| Pricing | `Membership::price($months, $userId)`, `monthlyFor()`, `annualFor()`, `classOf()` |
| The office's list | Studio → Members → **Classes** |
| One member's class | Studio → Members → Roster → open → **Membership class** |
| Who is in a class | the roster's **Any class** filter, and the member count on each class |
| What the member sees | their own card in the portal: their class and their rate |
| Tests | `tests/memberclasses.test.php` |

## The seeded classes

Student, Working, Volunteer, Associate, Patron, Honorary — all at **price 0**,
which means "on the standard dues price" for the four that owe and "free" for
the two that do not. They are a starting point: the office renames them,
re-prices them, adds its own and archives the ones it does not use.

An **archived** class takes nobody new and is not offered in the pickers, but
it still prices: a member already in it keeps it, and every month already
priced against it must still find it. For the same reason a class's **key
never changes** when it is renamed — the key is what the history points at.
