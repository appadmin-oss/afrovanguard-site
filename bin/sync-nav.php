<?php
/**
 * bin/sync-nav.php — report static pages still carrying the OLD site-header nav.
 *
 *   php bin/sync-nav.php [--check]   exit 1 if any listed page still has it
 *
 * It no longer writes anything: every page wears the Home nav now
 * (partials/avh-chrome.php; static pages via php tools/build-avh-chrome.php),
 * and writing the old block back would reintroduce the old chrome.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }
require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_once AV_ROOT . '/lib/partials.php';

$check = in_array('--check', $argv, true);
$stale = 0; $bad = 0;

foreach (NavSync::all(!$check) as $page => $r) {
    if (!$r['ok'])          { printf("  %-24s ERROR  %s\n", $page, $r['reason']); $bad++; continue; }
    if ($r['changed'])      { printf("  %-24s %s\n", $page, 'STALE  old nav — move to <!-- avh:nav --> markers'); $stale++; }
    else                    { printf("  %-24s ok\n", $page); }
}

if ($bad)   { fwrite(STDERR, "\n$bad page(s) could not be read.\n"); exit(2); }
if ($stale) {
    fwrite(STDERR, "\n$stale page(s) still carry the old nav. Run: php tools/build-avh-chrome.php after adding the markers\n");
    exit(1);
}
echo "\nNo page carries the old nav.\n";
