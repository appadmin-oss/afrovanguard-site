<?php
// ─────────────────────────────────────────────────────────────────────
// STS · Backend Configuration EXAMPLE
// Copy to config.php and set env vars in .htaccess (never commit either)
// ─────────────────────────────────────────────────────────────────────
//
//   SetEnv STS_APPS_SCRIPT_URL  https://script.google.com/macros/s/.../exec
//   SetEnv STS_APPS_SCRIPT_KEY  your_shared_key_here
//

// ── Gemini AI ────────────────────────────────────────────────────────
define('GEMINI_API_KEY', 'YOUR_GEMINI_API_KEY_HERE');
define('GEMINI_MODEL',   'gemini-2.0-flash');
define('GEMINI_URL',     'https://generativelanguage.googleapis.com/v1beta/models/' . GEMINI_MODEL . ':generateContent?key=' . GEMINI_API_KEY);

// ── Google Sheets via Apps Script ────────────────────────────────────
$_sts_url = getenv('STS_APPS_SCRIPT_URL');
$_sts_key = getenv('STS_APPS_SCRIPT_KEY');
if (!$_sts_url || !$_sts_key) {
    http_response_code(500);
    error_log('[STS] Missing required env var: STS_APPS_SCRIPT_URL or STS_APPS_SCRIPT_KEY');
    echo json_encode(['success' => false, 'message' => 'Server configuration error.']);
    exit;
}
define('APPS_SCRIPT_URL', $_sts_url);
define('APPS_SCRIPT_KEY', $_sts_key);
unset($_sts_url, $_sts_key);

// ── Email notifications (optional) ───────────────────────────────────
define('NOTIFY_EMAIL',   '');
define('NOTIFY_FROM',    'noreply@sts.afrovanguard.org.ng');

// ── Rate limiting ────────────────────────────────────────────────────
define('AI_RATE_LIMIT',         30);
define('SUBMIT_RATE_LIMIT',      3);
define('SUBSCRIBE_RATE_LIMIT',   5);
define('RESERVATIONS_CACHE_TTL', 60);

// ── CORS ─────────────────────────────────────────────────────────────
define('ALLOWED_ORIGIN', 'https://afrovanguard.org.ng/projects/sts/ceo/');

// ── Storage paths (auto-created) ─────────────────────────────────────
define('DATA_DIR', __DIR__ . '/data');
if (!is_dir(DATA_DIR)) { @mkdir(DATA_DIR, 0700, true); }
