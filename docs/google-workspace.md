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
https://www.googleapis.com/auth/admin.directory.group.readonly,
https://www.googleapis.com/auth/meetings.space.readonly,
https://www.googleapis.com/auth/meetings.space.created,
https://www.googleapis.com/auth/meetings.space.settings,
https://www.googleapis.com/auth/admin.reports.audit.readonly,
https://www.googleapis.com/auth/chat.messages.create
```

The last four are used by Meet attendance/transcripts (`lib/GoogleWorkspace.php`
`meetConferenceForCode`, `createMeetSpace`, `reportsMeetSeconds`) and by posting
to Chat as a member. Leave one out and only that feature fails; the Setup
screen's **Test Google Workspace** names the refusal (`unauthorized_client`
means the scope or the client ID is missing from this list).

Enable these APIs on the service account's project as well: Google Calendar API,
Google Drive API, Admin SDK API, Google Meet REST API, Google Chat API, and — for
live Meet events — Google Workspace Events API and Cloud Pub/Sub API.

## Meetings that run themselves (Google Meet)

**Start a call now** (portal → Tools → Meetings) opens an instant Meet room with no
Calendar event (`Meetings::startNow` → `createMeetSpace`, scope
`meetings.space.created`), owned by the organiser mailbox and captured from the
start like a scheduled meeting. Anybody named in the invite box is emailed the link.

What happens, with nothing for a volunteer to press:

1. **Scheduling** writes the Calendar event with a Meet link (invites go to
   everyone). Then, as the **organiser** (`AV_MEET_ORGANISER`), the site turns on
   the space's own **recording, transcript, Gemini notes and attendance report**
   (`PATCH /v2/spaces/{id}`, `meetings.space.settings`). An edition that refuses
   recording gets transcript and notes. `AV_MEET_ARTIFACTS` = `all` | `transcript` | `off`.
2. **During the call** the cron reads who is in (Meet REST `participants`), and
   the room's own check-in QR and captions keep working.
3. **After the call** finishing reads every join and leave (`participantSessions`
   — rejoining counts), waits while Google writes the transcript (tried every 10
   minutes for two hours, then hourly, up to a day), then takes it speaker by
   speaker with Gemini's notes after it. The minutes draft is made from it.
4. **Live events (optional, faster)**: the Workspace Events API sends the
   organiser's Meet events to a Pub/Sub topic; the cron drains a **pull**
   subscription every tick (no public URL needed) and renews the events
   subscription before Google's 7-day limit. A **push** subscription to
   `/webhooks/google-meet.php` works too; each push is checked against Google's
   signing keys (firebase/php-jwt) and `AV_MEET_PUSH_AUDIENCE`.

Once, in Google:

- **A licensed organiser mailbox** (e.g. `meetings@`) — Business Standard or
  higher for recording and transcripts; a Gemini licence for notes. Set it as
  *Meeting organiser mailbox* (Studio → Setup → Google Meet) and, ideally, as the
  *Shared calendar*. Recording/transcription start when someone with the
  privilege joins.
- **Admin console → Apps → Google Workspace → Google Meet → Meet video settings**:
  allow recording, transcripts and "Take notes for me".
- **Domain-wide delegation**: the scopes above, including
  `meetings.space.settings`.
- **Pub/Sub (optional)**: create topic `meet-events`; grant
  `meet-api-event-push@system.gserviceaccount.com` *Pub/Sub Publisher* on it;
  create a **pull** subscription `meet-events-pull` and grant the service
  account *Pub/Sub Subscriber* on it. Paste both full names
  (`projects/…/topics/meet-events`, `projects/…/subscriptions/meet-events-pull`)
  into Studio → Setup → Google Meet.

Without any of this the meeting still works: Calendar invite and link, our
own email invite, QR check-in, room captions, and minutes.

## 3. Configure — from the Studio (no shell needed)

Studio → **Setup → Google sign-in & Workspace** holds every value below. Secrets
(the OAuth client secret, the service-account JSON key) are encrypted at rest
and never shown again; paste the whole downloaded key file into the key field.
A value set there wins over `config.php` and SetEnv. Then press **Test Google
sign-in** and **Test Google Workspace**: the first proves the client ID/secret
with Google and prints the redirect URI to register; the second reports
calendar, Drive, directory and groups one by one, with Google's own reason for
any refusal. Studio → **System** shows the redirect URI, the last failed Google
round-trip (with Google's reason) and the last directory sync.

The same keys still work from `config.php` / SetEnv:

```
AV_GDRIVE_SERVICE_ACCOUNT= <json or path>
AV_WS_SUBJECT=admin@afrovanguard.org.ng     # a real admin the SA impersonates
AV_WS_CALENDAR_ID=mentorship@afrovanguard.org.ng   # optional; defaults to the subject
AV_WS_CALENDAR_WRITE=1                       # auto-create Meet events (default on)
AV_WS_TZ=Africa/Lagos
AV_ORG_DOMAIN=afrovanguard.org.ng
```

## 4. Verify & run

From the Studio: the two Setup tests above. Directory sync: set **Add Workspace
users as members** to `daily` and the site cron (`tasks/cron.php`, which a
cPanel Cron Job or an external pinger can call without a shell) runs it once a
day. It uses the directory's admin view, so suspended users are skipped, and
pages through the whole directory. The same cron renews push channels.

From a shell, if there is one:

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

## Google sign-in: the OAuth client

1. Google Cloud console → APIs & Services → **Credentials → Create credentials →
   OAuth client ID → Web application**.
2. **Authorised redirect URIs**: exactly `https://afrovanguard.org.ng/auth/google/callback`
   (the site's `SITE_URL` + `/auth/google/callback`; the Setup test prints it).
   One URI serves both sign-in and the Workspace connect flow. Add the `www.`
   variant only if `SITE_URL` uses it — the start step bounces to `SITE_URL`'s
   host first, so the state cookie and the callback are on the same host.
3. **OAuth consent screen**: user type **External** if learners with personal
   Google accounts are to sign in (Internal refuses every account outside the
   Workspace tenant with `org_internal`). Publish it to **In production**: in
   Testing, only listed test users can sign in and Google expires every refresh
   token after 7 days (the site now clears such a connection and asks the member
   to reconnect, and the System page names it). Add the scopes `openid`, `email`,
   `profile`; add Gmail/Calendar/Drive/Chat only if members will connect their
   Workspace — `gmail.readonly` is a restricted scope and needs Google's
   verification for an External app.

What sign-in enforces: a signed, 10-minute `state` pinned to a cookie; the OIDC
`nonce` from that state checked in the id_token; `aud`, `iss` and `exp` on the
id_token; **an address Google has not verified signs nobody in**; and the
member role for an `@afrovanguard.org.ng` address needs Google's `hd` claim to
name the domain (an unmanaged consumer account on an org address signs in as a
learner).

By default only someone signing in with an org address is also asked for
Workspace scopes (`AV_GWS_CONNECT_ON_SIGNIN` = `org`; `all` or `0` on the Setup
screen). Everybody else signs in with `openid email profile` only and can
connect Workspace later from `/workspace`, which now says whether the connect
worked.

## Enterprise: per-user Workspace (the site acts AS each member)

The service account above is ONE shared identity. For a true Workspace platform,
each member connects **their own** Google account so the site reads and acts on
*their* Gmail, Calendar and Drive (`lib/GoogleWorkspaceUser.php`).

- **Connect:** a member visits `/auth/google/connect` (a button on `/workspace`).
  This is incremental authorization — separate from normal sign-in — requesting
  offline access to the scopes in `AV_GWS_USER_SCOPES` (default: `gmail.readonly`,
  `calendar`, `drive.readonly`). It uses the **same OAuth client**
  (`AV_GOOGLE_CLIENT_ID/SECRET`); add these scopes on the OAuth consent screen.
- **Storage:** one row per user in `google_connections`. **Refresh tokens are
  encrypted at rest** (libsodium secretbox keyed off `APP_KEY` — set a strong
  `APP_KEY`!). Access tokens are cached with their expiry and auto-refreshed.
- **Disconnect:** `/auth/google/disconnect` (POST + CSRF) revokes the token with
  Google and forgets it.
- On `/workspace`, a connected member sees their live Inbox (with unread count),
  upcoming calendar and recent Drive files.

## Enterprise: real-time push + automation

- **Real-time:** `lib/GoogleWatch.php` registers Calendar/Drive *watch* channels
  so Google POSTs to `/webhooks/google` (`webhooks/google.php`) on every change —
  no polling. The receiver validates the channel id + token against
  `google_channels` and emits `workspace.calendar_changed` / `.drive_changed`.
  Manage channels with `tools/gws-watch.php`:

  ```
  php tools/gws-watch.php start <userId>   # calendar + drive channels
  php tools/gws-watch.php renew            # renew those expiring soon (cron)
  php tools/gws-watch.php stop  <userId>
  php tools/gws-watch.php list
  ```

  Going live requires a **public https** webhook (`SITE_URL`, or override with
  `AV_WS_WEBHOOK_URL`) and a **push domain verified** in Google Search Console.
  Until then it's inert. Enable channel creation on connect with `AV_WS_WATCH=1`.

- **Automation:** `lib/AvAutomation.php` turns events into actions:
  - `workspace.connected` → one-shot onboarding event on the member's calendar
    (`AV_WS_ONBOARDING=1`, default on) + start watch channels (`AV_WS_WATCH`).
  - `workspace.disconnected` → stop the member's watch channels.
  - `workspace.calendar_changed` / `.drive_changed` → real-time hook points.

## Testing without live Google

`AV_WS_BASE_URL`, `AV_WS_TOKEN_URL`, `AV_WS_REVOKE_URL` and `AV_WS_WEBHOOK_URL`
override the Google endpoints so the clients can be pointed at a mock server in
tests. Leave them blank in production.
