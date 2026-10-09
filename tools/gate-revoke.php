<?php
/**
 * tools/gate-revoke.php — withdraw every CACENTRE gate pass a member holds.
 *
 *     php tools/gate-revoke.php <member id | email>
 *
 * For a lost or stolen phone, or somebody who has left. Passes minted after
 * this still work, so a member who is keeping their account just opens
 * /gate-pass again for a good one.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }

require_once dirname(__DIR__) . '/lib/bootstrap.php';

$who = trim((string) ($argv[1] ?? ''));
if ($who === '') { fwrite(STDERR, "usage: php tools/gate-revoke.php <member id | email>\n"); exit(2); }
$st = Database::pdo()->prepare(ctype_digit($who) ? 'SELECT id, name FROM lms_users WHERE id = ?' : 'SELECT id, name FROM lms_users WHERE email = ?');
$st->execute([ctype_digit($who) ? (int) $who : strtolower($who)]);
$u = $st->fetch();
if (!$u) { fwrite(STDERR, "No member $who.\n"); exit(1); }
$r = GatePass::revoke((int) $u['id']);
echo $r['ok'] ? "Passes withdrawn for {$u['name']} (#{$u['id']}).\n" : ($r['error'] . "\n");
exit($r['ok'] ? 0 : 1);
