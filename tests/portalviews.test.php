<?php
/**
 * tests/portalviews.test.php — every portal view parses.
 *
 * portal/views/inventory.php lost its closing endif in the shell rebuild. It
 * is only included when the CACENTRE bridge is configured, so every local
 * run passed while the live portal stopped half-way down the page, before
 * any of its scripts — Tasks, Volunteering, the lot — were sent.
 *
 * Run via tests/run.php (provides ck()).
 */
declare(strict_types=1);

foreach (array_merge(glob(AV_ROOT . '/portal/views/*.php') ?: [], glob(AV_ROOT . '/portal/*.php') ?: []) as $f) {
    $out = []; $rc = 0;
    exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($f) . ' 2>&1', $out, $rc);
    ck('portal: ' . substr($f, strlen(AV_ROOT) + 1) . ' parses', $rc === 0);
}
