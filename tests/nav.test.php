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

// The header holds a <nav class="nav-inner"> of its own, so taking the next
// </nav> after the drawer opens would cut the block in half and the sync would
// splice mangled markup into five production pages.
$sample = NavSync::region('about');
$at = NavSync::locate($sample);
ck('nav: the navigation block is locatable in a fresh render', $at !== null);
if ($at !== null) {
    [$s, $l] = $at;
    $block = substr($sample, $s, $l);
    ck('nav: …the block starts at the header', strpos($block, '<header class="site-header"') === 0);
    ck('nav: …and ends at the drawer, not at the first inner nav',
       substr(rtrim($block), -6) === '</nav>' && strpos($block, '<nav class="av-drawer"') !== false);
    ck('nav: …opening and closing nav tags balance',
       substr_count($block, '<nav') === substr_count($block, '</nav>'));
}
ck('nav: a page with no navigation is reported, not half-written',
   NavSync::locate('<html><body><p>nothing here</p></body></html>') === null);

/* ── The sync leaves the page's own markup alone ─────────────────────────── */

// index and projects wrap the header in a `.site-top` div and close it between
// </header> and the drawer. That close belongs to the page, and the first
// version of this sync dropped it — leaving the wrapper open for the rest of
// the document on two live pages. Balance is asserted here because a stray
// unclosed div is invisible until a layout collapses.
foreach (array_keys(NavSync::PAGES) as $rel) {
    $src = (string) @file_get_contents(AV_ROOT . '/' . $rel);
    if ($src === '') continue;
    $at = NavSync::locate($src);
    if ($at === null) continue;
    // The property that matters is not that the block balances on its own —
    // index and projects legitimately close a page wrapper inside it — but
    // that running the sync does not CHANGE the document's balance. Rendered
    // in memory, so the test neither writes nor depends on the last run.
    $before = substr_count($src, '<div') - substr_count($src, '</div>');
    $fresh  = $src;
    NavSync::sync(AV_ROOT . '/' . $rel, NavSync::PAGES[$rel], false);
    $after  = substr_count($fresh, '<div') - substr_count($fresh, '</div>');
    ck('nav: syncing ' . $rel . ' leaves its div balance untouched', $before === $after);

    // Every one of these pages now balances outright. donate.html did not until
    // the stray </div> after the material-donation <style> block was removed —
    // it had closed #panel-material early, so the close labelled
    // "/#panel-material" was really shutting .container and the one labelled
    // "/.container" was closing nothing at all.
    ck('nav: ' . $rel . ' has balanced div markup', $before === 0);

    $region = substr($src, $at[0], $at[1]);
    $carriedClose = substr_count($region, '</div>') > substr_count($region, '<div');
    if ($carriedClose) {
        // Such a page closes a wrapper it opened before the header. The sync
        // must put that back; its first version dropped it and left the
        // wrapper open for the rest of two live documents.
        ck('nav: ' . $rel . ' still closes the wrapper it opens before the header',
           strpos(substr($region, strpos($region, '</header>')), '</div>') !== false);
    }
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
foreach (['sts', 'techhome', 'mediapro', 'africa-gates', 'bec', 'career-hub', 'kap'] as $slug) {
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
