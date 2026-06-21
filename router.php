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

// Diary pretty routes
if (preg_match('~^/diary/?$~', $uri)) { require __DIR__ . '/diary/index.php'; return true; }
if (preg_match('~^/diary/([a-z0-9-]+)/?$~', $uri, $m)) {
    $_GET['slug'] = $m[1];
    require __DIR__ . '/diary/article.php';
    return true;
}

// Fallback: let the built-in server handle it (404 for missing files).
return false;
