<?php
/**
 * Afrovanguard Donation System — Configuration EXAMPLE
 *
 * Copy this file to config.php and fill in real values,
 * OR (preferred for shared hosting) set the env vars below
 * in your .htaccess (which is excluded from git):
 *
 *   SetEnv AV_SMTP_PASSWORD   your_gmail_app_password
 *   SetEnv AV_PAYSTACK_PK     pk_live_...
 *   SetEnv AV_PAYSTACK_SK     sk_live_...
 *   SetEnv AV_ADMIN_TOKEN     <output of: php -r "echo bin2hex(random_bytes(32));">
 *
 * NEVER commit config.php or .htaccess to git.
 */

/**
 * Read a secret from the environment.
 *
 * IMPORTANT: configuration loading must NEVER terminate the request. This used
 * to http_response_code(500) + exit when a value was missing, which meant a
 * single absent secret (e.g. the SMTP password) took down every page that
 * transitively loads this config — including the homepage, Diary and Academy,
 * none of which need payment/SMTP secrets just to render. We now log loudly and
 * return '' instead; endpoints that genuinely need a secret validate it at the
 * point of use via av_config_present() and fail only that one request cleanly.
 */
function _av_require_env(string $name): string {
    $v = getenv($name);
    if ($v === false || $v === '') {
        error_log("[AV] Missing environment variable: {$name} — dependent feature disabled.");
        return '';
    }
    return $v;
}

/** True when a config constant resolves to a usable (non-empty) value. */
function av_config_present(string $const): bool {
    return defined($const) && (string) constant($const) !== '';
}

/* ─── Email (SMTP + fallbacks) ─────────────────────────────────
 * Powers donation receipts, contact replies AND Academy emails
 * (welcome / enrolment / membership / certificate-ready) via the
 * shared lib/Mailer.php. Delivery order (first that works wins):
 *   PHPMailer/SMTP  →  Resend (HTTPS API)  →  built-in SMTP  →  mail()
 * PHPMailer is bundled under vendor/ (no Composer install needed). */
define('SMTP_HOST',     'smtp.gmail.com');
define('SMTP_PORT',      587);
define('SMTP_USERNAME', 'donations@afrovanguard.org.ng');
define('SMTP_PASSWORD', _av_require_env('AV_SMTP_PASSWORD'));   // 16-char Gmail App Password
define('FROM_EMAIL',    'donations@afrovanguard.org.ng');
define('FROM_NAME',     'Afrovanguard');
define('ADMIN_EMAIL',   'cacentre@afrovanguard.org.ng');
// Optional transport overrides (defaults shown):
//   SMTP_SECURE 'tls' = STARTTLS (587, Gmail) | 'ssl' = SMTPS (465) | '' = none
//   SMTP_VERIFY true   = verify TLS cert (set false only for self-signed relays)
// define('SMTP_SECURE', 'tls');
// define('SMTP_VERIFY', true);
// Reply-To override (defaults to FROM_EMAIL so replies reach a human):
// define('REPLY_TO', 'cacentre@afrovanguard.org.ng');
//
// TLS insecure fallback: some shared hosts ship a stale CA bundle, so an
// otherwise-correct STARTTLS to smtp.gmail.com fails cert verification — the
// #1 cause of "the exact same SMTP works elsewhere but not here". When true,
// a TLS/connect failure is retried once WITHOUT peer verification. Off by
// default (a downgrade could expose SMTP AUTH credentials); enable only if the
// Studio email test reports a certificate/TLS error you can't fix on the host.
// define('SMTP_ALLOW_INSECURE_FALLBACK', true);
//
// Resend HTTPS API (https://resend.com — free tier, one key). The most
// reliable path on locked-down shared hosts that block outbound SMTP ports
// (587/465): outbound HTTPS almost always still works. Verify the sending
// domain in Resend first. Set via .htaccess: SetEnv AV_RESEND_KEY re_...
// define('RESEND_KEY', _av_require_env('AV_RESEND_KEY'));

/* ─── Paystack ──────────────────────────────────────────────────
 * Primary payment provider. The same keys power donations AND the
 * Afrovanguard Academy (paid courses + membership). Set a webhook in
 * your Paystack dashboard pointing at:
 *   https://afrovanguard.org.ng/academy/pay.php   (charge.success) */
define('PAYSTACK_PUBLIC_KEY', _av_require_env('AV_PAYSTACK_PK'));
define('PAYSTACK_SECRET_KEY', _av_require_env('AV_PAYSTACK_SK'));

/* ─── Flutterwave (optional, secondary) ─────────────────────────
 * Leave blank to disable. */
define('FLW_PUBLIC_KEY', getenv('AV_FLW_PK') ?: '');
define('FLW_SECRET_KEY', getenv('AV_FLW_SK') ?: '');

/* ─── Academy membership ────────────────────────────────────────
 * Annual membership price (NGN) unlocking all members’ programmes. */
define('AV_MEMBERSHIP_NGN', (int) (getenv('AV_MEMBERSHIP_NGN') ?: 5000));

/* ─── Static Bank Account (Zenith) ─────────────────────────── */
define('BANK_NAME',      'Zenith Bank');
define('ACCOUNT_NAME',   'AMBASSADORS FOR COMMUNITY, TECH AND CULTURAL ADVANCEMENTS');
define('ACCOUNT_NUMBER', '1229629683');
define('BANK_CODE',      '057');

