<?php
/**
 * diary/api.php — JSON API for the Diary.
 *
 *   GET  ?action=list                       → all entries (cards)
 *   GET  ?action=article&slug=<slug>        → one entry (+ sections, claps)
 *   GET  ?action=reactions&slug=<slug>      → live clap total
 *   POST ?action=subscribe  {email}         → store newsletter subscriber
 *
 * Engagement (the entry page's own JS; writes carry X-CSRF):
 *   POST ?action=view       {slug}          → count a read (deduped per reader)
 *   POST ?action=clap       {slug}          → one clap, capped at 50 per reader
 *   POST ?action=save|unsave {slug}         → keep an entry
 *   GET  ?action=comments&slug&sort&all     → the thread, rendered
 *   POST ?action=comment    {slug,body,…}   → post one (stored pending)
 *   POST ?action=comment-like   {id,on}     → like / unlike
 *   POST ?action=comment-report {id}        → flag for the team
 *
 * Reactions + subscribers persist in SQLite, so engagement is real and
 * shared across every visitor — not just per-browser.
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/bootstrap.php';

header('Vary: Origin');
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin !== '' && preg_match('~^https?://([a-z0-9.-]*\.)?afrovanguard\.org\.ng$~i', $origin)) {
    header("Access-Control-Allow-Origin: {$origin}");
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(204); exit; }

/**
 * The page's engagement JS sends the token as `X-CSRF`; the rest of the site
 * sends `X-CSRF-Token`. Accept either rather than silently rejecting half the
 * writes — and rather than editing a drop-in file to match the server.
 */
