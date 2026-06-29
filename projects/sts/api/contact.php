<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/ai.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/ratelimit.php';
require_once __DIR__ . '/mailer.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_error('Method not allowed', 405);

$step = $_GET['step'] ?? input_post('step', 'submit');
$ALLOWED_CATS = ['press','partnership','volunteer','general','complaint'];

if ($step === 'categorize') {
    rate_limit('contact.categorize', 30, 60);
    $message = input_post('message', '');
    if (strlen($message) < 8) json_response(['ok' => true, 'category' => 'general']);
    $prompt = "Classify this contact-form message into exactly ONE category from this list: press, partnership, volunteer, general, complaint.\n"
        . "Output ONLY the single category word. Message:\n" . substr($message, 0, 1500);
    $resp = ai_generate($prompt, ['timeout' => 6]);
    $cat = 'general';
    if ($resp) {
        $resp = strtolower(trim($resp));
        $resp = preg_replace('/[^a-z]/', '', $resp) ?? '';
        if (in_array($resp, $ALLOWED_CATS, true)) $cat = $resp;
    }
    json_response(['ok' => true, 'category' => $cat]);
}

// Submit
check_honeypot();
csrf_require();
rate_limit('contact.submit', 5, 300);

$name = require_field('full_name', input_post('full_name'));
$email = input_email('email') ?: json_error('Please enter a valid email address.', 422, 'email');
$message = require_field('message', input_post('message'));
$category = input_post('user_confirmed_category', 'general');
// Normalize aliases so the form can pass either id.
if ($category === 'partner') $category = 'partnership';
if (!in_array($category, $ALLOWED_CATS, true)) $category = 'general';

$ref = short_ref();

try {
    $pdo = db();
    $stmt = $pdo->prepare("INSERT INTO contact_messages
        (full_name, email, subject, message, ai_category, user_confirmed_category, routed_to)
        VALUES (:n, :e, :s, :m, :ai, :uc, :r)");
    $routed = inbox_for($category === 'partnership' ? 'partner' : $category);
    $stmt->execute([
        ':n' => $name, ':e' => $email,
        ':s' => "STS Contact · $category · $ref",
        ':m' => substr($message, 0, 10000),
        ':ai' => $category, ':uc' => $category, ':r' => $routed,
    ]);
    $id = (int)$pdo->lastInsertId();
} catch (Throwable $e) {
    log_line('db', 'contact insert failed', ['err' => $e->getMessage()]);
    json_error('We could not save your message. Please try again or email hello@streettostardom.org.', 500);
}

send_email($email, "We got your message — Street-To-Stardom (#$ref)",
    "<p>Hi " . htmlspecialchars(explode(' ', $name)[0]) . ",</p>"
    . "<p>Thanks for getting in touch. We've routed your message to the relevant team and will reply within two business days.</p>"
    . "<p>Reference: <strong>$ref</strong></p>"
    . "<p>— The STS team</p>");

send_email($routed,
    "Contact · $category · $name · #$ref",
    "<p>Contact form submission.</p>"
    . "<ul>"
    . "<li>Name: " . htmlspecialchars($name) . "</li>"
    . "<li>Email: " . htmlspecialchars($email) . "</li>"
    . "<li>Category: " . htmlspecialchars($category) . "</li>"
    . "<li>Reference: $ref</li>"
    . "</ul>"
    . "<p><strong>Message:</strong><br>" . nl2br(htmlspecialchars($message)) . "</p>"
    . "<p>DB id: $id</p>");

json_response(['ok' => true, 'id' => $id, 'reference' => $ref, 'routed_to' => $routed]);
