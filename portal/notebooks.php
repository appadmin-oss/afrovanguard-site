<?php
/**
 * portal/notebooks.php — JSON API for Notebooks, entry tabs and organisation.
 *
 * NOTEBOOKS (the diary's folders, properly named — see lib/DiaryNotebooks.php)
 *   GET  ?action=list                                → { ok, notebooks, counts, tags }
 *   POST ?action=create   {name, description, colour}
 *   POST ?action=update   {id, name?, description?, colour?, archived?, sort?}
 *   POST ?action=delete   {id}                       → entries survive, unfiled
 *   POST ?action=move     {entry_id|entry_ids[], notebook_id}
 *   POST ?action=share    {id, email, role}          → viewer | contributor
 *   POST ?action=unshare  {id, user_id}
 *   GET  ?action=members&id=N
 *   POST ?action=link     {id}                       → mint a read-only link
 *   POST ?action=unlink   {id}
 *
 * TABS (Google-Docs-style sections inside one entry — see lib/DiaryTabs.php)
 *   GET  ?action=tabs&entry=N
 *   POST ?action=tab_add    {entry_id, title, body}
 *   POST ?action=tab_save   {entry_id, tab_id, title?, body?}   tab_id 0 = the entry
 *   POST ?action=tab_delete {entry_id, tab_id}
 *   POST ?action=tab_order  {entry_id, order:[ids]}
 *
 * ORGANISATION (see lib/DiaryOrganise.php)
 *   GET  ?action=search&q=&notebook=&kind=&tag=&from=&to=&archived=
 *   POST ?action=tags    {entry_id, tags:[]}
 *   POST ?action=pin     {entry_id, on}
 *   POST ?action=archive {entry_id, on}
 *   POST ?action=tag_rename {from, to}
 *
 * Any signed-in member. Writes are same-origin + CSRF + rate-limited, matching
 * every other portal endpoint. Authorisation itself lives in the lib classes —
 * this file must never be the only thing standing between a member and somebody
 * else's diary.
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/bootstrap.php';

$u = LmsAuth::user();
if (!$u) json_out(['ok' => false, 'error' => 'Please sign in.'], 401);
$uid = (int) $u['id'];

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = (string) ($_GET['action'] ?? 'list');
$body   = [];
if ($method === 'POST') { $body = json_decode(file_get_contents('php://input') ?: '', true) ?: $_POST; }
if (!is_array($body)) $body = [];

$writeGuard = function () use ($uid) { av_require_write($uid, 'notebooks', 90); };
$post = function () use ($method) {
    if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required.'], 405);
};

$nb   = new DiaryNotebooks();
$tabs = new DiaryTabs();
$org  = new DiaryOrganise();

try {
    switch ($action) {

        /* ── Notebooks ──────────────────────────────────────────────────── */

        case 'list':
            json_out([
                'ok'        => true,
                'notebooks' => $nb->forUser($uid, !empty($_GET['archived'])),
                'counts'    => $org->counts($uid),
                'tags'      => $org->vocabulary($uid),
                'colours'   => DiaryNotebooks::COLOURS,
                'roles'     => DiaryNotebooks::ROLES,
            ]);

        case 'create':
            $post(); $writeGuard();
            json_out($nb->create($uid,
                (string) ($body['name'] ?? ''),
                (string) ($body['description'] ?? ''),
                (string) ($body['colour'] ?? 'ink')));

        case 'update':
            $post(); $writeGuard();
            $ok = $nb->update($uid, (int) ($body['id'] ?? 0), $body);
            json_out(['ok' => $ok] + ($ok ? [] : ['error' => 'Notebook not found, or that name is empty.']));

        case 'delete':
            $post(); $writeGuard();
            json_out(['ok' => $nb->delete($uid, (int) ($body['id'] ?? 0))]);

        case 'move':
            $post(); $writeGuard();
            $target = (int) ($body['notebook_id'] ?? 0);
            if (isset($body['entry_ids']) && is_array($body['entry_ids'])) {
                json_out(['ok' => true, 'moved' => $nb->moveMany($uid, $body['entry_ids'], $target)]);
            }
            $ok = $nb->moveEntry($uid, (int) ($body['entry_id'] ?? 0), $target);
            json_out(['ok' => $ok] + ($ok ? [] : ['error' => 'That entry is not yours, or you cannot write to that notebook.']));

        /* ── Sharing ────────────────────────────────────────────────────── */

        case 'share':
            $post(); $writeGuard();
            json_out($nb->share($uid, (int) ($body['id'] ?? 0),
                (string) ($body['email'] ?? ''), (string) ($body['role'] ?? 'viewer')));

        case 'unshare':
            $post(); $writeGuard();
            json_out(['ok' => $nb->unshare($uid, (int) ($body['id'] ?? 0), (int) ($body['user_id'] ?? 0))]);

        case 'members':
            json_out(['ok' => true, 'members' => $nb->members($uid, (int) ($_GET['id'] ?? 0))]);

        case 'link':
            $post(); $writeGuard();
            $tok = $nb->linkToken($uid, (int) ($body['id'] ?? 0));
            if ($tok === null) json_out(['ok' => false, 'error' => 'Notebook not found.'], 404);
            $base = defined('SITE_URL') ? rtrim(SITE_URL, '/') : '';
            json_out(['ok' => true, 'token' => $tok, 'url' => $base . '/diary/notebook.php?t=' . $tok]);

        case 'unlink':
            $post(); $writeGuard();
            json_out(['ok' => $nb->revokeLink($uid, (int) ($body['id'] ?? 0))]);

        /* ── Tabs ───────────────────────────────────────────────────────── */

        case 'tabs':
            $eid = (int) ($_GET['entry'] ?? 0);
            // Readable by the author, or by anyone the entry's notebook is
            // shared with. The check is here rather than in DiaryTabs because
            // it is a question about the ENTRY, and DiaryTabs deliberately
            // knows only about tabs.
            if (!av_diary_may_read($uid, $eid)) json_out(['ok' => false, 'error' => 'Not found.'], 404);
            json_out(['ok' => true, 'tabs' => $tabs->all($eid)]);

        case 'tab_add':
            $post(); $writeGuard();
            json_out($tabs->add($uid, (int) ($body['entry_id'] ?? 0),
                (string) ($body['title'] ?? ''), (string) ($body['body'] ?? '')));

        case 'tab_save':
            $post(); $writeGuard();
            json_out(['ok' => $tabs->save(
                $uid,
                (int) ($body['entry_id'] ?? 0),
                (int) ($body['tab_id'] ?? 0),
                array_key_exists('title', $body) ? (string) $body['title'] : null,
                array_key_exists('body', $body) ? (string) $body['body'] : null
            )]);

        case 'tab_delete':
            $post(); $writeGuard();
            json_out(['ok' => $tabs->remove($uid, (int) ($body['entry_id'] ?? 0), (int) ($body['tab_id'] ?? 0))]);

        case 'tab_order':
            $post(); $writeGuard();
            $order = is_array($body['order'] ?? null) ? $body['order'] : [];
            json_out(['ok' => $tabs->reorder($uid, (int) ($body['entry_id'] ?? 0), $order)]);

        /* ── Organisation ───────────────────────────────────────────────── */

        case 'search':
            json_out(['ok' => true, 'entries' => $org->search($uid, [
                'q'        => (string) ($_GET['q'] ?? ''),
                'notebook' => isset($_GET['notebook']) && $_GET['notebook'] !== '' ? (int) $_GET['notebook'] : null,
                'kind'     => (string) ($_GET['kind'] ?? ''),
                'tag'      => (string) ($_GET['tag'] ?? ''),
                'from'     => (string) ($_GET['from'] ?? ''),
                'to'       => (string) ($_GET['to'] ?? ''),
                'archived' => !empty($_GET['archived']),
            ])]);

        case 'tags':
            $post(); $writeGuard();
            $list = is_array($body['tags'] ?? null) ? $body['tags'] : [];
            json_out(['ok' => true, 'tags' => $org->setTags($uid, (int) ($body['entry_id'] ?? 0), $list)]);

        case 'tag_rename':
            $post(); $writeGuard();
            json_out(['ok' => true, 'changed' => $org->renameTag($uid, (string) ($body['from'] ?? ''), (string) ($body['to'] ?? ''))]);

        case 'pin':
            $post(); $writeGuard();
            json_out($org->pin($uid, (int) ($body['entry_id'] ?? 0), !empty($body['on'])));

        case 'archive':
            $post(); $writeGuard();
            json_out(['ok' => $org->archive($uid, (int) ($body['entry_id'] ?? 0), !empty($body['on']))]);

        default:
            json_out(['ok' => false, 'error' => 'Unknown action.'], 400);
    }
} catch (Throwable $e) {
    error_log('[notebooks] ' . $e->getMessage());
    json_out(['ok' => false, 'error' => av_is_prod() ? 'Server error.' : ('Server error: ' . $e->getMessage())], 500);
}
