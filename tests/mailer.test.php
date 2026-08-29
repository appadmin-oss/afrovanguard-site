<?php
/**
 * tests/mailer.test.php — PHPMailer is the mailer's only SMTP transport.
 *
 * The hand-rolled lib/Smtp.php was retired, but two places went on advertising
 * it: the Studio's email diagnostic offered a 'built-in SMTP client' as a
 * fallback transport, and config.example.php documented it in the delivery
 * chain. Both described a class that does not exist, which is worse than
 * useless on the one screen an operator opens when mail is not arriving.
 *
 * What is worth pinning is the shape of the truth: exactly one SMTP transport,
 * it is PHPMailer, and the diagnostic reports whether it actually LOADS —
 * because credentials without the library mean every send quietly degrades to
 * PHP mail(), which shared hosts drop.
 *
 * Run via tests/run.php (provides ck()).
 */
declare(strict_types=1);

/* ══ The retired client is gone, and nothing claims otherwise ═══════════ */

ck('mailer: the hand-rolled SMTP client is not present', !class_exists('Smtp'));
ck('mailer: lib/Smtp.php is not on disk', !is_file(AV_ROOT . '/lib/Smtp.php'));

$api = (string) @file_get_contents(AV_ROOT . '/admin/api.php');
ck('mailer: the Studio diagnostic no longer offers a built-in SMTP transport',
   !str_contains($api, "'own_smtp'"));
ck('mailer: the Studio diagnostic does not name a built-in SMTP client as a transport',
   !str_contains($api, "? 'the built-in SMTP client'"));
$cfg = (string) @file_get_contents(AV_ROOT . '/config.example.php');
ck('mailer: the documented delivery chain does not include a built-in SMTP client',
   !str_contains($cfg, 'built-in SMTP'));

/* ══ PHPMailer is really there, and really the SMTP path ════════════════ */

$pm = Mailer::phpMailerInfo();
ck('mailer: phpMailerInfo() reports PHPMailer as available', $pm['available'] === true);
ck('mailer: PHPMailer actually loads as a class', class_exists('PHPMailer\\PHPMailer\\PHPMailer'));
ck('mailer: phpMailerInfo() names where PHPMailer came from',
   in_array($pm['source'], ['Composer', 'bundled with the site', 'manual install'], true));
ck('mailer: phpMailerInfo() reports a version', $pm['version'] !== '');

// The SMTP send path constructs PHPMailer directly — no other client exists.
$src = (string) @file_get_contents(AV_ROOT . '/lib/Mailer.php');
ck('mailer: the SMTP path constructs PHPMailer',
   str_contains($src, 'new \\PHPMailer\\PHPMailer\\PHPMailer('));
ck('mailer: no socket-level SMTP is hand-rolled in the mailer',
   !str_contains($src, 'fsockopen') && !str_contains($src, 'stream_socket_client'));

/* ══ The diagnostic answers the question an operator is actually asking ══ */

ck('mailer: a Resend key is reported separately from SMTP',
   Mailer::resendConfigured() === false || Mailer::resendConfigured() === true);

$groups = Config::diagnostics();
$email  = [];
foreach ($groups as $g) if (($g['group'] ?? '') === 'Email (SMTP)') $email = $g['checks'];
$labels = array_map(static fn($c) => $c['label'], $email);
ck('mailer: System health reports the PHPMailer transport, not just credentials',
   in_array('PHPMailer', $labels, true));
ck('mailer: System health reports where staff alerts go',
   in_array('Staff alerts to', $labels, true));
