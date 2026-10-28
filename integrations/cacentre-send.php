<?php
/**
 * integrations/cacentre-send.php — CACENTRE's email, sent by this site's mail.
 *
 *   GET                 → {ok, headroom, sender}   is this road open?
 *   POST {to, to_name, subject, html, text, from_name, reply_to, headers}
 *                       → {ok, road} or {ok:false, code, error}
 *
 * Signed by CACENTRE: header X-Cac-Token, checked by lib/CacInbound.php for
 * the "mail-send" door, bound to the request. See lib/CacSend.php.
 * Any failure to verify is the same short refusal. Server to server: no CORS.
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$out = static function (array $b, int $code = 200): void {
    http_response_code($code);
    echo json_encode($b, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
};

$method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');
if (!in_array($method, ['GET', 'POST'], true)) $out(['ok' => false, 'error' => 'GET or POST only.'], 405);
$body = $method === 'POST' ? (string) file_get_contents('php://input') : '';
$c = CacInbound::verify((string) ($_SERVER['HTTP_X_CAC_TOKEN'] ?? ''), $method === 'POST' ? $body : 'GET status', CacSend::AUD);
if (!is_array($c)) $out(['ok' => false, 'error' => $c === 'not-configured' ? 'not-configured' : 'refused'], $c === 'not-configured' ? 503 : 403);

if ($method === 'GET') {
    $out(['ok' => true, 'headroom' => CacSend::headroom(), 'cap' => CacSend::dailyCap(),
          'sender' => defined('FROM_EMAIL') ? (string) FROM_EMAIL : '', 'ready' => Mailer::configured()]);
}
$m = json_decode($body, true);
if (!is_array($m)) $out(['ok' => false, 'error' => 'bad-body'], 400);
try {
    $r = CacSend::send($m);
} catch (Throwable $e) {
    error_log('[cacentre-send] ' . $e->getMessage());
    $out(['ok' => false, 'code' => 'error', 'error' => 'Afrovanguard could not send it.'], 500);
}
$out($r, !empty($r['ok']) ? 200 : (($r['code'] ?? '') === 'daily_cap' ? 429 : 422));
