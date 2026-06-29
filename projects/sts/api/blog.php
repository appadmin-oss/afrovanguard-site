<?php
/**
 * STS · blog — list + single-post endpoint.
 *
 * Body is stored as HTML in MySQL. If a post happens to be authored in
 * markdown (no `<` in the first 200 chars), we render it via
 * league/commonmark when the dependency is installed. Output is JSON.
 */
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';

if (is_file(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

use League\CommonMark\GithubFlavoredMarkdownConverter;

function render_body(string $raw): string
{
    $head = substr(ltrim($raw), 0, 200);
    $looksHtml = str_contains($head, '<') && preg_match('/<\w+[\s>]/', $head);
    if ($looksHtml) return $raw;
    if (class_exists(GithubFlavoredMarkdownConverter::class)) {
        try {
            $conv = new GithubFlavoredMarkdownConverter([
                'html_input' => 'escape',
                'allow_unsafe_links' => false,
                'max_nesting_level' => 16,
            ]);
            return (string)$conv->convert($raw);
        } catch (Throwable $e) {
            log_line('blog', 'commonmark failed', ['err' => $e->getMessage()]);
        }
    }
    return '<p>' . nl2br(htmlspecialchars($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8')) . '</p>';
}

$slug = $_GET['slug'] ?? null;
$cat = $_GET['category'] ?? null;
$limit = (int)($_GET['limit'] ?? 20);
if ($limit > 50) $limit = 50;
if ($limit < 1) $limit = 1;

try {
    $pdo = db();
    if ($slug && is_string($slug) && preg_match('/^[a-z0-9-]{1,160}$/i', $slug)) {
        $stmt = $pdo->prepare("SELECT slug, title, excerpt, body, cover_image, category, author, published_at
                               FROM blog_posts WHERE slug = :s AND status = 'published' LIMIT 1");
        $stmt->execute([':s' => $slug]);
        $post = $stmt->fetch();
        if (!$post) json_response(['ok' => false, 'error' => 'Not found'], 404);
        $post['body'] = render_body((string)$post['body']);
        header('Cache-Control: public, max-age=300');
        json_response(['ok' => true, 'post' => $post]);
    }
    $sql = "SELECT slug, title, excerpt, cover_image, category, author, published_at
            FROM blog_posts WHERE status = 'published'";
    $params = [];
    if ($cat && is_string($cat) && strlen($cat) <= 40) {
        $sql .= " AND category = :c";
        $params[':c'] = $cat;
    }
    $sql .= " ORDER BY published_at DESC LIMIT " . (int)$limit;
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $posts = $stmt->fetchAll();
    header('Cache-Control: public, max-age=120');
    json_response(['ok' => true, 'posts' => $posts]);
} catch (Throwable $e) {
    log_line('db', 'blog read failed', ['err' => $e->getMessage()]);
    json_response(['ok' => true, 'posts' => []]);
}
