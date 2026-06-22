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

/** Canonical full nav (header + scrim + mobile drawer), captured from the
 *  shared partial so static pages get the exact same mega menu + drawer.
 *  Returns [headerHtml, drawerHtml] split at </header> so each can be
 *  replaced in place without a greedy gap that could swallow page content. */
function nav_parts(string $active): array {
    ob_start(); render_nav($active, ['theme_toggle' => true]); $full = trim(ob_get_clean());
    $i = strpos($full, '</header>');
    if ($i === false) return [$full, ''];
    $i += strlen('</header>');
    return [substr($full, 0, $i), trim(substr($full, $i))];
}

/** Canonical <footer>…</footer> markup, captured from the shared partial. */
function footer_html(): string {
    ob_start(); av_footer_inner(); return trim(ob_get_clean());
}

$footer  = footer_html();
$changed = 0; $drift = 0;

foreach ($pages as $file => $active) {
    $path = $root . '/' . $file;
    if (!is_file($path)) { fwrite(STDERR, "skip (missing): $file\n"); continue; }
    $html = file_get_contents($path);
    $orig = $html;

    // 1) replace the header, and (separately) the scrim+drawer, in place.
    [$navHeader, $navDrawer] = nav_parts($active);
    $html = preg_replace_callback('~<header class="site-header".*?</header>~s', fn($m) => $navHeader, $html, 1);
    $html = preg_replace_callback(
        '~(?:<div class="scrim"[^>]*>\s*</div>\s*)?<nav class="(?:nav-mobile|av-drawer)"[^>]*>.*?</nav>~s',
        fn($m) => $navDrawer,
        $html, 1
    );
    // 2) footer
    $html = preg_replace('~<footer\b[^>]*>.*?</footer>~s', $footer, $html, 1);

    // 3) no-FOUC theme boot as the first thing in <head> (shared av.theme key).
    if (strpos($html, "av.theme") === false) {
        $html = preg_replace('~(<head[^>]*>)~', '$1' . "\n  " . THEME_BOOT, $html, 1);
    }
    // 4) shared stylesheets last in <head> (after the page's inline styles).
    foreach (['/assets/site/nav.css', '/assets/site/chrome.css'] as $css) {
        if (strpos($html, $css) === false) {
            $html = preg_replace('~</head>~', '  <link rel="stylesheet" href="' . $css . '" />' . "\n</head>", $html, 1);
        }
    }
    // 5) shared scripts before </body>.
    foreach (['/assets/site/nav.js', '/assets/site/celebrations.js', '/assets/site/chrome.js'] as $js) {
        if (strpos($html, $js) === false) {
            $html = preg_replace('~</body>~', '  <script src="' . $js . '" defer></script>' . "\n</body>", $html, 1);
        }
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
