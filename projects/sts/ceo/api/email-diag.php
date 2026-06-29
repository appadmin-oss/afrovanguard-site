<?php
// ─────────────────────────────────────────────────────────────────────
// STS · Email Diagnostic
//
// Visit in browser:
//   https://yourdomain.com/api/email-diag.php
//   https://yourdomain.com/api/email-diag.php?to=you@example.com
//
// Reports the email subsystem state and (optionally) sends a single
// test message through the same code path the form uses, so what works
// here is what will work for confirmations and pledge receipts.
//
// Safe to leave in production: it's NOT rate-limited but it writes no
// data. Remove if you prefer.
// ─────────────────────────────────────────────────────────────────────
require_once __DIR__ . '/_helpers.php';

ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');

$composer_autoload = file_exists(__DIR__ . '/vendor/autoload.php');
$manual_install    = file_exists(__DIR__ . '/phpmailer/src/PHPMailer.php');
$phpmailer_class   = sts_phpmailer_available();

$report = [
    'ok' => true,
    'environment' => [
        'php_version'      => PHP_VERSION,
        'sapi'             => php_sapi_name(),
        'mail_function'    => function_exists('mail'),
        'openssl'          => extension_loaded('openssl'),
        'curl'             => function_exists('curl_init'),
        'sendmail_path'    => ini_get('sendmail_path') ?: '(not set)',
    ],
    'phpmailer' => [
        'composer_autoload' => $composer_autoload,
        'manual_install'    => $manual_install,
        'class_loadable'    => (bool)$phpmailer_class,
        'version'           => $phpmailer_class && defined($phpmailer_class . '::VERSION')
                                 ? constant($phpmailer_class . '::VERSION') : null,
    ],
    'paths_configured' => [
        'phpmailer_smtp' => (defined('USE_SMTP') && USE_SMTP &&
                             defined('SMTP_HOST') && SMTP_HOST &&
                             defined('SMTP_USER') && SMTP_USER &&
                             (bool)$phpmailer_class),
        'resend_api'     => (defined('RESEND_API_KEY') && RESEND_API_KEY && RESEND_API_KEY !== 'YOUR_RESEND_KEY_HERE'),
        'mail_function'  => function_exists('mail'),
    ],
    'config' => [
        'NOTIFY_EMAIL'     => defined('NOTIFY_EMAIL') ? NOTIFY_EMAIL : '(undefined)',
        'NOTIFY_FROM'      => defined('NOTIFY_FROM') ? NOTIFY_FROM : '(undefined)',
        'NOTIFY_FROM_NAME' => defined('NOTIFY_FROM_NAME') ? NOTIFY_FROM_NAME : '(undefined)',
        'USE_SMTP'         => defined('USE_SMTP') ? (USE_SMTP ? 'true' : 'false') : '(undefined)',
        'SMTP_HOST'        => defined('SMTP_HOST') ? (SMTP_HOST ?: '(empty)') : '(undefined)',
        'SMTP_PORT'        => defined('SMTP_PORT') ? SMTP_PORT : '(undefined)',
        'SMTP_USER'        => defined('SMTP_USER') ? (SMTP_USER ? substr(SMTP_USER, 0, 3) . '…@' . (strpos(SMTP_USER, '@') !== false ? explode('@', SMTP_USER)[1] : '') : '(empty)') : '(undefined)',
        'SMTP_SECURE'      => defined('SMTP_SECURE') ? SMTP_SECURE : '(undefined)',
        'RESEND_API_KEY'   => (defined('RESEND_API_KEY') && RESEND_API_KEY && RESEND_API_KEY !== 'YOUR_RESEND_KEY_HERE')
                                ? substr(RESEND_API_KEY, 0, 6) . '…' . substr(RESEND_API_KEY, -4) : '(not set)',
    ],
];

// ── Static problem detection (runs even without ?to=) ───────────────
$problems = [];
if (defined('USE_SMTP') && USE_SMTP) {
    if (!$phpmailer_class)         $problems[] = 'USE_SMTP=true but PHPMailer is not loadable. Confirm api/phpmailer/src/PHPMailer.php exists.';
    if (empty(SMTP_HOST))          $problems[] = 'USE_SMTP=true but SMTP_HOST is empty.';
    if (empty(SMTP_USER))          $problems[] = 'USE_SMTP=true but SMTP_USER is empty.';
    if (empty(SMTP_PASS))          $problems[] = 'USE_SMTP=true but SMTP_PASS is empty.';
    if (!extension_loaded('openssl')) $problems[] = 'PHP openssl extension missing — STARTTLS / SSL SMTP will fail.';
} else {
    $problems[] = 'USE_SMTP=false. Mail will fall through to Resend (if keyed) or PHP mail() (often blocked on shared hosts).';
}
if (defined('NOTIFY_FROM') && defined('SMTP_USER') && USE_SMTP && SMTP_USER && NOTIFY_FROM !== SMTP_USER) {
    $problems[] = 'NOTIFY_FROM (' . NOTIFY_FROM . ') is not the same address as SMTP_USER (' . SMTP_USER . '). Most hosts will reject; set NOTIFY_FROM to the authenticated mailbox.';
}
$report['problems'] = $problems;

// ── Optional live test — sends through sts_send_email() ─────────────
$to = isset($_GET['to']) ? filter_var($_GET['to'], FILTER_VALIDATE_EMAIL) : null;
if ($to) {
    $subject = 'STS email diagnostic · ' . date('Y-m-d H:i:s');
    $html = '<p>This is a test message from the STS form email diagnostic.</p>'
          . '<p>If you can read this, the email subsystem is wired up correctly and the form will send.</p>'
          . '<p style="color:#888;font-size:12px;">Generated at ' . date('c') . '</p>';
    $started = microtime(true);

    // Capture error_log output for this run so we can show what
    // happened inside PHPMailer / Resend without the user having to
    // open the host's log file.
    ob_start();
    $sent = sts_send_email($to, $subject, $html, '', '');
    ob_end_clean();

    $report['send_test'] = [
        'attempted'    => true,
        'success'      => (bool)$sent,
        'to'           => $to,
        'duration_ms'  => round((microtime(true) - $started) * 1000),
        'used_path'    => $sent
                            ? (($phpmailer_class && defined('USE_SMTP') && USE_SMTP) ? 'phpmailer-smtp'
                                : ((defined('RESEND_API_KEY') && RESEND_API_KEY && RESEND_API_KEY !== 'YOUR_RESEND_KEY_HERE') ? 'resend (probable)' : 'mail()'))
                            : '(failed — check error_log)',
        'note'         => $sent
                            ? 'sts_send_email returned true. Check your inbox / spam folder. Delivery is not guaranteed by a true return — providers can still drop it post-handoff.'
                            : 'sts_send_email returned false. See PHP error_log for the specific failure. Common: SMTP auth failed, SMTP port blocked, NOTIFY_FROM not owned by SMTP_USER.',
    ];
    $report['ok'] = (bool)$sent;
} else {
    $report['next'] = 'Add ?to=you@example.com to send a live test email through the same path the form uses.';
}

echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
