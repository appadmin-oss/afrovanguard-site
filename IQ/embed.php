<?php
/**
 * IQ/embed.php — a chrome-less, iframe-embeddable single quiz for Diary blog
 * posts (via the [iq slug] shortcode). Same player as the hub, no site nav.
 * Served at /IQ/embed.php?quiz=slug.
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/bootstrap.php';

$slug = preg_replace('/[^a-z0-9-]/', '', strtolower((string) ($_GET['quiz'] ?? '')));
$u    = LmsAuth::user();
$name = $u ? explode(' ', trim((string) $u['name']))[0] : '';
$csrf = av_csrf_token();
$exists = $slug !== '' && IQ::getForPlay($slug) !== null;
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Afrovanguard quiz</title>
<link rel="stylesheet" href="/IQ/iq.css">
<style>body{margin:0;background:transparent}.iq{padding:0}.iq-embeddoc .iq-player{margin:0}</style>
</head>
<body class="iq-embeddoc">
<main class="iq iq--embed" data-csrf="<?= e($csrf) ?>" data-signed-in="<?= $u ? '1' : '0' ?>" data-name="<?= e($name) ?>" data-embed="1" data-quiz="<?= e($slug) ?>">
<?php if (!$exists): ?>
  <p class="iq-empty" style="padding:24px">This quiz isn’t available.</p>
<?php else: ?>
  <section class="iq-view" id="iqPlayer"></section>
<?php endif; ?>
</main>
<script src="/IQ/iq.js" defer></script>
</body>
</html>
