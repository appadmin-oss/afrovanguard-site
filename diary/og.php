<?php
/**
 * diary/og.php — the Open Graph card for one Diary entry (1200×630 PNG).
 *
 *   /diary/og/<slug>.png   (pretty, via .htaccess)  or  ?slug=<slug>
 *
 * Design: Afrovanguard OG Images · 1b (lib/AvOg.php::diary): the eyebrow
 * with the category, the entry's reference code, the title, the wordmark and
 * date · read time. Cached to db/cache/ against everything drawn. Falls back
 * to the site card if GD/fonts are unavailable or the entry does not exist.
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_once AV_ROOT . '/lib/AvOg.php';

$slug = preg_replace('/[^a-z0-9\-]/', '', strtolower((string) ($_GET['slug'] ?? '')));
$fallback = rtrim(SITE_URL, '/') . '/assets/og/og-default.png';

if (!$slug || !AvImage::available()) { header('Location: ' . $fallback, true, 302); exit; }
$a = (new DiaryRepository())->bySlug($slug);
if (!$a) { header('Location: ' . $fallback, true, 302); exit; }

$card = [
    'title'    => (string) $a['title'],
    'category' => (string) ($a['category'] ?? ''),
    'ref'      => (string) ($a['ref_code'] ?? ''),
    'date'     => (string) ($a['published'] ?? ''),
    'minutes'  => (int) ($a['read_minutes'] ?? 0),
];
AvImage::serveCached('og-' . $slug, md5(json_encode($card) . '|1b|' . @filemtime(AV_ROOT . '/lib/AvOg.php')), fn() => AvOg::diary($card));
