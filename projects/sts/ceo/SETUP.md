# Street-To-Stardom · 2026 · Empowered 360° — Deployment

Hybrid stack: **PHP backend** (your shared hosting) + **Google Sheets** (database, via Apps Script) + **Gemini AI** (in-form intelligence) + **PHPMailer** (transactional email).

```
.
├── index.html                 ← single-file React frontend (Babel via CDN)
├── assets/                    ← logo + photographs
│   ├── sts-logo.svg           · brand mark (favicon + nav + footer + embedded into deliverables)
│   ├── bg.png                 · cinematic hero photo (also layered on user-uploaded photos)
│   ├── og-image.png           · social share image + confirmation trophy
│   └── i-will-be-there.png    · fallback photo for the "I Will Be There" card
├── api/                       ← PHP backend
│   ├── ai.php                 · Gemini proxy
│   ├── submit.php             · form submission (local + Sheets + 2 emails)
│   ├── subscribe.php          · newsletter (local + Sheets)
│   ├── donate.php             · legacy donation-intent tracker (kept for redirect compatibility)
│   ├── pledge.php             · commitment pledge (no payment; logs + emails team + speaker)
│   ├── reservations.php       · cached Sheets read
│   ├── _helpers.php           · CORS, rate limit, Apps Script bridge, email template
│   ├── config.php             · ALL secrets live here
│   ├── .htaccess              · denies helpers/config/dotfiles/data
│   └── data/                  · auto-created · submissions.json, subscribers.json, donations.json, pledges.json
└── apps-script/
    ├── Code.gs                · Sheets DB layer (4 tabs)
    └── appsscript.json        · manifest reference
```

### Flow

```
Browser  ──▶  PHP (api/*.php)
                │
                ├──▶ Gemini API           (AI calls — centre advisor, brief, share copy)
                ├──▶ Apps Script ──▶ Sheets
                ├──▶ Local JSON           (source of truth backup)
                └──▶ Admin/Speaker email  (PHPMailer SMTP, mail() fallback)
```

---

## 1 · Create the Google Sheet

1. Create a new Google Sheet, copy the Sheet ID from the URL (between `/d/` and `/edit`).
2. Four tabs auto-create on first request: **Submissions · Subscribers · Reservations · Donations**.
3. Only **Reservations** needs manual editing — that's where you mark dates as already-taken so they appear locked in the form:

| DateId | Name | Title | Initial |
| --- | --- | --- | --- |
| w1d2 | (Leader full name) | (Their role · their org) | XX |

`DateId` values: `w1d1`, `w1d2` … `w5d2`.

---

## 2 · Gemini API key

Free key at **https://aistudio.google.com/apikey** (15 RPM, 1,500 RPD).

---

## 3 · Deploy Apps Script

1. Sheet → **Extensions → Apps Script**.
2. Paste `apps-script/Code.gs` (replacing the default).
3. **Project Settings → Script Properties** — add three keys:
   - `SHEET_ID`    = Sheet ID from §1
   - `SHARED_KEY`  = any long random string (mirror it in PHP `api/config.php` as `APPS_SCRIPT_KEY`)
   - `ADMIN_EMAIL` = optional, bcc'd on every new submission
4. **Run** the `selfTest` function once — Apps Script will prompt for Sheets + Mail scopes; accept them. The function returns `{ok: true, issues: []}` when correctly set up.
5. **Deploy → New deployment → Web app** · Execute as **Me** · Access **Anyone**.
6. Copy the `/exec` URL — paste into PHP `api/config.php` as `APPS_SCRIPT_URL`.

### What lives in each tab

- **Submissions** — every form submission. Columns: Timestamp, ID, Honorific, Full Name, Role, Organisation, Email, WhatsApp, Centre ID, Centre Name, Centre Focus, Date ID, Date, Theme, Series Theme, Notes, Has Photo, Donated, Donation Total (₦), Donation Reference, Reminders Sent, **Confirmed**.

  The **Confirmed** column is a dropdown (`pending` / `confirmed` / `declined` / `cancelled`) that drives downstream logic:
  - Defaults to `pending` on every new submission.
  - The team flips it to `confirmed` once they've verified the speaker by phone or email.
  - **Only `confirmed` rows fire reminder emails.** Pending/declined/cancelled rows are skipped by the weekly cron.
  - **Confirmed rows are automatically merged into the public reservations feed**, so the hero peer list updates without anyone touching the Reservations tab. (The editorial Reservations tab still wins for any DateId clash, in case you want a custom display name.)
  - Conditional formatting: confirmed = green row, declined/cancelled = grey strikethrough row, pending = yellow pill in the cell.
