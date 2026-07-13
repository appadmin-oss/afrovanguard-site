# Google Workspace — full sync setup

Afrovanguard talks to Google Workspace with a **service account + domain‑wide
delegation** (dependency‑free RS256‑JWT → access token; no Composer, no OAuth
consent screen for users). Once configured it can:

| Capability | Scope | Used by |
|---|---|---|
| Read calendar events | `calendar.readonly` | Portal "Coming up" / launchpad |
| **Create/update Meet events** | `calendar` | Mentorship session scheduling (auto Meet link + invites) |
| Read Drive files & **transcripts** | `drive.metadata.readonly`, `drive.readonly` | Mentorship transcripts, shared folder |
| **Directory → members sync** | `admin.directory.user.readonly` | Provision org members |
| Directory groups | `admin.directory.group.readonly` | Group listing |

Everything is **best‑effort and gated**: with nothing configured the site runs
exactly as before (manual Meet links, no directory sync). Turning it on is
purely additive.

## 1. Create the service account

1. Google Cloud Console → the project for Afrovanguard → **IAM & Admin →
   Service Accounts → Create**.
2. Create a **JSON key**. Put the JSON (raw, or a path to the file) in
   `AV_GDRIVE_SERVICE_ACCOUNT`.
3. Enable the APIs used: **Google Calendar API**, **Google Drive API**,
   **Admin SDK API**.

## 2. Enable domain‑wide delegation

Google Admin console → **Security → Access and data control → API controls →
Domain‑wide delegation → Add new**. Use the service account's **Client ID** and
authorise these scopes (comma‑separated):

```
https://www.googleapis.com/auth/calendar,
https://www.googleapis.com/auth/calendar.readonly,
https://www.googleapis.com/auth/drive.readonly,
https://www.googleapis.com/auth/drive.metadata.readonly,
https://www.googleapis.com/auth/admin.directory.user.readonly,
https://www.googleapis.com/auth/admin.directory.group.readonly
```

## 3. Configure env (see `.env.example`)

```
AV_GDRIVE_SERVICE_ACCOUNT= <json or path>
AV_WS_SUBJECT=admin@afrovanguard.org.ng     # a real admin the SA impersonates
AV_WS_CALENDAR_ID=mentorship@afrovanguard.org.ng   # optional; defaults to the subject
AV_WS_CALENDAR_WRITE=1                       # auto-create Meet events (default on)
AV_WS_TZ=Africa/Lagos
AV_ORG_DOMAIN=afrovanguard.org.ng
```

## 4. Verify & run

```
php tools/gws-sync.php probe     # shows token_ok, calendar_read/write, drive, directory, groups
php tools/gws-sync.php users     # {created, updated, skipped} — provisions org members
php tools/gws-sync.php groups    # list directory groups
```

A nightly cron keeps members in sync:

```
0 2 * * *  php /path/to/tools/gws-sync.php users >/dev/null 2>&1
```

## What syncs automatically

- **Mentorship sessions** — scheduling a session (with `AV_WS_CALENDAR_WRITE=1`)
  creates a real Calendar event, provisions a **Google Meet** link, and invites
  both mentor and mentee (`sendUpdates=all`). Cancelling the session cancels the
  event. If Workspace is off, the mentor adds a Meet link manually as before.
- **Members** — `tools/gws-sync.php users` (or the cron) upserts every active
  directory user into the LMS as a verified member, by email. Idempotent.

## Testing without live Google

`AV_WS_BASE_URL` and `AV_WS_TOKEN_URL` override the Google endpoints so the
client can be pointed at a mock server in tests. Leave both blank in production.
