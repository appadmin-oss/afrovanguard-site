<?php
/**
 * tests/portalnav.test.php — moving between the portal's views.
 *
 * Reported twice on 2026-10-11: "I cannot move between pages" and "the URL
 * just changes, the page doesn't". What keeps it working:
 *   • the router acts first (capture phase), so another script stopping or
 *     cancelling a click cannot leave the address changed and the view not;
 *   • Back/Forward (popstate) re-sync the view, as hash changes do;
 *   • scripts and styles are never served stale after a deploy — not from the
 *     browser's cache (.htaccess: no-cache) nor the service worker's;
 *   • nothing floats over the phone tab bar (the celebrations pill is gone).
 */
declare(strict_types=1);

$avp = (string) file_get_contents(AV_ROOT . '/portal/avp.js');
ck('portal nav: the view router listens in the capture phase', (bool) preg_match("~main\\.focus\\(\\{ preventScroll: true \\}\\);\\s*\\}, true\\);~", $avp));
ck('portal nav: a click cancelled by another script still switches the view', !preg_match("~closest\\('a\\[data-view\\], \\[data-goto\\]'\\);\\s*if \\(!a \\|\\| e\\.defaultPrevented\\)~", $avp));
ck('portal nav: Back and Forward re-sync the view', str_contains($avp, "addEventListener('popstate', fromUrl)") && str_contains($avp, "addEventListener('hashchange', fromUrl)"));

$ht = (string) file_get_contents(AV_ROOT . '/.htaccess');
ck('portal nav: scripts and styles are revalidated on every load', (bool) preg_match('~<FilesMatch "\\\\\.\(js\|mjs\|css\)\$">\s*Header unset Expires\s*Header set Cache-Control "no-cache"~', $ht));

$sw = (string) file_get_contents(AV_ROOT . '/sw.js');
ck('portal nav: the service worker fetches scripts and styles from the network first', (bool) preg_match('~\\\\\.\(js\|mjs\|css\)\$/i\.test\(url\.pathname\)\) \{\s*e\.respondWith\(\s*caches\.open\(VERSION\)\.then\(function \(c\) \{\s*return fetch\(req\)~', $sw));
ck('portal nav: the service worker cache was renamed, so old copies are dropped', !str_contains($sw, "'av-pwa-v4'"));

$idx = (string) file_get_contents(AV_ROOT . '/portal/index.php');
ck('portal nav: every view link names a view that exists', (function () use ($idx) {
    $views = [];
    foreach (array_merge([AV_ROOT . '/portal/index.php'], glob(AV_ROOT . '/portal/views/*.php') ?: []) as $f) if (preg_match_all('~class="pview[^"]*"[^>]*data-view="([a-z-]+)"|data-view="([a-z-]+)"[^>]*class="pview~', (string) file_get_contents($f), $m)) foreach (array_merge($m[1], $m[2]) as $v) if ($v !== '') $views[$v] = true;
    // The sidebar and tab bar are drawn from PHP lists of ['id', 'Label', …]; plus any literal link.
    $nav = substr($idx, (int) strpos($idx, "['overview', 'Today'"), 6000);
    preg_match_all("~\\['([a-z-]+)', '~", $nav, $l);
    preg_match_all('~class="avp-(?:nav-link|tab)[^"]*"[^>]*data-view="([a-z-]+)"~', $idx, $l2);
    $ids = array_unique(array_merge($l[1], $l2[1]));
    foreach ($ids as $v) if (!isset($views[$v])) return false;
    return count($ids) >= 8;
})());
