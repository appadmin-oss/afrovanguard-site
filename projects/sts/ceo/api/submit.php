<?php
// ─────────────────────────────────────────────────────────────────────
// STS · Form Submission
//   Critical ordering: validate → persist local → reply 200 → THEN do
//   slow work (Sheets + 2 emails). Shared hosting kills requests at ~30s;
//   we ran into 500s when the user was waiting for Apps Script + mail()
//   to finish before the OK was returned.
// ─────────────────────────────────────────────────────────────────────
require_once __DIR__ . '/_helpers.php';

// Quiet runtime — surface unexpected fatals as JSON, not as a host's
// generic 500 page. Errors are still written to error_log for diagnosis.
ini_set('display_errors', '0');
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);
set_error_handler(function ($errno, $errstr, $errfile, $errline) {
    if (!(error_reporting() & $errno)) return false;
    error_log("[STS submit] $errstr in $errfile:$errline");
    return true; // suppress default handler
});
register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => 'Server error. The team has been notified.']);
        }
        error_log('[STS submit FATAL] ' . $e['message'] . ' in ' . $e['file'] . ':' . $e['line']);
    }
});

sts_cors_and_json();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') sts_fail('POST only', 405);
if (!sts_rate_limit('submit', SUBMIT_RATE_LIMIT)) sts_fail('Too many submissions. Please try again later.', 429);

$body = sts_read_json_body();
if (!$body) sts_fail('No payload provided.');

// Validate
$required = ['name', 'role', 'org', 'email', 'centre', 'date'];
foreach ($required as $f) {
    if (empty($body[$f])) sts_fail("Missing required field: $f");
}
if (!filter_var($body['email'], FILTER_VALIDATE_EMAIL)) sts_fail('Invalid email address.');

// Sanitize
$record = [
    'id'        => 'sts_' . base_convert((string)(int)(microtime(true) * 1000), 10, 36) . '_' . bin2hex(random_bytes(3)),
    'timestamp' => date('c'),
    'ip'        => sts_client_ip(),
    'honorific' => sts_sanitize($body['honorific'] ?? '', 30),
    'name'      => sts_sanitize($body['name'], 200),
    'role'      => sts_sanitize($body['role'], 200),
    'org'       => sts_sanitize($body['org'], 200),
    'email'     => filter_var($body['email'], FILTER_SANITIZE_EMAIL),
    'whatsapp'  => sts_sanitize($body['whatsapp'] ?? '', 40),
    'notes'     => sts_sanitize($body['notes'] ?? '', 1000),
    'centre'    => sts_sanitize($body['centre'], 30),
    'centre_name' => sts_sanitize($body['centre_name'] ?? '', 100),
    'centre_focus' => sts_sanitize($body['centre_focus'] ?? '', 100),
    'date'      => sts_sanitize($body['date'], 20),
    'date_display' => sts_sanitize($body['date_display'] ?? '', 100),
    'theme'     => sts_sanitize($body['theme'] ?? '', 100),
    'series_theme' => sts_sanitize($body['series_theme'] ?? '', 100),
    'has_photo' => !empty($body['has_photo']),
];

// 1. Local backup — must succeed or we degrade the user's UX
if (!is_dir(DATA_DIR)) @mkdir(DATA_DIR, 0700, true);
if (!is_writable(DATA_DIR)) {
    error_log('[STS submit] DATA_DIR not writable: ' . DATA_DIR);
    sts_fail('Server storage error. Please try again or contact the team.', 503);
}
$file = DATA_DIR . '/submissions.json';
$existing = file_exists($file) ? (json_decode(@file_get_contents($file), true) ?: []) : [];
$existing[] = $record;
@file_put_contents($file, json_encode($existing, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);

// 2. Reply to the client RIGHT NOW. Everything after this is post-response.
// We buffer the OK output, then call sts_finish_response() so the client's
// promise resolves; PHP continues with Sheets + emails on the closed conn.
ob_start();
echo json_encode(['ok' => true, 'data' => ['id' => $record['id']]]);
$len = ob_get_length();
header('Content-Length: ' . $len);
header('Connection: close');
ob_end_flush();
@flush();
sts_finish_response();

// ── Post-response work ───────────────────────────────────────────────
// From here on, the user has already seen "session confirmed" — these
// errors are non-fatal and just get logged.
try {
    // 3. Sheets (best-effort) — strip IP before sending.
    $sheets_record = $record;
    unset($sheets_record['ip']);
    sts_appscript_post('submit', $sheets_record);
} catch (\Throwable $e) {
    error_log('[STS submit] Sheets failed: ' . $e->getMessage());
}

try {
    // 4. Admin notification
    $display_name = trim($record['honorific'] . ' ' . $record['name']);
    $admin_rows = [
        'Leader'       => htmlspecialchars((string)$display_name),
        'Role'         => htmlspecialchars((string)$record['role']),
        'Organisation' => htmlspecialchars((string)$record['org']),
        'Email'        => '<a href="mailto:'.htmlspecialchars((string)$record['email']).'">'.htmlspecialchars((string)$record['email']).'</a>',
        'WhatsApp'     => htmlspecialchars((string)($record['whatsapp'] ?: '—')),
        'Centre'       => htmlspecialchars((string)($record['centre_name'] ?: $record['centre'])),
        'Date'         => htmlspecialchars((string)($record['date_display'] ?: $record['date'])),
        'Theme'        => htmlspecialchars((string)($record['theme'] ?: '—')),
        'Note'         => htmlspecialchars((string)($record['notes'] ?: '—')),
        'Reference'    => '<code>'.htmlspecialchars((string)$record['id']).'</code>',
    ];
    $admin_html = sts_email_template(
        'New speaker initiation',
        'A leader has just confirmed their seat in the 2026 Future Leaders Archive — they\'ll address one of the centres housing the 900 Lagos children of this cohort.',
        $admin_rows, '', '', 'New Initiation'
    );
    if (defined('NOTIFY_EMAIL') && NOTIFY_EMAIL) {
        sts_send_email(NOTIFY_EMAIL, '[STS 2026] New speaker · ' . $display_name, $admin_html, '', $record['email']);
    }
} catch (\Throwable $e) {
    error_log('[STS submit] Admin email failed: ' . $e->getMessage());
}

try {
    // 5. Speaker confirmation
    $first = explode(' ', trim($record['name']))[0];
    $honor = $record['honorific'] ? $record['honorific'] . ' ' : '';
    $speaker_rows = [
        'Centre' => htmlspecialchars((string)($record['centre_name'] ?: $record['centre'])),
        'Date'   => htmlspecialchars((string)($record['date_display'] ?: $record['date'])),
        'Theme'  => htmlspecialchars((string)($record['theme'] ?: '')),
        'Time'   => '9:00 — 10:00 AM <span style="color:#6b7280;font-size:13px;">+ 30 min mentorship circle</span>',
    ];
    $speaker_html = sts_email_template(
        'Thank you, ' . htmlspecialchars($honor . $first) . '. Your seat is reserved.',
        'You have been added to the 2026 Future Leaders Archive. A member of our team will reach you 48 hours before your session with a personalised brief, the context of the Lagos children you\'ll address, and the run-of-show.',
        $speaker_rows, 'Visit Afrovanguard', 'https://afrovanguard.org.ng', 'Session Confirmed'
    );
    sts_send_email($record['email'], 'Your 2026 STS speaker session is confirmed', $speaker_html, '', defined('NOTIFY_EMAIL') ? NOTIFY_EMAIL : '');
} catch (\Throwable $e) {
    error_log('[STS submit] Speaker email failed: ' . $e->getMessage());
}
