<?php
/**
 * thumb.php — one of our uploaded images, resized to a width on the fixed
 * ladder (lib/Thumbs.php). Made once, then served from /uploads/thumbs/ with
 * a year-long cache: the source path and its size and time are in the file
 * name, so a replaced upload is a new thumbnail, never a stale one.
 *
 *   /thumb.php?w=320&src=/uploads/diary/20261104-….jpg
 */
declare(strict_types=1);
require_once __DIR__ . '/lib/bootstrap.php';

$w = (int) ($_GET['w'] ?? 0);
$src = (string) ($_GET['src'] ?? '');
if (!in_array($w, Thumbs::WIDTHS, true)) { http_response_code(404); exit; }
$t = Thumbs::make($src, $w);
if ($t === null) {
    /* Not a thumbnail we can make: send the browser to the original rather
       than show a broken image — but only for a path that is one of ours. */
    if (Thumbs::localImage((string) parse_url($src, PHP_URL_PATH)) !== null) { header('Location: ' . $src, true, 302); exit; }
    http_response_code(404); exit;
}
[$file, $mime] = $t;
header('Content-Type: ' . $mime);
header('Content-Length: ' . (string) filesize($file));
header('Cache-Control: public, max-age=31536000, immutable');
header('X-Content-Type-Options: nosniff');
readfile($file);
