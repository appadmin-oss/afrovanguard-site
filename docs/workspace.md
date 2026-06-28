# Google Workspace integration

Afrovanguard members sign in with Google (`@afrovanguard.org.ng`) and the member
portal (`/portal/`) is their **single sign-on launchpad into the org's Google
Workspace** — Gmail, Chat, Meet, Calendar, Drive and Groups.

## Why a launchpad, not a clone

The brief was "an entire workspace **running on** Google Workspace, with a
chatroom, communities, and so on." The right way to deliver that on this stack
(shared cPanel · PHP · SQLite · no persistent process) is to **use Google's own
tools**, not rebuild them:

- Members already authenticate via Google SSO (`lib/GoogleAuth.php`), so the deep
  links land them straight in the org instance — no second login.
- Google Chat / Spaces can't be embedded (`X-Frame-Options: DENY`), and a custom
  realtime chat needs WebSockets / a long-running process, which shared hosting
  doesn't offer. Deep-linking into Google Chat/Spaces/Groups is the correct,
  robust pattern — and it's literally "running on Google Workspace."

## What members see

In `/portal/` (members only — `@org` accounts; learners don't see it):

- **Your Workspace** — a tile grid: Gmail, Chat, Meet, Calendar, Drive, Groups
  (+ an Admin console tile for `admin`-role accounts). Links auto-target the org
  instance via Google's `/a/<domain>/` convention.
- **Communities** — Google Chat Spaces / Groups, managed in the Studio
  (**Communities** tab). Hidden when none exist.
- **Team calendar** & **Shared files** — optional read-only embeds of an org
  Google Calendar and a Drive folder, shown only when configured.

## Configuration

**Communities** are managed in the **Studio → Communities** tab (stored in the
`communities` table): a name, optional description, and the Space/Group link.
To get a Space link: open it in Google Chat → ⋮ → Copy link; for a Group, its
`groups.google.com/a/<domain>/g/<name>` URL.

Everything else is env (`SetEnv` in `.htaccess`, read by `lib/workspace.php`).
**Nothing is required** — the tool tiles auto-derive from `AV_ORG_DOMAIN`
(default `afrovanguard.org.ng`):

| Env var | Purpose |
|---|---|
| `AV_WS_MAIL_URL` … `AV_WS_GROUPS_URL`, `AV_WS_ADMIN_URL` | Override a tool tile link |
| `AV_WS_CALENDAR_ID` (or full `AV_WS_CALENDAR_EMBED`) + `AV_WS_TZ` | Embed a read-only org calendar (agenda view) |
| `AV_WS_DRIVE_FOLDER_ID` | Embed a read-only Drive folder (shared "anyone with the link") |
| `AV_WS_COMMUNITIES` | Fallback JSON list, used only when the Studio has no communities |

The Calendar/Drive embeds rely on the portal's CSP allowing
`calendar.google.com` / `drive.google.com` in `frame-src` (already configured in
`lib/security.php`).

## Done / possible follow-ups

- ✅ **Admin-managed communities** — Studio → Communities (`communities` table).
- ✅ **Calendar/Drive surfacing** — read-only embeds in the portal (env-configured).
- **In-site realtime chat** — only worth it if the site moves to a host with a
  persistent process (Node/WebSockets); otherwise Google Chat is the better home.
