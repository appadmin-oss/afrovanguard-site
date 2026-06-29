<?php
// ─────────────────────────────────────────────────────────────────────
// STS · Commitment pledge
//   Speaker has clicked "I'll equip N children". This is a pledge of
//   intent (not a payment). We persist locally, reply 200 immediately,
//   then do Sheets + emails after the response is closed — same pattern
//   as submit.php to stay under shared hosting's 30s execution limit.
// ─────────────────────────────────────────────────────────────────────
require_once __DIR__ . '/_helpers.php';

ini_set('display_errors', '0');
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);
register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => 'Server error.']);
        }
        error_log('[STS pledge FATAL] ' . $e['message']);
    }
});

sts_cors_and_json();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') sts_fail('POST only', 405);
if (!sts_rate_limit('pledge', DONATION_RATE_LIMIT)) sts_fail('Too many pledges. Please try again later.', 429);

$body = sts_read_json_body();
if (!$body) sts_fail('No payload provided.');

$record = [
    'timestamp'    => date('c'),
    'speaker_id'   => sts_sanitize($body['id'] ?? '', 80),
    'speaker_name' => sts_sanitize($body['speaker_name'] ?? '', 200),
    'email'        => filter_var($body['email'] ?? '', FILTER_SANITIZE_EMAIL),
    'tier'         => sts_sanitize($body['tier'] ?? '', 60),
    'amount'       => (int)($body['amount'] ?? 0),
    'children'     => (int)($body['children'] ?? 0),
    'ip'           => sts_client_ip(),
];

if (!$record['tier'] || $record['amount'] < 1) sts_fail('Tier and amount required.');

// 1. Local JSON backup (must succeed)
if (!is_dir(DATA_DIR)) @mkdir(DATA_DIR, 0700, true);
$file = DATA_DIR . '/pledges.json';
$all = file_exists($file) ? (json_decode(@file_get_contents($file), true) ?: []) : [];
$all[] = $record;
@file_put_contents($file, json_encode($all, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);

// 2. Reply OK now; slow work below the fold
ob_start();
echo json_encode(['ok' => true, 'data' => ['logged' => true]]);
header('Content-Length: ' . ob_get_length());
header('Connection: close');
ob_end_flush();
@flush();
sts_finish_response();

// ── Post-response (best-effort) ──────────────────────────────────────
try {
    sts_appscript_post('log_donation', [
        'speaker_id'   => $record['speaker_id'],
        'speaker_name' => $record['speaker_name'],
        'email'        => $record['email'],
        'tier'         => $record['tier'],
        'children'     => $record['children'],
        'amount'       => $record['amount'],
        'currency'     => 'NGN',
        'channel'      => 'commitment',
        'reference'    => '',
        'verified'     => false,
        'paid_at'      => '',
    ]);
} catch (\Throwable $e) { error_log('[STS pledge] Sheets failed: ' . $e->getMessage()); }

// 3. Team notification
try {
    if (defined('NOTIFY_EMAIL') && NOTIFY_EMAIL) {
        $rows = [
            'Speaker'         => htmlspecialchars((string)($record['speaker_name'] ?: '(unknown)')),
            'Email'           => htmlspecialchars((string)($record['email'] ?: '—')),
            'Tier'            => htmlspecialchars((string)$record['tier']),
            'Lagos children'  => $record['children'] ? $record['children'] . ' to equip' : '—',
            'Estimated total' => '₦' . number_format($record['amount']),
            'Speaker Ref'     => '<code>' . htmlspecialchars((string)($record['speaker_id'] ?: '—')) . '</code>',
            'Action needed'   => '<strong style="color:#C8102E">Reach out within 48 hours to arrange the contribution.</strong>',
        ];
        $html = sts_email_template(
            'A new commitment has been made',
            'A confirmed speaker has just pledged to equip some of the Lagos children of the 2026 cohort with their Summer Packs. The team should reach out within 48 hours to arrange the actual contribution by whatever method suits them best.',
            $rows, '', '', 'Commitment · action needed'
        );
        sts_send_email(NOTIFY_EMAIL, '[STS 2026] Commitment · ' . ($record['speaker_name'] ?: 'Speaker') . ' · ₦' . number_format($record['amount']), $html, '', $record['email']);
    }
} catch (\Throwable $e) { error_log('[STS pledge] Team email failed: ' . $e->getMessage()); }

// 4. Speaker receipt
try {
    if ($record['email'] && filter_var($record['email'], FILTER_VALIDATE_EMAIL)) {
        $first_name = trim(explode(' ', trim($record['speaker_name']))[0] ?: '');
        $speaker_rows = [
            'Your commitment' => htmlspecialchars((string)$record['tier']),
            'Lagos children'  => $record['children'] ? $record['children'] . ' to equip' : '—',
            'Estimated total' => '₦' . number_format($record['amount']),
            'Next step'       => 'Our team will be in touch within 48 hours.',
        ];
        $speaker_html = sts_email_template(
            ($first_name ? 'Thank you, ' . htmlspecialchars($first_name) . '.' : 'Thank you.') . ' Noted with care.',
            'You\'ve indicated you would like to help equip some of the Lagos children you\'ll be speaking to with their 2026 Summer Packs. <strong>No payment has been taken.</strong> The details below are a record of your intent. A member of our team will reach you within 48 hours to arrange the contribution by whatever method suits you — bank transfer, card, or cheque. You can change your mind at any point before then — simply reply to this email.',
            $speaker_rows, '', '', 'Commitment Noted'
        );
        sts_send_email($record['email'], 'Your STS 2026 commitment — noted', $speaker_html, '', defined('NOTIFY_EMAIL') ? NOTIFY_EMAIL : '');
    }
} catch (\Throwable $e) { error_log('[STS pledge] Speaker email failed: ' . $e->getMessage()); }
