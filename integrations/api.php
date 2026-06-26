<?php
/**
 * integrations/api.php — the inbound integration API (v1).
 *
 * Lets trusted apps and other Afrovanguard sites call IN, server-to-server,
 * authenticated by a Bearer app token (Studio → Webhooks → API tokens) and
 * scoped to exactly what they may do. This is the partner to outbound webhooks:
 * together they make the site interoperable with modern tools (bots, bridges,
 * cron announcers, sister sites).
 *
 *   Authorization: Bearer av_int_xxxxxxxx…
 *
 *   GET  ?action=ping                                   any token
 *   GET  ?action=community.feed&space=&sort=&offset=    scope community:read
 *   POST ?action=community.post   {space, body}         scope community:bot  (posts as @Afrovanguard)
 *   POST ?action=community.reply  {id, body}            scope community:bot
 *   POST ?action=event            {type, data}          scope events:write
 *
 * Tokens are Bearer secrets, so there is no cookie and no CSRF surface; CORS is
 * open because every call must carry the token. Per-token-less rate limiting is
 * by client IP.
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_once AV_ROOT . '/lib/Community.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Authorization, Content-Type');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'OPTIONS') { http_response_code(204); exit; }

$action = (string) ($_GET['action'] ?? 'ping');
$body = [];
if ($method === 'POST') { $body = json_decode(file_get_contents('php://input') ?: '', true) ?: $_POST; }

/** Pull the Bearer token from the Authorization header (proxy-safe). */
function int_bearer(): string
{
    $h = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    if ($h === '' && function_exists('getallheaders')) {
        foreach (getallheaders() as $k => $v) { if (strcasecmp($k, 'Authorization') === 0) { $h = $v; break; } }
    }
    return str_starts_with($h, 'Bearer ') ? trim(substr($h, 7)) : '';
}

try {
    if (!av_rate_ok('integrations_api', 120, 60)) json_out(['ok' => false, 'error' => 'Rate limit exceeded.'], 429);

    $tok = AppTokens::verify(int_bearer());
    if (!$tok) json_out(['ok' => false, 'error' => 'Missing or invalid API token.'], 401);
    $need = function (string $scope) use ($tok) {
        if (!AppTokens::hasScope($tok, $scope)) json_out(['ok' => false, 'error' => 'This token lacks the "' . $scope . '" scope.'], 403);
    };

    switch ($action) {
        case 'ping':
            json_out(['ok' => true, 'name' => $tok['name'], 'scopes' => $tok['scope_list'], 'time' => gmdate('c')]);

        case 'community.feed': {
            $need('community:read');
            $space  = ($_GET['space'] ?? '') !== '' ? preg_replace('/[^a-z0-9\-]/', '', strtolower((string) $_GET['space'])) : null;
            $sort   = ($_GET['sort'] ?? '') === 'top' ? 'top' : 'latest';
            $offset = max(0, (int) ($_GET['offset'] ?? 0));
            $items  = Community::feed($space, $sort, 20, $offset, 0);
            json_out(['ok' => true, 'posts' => $items, 'offset' => $offset + count($items)]);
        }

        case 'community.post': {
            if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required.'], 405);
            $need('community:bot');
            $text = trim((string) ($body['body'] ?? ''));
            if (mb_strlen($text) < 2) json_out(['ok' => false, 'error' => 'Body too short.'], 422);
            $id = Community::botPost((string) ($body['space'] ?? 'announcements'), $text, (bool) ($body['pinned'] ?? false));
            if (!$id) json_out(['ok' => false, 'error' => 'Could not post.'], 422);
            json_out(['ok' => true, 'post' => Community::post($id)]);
        }

        case 'community.reply': {
            if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required.'], 405);
            $need('community:bot');
            $text = trim((string) ($body['body'] ?? ''));
            if (mb_strlen($text) < 1) json_out(['ok' => false, 'error' => 'Empty reply.'], 422);
            $rid = Community::reply(Community::botId(), (int) ($body['id'] ?? 0), $text);
            if (!$rid) json_out(['ok' => false, 'error' => 'Could not reply (post not found?).'], 422);
            json_out(['ok' => true, 'reply' => Community::post($rid)]);
        }

        case 'event': {
            if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required.'], 405);
            $need('events:write');
            $type = trim((string) ($body['type'] ?? ''));
            if (!preg_match('/^[a-z][a-z0-9_.\-]{1,60}$/i', $type)) json_out(['ok' => false, 'error' => 'Invalid event type.'], 422);
            $data = is_array($body['data'] ?? null) ? $body['data'] : [];
            $data['_source'] = 'integration:' . $tok['name'];
            Events::emit($type, $data);
            json_out(['ok' => true, 'emitted' => $type]);
        }

        default:
            json_out(['ok' => false, 'error' => 'Unknown action.'], 400);
    }
} catch (Throwable $e) {
    error_log('[integrations api] ' . $e->getMessage());
    json_out(['ok' => false, 'error' => av_is_prod() ? 'Server error.' : ('Server error: ' . $e->getMessage())], 500);
}