- **Subscribers** — newsletter signups (de-duped on email).
- **Reservations** — your editorial list of dates already locked.
- **Donations** — commitment pledges (and any verified donations from the legacy donate.php flow). The handler back-links to the Submissions row so the team sees `donated: yes · ₦300,000` inline against the speaker.
- **Milestones** — the programme phases shown on the hero countdown band. Columns: Order, Key, Label, Date (ISO 8601, e.g. `2026-08-01` or `2026-08-01T09:00`). The tab is auto-seeded with the default seven phases on first use; edit the dates directly in the sheet. The site fetches via `/api/milestones.php` (cached briefly), with the bundled defaults as a fallback if the sheet is unreachable.

### Updating later

Apps Script web apps are versioned — saving doesn't update the live URL. To publish a change: **Deploy → Manage deployments → pencil → Version: New version → Deploy.** URL stays the same.

### Speaker reminders (weekly: T-28, T-21, T-14, T-7)

Apps Script sends each confirmed speaker a short reminder email once a week in the four weeks leading up to their session. Plain English, short paragraphs, and direct about what they committed to: their centre, their theme, and (for sponsors) the Summer Packs they also pledged. No CTAs, no asks.

**Install:** from the Apps Script editor, run `setupReminderTrigger()` once. That installs a weekly time-based trigger every **Wednesday 07:00 Africa/Lagos** that calls `sendWeeklyReminders()`. Each row's "Reminders Sent" column tracks which stages have fired so nothing double-sends.

**Test without sending:** run `previewReminders()` — logs to Stackdriver what *would* be sent. To trigger a real send on demand: `sendWeeklyReminders()` from the editor, or hit the web-app URL with `?action=send_reminders&key=YOUR_SHARED_KEY`.

**Remove:** `removeReminderTrigger()`.

---

## 4 · Commitments — how the gated deliverables work

There is **no payment integration**. The form's confirmation screen shows four downloadable deliverables:

| Deliverable | Free / Gated |
| --- | --- |
| "I Will Be There" Card (PNG) | Free — always available |
| Add to Calendar (`.ics`) | Free — always available |
| Digital Impact Badge (PNG) | Gated behind commitment |
| Partnership Letter (PDF, 3 pages — provisional) | Gated behind commitment |
| AI Share Copy | Gated behind commitment |

A speaker unlocks the gated deliverables by clicking a single button on the support panel: **"I'll equip N Lagos children →"**. That fires a POST to `api/pledge.php`, which:

1. Logs the pledge to `api/data/pledges.json` (local backup)
2. Posts a `log_donation` action to the Apps Script web app, which writes a row to the **Donations** tab with `verified: false, channel: "commitment"`
3. Emails the team (`NOTIFY_EMAIL`) with a "Reach out within 48 hours" callout
4. Emails the speaker their own dignified receipt — *"Thank you. Noted with care."* — explicitly stating no payment was taken and offering them an out

The team then reaches the speaker off-platform within 48 hours to arrange the actual contribution by whatever method the speaker prefers (bank transfer, card, cheque). When that payment lands, the team marks the row in the Donations tab manually, or you can write a small script to do it.

This intentionally avoids any in-page payment processor — the donation conversation is human, not transactional. If you later want to add Paystack/Stripe, the hook point is `handleProceed` in `SupportPrompt` (in `index.html`).

---

## 5 · PHP configuration

Edit `api/config.php`:

```php
define('GEMINI_API_KEY',  'AI…');
define('APPS_SCRIPT_URL', 'https://script.google.com/macros/s/…/exec');
define('APPS_SCRIPT_KEY', 'your-long-random-string');   // matches SHARED_KEY in Apps Script
define('NOTIFY_EMAIL',    'cacentre@afrovanguard.org.ng');
define('NOTIFY_FROM',     'noreply@yourdomain.com');
define('NOTIFY_FROM_NAME','Street-To-Stardom');
```

### Email — PHPMailer with SMTP (recommended)

**PHPMailer is bundled** in the repo at `api/phpmailer/src/` (v6.9.3, LGPL-2.1). The backend autoloads it automatically — no Composer step needed. The whole `api/` folder uploaded to your host is all you need.

Turn SMTP on in `config.php`:
```php
define('USE_SMTP',   true);
define('SMTP_HOST',  'smtp.hostinger.com');  // or smtp.gmail.com, smtp.office365.com, etc.
define('SMTP_PORT',  587);                    // 587 TLS or 465 SSL
define('SMTP_USER',  'cacentre@afrovanguard.org.ng');  // full mailbox address
define('SMTP_PASS',  'your-mailbox-password');         // or app password
define('SMTP_SECURE','tls');
// Set to 2 or 3 temporarily to dump the SMTP conversation into error_log
define('SMTP_DEBUG', 0);
```

Critical: **`NOTIFY_FROM` must be the same address as `SMTP_USER`** (or an alias the host has explicitly authorised). If they don't match, most providers — including Hostinger, Google Workspace, Microsoft 365 — silently drop the message. Set `NOTIFY_EMAIL` to wherever you want replies to land; the template already wires it as Reply-To.

