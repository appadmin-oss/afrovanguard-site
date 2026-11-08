<?php
/**
 * lib/Analytics.php — puts the Google Analytics tag on every page, once.
 *
 * There are over a hundred hand-written <head>s in this tree. Adding the tag
 * to each is how one page ends up without it and a new page is born without
 * it; so the bootstrap buffers HTML output and this adds one script tag before
 * the first </head>. The tag itself (the measurement id, what is and is not
 * sent) lives in /assets/site/ga.js and nowhere else. The static .html pages,
 * which PHP never sees, carry the same line; tests/analytics.test.php holds both.
 */
declare(strict_types=1);

final class Analytics
{
    public const SRC = '/assets/site/ga.js';

    /** The script tag, versioned by content like every other asset (av_asset), so a change reaches browsers at once. */
    public static function tag(): string
    {
        $src = function_exists('av_asset') ? av_asset(self::SRC) : self::SRC;
        return '<script src="' . htmlspecialchars($src, ENT_QUOTES) . '" async></script>';
    }

    /** Paths whose pages are never tagged: staff tools. ga.js checks the same list in the browser. */
    private const SKIP = '~^/(admin|academy/studio|card)(/|$)~';

    /** Start buffering a web request's output so the tag can be added. */
    public static function start(): void
    {
        if (PHP_SAPI === 'cli' || getenv('AV_ANALYTICS_OFF') === '1') return;
        $path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
        if (preg_match(self::SKIP, $path)) return;
        /* Chunked so a large download (a backup, an export) still streams;
           the <head> of a page always falls inside the first chunk. */
        ob_start([self::class, 'filter'], 1 << 16);
    }

    /** The output callback: tag HTML responses, pass everything else through untouched. */
    public static function filter(string $chunk, int $phase): string
    {
        static $done = false;
        if ($done || !self::isHtml()) return $chunk;
        $out = self::inject($chunk);
        if ($out !== $chunk) $done = true;
        return $out;
    }

    /** Add the tag before the first </head>, unless the page already has it. */
    public static function inject(string $html): string
    {
        if (str_contains($html, self::SRC)) return $html;
        $i = stripos($html, '</head>');
        return $i === false ? $html : substr($html, 0, $i) . self::tag() . "\n" . substr($html, $i);
    }

    /** A response is HTML when it says so, or says nothing (PHP's default type). */
    private static function isHtml(): bool
    {
        foreach (headers_list() as $h) {
            if (stripos($h, 'content-type:') === 0) return stripos($h, 'text/html') !== false;
        }
        return true;
    }
}