/* ─── Admin ─────────────────────────────────────────────────── */
// Gates the Diary Studio editor at /admin/. Generate:
//   php -r "echo bin2hex(random_bytes(32));"
define('ADMIN_TOKEN', _av_require_env('AV_ADMIN_TOKEN'));

/* ─── Cloudinary (Diary media uploads) ─────────────────────────
 * Optional. If unset, the editor stores uploads locally under /uploads.
 * Find these in your Cloudinary dashboard. */
define('CLOUDINARY_CLOUD_NAME', getenv('CLOUDINARY_CLOUD_NAME') ?: '');
define('CLOUDINARY_API_KEY',    getenv('CLOUDINARY_API_KEY') ?: '');
define('CLOUDINARY_API_SECRET', getenv('CLOUDINARY_API_SECRET') ?: '');

/* Document storage on Google Drive (service account). Images go to Cloudinary
 * (above); documents (PDF/Office/CSV) go to a shared Drive folder via lib/Storage.
 * Unset ⇒ documents fall back to local /uploads. AV_GDRIVE_SERVICE_ACCOUNT may be
 * the service-account JSON itself or a path to the key file. */
define('AV_GDRIVE_SERVICE_ACCOUNT', getenv('AV_GDRIVE_SERVICE_ACCOUNT') ?: '');
define('AV_GDRIVE_FOLDER_ID',       getenv('AV_GDRIVE_FOLDER_ID') ?: '');

/* Google sign-in (OAuth). Unset ⇒ "Continue with Google" stays disabled. */
define('AV_GOOGLE_CLIENT_ID',     getenv('AV_GOOGLE_CLIENT_ID') ?: '');
define('AV_GOOGLE_CLIENT_SECRET', getenv('AV_GOOGLE_CLIENT_SECRET') ?: '');

/* Google Workspace launchpad (member portal /portal/, @org members only).
 * Tool tiles (Gmail/Chat/Meet/Calendar/Drive/Groups) auto-derive from
 * AV_ORG_DOMAIN — no config needed. Optional extras via SetEnv in .htaccess
 * (read at runtime by lib/workspace.php):
 *   Link overrides:  AV_WS_MAIL_URL / AV_WS_CHAT_URL / AV_WS_MEET_URL /
 *                    AV_WS_CALENDAR_URL / AV_WS_DRIVE_URL / AV_WS_GROUPS_URL / AV_WS_ADMIN_URL
 *   In-portal embeds (read-only): AV_WS_CALENDAR_ID (or a full AV_WS_CALENDAR_EMBED
 *                    src) + AV_WS_TZ (default Africa/Lagos); AV_WS_DRIVE_FOLDER_ID
 *                    (a Drive folder shared "anyone with the link").
 *   Two-way calendar sync: when Google Workspace is connected with calendar
 *                    WRITE access (domain-wide delegation), AV_WS_CALENDAR_ID is
 *                    also the ORG calendar the portal's team calendar syncs with —
 *                    native team events are mirrored onto it (create/edit/delete)
 *                    and its events are pulled into the portal's Today agenda.
 *                    Unset ⇒ the portal calendar still works locally, just not synced.
 *   Communities: managed in the Studio (Communities tab). As a fallback when none
 *                exist there, AV_WS_COMMUNITIES accepts a JSON list, e.g.
 *                '[{"name":"All-hands","url":"https://chat.google.com/room/AAAA"}]'
 * Anything unset is simply hidden. */

/* ─── Team Chat ─────────────────────────────────────────────────
 * Team Chat (in the member portal) is open to ANY signed-in account by
 * default. Lock it to @org members only with:
 *   SetEnv AV_CHAT_ORG_ONLY 1
 * Mirror chat messages into a Google Chat space via an incoming webhook
 * (Google Chat → a space → "Manage webhooks" → copy the URL). Set one default
 * for all channels, and/or override per channel:
 *   SetEnv AV_GCHAT_WEBHOOK            https://chat.googleapis.com/v1/spaces/AAAA/messages?key=...&token=...
 *   SetEnv AV_GCHAT_WEBHOOK_ANNOUNCEMENTS  https://chat.googleapis.com/v1/spaces/BBBB/...
 * Unset ⇒ no mirroring (the chat still works fully on its own). */

/* ─── Application ───────────────────────────────────────────── */
define('SITE_URL',            'https://afrovanguard.org.ng');
define('CURRENCY_DEFAULT',    'NGN');
define('MIN_DONATION_AMOUNT',  1000);

define('TAX_RECEIPT_THRESHOLD_NGN', 5000);
define('TAX_RECEIPT_THRESHOLD_USD',    5);
define('TAX_RECEIPT_THRESHOLD_GBP',    5);

define('ENABLE_EMAIL_NOTIFICATIONS',  true);
define('ENABLE_ADMIN_NOTIFICATIONS',  true);
define('ENABLE_MONTHLY_RECURRING',    true);
define('ENABLE_BANK_TRANSFER_EMAIL',  true);

return [
    'smtp'     => ['host'=>SMTP_HOST,'port'=>SMTP_PORT,'username'=>SMTP_USERNAME,'password'=>SMTP_PASSWORD],
    'email'    => ['from'=>FROM_EMAIL,'from_name'=>FROM_NAME,'admin'=>ADMIN_EMAIL],
    'paystack' => ['public_key'=>PAYSTACK_PUBLIC_KEY,'secret_key'=>PAYSTACK_SECRET_KEY],
    'bank'     => ['name'=>BANK_NAME,'account_name'=>ACCOUNT_NAME,'account_number'=>ACCOUNT_NUMBER,'code'=>BANK_CODE],
];
