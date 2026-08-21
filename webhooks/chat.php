<?php
/**
 * webhooks/chat.php — receiver for Google Chat app events.
 *
 * Google POSTs a JSON event here whenever someone messages the Chat app, and
 * whatever this returns as JSON is what the app says back in the space. The
 * whole exchange is one request: there is no callback, and Chat gives roughly
 * 30 seconds before it gives up, which is why the parse is deterministic first
 * and only reaches for a model to fill a gap.
 *
 * Authentication is a bearer JWT Google signs with a published key —
 * ChatBot::verifyBearer() checks it. Unlike webhooks/google.php, which is
 * authenticated by an unguessable channel id we issued, here the credential
 * comes from Google and has to be verified properly: the signature, the
 * algorithm, the issuer, and the audience, which is the claim that stops a
 * token minted for somebody else's Chat app being replayed at ours.
 *
 * Fails closed. No audience configured, a bad signature, or the bot switched
 * off in the rules all return 401/403 with nothing written and nothing leaked
 * about which check failed.
 *
 * Served at /webhooks/chat (see .htaccess + router.php).
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

$say = static function (array $body, int $code = 200): void {
    http_response_code($code);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
};

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') $say(['error' => 'POST required'], 405);

// Switched off, or unable to verify what it receives. Either way it must not
// accept the request: a bot that processes unverified events is a bot anyone
// on the internet can file tasks with.
if (!ChatBot::enabled()) $say(['error' => 'not configured'], 403);

$auth = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
$v = ChatBot::verifyBearer($auth);
if (!$v['ok']) {
    // Logged in full, answered in one word. The detail belongs in the server
    // log where an administrator can see it, not in a response body where it
    // tells whoever is probing which check they still have to beat.
    error_log('[webhook chat] rejected: ' . $v['error']);
    $say(['error' => 'unauthorized'], 401);
}

$raw = (string) file_get_contents('php://input');
if (strlen($raw) > 262144) $say(['error' => 'payload too large'], 413);
$event = json_decode($raw, true);
if (!is_array($event)) $say(['error' => 'bad request'], 400);

try {
    $reply = ChatBot::handleEvent($event);
} catch (Throwable $e) {
    error_log('[webhook chat] ' . $e->getMessage());
    // 200 with an apology, not a 500. A 5xx makes Chat retry, and a retried
    // task-filing request is a duplicate task — the dedupe key would catch it,
    // but the person still gets told twice.
    $say(['text' => 'Something went wrong on my side. Nothing was recorded — please try again.']);
}

$text = trim((string) ($reply['text'] ?? ''));
// An empty reply means "acknowledge and stay quiet" (a REMOVED_FROM_SPACE
// event, say). Chat accepts an empty object for that.
$say($text === '' ? [] : ['text' => $text]);
