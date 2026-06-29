<?php
/**
 * STS · admin/blog — publish, update, unpublish posts via bearer token.
 *
 * GET    /api/admin/blog.php                          → list (auth)
 * POST   /api/admin/blog.php                          → create
 * POST   /api/admin/blog.php?slug=foo&_method=PUT     → update
 * POST   /api/admin/blog.php?slug=foo&_method=DELETE  → soft-archive
 *
 * Auth: header `Authorization: Bearer <ADMIN_TOKEN>` where ADMIN_TOKEN is set
 * in /api/.env. Constant-time comparison; the endpoint silently 404s when the
 * token is missing from env (so a forgotten config doesn't open a door).
 */
declare(strict_types=1);

require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../ratelimit.php';

function admin_auth(): void {
    $configured = env('ADMIN_TOKEN');
    if (!$configured) json_response(['ok' => false, 'error' => 'Not found'], 404);
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['Authorization'] ?? '';
    if (str_starts_with($header, 'Bearer ')) $header = substr($header, 7);
    if (!$header || !hash_equals((string)$configured, $header)) {
        json_error('Unauthorized', 401);
    }
}

rate_limit('admin.blog', 60, 60);
admin_auth();

$method = strtoupper((string)($_POST['_method'] ?? $_SERVER['REQUEST_METHOD'] ?? 'GET'));
$slug = $_GET['slug'] ?? null;

try {
    $pdo = db();

    if ($method === 'GET') {
        $stmt = $pdo->query("SELECT id, slug, title, excerpt, category, author, status, published_at, created_at
                             FROM blog_posts ORDER BY created_at DESC LIMIT 200");
        json_response(['ok' => true, 'posts' => $stmt->fetchAll()]);
    }

    if ($method === 'POST') {
        $payload = read_payload();
        $required = ['slug', 'title', 'body'];
        foreach ($required as $r) {
            if (empty($payload[$r])) json_error("Missing field: $r", 422, $r);
        }
        if (!preg_match('/^[a-z0-9-]{1,160}$/', (string)$payload['slug'])) {
            json_error('Slug must be lowercase letters, numbers, dashes (≤160 chars).', 422, 'slug');
        }
        $stmt = $pdo->prepare("INSERT INTO blog_posts
            (slug, title, excerpt, body, cover_image, category, author, status, published_at)
            VALUES (:s, :t, :e, :b, :ci, :c, :a, :st, :pa)");
        $now = (string)($payload['published_at'] ?? date('Y-m-d H:i:s'));
        $stmt->execute([
            ':s'  => (string)$payload['slug'],
            ':t'  => (string)$payload['title'],
            ':e'  => substr((string)($payload['excerpt'] ?? ''), 0, 300),
            ':b'  => (string)$payload['body'],
            ':ci' => (string)($payload['cover_image'] ?? ''),
            ':c'  => (string)($payload['category'] ?? 'General'),
            ':a'  => (string)($payload['author'] ?? 'STS team'),
            ':st' => in_array(($payload['status'] ?? 'published'), ['draft','published','archived'], true)
                   ? (string)$payload['status'] : 'published',
            ':pa' => $now,
        ]);
        json_response(['ok' => true, 'id' => (int)$pdo->lastInsertId(), 'slug' => $payload['slug']]);
    }

    if ($method === 'PUT') {
        if (!$slug) json_error('Slug required', 422, 'slug');
        $payload = read_payload();
        $cols = []; $vals = [':s' => $slug];
        foreach (['title','excerpt','body','cover_image','category','author','status','published_at'] as $f) {
            if (array_key_exists($f, $payload)) { $cols[] = "$f = :$f"; $vals[":$f"] = $payload[$f]; }
        }
        if (!$cols) json_error('Nothing to update', 422);
        $sql = "UPDATE blog_posts SET " . implode(', ', $cols) . " WHERE slug = :s";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($vals);
        json_response(['ok' => true, 'affected' => $stmt->rowCount()]);
    }

    if ($method === 'DELETE') {
        if (!$slug) json_error('Slug required', 422, 'slug');
        $stmt = $pdo->prepare("UPDATE blog_posts SET status = 'archived' WHERE slug = :s");
        $stmt->execute([':s' => $slug]);
        json_response(['ok' => true, 'archived' => $stmt->rowCount()]);
    }

    json_error('Method not allowed', 405);
} catch (Throwable $e) {
    log_line('admin', 'blog op failed', ['err' => $e->getMessage(), 'method' => $method]);
    json_error('Database error', 500);
}

function read_payload(): array {
    $raw = file_get_contents('php://input') ?: '';
    if ($raw && ($json = json_decode($raw, true)) && is_array($json)) return $json;
    return $_POST;
}
