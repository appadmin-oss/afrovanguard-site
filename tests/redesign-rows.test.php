<?php
/**
 * tests/redesign-rows.test.php — rows 5, 11 and 12 of the site redesign
 * (About, How it works, Ethos) keep the working things their v1 pages had.
 */
declare(strict_types=1);

/** Render a PHP page into a string (headers are moot in the CLI). */
$avRender = static function (string $rel): string {
    ob_start();
    try { (static function () use ($rel) { require AV_ROOT . '/' . $rel; })(); }
    catch (Throwable $e) { ob_end_clean(); return 'ERROR ' . $e->getMessage(); }
    return (string) ob_get_clean();
};

/* ── Row 5 · About (static) ─────────────────────────────────────────── */
$about = (string) @file_get_contents(AV_ROOT . '/about.html');
ck('about: canonical kept', str_contains($about, '<link rel="canonical" href="https://afrovanguard.org.ng/about.html" />'));
ck('about: carries the Home chrome markers', str_contains($about, '<!-- avh:nav -->') && str_contains($about, '<!-- avh:foot -->'));
ck('about: our-people anchor kept (other pages link to about.html#our-people)', str_contains($about, 'id="our-people"'));
ck('about: people still come from the directory API', str_contains($about, 'data-avab-team')
   && str_contains((string) @file_get_contents(AV_ROOT . '/assets/site/avab.js'), "/api.php?action=members"));
ck('about: no hex literal in the page', !preg_match('/#[0-9a-fA-F]{3,8}\b/', $about));

/* ── Row 11 · How it works ──────────────────────────────────────────── */
$hiw = $avRender('how-it-works.php');
ck('how-it-works: renders', !str_starts_with($hiw, 'ERROR') && str_contains($hiw, 'Progressive growth &amp; member alignment'));
ck('how-it-works: canonical is /how-it-works', str_contains($hiw, rtrim(SITE_URL, '/') . '/how-it-works"'));
ck('how-it-works: monthly dues come from config', str_contains($hiw, '₦' . number_format((int) AV_DUES_MONTHLY_NGN) . '<small> / month'));
ck('how-it-works: annual dues come from config', str_contains($hiw, 'or ₦' . number_format(defined('AV_DUES_ANNUAL_NGN') ? (int) AV_DUES_ANNUAL_NGN : 12000) . ' / year'));
ck('how-it-works: Home nav and footer', str_contains($hiw, 'data-avh-nav') && str_contains($hiw, 'class="avh-foot"'));
ck('how-it-works: one <main>', substr_count($hiw, '<main') === 1);

/* ── Row 12 · Ethos ─────────────────────────────────────────────────── */
$E = require AV_ROOT . '/lib/ethos_content.php';
$eth = $avRender('ethos/index.php');
ck('ethos: renders', !str_starts_with($eth, 'ERROR') && str_contains($eth, 'The Ethos of Afrovanguardism'));
ck('ethos: #leadership anchor kept (the site nav links to /ethos/#leadership)', str_contains($eth, 'id="leadership"'));
ck('ethos: all nine commitments from lib/ethos_content.php', count($E['commitments']) === 9
   && array_reduce($E['commitments'], fn($ok, $c) => $ok && str_contains($eth, e($c[0])) && str_contains($eth, e($c[2])), true));
ck('ethos: leadership model, values and creed from the content source',
   array_reduce(array_merge($E['leadership'], $E['values']), fn($ok, $x) => $ok && str_contains($eth, e($x[1])), true)
   && array_reduce($E['creed'], fn($ok, $c) => $ok && str_contains($eth, e($c)), true));
ck('ethos: JSON-LD kept', str_contains($eth, 'application/ld+json') && str_contains($eth, '"headline":"The Global Ethos of Afrovanguardism'));
ck('ethos: Home nav and footer', str_contains($eth, 'data-avh-nav') && str_contains($eth, 'class="avh-foot"'));
$_GET['format'] = 'txt';
$txt = $avRender('ethos/index.php');
unset($_GET['format']);
ck('ethos: ?format=txt still returns the plain-text ethos', str_starts_with($txt, 'THE GLOBAL ETHOS OF AFROVANGUARDISM') && !str_contains($txt, '<html'));

/* About repeats the mission, vision and values as static copy: hold it to the ethos source. */
ck('about: mission matches the ethos source', str_contains($about, e($E['mission'])));
ck('about: vision matches the ethos source', str_contains($about, e($E['vision'])));
ck('about: the seven values match the ethos source', array_reduce($E['values'], fn($ok, $v) => $ok && str_contains($about, e($v[0])) && str_contains($about, e($v[1])), true));
