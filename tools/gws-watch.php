<?php
/**
 * tools/gws-watch.php — manage Google real-time push (watch) channels (CLI).
 *
 *   php tools/gws-watch.php start <userId>   # start calendar + drive channels
 *   php tools/gws-watch.php stop  <userId>   # stop + forget a user's channels
 *   php tools/gws-watch.php renew            # renew channels expiring within 24h
 *   php tools/gws-watch.php list
 *
 * Channels expire (~weekly), so run `renew` from cron every few hours
 * (e.g. a "0 every-6-hours" schedule):
 *   php /path/to/tools/gws-watch.php renew >/dev/null 2>&1
 *
 * Needs a PUBLIC https webhook (SITE_URL / AV_WS_WEBHOOK_URL) reachable by
 * Google and a push domain verified in Search Console. See docs/google-workspace.md.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }
require_once dirname(__DIR__) . '/lib/bootstrap.php';

$cmd = $argv[1] ?? 'list';
$uid = (int) ($argv[2] ?? 0);

if (!GoogleWorkspaceUser::configured()) {
    fwrite(STDERR, "Per-user OAuth is not configured (set AV_GOOGLE_CLIENT_ID/SECRET). See docs/google-workspace.md.\n");
    exit(1);
}

switch ($cmd) {
    case 'start':
        if ($uid <= 0) { fwrite(STDERR, "Usage: start <userId>\n"); exit(1); }
        fwrite(STDOUT, "webhook: " . GoogleWatch::webhookAddress() . "\n");
        fwrite(STDOUT, "start user $uid: " . json_encode(GoogleWatch::startAll($uid)) . "\n");
        break;
    case 'stop':
        if ($uid <= 0) { fwrite(STDERR, "Usage: stop <userId>\n"); exit(1); }
        GoogleWatch::stopForUser($uid);
        fwrite(STDOUT, "stopped channels for user $uid\n");
        break;
    case 'renew':
        fwrite(STDOUT, "renew: " . json_encode(GoogleWatch::renewExpiring()) . "\n");
        break;
    case 'list':
        foreach (GoogleWatch::all() as $c) {
            fwrite(STDOUT, sprintf("%-9s user=%-5d exp=%s  %s\n",
                $c['kind'], (int) $c['user_id'], gmdate('Y-m-d H:i', (int) $c['expiration']), $c['channel_id']));
        }
        break;
    default:
        fwrite(STDERR, "Unknown command '$cmd'. Use: start | stop | renew | list\n");
        exit(1);
}
