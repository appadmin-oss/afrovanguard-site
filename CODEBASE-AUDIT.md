# Afrovanguard-Site Codebase Audit

_Repository:_ `appadmin-oss/afrovanguard-site` · _Audit date:_ 2026-07-12 · _Scope:_ full repository (~362 tracked files)

This audit maps the components, composition, and infrastructure of the Afrovanguard main website so the team has an accurate mental model of what runs where, how the pieces connect, and where the risks are.

---

## 1. Executive Summary

**What it is.** `afrovanguard-site` is the main public website and back-office for Afrovanguard ("the Studio") — a Nigerian youth-empowerment nonprofit. It is a **PHP 8 monolith** running on **shared cPanel/Apache hosting**, deliberately built to need no Composer, no Node, and no managed database: it defaults to **SQLite** and ships its own `.env` parser. Large hand-authored **static HTML** marketing pages coexist with server-rendered dynamic modules (Diary/blog, Academy LMS, Community, Mentorship, Member Portal, People, Projects), all funnelling through a single backend entry point, `lib/bootstrap.php`.

**How it is composed.** The dynamic backend is a ~47-file class-per-concern service layer under `lib/`, fronted by module directories that each begin by including `bootstrap.php`. Cross-cutting concerns — a portable PDO database layer, HMAC-signed admin sessions, stateless CSRF, rate limiting, security headers, events/webhooks — live in `lib/`. The site is currently in a documented **transition off a legacy WordPress install** (`WORDPRESS-COEXISTENCE.md`).

**Top takeaways:**

