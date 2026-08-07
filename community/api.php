<?php
/**
 * community/api.php — JSON API for the Afrovanguard Community.
 *
 *   GET  ?action=feed&space=<slug|>&sort=top|latest&offset=N   → posts + has_more
 *   GET  ?action=replies&id=<postId>                            → replies
 *   POST ?action=post   {space, body}                           → create (members)
 *   POST ?action=reply  {id, body}                              → reply  (members)
 *   POST ?action=like   {id}                                    → toggle (members)
 *
 * Reads are public; writes require a signed-in member (LmsAuth). The LMS session
 * cookie is SameSite=Lax, so cross-site POSTs can't carry it — that plus an
 * Origin check is the CSRF defence. Per-IP rate limited.
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_once AV_ROOT . '/lib/Community.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'OPTIONS') { http_response_code(204); exit; }

$action = (string) ($_GET['action'] ?? 'feed');
$body = [];
if ($method === 'POST') { $body = json_decode(file_get_contents('php://input') ?: '', true) ?: $_POST; }

/**
 * Same-origin guard for writes (defence-in-depth on top of the Lax cookie).
 * Host-relative — compares the Origin/Referer host to the request host — so it
 * works on ANY deployment host (production, preview, staging, local), exactly
 * like require_same_origin() elsewhere in the app. (The old check hardcoded the
 * production domain, which 403'd every write on any other host.)
 */
function comm_same_origin(): bool
{
    $host = $_SERVER['HTTP_HOST'] ?? '';
    $o = $_SERVER['HTTP_ORIGIN'] ?? ($_SERVER['HTTP_REFERER'] ?? '');
    if ($o === '') return true; // no Origin/Referer (same-origin fetch / curl) — cookie+Lax still gates
    $oh = parse_url($o, PHP_URL_HOST) ?: '';
    if ($oh === '' || $host === '') return true;
    return stripos($host, $oh) !== false || stripos($oh, $host) !== false;
}

