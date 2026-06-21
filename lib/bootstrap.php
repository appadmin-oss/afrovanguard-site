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
$cfg = AV_ROOT . '/config.php';
if (is_file($cfg)) { require_once $cfg; }
if (!defined('SITE_URL'))         define('SITE_URL', 'https://afrovanguard.org.ng');
if (!defined('AV_DB_PATH'))       define('AV_DB_PATH', AV_ROOT . '/db/diary.sqlite');

// Admin token (config.php or AV_ADMIN_TOKEN env). Absent ⇒ admin disabled.
if (!defined('ADMIN_TOKEN')) {
    $t = getenv('AV_ADMIN_TOKEN');
    if ($t !== false && $t !== '') define('ADMIN_TOKEN', $t);
}
// Cloudinary (config.php or env). Absent ⇒ uploads fall back to local /uploads.
foreach (['CLOUDINARY_CLOUD_NAME', 'CLOUDINARY_API_KEY', 'CLOUDINARY_API_SECRET'] as $k) {
    if (!defined($k)) { $v = getenv($k); if ($v !== false && $v !== '') define($k, $v); }
}

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/DiaryRepository.php';
require_once __DIR__ . '/AcademyRepository.php';

av_harden_errors();

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