1. **Mature, security-conscious, well-documented.** `declare(strict_types=1)` throughout, prepared statements everywhere, server-side payment verification, HMAC webhook validation, CSRF, rate limiting, and a `docs/` folder with real handoff notes. This is not a typical brochure site.
2. **Graceful degradation is a core design principle.** The public site stays up even when secrets (SMTP, Paystack, admin) are absent; only the features that need them switch off. The trade-off is that failures can be **silent** (see #3, #4).
3. **⚠️ PII files live in the web root.** `contacts.json` and `donations.json` (donor + contact PII) are written to the site root and protected **only** by a name-based `.htaccess` deny — fragile during the WordPress transition.
4. **⚠️ Silent database fallback.** If a configured MySQL/Postgres server is unreachable, the app silently falls back to an (empty) local SQLite DB; this is only visible on the Studio System page.
5. **One secret does two jobs.** `ADMIN_TOKEN` is both the break-glass superadmin credential **and** the HMAC key that signs admin cookies and CSRF tokens; rotating it invalidates all sessions.
6. **Rich integration surface.** Paystack (payments), Google OAuth + Workspace API, Cloudinary, Google Drive, Anthropic Claude (two AI assistants), optional Flutterwave/TTS/Gemini — all optional and env-gated.
7. **An inbound integrations API** (`integrations/api.php`) shares data (including mentor emails) server-to-server with a sister site (NextGenGen) via scoped Bearer tokens.
8. **Some fragmentation / tech debt:** duplicated routing (`.htaccess` + `router.php`), an orphaned second admin-auth implementation, no autoloader for the domain layer, and self-contained sub-apps under `projects/` (an Astro build + a Google Apps Script backend) that bypass the shared security layer.

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
                          │  • require_once ~35 lib/*.php classes
                          ▼
                     lib/ service layer (Database, Diary*, Lms*, Community,
                     Mentorship, Google*, Payments, Mailer, Webhooks, AvBot…)
```

`router.php` re-implements the `.htaccess` rewrite rules so `php -S … router.php` behaves like production Apache for local dev.

### Directory map

| Path | Role |
|---|---|
| `lib/` | The domain layer — ~47 class-per-concern files + `bootstrap.php`, `security.php`, `helpers.php`, `partials.php` (41 KB shared page chrome). |
| `db/` | SQLite DB (`diary.sqlite`), SQL schemas (`schema.sql` / `.mysql.sql` / `.pgsql.sql`), seeders, `migrate.php`, `webhooks_run.php`. Web-denied. |
| `data/` | Runtime caches (e.g. TTS audio). Web-denied. |
| `admin/` | The Studio back-office: `api.php` (63 KB dispatcher), `index.php` (64 KB), `app.js` (115 KB SPA), `admin.css`. |
| `integrations/` | `api.php` — inbound scoped Bearer-token API for sister sites/bots. |
| `auth/`, `login/`, `portal/` | Google OAuth start/callback, password/OTP sign-in, and the Google Workspace member launchpad. |
| `diary/`, `academy/`, `community/`, `mentorship/`, `people/`, `events/`, `projects/` | Server-rendered dynamic modules (each `index.php` + often `api.php`). |
| `*.html` (root) | Large hand-authored static marketing pages + custom error pages. |
| `js/` | `site.js`, `onboarding.js` (public front-end enhancement). |
| `docs/` | HANDOFF, db-portability, integrations, webhooks, workspace, configuration docs. |
| `vendor/` | Composer install (PHPMailer only). |
| `tasks/`, `tools/` | `cron.php` web-cron runner + operator tooling. |

### Tech stack

- **Language/runtime:** PHP 8, `declare(strict_types=1)` throughout.
- **Web server:** Apache + `mod_rewrite` / `mod_headers` (shared cPanel target); Cloudflare-aware.
- **Database:** SQLite by default; MySQL/MariaDB and PostgreSQL supported via a portable PDO layer (`docs/db-portability.md`).
- **Dependencies:** Composer with a **single** dependency — `phpmailer/phpmailer ^6.9`. Everything else is hand-rolled (SMTP client, `.env` parser, router).
- **Front-end:** static HTML/CSS/vanilla JS (no SPA framework on the public site); a PWA layer (`sw.js`, `manifest.webmanifest`).
- **Nested sub-apps:** `projects/sts` ships a built **Astro** site plus its own PHP mini-backend and a **Google Apps Script**.

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
| `admin-auth.php` | — | **Orphaned/legacy** admin middleware (see Findings). | (unreferenced) |
| `router.php` | — | Dev-server routing mirror of `.htaccess`. | — |

### Module APIs

| Endpoint | Purpose |
|---|---|
| `admin/api.php` | Authenticated Studio dispatcher (~63 KB): role-gated actions for articles, team, celebrations, enrollments, subscribers, audit log, WordPress import, etc. |
| `integrations/api.php` | Inbound Bearer-token API: `community.feed/post/reply`, `event`, `mentors.directory`, `bot.ask`, `chioma.ask`. |
| `diary/api.php` | Diary/blog CRUD + reactions. |
| `academy/api.php` | LMS: courses, lessons, enrolment, progress, certificates. |
| `community/api.php` | Community spaces/posts feed + bot identity. |
| `mentorship/api.php` | Mentorship pool, pairings, cohorts. |

### Data layer

- **`lib/Database.php`** — a PDO **singleton**, driver-selectable (sqlite/mysql/pgsql), with `ERRMODE_EXCEPTION`, emulated prepares off. It **self-provisions**: on first use it creates + migrates + seeds SQLite from `db/schema.sql` (revision-gated auto-migration), or auto-applies `schema.mysql.sql` / `schema.pgsql.sql` when the core table is absent. If a configured server DB is unreachable it **silently falls back to SQLite** (recorded in `fellBack()`).
- **`db/migrate.php`** — FK-safe cross-engine data copy with row-count verification.
- **Core tables** (`db/schema.sql`): `articles`, `categories`, `related`, `reactions`, `diary_entries`, `auth_illustrations`, `courses`, `modules`, `sections`, `lessons`, `lesson_progress`, `quiz_attempts`, `course_enrolment`, `enrollments`, `certificates`, `memberships`, `payments`, `lms_users`, `lms_sessions`, `lms_audit`, `subscribers`.
- **File-based stores in the web root:** `donations.json` (donor records) and `contacts.json` (contact/newsletter PII) — file-locked (`LOCK_EX`), history-capped at 500, `chmod 0600` on creation.

---

## 4. Frontend & Pages

The public site is **static-first**: large hand-authored HTML pages, enhanced with `fetch()` calls to the PHP JSON endpoints.

| Page | Notes |
|---|---|
| `index.html` (317 KB) | Homepage. |
| `about.html` (170 KB) | About / mission. |
| `contact.html` (166 KB) | Contact form → `process-contact.php`. |
| `donate.html` (356 KB) | Donation flow → `get-config.php` + `process-donation.php` (Paystack inline). |
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

**Access-control notes:** see Findings S-3 (overloaded signing key), S-4 (weak 8-char minimum on the superadmin break-glass token / orphaned `admin-auth.php` that expects ≥32).

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
| **Gemini** | `projects/sts/ceo/api/ai.php` | `GEMINI_API_KEY`, `STS_AI_ACCESS_KEY` | Isolated STS sub-app only. |
| **SMTP (Gmail/Workspace)** | `lib/Smtp.php`, `lib/Mailer.php` | `SMTP_*` / `AV_SMTP_PASSWORD` | Dependency-free authenticated mail; degrades to `mail()`. |
| **Cloudflare** | `lib/security.php` | (built-in CIDR ranges) | Trusted-proxy client-IP resolution. |
| **Inbound integrations API** | `integrations/api.php` | `av_int_…` Bearer app tokens (scoped) | Sister-site sync (NextGenGen mirrors the mentor directory). |
| **Webhooks (outbound)** | `lib/Webhooks.php`, `lib/Events.php`, `db/webhooks_run.php` | `AV_CRON_KEY` (falls back to `ADMIN_TOKEN`) | Retry queue w/ exponential backoff; web-cron runner. |

---

## 8. Infrastructure & Deployment

- **Hosting model:** shared **cPanel + Apache**, PHP 8. No build step required for the core site; `git pull` / upload-and-run. Cloudflare in front.
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

> **Remediation status (2026-07-12).** S-1, S-2, S-3, S-4, S-5 are **fixed** in this branch. S-6 (CSP `unsafe-inline`) and S-7 (routing duplication) are **deferred** — both are large, cross-cutting refactors on live-serving surfaces (300 KB+ of inline HTML / the routing front door) where a hasty change risks breakage; they are best done as focused, individually-verified follow-ups. See the "Status" column below.
>
> | # | Status | What changed |
> |---|---|---|
> | S-1 | ✅ Fixed | `av_private_path()` (`lib/security.php`) stores `donations.json`/`contacts.json` under `AV_PRIVATE_DIR` (above web root) or the already-denied `db/private/`, and migrates any legacy web-root file on first use. Wired into `process-donation.php` + `process-contact.php`; `db/private/` gitignored. |
> | S-2 | ✅ Fixed | `AV_DB_STRICT=1` makes an unreachable primary DB a hard error instead of a silent SQLite fallback (`Database::dbStrict()`). |
> | S-3 | ✅ Fixed | `av_secret()` now prefers a dedicated `APP_KEY`/`AV_APP_KEY` for signing, falling back to `ADMIN_TOKEN` only when unset — credential rotation no longer invalidates sessions. |
> | S-4 | ✅ Fixed | Break-glass token minimum raised to 32 chars via `av_admin_token_configured()` (used by `security.php`, `bootstrap.php`, `admin/api.php`); orphaned `admin-auth.php` deleted. ⚠️ **Action:** if your live `AV_ADMIN_TOKEN` is shorter than 32 chars, regenerate it (`php -r "echo bin2hex(random_bytes(32));"`). |
> | S-5 | ✅ Fixed | Mentor email in `mentors.directory` is now gated by `AV_MENTORS_SHARE_EMAIL` (defaults on for NextGenGen back-compat; set `0` to share only the stable `ref`). |
> | S-6 | ⏸ Deferred | CSP `unsafe-inline` removal needs nonces threaded through every inline script across the static pages + admin SPA. |
> | S-7 | ⏸ Deferred | De-duplicating `.htaccess`/`router.php` needs a route-parity test harness to change safely. |

| # | Severity | Area | Location | Issue | Recommendation |
|---|---|---|---|---|---|
| S-1 | **Medium** | Data / PII | `process-donation.php:86`, `process-contact.php` (`CONTACT_FILE`), root `.htaccess:91-101` | `donations.json` & `contacts.json` (donor/contact PII) are written to the **web root**, protected only by a name-based `.htaccess` `FilesMatch` deny. If that `.htaccess` isn't honored (server misconfig, or during the WordPress-coexistence swap) the PII becomes directly fetchable. | Move both stores **outside the web root** (or into the already-denied `db/`), like the SQLite DB. Don't rely on a single by-name deny for PII. |
| S-2 | **Medium** | Availability / integrity | `lib/Database.php:73-82` | Silent fallback from a configured MySQL/Postgres to a local (possibly empty) SQLite DB when the server DB is unreachable — the live site can quietly run on the wrong/empty database; only visible on the Studio System page. | Make production DB failure **loud** (alert/500 on a hard flag `AV_DB_STRICT=1`) instead of silently degrading; surface `fellBack()` in monitoring. |
| S-3 | **Low** | Cryptography / auth | `lib/security.php:14-18` | `ADMIN_TOKEN` is overloaded: it is both the break-glass credential **and** the HMAC signing key for the admin cookie and all CSRF tokens. Rotating the credential invalidates every session and outstanding CSRF token; a credential doubles as a crypto key. | Use a **dedicated `APP_KEY`** (already a supported fallback) as the signing key so credential rotation is decoupled from session/CSRF validity. |
| S-4 | **Low** | Auth | `lib/bootstrap.php:257`, `admin/api.php:41`, `admin-auth.php:36` | The live admin gate accepts `ADMIN_TOKEN` as short as **8 chars**, while the orphaned `admin-auth.php` expects **≥32**. Two inconsistent rules, and 8 chars is weak for a superadmin break-glass credential. | Enforce a **single, high minimum** (≥32) for the break-glass token and delete/retire `admin-auth.php` to remove the ambiguity. |
| S-5 | **Low** | Data sharing / PII | `integrations/api.php:101-128` | `mentors.directory` returns mentor **name + email** cross-site to a sister app (NextGenGen). Token-scoped (`mentors:read`) and intentional, but it is real PII leaving the system over a `CORS: *` endpoint. | Confirm the data-sharing is covered by the privacy policy; consider omitting email or hashing a stable ref unless the mirror truly needs it. |
| S-6 | **Low** | XSS surface | `lib/security.php:51-62` | CSP uses `'unsafe-inline'` for both `script-src` and `style-src` (needed by the large inline static pages and the admin SPA), weakening XSS protection. | Longer term, move inline scripts to files + nonces/hashes so `'unsafe-inline'` can be dropped, at least for authenticated pages. |
| S-7 | **Low** | Maintainability | `.htaccess` vs `router.php:26-83` | Routing rules are hand-maintained in two places and can drift; a route added to one must be added to the other. | Generate one from the other, or add a smoke test that asserts both resolve the same set of routes. |
| S-8 | **Info** | Architecture | `projects/sts/` (Astro + `ceo/api/*.php` + Apps Script) | Self-contained sub-apps bypass the shared `bootstrap.php`/`security.php` layer and carry their own config + keys (`GEMINI_API_KEY`, `STS_APPS_SCRIPT_KEY`). | Audit `projects/sts` separately; it is out of scope of the shared hardening and should be reviewed on its own. |
| S-9 | **Info** | Config safety | `lib/bootstrap.php:140-155`, root `.htaccess:74-84` | The entire secret-protection model rests on a single `FilesMatch` block plus keeping `config.php`/`.env` out of (or denied in) the web root — brittle during the WordPress transition. | Keep `.env` **above** the web root (as the docs recommend) and verify the deny block is active immediately after WordPress removal. |

**Positive controls worth recording:** parameterised PDO (no string-built SQL on user input), `hash_equals` for all secret comparisons, server-side payment verification, HMAC webhook signatures, CSRF on cookie-auth writes, per-IP rate limiting, honeypot on the contact form, httpOnly/SameSite/Secure cookies, OAuth state pinning, upload size caps (25 MB WXR import), and Cloudflare-aware IP resolution that refuses to trust forwarded headers from untrusted peers.

---

## 10. Risks, Tech Debt & Prioritized Recommendations

**Priority 1 — do before/around the WordPress cutover**
1. **Relocate `donations.json` and `contacts.json` out of the web root** (S-1). This is the single highest-value hardening change.
2. **Verify the `.htaccess` deny/secret protection is live the moment WordPress leaves the web root** (S-9); confirm `.env` sits above the web root in production.
3. **Make production DB failure loud, not silent** (S-2) — add a strict-mode flag and monitoring on `fellBack()`.

**Priority 2 — auth & crypto hygiene**
4. **Introduce a dedicated `APP_KEY`** for HMAC signing, separate from `ADMIN_TOKEN` (S-3).
5. **Raise the break-glass token minimum to ≥32 and delete the orphaned `admin-auth.php`** (S-4).
6. **Review the cross-site mentor PII share** against the privacy policy (S-5).

**Priority 3 — maintainability & performance**
7. **De-duplicate routing** (`.htaccess`/`router.php`) or add a route-parity test (S-7).
8. **Add a lightweight autoloader** for `lib/` so a simple page doesn't eager-load ~35 classes (`bootstrap.php:199-231`).
9. **Tighten CSP** toward nonces/hashes to remove `'unsafe-inline'`, at least for authenticated surfaces (S-6).
10. **Audit `projects/sts` as its own project** (S-8) — it has independent config, an AI proxy, and an Apps Script backend.

**Overall assessment.** This is a well-engineered, deliberately dependency-light monolith with strong security fundamentals and unusually good internal documentation. The residual risks are concentrated in **operational fragility during the WordPress transition** and in **silent-degradation** behaviours, rather than in the application logic itself. Addressing the Priority 1 items materially de-risks the platform.

---

### Appendix — Audit method

This audit was produced by dispatching parallel workflow agents (one per lens: architecture, backend/data, frontend, security, infrastructure, integrations, donations/payments, auth/admin/members). The architecture lens completed via the automated run; the remaining lenses were completed by direct source review after a session usage limit interrupted the automated pass. All findings cite files verified to exist in the repository at audit time.
