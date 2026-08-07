<?php
/** academy/sitemap.php — XML sitemap for the Academy (served at /academy/sitemap.xml). */
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/bootstrap.php';
$courses = (new AcademyRepository())->all();
header('Content-Type: application/xml; charset=utf-8');
$S = rtrim(SITE_URL, '/');
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n";
echo "  <url><loc>$S/academy/</loc><changefreq>weekly</changefreq><priority>0.9</priority></url>\n";
echo "  <url><loc>$S/academy/ngv/</loc><changefreq>weekly</changefreq><priority>0.9</priority></url>\n";
foreach ($courses as $c) {
    echo '  <url><loc>' . e("$S/academy/{$c['slug']}/") . "</loc><changefreq>monthly</changefreq><priority>0.7</priority></url>\n";
}
echo "</urlset>\n";
