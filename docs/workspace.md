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
- **Communities** — an optional list of Google Chat Spaces / Groups (hidden when
  none are configured).

## Configuration

All in `lib/workspace.php`, driven by env vars (set via `SetEnv` in `.htaccess`,
like the other config). **Nothing is required** — the tool tiles auto-derive from
`AV_ORG_DOMAIN` (default `afrovanguard.org.ng`).

| Env var | Purpose | Default |
|---|---|---|
| `AV_WS_MAIL_URL` … `AV_WS_GROUPS_URL`, `AV_WS_ADMIN_URL` | Override a tile link | `https://<tool>.google.com/a/<domain>` |
| `AV_WS_COMMUNITIES` | JSON list of Spaces/Groups | unset ⇒ section hidden |

`AV_WS_COMMUNITIES` example (https links only):

```
SetEnv AV_WS_COMMUNITIES '[{"name":"All-hands","desc":"Org-wide space","url":"https://chat.google.com/room/AAAA"},{"name":"Volunteers","url":"https://groups.google.com/a/afrovanguard.org.ng/g/volunteers"}]'
```

To get a Space link: open the Space in Google Chat → ⋮ → Copy link. For a Group:
its `groups.google.com/a/<domain>/g/<name>` URL.

## Possible follow-ups

- **Admin-managed communities** — move the list from env JSON to a small
  `communities` table with a Studio editor (same pattern as `celebrations`/`team`).
- **Calendar/Drive surfacing** — embed a read-only org Calendar or a Drive folder
  list in the portal (both *do* allow iframes / have list APIs), if you want
  content in-page rather than deep links.
- **In-site realtime chat** — only worth it if the site moves to a host with a
  persistent process (Node/WebSockets); otherwise Google Chat is the better home.
