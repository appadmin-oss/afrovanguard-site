<?php
/**
 * tasks/cron.php — web-triggerable maintenance runner for hosts WITHOUT shell
 * cron (shared cPanel, no SSH). Authenticate with a secret key, then it drives
 * the webhook delivery/retry queue (and any future periodic jobs).
 *
 * Why this exists: db/webhooks_run.php is CLI-only and db/ is web-denied, so a
 * site with no SSH/cron had no way to retry failed webhook deliveries. Point a
 * scheduler at THIS url every few minutes — either:
 *   • a free external cron (cron-job.org, UptimeRobot, EasyCron), or
 *   • a cPanel "Cron Jobs" entry (every ~5 min) that curls it:
 *       curl -fsS "https://your-site/tasks/cron.php?key=YOUR_KEY"
 *
 *   GET /tasks/cron.php?key=SECRET[&max=50]   →  {ok:true, webhooks:{processed,ok}}
 *   (the key may also be sent as the header  X-AV-Cron-Key: SECRET)
 *
 * Key precedence: AV_CRON_KEY, else the admin token (AV_ADMIN_TOKEN); 8+ chars.
 * CLI still works:  php tasks/cron.php [max]
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/bootstrap.php';

$cli = PHP_SAPI === 'cli';
if (!$cli) {
    header('Content-Type: application/json; charset=utf-8');
    header('X-Robots-Tag: noindex');
    header('X-Content-Type-Options: nosniff');
    $want = (string) (getenv('AV_CRON_KEY') ?: (defined('ADMIN_TOKEN') ? ADMIN_TOKEN : ''));
    if (strlen($want) < 8) {
        http_response_code(503);
        echo json_encode(['ok' => false, 'error' => 'Cron key not configured. Set AV_CRON_KEY (or AV_ADMIN_TOKEN) to a random 8+ char string.']);
        exit;
    }
    $key = (string) ($_GET['key'] ?? ($_SERVER['HTTP_X_AV_CRON_KEY'] ?? ''));
    if (!hash_equals($want, $key)) { http_response_code(403); echo json_encode(['ok' => false, 'error' => 'Forbidden.']); exit; }
    if (function_exists('av_rate_ok') && !av_rate_ok('cron_web', 30, 60)) { http_response_code(429); echo json_encode(['ok' => false, 'error' => 'Rate limited.']); exit; }
}

$max = $cli
    ? (isset($argv[1]) && ctype_digit((string) $argv[1]) ? (int) $argv[1] : 50)
    : ((isset($_GET['max']) && ctype_digit((string) $_GET['max'])) ? max(1, min(200, (int) $_GET['max'])) : 50);

$result = ['ok' => true, 'at' => gmdate('c')];
if (class_exists('Webhooks')) {
    try { $result['webhooks'] = Webhooks::runQueue($max); }
    catch (Throwable $e) { $result['ok'] = false; $result['error'] = $e->getMessage(); error_log('[cron] webhooks: ' . $e->getMessage()); }
}

// Every tick: turn due reminders + imminent sessions into notifications
// (idempotent via dedupe keys; emails too when a Mailer is configured).
if (class_exists('Notifications')) {
    try { $result['notifications'] = Notifications::dispatchDue(); }
    catch (Throwable $e) { error_log('[cron] notifications: ' . $e->getMessage()); }
}

// Every tick: send the AI notetaker into meetings that are about to start.
// Covers providers that cannot be told to join later, and retries any dispatch
// that failed when the meeting was scheduled — otherwise a bot that could not be
// created (provider down, link not yet provisioned) would simply never arrive.
if (class_exists('Meetings')) {
    try { $result['meeting_bots'] = Meetings::dispatchDueBots(); }
    catch (Throwable $e) { error_log('[cron] meeting bots: ' . $e->getMessage()); }
}
// Mentorship sessions run their own Meet links, so they need the same sweep.
if (class_exists('Mentorship')) {
    try { $result['session_bots'] = Mentorship::dispatchDueSessionBots(); }
    catch (Throwable $e) { error_log('[cron] session bots: ' . $e->getMessage()); }
}

// Daily: email today's birthday people (idempotent — safe to run every tick).
if (is_file(AV_ROOT . '/lib/people.php')) {
    require_once AV_ROOT . '/lib/people.php';
    if (function_exists('av_birthday_emails_run')) {
        try { $result['birthdays'] = av_birthday_emails_run(Database::pdo()); }
        catch (Throwable $e) { error_log('[cron] birthdays: ' . $e->getMessage()); }
    }
}

if ($cli) { fwrite(STDOUT, $result['at'] . ' ' . json_encode($result) . "\n"); }
else { echo json_encode($result); }
exit(0);
