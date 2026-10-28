<?php
/**
 * tests/avsi.test.php — the "signed in" nav hint (LmsAuth::HINT, av_si).
 * The static pages read it before first paint to show My portal instead of
 * Sign in / Join the Movement; the chrome carries both variants.
 */
declare(strict_types=1);
require_once AV_ROOT . '/lib/partials.php';
require_once AV_ROOT . '/partials/avh-chrome.php';
LmsAuth::hint(true, 60);
ck('avsi: the hint is set to "1" and nothing more', ($_COOKIE[LmsAuth::HINT] ?? '') === '1');
LmsAuth::hint(false);
ck('avsi: the hint clears', !isset($_COOKIE[LmsAuth::HINT]));
$siNav = (string) (avh_chrome_html()['nav'] ?? '');
ck('avsi: Sign in and Join the Movement are marked guest-only', substr_count($siNav, 'data-avh-guest') === 5);
ck('avsi: every guest place has a My portal for members', substr_count($siNav, 'avh-member" href="/portal/"') === 3);
ck('avsi: the hint is read before first paint on server-rendered pages', str_contains(THEME_BOOT_SITE, 'av_si=1'));
