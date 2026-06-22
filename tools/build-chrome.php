<?php
/**
 * tools/build-chrome.php — sync the canonical nav + footer into the static
 * marketing pages so the whole site shares ONE definition of its chrome.
 *
 * The single source of truth is lib/partials.php (av_nav_items() +
 * av_footer_inner()). PHP pages render it live; the static .html pages
 * (which cannot be converted to root index.php without clobbering the
 * co-resident WordPress front controller) get the same markup injected
 * here and committed — the project's established "generate with PHP,
 * commit the output" pattern. URLs and per-page styling are untouched.
 *
 *   php tools/build-chrome.php          # rewrite the static pages
 *   php tools/build-chrome.php --check  # report drift, change nothing
 *
 * Re-run whenever the nav/footer in lib/partials.php changes.
 */
declare(strict_types=1);

if (!defined('SITE_URL')) define('SITE_URL', 'https://afrovanguard.org.ng');
require __DIR__ . '/../lib/helpers.php';   // e()
require __DIR__ . '/../lib/partials.php';  // av_nav_items(), av_footer_inner(), Icons

$check = in_array('--check', $argv, true);
$root  = dirname(__DIR__);
$S     = rtrim(SITE_URL, '/');

// Public marketing pages and which nav item is "current" on each.
// (member.html / donor-dashboard.html are app shells — left alone.)
$pages = [
    'index.html'   => 'home',
    'about.html'   => 'about',
    'contact.html' => 'contact',
    'donate.html'  => '',
];

$items = av_nav_items();

/** Desktop <li> list (inner of <ul class="nav-links">). */
function desktop_links(array $items, string $active): string {
    $o = "\n";
    foreach ($items as $k => [$label, $href]) {
        $cur = $k === $active ? ' aria-current="page"' : '';
        $o .= '          <li><a href="' . e($href) . '"' . $cur . '>' . e($label) . "</a></li>\n";
    }
    return $o . '        ';
}

/** Mobile drawer contents (inner of <nav class="nav-mobile">). */
function mobile_links(array $items, string $active, string $S): string {
    $o = "\n";
    foreach ($items as $k => [$label, $href]) {
        $cur = $k === $active ? ' aria-current="page"' : '';
        $o .= '    <a href="' . e($href) . '"' . $cur . '>' . e($label) . "</a>\n";
    }
    $o .= '    <div class="mobile-cta-wrap">' . "\n";
    $o .= '      <a href="https://cacentre.afrovanguard.org.ng/volunteer" class="btn btn-primary" style="width:100%;">Join the Movement</a>' . "\n";
    $o .= '      <a href="' . $S . '/donate.html" class="btn btn-outline" style="width:100%;">Donate</a>' . "\n";
    $o .= '    </div>' . "\n  ";
    return $o;
}

/** Canonical <footer>…</footer> markup, captured from the shared partial. */
function footer_html(): string {
    ob_start(); av_footer_inner(); return trim(ob_get_clean());
}

$navInner = null; // memoised per active key below
$footer   = footer_html();
$changed  = 0; $drift = 0;

foreach ($pages as $file => $active) {
    $path = $root . '/' . $file;
    if (!is_file($path)) { fwrite(STDERR, "skip (missing): $file\n"); continue; }
    $html = file_get_contents($path);
    $orig = $html;

    // 1) desktop nav links
    $html = preg_replace_callback(
        '~(<ul class="nav-links"[^>]*>).*?(</ul>)~s',
        fn($m) => $m[1] . desktop_links($items, $active) . $m[2],
        $html, 1
    );
    // 2) mobile drawer
    $html = preg_replace_callback(
        '~(<nav class="nav-mobile"[^>]*>).*?(</nav>)~s',
        fn($m) => $m[1] . mobile_links($items, $active, $S) . $m[2],
        $html, 1
    );
    // 3) footer
    $html = preg_replace('~<footer\b[^>]*>.*?</footer>~s', $footer, $html, 1);

    // 4) ensure the canonical footer stylesheet loads once, last in <head>
    //    (after the page's own inline styles, so it is authoritative).
    if (strpos($html, '/assets/site/chrome.css') === false) {
        $html = preg_replace('~</head>~', '  <link rel="stylesheet" href="/assets/site/chrome.css" />' . "\n</head>", $html, 1);
    }
    // 5) ensure the shared footer script is present once, before </body>
    if (strpos($html, '/assets/site/chrome.js') === false) {
        $html = preg_replace('~</body>~', '  <script src="/assets/site/chrome.js" defer></script>' . "\n</body>", $html, 1);
    }

    if ($html === $orig) { echo "unchanged: $file\n"; continue; }
    $drift++;
    if ($check) { echo "DRIFT:     $file (would update)\n"; continue; }
    file_put_contents($path, $html);
    $changed++;
    echo "updated:   $file\n";
}

if ($check) { echo "\n" . ($drift ? "$drift file(s) out of sync." : "All static pages in sync.") . "\n"; exit($drift ? 1 : 0); }
echo "\nDone. $changed file(s) rewritten.\n";
