# HANDOFF — NGG × Afrovanguard program of work

Working memory for the next agent. This file is duplicated in both repos
(`nextgengen/docs/HANDOFF.md` and `afrovanguard-site/docs/HANDOFF.md`).

---

## 0. Intent (what the user asked for)

Verbatim scope, plus one mid-stream addition:

1. **Audit and index the NGG website.**
2. **Find a way to sync it with Afrovanguard.**
3. **All Afrovanguard changes go to the `vigilant` branch.**
4. **Add a default super admin** with access to all admins and managers — "I need
   to be able to login."
5. **Improve the admin and managers UI and logic.**
6. **Upgrade the Anchor Journal** (design comps were provided as a zip).
7. (Added later) **"Do the separate super admin for NGG"** — NGG needs its own
   super admin, distinct from Afrovanguard.

The user's email (the intended super-admin identity): `mamcareer@afrovanguard.org.ng`.

### Repos & branches — IMPORTANT
- **Afrovanguard** (`appadmin-oss/afrovanguard-site`): develop on
  **`claude/vigilant-knuth-kuhk7c`** — this is the "vigilant" branch the user
  meant. Do NOT use the audit-sync branch for Afrovanguard.
- **NGG** (`appadmin-oss/nextgengen`): develop on
  **`claude/ngg-afrovanguard-audit-sync-becs2k`**.
- **mam-academy**: in scope but untouched — nothing was required there.
- No PRs have been opened yet (user was asked; hasn't answered). Don't open PRs
  unless asked.

---

## 1. Status — DONE, verified, pushed

| # | Task | Where | Verified |
|---|------|-------|----------|
| 1 | NGG audit & index | `nextgengen/docs/NGG-AUDIT-INDEX.md` | n/a (doc) |
| 2 | Afrovanguard default super admin + login | `afrovanguard-site` (vigilant) | login → superadmin, wrong pw rejected, idempotent |
| 3 | Admin/manager UI + logic | AV Studio card + NGG lockout guards | guard unit-tested |
| 4 | Anchor Journal upgrade (phases 2–4) | `nextgengen/journal/*` | `node build.js` clean |
| 5 | NGG ↔ Afrovanguard sync | both repos | end-to-end over HTTP |
| 6 | Separate NGG super admin | `nextgengen` | login → super_admin, idempotent |

### Task 2 — Afrovanguard super admin (vigilant branch)
- `lib/SuperAdmin.php` — idempotent, fingerprint-guarded `ensure()` sets BOTH
  `admin_users(email→superadmin)` AND `lms_users(role=admin, active, verified,
  password)`. Config: `AV_SUPERADMIN_EMAIL` / `_NAME` / `_PASSWORD`.
- Hooked into `login/index.php` and `admin/api.php` (login/session actions only).
- `db/seed_admin.php` CLI prints/reveals credentials. `admin/api.php` action
  `superadmin_reveal` + `admins_list` extras. Studio UI: `admin/index.php`
  `#adSuperCard` + `admin/app.js` `renderSuperCard()`.
- `.env.example` documents the block.

### Task 6 — NGG super admin (audit-sync branch)
- `api/_lib/superadmin.php` — `ensure_super_admin()` creates/promotes a
  `members` row `role=super_admin` with a password; config `super_admin`
  {email,name,password} in `api/config.php`.
- Hooked into `api/auth/{login,request,verify}.php`. CLI `api/tools/seed-admin.php`.
- NGG admins still pass a one-time TOTP setup on first Control Room visit (by
  design — do not weaken).

### Task 3 — admin logic
- AV: `admin/app.js` shows the default super admin + one-time password reveal.
- NGG: `api/_lib/domain/admin.php` `admin_members_upsert()` now blocks demoting/
  suspending the **last** active super_admin and **self**-demotion/suspension
  (`last_super_admin`, `cannot_demote_self`, `cannot_suspend_self`).

### Task 5 — sync (NGG mirrors Afrovanguard's approved mentor pool)
- **AV side:** `integrations/api.php` action `mentors.directory` + new
  `mentors:read` scope in `lib/AppTokens.php`.
- **NGG side:** `api/tools/sync-afrovanguard.php` (CLI/cron) upserts into
  `mentors` (`source='afrovanguard'`, keyed on `ref`); config `afrovanguard`
  {api_base, api_token}. Docs: `nextgengen/docs/afrovanguard-sync.md`.

### Task 4 — Anchor Journal (all in `nextgengen/journal/app.jsx` + `app.css`)
- Reflect editor v2 (School/Home/Peer structured logs + Coach entry card).
- Coach chat screen (`Coach`) → `NGGApi.member.coach`.
- Focus tool (`Focus`) + 4-tab nav (Journey·Focus·Growth·You).
- Notepad (`Notepad`) + Goals (`Goals`) + Quick Tools grid on Home.
- New `member_state` keys loaded on boot: `focusTasks:<day>`, `notes`, `goals`.

---

## 2. How to log in (tell the user)

**Afrovanguard:** email `mamcareer@afrovanguard.org.ng`. Set
`AV_SUPERADMIN_PASSWORD` in env for a known password, OR run
`php db/seed_admin.php` on the server to reveal an auto-generated one. Sign in at
`/login`. If Google sign-in is configured, "Continue with Google" (org account)
also lands as superadmin. Break-glass `AV_ADMIN_TOKEN` at `/admin` always works.

**NGG:** set `super_admin.password` in `api/config.php` (or run
`php api/tools/seed-admin.php` to reveal a generated one), then sign in with the
email + password and complete the authenticator (TOTP) setup on first Control
Room visit.

---

## 3. Anchor Journal — ALL PHASES DELIVERED (this session)

Everything below is pushed to `claude/ngg-afrovanguard-audit-sync-becs2k`,
each increment verified with `node build.js` (+ `php api/tests/run.php`,
148/148, where the backend changed). Details in
`docs/anchor-journal-v2-gap-analysis.md` §G.

1. ✅ **Multi-step onboarding (Phase 1).** Welcome → Name → Why → Study time;
   persists to `member_state.prefs` (+ `onboardedAt` — completion follows the
   account); Replay intro re-enters pre-filled (one-shot session flag).
2. ✅ **Shop + economy (Phase 5) + XP reconciliation.** Client adopts the
   server's flat model (60 XP/reflection-day, 250/level — `momentum.php` was
   already canonical). `member_state.shop`: diamonds (5/day + 5/focus-session
   − spend), Streak Freeze (30💎, max 2, auto-consume, sync-gated), accent
   themes (20💎) swapping `--a/--a-ink/--a-rgb` app-wide, gold level-up
   overlay (per-device baseline in localStorage — no server-clobber race).