**Verify it works:** open `https://yourdomain.com/api/email-diag.php?to=you@example.com` in a browser. The endpoint reports the configuration, flags common problems (empty SMTP_USER, NOTIFY_FROM mismatch, missing openssl), and sends a live test through the exact same path the form uses. If the test succeeds and your inbox shows nothing, check spam — the next problem to fix is SPF/DKIM on `NOTIFY_FROM`'s domain.

Without SMTP the backend falls through to Resend (if you key it) or PHP's `mail()` — `mail()` works locally but most shared hosts route it to spam without SPF/DKIM, and many block it outright.

### Email design

Both speaker and admin emails use the same `sts_email_template()` helper in `_helpers.php`. The template:

- Renders identically in Gmail, Outlook (with VML fallback for buttons), Apple Mail, mobile
- Supports `prefers-color-scheme: dark` for clients that respect it (Apple Mail, native iOS)
- Uses Montserrat + Playfair Display via Google Fonts, with Georgia / Helvetica fallbacks
- STS gradient masthead (blue → crimson), light card body, calm footer with contact/privacy/copyright

Tweak the masthead, palette, or footer in `sts_email_template()` directly — all callers (`submit.php`, `pledge.php`) use the same shared template.

---

## 6 · Upload to your host

Drop the whole tree into `public_html/`. PHP 7.4+ with `curl` is required (every modern shared host has it). `api/data/` auto-creates on first request. `api/.htaccess` is included and denies access to `config.php`, `_helpers.php`, `data/`, and any dotfiles.

---

## 7 · Verify

1. Open the site. Hero shows live "N of 10 dates open" pulled from Sheets.
2. Try the **theme toggle** in the nav (system / light / dark).
3. Complete the 4-step form. **Optionally upload a portrait photo on Scene I** — it's auto-resized to 1400px and you can click **✨ Smart isolate** to remove the background browser-side (no upload to any external service).
4. On step 2, the AI Centre Advisor panel populates.
5. Submit. Three things should happen:
   - Row appears in **Submissions** sheet (Donated = no)
   - Backup in `api/data/submissions.json`
   - Speaker confirmation email + admin notification email to `cacentre@afrovanguard.org.ng`
6. On the confirmation screen, four deliverables appear:
   - **"I Will Be There" Card** (free) — vertical PNG, your photo as backdrop if uploaded, STS `bg.png` cinematic overlay layered on top, embedded logo
   - **Add to Calendar** (free) — universal `.ics` with VTIMEZONE for Lagos
   - **Digital Impact Badge** (gated) — square social-shareable PNG
   - **Partnership Letter** (gated) — A4 PDF, three pages (provisional letter → credential card + briefing → letter of confirmation). Marked provisional throughout; the binding partnership is issued by the team after they speak with the speaker.
7. Click **"See the options"** on the donation banner → choose a tier → click **"I'll equip N Lagos children →"**. Two emails fire (team + speaker); the gated deliverables unlock immediately; the Donations sheet gets a new row.

---

## Troubleshooting

| Symptom | Fix |
| --- | --- |
| Emails not arriving | If `USE_SMTP=false`, your host may block `mail()`. Set up SMTP via PHPMailer per §5. |
| `mail()` lands in spam | Configure SPF/DKIM for `NOTIFY_FROM` domain, or use SMTP. |
| AI panels never finish | Bad `GEMINI_API_KEY` or quota. Check `error_log` for `[STS AI]`. |
| Submit OK but no Sheet row | `APPS_SCRIPT_URL` or `APPS_SCRIPT_KEY` mismatch. Local JSON has the record. Run `selfTest` from the Apps Script editor. |
| Reservations empty | Tab must be named exactly `Reservations`; `DateId` must match codes (`w1d1`, `w2d2`, …). |
| Card/Badge looks pixelated | Asset images must be in `assets/`: `i-will-be-there.png`, `bg.png`. |
| Smart isolate fails | The CDN that hosts the WASM model (`cdn.jsdelivr.net`) is blocked on the speaker's network. The original photo still saves; the speaker just doesn't get bg removal. |
| Photo upload says "image is over 20 MB" | Use a smaller image; the resizer accepts up to 20 MB raw, ratios up to ~12 MP cameras. |
| Pledge doesn't unlock kit | Browser blocked the fetch. The unlock is optimistic (localStorage), so it should still happen client-side. Check the Network tab for the `pledge.php` request. |
| 429 errors | Adjust limits in `config.php` (`*_RATE_LIMIT`). |

---

## Privacy & security

- Speaker data: Google Sheet + local JSON only. IP captured in JSON for audit, never sent to Sheets.
- **Photos** stay in the speaker's browser (localStorage) and on the canvas-generated PNG card. They are never uploaded to your server. Smart isolate also runs entirely browser-side.
- Gemini receives only profile fields (name/role/org) + session details — never emails, phone numbers, or photos.
- **No payment processor** — there is no card data flowing through any system. All contributions are arranged off-platform by the team.
- `config.php` denied by `.htaccess`. Don't commit it to public repos.
- Rate limits per IP per hour: 3 form submits, 5 newsletter subs, 20 commitment pledges, 30 AI calls.
