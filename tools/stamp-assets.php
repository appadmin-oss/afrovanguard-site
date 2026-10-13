<?php
/**
 * tools/stamp-assets.php — stamp the static pages' stylesheet and script links
 * with a hash of each file (av_stamp_html() in lib/helpers.php).
 *
 * Run after changing any CSS or JS a static page links, and before uploading:
 *   php tools/stamp-assets.php          # rewrite the pages
 *   php tools/stamp-assets.php --check  # exit 1 when a stamp is stale
 *
 * A page whose stamp matches its files can only ever load those files, however
 * long a browser or proxy has held an older copy of the same URL.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }

define('AV_ROOT', dirname(__DIR__));
require AV_ROOT . '/lib/helpers.php';

$check = in_array('--check', $argv, true);
$stale = 0;
foreach (av_static_pages() as $rel) {
    $path = AV_ROOT . '/' . $rel;
    if (!is_file($path)) continue;
    $html = (string) file_get_contents($path);
    $new  = av_stamp_html($html);
    if ($new === $html) continue;
    $stale++;
    if ($check) { echo "stale: $rel\n"; continue; }
    file_put_contents($path, $new);
    echo "stamped: $rel\n";
}
if ($check && $stale) { fwrite(STDERR, "$stale page(s) need: php tools/stamp-assets.php\n"); exit(1); }
echo $stale ? "$stale page(s) " . ($check ? 'stale' : 'stamped') . ".\n" : "All static pages current.\n";