3. ✅ **You → Your Circle (Phases 6–7).** Real invite code + Copy/rotate,
   LINKED/PENDING from `circle_links`, per-link revoke, and circle remarks
   surfacing in Weekly Review ("From your circle").
4. ✅ **Portal cohort strip (Phase 8 remainder).** New `circle.cohort` op
   (device token list → read-only summaries + at-risk flags); /circle
   remembers pairings, chip-switches students, "+ Add", graceful fall-over
   on revocation. (The role→code→verify→consent→dashboard flow shipped in
   an earlier session.)
5. ✅ **Phase 9 core** — printable report was already live; remark→Review
   wiring landed with (3). ✅ **Phase 10** fonts (earlier session).
   ✅ "Sound & haptics" toggle (A10).

6. ✅ **Runtime smoke.** `tests/journal-smoke.js` (Playwright + Chromium +
   real PHP backend on a throwaway SQLite DB): OTP sign-in → onboarding →
   Journey → Shop → Growth → You (live circle code) → Focus → anchor a
   reflection. PASS, zero uncaught page errors. Setup in the file header.

Nothing from the Anchor design comps remains open.

---

## 3b. Platform build wave (post-blueprint) — DONE, verified, pushed

All on `claude/ngg-afrovanguard-audit-sync-becs2k`; suite now 182/182 +
browser E2E. UX blueprint (locked v2): https://claude.ai/code/artifact/6744d694-868b-4f14-b547-7c2edcc52aaf

| Piece | Where | Verified |
|---|---|---|
| Identity gateway (one door, staff invisible) + device sessions/revoke + lockout + Argon2id + headers | auth.gateway, api/member/security.php, session.php, migration 10 | tests + live HTTP + smoke |
| AI key profiles (coach/behaviour/analysis/moderation, Gemini+Groq, fallback chains) | api/_lib/ai.php | tests (offline resolution) |
| Integration bus (outbox→GChat/signed webhooks/Apps Script Sheets; retries+log) + SMS (Termii→Twilio) | api/_lib/bus.php, sms.php, admin/integrations.php, tools/dispatch-events.php, docs/integrations.md, migration 11 | tests (mocked HTTP) |
| Registration stack (versioned form builder, gate links, parent verify + consents ledger, QR bind on verify, reminders) | api/_lib/domain/registration.php, admin/registration.php, public/gate.php, public/verify.php, tools/registration-reminders.php, migration 12 | tests + browser E2E |
| Inbound attendance webhook + published spec | api/hooks/attendance.php, docs/attendance-webhook.md | tests |
| Behaviour monitor (AI classify + rules fallback, wellbeing flags → bus) | api/_lib/domain/behaviour.php, migration 13 | tests |
| Gate desk UI (/gate?g=…) + parent page (/verify?t=…) | page-other.jsx (GatePage, ParentVerifyPage), app.jsx routes | Playwright E2E 12/12 |
| Audit (M&E) role — read-only by construction | util.php admin_role_caps, audit tail | tests green |

