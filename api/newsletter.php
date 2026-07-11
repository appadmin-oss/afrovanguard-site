<?php
/**
 * STS newsletter subscribe — the footer opt-in on the static STS pages.
 * De-duplicates on email (INSERT OR IGNORE), tagged source 'sts'.
 *
 * POST (FormData): csrf_token, email  → { ok }
 */
require_once __DIR__ . '/../lib/bootstrap.php';
if (function_exists('send_security_headers')) send_security_headers('public');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'OPTIONS') { http_response_code(204); exit; }
if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required.'], 405);
if (!av_rate_ok('sts_newsletter', 20, 300)) json_out(['ok' => false, 'error' => 'Too many requests.'], 429);

// Honeypot.
if (trim((string) ($_POST['website'] ?? '')) !== '') json_out(['ok' => true]);
if (!av_csrf_valid((string) ($_POST['csrf_token'] ?? ''))) {
    json_out(['ok' => false, 'error' => 'Session expired — please reload and try again.'], 403);
}

$email = trim((string) ($_POST['email'] ?? ''));
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    json_out(['ok' => false, 'error' => 'Please enter a valid email.'], 422);
}

try {
    $pdo = Database::pdo();
    $st  = $pdo->prepare("INSERT OR IGNORE INTO subscribers (email, source) VALUES (?, 'sts')");
    $st->execute([mb_substr($email, 0, 190)]);
    av_emit_event('newsletter.subscribed', ['email' => $email, 'source' => 'sts']);
    json_out(['ok' => true]);
} catch (Throwable $e) {
    error_log('[newsletter] ' . $e->getMessage());
    json_out(['ok' => false, 'error' => 'Could not subscribe right now.'], 500);
}
