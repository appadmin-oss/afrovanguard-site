# Afrovanguard-Site Codebase Audit

_Repository:_ `appadmin-oss/afrovanguard-site` · _Audit date:_ 2026-07-12 · _Re-indexed:_ 2026-08-06 · _Scope:_ full repository (**584 tracked files**, was ~362)

This audit maps the components, composition, and infrastructure of the Afrovanguard main website so the team has an accurate mental model of what runs where, how the pieces connect, and where the risks are.

> **2026-08-06 re-index.** The repo has grown ~362 → 584 files. `lib/` is now
> **68 classes** (was ~47) and five new subsystems have landed — **Team Chat**,
> **Meetings** (Google Meet + AI minutes + recorder bots), **NextGen Vanguard**
> (NGV), **IQ** (Incorruptible Quiz + brain games), and a **Portal
> collaboration / org task pool**. Email is now **PHPMailer-only** (the
> hand-rolled `lib/Smtp.php` is retired) and the app ships **Docker + a
> health-check probe + CI**. Every remediation the 2026-07-12 pass claimed
> (S-1…S-5) is now **confirmed done in the live code**. Sections below carry the
> update inline; §9 findings are re-ranked with the current live items on top.

**Change log**

| Date | Change |
|---|---|
| 2026-07-12 | Initial full-repository audit (~362 files). S-1…S-5 remediated in-branch. |
| 2026-08-06 | Re-indexed at 584 files: verified S-1…S-5 fixed in code, documented the five new subsystems (§3A), refreshed the `lib`/table/integration inventories, and re-ranked findings. |
| 2026-08-06 | **Remediated the CSP:** removed `'unsafe-eval'` from the production `.htaccess` `script-src` (former H-1). Residual `'unsafe-inline'` tracked as M-4. |
| 2026-08-06 | **NGV became a full member programme on its own database** — `lib/NgvDb.php` (isolated connection) + `lib/NgvMember.php` (participants, fee ledger, certifications, applications); a member dashboard, a public registration page, and a staff console (§3A.C). |

---

## 1. Executive Summary

**What it is.** `afrovanguard-site` is the main public website and back-office for Afrovanguard ("the Studio") — a Nigerian youth-empowerment nonprofit. It is a **PHP 8 monolith** running on **shared cPanel/Apache hosting**, deliberately built to need no Composer, no Node, and no managed database: it defaults to **SQLite** and ships its own `.env` parser. Large hand-authored **static HTML** marketing pages coexist with server-rendered dynamic modules (Diary/blog, Academy LMS, Community, Mentorship, Member Portal, People, Projects), all funnelling through a single backend entry point, `lib/bootstrap.php`.

**How it is composed.** The dynamic backend is a **~68-file** class-per-concern service layer under `lib/`, fronted by module directories that each begin by including `bootstrap.php`. Cross-cutting concerns — a portable PDO database layer, HMAC-signed admin sessions, stateless CSRF, rate limiting, security headers, events/webhooks — live in `lib/`. On top of the original modules (Diary/blog, Academy LMS, Community, Mentorship, Member Portal, People) it now also runs an **org Team Chat**, a **Meetings** system, the **NextGen Vanguard** programme page, the **IQ** quiz/games hub, and a **Portal collaboration** suite (org task pool, boards, goals, polls, standups, calendar). The site is still in a documented **transition off a legacy WordPress install** (`WORDPRESS-COEXISTENCE.md`).

**Top takeaways:**

