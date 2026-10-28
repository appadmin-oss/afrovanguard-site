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
    // Match the skip list against the path below the root, so a checkout that itself sits under .claude/ (a worktree) still works.
    $sub = substr($p, strlen(AV_ROOT));
    if (!str_ends_with($p, '.html') || str_contains($sub, '/vendor/') || str_contains($sub, '/node_modules/') || str_contains($sub, '/.claude/') || str_contains($sub, '/.git/')) continue;
    $h = (string) file_get_contents($p);
    $rel = substr($p, strlen(AV_ROOT) + 1);
    if (str_contains($h, '<!-- avh:nav -->')) ck("avh chrome: $rel nav in sync (php tools/build-avh-chrome.php)", str_contains($h, "<!-- avh:nav -->\n" . $c['nav'] . "\n<!-- /avh:nav -->"));
    if (str_contains($h, '<!-- avh:foot -->')) ck("avh chrome: $rel footer in sync (php tools/build-avh-chrome.php)", str_contains($h, "<!-- avh:foot -->\n" . $c['foot'] . "\n<!-- /avh:foot -->"));
}

/* The hero on phones (owner, 2026-10-11): the words take the full width, the
   hero fits the screen under the nav (svh, so the browser's bars do not make
   it jump), next/play are full-size and clear of the Chioma button, and a
   phone turned sideways still shows the headline. */
$avhCss = (string) file_get_contents(AV_ROOT . '/assets/site/avh.css');
ck('avh hero: on a phone the text and the controls stack, full width', (bool) preg_match('~@media \(max-width:639px\)\{\s*\.avh-hero\{height:auto;min-height:min\(calc\(100vh - 69px\),720px\);min-height:min\(calc\(100svh - 69px\),720px\)\}\s*\.avh-hero-in\{flex-direction:column~', $avhCss));
ck('avh hero: phone controls are 44px', str_contains($avhCss, '.avh-hero-ctrl button{width:44px;height:44px;font-size:18px}'));
ck('avh hero: a phone held sideways fits the hero to the screen', (bool) preg_match('~@media \(orientation:landscape\) and \(max-height:500px\)\{\s*\.avh-hero\{height:auto;min-height:calc\(100vh - 69px\);min-height:calc\(100svh - 69px\)\}~', $avhCss) && str_contains($avhCss, '.avh-hero-ctrl{margin-right:76px}'));
