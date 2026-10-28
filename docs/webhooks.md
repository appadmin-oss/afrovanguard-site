# Webhooks & events

The site can notify external systems when things happen — via an internal
**events layer** (`lib/Events.php`) feeding an **outbound webhook** dispatcher
(`lib/Webhooks.php`). Manage endpoints in the Studio → **Webhooks** tab.

## Events emitted

| Event | When |
|---|---|
| `diary.published`    | a Diary article is published (admin save or member-submission approval) |
| `member.created`     | a new account is created (register, Google SSO, or admin) |
| `donation.completed` | a donation is recorded (once per unique reference) |
| `contact.received`   | a contact form is submitted |
| `enrollment.created` | a learner enrolls in a course |

Add more sinks (Slack, a digest mailer, analytics) without touching domain code
by registering a listener: `Events::on('diary.published', fn($payload) => …)`.

## Delivery

- Each enabled endpoint subscribed to the event (or to `*`) gets a **delivery
  row**; the send happens **after the response is flushed** to the user
  (`fastcgi_finish_request` when available), so it never adds latency.
- Failures are **retried with exponential backoff** (~1m, 5m, 30m, 2h, 6h, 24h;
  6 attempts then `dead`) by the cron runner:
  ```
  * * * * * /usr/bin/php /path/to/site/db/webhooks_run.php >> /path/logs/webhooks.log 2>&1
  ```
  (The immediate post-response attempt means it also works without cron for the
  happy path; cron is the reliable retry backstop.)
- The Studio shows recent deliveries with status, attempt count, HTTP code, and
  the last error.

## Request format & signature

`POST` with `Content-Type: application/json` and headers:

| Header | Value |
|---|---|
| `X-AV-Event`     | the event name |
| `X-AV-Delivery`  | delivery id |
| `X-AV-Timestamp` | unix seconds |
| `X-AV-Signature` | `sha256=` + hex HMAC-SHA256 of `"{X-AV-Timestamp}.{raw body}"` keyed with the endpoint **secret** |

Body:
```json
{ "event": "diary.published", "data": { … }, "site": "https://afrovanguard.org.ng", "at": "2026-06-26T12:00:00+00:00" }
```

Verify on your receiver (pseudocode):
```
expected = "sha256=" + hmac_sha256(timestamp + "." + raw_body, secret)
reject unless constant_time_equals(expected, header["X-AV-Signature"])
reject if abs(now - timestamp) > 300   # optional replay window
```

## Tables

`webhook_endpoints` (url, secret, events, enabled) and `webhook_deliveries`
(event, payload, status, attempts, last_code, last_error, next_attempt_at) —
created on first use, driver-aware (SQLite/MySQL/Postgres), included by the
migration tool.

## The INBOUND payment webhook (Paystack)

Everything above is outbound — this site telling other systems what happened.
The payment gateway's webhook goes the other way, and it is the one address
the money arrives at.

**A gateway posts to ONE URL.** This site has two money routes, and each used
to understand only its own kind:

| Address | Understood | Did to everything else |
|---|---|---|
| `/academy/pay.php` | courses, dues | treated *any* charge carrying a plan as a dues renewal, so a recurring **donation** bought somebody a month of membership; donations were never recorded |
| `/process-donation.php` | donations, NGV fees | filed everything else as a **donation** — a dues or course charge went onto a campaign total, could appear on the public donor wall, and left the learner unenrolled |

Which one was live depended on the Paystack dashboard, and nothing in the code
said which. **Both now hand the event to `lib/PaymentWebhook::handle()`**, so
either address is correct and the same event does the same thing at both.

### How the kind is decided

`PaymentWebhook::classify()` is pure — the event and three facts in, a kind
and the reason for it out (the reason is logged, so "why did this charge go
there" is answerable without a reproduction). The order is not arbitrary:

| # | Signal | Kind | Owner |
|---|---|---|---|
| 1 | `subscription.*` event | `subscription` | `Appeals` |
| 2 | `metadata.ngv_member` | `ngv` | `NgvLedger::payOnline` (naira only) |
| 3 | a `payments` row for the reference | `lms` | `LmsRepository::finalizePayment` — a course, or dues bought from the portal, **including the first charge of a dues subscription** |
| 4 | the plan is `AV_DUES_PLAN_CODE` | `dues_renewal` | a `payments` row, then the same `finalizePayment` |
| 5 | anything else | `donation` | `Donations::recordCharge` |

The reference is the strongest signal because we created the row. The dues
plan is a **positive** signal, not "has a plan" — that was the academy route's
bug. And the donation is the **fallback**: money whose owner cannot be named
is recorded as given, never granted as bought.

### What a renewal does now

A later charge on the dues subscription has no local `payments` row — the
gateway raised it on its own schedule. It used to call
`grantMembershipByEmail()` and add a month: no de-duplication (every
redelivery added another), no amount or currency check (a subscription still
running at last year's price bought a month at today's), no `payments` row
(renewal money was missing from every dues total and from the member's own
history) and no audit. The renewal now **writes the row, keyed on the
gateway's reference**, and goes through `finalizePayment` like every other
dues charge:

- `payments.reference` is UNIQUE, so a redelivered webhook cannot insert a
  second row, and `finalizePayment` is idempotent on the one that is there;
- the amount is checked against **today's** `Membership::price(1)`. Short is
  refused, left recorded as `pending`, and written to the trail as
  `dues_renewal_short` — a subscription on an old price keeps arriving every
  month until somebody reissues it, so it is said out loud rather than left
  in an error log;
- a currency other than NGN, and an address with no account, are both
  answered **200** (a retry cannot fix either) and logged to reconcile by
  hand;
- `grantMembershipByEmail()` has been removed rather than left unused: an
  email and a number were all it took to call it.

### Donations are de-duplicated in a table, not in the file

`donations.json` keeps only the newest 500 donations, and the duplicate check
walked that list — so a webhook redelivered for an older reference (Paystack
retries for days; a restored backup replays everything) was counted again,
moving the campaign total and the donor count with it. The reference is now
claimed in `donation_refs`, whose PRIMARY KEY does the work: two webhooks
arriving at once both try the insert and exactly one succeeds. The file is the
display; the table is the ledger.

### Answering the gateway

`200` means "we have it, stop retrying". `500` is only for a failure a retry
could fix — answering 200 to a failure told Paystack it was delivered, and the
payment went silently missing.

### Setting it up

One URL in the Paystack dashboard; either of the two works. Set
`AV_DUES_PLAN_CODE` to the dues plan if the deployment offers auto-renewing
monthly dues — without it, a charge on any plan is a donation, which is the
safe direction. Tests: `tests/paywebhook.test.php` (the kinds, a renewal end
to end, and both addresses driven over HTTP with a real signature).
