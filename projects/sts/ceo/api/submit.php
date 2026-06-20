<?php
// ─────────────────────────────────────────────────────────────────────
// STS · Form Submission
// Writes to: 1) local JSON backup, 2) Google Sheets (Apps Script), 3) email
// ─────────────────────────────────────────────────────────────────────
require_once __DIR__ . '/_helpers.php';
sts_cors_and_json();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') sts_fail('POST only', 405);
if (!sts_rate_limit('submit', SUBMIT_RATE_LIMIT)) sts_fail('Too many submissions. Please try again later.', 429);

$body = sts_read_json_body();
if (!$body) sts_fail('No payload provided.');

// ── Validate ────────────────────────────────────────────────────────
$required = ['name', 'role', 'org', 'email', 'centre', 'date'];
foreach ($required as $f) {
    if (empty($body[$f])) sts_fail("Missing required field: $f");
}
if (!filter_var($body['email'], FILTER_VALIDATE_EMAIL)) sts_fail('Invalid email address.');

// ── Sanitize ────────────────────────────────────────────────────────
$record = [
    'id'        => 'sts_' . base_convert((string)microtime(true) * 1000, 10, 36) . '_' . bin2hex(random_bytes(3)),
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
    'date'      => sts_sanitize($body['date'], 20),
];

// ── 1. Local JSON backup (always runs, even if Sheets fails) ────────
$file = DATA_DIR . '/submissions.json';
$existing = file_exists($file) ? (json_decode(@file_get_contents($file), true) ?: []) : [];
$existing[] = $record;
@file_put_contents($file, json_encode($existing, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);

// ── 2. Write to Google Sheets via Apps Script ───────────────────────
$sheets = sts_appscript_post('submit', $record);
// We don't fail the user request if Sheets is down — local backup is the source of truth.

// ── 3. Email notification (optional) ────────────────────────────────
if (NOTIFY_EMAIL) {
    $subject = '[STS 2026] New speaker initiation: ' . $record['name'];
    $body_text =
        "New speaker initiation received.\n\n" .
        "Name: " . $record['honorific'] . " " . $record['name'] . "\n" .
        "Role: " . $record['role'] . "\n" .
        "Organisation: " . $record['org'] . "\n" .
        "Email: " . $record['email'] . "\n" .
        "WhatsApp: " . $record['whatsapp'] . "\n" .
        "Centre: " . $record['centre'] . "\n" .
        "Date: " . $record['date'] . "\n" .
        "Notes: " . $record['notes'] . "\n\n" .
        "ID: " . $record['id'] . "\n" .
        "Submitted: " . $record['timestamp'] . " (IP " . $record['ip'] . ")";
    @mail(NOTIFY_EMAIL, $subject, $body_text, "From: " . NOTIFY_FROM . "\r\nReply-To: " . $record['email']);
}

sts_ok(['id' => $record['id'], 'sheets' => !empty($sheets['ok'])]);
