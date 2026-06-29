<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/ai.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/ratelimit.php';
require_once __DIR__ . '/mailer.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_error('Method not allowed', 405);

$action = input_post('action', 'submit');

if ($action === 'suggest_skills') {
    rate_limit('volunteer.suggest', 20, 60);
    $role = input_post('role', '');
    $text = input_post('motivation', '');
    $allowed = ['teaching','mentoring','public_speaking','writing','design','photography','videography','social_media','fundraising','event_planning','administration','counseling','tech_support','data_analysis','translation'];
    $prompt = "Extract relevant volunteer skills from the user's note for a volunteer role.\n"
        . "Return ONLY a JSON array of strings from this exact set:\n"
        . json_encode($allowed) . "\n"
        . "Role: $role\nNote: " . substr($text, 0, 600);
    $resp = ai_generate($prompt, ['json' => true, 'timeout' => 8]);
    $arr = $resp ? (ai_extract_json_array($resp) ?? []) : [];
    $arr = array_values(array_intersect($arr, $allowed));
    json_response(['ok' => true, 'skills' => $arr]);
}

// Final submit
check_honeypot();
csrf_require();
rate_limit('volunteer.submit', 5, 300);

$name = require_field('full_name', input_post('full_name'));
$email = input_email('email') ?: json_error('Please enter a valid email address.', 422, 'email');
$phone = input_post('phone', '');
$role = require_field('role_applied', input_post('role_applied'));
$motivation = input_post('motivation_text', '');
$skillsJson = input_post('skills', '[]');
$skills = json_decode($skillsJson ?: '[]', true);
if (!is_array($skills)) $skills = [];

$allowedRoles = ['instructor','mentor','fundraiser','creator','admin'];
if (!in_array($role, $allowedRoles, true)) json_error('Invalid role', 422, 'role_applied');
if (strlen($name) > 120) json_error('Name too long', 422, 'full_name');
if (strlen($motivation) > 2000) $motivation = substr($motivation, 0, 2000);

try {
    $pdo = db();
    $stmt = $pdo->prepare("INSERT INTO volunteers
        (full_name, email, phone, role_applied, motivation_text, skills, availability, ai_parsed_skills, source_url, ip_address)
        VALUES (:n, :e, :p, :r, :m, :s, :a, :ai, :u, :ip)");
    $stmt->execute([
        ':n' => $name,
        ':e' => $email,
        ':p' => $phone,
        ':r' => $role,
        ':m' => $motivation,
        ':s' => json_encode(array_values($skills)),
        ':a' => json_encode([]),
        ':ai' => json_encode(array_values($skills)),
        ':u' => $_SERVER['HTTP_REFERER'] ?? '',
        ':ip' => client_ip(),
    ]);
    $id = (int)$pdo->lastInsertId();
} catch (Throwable $e) {
    log_line('db', 'volunteer insert failed', ['err' => $e->getMessage()]);
    json_error('We could not save your application. Please try again or email volunteers@streettostardom.org.', 500);
}

// Emails (best-effort; don't fail the request if mail fails)
$skillsList = $skills ? implode(', ', $skills) : '—';
send_email($email, 'We got your application — Street-To-Stardom',
    "<p>Hi " . htmlspecialchars(explode(' ', $name)[0]) . ",</p>"
    . "<p>Thanks for applying to volunteer with Street-To-Stardom. A named member of our volunteer team will be in touch within two business days.</p>"
    . "<p><strong>Your application</strong><br>Role: " . htmlspecialchars(ucfirst($role)) . "<br>Skills: " . htmlspecialchars($skillsList) . "</p>"
    . "<p>— The STS team</p>");

send_email(inbox_for('volunteer'),
    "New volunteer · {$role} · {$name}",
    "<p>New volunteer application received.</p>"
    . "<ul>"
    . "<li>Name: " . htmlspecialchars($name) . "</li>"
    . "<li>Email: " . htmlspecialchars($email) . "</li>"
    . "<li>Phone: " . htmlspecialchars($phone) . "</li>"
    . "<li>Role: " . htmlspecialchars($role) . "</li>"
    . "<li>Skills: " . htmlspecialchars($skillsList) . "</li>"
    . "</ul>"
    . "<p><strong>Motivation:</strong><br>" . nl2br(htmlspecialchars($motivation)) . "</p>"
    . "<p>DB id: $id</p>");

json_response(['ok' => true, 'id' => $id]);
