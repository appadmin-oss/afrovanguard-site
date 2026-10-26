<?php
/**
 * tests/vanquest.test.php — lib/VanQuest.php: join, the seat, the fifteen-minute
 * onboarding mail, the community, the interview, the avatars.
 * Run via tests/run.php (provides ck()).
 */
declare(strict_types=1);

$sent = [];
Mailer::fakeRoads(['smtp' => static function (array $m) use (&$sent): bool { $sent[] = [$m['to'], $m['subject']]; return true; }]);
$mails = function () use (&$sent) { $s = $sent; $sent = []; return $s; };
VanQuest::ensure();
Database::pdo()->exec('DELETE FROM vq_players');

/* The token travels only by email, so the test reads it back the way the site
   would: from the link in the rendered mail. */
$lastLink = null;
$join = function (string $email, string $placement = '') use (&$lastLink) { $r = VanQuest::join($email, $placement, 50); $lastLink = $r['token'] ?? null; return $r; };

$r = $join('Ada@Example.com');
ck('vanquest: a new email joins and gets the first mail', $r['ok'] && $r['status'] === 'new' && $r['mailed'] === true);
$m = $mails();
ck('vanquest: the first mail is the seat confirmation', count($m) === 1 && $m[0][0] === 'ada@example.com' && $m[0][1] === 'Confirm your seat in the VanQuest community');
ck('vanquest: a bad address is refused', !VanQuest::join('not-an-email')['ok']);
ck('vanquest: asking again at once is held back, not mailed twice', $join('ada@example.com')['status'] === 'wait' && $mails() === []);

$t = (string) $r['token'];
ck('vanquest: the token opens the seat; a made-up one does not',
    VanQuest::byToken($t)['email'] === 'ada@example.com' && VanQuest::byToken(str_repeat('0', 48)) === null && VanQuest::byToken('x') === null);
ck('vanquest: the seat needs a first name and Male or Female',
    !VanQuest::confirm($t, '', 'female')['ok'] && !VanQuest::confirm($t, 'Ada', 'other')['ok']);
$c = VanQuest::confirm($t, '  Ada <b>', 'female');
ck('vanquest: confirming seats them, with the name cleaned', $c['ok'] && $c['player']['first_name'] === 'Ada b' && $c['player']['confirmed_at'] !== '');
VanQuest::confirm($t, 'Ada', 'female');

ck('vanquest: no onboarding mail before fifteen minutes', VanQuest::tick()['sent'] === 0 && $mails() === []);
Database::pdo()->prepare("UPDATE vq_players SET confirmed_at = ? WHERE email = 'ada@example.com'")->execute([gmdate('Y-m-d H:i:s', time() - 901)]);
$k = VanQuest::tick();
$m = $mails();
ck('vanquest: fifteen minutes after confirming, the onboarding mail goes', $k['sent'] === 1 && count($m) === 1 && str_starts_with($m[0][1], 'Ada, you’re in the community.'));
ck('vanquest: and only once', VanQuest::tick()['sent'] === 0 && $mails() === []);
ck('vanquest: onboarding keeps the link the player already has', VanQuest::byToken($t) !== null);

/* The community. */
$r2 = $join('chidi@example.com', 'B'); $mails();
VanQuest::confirm($r2['token'], 'Chidi', 'male');
$lobby = VanQuest::community(VanQuest::byToken($t));
ck('vanquest: the community shows the others by first name, letter and avatar only',
    count($lobby['people']) === 1 && $lobby['people'][0]['first_name'] === 'Chidi' && $lobby['people'][0]['letter'] === 'B'
    && !isset($lobby['people'][0]['email']) && !isset($lobby['people'][0]['phone']) && str_contains($lobby['people'][0]['avatar'], 'action=avatar'));
ck('vanquest: the community carries its numbers, read live', ($lobby['metrics']['players'] ?? null) !== null && isset($lobby['metrics']['centres']));
ck('vanquest: a new link replaces the old one', ($r3 = (Database::pdo()->exec("UPDATE vq_players SET mailed_at = '' WHERE email = 'ada@example.com'") !== false ? $join('ada@example.com') : null)) && $r3['status'] === 'seated'
    && VanQuest::byToken($t) === null && VanQuest::byToken($r3['token']) !== null);
$mails();
$t = $r3['token'];

/* The interview. */
ck('vanquest: the interview needs a phone number', !VanQuest::requestInterview($t, 'Ada', 'female', '12')['ok']);
$iv = VanQuest::requestInterview($t, 'Ada', 'female', '+234 801 234 5678', 'Weekends');
$m = $mails();
ck('vanquest: an interview request reaches Afrovanguard by email', $iv['ok'] && count($m) === 1 && str_contains($m[0][1], 'VanQuest interview request: Ada'));
ck('vanquest: and is remembered with the phone', VanQuest::byToken($t)['phone'] === '+2348012345678' && VanQuest::byToken($t)['interview_at'] !== '');

/* Avatars. */
$png = VanQuest::avatarPng('3fa1c2d9', 'f', 4);
$im = @imagecreatefromstring($png);
ck('vanquest: an avatar is a 64x64 PNG', $im !== false && imagesx($im) === 64 && imagesy($im) === 64);
ck('vanquest: the same seed draws the same avatar; a bad seed still draws one',
    VanQuest::avatarPng('3fa1c2d9', 'f') === $png && VanQuest::avatarPng('../../x', 'm') !== '');

/* The web cron runs the onboarding tick, like every other timed job. */
ck('vanquest: tasks/cron.php runs VanQuest::tick()', str_contains((string) file_get_contents(AV_ROOT . '/tasks/cron.php'), 'VanQuest::tick('));
ck('vanquest: process-contact.php hands VanQuest joins to the module',
    str_contains((string) file_get_contents(AV_ROOT . '/process-contact.php'), 'VanQuest::join('));

Mailer::fakeRoads(null);
Database::pdo()->exec('DELETE FROM vq_players');
