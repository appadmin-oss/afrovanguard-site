<?php
// ─────────────────────────────────────────────────────────────────────
// STS · Donation click tracker
// Logs the speaker's donation tier choice, emails admin, then redirects.
// Called via <a href="api/donate.php?id=...&tier=...&amount=...">
// ─────────────────────────────────────────────────────────────────────
require_once __DIR__ . '/_helpers.php';

if (!sts_rate_limit('donation', DONATION_RATE_LIMIT)) {
    header('Location: ' . DONATE_URL); exit;
}

$id     = $_GET['id']     ?? '';
$tier   = $_GET['tier']   ?? '';
$amount = (int)($_GET['amount'] ?? 0);
$children = (int)($_GET['children'] ?? 0);

// Look up the speaker by id (best-effort)
$record = null;
$file = DATA_DIR . '/submissions.json';
if ($id && file_exists($file)) {
    $all = json_decode(@file_get_contents($file), true) ?: [];
    foreach ($all as $r) {
        if (($r['id'] ?? '') === $id) { $record = $r; break; }
    }
}

// Append donation intent to a log
$log_file = DATA_DIR . '/donations.json';
$log = file_exists($log_file) ? (json_decode(@file_get_contents($log_file), true) ?: []) : [];
$log[] = [
    'timestamp' => date('c'),
    'ip'        => sts_client_ip(),
    'id'        => sts_sanitize($id, 64),
    'tier'      => sts_sanitize($tier, 60),
    'amount'    => $amount,
    'children'  => $children,
    'speaker'   => $record ? trim(($record['honorific'] ?? '') . ' ' . ($record['name'] ?? '')) : null,
    'email'     => $record['email'] ?? null,
];
@file_put_contents($log_file, json_encode($log, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);

// Notify admin
if (NOTIFY_EMAIL) {
    $speaker_name = $record ? trim(($record['honorific'] ?? '') . ' ' . ($record['name'] ?? '')) : '(unknown speaker)';
    $rows = [
        'Speaker'   => htmlspecialchars($speaker_name),
        'Email'     => $record ? '<a href="mailto:'.htmlspecialchars($record['email']).'">'.htmlspecialchars($record['email']).'</a>' : '—',
        'Tier'      => htmlspecialchars($tier ?: 'Custom'),
        'Amount'    => '₦' . number_format($amount),
        'Children'  => $children ? $children . ' children equipped' : '—',
        'Reference' => '<code>'.htmlspecialchars($id ?: '—').'</code>',
    ];
    $html = sts_email_template(
        'A speaker is donating',
        'A confirmed 2026 speaker just clicked through to the donation page after choosing a sponsorship tier.',
        $rows
    );
    sts_send_email(NOTIFY_EMAIL, '[STS 2026] Sponsorship intent · ' . $speaker_name . ' · ₦' . number_format($amount), $html);
}

// Sheets log — donation INTENT (verified=false). When the user actually
// pays via Paystack, paystack-verify.php logs the same speaker_id with
// verified=true, which is what unlocks the kit.
sts_appscript_post('log_donation', [
    'speaker_id'   => $id,
    'speaker_name' => $record ? trim(($record['honorific'] ?? '') . ' ' . ($record['name'] ?? '')) : '',
    'email'        => $record['email'] ?? '',
    'tier'         => $tier,
    'children'     => $children,
    'amount'       => $amount,
    'currency'     => 'NGN',
    'channel'      => 'intent',
    'reference'    => '',
    'verified'     => false,
]);

// Redirect to actual donation page.
// Includes a callback URL so the portal can return the user to the form
// with ?donated=1 on success (the React app picks this up and unlocks).
$origin = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http')
        . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
$callback = $origin . '/index.html?donated=1&amount=' . $amount . '&ref=' . urlencode($id);

$target = DONATE_URL;
$qs = http_build_query([
    'ref'      => $id,
    'tier'     => $tier,
    'amount'   => $amount,
    'children' => $children,
    'callback' => $callback,
]);
header('Location: ' . $target . (strpos($target, '?') !== false ? '&' : '?') . $qs, true, 302);
exit;
