<?php
/**
 * lib/bootstrap.php — single entry point for the Diary backend.
 *
 * Loads shared config + the modular data layer. Include this from any
 * diary endpoint (index.php, article.php, api.php). Everything the Diary
 * needs flows from here, so the pieces stay connected and in sync.
 */
declare(strict_types=1);

define('AV_ROOT', dirname(__DIR__));

// Reuse the site's config.php if deployed; otherwise fall back to safe
// public defaults so the Diary runs standalone (and in local dev).
//
// config.php (the legacy donation/payments config) calls _av_require_env() for
// a handful of SECRETS and historically hard-exits the whole request if any is
// missing. The Diary / Academy / Portal / public team API don't need those
// secrets just to RENDER, so a missing payment/SMTP key must never take the
// public site down. Only load config.php when its required secrets are actually
// present; if any is missing we skip it and rely on the env-driven fallbacks
// below. (Endpoints that truly need a secret — donations, contact, admin —
// require config.php directly and validate their own prerequisites.)
$cfg = AV_ROOT . '/config.php';
if (is_file($cfg)) {
    $cfgSafe = true;
    foreach (['AV_SMTP_PASSWORD', 'AV_PAYSTACK_PK', 'AV_PAYSTACK_SK', 'AV_ADMIN_TOKEN'] as $__k) {
        $__v = getenv($__k);
        if ($__v === false || $__v === '') { $cfgSafe = false; break; }
    }
    if ($cfgSafe) {
        require_once $cfg;
    } else {
        error_log('[AV bootstrap] config.php present but a required secret env var is missing — '
            . 'serving the public site from env fallbacks. Set AV_SMTP_PASSWORD / AV_PAYSTACK_PK / '
            . 'AV_PAYSTACK_SK / AV_ADMIN_TOKEN (e.g. via .htaccess SetEnv) to restore '
            . 'donation/contact/admin features.');
    }
}
if (!defined('SITE_URL'))         define('SITE_URL', 'https://afrovanguard.org.ng');
if (!defined('AV_DB_PATH'))       define('AV_DB_PATH', getenv('AV_DB_PATH') ?: (AV_ROOT . '/db/diary.sqlite'));

// Admin token (config.php or AV_ADMIN_TOKEN env). Absent ⇒ admin disabled.
if (!defined('ADMIN_TOKEN')) {
    $t = getenv('AV_ADMIN_TOKEN');
    if ($t !== false && $t !== '') define('ADMIN_TOKEN', $t);
}
// Cloudinary (config.php or env). Absent ⇒ uploads fall back to local /uploads.
foreach (['CLOUDINARY_CLOUD_NAME', 'CLOUDINARY_API_KEY', 'CLOUDINARY_API_SECRET'] as $k) {
    if (!defined($k)) { $v = getenv($k); if ($v !== false && $v !== '') define($k, $v); }
}
// Payments (Paystack primary — already used by donations — + optional Flutterwave).
// config.php normally defines PAYSTACK_PUBLIC_KEY / PAYSTACK_SECRET_KEY directly;
// also accept the donation system's AV_PAYSTACK_PK/SK env aliases as a fallback.
if (!defined('PAYSTACK_PUBLIC_KEY')) { $v = getenv('PAYSTACK_PUBLIC_KEY') ?: getenv('AV_PAYSTACK_PK'); if ($v) define('PAYSTACK_PUBLIC_KEY', $v); }
if (!defined('PAYSTACK_SECRET_KEY')) { $v = getenv('PAYSTACK_SECRET_KEY') ?: getenv('AV_PAYSTACK_SK'); if ($v) define('PAYSTACK_SECRET_KEY', $v); }
foreach (['FLW_PUBLIC_KEY', 'FLW_SECRET_KEY'] as $k) {
    if (!defined($k)) { $v = getenv($k); if ($v !== false && $v !== '') define($k, $v); }
}
if (!defined('AV_MEMBERSHIP_NGN')) { $v = getenv('AV_MEMBERSHIP_NGN'); define('AV_MEMBERSHIP_NGN', $v !== false && $v !== '' ? (int) $v : 5000); }
// Google sign-in (config.php or env). Absent ⇒ the "Continue with Google" button stays disabled.
foreach (['AV_GOOGLE_CLIENT_ID', 'AV_GOOGLE_CLIENT_SECRET'] as $k) {
    if (!defined($k)) { $v = getenv($k); if ($v !== false && $v !== '') define($k, $v); }
}
// Document storage on Google Drive (service account). Absent ⇒ docs fall back to local /uploads.
foreach (['AV_GDRIVE_SERVICE_ACCOUNT', 'AV_GDRIVE_FOLDER_ID'] as $k) {
    if (!defined($k)) { $v = getenv($k); if ($v !== false && $v !== '') define($k, $v); }
}
// SMTP / email. config.php normally defines these; ALSO accept env so the
// documented SetEnv-based setup actually works. Without them, Mailer reports
// "not configured" and silently skips EVERY email — including sign-in codes
// and verification links. SMTP_PASSWORD falls back to the AV_SMTP_PASSWORD
// alias the donation config already uses.
foreach (['SMTP_HOST', 'SMTP_PORT', 'SMTP_USERNAME', 'SMTP_SECURE', 'FROM_EMAIL', 'FROM_NAME', 'ADMIN_EMAIL'] as $k) {
    if (!defined($k)) { $v = getenv($k); if ($v !== false && $v !== '') define($k, $v); }
}
if (!defined('SMTP_PASSWORD')) { $v = getenv('SMTP_PASSWORD'); if ($v === false || $v === '') $v = getenv('AV_SMTP_PASSWORD'); if ($v !== false && $v !== '') define('SMTP_PASSWORD', $v); }
if (!defined('SMTP_VERIFY')) { $v = getenv('SMTP_VERIFY'); if ($v !== false && $v !== '') define('SMTP_VERIFY', !in_array(strtolower((string) $v), ['0', 'false', 'no', 'off'], true)); }

