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
 * THE FIX
 * -------
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
        'index.html'          => '',
        'about.html'          => 'about',
        'contact.html'        => 'about',
        'donate.html'         => 'involved',
        'projects/index.html' => 'projects',
    ];

    /** The navigation markup for one section, exactly as a PHP page would emit it. */
    public static function region(string $active): string
    {
        ob_start();
        render_nav($active, ['theme_toggle' => true]);
        return (string) ob_get_clean();
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
     * Bring one page's navigation up to date.
     *
     * @param bool $write false only reports, which is how the test runs.
     * @return array{ok:bool,changed:bool,reason:string}
     */
    public static function sync(string $absPath, string $active, bool $write = true): array
    {
        if (!is_file($absPath)) return ['ok' => false, 'changed' => false, 'reason' => 'no such file'];
        $html = (string) file_get_contents($absPath);
        $at = self::locate($html);
        if ($at === null) return ['ok' => false, 'changed' => false, 'reason' => 'no navigation block found'];

        [$start, $len] = $at;
        $current = substr($html, $start, $len);
        $fresh   = trim(self::region($active));

        // Some pages wrap the header in markup of their own — index and
        // projects close a `.site-top` div between </header> and the drawer's
        // scrim. That close belongs to the PAGE, not to the navigation, and a
        // straight replacement would drop it and leave the wrapper open for the
        // rest of the document. Carry anything found there across.
        $carry = self::betweenHeaderAndScrim($current);
        if ($carry !== '' && strpos($fresh, $carry) === false) {
            $fresh = self::insertAfterHeader($fresh, $carry);
        }

        // Compare on collapsed whitespace: indentation differs between a file
        // and a render, and re-indenting every page on every run would bury the
        // real change in a diff nobody reads.
        $norm = static fn(string $s): string => trim((string) preg_replace('/\s+/', ' ', $s));
        if ($norm($current) === $norm($fresh)) return ['ok' => true, 'changed' => false, 'reason' => 'up to date'];

        if ($write) {
            file_put_contents($absPath, substr($html, 0, $start) . $fresh . substr($html, $start + $len));
        }
        return ['ok' => true, 'changed' => true, 'reason' => 'navigation rewritten from av_nav_model()'];
    }

    /**
     * Whatever a page keeps between its </header> and the drawer's scrim.
     *
     * Returns '' when there is nothing but whitespace and comments to keep,
     * which is the normal case.
     */
    private static function betweenHeaderAndScrim(string $region): string
    {
        $endHeader = strpos($region, '</header>');
        $scrim     = strpos($region, '<div class="scrim"');
        if ($endHeader === false || $scrim === false || $scrim < $endHeader) return '';
        $mid = substr($region, $endHeader + 9, $scrim - $endHeader - 9);
        return trim($mid) === '' ? '' : trim($mid);
    }

    /** Put the page's own markup back, immediately after the rendered header. */
    private static function insertAfterHeader(string $fresh, string $carry): string
    {
        $endHeader = strpos($fresh, '</header>');
        if ($endHeader === false) return $fresh;
        $at = $endHeader + 9;
        return substr($fresh, 0, $at) . "\n  " . $carry . substr($fresh, $at);
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
