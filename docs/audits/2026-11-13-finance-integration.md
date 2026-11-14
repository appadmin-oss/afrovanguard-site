# Finance integration audit — 13 November 2026

How Afrovanguard reaches CACENTRE finance (CFIS): members asking for money
(`lib/CfisRequests.php`, `portal/money-requests.*`), the hosted checkout and
income reporting (`lib/CfisCheckout.php`), pairing (`lib/CfisPairing.php`),
and who at Afrovanguard hears of money. The CFIS half of each change is in
cacentre-site `finance/`, ADR 56.

## What was wrong

| # | Severity | Finding |
|---|---|---|
| 1 | High | Every portal request reached CFIS as **money needed**, never as money already spent. The receipt rule only asks about money already spent, so the member who paid out of their own pocket — the person this page was built for — was never asked for a receipt, and had no way to send one. |
| 2 | High | The kinds of spending CFIS published to every member included CACENTRE's own **hire refunds** (`deposit_refund`, `hire_refund`). A member choosing one would have had a payout booked against hire income or the deposits liability. |
| 3 | Medium | The kinds of spending lived in CFIS's config file, on a host with no shell. Adding **utilities** — electricity, water, generator fuel — needed a code change and a deploy. |
| 4 | Medium | The form showed heading keys (`facilitation`, `internet`), not words, and nothing said what each covered. |
| 5 | Medium | `AV_FINANCE_EMAIL` was read but was not a Studio setting, so it could not be set without a shell. Finance notices went to the general mailbox, and only when a request **could not** be sent; a request that reached CFIS told nobody here. Donations told the general mailbox only. |
| 6 | Medium | CFIS's member history read a fixed sender code `afg` (Africa GATES) after requests were stamped with their own sender's code, so it answered Afrovanguard (`avg`) with nothing. It has no route yet; it is correct now for when one is added. |

## What changed

- **Utilities** is a kind of spending (CFIS heading 5230: electricity, prepaid units or a bill, water, generator fuel, waste collection), with repairs and maintenance, events, and publicity and printing. The list is finance's, on CFIS **Books › Request categories**; a new kind or a new heading needs a second finance director, wording does not. Every kind has a name and a note the member reads.
- CACENTRE's refunds are no longer offered to members, and CFIS accepts them from CACENTRE only.
- The form asks **"Have you already paid?"**. Money already spent over ₦5,000 needs a photo of the receipt, or a reason there is none. Up to three receipts (photos or PDFs, 5MB each, 8MB together) are kept in the private store until CFIS has them, sent with the request, named in the signed claims by SHA-256, and deleted here once CFIS confirms it holds the request.
- **Finance mailbox**: Studio → Rules & AI → Setup → CACENTRE finance (CFIS) → *Finance mailbox*. Blank means `finance@afrovanguard.org.ng`. Finance hears of every request — sent into CFIS, or kept here while CFIS cannot be reached — and of every donation and in-kind gift (the general mailbox still gets donations as before).

Held by `tests/cfisfinance.test.php` here and `tests/Feature/Requests/RequestCategoriesTest.php` in CFIS; each rule was broken on purpose and its test failed.

## Follow-up, 14 November 2026 (CFIS ADR 58)

The CFIS side (`finance/docs/api.md`) gained one middleware for every signed property call, a stable
`code` on every refusal, a spent nonce on calls that change something, `/api/v1/...` beside every
existing path, and four member-request endpoints. The portal now uses them:

- **Withdraw.** Requests nobody has decided show a *Withdraw* button (with a confirm). One kept here
  and never sent is withdrawn here and never sent; one in CFIS is withdrawn there by our own
  reference, and CFIS refuses once a decision or payment exists. Nothing is deleted on either side.
- **Where it is, and why.** Each request shows CFIS's stage ("Waiting for approval — step 2 of 3",
  "Paid on 12 Nov 2026") and, when refused, the reason the approver gave — the words CFIS already
  emailed the member. Nothing else said inside finance reaches the page.
- **Earlier requests.** What the member asked for from another device or account, from CFIS's own
  record by their email, listed under *Earlier requests* without repeating anything already shown.
- **The bank.** A bank picker from CFIS's list (fetched with the status, kept a day), and "The bank
  has this account as …" before sending. A lookup that cannot be made says so and never stops a send.
  It has its own allowance, separate from the eight requests an hour.
- **Several lines** (up to ten, each with its own kind of spending, a running total) with
  *+ Add another line*; without script the single line posts as before. **Needed by** is on the form.

Every call mints a fresh envelope (CFIS now refuses a re-sent one as a replay). Against a CFIS
deployed before ADR 58 the new endpoints answer 404 with no `code`; the portal reads that as "not
offered yet" — no earlier list, "ask finance to cancel" for a withdrawal, "could not check" for a
name — and everything that worked before still does. Held by `tests/cfisrequestsfollow.test.php`.

**Deploy order:** CFIS first, then this site. Either order is safe; only CFIS-first gives members
the new features on the day the portal ships.

## Still open

- A request sent back to finance's desk for correction cannot be withdrawn or corrected by the member;
  CFIS treats it as decided. They hear from finance.
- Receipts on a request kept here are sent only when CFIS answers; a request older than 60 days is no
  longer sent by itself.
- The bank name is shown, never required to match the account name typed; CFIS checks it again
  before paying.
- The new calls use `/api/v1/`; the existing ones still use the unversioned paths, which CFIS keeps
  as aliases. Moving them is a one-line change each once every CFIS in use has `v1`.
