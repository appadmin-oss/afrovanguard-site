<?php
/**
 * tests/nav.test.php — one navigation, and the links in it go somewhere.
 *
 * The site renders its menu twice: PHP pages call render_nav(), and five .html
 * pages carry a copy because they cannot. They drifted — the static copy still
 * sent every flagship programme to a subdomain after the PHP menu had been
 * pointed at the local pages, and it marked no current section at all. A
 * navigation that differs between pages is worse than one that is merely
 * wrong, because a reader cannot learn it (WCAG 3.2.3).
 *
 * So the parity is asserted here rather than left to whoever remembers. If this
 * fails, the fix is one command: php bin/sync-nav.php
 *
 * Run via tests/run.php (provides ck()).
 */
declare(strict_types=1);

require_once AV_ROOT . '/lib/partials.php';

/* ── The two navigations agree ───────────────────────────────────────────── */

foreach (NavSync::all(false) as $page => $r) {
    ck('nav: ' . $page . ' carries the shared navigation', $r['ok']);
    ck('nav: ' . $page . ' is in step with av_nav_model() (run php bin/sync-nav.php)', !$r['changed']);
}

/* ── The block finder is what makes that safe to rewrite ─────────────────── */

// The block holds dozens of divs of its own, so the finder counts opens and
// closes; taking the first </div> would cut it in half and the sync would
// splice mangled markup into five production pages.
$sample = NavSync::region('about');
$at = NavSync::locate($sample);
ck('nav: the navigation block is locatable in a fresh render', $at !== null);
if ($at !== null) {
    $block = substr($sample, $at[0], $at[1]);
    ck('nav: …the block starts at the chrome wrapper', strpos($block, NavSync::OPEN) === 0);
    ck('nav: …and holds the bar, the backdrop and the drawer',
       strpos($block, 'data-avh-nav') !== false && strpos($block, 'data-avh-backdrop') !== false && strpos($block, 'data-avh-drawer') !== false);
    ck('nav: …and ends where it balances', trim(substr($sample, $at[0] + $at[1])) === ''
       && substr_count($block, '<div') === substr_count($block, '</div>'));
}
ck('nav: a page with no navigation is reported, not half-written',
   NavSync::locate('<html><body><p>nothing here</p></body></html>') === null);

/* ── The sync leaves the page's own markup alone ─────────────────────────── */

// index and projects wrap the nav in a fixed `.site-top` div and close it after
// the block. That close belongs to the page; a stray unclosed div is invisible
// until a layout collapses, so every page is asserted to balance outright.
foreach (array_keys(NavSync::PAGES) as $rel) {
    $src = (string) @file_get_contents(AV_ROOT . '/' . $rel);
    if ($src === '') continue;
    ck('nav: ' . $rel . ' has balanced div markup', substr_count($src, '<div') - substr_count($src, '</div>') === 0);
    ck('nav: ' . $rel . ' loads the nav styles and script',
       str_contains($src, '/assets/site/avh-nav.css') && str_contains($src, '/assets/site/av-tokens.css') && str_contains($src, '/assets/site/avh-nav.js'));
    ck('nav: ' . $rel . ' no longer loads the old nav', !str_contains($src, 'href="/assets/site/nav.css"') && !str_contains($src, 'src="/assets/site/nav.js"'));
}

/* ── The bar is the handoff's, and a static page never carries a member ──── */

$render = NavSync::region('academy');
ck('nav: every section has a menu button, a mega panel and a drawer accordion',
   array_reduce(array_keys(av_nav_model()), static fn($ok, $k) => $ok
       && str_contains($render, 'data-avh-menu="' . $k . '"')
       && str_contains($render, 'id="avh-mega-' . $k . '"')
       && str_contains($render, 'id="avh-acc-' . $k . '"'), true));
ck('nav: the drawer is a modal dialog the burger controls',
   str_contains($render, 'role="dialog" aria-modal="true"') && str_contains($render, 'aria-controls="avh-drawer"'));
ck('nav: the signed-in parts render as placeholders only (avh-nav.js fills them)',
   str_contains($render, 'data-avh-me-name>Member<') && !str_contains($render, '@'));
ck('nav: the Academy keeps its section bar', str_contains($render, 'class="avh-sub"'));
ck('nav: other sections have no section bar', !str_contains(NavSync::region('about'), 'class="avh-sub"'));
$js = (string) @file_get_contents(AV_ROOT . '/assets/site/avh-nav.js');
ck('nav: the member name is written as text, never as markup',
   str_contains($js, 'textContent = name') && !str_contains($js, 'innerHTML'));
ck('nav: sign-out and sign-in links still work on pages without the nav (the portal)',
   strpos($js, "'[data-logout]'") !== false && strpos($js, "'[data-logout]'") < strpos($js, 'if (!root) return;'));

/* ── Every internal destination exists ───────────────────────────────────── */

$model = av_nav_model();
$targets = [];
foreach ($model as $top) {
    $targets[$top['href']] = $top['label'];
    foreach (($top['cols'] ?? []) as $col) {
        foreach ($col['links'] as [$label, $href]) $targets[$href] = $label;
    }
    if (!empty($top['feature']['href'])) $targets[$top['feature']['href']] = $top['label'] . ' feature';
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
    foreach (($top['cols'] ?? []) as $col) {
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
   && strpos($mark, 'avh-sr') !== false);
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
