<?php
/**
 * tools/gws-sync.php — Google Workspace sync + connectivity probe (CLI).
 *
 *   php tools/gws-sync.php probe     # report what's connected (no writes)
 *   php tools/gws-sync.php users     # provision org directory users as members
 *   php tools/gws-sync.php groups    # list directory groups
 *
 * Requires the service account + domain-wide delegation to be configured
 * (see docs/google-workspace.md). Safe to run from cron, e.g. nightly:
 *   0 2 * * *  php /path/to/tools/gws-sync.php users >/dev/null 2>&1
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }
require_once dirname(__DIR__) . '/lib/bootstrap.php';

$cmd = $argv[1] ?? 'probe';

if (!GoogleWorkspace::configured()) {
    fwrite(STDERR, "Google Workspace is not configured. Set AV_GDRIVE_SERVICE_ACCOUNT (and AV_WS_SUBJECT for the directory). See docs/google-workspace.md.\n");
    exit(1);
}

switch ($cmd) {
    case 'probe':
        foreach (GoogleWorkspace::probe() as $k => $v) {
            fwrite(STDOUT, sprintf("%-16s %s\n", $k, is_bool($v) ? ($v ? 'yes' : 'no') : (string) ($v ?? '—')));
        }
        break;
    case 'users':
        $r = GoogleWorkspace::syncDirectoryUsers();
        fwrite(STDOUT, "Directory user sync: " . json_encode($r) . "\n");
        break;
    case 'groups':
        foreach (GoogleWorkspace::directoryGroups() as $g) {
            fwrite(STDOUT, sprintf("%-40s %s (%d)\n", $g['email'], $g['name'], $g['count']));
        }
        break;
    default:
        fwrite(STDERR, "Unknown command '$cmd'. Use: probe | users | groups\n");
        exit(1);
}
