<?php
/**
 * tests/reviewfixes.test.php — the review of the Meeting Recorder branch (2026-11-11).
 *
 *   1. The web-cron's key is never the admin token: AV_CRON_KEY, else a key
 *      derived from the site's secret. The admin token is refused, with a
 *      message that says why (so a cron job does not just go quiet).
 *   2. The study coach is limited per learner, not only per address.
 *
 * Run via tests/run.php (provides ck()).
 */
declare(strict_types=1);

$cronSrc = (string) file_get_contents(AV_ROOT . '/tasks/cron.php');
putenv('AV_CRON_KEY');
$k = av_cron_key();
ck('cron: with no AV_CRON_KEY the key is derived from the site secret', $k['source'] === 'derived' && strlen($k['key']) === 32);
ck('cron: the derived key is not the admin token', !defined('ADMIN_TOKEN') || (string) ADMIN_TOKEN === '' || !hash_equals((string) ADMIN_TOKEN, $k['key']));
ck('cron: it is stable', av_cron_key()['key'] === $k['key']);
putenv('AV_CRON_KEY=my-own-cron-key-123');
ck('cron: AV_CRON_KEY wins when set', av_cron_key() === ['key' => 'my-own-cron-key-123', 'source' => 'env']);
putenv('AV_CRON_KEY=short');
ck('cron: a too-short AV_CRON_KEY is ignored', av_cron_key()['source'] === 'derived');
putenv('AV_CRON_KEY');
ck('cron: the endpoint no longer falls back to ADMIN_TOKEN', !preg_match("~getenv\\('AV_CRON_KEY'\\)\\s*\\?:\\s*\\(defined\\('ADMIN_TOKEN'\\)~", $cronSrc) && str_contains($cronSrc, "av_cron_key()['key']"));
ck('cron: an old admin-token caller is told why it is refused', str_contains($cronSrc, 'The admin token is no longer accepted as the cron key'));
ck('cron: the header is read before the URL', strpos($cronSrc, "HTTP_X_AV_CRON_KEY") < strpos($cronSrc, "\$_GET['key']"));
ck('cron: the cPanel helper puts the key in a header, not the URL', str_contains((string) file_get_contents(AV_ROOT . '/tools/cpanel-cron-setup.mjs'), '-H "X-AV-Cron-Key: ${key}"'));

$who = 'u' . random_int(100000, 999999);
$okAll = true;
for ($i = 0; $i < 3; $i++) $okAll = $okAll && av_rate_ok_for('test_coach', $who, 3, 600);
ck('coach: a learner gets their allowance', $okAll);
ck('coach: and no more, wherever they ask from', !av_rate_ok_for('test_coach', $who, 3, 600));
ck('coach: another learner has their own', av_rate_ok_for('test_coach', $who . 'x', 3, 600));
ck('coach: the endpoint counts per learner', str_contains((string) file_get_contents(AV_ROOT . '/academy/api.php'), "av_rate_ok_for('lms_coach', 'u' . (int) \$u['id']"));
foreach (glob(AV_ROOT . '/db/cache/rl/who_test_coach_*') ?: [] as $f) @unlink($f);