// The Workspace domain whose VERIFIED accounts are recognised as real org members.
if (!defined('AV_ORG_DOMAIN')) define('AV_ORG_DOMAIN', getenv('AV_ORG_DOMAIN') ?: 'afrovanguard.org.ng');

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/DiaryRepository.php';
require_once __DIR__ . '/DiaryJournal.php';
require_once __DIR__ . '/AcademyRepository.php';
require_once __DIR__ . '/AuthPolicy.php';
require_once __DIR__ . '/Otp.php';
require_once __DIR__ . '/LmsAuth.php';
require_once __DIR__ . '/LmsRepository.php';
require_once __DIR__ . '/GoogleAuth.php';
require_once __DIR__ . '/Payments.php';
require_once __DIR__ . '/Smtp.php';
require_once __DIR__ . '/Mailer.php';
require_once __DIR__ . '/Notify.php';
require_once __DIR__ . '/Events.php';
require_once __DIR__ . '/Webhooks.php';
require_once __DIR__ . '/AppTokens.php';
require_once __DIR__ . '/AvBot.php';
require_once __DIR__ . '/Mentorship.php';
require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/Sitemap.php';
require_once __DIR__ . '/AuthArt.php';
require_once __DIR__ . '/Cloudinary.php';
require_once __DIR__ . '/Drive.php';
require_once __DIR__ . '/GoogleWorkspace.php';
require_once __DIR__ . '/Storage.php';

av_harden_errors();

/** Academy base URL (subdomain-ready). Defaults to the /academy path. */
if (!defined('ACADEMY_URL')) {
    $env = getenv('AV_ACADEMY_URL');
    define('ACADEMY_URL', $env ?: (rtrim(SITE_URL, '/') . '/academy'));
}
function academy_url(string $path = ''): string { return rtrim(ACADEMY_URL, '/') . '/' . ltrim($path, '/'); }

/** Member portal base URL (subdomain-ready). Set AV_PORTAL_URL to e.g.
 *  https://members.afrovanguard.org.ng to serve the portal on its own host
 *  (same docroot); defaults to the /portal path on the main domain. */
if (!defined('PORTAL_URL')) {
    $env = getenv('AV_PORTAL_URL');
    define('PORTAL_URL', $env ?: (rtrim(SITE_URL, '/') . '/portal'));
}
function portal_url(string $path = ''): string { return rtrim(PORTAL_URL, '/') . '/' . ltrim($path, '/'); }

/**
 * Gate an endpoint behind admin auth: a valid signed session cookie OR a
 * Bearer admin token (break-glass). State-changing cookie requests must
 * also carry a valid CSRF header (call av_csrf_require() in the route).
 */
function require_admin(): void {
    if (!defined('ADMIN_TOKEN') || strlen((string) ADMIN_TOKEN) < 8) {
        json_out(['ok' => false, 'error' => 'Admin is not configured on this server.'], 503);
    }
    if (av_admin_cookie_valid() || av_admin_bearer_ok()) return;
    json_out(['ok' => false, 'error' => 'Unauthorized.'], 401);
}
