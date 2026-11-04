<?php
/**
 * tests/analytics.test.php — Google Analytics is on every public page, once,
 * and sends Google nothing it should not have.
 *
 * Run via tests/run.php (provides ck()).
 */
declare(strict_types=1);

$ga = (string) file_get_contents(AV_ROOT . '/assets/site/ga.js');
ck('analytics: the tag carries the site’s measurement id', str_contains($ga, "'G-K3MXKHNB0W'") && str_contains($ga, 'googletagmanager.com/gtag/js?id='));
ck('analytics: the id is typed in one file only', count(array_filter(array_merge(glob(AV_ROOT . '/*.html'), glob(AV_ROOT . '/lib/*.php')),
    static fn($f) => str_contains((string) file_get_contents($f), 'G-K3MXKHNB0W'))) === 0);
ck('analytics: page addresses go without their query string (tokens live there)', str_contains($ga, 'location.origin + location.pathname') && !str_contains($ga, 'location.href'));
ck('analytics: campaign tags are kept', str_contains($ga, 'utm_') && str_contains($ga, 'gclid'));
ck('analytics: Global Privacy Control and Do Not Track are honoured', str_contains($ga, 'globalPrivacyControl === true') && str_contains($ga, "doNotTrack === '1'"));
ck('analytics: staff tools are not counted', str_contains($ga, 'admin|academy\/studio|card'));

$page = "<!doctype html><html><head><title>x</title></head><body>hi</body></html>";
$tagged = Analytics::inject($page);
ck('analytics: the tag goes in before </head>, versioned', str_contains($tagged, Analytics::tag() . "\n</head>") && str_contains(Analytics::tag(), '/assets/site/ga.js?v='));
ck('analytics: once, however often it runs', Analytics::inject($tagged) === $tagged);
ck('analytics: a fragment with no head is left alone', Analytics::inject('{"ok":true}') === '{"ok":true}');
foreach (glob(AV_ROOT . '/*.html') as $f) {
    ck('analytics: static page ' . basename($f) . ' carries the tag', preg_match('~<script src="/assets/site/ga\.js(\?v=[a-f0-9]+)?" async></script>~', (string) file_get_contents($f)) === 1);
}
$boot = (string) file_get_contents(AV_ROOT . '/lib/bootstrap.php');
ck('analytics: the bootstrap starts the injector', str_contains($boot, 'Analytics::start();'));

$ht = (string) file_get_contents(AV_ROOT . '/.htaccess');
$sec = (string) file_get_contents(AV_ROOT . '/lib/security.php');
foreach (['https://www.googletagmanager.com', 'https://*.google-analytics.com', 'https://*.analytics.google.com'] as $h)
    ck("analytics: both content policies allow $h", str_contains($ht, $h) && str_contains($sec, $h));

$pp = (string) file_get_contents(AV_ROOT . '/privacy-policy/index.php');
ck('analytics: the privacy policy says Google Analytics is used', str_contains($pp, 'Google Analytics') && str_contains($pp, '_ga'));
ck('analytics: and no longer claims there are no tracking cookies', !str_contains($pp, 'tracking cookies, and we'));
