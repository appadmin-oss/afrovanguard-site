# Membership is an act, not an email address

An `@afrovanguard.org.ng` address **was** membership. Three places said so:

1. `LmsAuth::isOrgMember()` — an elevated role **or** an org email. The
   portal's whole Work group hung off it.
2. Signing in with a code made a **new** account at role `member` when the
   address was an org one.
3. Signing in — by code **or** by Google — **promoted** an existing learner to
   `member` on the same test.

So anybody the organisation had ever given an address to was a member: a
contractor, a one-week volunteer, somebody who left two years ago, a shared
inbox. "Who made this person a member?" had no answer, because nobody had.

## The four states

| State | Means | Counts as a member? |
|---|---|---|
| `verified` | Somebody at the office said so, and said on what basis | yes |
| `grandfathered` | The old rule covered them the day this shipped | yes — and they are still on the list |
| `declined` | Considered, and not a member | **no**, and this beats role and address both |
| *(none)* | Never considered | only if their **role** says so, which is itself an act |

`MemberVerification::verify()` **requires a basis** — what the office actually
saw. An ID, a signed form, a referee, "known to the team at Egbeda". A
verification with nothing behind it is the old rule with a button on it, so it
is refused in words that say as much.

## Nobody was locked out

`grandfatherAll()` runs **once**, on the first request after the deploy, and
marks everybody the old rule covered — an elevated role, an org address, or a
NextGen Vanguard. They keep their access to the naira; the address confers
nothing on anyone **new** from the same moment.

That was deliberate. Enforcing immediately would have locked out everybody the
office had not got to yet, and an organisation that cannot sign in does not
finish the clean-up — it turns the check off.

The pass **claims the marker before it works** (`claimBackfill()`), because the
first request after a deploy is several processes at once and all of them
would otherwise do the whole scan. The claim carries its time, so a process
that dies mid-pass does not leave the backfill unfinished for ever: after
`CLAIM_SECONDS` (600) the next request takes it over.

## What the office sees

**Studio → Members → To verify**, with a count on the tab. Each row says who,
what state they are in, and **why they are on the list**:

- *grandfathered* — "Had access under the old rule. Nobody has checked who
  they are."
- *(none)*, on an org address — "Has one of our email addresses, which is no
  longer enough on its own."

From there: **Verify** (with the basis), or **Not a member**. The community bot
is excluded — a to-do list with something nobody can ever action at the top is
a list people stop reading.

## What the member sees

Somebody on an org address who is not a member gets a card on their Account
view: *Membership not confirmed yet*, saying they are signed in, that Learning
and the Diary are theirs, that membership is a separate thing somebody
confirms, and that they are on the office's list. Seeing "Learner" with no
explanation reads as a fault in the software rather than a step nobody has
taken yet.

## Dues: only a verified member owes them

There are two kinds of person, and that is the whole taxonomy:

- **Verified** — a member. Owes monthly dues.
- **Unverified** — a volunteer, with limited access. Owes nothing.

A membership-class system with six priced categories was built for this and
taken straight back out: it was more to keep up than the organisation wants,
and the real answer is the verification state it already had.

Two rules keep the money honest across the boundary:

- **Dues begin the day somebody first counts as a member.** For anybody
  verified from scratch that is the day they were verified — verifying
  somebody must not hand them a year of arrears for months in which they were
  an unverified volunteer and owed nothing.
- **Verifying a grandfathered member changes nothing about their money.**
  They were being charged the day before verification existed, so the office
  doing its paperwork must not wipe a real debt. `Membership::anchorOf()`
  applies the verification date only when there is no `grandfather` row.

## What the org domain still does

It is a real signal and is still read for what it actually tells you:

- whether a sign-in code is required (`AuthPolicy::requireOtpForOrg`),
- which Google Workspace account somebody has (`GoogleWorkspace`),
- how the community segments its spaces (`Community`),
- which addresses may hold an elevated role (`AdminRoles`, `MemberRoster`).

None of that is membership, and none of it changed.

## Where it is

| | |
|---|---|
| The model | `lib/MemberVerification.php` |
| Who is a member | `LmsAuth::isOrgMember()`, `MemberRoster::memberWhere()` |
| The office's list | Studio → Members → **To verify** |
| What the member is told | `portal/views/membership.php` |
| Tests | `tests/memberverify.test.php` |
