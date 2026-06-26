# Afrovanguard integration API

Two complementary surfaces let Afrovanguard talk to other sites, bots and tools
like a modern platform:

- **Outbound — webhooks.** The site POSTs signed JSON to your endpoint when
  something happens (a post, a donation, a new member). Configure endpoints in
  **Studio → Webhooks & integrations**. Deliveries are HMAC-SHA256 signed and
  retried with backoff.
- **Inbound — the integration API.** Your app calls *in* over HTTPS, authenticated
  by a Bearer **app token**, to post as the official Afrovanguard bot, emit
  events, or read the community feed.

This document covers the inbound API.

## Getting a token

**Studio → Webhooks & integrations → API tokens → Create a token.** Choose a name
and the scopes it needs. The token (`av_int_…`) is shown **once** — copy it then.
Only its hash is stored, and you can revoke it anytime.

### Scopes

| Scope             | Allows                                             |
|-------------------|----------------------------------------------------|
| `community:read`  | Read the public community feed                     |
| `community:bot`   | Post & reply **as the official Afrovanguard bot**  |
| `events:write`    | Emit events (fan out to your webhooks / listeners) |

## Authentication

Send the token as a Bearer header on every request:

```
Authorization: Bearer av_int_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
```

There is no cookie and no CSRF: the token is the credential. Calls are rate-limited
per client IP. All responses are JSON with an `ok` boolean.

## Endpoints — `/integrations/api.php`

### `GET ?action=ping`
Health + token introspection. Any valid token.
```bash
curl -H "Authorization: Bearer $TOKEN" \
  https://afrovanguard.org.ng/integrations/api.php?action=ping
# → {"ok":true,"name":"Discord announcer","scopes":["community:read","community:bot"],"time":"…"}
```

### `GET ?action=community.feed&space=&sort=&offset=`
Read the feed. Scope: `community:read`. `space` = a space slug (optional),
`sort` = `latest|top`, `offset` for paging (20/page).

### `POST ?action=community.post`  — body `{ "space": "announcements", "body": "…", "pinned": false }`
Post **as the official Afrovanguard bot**. Scope: `community:bot`.
```bash
curl -X POST -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  -d '{"space":"announcements","body":"Town hall this Saturday, 4pm.","pinned":true}' \
  https://afrovanguard.org.ng/integrations/api.php?action=community.post
```

### `POST ?action=community.reply`  — body `{ "id": 123, "body": "…" }`
Reply as the bot to post `id`. Scope: `community:bot`.

### `POST ?action=event`  — body `{ "type": "diary.published", "data": { … } }`
Emit an event, which fans out to your configured webhooks and any server-side
listeners. Scope: `events:write`. `type` is a dotted name (`a-z0-9_.-`).

## The official Afrovanguard bot

Posts and replies made through `community:bot` appear under **Afrovanguard** with
the verified ✓ badge and the *Official* tier — the same identity used for the
pinned community welcome. This is the supported way for a sister site, a Discord/
Slack bridge, or a scheduled announcer to speak as the organisation.

## Errors

| Status | Meaning                                  |
|--------|------------------------------------------|
| 401    | Missing/invalid/revoked token            |
| 403    | Token lacks the required scope           |
| 422    | Bad input (e.g. empty body)              |
| 429    | Rate limited                             |