## 3c. REMAINING UI (backends all exist — wire, don't invent)

1. **Member dashboard shell** (dark blue #16223E + gold #FFCE54 + Tilt from
   motion.jsx): tabs Home · Growth · Me; journal = launcher REDIRECT (never a
   tab); W10 first-run (member.security has hasPassword/sessions; setPassword
   exists); Me → Security = NGGApi.member.security/securityRevoke*.
2. **Admin Today screen** (mobile-first W7): stat tiles from
   admin.registrations.list (pendingCount), admin.behaviour.list (openFlags),
   admin.summer.attendance; "Needs you" rows; gate-link manager
   (admin.registration.gates/gateCreate/gateRevoke); integrations screen
   (admin.integrations.*, test-fire + log); behaviour screen (admin.behaviour.*).
3. **Form builder UI** (W5) — admin.registration.form/publishForm; builder is
   super_admin-gated server-side already.
4. **Audit/M&E workspace** (W8 §6) — audit tail op is open to the 'audit'
   role; KPIs derivable from admin.summer.attendance + behaviour trend;
   indicators/report-builder = new (small) ops.
5. **ID card render + QR scan-by-camera** — waiting on the user's ID design;
   QR decode needs a vendored lib (jsQR); tokens already bind at verification
   and check in via typed code today.
6. **Legal pages** (NDPR set, §blueprint) — static content page + footer links.
7. Desktop journal rail shell (W9) + ⌘K in journal.

Gotchas for the wave: new JSX pages were appended to page-other.jsx (no new
ORDER entry needed); /gate + /verify are chrome-less routes in app.jsx; the
verify POST is CSRF-exempt (token IS the proof); test with throwaway config —
NEVER commit api/config.php; camera Permissions-Policy is camera=(self) in
.htaccess now.

---

## 4. Advice / gotchas for the next agent

- **NGG build is in-browser Babel; prod build needs esbuild.** `node build.js`
  requires `npm install esbuild --no-save` first (not vendored). It transpiles
  BOTH targets (main SPA + journal PWA), validates the `ORDER` array against
  `index.html`, and fails on drift. ALWAYS run it after editing `.jsx` — it's the
  only compile check. It does NOT catch runtime React errors — for those run
  `tests/journal-smoke.js` (Playwright; setup in its header).
- **Never commit** `nextgengen/api/config.php`, `afrovanguard-site/config.php`,
  `dist/`, or `node_modules/` (all git-ignored). Tests used throwaway SQLite DBs
  under the scratchpad + temporary config files that were cleaned up.
- **Journal entry body is line-based.** The Reflect editor joins labelled lines
  (`School: …`, `Home: …`, `Peer: …`, `Did well by …`, etc.); `parseEntry()`
  (top of `journal/app.jsx`) is the inverse. Keep each field on ONE line (no
  internal `\n`) or the parser breaks. The v2 structured logs fold into these
  single lines on purpose.
- **`member_state` persistence:** `setState(key, value)` in the journal is
  optimistic + fire-and-forget to `NGGApi.member.stateSet`. To have a key survive
  a reload, add it to the `stateGet([...])` boot fetch (~line 242 of
  `journal/app.jsx`).
- **Afrovanguard org-email → Google** only fires when Google is actually
  configured (`av_require_google_for_org`, `login/auth.js` `isOrgEmail`). So the
  seeded password super admin works out of the box; when Google IS configured,
  use Google instead. Don't "fix" this — it's intended.
- **Both super admins are idempotent + fingerprint-guarded** — safe to re-run.
  Changing the configured password re-applies automatically (fingerprint keyed
  on email|password). A blank password generates one, stored once for a single
  reveal (CLI `--reveal` or Studio button), then cleared.
- **Afrovanguard is a mature, well-built PHP app** ("the Studio"): superadmin >
  admin > editor (`admin_users`), member RBAC via `LmsAuth`
  (learner<member<mentor<coordinator<admin), OTP/password/Google sign-in,
  mentorship engine, webhooks, inbound integrations API + App Tokens. Reuse this
  infra rather than building parallel systems (the sync did — extend the same
  pattern for any new cross-site flow: scoped App Token in, webhook/cron out).
- **Verification commands** (throwaway DB; clean up after):
  - AV: `AV_DB_PATH=/tmp/…/x.sqlite php db/seed_admin.php`
  - NGG: create a temp `api/config.php` (sqlite_path → scratch), then
    `php api/tools/seed-admin.php`; delete config.php after.
  - Sync e2e: serve AV via `php -S 127.0.0.1:8199 -t afrovanguard-site` with
    `AV_DB_PATH` set + a seeded approved mentor + `mentors:read` token, point
    NGG's `afrovanguard.api_token` at it, run `sync-afrovanguard.php --dry-run`.