1. **Mature, security-conscious, well-documented.** `declare(strict_types=1)` throughout, prepared statements everywhere, server-side payment verification, HMAC webhook validation, CSRF, rate limiting, and a `docs/` folder with real handoff notes. This is not a typical brochure site.
2. **Graceful degradation is a core design principle.** The public site stays up even when secrets (SMTP, Paystack, admin) are absent; only the features that need them switch off. Silent-degradation risk is now mitigated by `AV_DB_STRICT` and a `health.php` probe (see #4).
3. **✅ PII now stored outside the web root.** `contacts.json` and `donations.json` are written via `av_private_path()` (`lib/security.php:197`) under `AV_PRIVATE_DIR` or the already-denied `db/private/`, with legacy web-root files migrated on first use. (Was the top risk in 2026-07; confirmed fixed in code.)
4. **✅ Database fallback is now loud on request.** `AV_DB_STRICT` / `Database::dbStrict()` turns an unreachable primary DB into a hard error instead of a silent SQLite fallback, and `fellBack()` is surfaced by `health.php` + the Studio System tab.
5. **✅ Signing key decoupled from the break-glass token.** `av_secret()` prefers a dedicated `APP_KEY`/`AV_APP_KEY` and only falls back to `ADMIN_TOKEN`, so credential rotation no longer invalidates every session.
6. **Rich, expanded integration surface.** Paystack, Google OAuth + Workspace API, Cloudinary, Google Drive, Anthropic Claude (two assistants), plus now **first-class Gemini** (meeting minutes/transcription), **Recall.ai** + Google Meet REST/Calendar writes, a **Google Chat incoming-webhook mirror**, and **Google push/watch channels** — all optional and env-gated.
7. **An inbound integrations API** (`integrations/api.php`) shares data (mentor directory — email now gated by `AV_MENTORS_SHARE_EMAIL`) and exposes billable AI actions (`bot.ask`/`chioma.ask`) to sister sites via scoped Bearer tokens, over a wildcard-CORS endpoint (see finding M-2).
8. **Surviving fragmentation / tech debt:** two **divergent admin-authorization models** (Studio `admin_users` roles vs Community clearance for IQ/chat — see M-1), duplicated routing (`.htaccess` now diverges from `router.php`), no autoloader for the growing `lib/` (~50 eager requires), new secrets undocumented in `.env.example`, and the self-contained `projects/sts` Astro+PHP+Apps Script island that bypasses the shared security layer.

---

## 2. Architecture & Composition

### Request lifecycle

```
Browser
  │
  ▼
Apache + mod_rewrite  (.htaccess)          ← production front door
  │   • DirectoryIndex: index.html → index.php
  │   • clean/extensionless URLs (/about → about.html, /name → name.php)
  │   • pretty routes (/diary/<slug>, /academy/<slug>/learn, /people/<id>, /auth/google/*)
  │   • hard denies: lib/ db/ data/, dotfiles, config.*, contacts/donations.json
  ▼
Static .html  ──OR──  Module index.php / api.php
                          │
                          ▼
                     lib/bootstrap.php   ← single backend entry point
                          │  • parse .env  • conditionally load config.php
                          │  • promote AV_* env → constants (safe fallbacks)
                          │  • require_once ~50 lib/*.php classes (no autoloader)
                          ▼
                     lib/ service layer (Database, Diary*, Lms*, Community,
                     Mentorship, Google*, Payments, Mailer, Webhooks, AvBot,
                     Meetings, IQ, Ngv, Collab, Gemini, RecallBot…)
```

`router.php` re-implements the `.htaccess` rewrite rules so `php -S … router.php` behaves like production Apache for local dev — but it is now **materially stale**: it has no explicit routes for IQ, NGV, `/workspace`, `/franchise`, `/how-it-works`, or `/blueprint`, which work only via its generic `.php` fallback. Dev no longer faithfully mirrors prod (finding S-7).

### Directory map

| Path | Role |
|---|---|
| `lib/` | The domain layer — **68** class-per-concern files + `bootstrap.php`, `security.php`, `helpers.php`, `partials.php` (631 LOC shared page chrome), plus `Config.php`, `Migrator.php`. |
| `db/` | SQLite DB (`diary.sqlite`), SQL schemas (`schema.sql` / `.mysql.sql` / `.pgsql.sql`), seeders, `migrate.php`, `webhooks_run.php`, and generated `private/` (PII stores) + `cache/rl/` (rate-limit). Web-denied. |
| `data/` | Runtime caches (e.g. TTS audio). Web-denied. |
| `admin/` | The Studio back-office: `api.php` (942 LOC, **103 action cases**), `index.php` (916 LOC), `app.js` (1,725 LOC SPA), `admin.css`. |
| `IQ/` | Incorruptible Quiz hub: `index.php`, `api.php`, `admin.php`, `embed.php`, `iq.js` (449 LOC — brain games + personality quizzes), `iq.css`. **No per-dir `.htaccess`.** |
| `integrations/` | `api.php` — inbound scoped Bearer-token API for sister sites/bots (wildcard CORS). |
| `auth/`, `login/`, `portal/` | Google OAuth start/callback, password/OTP sign-in, and the Workspace launchpad — `portal/` now hosts **16 endpoints** (chat, meetings, collab, boards, goals, polls, reminders, standup, notifications, calendar, bookmarks, directory, prefs, dues, workspace). |
| `diary/`, `academy/`, `community/`, `mentorship/`, `people/`, `events/`, `blog/`, `ethos/`, `terms/`, `privacy-policy/`, `webhooks/`, `projects/` | Server-rendered dynamic modules (each `index.php` + often `api.php`); `academy/ngv/` and `academy/studio/` are new. |
| `*.html` / `*.php` (root) | Large hand-authored static marketing pages + custom error pages; new root PHP pages `how-it-works.php`, `franchise.php`, `blueprint.php` (members-only), `workspace.php`, `process-wish.php`, `health.php`. |
| `js/`, `assets/` | `site.js`, `onboarding.js` + 117 static-page enhancer scripts under `assets/`. |
| `docs/` | 26 markdown docs incl. HANDOFF, db-portability, integrations, webhooks, workspace, meetings, chat-roadmap, configuration. |
| `vendor/` | Composer install (PHPMailer only). |
| `tasks/`, `tools/`, `deploy/`, `tests/` | `cron.php` web-cron runner + operator tooling; `deploy/docker-entrypoint.sh`; `tests/run.php` + `suite.test.php` (377 LOC). |

### Tech stack

- **Language/runtime:** PHP 8, `declare(strict_types=1)` throughout.
- **Web server:** Apache + `mod_rewrite` / `mod_headers` (shared cPanel target); Cloudflare-aware.
- **Database:** SQLite by default; MySQL/MariaDB and PostgreSQL supported via a portable PDO layer (`docs/db-portability.md`).
- **Dependencies:** Composer with a **single** dependency — `phpmailer/phpmailer ^6.9`, now the **sole SMTP transport** (`lib/Mailer.php`); the hand-rolled `lib/Smtp.php` has been retired. `.env` parser and router are still hand-rolled.
- **Front-end:** static HTML/CSS/vanilla JS (no SPA framework on the public site); a PWA layer (`sw.js`, `manifest.webmanifest`).
- **Nested sub-apps:** `projects/sts` ships a built **Astro** site plus its own PHP mini-backend and a **Google Apps Script**.
- **Ops/portability (new):** `Dockerfile` + `docker-compose.yml` + `deploy/docker-entrypoint.sh`, a `health.php` liveness/readiness probe (503 on DB down), and a `.github/workflows/ci.yml` that lints every PHP file and runs `php tests/run.php`.

---

## 3. Backend, APIs & Data Layer

### PHP endpoints (top level)

| File | Method(s) | Purpose | Auth |
|---|---|---|---|
| `process-donation.php` | GET/POST | Paystack donation processor (stats, donor wall, verify, record, virtual account, webhook, in-kind). | Public + Paystack HMAC webhook + `ADMIN_TOKEN` for manual bank confirm. |
| `process-contact.php` | POST | Contact + newsletter submissions → `contacts.json`. | Public; honeypot + 5/15min rate limit. |
| `get-config.php` | GET | Returns **only** the Paystack public key to the browser. | Public. |
| `api.php` | GET/POST | Public site API (team/people, etc.). | Public/read. |
| `search.php` | GET | Site search. | Public. |
| `events-feed.php` | GET | Events feed. | Public. |
| `chioma.php` | POST | "Chioma" AI assistant proxy. | Public (rate-limited). |
| `error.php` | — | Shared error renderer. | — |
| `health.php` | GET | Liveness/readiness probe; **503 on DB down**. | Public. |
| `how-it-works.php` / `franchise.php` | GET | Public framework pages (Progressive Growth / Social Franchise). | Public. |
| `blueprint.php` / `workspace.php` | GET | Continental Scaling Plan / Workspace hub. | **Members-only** (`LmsAuth::isOrgMember`). |
| `process-wish.php` | POST | Private birthday note (honeypot + today-only guard). | Public (guarded). |
| `router.php` | — | Dev-server routing mirror of `.htaccess` (now stale). | — |

_(The orphaned `admin-auth.php` middleware has been **deleted** — old finding S-4.)_

### Module APIs

| Endpoint | Purpose |
|---|---|
| `admin/api.php` | Authenticated Studio dispatcher (**103 action cases**): role-gated actions for articles, team, celebrations, enrollments, subscribers, audit log, WordPress import, mentorship (×16), webhooks + app tokens, **NGV (`ngv_*`)**, auth policy, superadmin reveal, system/DB health, mail test. |
| `integrations/api.php` | Inbound Bearer-token API: `community.feed/post/reply`, `event`, `mentors.directory`, `bot.ask`, `chioma.ask`. Wildcard CORS (finding M-2). |
| `IQ/api.php` | Quizzes/games/leaderboard reads + `submit`; **authoring gated by `Community::isAdmin` (clearance ≥ 2)** — a different model than the Studio (finding M-1). |
| `portal/chat.php` + 15 others | Team Chat, meetings, collab/task-pool, boards, goals, polls, reminders, standup, notifications, calendar, bookmarks, directory, prefs, dues, workspace. All `LmsAuth::user()`-gated. |
| `diary/api.php` | Diary/blog CRUD + reactions. |
| `academy/api.php` | LMS: courses, lessons, enrolment, progress, certificates. |
| `community/api.php` | Community spaces/posts feed + bot identity. |
| `mentorship/api.php` | Mentorship pool, pairings, cohorts. |

### Data layer

- **`lib/Database.php`** — a PDO **singleton**, driver-selectable (sqlite/mysql/pgsql), with `ERRMODE_EXCEPTION`, emulated prepares off. It **self-provisions**: on first use it creates + migrates + seeds SQLite from `db/schema.sql` (revision-gated auto-migration), or auto-applies `schema.mysql.sql` / `schema.pgsql.sql` when the core table is absent. If a configured server DB is unreachable it **silently falls back to SQLite** (recorded in `fellBack()`).
- **`db/migrate.php`** — FK-safe cross-engine data copy with row-count verification.
- **Core tables** (`db/schema.sql`, ~24): `articles`, `categories`, `related`, `reactions`, `diary_entries`, `auth_illustrations`, `courses`, `modules`, `sections`, `lessons`, `lesson_progress`, `quiz_attempts`, `course_enrolment`, `enrollments`, `certificates`, `memberships`, `payments`, `lms_users`, `lms_sessions`, `lms_audit`, `subscribers`.
- **Runtime-provisioned tables** (~36 more via `ensure*` helpers, total ~60): chat (`community_chat`, `community_chat_channels`, `community_chat_channel_members`, `community_chat_reactions`, `community_chat_saves`, `community_chat_trash`, `community_typing`, `presence`), meetings (`meetings`, `meeting_attendees`, `meeting_transcripts`), IQ (`iq_quizzes`, `iq_questions`, `iq_attempts`), portal/collab (`collab_tasks`, `team_cards`, `team_goals`, `team_events`, `team_links`, `team_polls`, `team_poll_votes`, `team_standups`, `user_notifications`, `user_prefs`, `user_reminders`, `activity`, `automation_runs`, `member_referrals`), `app_meta` (NGV content doc), `app_tokens`, `admin_users`, `communities`, `google_connections`, `google_channels`, `mentor_*`.
- **File-based PII stores are now outside the web root:** `donations.json` and `contacts.json` are written via `av_private_path()` under `AV_PRIVATE_DIR` or the already-denied `db/private/` — file-locked (`LOCK_EX`), history-capped at 500, `chmod 0600`, with legacy web-root files migrated on first use (old finding S-1, confirmed fixed).

---

## 3A. New subsystems (landed since 2026-07-12)

`lib/` grew from ~47 to **68 classes**. The additions cluster into five
subsystems; each is real, wired, and gated, but none was covered by the prior
audit.

### A. Team Chat — org Slack-style chat + Google Chat mirror
- **UI:** `#teamChat` in `portal/index.php` + `portal/team-chat.js` + `.tc-*` styles. **API:** `portal/chat.php`. **Backend:** `lib/Community.php` (~600 LOC of its 1,528 host the chat), with `lib/Collab.php` (presence/heartbeat), `lib/AvBot.php`+`lib/Gemini.php` ("Catch-me-up" recap), `lib/Notify.php`/`Notifications.php` (mention email).
- **Tables:** `community_chat`, `community_chat_channels`, `community_chat_channel_members`, `community_chat_reactions`, `community_chat_saves`, `community_chat_trash`, `community_typing`, `presence`.
- **Gate:** any signed-in org member (`Community::canChat`); writes are same-origin + CSRF + rate-limited; channel admin needs Community clearance ≥ 2. Outbound **Google Chat incoming-webhook mirror** (`Community.php:1252`, host-checked `chat.googleapis.com`) — message bodies leave the org per `gchat_on` channel (finding L-3).

### B. Meetings — Google Meet scheduling, AI minutes, recorder bots
- **UI/API:** `portal/meetings.php` + `portal/meetings.js`. **Backend:** `lib/Meetings.php` (627 LOC) + `lib/RecallBot.php`, using `lib/GoogleWorkspace.php` (`createMeetEvent`, `meetTranscriptText`) and `lib/Gemini.php` (minutes/audio transcription).
- **Tables:** `meetings`, `meeting_attendees`, `meeting_transcripts`.
- **Integrations:** Google Calendar/Meet (service account, domain-wide delegation), **Recall.ai**, custom webhook recorder, Gemini Flash.
- **Gate:** user endpoints behind `LmsAuth::user()` + `av_require_write`. Two pre-auth **service endpoints** — `bot_ingest` (per-meeting HMAC, `Meetings.php:498`) and `recall_webhook` (`?t=AV_RECALL_WEBHOOK_TOKEN`, rejects empty token, `:457`) — see finding L-2. Their secrets (`AV_RECALL_*`, `AV_MEET_BOT_*`, `AV_GEMINI_*`) are **not in `.env.example`** (finding M-3).

### C. NextGen Vanguard (NGV) — programme page **+ member programme on its own DB**
- **Content page:** `academy/ngv/index.php` (public, SEO/JSON-LD) + `academy/ngv/edit.php` (two-pane admin editor). **Backend:** `lib/Ngv.php` — content document in the **`app_meta`** table (main DB) with version backup/restore; edited via `admin/api.php` actions `ngv_*` with `AdminAudit::log`.
- **⚠️ Separate database.** NGV *participant* data lives in its **own DB**, isolated from the main site DB — `lib/NgvDb.php` is an independent PDO connection (`AV_NGV_DB_*` config; defaults to `db/ngv.sqlite`, portable to a separate MySQL/Postgres), self-provisioning its own schema via the shared `Database::execSchema()` translator. Tables: `ngv_participants`, `ngv_payments` (append-only fee ledger — void, never delete), `ngv_certifications`, `ngv_applications`. It shares **no** tables with the main DB.
- **Domain:** `lib/NgvMember.php` — enrolment (status/cohort/track), the fee ledger + computed fee status (membership/year, commitment/month), certifications, and the public-registration applications intake + enrol-from-application (the only cross-DB step: matches applicant email → `lms_users`).
- **Member dashboard:** `academy/ngv/dashboard.php` — signed-in vanguard's home (phase, track, 24-book challenge, focus note, real fee status + certs). Gated on `LmsAuth::user()`; self-saves via same-origin + CSRF + rate-limited POST; migrates prior `Prefs`-based state on first visit.
- **Public registration:** `academy/ngv/register.php` — no-login application form (honeypot + same-origin + per-IP rate limit); default Apply CTAs now point here instead of the old external `bit.ly` link.
- **Staff console:** `academy/ngv/members.php` (`av_admin_role`) — roster, applications inbox (enrol/reject), record/void payments, add certifications; JSON actions guarded by admin + same-origin + CSRF.

### D. IQ — Incorruptible Quiz, brain games, leaderboard, authoring
- **Entry:** `IQ/index.php` (hub), `IQ/api.php` (JSON), `IQ/admin.php` (authoring), `IQ/embed.php`; `IQ/iq.js` (449 LOC — 3 brain games + personality quizzes, client-side). Served at `/IQ/` (capital-I path; **no per-dir `.htaccess`**). **Backend:** `lib/IQ.php` (529 LOC).
- **Tables:** `iq_quizzes`, `iq_questions`, `iq_attempts`.
- **Gate:** public reads open; `submit` same-origin + rate-limited; **authoring gated by `Community::isAdmin` (clearance ≥ 2)** — a different model than the Studio's `admin_users` roles, and not wired into `admin/api.php` at all (finding M-1).

### E. Portal collaboration / org task pool / calendar sync
- **Entry:** 16 `portal/*.php` endpoints (`collab`, `boards`, `goals`, `polls`, `reminders`, `standup`, `notifications`, `calendar`, `bookmarks`, `directory`, `prefs`, `dues`, `workspace`, …), all `LmsAuth::user()`-gated. **Backend:** `lib/Collab.php`, `Boards.php`, `Goals.php`, `Polls.php`, `Reminders.php`, `Standup.php`, `Notifications.php`, `Bookmarks.php`, `TeamCalendar.php`, `Prefs.php`, `MemberDirectory.php`, `AvAutomation.php`, `AvEvents.php`, `Levels.php`, `AiKnowledge.php`.
- **Tables:** `collab_tasks`, `team_cards`, `team_goals`, `team_events`, `team_links`, `team_polls`, `team_poll_votes`, `team_standups`, `user_notifications`, `user_prefs`, `user_reminders`, `activity`, `automation_runs`, `member_referrals`.

### Also: Google Workspace deepening
`lib/GoogleWorkspaceUser.php` (per-user OAuth connect), `lib/GoogleWatch.php` + `webhooks/google.php` (Calendar/Drive push channels → `google_channels`/`google_connections`), root `workspace.php` + `lib/workspace.php` ("the site IS Workspace" hub).

---

## 4. Frontend & Pages

The public site is **static-first**: large hand-authored HTML pages, enhanced with `fetch()` calls to the PHP JSON endpoints.

| Page | Notes |
|---|---|
| `index.html` (331 KB) | Homepage. |
| `about.html` (195 KB) | About / mission. |
| `contact.html` (179 KB) | Contact form → `process-contact.php`. |
| `donate.html` (369 KB) | Donation flow → `get-config.php` + `process-donation.php` (Paystack hosted checkout redirect). |
| `donor-dashboard.html` | Donor wall / admin donation view. |
| `member.html` | Member landing. |
| `403/404/429/500/503.html` | Custom branded error pages. |

- **Server-rendered modules** (Diary, Academy, Community, Mentorship, People, Portal) render through `lib/partials.php` (`render_head`/`render_nav`, JSON-LD schema helpers) for consistent chrome and SEO.
- **Client JS:** `js/site.js`, `js/onboarding.js` (public); `admin/app.js` (115 KB back-office SPA); `login/auth.js` (sign-in).
- **PWA:** root-scoped `sw.js` precaches an app shell + offline page; network-first for navigations, stale-while-revalidate for static assets, and **never** caches API or authenticated HTML.

---

## 5. Donations & Payments

The money path (`process-donation.php`) is the most safety-critical code and is well-built. It deliberately loads only `lib/security.php` + `bootstrap.php` (not the full stack) and **fails cleanly** (HTTP 503) if `PAYSTACK_SECRET_KEY` is absent rather than attempting to charge.

**Flow & controls:**

| Action | Control |
|---|---|
| `record_donation` (POST) | **Server-side verifies** the transaction with Paystack (`/transaction/verify`) — amount/currency come from Paystack, **never** the client. |
| `webhook` (POST) | Verifies `X-Paystack-Signature` via `hash_equals(hash_hmac('sha512', body, SECRET), sig)` before trusting `charge.success`. |
| Idempotency | `storeDonationIfNew()` does check-and-write inside a **single `LOCK_EX`** (fixes a TOCTOU race); safe on retried webhooks. |
| Rate limiting | 60/min global + 10/min on payment actions, per client IP. |
| Amount validation | Min (`MIN_DONATION_AMOUNT` ₦1,000) and max (₦10,000,000) bounds; reference regex `^[A-Za-z0-9_\-]{5,100}$`. |
| Idempotency store | `donations.json`, locked, capped at 500 entries, `chmod 0600`. |
| Events | Emits `donation.completed` for the webhook dispatcher (best-effort, guarded). |
| Manual bank confirm | `record_bank_transfer` requires `ADMIN_TOKEN` (`hash_equals`). |
| Provider | **Paystack** (cards + Dynamic Virtual Accounts for bank transfer); Flutterwave keys are wired as optional. Static Zenith Bank details for manual transfer. |
| Email | Receipts + admin notifications via the dependency-free `Mailer` (authenticated SMTP), degrading to `mail()`/log. |

**Concern:** the only real weakness here is storage location — `donations.json` sits in the web root (see Finding S-1), not the correctness/verification of the payment logic, which is sound.

---

## 6. Auth, Admin & Members

The site runs **four layered auth mechanisms**, all keyed off `lib/security.php`:

1. **Break-glass admin token** — a valid `Bearer <ADMIN_TOKEN>` (or `X-Admin-Token`) grants **superadmin** and, being an API credential, bypasses CSRF by design. Compared with `hash_equals`.
2. **Signed admin session cookie** — `av_admin` is a **stateless HMAC-signed** cookie (`exp.nonce.role.sig`) with the role embedded inside the signed payload; httpOnly, `SameSite=Lax`, `Secure` when HTTPS. Legacy 3-part cookies map to superadmin for back-compat.
3. **LMS / member RBAC** — `LmsAuth` + `admin_users` bridge members into `editor` / `admin` / `superadmin`. A default superadmin is auto-provisioned on first sign-in (`AV_SUPERADMIN_EMAIL`).
4. **Google OAuth** (`auth/google.php`) — one-shot `SameSite=Lax` state cookie (login-CSRF protection), host-pinned to the canonical domain, `hash_equals` on state, rate-limited; org-domain verified accounts are recognised as real members.

**Admin request gate** (`admin/api.php`) is exemplary:
- `login` is rate-limited (8 attempts / 15 min).
- Cookie-authenticated **writes require a valid CSRF header** (`av_csrf_require()`); Bearer writes are exempt.
- Actions are **role-gated**: superadmin-only vs admin vs editor ("Editors can manage content only").
- Sensitive reads go through parameterised PDO (`enrollments`, `subscribers`, `audit_log`).

**Member portal** (`portal/`) is a Google Workspace launchpad (mail/chat/meet/calendar/drive tiles), optionally backed by real Workspace API reads via a service account with domain-wide delegation.

**Access-control notes:** S-3 (signing key) and S-4 (token minimum + orphaned `admin-auth.php`) are now **resolved**. The live authorization concern is **M-1** — two divergent admin-authorization models: the Studio's `admin_users` roles vs Community clearance (`Community::isAdmin`) used to gate IQ authoring and chat channel management.

---

## 7. Integrations

| Integration | Where wired | Config / keys | Notes |
|---|---|---|---|
| **Paystack** (payments) | `process-donation.php`, `get-config.php`, `lib/Payments.php` | `AV_PAYSTACK_PK` / `AV_PAYSTACK_SK` (aliases `PAYSTACK_PUBLIC/SECRET_KEY`) | Cards + virtual accounts; HMAC webhooks. Primary provider. |
| **Flutterwave** (payments) | `bootstrap.php` (defined, optional) | `FLW_PUBLIC_KEY` / `FLW_SECRET_KEY` | Wired but optional/secondary. |
| **Google OAuth** | `auth/google.php`, `lib/GoogleAuth.php` | `AV_GOOGLE_CLIENT_ID` / `_SECRET` | "Continue with Google"; disabled if unset. |
| **Google Workspace API** | `portal/`, `lib/GoogleWorkspace.php` | `AV_GDRIVE_SERVICE_ACCOUNT`, `AV_WS_SUBJECT`, domain-wide delegation | Live calendar/drive/directory reads in the portal. |
| **Cloudinary** | `lib/Cloudinary.php` | `CLOUDINARY_CLOUD_NAME/API_KEY/API_SECRET` | Image uploads; falls back to local `/uploads`. |
| **Google Drive** | `lib/Drive.php` | service account key | Document storage; local fallback. |
| **Anthropic Claude** | `lib/AvBot.php`, `lib/Chioma.php`, `integrations/api.php` | `ANTHROPIC_API_KEY`, `AV_AI_MODEL` | Two assistants: `@Afrovanguard` community bot + "Chioma" site guide. |
| **Chioma agent webhook** | `lib/Chioma.php` | `AV_CHIOMA_AGENT_URL` / `_KEY` | Optional external agent can *be* Chioma. |
| **TTS (OpenAI/ElevenLabs)** | `lib/Tts.php` | `AV_TTS_ENGINE/API_KEY/VOICE` | Diary "Listen" neural narration; optional. |
| **Gemini** (first-class) | `lib/Gemini.php` (+ `projects/sts/ceo/api/ai.php`) | `AV_GEMINI_*`, `GEMINI_API_KEY` | Meeting minutes + audio transcription; also the isolated STS sub-app. Keys **not in `.env.example`** (M-3). |
| **Recall.ai + Google Meet writes** | `lib/RecallBot.php`, `lib/GoogleWorkspace.php` (`createMeetEvent`) | `AV_RECALL_*`, `AV_MEET_BOT_*` | Meeting recorder bots + Calendar/Meet event creation. Keys **not in `.env.example`** (M-3). |
| **Google Chat mirror** | `lib/Community.php:1252` | per-channel incoming-webhook URL | Outbound chat mirror to `chat.googleapis.com` (host-checked); content leaves the org (L-3). |
| **Google push/watch** | `lib/GoogleWatch.php`, `webhooks/google.php` | service account | Calendar/Drive change notification channels. |
| **SMTP (Gmail/Workspace)** | `lib/Mailer.php` (PHPMailer) | `SMTP_*` / `AV_SMTP_PASSWORD` | **PHPMailer is now the sole transport** (`lib/Smtp.php` retired); default From `cacentre@`, donations from `donations@`; degrades to `mail()`. |
| **Cloudflare** | `lib/security.php` | (built-in CIDR ranges) | Trusted-proxy client-IP resolution. |
| **Inbound integrations API** | `integrations/api.php` | `av_int_…` Bearer app tokens (scoped) | Sister-site sync (NextGenGen mirrors the mentor directory). |
| **Webhooks (outbound)** | `lib/Webhooks.php`, `lib/Events.php`, `db/webhooks_run.php` | `AV_CRON_KEY` (falls back to `ADMIN_TOKEN`) | Retry queue w/ exponential backoff; web-cron runner. |

---

## 8. Infrastructure & Deployment

- **Hosting model:** shared **cPanel + Apache**, PHP 8. No build step required for the core site; `git pull` / upload-and-run. Cloudflare in front. Now **also containerizable**: `Dockerfile` + `docker-compose.yml` + `deploy/docker-entrypoint.sh`, with `health.php` as the container health probe and `.github/workflows/ci.yml` linting every PHP file + running `php tests/run.php` on push.
- **Routing:** `.htaccess` in production (must be activated only once WordPress is removed from the web root — see `WORDPRESS-COEXISTENCE.md`); `router.php` for local dev.
- **Config & secrets:** three-tier — real Apache `SetEnv`/PHP-FPM env (wins) → a `.env` file (parser prefers one **above** the web root) → `config.php` constants. `config.example.php` and `.env.example` are the templates; **only four secrets are required** (`AV_ADMIN_TOKEN`, `AV_SMTP_PASSWORD`, `AV_PAYSTACK_PK`, `AV_PAYSTACK_SK`). `config.php` is git-ignored and denied by `.htaccess`.
- **Dependencies:** Composer (PHPMailer only) in `vendor/`; everything else vendored/hand-rolled.
- **Database:** zero-config SQLite by default under `db/`; documented cutover path to MySQL/Postgres via `db/migrate.php`.
- **Scheduled work:** `db/webhooks_run.php` (shell cron) or `tasks/cron.php?key=…` (web cron for no-SSH hosts), gated by `AV_CRON_KEY`.
- **Caching / PWA:** service worker app-shell precache; `Cache-Control` on public endpoints (e.g. `get-config.php` 1 h).
- **Error handling:** custom `403/404/429/500/503.html`; `display_errors` off in production (`av_harden_errors()`), errors logged.
- **Security headers:** strong CSP, `X-Content-Type-Options`, `X-Frame-Options: SAMEORIGIN`, `Referrer-Policy`, `Permissions-Policy`, `COOP`, HSTS in prod — set both in `.htaccess` and in `lib/security.php`.

---

## 9. Security Findings

Sorted by severity. The codebase is **notably hardened**; most findings are defense-in-depth or maintainability, not active exploits.

> **Remediation status (confirmed in code, 2026-08-06).** S-1…S-5 are **fixed and verified in the live source** (not merely claimed). **S-6 is now partially fixed (2026-08-06):** `'unsafe-eval'` has been removed from the production `.htaccess` CSP — the earlier HIGH item (the `unsafe-eval` addition) is closed; the residual `'unsafe-inline'` is tracked as **M-4**. S-7 (routing) remains **open and has worsened** — `router.php` has drifted further from `.htaccess`. Four other findings (M-1…M-3, L-1…L-3) come from the subsystems added since. See the re-ranked table below.
>
> | # | Status | Verified in code |
> |---|---|---|
> | S-1 | ✅ Fixed | `av_private_path()` (`lib/security.php:197`) stores `donations.json`/`contacts.json` under `AV_PRIVATE_DIR` or the denied `db/private/`, migrating legacy files. Wired into `process-donation.php` + `process-contact.php`; `db/private/` gitignored. |
> | S-2 | ✅ Fixed | `AV_DB_STRICT` / `Database::dbStrict()` (`Database.php:21`) makes an unreachable primary DB a hard error; `fellBack()` surfaced in `health.php` + System tab. |
> | S-3 | ✅ Fixed | `av_secret()` prefers `APP_KEY`/`AV_APP_KEY` for signing, falling back to `ADMIN_TOKEN` only when unset (`security.php:22`, `bootstrap.php:171`). |
> | S-4 | ✅ Fixed | `AV_ADMIN_TOKEN_MIN = 32` enforced by `av_admin_token_configured()` (`security.php:32`); orphaned `admin-auth.php` **deleted** (confirmed absent). ⚠️ **Action:** if your live `AV_ADMIN_TOKEN` is < 32 chars, regenerate it. ⚠️ `docs/configuration.md:744` still says "≥ 8" — stale, correct it. |
> | S-5 | ✅ Fixed | Mentor email in `mentors.directory` gated by `AV_MENTORS_SHARE_EMAIL` (`bootstrap.php:179`). |
> | S-6 | ◑ Partially fixed (2026-08-06) | `'unsafe-eval'` **removed** from the `.htaccess` `script-src`, so both CSP copies now match the eval-free `lib/security.php` policy. `'unsafe-inline'` remains (the 300 KB+ inline static pages need it) — tracked as the residual **M-4**. |
> | S-7 | ⏸ Open, worse | `router.php` no longer mirrors `.htaccess` (≥6 route families missing). |

Re-ranked 2026-08-06 with the current live items on top. S-1…S-5 are resolved
(see the status banner); the items below are the open ones.

| # | Severity | Area | Location | Issue | Recommendation |
|---|---|---|---|---|---|
| ~~H-1~~ | ✅ **Fixed 2026-08-06** | XSS surface | root `.htaccess:119` | CSP `script-src` no longer allows `'unsafe-eval'` — removed after confirming no `eval`/`Function()` use in the app's own JS/HTML and that the `lib/security.php` policy already ran eval-free. Both CSP copies now match. |
| **M-4** | **Medium** | XSS surface | root `.htaccess:119`; `lib/security.php:70,80` | Residual: CSP still uses `'unsafe-inline'` for `script-src` and `style-src`, required by the 300 KB+ of hand-authored inline HTML and the admin SPA. This remains the weakest single control on the app. | Move inline scripts/styles to files + per-response nonces (or hashes) so `'unsafe-inline'` can be dropped, at least for authenticated pages; do it page-family by page-family to avoid breaking the large static pages. |
| **M-1** | **Medium** | AuthZ model | `IQ/api.php:27-28`, `portal/chat.php:146,161,170` vs `admin/api.php` | **Two divergent admin-authorization models.** Studio uses `admin_users` roles; IQ authoring and chat channel management gate on `Community::isAdmin` (community **clearance ≥ 2**), a different table/scale. Someone with community clearance but no Studio `admin_users` row can author public IQ quizzes. | Reconcile onto one authorization source; at minimum document which surfaces use which gate. |
| **M-2** | **Medium** | Data sharing / cost | `integrations/api.php:28-30` (`Access-Control-Allow-Origin: *`) | The inbound API is wildcard-CORS and can return mentor name+email (`mentors.directory`) and invoke **billable AI** (`bot.ask`, `chioma.ask`) / post as the official bot. Token-scoped and email now gated (S-5), but a credentialed data+AI surface reachable from any origin warrants confirmation against policy + cost caps. | Restrict CORS to known sister-site origins; rate-limit/cap the AI actions per token; confirm the PII share is covered by the privacy policy. |
| **M-3** | **Medium** | Config / ops | `.env.example` vs `lib/Meetings.php:383`, `lib/Gemini.php:30`, `Config.php` | New secrets are read in code but **absent from `.env.example`**: `AV_RECALL_*`, `AV_MEET_BOT_PROVIDER/JOIN_URL`, `AV_GEMINI_*`, `AV_CHAT/MEET/REPORTS_BASE_URL`, `AV_GWS_*`. Operators can't discover them; the meeting-bot / Gemini keys are the most sensitive new secrets. `docs/configuration.md:744` also still says the admin-token min is 8 (code enforces 32). | Document every new `AV_*` key in `.env.example`; fix the stale 8→32 min in `docs/configuration.md`. |
| S-7 | **Medium** | Maintainability | `.htaccess` vs `router.php:26-87` | Routing is hand-maintained in two places and has now **drifted** — `router.php` lacks IQ, NGV, `/workspace`, `/franchise`, `/how-it-works`, `/blueprint`; dev doesn't mirror prod, so route bugs won't surface locally. | Generate one from the other, or add a route-parity smoke test; at minimum add the missing dev routes. |
| **L-1** | **Low** | Perf / maintainability | `lib/bootstrap.php:228-275` | No autoloader; **~50 classes eager-required** on every request, including near-static pages. Grows linearly with `lib/`. | Add a lightweight PSR-4-style autoloader so a page loads only what it uses. |
| **L-2** | **Low** | Auth (service endpoints) | `portal/meetings.php:22-33`, `Meetings.php:457,498` | The `bot_ingest` / `recall_webhook` endpoints run before the auth wall. Correctly HMAC/token-gated and reject empty tokens — but if `AV_RECALL_WEBHOOK_TOKEN` is unset the webhook path silently no-ops. | Verify operators set the token; log/alert when a webhook arrives with the token unconfigured. |
| **L-3** | **Low** | Data egress | `lib/Community.php:805,1252` | The Google Chat mirror posts message bodies to an external `chat.googleapis.com` webhook (host checked). Content leaves the org per `gchat_on` channel. | Confirm the mirror is intended per channel; make the egress visible in channel settings. |
| S-8 | **Info** | Architecture | `projects/sts/` (Astro + `ceo/api/*.php` + Apps Script) | Self-contained sub-apps bypass the shared `bootstrap.php`/`security.php` layer and carry their own config + keys (`GEMINI_API_KEY`, `STS_APPS_SCRIPT_KEY`). | Audit `projects/sts` separately; out of scope of the shared hardening. |
| S-9 | **Info** | Config safety | `lib/bootstrap.php:140-155`, root `.htaccess:78-105` | Secret/PII protection still rests on `.htaccess` `FilesMatch` denies plus keeping `config.php`/`.env` out of the web root — brittle during the WordPress transition. | Keep `.env` above the web root; verify the deny block is active the moment WordPress leaves. |

**Positive controls worth recording:** parameterised PDO (no string-built SQL on user input), `hash_equals` for all secret comparisons, server-side payment verification, HMAC webhook signatures, CSRF on cookie-auth writes, per-IP rate limiting, honeypot on the contact form, httpOnly/SameSite/Secure cookies, OAuth state pinning, upload size caps, Cloudflare-aware IP resolution that refuses untrusted forwarded headers, and — new since the last audit — a `health.php` liveness/readiness probe, `AV_DB_STRICT`, per-meeting HMAC bot tokens, uniform `require_same_origin()` + CSRF on chat writes, and a CI workflow that lints every PHP file and runs the data-layer suite.

---

## 10. Risks, Tech Debt & Prioritized Recommendations

The 2026-07 Priority-1/2 list (relocate PII, loud DB failure, dedicated
`APP_KEY`, token minimum, mentor-PII gate — S-1…S-5) is **done and confirmed in
code**. The current priorities are:

**Priority 1 — the live risk**
1. ✅ **Done (2026-08-06):** `'unsafe-eval'` removed from the production CSP. **Next:** work toward removing the residual `'unsafe-inline'` via file-based scripts + nonces/hashes, page-family by page-family (M-4).
2. **Reconcile the two admin-authorization models** (M-1) — decide whether IQ authoring / chat channel admin should key off `admin_users` roles or Community clearance, and make it one source of truth.

**Priority 2 — integration & config hygiene**
3. **Lock down the inbound API** (M-2) — restrict CORS to sister-site origins and cap the billable `bot.ask`/`chioma.ask` actions per token.
4. **Document every new `AV_*` secret in `.env.example`** and fix the stale 8→32 admin-token minimum in `docs/configuration.md` (M-3).
5. **Verify the meeting-bot webhook token is set** in production (L-2) and confirm the Google Chat mirror egress is intended per channel (L-3).

**Priority 3 — maintainability & performance**
6. **De-duplicate routing** or add a route-parity test, and at minimum add the missing dev routes to `router.php` (S-7).
7. **Add a lightweight autoloader** for `lib/` so a page doesn't eager-load ~50 classes (L-1).
8. **Audit `projects/sts` as its own project** (S-8); verify the `.htaccess` secret/PII denies stay live through the WordPress cutover (S-9).

**Overall assessment.** This remains a well-engineered, deliberately
dependency-light monolith with strong security fundamentals and unusually good
internal documentation — and it has visibly matured: last cycle's top data/PII
and availability risks are genuinely closed. The residual risks have shifted
from *data-at-rest fragility* to **front-end XSS surface (the CSP still relies on
`unsafe-inline`, though `unsafe-eval` was removed 2026-08-06)** and to the
**governance of a much larger surface** — two authorization models,
a wildcard-CORS AI/PII endpoint, and a growing set of undocumented secrets —
introduced by the five new subsystems. Addressing Priority 1 materially de-risks
the platform.

---

### Appendix — Audit method

The original 2026-07-12 audit was produced by dispatching parallel workflow
agents (one per lens: architecture, backend/data, frontend, security,
infrastructure, integrations, donations/payments, auth/admin/members).

The **2026-08-06 re-index** re-verified every section against current source
(repo grown ~362 → 584 files): each prior claim was checked STILL-TRUE /
CHANGED / NOW-WRONG, the five new subsystems (§3A) were mapped to their entry
points, `lib/` classes, and tables, and the findings were re-ranked — S-1…S-5
confirmed fixed in code, S-6/S-7 confirmed open-and-worse, and M-1…M-3 / L-1…L-3
raised from the new surface. All findings cite files verified to exist in the
repository at the stated date.
