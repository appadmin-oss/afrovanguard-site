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
/* A mail that does not go is not reported as sent, and the player can ask again at once. */
Mailer::fakeRoads(['smtp' => static fn(array $m): bool => false]);
$u = VanQuest::join('nomail@example.com');
ck('vanquest: when the email cannot be sent, the player is told, not "an email has been sent"', !$u['ok'] && $u['status'] === 'unsent' && str_contains($u['message'], 'could not send'));
Mailer::fakeRoads(['smtp' => static function (array $m) use (&$sent): bool { $sent[] = [$m['to'], $m['subject']]; return true; }]);
ck('vanquest: …and asking again straight away is not held back', VanQuest::join('nomail@example.com')['status'] !== 'wait');
$mails();

/* VanQuest's mail comes from VanQuest, not from Afrovanguard. */
$fromSeen = null;
Mailer::fakeRoads(['smtp' => static function (array $m) use (&$fromSeen): bool { $fromSeen = $m; return true; }]);
VanQuest::join('sender.check@example.com');
ck('vanquest: its mail is from VanQuest, not Afrovanguard', ($fromSeen['from']['name'] ?? '') === 'VanQuest');
ck('vanquest: …its plain-text part is signed VanQuest too', str_ends_with(trim((string) ($fromSeen['text'] ?? '')), '— VanQuest'));
putenv('AV_VQ_FROM_EMAIL=vanquest@afrovanguard.org.ng');
VanQuest::join('sender.check2@example.com');
ck('vanquest: …from its own address when one is set, and replies go there', ($fromSeen['from']['email'] ?? '') === 'vanquest@afrovanguard.org.ng' && ($fromSeen['from']['replyTo'] ?? '') === 'vanquest@afrovanguard.org.ng');
putenv('AV_VQ_FROM_EMAIL');
VanQuest::join('sender.check3@example.com');
ck('vanquest: Afrovanguard\'s own mail still comes from Afrovanguard', Mailer::send('x@example.com', 'Hi', '<p>Hi</p>') && ($fromSeen['from']['name'] ?? '') !== 'VanQuest');
Mailer::fakeRoads(['smtp' => static function (array $m) use (&$sent): bool { $sent[] = [$m['to'], $m['subject']]; return true; }]);
Database::pdo()->exec("DELETE FROM vq_players WHERE email LIKE 'sender.check%'");

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
ck('vanquest: the interview comes after the placement test',
    (VanQuest::requestInterview($t, 'Ada', 'female', '+2348012345678')['errors']['placement'] ?? '') !== '' && $mails() === []);
VanQuest::recordPlacement(VanQuest::byToken($t), 'Z');
ck('vanquest: a placement outside O–D is not recorded', VanQuest::byToken($t)['placement'] === '');
VanQuest::recordPlacement(VanQuest::byToken($t), 'A');
VanQuest::recordPlacement(VanQuest::byToken($t), 'C');
ck('vanquest: a placement from the browser is kept, once', VanQuest::byToken($t)['placement'] === 'A');
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