- **Commit trailers**: a `Claude-Session:` line only. Do NOT add a
  `Co-Authored-By` line, a model identifier, or any external email to
  commits or PRs.
- **Don't touch mam-academy** unless asked.

---

## 5. Key file map (quick jump)

**Afrovanguard (vigilant):** `lib/SuperAdmin.php` · `db/seed_admin.php` ·
`admin/api.php` (superadmin_reveal, admins_list) · `admin/app.js`
(renderSuperCard) · `admin/index.php` (#adSuperCard) · `integrations/api.php`
(mentors.directory) · `lib/AppTokens.php` (mentors:read) · `.env.example`.

**NGG (audit-sync):** `api/_lib/superadmin.php` · `api/tools/seed-admin.php` ·
`api/tools/sync-afrovanguard.php` · `api/_lib/domain/admin.php` (lockout guards)
· `api/auth/{login,request,verify}.php` · `api/config.example.php` ·
`journal/app.jsx` + `journal/app.css` · `docs/NGG-AUDIT-INDEX.md` ·
`docs/afrovanguard-sync.md` · `docs/anchor-journal-v2-gap-analysis.md`.

## NGV ID card, its standing, and the two-way scan (2026-10)

- **Two-way scan, as NGG's cards have.** A secure card's QR is now `SITE_URL/q/AVQR-…` (`MemberCards::scanUrl`), not a bare code. A phone camera opens `q.php` (routed in `.htaccess` and `router.php`); the CACENTRE gate reads the `AVQR-` code out of the same URL (its `AV_CARD` pattern matches anywhere, before its `/q/` rule, so the path never makes it a cacentre card; `gate/test/units.test.ts` pins that). `MemberCards::tokenFrom()` extracts the code; `lookup()` accepts a whole URL. The `AVQR-` prefix must stay in the path.
- **One standing**, `NgvCard::standing($u)`, most severe first: suspended account → withdrawn → not a member (learners are not members) → completed → applicant → paused → gate blocked (`GateAttendance::whyNot`) → probation → dues owing (NGV ledger payable now) → membership lapsed (non-NGV members) → active. Each has a word, a tone, a detail sentence, and a `public` word that never names money or discipline.
- **One card**, `NgvCard::html()`: ID-1 portrait, name, track, NGV number, the QR, and a status band (white on a dark fill, ≥ 6:1) that says the date it was true — a printed status goes stale; the QR gives the live one.
- **`/q/AVQR-…`**: a stranger sees the first name and the public standing; the holder sees their standing and the gate's verdict; NGV staff (admin/superadmin, or coordinator and above) see the full record and can print the card (only the card prints). A replaced card says so (410); an unknown code says only that (404), and only failures are rate-limited.
- Portal → Attendance & pass shows the card with the live standing. The Studio member drawer links each secure card to its page.
- Tests: `tests/ngvcard.test.php` (27 checks). Note the default probation rule: an empty `gate.probation_levels` means level O, so a member never promoted past O shows "On probation" — that is the policy, not a card bug.

## NGV fines on the page, leave with a duration, and the book list (2026-10)

- **Fines.** The public page `/academy/ngv/` has a **Fines** section: the
  reasons and usual amounts read from the fines desk's own catalogue
  (`NgvFines::catalogue()`), and how a fine is seen, paid and questioned
  (`fines_enabled` in `lib/Ngv.php`). Each vanguard's portal has a **Fines** view
  (`#ngv-fines`, card `fines` in `_dashboard-body.php`) from
  `NgvFines::forMember()` — the desk's own standing, narrowed to them. The fine
  notice now links there. Recording stays on the admin-only fines desk
  (`academy/ngv/fines.php`), linked from the page's admin bar.
- **Leave has a duration.** `GateAttendance::requestLeave($member, $from, $to, $why)`:
  up to `LEAVE_MAX_DAYS` (31), every day checked before any is written. Still
  one `gate_excuses` row per day (the sweep and the unique index are unchanged),
  grouped by a new `leave_key` column and shown and decided as one leave
  (`asLeaves()`, `leaveLabel()` — "Mon 6 Oct – Fri 10 Oct · 5 days"). Forms:
  the portal's Attendance view and `/gate-pass` ask for a first and last day;
  the staff desk shows the days and decides the whole leave.
- **Books are prepared by admins.** `/academy/ngv/books.php` keeps `ngv_books`
  (title, author, chapters). Vanguards choose from it and write a summary of
  each chapter plus the whole-book reflection — see `docs/ngv-reading.md`.
- Tests: `tests/gateattendance.test.php` (Leave:), `tests/ngvreading.test.php`
  (book list:), `tests/ngvfines.test.php` (a vanguard's own fines).

## 2026-10-22 — Centres follow CACENTRE's locations; absences by each location's calendar

- **CACENTRE decides the places.** Its locations list is the only one. The
  CACENTRE gate sends it to `integrations/cacentre-gate.php` as
  `{op:"locations", version, tz, locations:[{id, name, status, aliases, calendar}]}`,
  signed with the resolve key the gate already uses, and retries every five
  minutes until this answers `{ok:true}`.
- `Centres::syncFromCacentre()`: checked whole, one transaction, one audit line
  (`centre.cacentre`). A location is linked to a centre by its id (`av_centres.cac_id`,
  added by `Centres::ensure()`), else by its name or a former name. New → a centre
  with a code from its name. Renamed → the same centre renamed, with its members
  (`member_profiles.centre`). Removed → retired. The calendar goes in `cac_calendar`.
  The list's version and time are kept in meta `av_cac_locations`.
- Once the list has arrived: `Centres::save()` refuses a new centre and a linked
  centre's rename; `setStatus()` and `delete()` refuse a linked centre
  (`managed_by_cacentre`, with where to do it). The code is still the portal's.
  `adopt()` no longer adds a centre typed on a member. Studio → Members →
  Centres shows the note, a CACENTRE badge, and read-only names.
- **Absences by the member's location.** `GateAttendance::isProgrammeDayFor($member, $day)`:
  the portal's holidays never count; otherwise the member's centre's calendar
  decides (`meetsOn()`: its weekdays, inside the terms it runs, not on its
  holidays); a member at no centre, or a centre with no calendar, keeps
  `gate.programme_days`. Used by `sweep()` (absences and absence fines),
  `award()`, runs and perfect weeks, and the day register's "not in yet" list.
  Members' centres are read in one query per request (`centreOf`).
- Tests: `tests/cacentrelocations.test.php`; CACENTRE's
  `tests/integration/gate-e2e.php` renames a location in CACENTRE and sees it
  arrive here through the real gate.

## 2026-10-22 — Fines for any member, not only NGV participants

- `NgvFines` fines any active portal member (`MemberRoster::memberWhere`), NGV
  or not. The roll (`population()`) is the NGV participants plus every active
  member, each marked `ngv`; the desk's picker and the spreadsheet import match
  them the same way (NGV ID, card, email, phone, name).
- Same ledger: a member outside NGV gets `ngv_charges` rows of kind `fine`
  under their portal id (one id space). `issue()` posts directly for them
  (`NgvLedger::charge()` stays participant-only for fees). `NgvLedger::postCredit`
  accepts the `fine` line for any member, so a payment, waiver or void works;
  `receiptFor()` takes name and email from `lms_users` when there is no
  participant row. Fees, Paystack and reminders stay NGV-only.
- The fines desk (`/academy/ngv/fines.php`) has **Record payment** on an open
  fine (`NgvFines::payOne`: cash, transfer, POS or other, with a reference).
  Like every payment it comes off the member's oldest fine first.
- The portal: a member outside NGV who has had a fine gets **Fines** under You
  (`portal/views/fines.php`); the email and notification say "Afrovanguard
  account" and link there. A vanguard's fines are unchanged, under NextGen
  Vanguard.
- Tests: `tests/finesanymember.test.php`.

## 2026-10-22 — The fines import, smarter

- **Reads what people have** (`NgvFines::parseAny`): an `.xlsx` workbook (first
  sheet, read with ZipArchive + SimpleXML; Excel day numbers become dates), a
  CSV with headings (as before), a CSV or tab sheet with NO headings (each
  column typed by what most of its cells are: `kindOf()` — email, phone, NGV
  ID, date, amount, reason, name, note), and a typed list, one fine a line
  ("Tunde Bakare – late – 12 Oct – 2k"). Amounts read "2k", "₦1,500", "N2000".
  A day with no year is the last one that has passed. The desk says how it read
  the sheet (`read_as`).
- **Remembers who people meant**: when a person picks who a row is for and the
  import is applied, the row's name, email and phone are remembered for that
  member (meta `ngv_fine_aliases`, at most 3000). The next sheet that says the
  same is matched "by what you chose before" — unless that name now belongs
  exactly to someone else, when it asks again.
- **No double fines**: a row for a member already fined for the same reason on
  the same day — by the CACENTRE gate (`late:`/`absent:`/`nocard:` periods), at
  the desk, or by an earlier import — is "already fined", and says how.
- **Repeat offences are pointed out**: "3rd late arrival fine in 30 days".
  Nothing is escalated automatically; the amount is still the catalogue's.
- Tests: `tests/finessmart.test.php`.


## Members owe dues; dues are calculated, not just recorded (Oct 2026)

Before this, dues were only ever *paid*: nothing said a member owed them, so
a member who never paid read "never paid" and owed nothing, and a member who
lapsed in March and renewed in October got a year from October (the seven
months between were forgiven). `lib/Membership.php` now owns the obligation:

- **Who owes**: an Afrovanguard member (not a learner), active, at a level
  from `from_level` up, not a NextGen Vanguard (membership is on their NGV
  account), not lifetime, not ended by the office, not the community bot.
  `Membership::obligation()` gives the reason when somebody does not owe.
- **How much**: from the later of the start date, the day they joined (or the
  moment the account was made) and the day paid dues ran out, a month falls
  due on that day and every month after. Arrears = months × the monthly
  price, with whole years at the yearly price (`Membership::price`).
  Computed when read, with no cron and no charge table.
- **Paying clears the oldest month first**: `Membership::nextBase()` is where
  new months begin, read by the office's grant and by Paystack's
  `LmsRepository::grantMembership`.
- **The rules are the office's**: Studio → Members → **Dues** (prices, start
  date, from level, on/off; changing them is Super Admin only), meta
  `av_dues_settings`. The start date is fixed the first time it is read (the
  first of that month) so it does not slide. Every price reader (portal,
  offline receipt check, how-it-works, the assistant) reads
  `Membership::monthly()/annual()/price()`. A Paystack monthly plan's amount
  is set in Paystack, and the screen says so.
- **Member management**: dashboard tiles "Behind on dues" (total owed) and
  "Owe dues"; roster filters for both; an "Owes ₦…" badge per row (one query
  per page); the drawer says how far behind and why; the Dues tab lists the
  largest debts first; the CSV export has months and naira owed.
- **Portal**: Membership shows what is owed and since when, a "Pay what's
  owed" button (period `arrears`), and the offline form offers that amount.
- **Bug fixed**: `portal/dues.php` read `$body` without ever setting it, so
  "Pay a month" started a checkout for a year.
- **NGV ledger**: a membership year runs from the day somebody began, not
  the calendar year (a 5 December start was charged again on 1 January). The
  keys are still `YYYY`, so nothing already posted is charged twice. The year
  that straddles a rollout guard is still owed. The balance ceiling is tested
  before every charge, not once per run.
- **Finance requests are on by default**: CFIS's address
  (`https://cacentre.afrovanguard.org.ng/finance`) and Afrovanguard's property
  code (`avg`) are defaults, so the private key alone switches finance
  requests and the CFIS checkout on. It can be pasted into Studio → Setup →
  CACENTRE finance (CFIS), or set as `CFIS_SECRET`. An explicitly empty
  setting still switches it off.
- Tests: `tests/dues.test.php`, `tests/cfisdefault.test.php`.

## Setup → Test Google Workspace always answers (Oct 2026)

The Test buttons on Studio → Rules & AI → Setup were drawn **disabled** when a
provider was not ready, with the reason only in a hover tooltip. A Workspace
key set in config.php that the site could not use (cut short, no
private_key, defined twice, on the empty template line) therefore made
"Test Google Workspace" do nothing. Every test button can now be pressed. One
that is not ready shows the server's reason beside it before it is pressed
(`AvSettings::testable()['why']`, from `GoogleWorkspace::whyNotConfigured()`).
That reason says when the bad key is in config.php rather than the Studio, and
what is wrong with it. A refusal before the test (not a Super Admin, rate
limited) now prints its error instead of a bare ✗. Test:
`tests/setuptests.test.php`.

## Meetings: everybody sees them; CACENTRE staff schedule them (Oct 2026)

Every member and staff member now sees every meeting, and the organiser can
keep a meeting's minutes to the people in it. CACENTRE's Staff Office schedules
and lists meetings through `integrations/cacentre-meetings.php`, which is
signed with the existing `AV_SSO_SECRET`. Meetings has its own portal menu
entry. Details: docs/meetings.md, "Everybody sees every meeting". The nav test
now recognises router aliases (`portal/avp.js` `ALIAS`).

## CACENTRE's email goes out through this site's mail (Oct 2026)

CACENTRE's own outbound mail was not being delivered. Its mailer
(`cac/includes/mail-transport.php`) now hands each message here first, through
`integrations/cacentre-send.php` (`lib/CacSend.php`). The request is signed with
the bridge secret both sites already hold (`AV_SSO_SECRET`), for the
`mail-send` door, bound to the message, with a nonce spent once. One check
covers every door CACENTRE calls: `lib/CacInbound.php` (meetings now uses it
too). This site sends it at once with its own roads (`Mailer::send`), with the
sender name "CACENTRE", replies to CACENTRE, and this site's own From address
(the one its mail may send as). Only List-Unsubscribe headers are carried.
There is a daily ceiling: `AV_CAC_SEND_DAILY`, default 500. Past it the answer
is `daily_cap` and CACENTRE falls through to its own routes. Tests:
`tests/cacsend.test.php` here, and `cac/tests/avmail.test.php` in CACENTRE.

## Finance requests on by default; volunteer meetings editable (Oct 2026)

- **Finance requests are always open.** With CFIS not connected, or not
  answering, a member's request is kept under its own reference (status
  `unsent`, with who asked and the bank details). The office is emailed
  (`AV_FINANCE_EMAIL`, else `ADMIN_EMAIL`), and the cron sends it by itself
  once finance can take it (`CfisRequests::sendPending`). The form offers
  CFIS's own default spending kinds while finance cannot be asked
  (`CfisRequests::DEFAULT_CATEGORIES`, mirroring CFIS `config/cfis.php`);
  CFIS still decides on arrival. A refusal from finance is still shown as
  such. Tests: `tests/cfisrequests.test.php`, rewritten for this rule.
