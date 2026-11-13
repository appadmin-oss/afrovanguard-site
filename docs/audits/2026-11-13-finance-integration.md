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

## Still open

- A member cannot withdraw a request once CFIS has it, and is not told the reason a request was refused (deliberately — CFIS keeps what was said inside finance).
- No account-name check on the bank details a member types; CFIS can resolve names from the bank (it does on its own form) but the portal does not ask it yet.
- The member history in CFIS (`historyFor`) has no route, so a member who changes account still cannot see older claims.
- Receipts on a request kept here are sent only when CFIS answers; a request older than 60 days is no longer sent by itself.
