<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/ratelimit.php';
require_once __DIR__ . '/mailer.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_error('Method not allowed', 405);

check_honeypot();
csrf_require();
rate_limit('sponsor.submit', 5, 300);

$name = require_field('full_name', input_post('full_name'));
$email = input_email('email') ?: json_error('Please enter a valid email address.', 422, 'email');
$phone = input_post('phone', '');
$org = input_post('organization', '');
$amount = (float)(input_post('intended_amount_ngn', '0') ?? 0);
$frequency = input_post('frequency', 'one_time');
$program = input_post('selected_program', 'any');

$allowedFreq = ['one_time','monthly','quarterly','annually'];
if (!in_array($frequency, $allowedFreq, true)) $frequency = 'one_time';
if ($amount < 0) $amount = 0;
if ($amount > 100000000) $amount = 100000000;

try {
    $pdo = db();
    $stmt = $pdo->prepare("INSERT INTO sponsor_inquiries
        (full_name, email, phone, organization, intended_amount_ngn, frequency, selected_program, referred_to_afrovanguard)
        VALUES (:n, :e, :p, :o, :a, :f, :pr, 1)");
    $stmt->execute([
        ':n' => $name, ':e' => $email, ':p' => $phone, ':o' => $org,
        ':a' => $amount, ':f' => $frequency, ':pr' => $program,
    ]);
    $id = (int)$pdo->lastInsertId();
} catch (Throwable $e) {
    log_line('db', 'sponsor insert failed', ['err' => $e->getMessage()]);
    json_error('We could not save your inquiry. Please try again or email sponsors@streettostardom.org.', 500);
}

$base = env('AFROVANGUARD_SPONSOR_URL', 'https://afrovanguard.org.ng/sponsor');
$handoff = $base . '?' . http_build_query([
    'amount' => (int)$amount,
    'frequency' => $frequency,
    'program' => $program,
    'source' => 'sts',
    'inquiry_id' => $id,
    'email' => $email,
]);

send_email($email, 'Next step · Complete your STS sponsorship',
    "<p>Hi " . htmlspecialchars(explode(' ', $name)[0]) . ",</p>"
    . "<p>Thanks for letting us know you'd like to sponsor a Street-To-Stardom cohort. To complete payment securely, head over to Afrovanguard — your selections are already pre-filled:</p>"
    . "<p><a href=\"" . htmlspecialchars($handoff) . "\">Complete sponsorship on Afrovanguard →</a></p>"
    . "<p>Once payment is confirmed, you'll receive a 1-page sponsorship summary and a monthly report on the children your sponsorship funds.</p>"
    . "<p>— The STS team</p>");

send_email(inbox_for('sponsor'),
    "New sponsor inquiry · ₦" . number_format($amount) . " · {$frequency}",
    "<p>New sponsor inquiry.</p>"
    . "<ul>"
    . "<li>Name: " . htmlspecialchars($name) . "</li>"
    . "<li>Email: " . htmlspecialchars($email) . "</li>"
    . "<li>Phone: " . htmlspecialchars($phone) . "</li>"
    . "<li>Organisation: " . htmlspecialchars($org) . "</li>"
    . "<li>Amount: ₦" . number_format($amount) . " " . htmlspecialchars($frequency) . "</li>"
    . "<li>Program: " . htmlspecialchars($program) . "</li>"
    . "<li>Handoff URL: <a href=\"" . htmlspecialchars($handoff) . "\">" . htmlspecialchars($handoff) . "</a></li>"
    . "</ul>"
    . "<p>DB id: $id</p>");

json_response(['ok' => true, 'id' => $id, 'handoff_url' => $handoff]);
