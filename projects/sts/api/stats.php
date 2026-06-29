<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';

// 5-minute file cache
$cacheDir = __DIR__ . '/logs';
$cacheFile = $cacheDir . '/stats.cache.json';
if (!is_dir($cacheDir)) @mkdir($cacheDir, 0775, true);

if (is_file($cacheFile) && (time() - filemtime($cacheFile)) < 300) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: public, max-age=300');
    readfile($cacheFile);
    exit;
}

$out = [];
try {
    $pdo = db();
    $stmt = $pdo->query("SELECT stat_key, stat_value, display_label FROM site_stats");
    foreach ($stmt as $row) {
        $out[$row['stat_key']] = ['value' => (int)$row['stat_value'], 'label' => $row['display_label']];
    }
} catch (Throwable $e) {
    // Fall through with empty out; the site has reasonable static defaults
}

$json = json_encode(['ok' => true, 'stats' => $out, 'updated_at' => date('c')], JSON_UNESCAPED_UNICODE);
@file_put_contents($cacheFile, $json);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=300');
echo $json;
