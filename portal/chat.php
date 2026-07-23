<?php
/**
 * portal/chat.php — the Slack-style Team Chat API.
 *
 *   GET  ?action=bootstrap&channel=general       → { channels, messages, online, react_emoji }
 *   GET  ?action=poll&channel=general&since=ID    → { messages, online, channels }
 *   GET  ?action=mention&q=ad                      → { members:[…] }
 *   POST ?action=send   {channel, body}            → { message }
 *   POST ?action=react  {id, emoji}                → { id, reactions }
 *
 * Org members only (native team chat). Writes are same-origin + CSRF +
 * rate-limited, matching the rest of the portal's endpoints.
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_once AV_ROOT . '/lib/Community.php';

header('Content-Type: application/json; charset=utf-8');

$u = LmsAuth::user();
if (!$u) json_out(['ok' => false, 'error' => 'Please sign in.'], 401);
if (!LmsAuth::isOrgMember($u)) json_out(['ok' => false, 'error' => 'Team chat is for Afrovanguard members.'], 403);

$uid    = (int) $u['id'];
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = (string) ($_GET['action'] ?? 'bootstrap');
$body   = ($method === 'POST') ? (json_decode((string) file_get_contents('php://input'), true) ?: []) : [];

$writeGuard = function () use ($method) {
    if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required.'], 405);
    require_same_origin();
    av_csrf_require();
};

try {
    switch ($action) {
        case 'bootstrap':
            Collab::heartbeat($uid);
            $channel = (string) ($_GET['channel'] ?? 'general');
            json_out([
                'ok'          => true,
                'channels'    => Community::chatChannels(),
                'messages'    => Community::chatList($uid, 0, 50, $channel),
                'online'      => Collab::onlineUsers(40),
                'count'       => Collab::onlineCount(),
                'react_emoji' => Community::REACT_EMOJI,
                'me'          => ['id' => $uid, 'name' => (string) $u['name']],
            ]);

        case 'poll':
            Collab::heartbeat($uid);
            $channel = (string) ($_GET['channel'] ?? 'general');
            $since   = (int) ($_GET['since'] ?? 0);
            json_out([
                'ok'       => true,
                'messages' => Community::chatList($uid, $since, 50, $channel),
                'online'   => Collab::onlineUsers(40),
                'count'    => Collab::onlineCount(),
                'channels' => Community::chatChannels(),
            ]);

        case 'mention':
            json_out(['ok' => true, 'members' => Community::mentionSearch((string) ($_GET['q'] ?? ''), $uid, 8)]);

        case 'thread':
            $parent = (int) ($_GET['parent'] ?? 0);
            json_out(['ok' => true, 'parent' => $parent, 'replies' => Community::chatThread($uid, $parent, (int) ($_GET['since'] ?? 0))]);

        case 'send':
            $writeGuard();
            if (!av_rate_ok('chat_send_' . $uid, 30, 60)) json_out(['ok' => false, 'error' => 'You’re sending very fast — slow down a moment.'], 429);
            $msg = Community::chatSend($uid, (string) ($body['body'] ?? ''), (string) ($body['channel'] ?? 'general'), (int) ($body['parent_id'] ?? 0));
            if (!$msg) json_out(['ok' => false, 'error' => 'Type a message.'], 400);
            json_out(['ok' => true, 'message' => $msg]);

        case 'assign':
            // Turn a chat message into an assigned task — the @mention → assignment bridge.
            $writeGuard();
            if (!av_rate_ok('collab_task_' . $uid, 40, 600)) json_out(['ok' => false, 'error' => 'Slow down a moment.'], 429);
            $assignee = (int) ($body['assignee'] ?? 0);
            $id = Collab::addTask($uid, (string) ($body['title'] ?? ''), $assignee, (string) ($body['due'] ?? ''), (string) ($body['priority'] ?? 'normal'));
            if (!$id) json_out(['ok' => false, 'error' => 'Enter a task title.'], 400);
            json_out(['ok' => true, 'task' => Collab::oneTask($uid, $id), 'assigned_to' => $assignee]);

        case 'react':
            $writeGuard();
            $rx = Community::chatReact($uid, (int) ($body['id'] ?? 0), (string) ($body['emoji'] ?? ''));
            if ($rx === null) json_out(['ok' => false, 'error' => 'Could not react.'], 400);
            json_out(['ok' => true, 'id' => (int) $body['id'], 'reactions' => $rx]);

        default:
            json_out(['ok' => false, 'error' => 'Unknown action.'], 400);
    }
} catch (Throwable $e) {
    error_log('[portal chat] ' . $e->getMessage());
    json_out(['ok' => false, 'error' => av_is_prod() ? 'Server error.' : ('Server error: ' . $e->getMessage())], 500);
}
