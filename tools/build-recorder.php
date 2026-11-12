<?php
/**
 * tools/build-recorder.php — package the Meeting Recorder from the command line.
 *
 * Without SSH, use the Studio page instead: /admin/recorder.php does the same
 * with one button (lib/RecorderBuild.php is shared by both).
 *
 *   php tools/build-recorder.php [--site=https://afrovanguard.org.ng] [--key=PATH] [--new-key]
 *                                [--out=downloads/meeting-recorder] [--unpacked=DIR]
 *
 * --key defaults to the server's key (db/private/recorder/meeting-recorder-key.pem,
 * or AV_REC_KEY). --new-key makes one there, and refuses if one exists.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }

$opt = getopt('', ['site:', 'key:', 'new-key', 'out:', 'unpacked:']);
if (isset($opt['key'])) putenv('AV_REC_KEY=' . $opt['key']);
if (isset($opt['out'])) putenv('AV_REC_DOWNLOADS=' . $opt['out']);
if (isset($opt['site'])) putenv('SITE_URL=' . $opt['site']);
require dirname(__DIR__) . '/lib/bootstrap.php';
require_once AV_ROOT . '/lib/RecorderBuild.php';

$site = (string) ($opt['site'] ?? SITE_URL);
if (isset($opt['new-key'])) {
    $k = RecorderBuild::createKey();
    if (!$k['ok']) { fwrite(STDERR, '✗ ' . $k['error'] . "\n"); exit(1); }
    echo '• New signing key at ' . RecorderBuild::keyPath() . " — BACK IT UP. It is the extension's identity.\n";
}
$r = RecorderBuild::build($site, null, null, $opt['unpacked'] ?? null);
if (!$r['ok']) { fwrite(STDERR, '✗ ' . $r['error'] . "\n"); exit(1); }
printf("✓ Meeting Recorder %s for %s\n  ID:          %s\n  Package:     %s (%d KB)\n  Update URL:  %s\n",
    $r['version'], $site, $r['id'], $r['crx'], (int) ceil($r['bytes'] / 1024), $r['update_url']);
