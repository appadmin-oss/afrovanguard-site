<?php
/**
 * lib/NavSync.php — one navigation, written into the pages that cannot render it.
 *
 * THE PROBLEM THIS EXISTS FOR
 * --------------------------
 * The site has two navigations. PHP pages call render_nav() and get whatever
 * av_nav_model() currently says. Five static pages — index, about, contact,
 * donate and projects — carry their own hand-maintained copy of the same
 * markup, because they are .html and cannot call it.
 *
 * They had already drifted. The static copy still sent every flagship programme
 * to a subdomain after the PHP menu had been pointed at the local pages, and it
 * carried no aria-current at all, so those pages never told anyone which section
 * they were in. Every nav change had to be made twice, by hand, in files of
 * several thousand lines — and a navigation that differs between pages is worse
 * than one that is merely wrong, because the reader cannot learn it
 * (WCAG 3.2.3, Consistent Navigation).
 *
 * SINCE THE REDESIGN
 * ------------------
 * render_nav() emits the Home nav (partials/avh-chrome.php) and the static pages
 * carry the same markup via tools/build-avh-chrome.php, so there is no second
 * navigation left to sync. This class keeps locate() — the finder for the OLD
 * block — so tests/nav.test.php can assert no page still carries it; sync()
 * only reports and never writes.
 *
 * THE FIX (historical)
 * --------------------
 * av_nav_model() is the single source. This class renders it and splices the
 * result into each static page between the boundaries the markup already has:
 * from `<header class="site-header"` to the closing tag of the drawer that
 * follows it. Nothing else in the file is touched.
 *
 * `bin/sync-nav.php` writes; `tests/nav.test.php` checks. So a change to the
 * menu is made once, and a static page that falls behind fails the suite rather
 * than going unnoticed until someone clicks it.
 *
 * Rendering happens with no session, which is what a static page needs: the
 * signed-out utility bar, never a half-rendered account menu baked into a file.
 */
declare(strict_types=1);

final class NavSync
{
    /**
     * The static pages, and the top-level nav section each one belongs to.
     *
     * The key is the section a reader is in, not the page's own name — Contact
     * lives under About in the menu, so that is what gets aria-current. The
     * homepage has no top-level item of its own and so marks nothing.
     */
    public const PAGES = [
        /* Not index.html: the home page carries its own navigation since the
           row-4 redesign (assets/site/avh.js — mega menu at ≥1180px, drawer
           below). Splicing this header into it would put the old nav back. */
        /* Nor about, contact, donate or projects: rows 5–8 of the redesign give
           them the Home nav too (partials/avh-chrome.php, spliced by
           tools/build-avh-chrome.php). Nothing static is left to sync. */
    ];

    /**
     * The navigation markup for one section, exactly as a PHP page emits it —
     * the Home nav (partials/avh-chrome.php) with the section marked, since
     * render_nav() switched to it. Never the retired site-header markup.
     */
    public static function region(string $active): string
    {
        return av_site_nav($active);
    }

    /**
     * Locate the navigation block in a page's source.
     *
     * The block runs from the opening <header class="site-header"> to the end of
     * the drawer <nav> that follows it. The closing tag is found by counting
     * opens and closes rather than by taking the next `</nav>`, because the
     * header contains a <nav class="nav-inner"> of its own and a naive search
     * would cut the block in half.
     *
     * @return array{0:int,1:int}|null [start, length], or null when absent
     */
    public static function locate(string $html): ?array
    {
        $start = strpos($html, '<header class="site-header"');
        if ($start === false) return null;

        $drawer = strpos($html, '<nav class="av-drawer"', $start);
        if ($drawer === false) return null;

        $depth = 0; $i = $drawer;
        while (true) {
            $open  = strpos($html, '<nav', $i);
            $close = strpos($html, '</nav>', $i);
            if ($close === false) return null;
            if ($open !== false && $open < $close) { $depth++; $i = $open + 4; continue; }
            $depth--;
            $i = $close + 6;
            if ($depth === 0) return [$start, $i - $start];
        }
    }

    /**
     * Report one page's navigation.
     *
     * The sync used to splice the old site-header nav into static pages. Every
     * page now carries the Home nav (tools/build-avh-chrome.php for .html, the
     * partial for PHP), so writing that block back would reintroduce the old
     * chrome. What is left is the check: a page still carrying the old block is
     * reported as stale (move it to the <!-- avh:nav --> markers); nothing is
     * ever written.
     *
     * @return array{ok:bool,changed:bool,reason:string}
     */
    public static function sync(string $absPath, string $active, bool $write = true): array
    {
        if (!is_file($absPath)) return ['ok' => false, 'changed' => false, 'reason' => 'no such file'];
        $html = (string) file_get_contents($absPath);
        if (self::locate($html) !== null) {
            return ['ok' => true, 'changed' => true, 'reason' => 'still carries the old site-header nav; use the <!-- avh:nav --> markers and php tools/build-avh-chrome.php'];
        }
        return ['ok' => true, 'changed' => false, 'reason' => 'no old navigation'];
    }

    /** Every page, reported or rewritten. @return array<string,array> */
    public static function all(bool $write = true): array
    {
        $root = defined('AV_ROOT') ? AV_ROOT : dirname(__DIR__);
        $out = [];
        foreach (self::PAGES as $rel => $active) {
            $out[$rel] = self::sync($root . '/' . $rel, $active, $write);
        }
        return $out;
    }
}
