<?php
/**
 * tests/cardbundle.test.php — Afrovanguard cards as NGG's ID Card Studio gets them.
 *
 * The studio draws the card from this site's own partial, alone in an iframe.
 * So the bundle must be self-contained (no URL back to this site — the studio
 * cannot fetch them), must be the partial and not a second drawing, and must
 * only answer a request NGG's server signed.
 */
declare(strict_types=1);

require_once AV_ROOT . '/lib/CardBundle.php';

$cbPdo = Database::pdo();
$cbPdo->prepare('INSERT INTO lms_users (name, email, password_hash, role, status) VALUES (?, ?, ?, ?, ?)')
      ->execute(['Bundle Vanguard', 'bundle.' . bin2hex(random_bytes(3)) . '@example.test', 'x', 'member', 'active']);
$cbId = (int) $cbPdo->lastInsertId();
MemberCards::issue($cbId, 'test', 'test');
GateAttendance::assignCard($cbId, 'C-NGV-24-0042', 0);
Levels::set($cbId, 'C', 'test');

$list = CardBundle::list('bundle vanguard');
ck('list: a member with a card is listed, with the number the card prints', count($list) === 1
   && $list[0]['id'] === (string) $cbId && $list[0]['printedId'] === 'C-NGV-24-0042' && $list[0]['number'] === 'NGV-24-0042'
   && $list[0]['kind'] === 'NGV' && $list[0]['level'] === 'C');
ck('list: the card’s scan URL is given, so the studio can check the printed QR reads', str_contains($list[0]['scanUrl'], $list[0]['cardCode']));
ck('list: carries a version, so the studio redraws a card only when it changed', strlen($list[0]['version']) === 16);

$style = CardBundle::style();
ck('style: every face the card uses is inlined — the studio cannot fetch this site’s fonts',
   substr_count($style['css'], 'src:url(data:font/ttf;base64,') === 7);
ck('style: the pattern is inlined too', str_contains($style['css'], 'url("data:image/svg+xml;base64,'));
ck('style: no URL back to this site is left in it', !preg_match('~url\(["\']?/~', $style['css']));
ck('style: the seal is sent once, as data', str_starts_with($style['seal'], 'data:image/png;base64,'));
ck('style: the studio gets the face trimmed (it adds its own bleed)', str_contains($style['css'], '.avc-face.is-print{--bleed:0px}'));

$faces = CardBundle::faces([$cbId, $cbId, 999999]);
ck('faces: one per member with a card, duplicates and strangers dropped', array_map('strval', array_keys($faces)) === [(string) $cbId]);
$f = $faces[(string) $cbId];
ck('faces: it IS the partial — front and back, print mode', str_contains($f['front'], 'avc-front is-print') && str_contains($f['back'], 'avc-back is-print'));
ck('faces: the number and the level print', str_contains($f['front'], 'NGV-24-0042') && str_contains($f['front'], '<span class="avc-tier-letter">C</span>'));
ck('faces: the seal is the token, not a URL', str_contains($f['front'], 'src="__AV_SEAL__"') && !str_contains($f['front'] . $f['back'], '/assets/'));
ck('faces: at most 24 a request', count(CardBundle::faces(range(1, 60))) <= CardBundle::FACES_MAX);

$src = (string) file_get_contents(AV_ROOT . '/integrations/ngg-cards.php');
ck('endpoint: signed by NGG’s server, constant-time, five minutes', str_contains($src, "hash_hmac('sha256', 'cards.' . \$ts . '.' . \$raw, \$secret)")
   && str_contains($src, 'hash_equals(') && str_contains($src, 'abs(time() - (int) $ts) > 300'));
ck('endpoint: absent when no secret is set', str_contains($src, "\$out(['ok' => false, 'error' => 'not-configured'], 404)"));

$cbPdo->exec("DELETE FROM av_member_cards WHERE member_id = {$cbId}");
$cbPdo->exec("DELETE FROM gate_member_cards WHERE member_id = {$cbId}");
$cbPdo->exec("DELETE FROM lms_users WHERE id = {$cbId}");
