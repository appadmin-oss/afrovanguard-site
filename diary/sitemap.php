<?php
/**
 * diary/sitemap.php — XML sitemap for the Diary, generated from the database.
 * Served at /diary/sitemap.xml (see .htaccess).
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/bootstrap.php';

$articles = (new DiaryRepository())->all();
header('Content-Type: application/xml; charset=utf-8');
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">' . "\n";

$rows = [['loc' => diary_url(), 'lastmod' => $articles[0]['published_at'] ?? date('Y-m-d'), 'pri' => '0.9', 'img' => null]];
foreach ($articles as $a) {
    $rows[] = [
        'loc' => diary_url($a['slug'] . '/'),
        'lastmod' => $a['published_at'],
        'pri' => $a['featured'] ? '0.8' : '0.7',
        'img' => diary_url('og/' . $a['slug'] . '.png'),
        'title' => $a['title'],
    ];
}
foreach ($rows as $r) {
    echo "  <url>\n";
    echo "    <loc>" . e($r['loc']) . "</loc>\n";
    echo "    <lastmod>" . e($r['lastmod']) . "</lastmod>\n";
    echo "    <changefreq>weekly</changefreq>\n";
    echo "    <priority>{$r['pri']}</priority>\n";
    if (!empty($r['img'])) {
        echo "    <image:image><image:loc>" . e($r['img']) . "</image:loc>"
           . "<image:title>" . e($r['title']) . "</image:title></image:image>\n";
    }
    echo "  </url>\n";
}
echo "</urlset>\n";
