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

/* ─── Email (SMTP) ────────────────────────────────────────────
 * Powers donation receipts, contact replies AND Academy emails
 * (welcome / enrolment / membership / certificate-ready) via the
 * shared lib/Mailer.php, which sends through the battle-tested
 * PHPMailer bundled at lib/vendor/phpmailer/ (same transport as
 * Africa GATES / NextGenGen — no Composer needed on the host).
 *
 * RECOMMENDED: a dedicated relay like Brevo (free tier: 300/day) —
 * shared-host Gmail/Workspace SMTP is often blocked and silently
 * drops mail. Brevo: app.brevo.com → SMTP & API → SMTP.
 *   SMTP_HOST=smtp-relay.brevo.com  SMTP_PORT=587
 *   SMTP_USERNAME=<your brevo login>  SMTP_PASSWORD=<brevo SMTP key>
 *
 * You can set these as constants here, OR as env / .env using either
 * name style — SMTP_USERNAME|SMTP_USER, SMTP_PASSWORD|SMTP_PASS,
 * FROM_EMAIL|MAIL_FROM_ADDRESS, FROM_NAME|MAIL_FROM_NAME. */
define('SMTP_HOST',     'smtp-relay.brevo.com');
define('SMTP_PORT',      587);
define('SMTP_USERNAME', getenv('AV_SMTP_USER') ?: 'your_brevo_login');  // Brevo login
define('SMTP_PASSWORD', _av_require_env('AV_SMTP_PASSWORD'));           // Brevo SMTP key
define('FROM_EMAIL',    'cacentre@afrovanguard.org.ng');
define('FROM_NAME',     'Afrovanguard');
define('ADMIN_EMAIL',   'cacentre@afrovanguard.org.ng');
// Optional transport overrides (defaults shown):
//   SMTP_SECURE 'tls' = STARTTLS (587, Gmail) | 'ssl' = SMTPS (465) | '' = none
//   SMTP_VERIFY true   = verify TLS cert (set false only for self-signed relays)
// define('SMTP_SECURE', 'tls');
// define('SMTP_VERIFY', true);

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
 *   Communities: managed in the Studio (Communities tab). As a fallback when none
 *                exist there, AV_WS_COMMUNITIES accepts a JSON list, e.g.
 *                '[{"name":"All-hands","url":"https://chat.google.com/room/AAAA"}]'
 * Anything unset is simply hidden. */

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