- **Volunteer dashboard → Coming up:** the edit button always reads "Edit".
  It said "⚠ Add Meet link" on any meeting without a link, which was every
  meeting on a site without Google Workspace, so nobody saw it could edit.
  `portal/volunteer.js` and `portal/views/volunteer.php` now write no inline
  styles: bar and meter sizes are 5% step classes (`.vh-w*`, `.vh-h*`), and
  the toast is `.vh-toast`. Test: `tests/volunteerui.test.php`.

## The CFIS key is collected, not carried (Oct 2026)

Setup used to say: "CACENTRE's finance team mints it with
`php artisan cfis:connect avg` and hands it over privately. Minting a new one
stops the old one working." Behind that, a 64-character signing key was
printed to a terminal at CACENTRE, lived in scrollback, travelled by whatever
the two people found convenient, and was pasted into a form here. The
instruction to keep it private was the entire control. And because the only
verb was `cfis:connect`, a lost hand-over and a compromised key had the same
remedy — which mints a NEW key, so the commoner problem was fixed by breaking
this site until somebody carried the new key across.

- **Setup → CACENTRE finance → Pair with CFIS.** Finance runs
  `php artisan cfis:pair avg` and gives out a pairing code (sixteen
  characters, fifteen minutes, one use, stored by CFIS only as a hash). Enter
  it and this site fetches its key from CFIS over TLS. The key is never
  displayed on either side.
