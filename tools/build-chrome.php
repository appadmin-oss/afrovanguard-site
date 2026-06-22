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
if (!defined('AV_ROOT')) define('AV_ROOT', dirname(__DIR__));
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

/** The seven core-value cards for the About grid, built from the ethos source. */
function values_cards(array $values): string {
    $icons = [
        '<path d="M12 2l2.4 7.4H22l-6 4.4 2.3 7.2-6.3-4.6-6.3 4.6 2.3-7.2-6-4.4h7.6z"/>',
        '<circle cx="12" cy="8" r="4"/><path d="M20 21a8 8 0 10-16 0"/>',
        '<path d="M20.84 4.61a5.5 5.5 0 00-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 00-7.78 7.78L12 21.23l8.84-8.84a5.5 5.5 0 000-7.78z"/>',
        '<polyline points="9 11 12 14 22 4"/><path d="M21 12v7a2 2 0 01-2 2H5a2 2 0 01-2-2V5a2 2 0 012-2h11"/>',
        '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>',
        '<circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 014 10 15.3 15.3 0 01-4 10 15.3 15.3 0 01-4-10 15.3 15.3 0 014-10z"/>',
        '<path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87"/>',
    ];
    $o = "\n";
    foreach (array_values($values) as $i => $v) {
        $span = ($i === count($values) - 1 && count($values) % 3 === 1) ? ' cv-card--span' : '';
        $o .= '      <article class="cv-card' . $span . '">' . "\n"
            . '        <div class="cv-num" aria-hidden="true">' . sprintf('%02d', $i + 1) . "</div>\n"
            . '        <div class="cv-icon-wrap"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">' . ($icons[$i] ?? $icons[0]) . "</svg></div>\n"
            . '        <h4 class="cv-name">' . e($v[0]) . "</h4>\n"
            . '        <p class="cv-desc">' . e($v[1]) . "</p>\n"
            . "      </article>\n";
    }
    return $o . '      ';
}

/** Keep the About page's mission / vision / values in sync with the ethos. */
function sync_ethos(string $html): string {
    if (!is_file(AV_ROOT . '/lib/ethos_content.php')) return $html;
    $ethos = require AV_ROOT . '/lib/ethos_content.php';
    $html = preg_replace_callback('~(<p class="mvv-text" data-ethos="mission">).*?(</p>)~s', fn($m) => $m[1] . e($ethos['mission']) . $m[2], $html, 1);
    $html = preg_replace_callback('~(<p class="mvv-text" data-ethos="vision">).*?(</p>)~s', fn($m) => $m[1] . e($ethos['vision']) . $m[2], $html, 1);
    $cards = values_cards($ethos['values']);
    $html = preg_replace_callback('~(<!-- AV:VALUES -->).*?(<!-- /AV:VALUES -->)~s', fn($m) => $m[1] . $cards . $m[2], $html, 1);
    return $html;
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
    // 2b) About page: keep mission / vision / values synced with the ethos
    $html = sync_ethos($html);

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
