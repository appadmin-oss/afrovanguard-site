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
rate_limit('partner.submit', 5, 300);

$orgName = require_field('org_name', input_post('org_name'));
$orgType = input_post('org_type', 'school');
$contactName = require_field('contact_name', input_post('contact_name'));
$contactRole = input_post('contact_role', '');
$email = input_email('email') ?: json_error('Please enter a valid email address.', 422, 'email');
$phone = input_post('phone', '');
$interest = require_field('partnership_interest', input_post('partnership_interest'));
$approved = input_post('user_approved_proposal', '');

$allowed = ['school','ngo','corporate','government','faith_based'];
if (!in_array($orgType, $allowed, true)) json_error('Invalid org_type', 422, 'org_type');

try {
    $pdo = db();
    $stmt = $pdo->prepare("INSERT INTO partners
        (org_name, org_type, contact_name, contact_role, email, phone, partnership_interest, ai_proposal_draft, user_approved_proposal)
        VALUES (:on, :ot, :cn, :cr, :e, :p, :pi, :ad, :ap)");
    $stmt->execute([
        ':on' => $orgName, ':ot' => $orgType,
        ':cn' => $contactName, ':cr' => $contactRole,
        ':e' => $email, ':p' => $phone,
        ':pi' => $interest, ':ad' => $approved, ':ap' => $approved,
    ]);
    $id = (int)$pdo->lastInsertId();
} catch (Throwable $e) {
    log_line('db', 'partner insert failed', ['err' => $e->getMessage()]);
    json_error('We could not save your proposal. Please try again or email partnerships@streettostardom.org.', 500);
}

send_email($email, 'Proposal received · Street-To-Stardom',
    "<p>Hi " . htmlspecialchars(explode(' ', $contactName)[0]) . ",</p>"
    . "<p>Thanks for reaching out on behalf of " . htmlspecialchars($orgName) . ". Our partnerships team will review your proposal and reply within five business days.</p>"
    . "<p>— The STS team</p>");

send_email(inbox_for('partner'),
    "New partnership · " . $orgType . " · " . $orgName,
    "<p>New partnership proposal received.</p>"
    . "<ul>"
    . "<li>Org: " . htmlspecialchars($orgName) . " (" . htmlspecialchars($orgType) . ")</li>"
    . "<li>Contact: " . htmlspecialchars($contactName) . " · " . htmlspecialchars($contactRole) . "</li>"
    . "<li>Email: " . htmlspecialchars($email) . "</li>"
    . "<li>Phone: " . htmlspecialchars($phone) . "</li>"
    . "</ul>"
    . "<p><strong>Interest:</strong><br>" . nl2br(htmlspecialchars($interest)) . "</p>"
    . "<p><strong>Approved proposal:</strong><br>" . nl2br(htmlspecialchars($approved)) . "</p>"
    . "<p>DB id: $id</p>");

json_response(['ok' => true, 'id' => $id]);
