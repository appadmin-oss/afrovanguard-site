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
 * av_nav_model() is the single source. This class renders render_nav() and
 * splices the result into each static page, replacing exactly one block: the
 * `<div class="avh-chrome" data-avh-chrome>` wrapper that holds the bar, the
 * backdrop and the drawer, found by counting <div> opens and closes. Nothing
 * else in the file is touched, so a page that wraps the nav in markup of its
 * own (index and projects close a fixed `.site-top` after it) keeps it.
 *
 * `bin/sync-nav.php` writes; `tests/nav.test.php` checks. So a change to the
 * menu is made once, and a static page that falls behind fails the suite rather
 * than going unnoticed until someone clicks it.
 *
 * Rendering happens with no session, which is what a static page needs: the
 * signed-out bar, never a member baked into a file; avh-nav.js fills the
 * signed-in state in the browser.
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
        render_nav($active);
        return (string) ob_get_clean();
    }

    /** Where the navigation block starts. */
    public const OPEN = '<div class="avh-chrome" data-avh-chrome>';

    /**
     * Locate the navigation block in a page's source: from OPEN to the </div>
     * that closes it, counted rather than searched for, because the block holds
     * dozens of divs of its own.
     *
     * @return array{0:int,1:int}|null [start, length], or null when absent or unbalanced
     */
    public static function locate(string $html): ?array
    {
        $start = strpos($html, self::OPEN);
        if ($start === false) return null;
        if (!preg_match_all('~<(/?)div\b[^>]*>~i', $html, $m, PREG_OFFSET_CAPTURE, $start)) return null;
        $depth = 0;
        foreach ($m[0] as $k => $tag) {
            $depth += $m[1][$k][0] === '/' ? -1 : 1;
            if ($depth === 0) return [$start, $tag[1] + strlen($tag[0]) - $start];
        }
        return null;
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
