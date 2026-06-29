<?php
/**
 * STS · RSS 2.0 feed for /blog
 *
 * Pulls the 30 most-recently-published posts from MySQL and renders an XML
 * feed. Cached for 10 minutes via Cache-Control. Errors degrade to a valid
 * empty-channel feed so feed readers never see a broken response.
 */
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';

$site = rtrim((string)env('SITE_URL', 'https://streettostardom.org'), '/');
$now  = gmdate('D, d M Y H:i:s') . ' GMT';

$posts = [];
try {
    $pdo = db();
    $stmt = $pdo->query("SELECT slug, title, excerpt, category, author, published_at
                         FROM blog_posts
                         WHERE status = 'published' AND published_at IS NOT NULL
                         ORDER BY published_at DESC
                         LIMIT 30");
    $posts = $stmt->fetchAll();
} catch (Throwable $e) {
    log_line('rss', 'db read failed', ['err' => $e->getMessage()]);
}

header('Content-Type: application/rss+xml; charset=utf-8');
header('Cache-Control: public, max-age=600');

$xml = '<?xml version="1.0" encoding="UTF-8"?>';
$xml .= '<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom" xmlns:dc="http://purl.org/dc/elements/1.1/">';
$xml .= '<channel>';
$xml .= '<title>Street-To-Stardom · Field notes</title>';
$xml .= '<link>' . htmlspecialchars($site . '/blog', ENT_XML1) . '</link>';
$xml .= '<atom:link rel="self" type="application/rss+xml" href="' . htmlspecialchars($site . '/api/rss.php', ENT_XML1) . '" />';
$xml .= '<description>Field notes, methodology, and the parts we are still figuring out.</description>';
$xml .= '<language>en</language>';
$xml .= '<lastBuildDate>' . $now . '</lastBuildDate>';
$xml .= '<generator>STS API</generator>';

foreach ($posts as $p) {
    $url = $site . '/blog/' . rawurlencode((string)$p['slug']);
    $pub = strtotime((string)$p['published_at']) ?: time();
    $xml .= '<item>';
    $xml .= '<title>' . htmlspecialchars((string)$p['title'], ENT_XML1) . '</title>';
    $xml .= '<link>' . htmlspecialchars($url, ENT_XML1) . '</link>';
    $xml .= '<guid isPermaLink="true">' . htmlspecialchars($url, ENT_XML1) . '</guid>';
    $xml .= '<pubDate>' . gmdate('D, d M Y H:i:s', $pub) . ' GMT</pubDate>';
    if (!empty($p['author']))   $xml .= '<dc:creator>' . htmlspecialchars((string)$p['author'], ENT_XML1) . '</dc:creator>';
    if (!empty($p['category'])) $xml .= '<category>' . htmlspecialchars((string)$p['category'], ENT_XML1) . '</category>';
    $xml .= '<description>' . htmlspecialchars((string)($p['excerpt'] ?? ''), ENT_XML1) . '</description>';
    $xml .= '</item>';
}

$xml .= '</channel></rss>';
echo $xml;
