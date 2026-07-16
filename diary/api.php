<?php
/**
 * diary/api.php — JSON API for the Diary.
 *
 *   GET  ?action=list                       → all entries (cards)
 *   GET  ?action=article&slug=<slug>        → one entry (+ sections, claps)
 *   GET  ?action=reactions&slug=<slug>      → live clap total
 *   POST ?action=react      {slug,count}    → increment claps, returns total
 *   POST ?action=subscribe  {email}         → store newsletter subscriber
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
            $res = $repo->page([
                'year'   => preg_replace('/\D/', '', (string) ($_GET['year'] ?? '')),
                'month'  => $month,
                'cat'    => preg_replace('/[^a-z0-9\-]/', '', strtolower((string) ($_GET['cat'] ?? ''))),
                'q'      => (string) ($_GET['q'] ?? ''),
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

        case 'react':
            if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required'], 405);
            require_same_origin();
            if ($slug === '') json_out(['ok' => false, 'error' => 'slug required'], 400);
            $count = (int) ($body['count'] ?? 1);
            json_out(['ok' => true, 'slug' => $slug, 'claps' => $repo->addClaps($slug, $count)]);

        case 'comments':
            if ($slug === '') json_out(['ok' => false, 'error' => 'slug required'], 400);
            json_out(['ok' => true, 'slug' => $slug, 'comments' => $repo->comments($slug)]);

        case 'comment':
            if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required'], 405);
            require_same_origin();
            if ($slug === '') json_out(['ok' => false, 'error' => 'slug required'], 400);
            if (trim((string) ($body['hp'] ?? '')) !== '') json_out(['ok' => true, 'comment' => null]); // honeypot
            if (!av_rate_ok('diary_comment', 8, 600)) json_out(['ok' => false, 'error' => 'You’re commenting quickly — give it a moment.'], 429);
            // Signed-in members comment under their real name; guests provide one.
            $me   = LmsAuth::user();
            $name = $me ? (string) $me['name'] : (string) ($body['name'] ?? '');
            $text = (string) ($body['body'] ?? '');
            if (trim($text) === '') json_out(['ok' => false, 'error' => 'Write a comment first.'], 422);
            if (!$me && trim($name) === '') json_out(['ok' => false, 'error' => 'Add your name.'], 422);
            $c = $repo->addComment($slug, $name, $text, $me ? (int) $me['id'] : 0);
            if (!$c) json_out(['ok' => false, 'error' => 'Could not post your comment.'], 422);
            json_out(['ok' => true, 'comment' => $c]);

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
            if ($email !== '') Database::pdo()->prepare('DELETE FROM subscribers WHERE email = ?')->execute([$email]);
            json_out(['ok' => true, 'message' => 'You have been unsubscribed.']);

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
                (string) ($body['entry_date'] ?? date('Y-m-d'))
            );
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
