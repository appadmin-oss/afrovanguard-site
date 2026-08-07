<?php
/**
 * process-wish.php — deliver a PRIVATE birthday note to a celebrant.
 *
 * The birthday popup offers two ways to celebrate someone: post publicly in the
 * community, or send a private note. This endpoint powers the private path: a
 * visitor's message is emailed to the celebrant WITHOUT ever exposing their
 * address to the browser.
 *
 *   POST (JSON) { id, from, message, company? }
 *
 * Guardrails: only accepts a wish for a person whose birthday is TODAY (so it
 * can't be abused to message arbitrary staff), per-IP rate limited, with a
 * honeypot. It never reveals whether the person exists or has an email.
 */
declare(strict_types=1);
require_once __DIR__ . '/lib/bootstrap.php';
require_once AV_ROOT . '/lib/people.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

$allowed = ['https://afrovanguard.org.ng', 'https://www.afrovanguard.org.ng'];
$origin  = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, $allowed, true)) { header("Access-Control-Allow-Origin: {$origin}"); header('Vary: Origin'); }
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['ok' => false, 'error' => 'Method not allowed.']); exit; }

$in = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($in)) $in = [];

// Honeypot: silently accept (don't tip off bots) but do nothing.
if (trim((string) ($in['company'] ?? '')) !== '') { echo json_encode(['ok' => true]); exit; }

if (function_exists('av_rate_ok') && !av_rate_ok('birthday_wish', 12, 3600)) {
    http_response_code(429);
    echo json_encode(['ok' => false, 'error' => 'That’s a lot of love! Please try again in a little while.']);
    exit;
}

$id      = (int) ($in['id'] ?? 0);
$from    = mb_substr(trim((string) ($in['from'] ?? '')), 0, 60);
$message = mb_substr(trim((string) ($in['message'] ?? '')), 0, 1000);
if ($id <= 0 || mb_strlen($message) < 2) {
    echo json_encode(['ok' => false, 'error' => 'Please write a short birthday note.']);
    exit;
}

// Only today's celebrants can be wished — prevents arbitrary messaging of staff.
$person = null;
try {
    foreach (av_birthdays_on(Database::pdo()) as $p) {
        if ((int) ($p['id'] ?? 0) === $id) { $person = $p; break; }
    }
} catch (\Throwable $e) { error_log('[wish] lookup: ' . $e->getMessage()); }

if ($person && trim((string) ($person['email'] ?? '')) !== ''
    && class_exists('Notify') && method_exists('Notify', 'birthdayWish')) {
    Notify::birthdayWish($person, $from !== '' ? $from : 'A well-wisher', $message);
}

// Always report success — never leak whether the person exists / is reachable.
echo json_encode(['ok' => true]);