function diary_csrf_require(): void
{
    // No signing key configured: the page could not MINT a token, so demanding
    // one would reject every comment on the site with a message about the page
    // being open too long — an install that looks broken for a reason nobody
    // can see. Same-origin still applies, and the real problem is said once,
    // where an operator reads it.
    if (av_secret() === '') {
        static $warned = false;
        if (!$warned) { $warned = true; error_log('[diary] APP_KEY is not set: engagement writes fall back to same-origin only. Set APP_KEY to a long random string.'); }
        return;
    }
    $t = (string) ($_SERVER['HTTP_X_CSRF'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!av_csrf_valid($t)) json_out(['ok' => false, 'error' => 'This page has been open a while — reload and try again.'], 403);
}

try {
    $repo   = new DiaryRepository();
    $action = (string) ($_GET['action'] ?? 'list');
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    // Accept JSON or form-encoded bodies for POST actions.
    $body = [];
    if ($method === 'POST') {
        $raw = file_get_contents('php://input') ?: '';
        $body = json_decode($raw, true) ?: $_POST;
    }
    $slug = preg_replace('/[^a-z0-9\-]/', '', strtolower((string) ($_GET['slug'] ?? $body['slug'] ?? '')));

    switch ($action) {
        case 'list': {
            // Paginated + filterable feed (year / month / category / search) so
            // both the list and the map can load progressively instead of all
            // at once. Back-compatible: still returns `articles` + `count`.
            $limit  = max(1, min(48, (int) ($_GET['limit'] ?? 12)));
            $page   = max(1, (int) ($_GET['page'] ?? 1));
            $offset = isset($_GET['offset']) ? max(0, (int) $_GET['offset']) : ($page - 1) * $limit;
            $month  = preg_replace('/\D/', '', (string) ($_GET['month'] ?? ''));
            if (strlen($month) === 1) $month = '0' . $month;
            $sort = (string) ($_GET['sort'] ?? 'latest');
            $res = $repo->page([
                'year'   => preg_replace('/\D/', '', (string) ($_GET['year'] ?? '')),
                'month'  => $month,
                'cat'    => preg_replace('/[^a-z0-9\-]/', '', strtolower((string) ($_GET['cat'] ?? ''))),
                'author' => preg_replace('/[^a-z0-9\-]/', '', strtolower((string) ($_GET['author'] ?? ''))),
                'q'      => (string) ($_GET['q'] ?? ''),
                'sort'   => in_array($sort, ['latest', 'read', 'discussed'], true) ? $sort : 'latest',
                'limit'  => $limit,
                'offset' => $offset,
            ]);
            $out = [
                'ok'       => true,
                'articles' => $res['items'],
                'count'    => count($res['items']),
                'total'    => $res['total'],
                'limit'    => $res['limit'],
                'offset'   => $res['offset'],
                'hasMore'  => ($res['offset'] + count($res['items'])) < $res['total'],
            ];
            // Counts by SLUG, not by row id: the cards have never carried an id
            // and the browser has no use for one. Three queries for the page.
            $ids = $repo->idsForSlugs(array_column($res['items'], 'slug'));
            $byId = $ids ? $repo->countsFor(array_values($ids)) : [];
            $counts = [];
            foreach ($ids as $slug => $id) if (isset($byId[$id])) $counts[$slug] = $byId[$id];
            $out['counts'] = $counts;
            if (!empty($_GET['facets'])) $out['facets'] = $repo->facets();
            json_out($out);
        }

        case 'article':
            $art = $slug ? $repo->bySlug($slug) : null;
            if (!$art) json_out(['ok' => false, 'error' => 'Article not found.'], 404);
            unset($art['id']);
            json_out(['ok' => true, 'article' => $art]);

        case 'reactions':
            if ($slug === '') json_out(['ok' => false, 'error' => 'slug required'], 400);
            json_out(['ok' => true, 'slug' => $slug, 'claps' => $repo->claps($slug)]);

        /* ── Engagement: views, applause, saves, the conversation ──────────
           Writes are same-origin AND carry the CSRF token the page embedded.
           Same-origin alone is not enough: an Origin header is absent on a
           form POST from an attacker's page in older browsers, and "absent"
           has to be allowed for same-site navigations. The token closes it. */

        case 'view':
            if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required'], 405);
            require_same_origin();
            if ($slug === '') json_out(['ok' => false, 'error' => 'slug required'], 400);
            $art = $repo->bySlug($slug);
            if (!$art) json_out(['ok' => false, 'error' => 'Article not found.'], 404);
            // No CSRF on a view: it writes a counter, not the reader's data, and
            // a token that expires mid-read would silently stop counting.
            json_out(['ok' => true, 'counted' => $repo->recordView((int) $art['id'])]);

        case 'clap':
            if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required'], 405);
            require_same_origin();
            diary_csrf_require();
            if ($slug === '') json_out(['ok' => false, 'error' => 'slug required'], 400);
            if (!av_rate_ok('diary_clap', 120, 600)) json_out(['ok' => false, 'error' => 'That’s a lot of applause — give it a moment.'], 429);
            $total = $repo->clapOnce($slug);
            if ($total === null) json_out(['ok' => false, 'capped' => true, 'error' => 'You’ve given this entry all fifty.'], 409);
            json_out(['ok' => true, 'claps' => $total]);

        case 'save':
        case 'unsave': {
            if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required'], 405);
            require_same_origin();
            diary_csrf_require();
            $art = $slug ? $repo->bySlug($slug) : null;
            if (!$art) json_out(['ok' => false, 'error' => 'Article not found.'], 404);
            $me = LmsAuth::user();
            $uid = $me ? (int) $me['id'] : 0;
            $ok = $action === 'save' ? $repo->addSave((int) $art['id'], $uid) : $repo->removeSave((int) $art['id'], $uid);
            json_out(['ok' => $ok, 'saved' => $action === 'save']);
        }

        case 'comments': {
            if ($slug === '') json_out(['ok' => false, 'error' => 'slug required'], 400);
            $art = $repo->bySlug($slug);
            if (!$art) json_out(['ok' => false, 'error' => 'Article not found.'], 404);
            $sort = ($_GET['sort'] ?? 'top') === 'new' ? 'new' : 'top';
            $all  = !empty($_GET['all']);
            $tree = $repo->commentTree((int) $art['id'], $sort, $all);
            require_once __DIR__ . '/partials.php';
            ob_start();
            if (!$tree) echo '<li class="avd-c-empty">Be the first to add to the conversation.</li>';
            foreach ($tree as $c) avd_comment($c, false);
            json_out(['ok' => true, 'html' => ob_get_clean(), 'total' => $repo->threadCount((int) $art['id'])]);
        }

        case 'comment': {
            if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required'], 405);
            require_same_origin();
            diary_csrf_require();
            if ($slug === '') json_out(['ok' => false, 'error' => 'slug required'], 400);
            if (trim((string) ($body['hp'] ?? '')) !== '') json_out(['ok' => true, 'html' => '']); // honeypot
            if (!av_rate_ok('diary_comment', 8, 600)) json_out(['ok' => false, 'error' => 'You’re commenting quickly — give it a moment.'], 429);
            $me   = LmsAuth::user();
            $name = $me ? (string) $me['name'] : (string) ($body['name'] ?? '');
            $text = (string) ($body['body'] ?? '');
            if (mb_strlen(trim($text)) < 3) json_out(['ok' => false, 'error' => 'Write a little more before posting.'], 422);
            if (trim($name) === '') json_out(['ok' => false, 'error' => 'Add your name to post.'], 422);
            $c = $repo->addThreadComment(
                $slug, $name, $text,
                $me ? (string) ($me['email'] ?? '') : (string) ($body['email'] ?? ''),
                (int) ($body['parent_id'] ?? 0),
                $me ? (int) $me['id'] : 0
            );
            if (!$c) json_out(['ok' => false, 'error' => 'Could not post your comment.'], 422);
            require_once __DIR__ . '/partials.php';
            ob_start();
            avd_comment($c, !empty($c['parent_id']));
            json_out(['ok' => true, 'html' => ob_get_clean(), 'pending' => true]);
        }

        case 'comment-like':
            if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required'], 405);
            require_same_origin();
            diary_csrf_require();
            if (!av_rate_ok('diary_clike', 120, 600)) json_out(['ok' => false, 'error' => 'Slow down a moment.'], 429);
            json_out(['ok' => true, 'likes' => $repo->likeComment((int) ($body['id'] ?? 0), !empty($body['on']) && $body['on'] !== 'false')]);

        case 'comment-report':
            if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required'], 405);
            require_same_origin();
            diary_csrf_require();
            if (!av_rate_ok('diary_creport', 20, 600)) json_out(['ok' => false, 'error' => 'Slow down a moment.'], 429);
            json_out(['ok' => $repo->reportComment((int) ($body['id'] ?? 0))]);

        case 'subscribe':
            if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required'], 405);
            require_same_origin();
            if (trim((string) ($body['hp'] ?? '')) !== '') json_out(['ok' => true, 'message' => 'You’re subscribed — watch for the next dispatch.']); // honeypot: pretend success
            if (!av_rate_ok('subscribe', 10, 600)) json_out(['ok' => false, 'error' => 'Too many attempts — please try again shortly.'], 429);
            $email = (string) ($body['email'] ?? '');
            if (!$repo->subscribe($email)) json_out(['ok' => false, 'error' => 'Enter a valid email address.'], 422);
            json_out(['ok' => true, 'message' => 'You’re subscribed — watch for the next dispatch.']);

        case 'unsubscribe':
            if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required'], 405);
            require_same_origin();
            $email = strtolower(trim((string) ($body['email'] ?? '')));
            $want  = $email !== '' ? av_unsubscribe_token($email) : '';
            if ($want === '' || !hash_equals($want, (string) ($body['token'] ?? ''))) {
                json_out(['ok' => false, 'error' => 'Use the unsubscribe link in one of our emails, or write to us and we will take you off the list.'], 403);
            }
            Database::pdo()->prepare('DELETE FROM subscribers WHERE email = ?')->execute([$email]);
            json_out(['ok' => true, 'message' => 'You have been unsubscribed.']);

        /* ── Following an author (lib/DiaryFollows.php) ──
           A signed-in member follows with their account email; a reader without
           an account sends one. One email per new entry from that author. ── */
        case 'follow.state': {
            $u = LmsAuth::user();
            $f = new DiaryFollows();
            $author = DiaryFollows::cleanSlug((string) ($_GET['author'] ?? ''));
            json_out(['ok' => true, 'signedIn' => (bool) $u, 'count' => $f->count($author),
                'following' => $u ? $f->isFollowing($author, (string) $u['email']) : false]);
        }
        case 'follow':
        case 'unfollow': {
            if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required'], 405);
            require_same_origin();
            if (!av_rate_ok('diary_follow', 20, 3600)) json_out(['ok' => false, 'error' => 'That is a lot of following — try again in a while.'], 429);
            if (!empty($body['hp'])) json_out(['ok' => true, 'following' => true, 'count' => 0]);   // honeypot
            $u = LmsAuth::user();
            $email = $u ? (string) $u['email'] : (string) ($body['email'] ?? '');
            $f = new DiaryFollows();
            $res = $action === 'follow'
                ? $f->follow((string) ($body['author'] ?? ''), $email, $u ? (int) $u['id'] : 0)
                : $f->unfollow((string) ($body['author'] ?? ''), $email);
            json_out($res, $res['ok'] ? 200 : 422);
        }
        case 'follow.stop': {
            // The link in every follow email: one click, no sign-in.
            $author = (new DiaryFollows())->stopByToken((string) ($_GET['t'] ?? ''));
            header('Location: ' . diary_url($author !== '' ? '?unfollowed=1' : ''), true, 302);
            exit;
        }

        /* ── Personal diary entries (Event / Private / Public) ──
           These require a signed-in account. Private + event entries are
           only ever read back to their own author. ── */
        case 'mine': {
            $u = LmsAuth::require();
            $journal = new DiaryJournal();
            json_out(['ok' => true, 'entries' => $journal->mine((int) $u['id'])]);
        }

        case 'entry.create': {
            if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required'], 405);
            require_same_origin();
            $u = LmsAuth::require();
            if (!av_rate_ok('diary_entry', 30, 3600)) json_out(['ok' => false, 'error' => 'You’re logging quickly — give it a moment.'], 429);
            $journal = new DiaryJournal();
            $res = $journal->create(
                (int) $u['id'],
                (string) ($body['kind'] ?? 'private'),
                (string) ($body['title'] ?? ''),
                (string) ($body['body'] ?? ''),
                (string) ($body['entry_date'] ?? date('Y-m-d')),
                (string) ($body['font'] ?? 'default'),
                !empty($body['draft'])
            );
            json_out($res, $res['ok'] ? 200 : 422);
        }
        case 'entry.update': {
            if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required'], 405);
            require_same_origin();
            $u = LmsAuth::require();
            if (!av_rate_ok('diary_edit_' . (int) $u['id'], 60, 3600)) json_out(['ok' => false, 'error' => 'You’re editing quickly — give it a moment.'], 429);
            $res = (new DiaryJournal())->updateOwn((int) $u['id'], (int) ($body['id'] ?? 0), $body);
            json_out($res, $res['ok'] ? 200 : 422);
        }

        case 'entry.delete': {
            if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required'], 405);
            require_same_origin();
            $u = LmsAuth::require();
            $id = (int) ($body['id'] ?? 0);
            $ok = (new DiaryJournal())->deleteOwn((int) $u['id'], $id);
            json_out(['ok' => $ok] + ($ok ? [] : ['error' => 'Entry not found.']), $ok ? 200 : 404);
        }

        case 'entry.share': {
            if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required'], 405);
            require_same_origin();
            $u = LmsAuth::require();
            $id = (int) ($body['id'] ?? 0);
            $journal = new DiaryJournal();
            if (!empty($body['revoke'])) {
                $journal->unshare((int) $u['id'], $id);
                json_out(['ok' => true, 'shared' => false]);
            }
            $tok = $journal->shareToken((int) $u['id'], $id);
            if ($tok === null) json_out(['ok' => false, 'error' => 'Entry not found.'], 404);
            $base = rtrim((string) (defined('SITE_URL') ? SITE_URL : ''), '/');
            json_out(['ok' => true, 'shared' => true, 'token' => $tok, 'url' => $base . '/diary/shared.php?t=' . $tok]);
        }

        /* ── Share with specific people (Google-Workspace style) ── */
        case 'entry.people': {
            $u = LmsAuth::require();
            json_out(['ok' => true, 'people' => (new DiaryJournal())->shareRecipients((int) $u['id'], (int) ($_GET['id'] ?? 0))]);
        }
        case 'entry.share_add': {
            if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required'], 405);
            require_same_origin();
            $u = LmsAuth::require();
            if (!av_rate_ok('diary_share_' . (int) $u['id'], 40, 900)) json_out(['ok' => false, 'error' => 'Slow down a moment.'], 429);
            $res = (new DiaryJournal())->shareWith((int) $u['id'], (int) ($body['id'] ?? 0), (string) ($body['email'] ?? ''));
            json_out($res, $res['ok'] ? 200 : 422);
        }
        case 'entry.share_remove': {
            if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required'], 405);
            require_same_origin();
            $u = LmsAuth::require();
            $ok = (new DiaryJournal())->unshareWith((int) $u['id'], (int) ($body['id'] ?? 0), (int) ($body['user_id'] ?? 0));
            json_out(['ok' => $ok]);
        }
        case 'mine.shared': {
            $u = LmsAuth::require();
            $rows = (new DiaryJournal())->sharedWithMe((int) $u['id']);
            $out = array_map(fn($e) => [
                'id' => (int) $e['id'], 'kind' => (string) $e['kind'], 'title' => (string) $e['title'],
                'author' => (string) $e['author_name'],
                'excerpt' => DiaryJournal::excerpt((string) $e['body'], 140),
                'entry_date' => (string) $e['entry_date'],
            ], $rows);
            json_out(['ok' => true, 'entries' => $out]);
        }
        case 'entry.read': {
            $u = LmsAuth::require();
            $e = (new DiaryJournal())->readable((int) $u['id'], (int) ($_GET['id'] ?? 0));
            if (!$e) json_out(['ok' => false, 'error' => 'Not available.'], 404);
            json_out(['ok' => true, 'entry' => [
                'title' => (string) ($e['title'] ?: 'Untitled entry'),
                'author' => (string) $e['author_name'],
                'entry_date' => (string) $e['entry_date'],
                'body_html' => DiaryJournal::bodyToHtml((string) $e['body']),
            ]]);
        }

        default:
            json_out(['ok' => false, 'error' => 'Unknown action.'], 400);
    }
} catch (Throwable $ex) {
    error_log('[diary api] ' . $ex->getMessage());
    json_out(['ok' => false, 'error' => 'Server error.'], 500);
}
