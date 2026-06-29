<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/ratelimit.php';
require_once __DIR__ . '/mailer.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_error('Method not allowed', 405);

check_honeypot();
rate_limit('newsletter', 5, 300);

$email = input_email('email') ?: json_error('Please enter a valid email address.', 422, 'email');
$name = input_post('full_name', '');
$token = bin2hex(random_bytes(32));

try {
    $pdo = db();
    $stmt = $pdo->prepare("INSERT INTO newsletter_subscribers (email, full_name, status, unsubscribe_token)
        VALUES (:e, :n, 'active', :t)
        ON DUPLICATE KEY UPDATE status = 'active'");
    $stmt->execute([':e' => $email, ':n' => $name, ':t' => $token]);
} catch (Throwable $e) {
    log_line('db', 'newsletter insert failed', ['err' => $e->getMessage()]);
    json_error('We could not subscribe you. Please try again.', 500);
}

$site = rtrim((string)env('SITE_URL', 'https://streettostardom.org'), '/');
send_email($email, 'Welcome to STS field notes',
    "<p>Thanks for subscribing to Street-To-Stardom field notes.</p>"
    . "<p>You'll receive about four emails a year — the longer reads, plus an annual methodology brief.</p>"
    . "<p style=\"font-size:12px;color:#888\"><a href=\"$site/api/unsubscribe.php?token=" . urlencode($token) . "\">Unsubscribe</a></p>");

json_response(['ok' => true]);
