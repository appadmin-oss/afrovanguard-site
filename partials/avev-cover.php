<?php
/**
 * partials/avev-cover.php — the Events page's name for the shared cover.
 *
 * The cover was built here first (row 13) and is now the one renderer every
 * page uses for anything without a photo: partials/av-cover.php (styles
 * assets/site/avcv.css, browser twin assets/site/avcv.js). This keeps the
 * Events call sites as they were.
 */
declare(strict_types=1);

require_once __DIR__ . '/av-cover.php';

/** FNV-1a over the title, as the design hashes it (32-bit, then absolute). */
function avev_cover_hash(string $s): int
{
    return av_cover_hash($s);
}

/**
 * @param array{title:string,category?:string,date?:string,end?:string,time?:string,location?:string,ratio?:string,today?:string} $o
 *   date / end / today are Y-m-d; ratio is '3:2' (default) or '1.91:1'.
 */
function avev_cover(array $o): string
{
    return av_cover(['kind' => 'event'] + $o + ['ratio' => '3:2', 'category' => 'general']);
}
