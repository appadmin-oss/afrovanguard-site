<?php
/**
 * tests/avhchrome.test.php — one nav and one footer for every redesigned page.
 *
 * index.html is the drop-in and keeps its own copy; partials/avh-chrome.php is
 * what every other page renders. If the two drift, a visitor sees two menus.
 * Static pages carry the markup between <!-- avh:nav/foot --> markers, kept in
 * step by tools/build-avh-chrome.php — a page that was not rebuilt fails here.
 */
declare(strict_types=1);

require_once AV_ROOT . '/partials/avh-chrome.php';

$c = avh_chrome_html();
$home = (string) file_get_contents(AV_ROOT . '/index.html');
ck('avh chrome: the shared nav is exactly the Home nav', str_contains($home, $c['nav']));
ck('avh chrome: the shared footer is exactly the Home footer', str_contains($home, $c['foot']));

$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(AV_ROOT, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    $p = $f->getPathname();
    if (!str_ends_with($p, '.html') || str_contains($p, '/vendor/') || str_contains($p, '/node_modules/')) continue;
    $h = (string) file_get_contents($p);
    $rel = substr($p, strlen(AV_ROOT) + 1);
    if (str_contains($h, '<!-- avh:nav -->')) ck("avh chrome: $rel nav in sync (php tools/build-avh-chrome.php)", str_contains($h, "<!-- avh:nav -->\n" . $c['nav'] . "\n<!-- /avh:nav -->"));
    if (str_contains($h, '<!-- avh:foot -->')) ck("avh chrome: $rel footer in sync (php tools/build-avh-chrome.php)", str_contains($h, "<!-- avh:foot -->\n" . $c['foot'] . "\n<!-- /avh:foot -->"));
}
