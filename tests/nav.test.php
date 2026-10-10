<?php
/**
 * tests/nav.test.php — one navigation, and the links in it go somewhere.
 *
 * One menu for the whole site: the Home nav. PHP pages get it from render_nav()
 * (which now emits partials/avh-chrome.php), static pages from the avh:nav
 * markers (tests/avhchrome.test.php). A navigation that differs between pages
 * is worse than one that is merely wrong, because a reader cannot learn it
 * (WCAG 3.2.3) — so the old site-header nav is asserted gone, everywhere.
 *
 * av_nav_model() is still checked below: Chioma's site knowledge and the link
 * audit read it, and its destinations must exist.
 *
 * Run via tests/run.php (provides ck()).
 */
declare(strict_types=1);

require_once AV_ROOT . '/lib/partials.php';

/* ── One navigation: the Home nav, everywhere ────────────────────────────── */

// render_nav() emits the Home nav (partials/avh-chrome.php), the same markup
// index.html and every redesigned page carries. The old site-header nav must
// not come back through any door: a PHP page, NavSync, or a static page.
require_once AV_ROOT . '/partials/avh-chrome.php';
$homeNav = avh_chrome_html()['nav'];
ob_start(); render_nav('about'); $rendered = (string) ob_get_clean();
ck('nav: render_nav() emits the Home nav', str_contains($rendered, '<nav class="avh-nav"') && str_contains($rendered, 'id="avh-drawer"'));
ck('nav: …and not the old site-header nav', !str_contains($rendered, 'site-header') && !str_contains($rendered, 'av-drawer') && !str_contains($rendered, 'id="avSearch"'));
ck('nav: …which is the Home nav plus the section marks, nothing else',
   trim(str_replace([' data-avh-current', ' aria-current="page"'], '', $rendered)) === $homeNav);
ck('nav: the section the page is in is marked (menu button and drawer)',
   str_contains($rendered, 'data-avh-menu="about" data-avh-current') && str_contains($rendered, 'aria-controls="avh-acc-about" data-avh-current'));
ck('nav: only that section is marked', substr_count($rendered, 'data-avh-current') === 2);
ck('nav: a section with no menu of its own marks its parent (mentorship → Get involved)',
   str_contains(av_site_nav('mentorship'), 'data-avh-menu="involved" data-avh-current'));
ck('nav: no section marks nothing', !str_contains(av_site_nav(''), 'data-avh-current'));
$here = av_site_nav('involved', '/franchise');
ck('nav: links to the page being viewed carry aria-current="page"', str_contains($here, '<a href="/franchise" aria-current="page">'));
ck('nav: …every one of them, and only them', substr_count($here, 'aria-current="page"') === substr_count($homeNav, '<a href="/franchise">'));
ck('nav: NavSync renders the same Home nav', NavSync::region('about') === av_site_nav('about'));

ob_start(); render_footer(); $foot = (string) ob_get_clean();
ck('nav: render_footer() emits the Home footer', str_contains($foot, avh_chrome_html()['foot']));
ck('nav: …and not the old site footer', !str_contains($foot, 'site-footer') && !str_contains($foot, 'footer-grid'));
ck('nav: …and closes the .avh-page wrapper render_head() opened', preg_match('~</footer>\s*</div>~', $foot) === 1);

// The head: a site page loads the new system and opens Home's structure; an
// app shell (portal, mentor portal, Workspace) keeps its own head and theme.
$headOf = static function (array $o): string {
    ob_start(); render_head($o + ['title' => 'T', 'canonical' => 'https://x.test/']); return (string) ob_get_clean();
};
$sh = $headOf(['body_class' => 'fr-page']);
foreach (['/assets/site/av-tokens.css', '/assets/site/avh.css', '/assets/site/avpg.css', '/assets/site/fonts.css'] as $css) {
    ck("nav: a site page's head loads $css", str_contains($sh, 'href="' . $css . '"'));
}
ck("nav: …and avh.js, deferred", str_contains($sh, '<script src="/assets/site/avh.js" defer></script>'));
ck("nav: …not the old nav stylesheet", !str_contains($sh, '/assets/site/nav.css'));
ck('nav: …body.avh with the page class kept', str_contains($sh, '<body class="avh avpg fr-page" id="top"'));
ck('nav: …the Home skip link to #main, then the .avh-page wrapper',
   preg_match('~<a class="avh-skip" href="#main">Skip to content</a>.*<div class="avh-page">~s', $sh) === 1);
