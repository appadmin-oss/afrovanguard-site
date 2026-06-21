<?php
/**
 * admin/api.php — authenticated CMS API for the Diary editor.
 *
 *   GET  ?action=ping                      → validate token
 *   GET  ?action=list                      → all entries (incl. drafts)
 *   GET  ?action=get&slug=…                → full entry for editing
 *   GET  ?action=categories                → category names
 *   GET  ?action=articles                  → slugs+titles (related picker)
 *   POST ?action=save        {…article}    → create/update, returns slug
 *   POST ?action=delete      {slug}        → delete
 *   POST ?action=upload      (multipart)   → Cloudinary/local image upload
 *
 * Every action requires the admin bearer token.
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_once AV_ROOT . '/lib/Cloudinary.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(204); exit; }
require_admin();

try {
    $repo   = new DiaryRepository();
    $action = (string) ($_GET['action'] ?? '');
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $isUpload = $action === 'upload';
    $body = [];
    if ($method === 'POST' && !$isUpload) {
        $raw = file_get_contents('php://input') ?: '';
        $body = json_decode($raw, true) ?: $_POST;
    }

    switch ($action) {
        case 'ping':
            json_out(['ok' => true, 'cloudinary' => Cloudinary::configured()]);

        case 'list':
            json_out(['ok' => true, 'articles' => $repo->allForAdmin()]);

        case 'categories':
            json_out(['ok' => true, 'categories' => $repo->categories()]);

        case 'articles':
            $rows = array_map(fn($a) => ['slug' => $a['slug'], 'title' => $a['title']], $repo->allForAdmin());
            json_out(['ok' => true, 'articles' => $rows]);

        case 'get':
            $slug = preg_replace('/[^a-z0-9\-]/', '', strtolower((string) ($_GET['slug'] ?? '')));
            $a = $slug ? $repo->getRaw($slug) : null;
            if (!$a) json_out(['ok' => false, 'error' => 'Not found.'], 404);
            json_out(['ok' => true, 'article' => $a]);

        case 'upload':
            if ($method !== 'POST' || empty($_FILES['file'])) json_out(['ok' => false, 'error' => 'No file.'], 400);
            $f = $_FILES['file'];
            if ($f['error'] !== UPLOAD_ERR_OK) json_out(['ok' => false, 'error' => 'Upload error.'], 400);
            if ($f['size'] > 10 * 1024 * 1024) json_out(['ok' => false, 'error' => 'Max 10 MB.'], 413);
            $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
            if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/avif'], true)) {
                json_out(['ok' => false, 'error' => 'Images only (jpg, png, webp, gif).'], 415);
            }
            $res = Cloudinary::upload($f['tmp_name'], $f['name']);
            json_out(['ok' => true, 'url' => $res['url'], 'provider' => $res['provider'], 'location' => $res['url']]);

        case 'save':
            if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required.'], 405);
            $title = trim((string) ($body['title'] ?? ''));
            if ($title === '') json_out(['ok' => false, 'error' => 'A title is required.'], 422);

            [$cleanBody, $sections] = extract_sections((string) ($body['body_html'] ?? ''));
            $words = str_word_count(strip_tags($cleanBody));
            $read = max(1, (int) ($body['read_minutes'] ?? 0)) ?: max(1, (int) round($words / 200));

            $pubAt = trim((string) ($body['published_at'] ?? '')) ?: date('Y-m-d');
            $pubTs = strtotime($pubAt) ?: time();

            $slug = $repo->save([
                'slug'         => trim((string) ($body['slug'] ?? '')) ?: $title,
                'title'        => $title,
                'dek'          => trim((string) ($body['dek'] ?? '')),
                'category'     => trim((string) ($body['category'] ?? 'Dispatch')),
                'authors_html' => trim((string) ($body['authors_html'] ?? 'The Afrovanguard Team')),
                'published'    => date('M j, Y', $pubTs),
                'published_at' => date('Y-m-d', $pubTs),
                'read_minutes' => $read,
                'gradient'     => trim((string) ($body['gradient'] ?? 'g-gold')),
                'mc_title'     => trim((string) ($body['mc_title'] ?? $title)),
                'cover_url'    => trim((string) ($body['cover_url'] ?? '')),
                'og_image'     => trim((string) ($body['og_image'] ?? '')),
                'body_html'    => $cleanBody,
                'featured'     => !empty($body['featured']),
                'status'       => ($body['status'] ?? 'draft') === 'published' ? 'published' : 'draft',
                'sections'     => $sections,
                'related'      => array_values(array_filter((array) ($body['related'] ?? []))),
            ]);
            json_out(['ok' => true, 'slug' => $slug, 'url' => diary_url($slug . '/'), 'sections' => $sections]);

        case 'delete':
            if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required.'], 405);
            $slug = preg_replace('/[^a-z0-9\-]/', '', strtolower((string) ($body['slug'] ?? '')));
            json_out(['ok' => $repo->delete($slug)]);

        default:
            json_out(['ok' => false, 'error' => 'Unknown action.'], 400);
    }
} catch (Throwable $ex) {
    error_log('[admin api] ' . $ex->getMessage());
    json_out(['ok' => false, 'error' => 'Server error: ' . $ex->getMessage()], 500);
}
