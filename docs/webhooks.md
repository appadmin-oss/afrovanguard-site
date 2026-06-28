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
