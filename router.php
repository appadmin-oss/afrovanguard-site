<?php
/**
 * router.php — local development router for PHP's built-in server.
 * Emulates the production .htaccess rewrite for pretty Diary URLs.
 *
 *   php -S 127.0.0.1:8000 router.php
 *
 * Not used in production (Apache + .htaccess handles routing there).
 */
declare(strict_types=1);

$uri  = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/';
$path = __DIR__ . $uri;

// Serve existing static files (css, js, images, .html) directly.
if ($uri !== '/' && is_file($path)) { return false; }

// Custom-owned /blog/* → /diary/* (301)
if (preg_match('~^/blog/?(.*)$~', $uri, $m)) {
    $qs = $_SERVER['QUERY_STRING'] ?? '';
    header('Location: /diary/' . $m[1] . ($qs ? '?' . $qs : ''), true, 301);
    return true;
}

// Diary SEO endpoints
if ($uri === '/diary/sitemap.xml') { require __DIR__ . '/diary/sitemap.php'; return true; }
if ($uri === '/diary/feed.xml')    { require __DIR__ . '/diary/feed.php'; return true; }
if (preg_match('~^/diary/og/([a-z0-9-]+)\.png$~', $uri, $m)) { $_GET['slug'] = $m[1]; require __DIR__ . '/diary/og.php'; return true; }

// Diary pretty routes
if (preg_match('~^/diary/?$~', $uri)) { require __DIR__ . '/diary/index.php'; return true; }
if (preg_match('~^/diary/([a-z0-9-]+)/?$~', $uri, $m)) {
    $_GET['slug'] = $m[1];
    require __DIR__ . '/diary/article.php';
    return true;
}

if (preg_match('~^/ethos/?$~', $uri)) { require __DIR__ . '/ethos/index.php'; return true; }

// Academy pretty routes
if ($uri === '/academy/pay' || $uri === '/academy/pay.php') { require __DIR__ . '/academy/pay.php'; return true; }
if ($uri === '/academy/sitemap.xml') { require __DIR__ . '/academy/sitemap.php'; return true; }
if (preg_match('~^/academy/og/([a-z0-9-]+)\.png$~', $uri, $m)) { $_GET['slug'] = $m[1]; require __DIR__ . '/academy/og.php'; return true; }
if (preg_match('~^/academy/?$~', $uri)) { require __DIR__ . '/academy/index.php'; return true; }
if (preg_match('~^/academy/([a-z0-9-]+)/certificate/?$~', $uri, $m)) { $_GET['course'] = $m[1]; require __DIR__ . '/academy/certificate.php'; return true; }
if (preg_match('~^/academy/([a-z0-9-]+)/learn/?$~', $uri, $m)) { $_GET['course'] = $m[1]; require __DIR__ . '/academy/learn.php'; return true; }
if (preg_match('~^/academy/([a-z0-9-]+)/learn/([a-z0-9-]+)/?$~', $uri, $m)) { $_GET['course'] = $m[1]; $_GET['lesson'] = $m[2]; require __DIR__ . '/academy/learn.php'; return true; }
if (preg_match('~^/academy/([a-z0-9-]+)/?$~', $uri, $m)) {
    $_GET['slug'] = $m[1];
    require __DIR__ . '/academy/course.php';
    return true;
}

// Fallback: let the built-in server handle it (404 for missing files).
return false;
