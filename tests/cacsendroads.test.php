<?php
/**
 * tests/cacsendroads.test.php — CACENTRE's mail, sent here, only by a road
 * that delivers.
 *
 * mail() on this host says "sent" for mail the recipient's provider bins.
 * Taken for CACENTRE, CACENTRE believed it had delivered and tried nothing
 * else, and its email did not arrive. So CacSend refuses mail(), and a batch
 * (CACENTRE's Wi-Fi starting passwords) stays off the Apps Script allowance
 * this site's own sign-in codes need. Starting passwords CACENTRE could not
 * send are sent from here instead (CacWifi::mailStartCodes, from the cron).
 */
declare(strict_types=1);

/* Mail is off in the suite; lifted only while the roads are fakes, so nothing real leaves.
   Bulk sends never take Apps Script, so these are marked bulk to keep it out of the test. */
putenv('AV_MAIL_DISABLED=0');
$sentBy = [];
Mailer::fakeRoads(['smtp' => static fn() => false, 'relay' => static fn() => false, 'resend' => static fn() => false,
                   'host' => static function () use (&$sentBy) { $sentBy[] = 'host'; return true; }]);
ck('mail roads: an ordinary message may still fall back to mail()', Mailer::send('ada@example.org', 'Hi', '<p>Hi</p>', ['bulk' => true]) === true && $sentBy === ['host']);
$sentBy = [];
ck('mail roads: a caller that refuses mail() is never sent through it', Mailer::send('ada@example.org', 'Hi', '<p>Hi</p>', ['noHost' => true, 'bulk' => true]) === false && $sentBy === []);
Mailer::fakeRoads(null);
putenv('AV_MAIL_DISABLED=1');

$src = (string) file_get_contents(AV_ROOT . '/lib/CacSend.php');
ck('mail roads: CACENTRE\'s mail refuses mail(), and a batch keeps off the Apps Script', str_contains($src, "'noHost' => true, 'bulk' => !empty(\$m['batch'])"));
ck('mail roads: Wi-Fi starting passwords CACENTRE could not send are sent from here, by the cron',
   method_exists('CacWifi', 'mailStartCodes') && str_contains((string) file_get_contents(AV_ROOT . '/tasks/cron.php'), 'CacWifi::mailStartCodes()'));