- **Why a second credential at all.** This site cannot simply ask for its own
  key, because the only thing that proves it is Afrovanguard is that key. The
  code breaks the loop with something whose leaking matters far less, and
  whose use CFIS records (`income_source.paired`, with when and from where).
- **A re-pair does not change the key**, so a hand-over that goes astray no
  longer means a rotation. Only `cfis:connect` changes a key.
- **The fingerprint is the check.** Both sides show the same 16 characters
  (`CfisPairing::fingerprint()`, mirroring CFIS's `IncomeSource::fingerprintFor()`).
  This site also verifies the key against the fingerprint CFIS sends with it,
  so a truncated answer is refused here rather than failing every settlement
  days later with nothing pointing back.
- `CFIS_PAIRING_CODE` is declared as type **`action`** — a button, not a
  setting. `AvSettings::save()` refuses to store an action, and the Studio
  field carries no `data-skey`, so a live credential cannot end up in
  `av_settings` by either route.
- `CFIS_SECRET` stays in Setup for a host that cannot reach CFIS outbound;
  there finance uses `cfis:connect avg --show-secret` as before.
- Super Admin only, rate-limited (10 per 10 minutes), audited by fingerprint
  and never by key. Tests: `tests/cfispairing.test.php` here, and
  `tests/Feature/Integrations/PropertyPairingTest.php` in CACENTRE.

## Nobody settles their own account, and no fine is cleared by a typed figure (Oct 2026)

Two findings from the member-management audit, both about money and both the
same shape: a control that moved money with nobody on the other side of it.

- **Fines (H9).** `academy/ngv/fines.php` recorded a payment from three
  `prompt()`s — how much, how, a reference — and `NgvFines::payOne()` wrote it
  straight to the ledger. The member desk two pages away had already stopped
  doing that (`evidence_required`), so a fine was the one charge on the site
  that could be cleared by typing its amount, and nothing stopped the fined
  person typing it. `payOne()` now goes through `lib/OfflinePayments` —
  purpose `ngv`, line `fine`, which ends in the same ledger call — so the
  receipt is read and checked before a naira moves, and the desk has a form
  with a file on it instead of a chain of prompts. The old JSON `pay` action
  stays as a route and answers `evidence_required`, so an old tab says what to
  do rather than failing as "Unknown action".
- **The self-check.** `NgvLedger::selfDealing()` refuses a waiver, a write-off
  or a void by the member the row is about; `payOne()` refuses a payment the
  same way, and `OfflinePayments::submit()` refuses a *staff-recorded* payment
  into the recorder's own account — their own still goes in from the portal,
  where it is checked identically. A `byUid` of 0 is the system and is exempt.
  The appeals sponsorship sweep is deliberately outside the rule: it credits
  everyone on a list who owes, and dropping the operator out of that list
  would quietly under-allocate the appeal; who ran it is on
  `av_appeal_allocations.by_uid`.
- **The offline override (M8).** A Super Admin could approve a payment they
  had submitted themselves, and the "this receipt already credited money"
  guard read back a stored verdict — which, when the reader was unavailable,
  had never been computed, so it said `ok`. The one case the override exists
  for was the one case the guard was missing. It now refuses an approver who
  submitted the payment or is the payer, and recomputes the reuse and
  same-day checks against the record as it stands.
- **NGV offline payments widened.** A fine can be issued to any Afrovanguard
  member, NGV or not, and sits on the NGV ledger — so an offline payment for
  `purpose: ngv` is now accepted from a participant or from anybody carrying a
  charge there, not participants alone.

Tests: `tests/finesanymember.test.php` (the desk, the self-check, the receipt),
`tests/offlinepayments.test.php` (staff self-submission, the two recomputed
reuse cases), `tests/ngvfines.test.php` (the desk's wiring).

## One front door for the gateway's webhook (Oct 2026)

A payment gateway posts to one address. This site had two money routes, each
understanding only its own kind, and which one was live depended on a setting
in the Paystack dashboard that nothing in the code could see.

- `/academy/pay.php` treated any charge carrying a plan as a dues renewal, so
  a recurring **donation** would have bought somebody a month of membership,
  and donations were never recorded at all.
- `/process-donation.php` filed everything that was not an NGV fee as a
  donation, so a dues or course charge landed on a campaign total, could
  appear on the public donor wall, and left the learner unenrolled.

Both now hand the event to `lib/PaymentWebhook::handle()`. The kind decides
the owner, in a fixed order: a fee (`metadata.ngv_member`), then a charge we
started (a `payments` row for the reference — which covers the FIRST charge of
a dues subscription), then the dues plan (`AV_DUES_PLAN_CODE`, a positive
signal rather than "has a plan"), then a donation as the fallback. Money whose
owner cannot be named is recorded as given, never granted as bought.
`classify()` is pure and its reason is logged, so "why did this charge go
there" is answerable without a reproduction.

A dues **renewal** now writes a `payments` row keyed on the gateway's
reference and goes through the same `finalizePayment` as every other dues
charge: `reference` is UNIQUE so a redelivery cannot add a second month, the
amount is checked against today's price (short is refused, left pending, and
written to the trail as `dues_renewal_short`), and the money is in the dues
totals and the member's own history for the first time.
`LmsRepository::grantMembershipByEmail()` is removed rather than left unused.

Donation de-duplication moved out of `donations.json` into `donation_refs`:
the file keeps only the newest 500, so a webhook redelivered for an older
reference was counted again. The donation store itself moved to
`lib/Donations.php` — a handler inside a 999-line top-level script cannot be
called by the other address.

Two things found on the way and fixed with it: `Payments::paystackWebhookValid`
required the PUBLIC key to check a signature made with the SECRET one, so a
deployment holding only its secret answered 401 to every webhook at
`/academy/pay.php` while `/process-donation.php` accepted the same call; and
`Payments::paystackVerify` read `PAYSTACK_SECRET_KEY` unguarded, which is a
fatal on an unconfigured site rather than "not paid".

**For the operator:** one URL in the Paystack dashboard, either address. Set
`AV_DUES_PLAN_CODE` if monthly dues auto-renew; without it a plan charge is a
donation, which is the safe direction. `docs/webhooks.md` has the table.

## The donor wall is opt-in, and lists donations (Oct 2026)

It was opt-out — a box saying "keep my name off the donor wall" — so a donor
who read nothing was published on a public page. Worse, a GATEWAY charge with
no donate form behind it (dues, a renewal, a course fee) carried no form
fields at all, fell through to the payer's own name and amount, and went up as
a donation they had never made.

The consent now travels as a field of its own (`wall`), set only by the donate
form, and the entry records `listed`. `get_donors` shows an entry only when
`listed` is true — absence is not consent, **including for the entries already
in the file**, which nobody was ever asked about. Expect the wall to be empty
after this deploy and to fill as people tick the box; the page already has an
honest empty state ("No gifts on the wall yet. Yours can be the first.").

The old `anonymous` field is still written and still read on the way in, so a
checkout started before the deploy finishes as the donor meant it — but it is
never a reason to LIST anybody, because its absence used to mean "publish me".

Figures are not names. The campaign totals, the thermometer and the donor
count are unchanged, and `get_donors` now returns `gifts` (every gift)
alongside `total` (how many are on the wall) so the page's "N gifts so far"
does not shrink because people stopped consenting to be named.

Tests: `tests/donorwall.test.php`, including the endpoint over HTTP.
