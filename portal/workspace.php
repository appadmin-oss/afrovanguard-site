<?php
/**
 * portal/workspace.php — live Google Workspace data for the member portal.
 *
 * Members-only JSON: real upcoming Calendar events, real Drive files, and (with
 * domain-wide delegation) the user directory — pulled server-side via
 * GoogleWorkspace. Fetched progressively by the portal so a slow/missing Google
 * never blocks the page; an unconfigured Workspace simply returns configured=false
 * and the portal keeps its launchpad + embeds.
 *
 *   GET ?action=events     → {ok, configured, events:[…]}
 *   GET ?action=files      → {ok, configured, files:[…]}
 *   GET ?action=directory  → {ok, configured, users:[…]}  (admins only)
 *   GET ?action=me         → {ok, oauth, connected, mine:{unread,mail,events,files}}
 *                            (PER-USER: the member's OWN Gmail/Calendar/Drive)
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/bootstrap.php';

$u = LmsAuth::user();
if (!$u) json_out(['ok' => false, 'error' => 'Please sign in.'], 401);
if (!LmsAuth::isOrgMember($u)) json_out(['ok' => false, 'error' => 'Members only.'], 403);

$configured = GoogleWorkspace::configured();
$action = (string) ($_GET['action'] ?? 'events');

try {
    switch ($action) {
        case 'events':
            json_out(['ok' => true, 'configured' => $configured, 'events' => $configured ? GoogleWorkspace::calendarEvents(null, 8) : []]);
        case 'files':
            json_out(['ok' => true, 'configured' => $configured, 'files' => $configured ? GoogleWorkspace::driveFiles(null, 12) : []]);
        case 'directory':
            if (!LmsAuth::atLeast($u, 'admin')) json_out(['ok' => false, 'error' => 'Admins only.'], 403);
            json_out(['ok' => true, 'configured' => $configured, 'users' => $configured ? GoogleWorkspace::directoryUsers(200) : []]);
        case 'me':
            // PER-USER: the member's own Google Workspace, via their connected account.
            $oauth = GoogleWorkspaceUser::configured();
            if (!$oauth || !GoogleWorkspaceUser::connected((int) $u['id'])) {
                json_out(['ok' => true, 'oauth' => $oauth, 'connected' => false, 'mine' => null]);
            }
            json_out(['ok' => true, 'oauth' => true, 'connected' => true, 'mine' => GoogleWorkspaceUser::snapshot((int) $u['id'])]);

        /* ── Google Chat (live, via the member's own connection) ── */
        case 'chat_spaces': {
            if (!GoogleWorkspaceUser::connected((int) $u['id'])) json_out(['ok' => false, 'connected' => false, 'error' => 'Connect Google to use Chat.'], 200);
            json_out(['ok' => true, 'connected' => true, 'spaces' => GoogleWorkspaceUser::chatSpaces((int) $u['id'])]);
        }
        case 'chat_unread': {
            if (!GoogleWorkspaceUser::connected((int) $u['id'])) json_out(['ok' => false, 'connected' => false], 200);
            json_out(['ok' => true, 'connected' => true, 'unread' => GoogleWorkspaceUser::chatUnreadMap((int) $u['id'], 20)]);
        }
        case 'chat_messages': {
            if (!GoogleWorkspaceUser::connected((int) $u['id'])) json_out(['ok' => false, 'connected' => false], 200);
            $sp = (string) ($_GET['space'] ?? '');
            if ($sp === '') json_out(['ok' => false, 'error' => 'space required'], 400);
            json_out(['ok' => true, 'connected' => true, 'messages' => GoogleWorkspaceUser::chatMessages((int) $u['id'], $sp, 40)]);
        }
        case 'chat_send': {
            if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') json_out(['ok' => false, 'error' => 'POST required.'], 405);
            require_same_origin();
            if (!av_csrf_valid((string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))) json_out(['ok' => false, 'error' => 'Bad token.'], 403);
            if (!GoogleWorkspaceUser::connected((int) $u['id'])) json_out(['ok' => false, 'error' => 'Not connected.'], 403);
            if (!av_rate_ok('chat_send_' . (int) $u['id'], 30, 300)) json_out(['ok' => false, 'error' => 'Slow down a moment.'], 429);
            $b = json_decode((string) file_get_contents('php://input'), true) ?: [];
            $ok = GoogleWorkspaceUser::chatSend((int) $u['id'], (string) ($b['space'] ?? ''), (string) ($b['text'] ?? ''));
            if ($ok) json_out(['ok' => true]);
            // Explain WHY. A 401/403 from Google almost always means the member's
            // Google connection predates the "post to Chat" permission — they need
            // to reconnect to grant it.
            $code = GoogleWorkspaceUser::lastHttpCode();
            $needsReconnect = in_array($code, [401, 403], true);
            json_out([
                'ok' => false,
                'reconnect' => $needsReconnect,
                'error' => $needsReconnect
                    ? 'Reconnect your Google account to allow posting to Chat.'
                    : ('Could not send' . ($code ? ' (Google ' . $code . ').' : '.')),
            ], 502);
        }
        default:
            json_out(['ok' => false, 'error' => 'Unknown action.'], 400);
    }
} catch (Throwable $e) {
    error_log('[portal workspace] ' . $e->getMessage());
    json_out(['ok' => false, 'error' => 'Server error.'], 500);
}
