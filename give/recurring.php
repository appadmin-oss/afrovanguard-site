<?php
/**
 * give/recurring.php — start a recurring gift to an appeal.
 *
 * POST slug, email, amount, interval → a Paystack authorization URL.
 *
 * It is a POST and a redirect rather than a form that posts straight to
 * Paystack, because the Plan has to exist before a subscription can, and
 * creating it is a server-side call with our secret key on it.
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') { http_response_code(405); echo json_encode(['ok' => false, 'error' => 'POST only.']); exit; }
if (function_exists('require_same_origin')) require_same_origin();

/* Money is about to move, so this one is throttled hard — and per IP, because
   there is no session here. A legitimate donor sets up one subscription. */
if (function_exists('av_rate_ok') && !av_rate_ok('give_recurring', 8, 600)) {
    http_response_code(429);
    echo json_encode(['ok' => false, 'error' => 'Too many attempts. Wait a few minutes and try again.']);
    exit;
}

$in = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($in)) $in = $_POST;

$slug = preg_replace('/[^a-z0-9\-]/', '', strtolower((string) ($in['slug'] ?? '')));
$a = $slug !== '' ? Appeals::bySlug($slug) : null;
if (!$a) { http_response_code(404); echo json_encode(['ok' => false, 'error' => 'No such appeal.']); exit; }

$r = Appeals::startRecurring(
    (int) $a['id'],
    trim((string) ($in['email'] ?? '')),
    (int) ($in['amount'] ?? 0),
    (string) ($in['interval'] ?? 'monthly'),
    (string) ($in['name'] ?? '')
);
http_response_code(empty($r['ok']) ? 400 : 200);
echo json_encode($r);
