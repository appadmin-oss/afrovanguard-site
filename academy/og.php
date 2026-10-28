<?php
/**
 * academy/og.php — the Open Graph card for a course (1200×630 PNG).
 *   /academy/og/<slug>.png  or  ?slug=<slug>
 * Design: Afrovanguard OG Images · 1d (lib/AvOg.php::course): paper, gold
 * frame, the price · duration pill, the title, the wordmark.
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_once AV_ROOT . '/lib/AvOg.php';

$slug = preg_replace('/[^a-z0-9\-]/', '', strtolower((string) ($_GET['slug'] ?? '')));
$fallback = rtrim(SITE_URL, '/') . '/assets/og/og-default.png';
if (!$slug || !AvImage::available()) { header('Location: ' . $fallback, true, 302); exit; }

$c = (new AcademyRepository())->bySlug($slug);
if (!$c) { header('Location: ' . $fallback, true, 302); exit; }

$card = [
    'title' => (string) $c['title'],
    'pill'  => implode(' · ', array_filter([trim((string) ($c['price'] ?? '')) ?: 'Free', trim((string) ($c['duration'] ?? ''))])),
    'foot'  => 'Certificate on completion',
];
AvImage::serveCached('acog-' . $slug, md5(json_encode($card) . '|1d|' . @filemtime(AV_ROOT . '/lib/AvOg.php')), fn() => AvOg::course($card));
