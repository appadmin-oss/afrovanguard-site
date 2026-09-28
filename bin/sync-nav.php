<?php
/**
 * bin/sync-nav.php — write the shared navigation into the static pages.
 *
 *   php bin/sync-nav.php          rewrite any page whose nav has fallen behind
 *   php bin/sync-nav.php --check  report only; exit 1 if any page is stale
 *
 * The menu is edited in ONE place — av_nav_model() in lib/partials.php — and
 * this carries it into the five .html pages that cannot call it. See
 * lib/NavSync.php for why they exist and what gets replaced.
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_once AV_ROOT . '/lib/partials.php';

$check = in_array('--check', $argv, true);
$stale = 0; $bad = 0;

foreach (NavSync::all(!$check) as $page => $r) {
    if (!$r['ok'])          { printf("  %-24s ERROR  %s\n", $page, $r['reason']); $bad++; continue; }
    if ($r['changed'])      { printf("  %-24s %s\n", $page, $check ? 'STALE  needs sync' : 'updated'); $stale++; }
    else                    { printf("  %-24s ok\n", $page); }
}

if ($bad)   { fwrite(STDERR, "\n$bad page(s) could not be read.\n"); exit(2); }
if ($check && $stale) {
    fwrite(STDERR, "\n$stale page(s) out of date. Run: php bin/sync-nav.php\n");
    exit(1);
}
echo "\n", $check ? "All pages match av_nav_model()." : ($stale ? "$stale page(s) rewritten." : "Nothing to do."), "\n";