ck('nav: …forced light, and locked (the Home design has no dark set)',
   str_contains($sh, 'data-theme="light" data-theme-lock') && !str_contains($sh, "localStorage.getItem('av.theme')"));
$ah = $headOf(['chrome' => 'app', 'body_class' => 'portal-app']);
ck('nav: an app shell keeps its own head (no Home chrome, its theme boot)',
   !str_contains($ah, 'avh.css') && !str_contains($ah, 'avh-page') && str_contains($ah, "localStorage.getItem('av.theme')"));
av_chrome_mode('site');
foreach (['portal/index.php', 'workspace.php', 'mentorship/mentor/index.php'] as $shell) {
    ck("nav: $shell opts out as an app shell", str_contains((string) file_get_contents(AV_ROOT . '/' . $shell), "'chrome'     => 'app'"));
}

// The old block finder still works — it is how a straggler is caught.
$legacy = '<header class="site-header"><nav class="nav-inner"></nav></header><div class="scrim"></div><nav class="av-drawer"><nav></nav></nav><p>after</p>';
$at = NavSync::locate($legacy);
ck('nav: the old navigation block is locatable', $at !== null && substr($legacy, $at[0], $at[1]) === substr($legacy, 0, strpos($legacy, '<p>')));
ck('nav: a page with no old navigation is reported as such',
   NavSync::locate('<html><body><p>nothing here</p></body></html>') === null);

// No public page carries the old nav. member.html and donor-dashboard.html are
// app shells with headers of their own; projects/sts is its own (Astro) site.
$stragglers = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(AV_ROOT, FilesystemIterator::SKIP_DOTS));
foreach ($it as $file) {
    $p = $file->getPathname();
    if (!str_ends_with($p, '.html')) continue;
    if (preg_match('~/(vendor|node_modules|\.claude|\.git)/|/projects/sts/~', $p)) continue;
    if (NavSync::locate((string) file_get_contents($p)) !== null) $stragglers[] = substr($p, strlen(AV_ROOT) + 1);
}
ck('nav: no static page carries the old nav' . ($stragglers ? ': ' . implode(', ', $stragglers) : ''), $stragglers === []);
foreach (NavSync::all(false) as $page => $r) {
    ck('nav: ' . $page . ' carries no old nav', $r['ok'] && !$r['changed']);
}

/* ── Every internal destination exists ───────────────────────────────────── */

$model = av_nav_model();
$targets = [];
foreach ($model as $top) {
    $targets[$top['href']] = $top['label'];
    foreach (($top['mega']['cols'] ?? []) as $col) {
        foreach ($col['links'] as [$label, $href]) $targets[$href] = $label;
    }
    if (!empty($top['mega']['feature']['href'])) $targets[$top['mega']['feature']['href']] = $top['label'] . ' feature';
}

// A menu entry pointing at a path with no page is a dead end the whole site
// links to. Resolved against the tree, not over HTTP, so the suite needs no
// server. Known dynamic routes are listed because they have no file of their own.
$routed = ['/portal/', '/login', '/franchise', '/how-it-works', '/ethos/', '/events/', '/IQ/', '/diary/'];
foreach ($targets as $href => $label) {
    if ($href === '' || $href[0] !== '/') continue;          // off-site is checked below
    // A menu link may carry a query, a fragment or both; the page is the part
    // before either. /diary/?q=Technology is the Diary, filtered.
    $path = (string) (parse_url($href, PHP_URL_PATH) ?: '');
    if ($path === '' || $path === '/') continue;
    if (in_array(rtrim($path, '/') . '/', $routed, true) || in_array($path, $routed, true)) continue;
    $rel  = ltrim($path, '/');
    $hit  = is_file(AV_ROOT . '/' . $rel)
         || is_file(AV_ROOT . '/' . rtrim($rel, '/') . '/index.php')
         || is_file(AV_ROOT . '/' . rtrim($rel, '/') . '/index.html')
         || is_file(AV_ROOT . '/' . rtrim($rel, '/') . '.php');
    ck('nav: "' . $label . '" resolves to a real page (' . $path . ')', $hit);
}