try {
    $PER = 12;
    switch ($action) {
        case 'feed': {
            $space  = ($_GET['space'] ?? '') !== '' ? preg_replace('/[^a-z0-9\-]/', '', strtolower((string) $_GET['space'])) : null;
            $sort   = ($_GET['sort'] ?? '') === 'top' ? 'top' : 'latest';
            $offset = max(0, (int) ($_GET['offset'] ?? 0));
            $u      = LmsAuth::user();
            $items  = Community::feed($space, $sort, $PER + 1, $offset, $u ? (int) $u['id'] : 0);
            $hasMore = count($items) > $PER;
            json_out(['ok' => true, 'posts' => array_slice($items, 0, $PER), 'has_more' => $hasMore, 'offset' => $offset + $PER]);
        }
        case 'replies': {
            $u = LmsAuth::user();
            json_out(['ok' => true, 'replies' => Community::replies((int) ($_GET['id'] ?? 0), $u ? (int) $u['id'] : 0)]);
        }
        case 'post': {
            if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required.'], 405);
            if (!comm_same_origin()) json_out(['ok' => false, 'error' => 'Bad origin.'], 403);
            $u = LmsAuth::user();
            if (!$u) json_out(['ok' => false, 'error' => 'Please sign in to post.'], 401);
            if (!av_rate_ok('community_post', 20, 900)) json_out(['ok' => false, 'error' => 'You’re posting quickly — give it a moment.'], 429);
            $bodyText = trim((string) ($body['body'] ?? ''));
            if (mb_strlen($bodyText) < 2) json_out(['ok' => false, 'error' => 'Write a little more.'], 422);
            $id = Community::createPost((int) $u['id'], (string) ($body['space'] ?? 'open-floor'), $bodyText, null, false, (string) ($body['classification'] ?? 'members'));
            if (!$id) json_out(['ok' => false, 'error' => 'Could not post.'], 422);
            json_out(['ok' => true, 'post' => Community::post($id, (int) $u['id'])]);
        }
        case 'reply': {
            if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required.'], 405);
            if (!comm_same_origin()) json_out(['ok' => false, 'error' => 'Bad origin.'], 403);
            $u = LmsAuth::user();
            if (!$u) json_out(['ok' => false, 'error' => 'Please sign in to reply.'], 401);
            if (!av_rate_ok('community_reply', 40, 900)) json_out(['ok' => false, 'error' => 'Slow down a touch.'], 429);
            $bodyText = trim((string) ($body['body'] ?? ''));
            if (mb_strlen($bodyText) < 1) json_out(['ok' => false, 'error' => 'Empty reply.'], 422);
            $rid = Community::reply((int) $u['id'], (int) ($body['id'] ?? 0), $bodyText);
            if (!$rid) json_out(['ok' => false, 'error' => 'Could not reply.'], 422);
            json_out(['ok' => true, 'reply' => Community::post($rid, (int) $u['id'])]);
        }
        case 'like': {
            if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required.'], 405);
            if (!comm_same_origin()) json_out(['ok' => false, 'error' => 'Bad origin.'], 403);
            $u = LmsAuth::user();
            if (!$u) json_out(['ok' => false, 'error' => 'Please sign in.'], 401);
            if (!av_rate_ok('community_like', 120, 900)) json_out(['ok' => false, 'error' => 'Slow down a touch.'], 429);
            json_out(['ok' => true] + Community::toggleLike((int) ($body['id'] ?? 0), (int) $u['id']));
        }
        case 'mod_delete': {
            if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required.'], 405);
            if (!comm_same_origin()) json_out(['ok' => false, 'error' => 'Bad origin.'], 403);
            $u = LmsAuth::user();
            if (!$u) json_out(['ok' => false, 'error' => 'Please sign in.'], 401);
            if (!av_rate_ok('community_mod_' . (int) $u['id'], 60, 600)) json_out(['ok' => false, 'error' => 'Slow down a moment.'], 429);
            $ok = Community::moderateDelete((int) $u['id'], (int) ($body['id'] ?? 0));
            json_out(['ok' => $ok] + ($ok ? [] : ['error' => 'Not allowed.']), $ok ? 200 : 403);
        }
        case 'mod_pin': {
            if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required.'], 405);
            if (!comm_same_origin()) json_out(['ok' => false, 'error' => 'Bad origin.'], 403);
            $u = LmsAuth::user();
            if (!$u || !Community::isAdmin((int) $u['id'])) json_out(['ok' => false, 'error' => 'Moderators only.'], 403);
            $ok = Community::moderatePin((int) $u['id'], (int) ($body['id'] ?? 0), (bool) ($body['pin'] ?? true));
            json_out(['ok' => $ok]);
        }
        case 'mod_classify': {
            if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required.'], 405);
            if (!comm_same_origin()) json_out(['ok' => false, 'error' => 'Bad origin.'], 403);
            $u = LmsAuth::user();
            if (!$u || !Community::isAdmin((int) $u['id'])) json_out(['ok' => false, 'error' => 'Moderators only.'], 403);
            $ok = Community::moderateClassify((int) $u['id'], (int) ($body['id'] ?? 0), (string) ($body['classification'] ?? 'members'));
            json_out(['ok' => $ok]);
        }
        case 'announce': {
            if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required.'], 405);
            if (!comm_same_origin()) json_out(['ok' => false, 'error' => 'Bad origin.'], 403);
            $u = LmsAuth::user();
            if (!$u || !Community::isAdmin((int) $u['id'])) json_out(['ok' => false, 'error' => 'Moderators only.'], 403);
            if (!av_rate_ok('community_announce_' . (int) $u['id'], 20, 900)) json_out(['ok' => false, 'error' => 'Slow down a moment.'], 429);
            $id = Community::announce((int) $u['id'], (string) ($body['space'] ?? 'announcements'), (string) ($body['body'] ?? ''), (bool) ($body['pin'] ?? false));
            if (!$id) json_out(['ok' => false, 'error' => 'Write an announcement.'], 422);
            json_out(['ok' => true, 'post' => Community::post($id, (int) $u['id'])]);
        }
        case 'ask': {
            // Ask the official Afrovanguard bot (AI). Posts the member's question,
            // then the bot's Claude-generated reply, in the same thread.
            if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required.'], 405);
            if (!comm_same_origin()) json_out(['ok' => false, 'error' => 'Bad origin.'], 403);
            $u = LmsAuth::user();
            if (!$u) json_out(['ok' => false, 'error' => 'Please sign in to ask.'], 401);
            if (!av_rate_ok('community_ask', 10, 900)) json_out(['ok' => false, 'error' => 'You’re asking quickly — give it a moment.'], 429);
            $q = trim((string) ($body['body'] ?? ''));
            if (mb_strlen($q) < 3) json_out(['ok' => false, 'error' => 'Ask a fuller question.'], 422);
            $uid = (int) $u['id'];

            $threadId = (int) ($body['id'] ?? 0);

            // Capture the existing thread as context BEFORE adding this question,
            // so the bot doesn't receive it twice (once as history, once as prompt).
            $history = [];
            if ($threadId > 0) {
                $root = Community::post($threadId);
                if ($root) $history[] = ['role' => $root['is_bot'] ? 'bot' : 'member', 'name' => $root['author'], 'text' => $root['body']];
                foreach (Community::replies($threadId) as $r) {
                    $history[] = ['role' => $r['is_bot'] ? 'bot' : 'member', 'name' => $r['author'], 'text' => $r['body']];
                }
            }

            // Anchor the question: a reply in an existing thread, or a new post.
            if ($threadId > 0) {
                $qid = Community::reply($uid, $threadId, $q);
                if (!$qid) json_out(['ok' => false, 'error' => 'Could not post your question.'], 422);
                $parent = $threadId;
            } else {
                $qid = Community::createPost($uid, (string) ($body['space'] ?? 'open-floor'), $q);
                if (!$qid) json_out(['ok' => false, 'error' => 'Could not post your question.'], 422);
                $parent = $qid;
            }
            $question = Community::post($qid, $uid);

            // Ask Claude with the prior thread as context, then post the reply.
            $ai = AvBot::reply($q, $history);
            $botPost = null;
            if ($ai['ok']) {
                $brid = Community::reply(Community::botId(), $parent, $ai['text']);
                if ($brid) $botPost = Community::post($brid, $uid);
            }
            json_out([
                'ok'       => true,
                'question' => $question,
                'parent'   => $parent,
                'bot'      => $botPost,
                'ai_ok'    => (bool) $ai['ok'],
                'note'     => $ai['ok'] ? null : (AvBot::configured() ? 'The bot couldn’t answer just now.' : 'The AI bot isn’t enabled yet — a teammate will follow up.'),
            ]);
        }
        /* ── Org-only: member directory + live chat + @mentions ── */
        case 'directory': {
            $u = LmsAuth::user();
            if (!$u) json_out(['ok' => false, 'error' => 'Please sign in.'], 401);
            if (!Community::isOrgMember((int) $u['id'])) json_out(['ok' => false, 'error' => 'Members-only.'], 403);
            json_out(['ok' => true, 'members' => Community::directory((int) $u['id'])]);
        }
        case 'mention_search': {
            $u = LmsAuth::user();
            if (!$u) json_out(['ok' => false, 'error' => 'Please sign in.'], 401);
            if (!Community::isOrgMember((int) $u['id'])) json_out(['ok' => false, 'error' => 'Members-only.'], 403);
            json_out(['ok' => true, 'matches' => Community::mentionSearch((string) ($_GET['q'] ?? ''), (int) $u['id'])]);
        }
        case 'chat_list': {
            $u = LmsAuth::user();
            if (!$u) json_out(['ok' => false, 'error' => 'Please sign in.'], 401);
            if (!Community::isOrgMember((int) $u['id'])) json_out(['ok' => false, 'error' => 'Members-only.'], 403);
            json_out(['ok' => true, 'channels' => Community::chatChannelLabels(), 'messages' => Community::chatList((int) $u['id'], (int) ($_GET['since'] ?? 0), 50, (string) ($_GET['channel'] ?? 'general'))]);
        }
        case 'chat_send': {
            if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required.'], 405);
            if (!comm_same_origin()) json_out(['ok' => false, 'error' => 'Bad origin.'], 403);
            $u = LmsAuth::user();
            if (!$u) json_out(['ok' => false, 'error' => 'Please sign in to chat.'], 401);
            if (!Community::isOrgMember((int) $u['id'])) json_out(['ok' => false, 'error' => 'The members chat is for Afrovanguard members.'], 403);
            if (!av_rate_ok('community_chat', 60, 300)) json_out(['ok' => false, 'error' => 'Slow down a touch.'], 429);
            $msg = Community::chatSend((int) $u['id'], (string) ($body['body'] ?? ''), (string) ($body['channel'] ?? 'general'));
            if (!$msg) json_out(['ok' => false, 'error' => 'Write a message first.'], 422);
            json_out(['ok' => true, 'message' => $msg]);
        }
        case 'to_task': {
            // Turn a chat message or post into a task (org members) — chat ⇄ work bridge.
            if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required.'], 405);
            if (!comm_same_origin()) json_out(['ok' => false, 'error' => 'Bad origin.'], 403);
            $u = LmsAuth::user();
            if (!$u) json_out(['ok' => false, 'error' => 'Please sign in.'], 401);
            if (!Community::isOrgMember((int) $u['id'])) json_out(['ok' => false, 'error' => 'Members-only.'], 403);
            if (!class_exists('Collab')) { require_once AV_ROOT . '/lib/Collab.php'; }
            if (!av_rate_ok('community_totask_' . (int) $u['id'], 30, 600)) json_out(['ok' => false, 'error' => 'Slow down a moment.'], 429);
            $title = trim(mb_substr((string) ($body['body'] ?? ''), 0, 300));
            $id = $title !== '' ? Collab::addTask((int) $u['id'], $title) : 0;
            if (!$id) json_out(['ok' => false, 'error' => 'Nothing to turn into a task.'], 422);
            json_out(['ok' => true, 'task_id' => $id]);
        }

        default:
            json_out(['ok' => false, 'error' => 'Unknown action.'], 400);
    }
} catch (Throwable $e) {
    error_log('[community api] ' . $e->getMessage());
    json_out(['ok' => false, 'error' => av_is_prod() ? 'Server error.' : ('Server error: ' . $e->getMessage())], 500);
}