/* ── Local pages are preferred over the subdomains ───────────────────────── */

// Every flagship programme has a page on this site. The menu used to send all
// of them to cacentre/next instead, which orphaned the local pages and pushed
// every visitor off-site to read about a programme.
/* africa-gates is not in this list: it has its own site now, and what is left
   at /projects/africa-gates/ is a redirect to it, not a page. The is_file
   guard below cannot tell those apart — a redirect stub is a file — so the
   exception is named here rather than by loosening the rule for everyone. */
foreach (['sts', 'techhome', 'mediapro', 'bec', 'career-hub', 'kap'] as $slug) {
    if (!is_file(AV_ROOT . '/projects/' . $slug . '/index.html')
        && !is_file(AV_ROOT . '/projects/' . $slug . '/index.php')) continue;
    ck('nav: /projects/' . $slug . '/ is linked rather than its subdomain twin',
       isset($targets['/projects/' . $slug . '/']));
}
ck('nav: no flagship programme still points at the CACENTRE subdomain',
   !array_filter(array_keys($targets), static fn($h) =>
       strpos($h, 'cacentre.afrovanguard.org.ng/') !== false
       && !in_array($h, ['https://cacentre.afrovanguard.org.ng', AV_VOLUNTEER_URL], true)));

/* ── One destination, one name ───────────────────────────────────────────── */

// WCAG 3.2.4: the same function is identified consistently. Two names for one
// page also split the analytics for it.
$byHref = [];
foreach ($model as $top) {
    foreach (($top['mega']['cols'] ?? []) as $col) {
        foreach ($col['links'] as [$label, $href]) $byHref[$href][$label] = true;
    }
}
$clash = [];
foreach ($byHref as $href => $labels) {
    // Contact legitimately answers several intents ("Contact us", "Partner with
    // us"); the menu entries that name one PAGE should not disagree.
    if ($href === '/contact.html' || $href === '/academy/' || $href === '/diary/') continue;
    if (count($labels) > 1) $clash[] = $href . ' (' . implode(' / ', array_keys($labels)) . ')';
}
ck('nav: no page is listed under two different names' . ($clash ? ': ' . implode(', ', $clash) : ''), $clash === []);

/* ── Off-site links are marked ───────────────────────────────────────────── */

[$attrs, $mark] = av_nav_offsite('https://cacentre.afrovanguard.org.ng/x');
ck('nav: an off-site link opens in a new tab', strpos($attrs, 'target="_blank"') !== false);
ck('nav: …with noopener', strpos($attrs, 'rel="noopener"') !== false);
ck('nav: …and says where it goes, in text', strpos($mark, 'cacentre.afrovanguard.org.ng') !== false
   && strpos($mark, 'sr-only') !== false);
ck('nav: …with the arrow hidden from assistive tech', strpos($mark, 'aria-hidden="true"') !== false);

[$sameAttrs, $sameMark] = av_nav_offsite('/academy/');
ck('nav: a same-site link is not marked', $sameAttrs === '' && $sameMark === '');
[$selfAttrs] = av_nav_offsite(rtrim(SITE_URL, '/') . '/academy/');
ck('nav: an absolute link to our own host is not marked either', $selfAttrs === '');
[$wwwAttrs] = av_nav_offsite('https://www.' . parse_url(SITE_URL, PHP_URL_HOST) . '/academy/');
ck('nav: …nor is the www form of our own host', $wwwAttrs === '');

/* ── The public menu does not point at gated pages ───────────────────────── */

// /mentorship/ is the signed-in tool: noindex, behind a login gate. A public
// menu that sends anonymous visitors there dead-ends them at a sign-in wall.
ck('nav: the public menu links the mentor page, not the gated tool',
   isset($targets['/mentorship/become-a-mentor/']) && !isset($targets['/mentorship/']));
