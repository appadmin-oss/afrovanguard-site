<?php
/**
 * tests/memberrequests.test.php — a member asks from their own portal
 * (portal → Requests), and an admin decides in the Studio (lib/MemberRequests.php).
 */
declare(strict_types=1);

$qPdo = Database::pdo();
MemberRoster::ensure();
MemberRequests::ensure();
Centres::ensure();
$qPdo->exec('DELETE FROM av_member_requests');
$qPdo->exec("DELETE FROM member_profiles WHERE user_id IN (SELECT id FROM lms_users WHERE email LIKE '%@mreq.test')");
$qPdo->exec("DELETE FROM lms_users WHERE email LIKE '%@mreq.test'");
foreach (['Egbeda', 'Ayobo'] as $c) if (Centres::canonical($c) === null) Centres::save(['name' => $c], 'tester');

$tola = (int) MemberRoster::create(['name' => 'Tola Ade', 'email' => 'tola@mreq.test', 'centre' => 'Egbeda', 'phone' => '08030000001'], 'tester')['id'];
$adminCtx = ['studio_role' => 'admin', 'self_id' => 999999];

/* ── asking ────────────────────────────────────────────────────────────── */
ck('requests: a member asks to move centre', ($a = MemberRequests::submit($tola, ['kind' => 'centre', 'centre' => 'ayobo', 'message' => 'Nearer home']))['ok']);
ck('requests: …and nothing about their record has changed', MemberRoster::get($tola)['centre'] === 'Egbeda');
ck('requests: asking the same thing twice is refused while it waits', !MemberRequests::submit($tola, ['kind' => 'centre', 'centre' => 'Ayobo'])['ok']);
ck('requests: a centre not on the list is refused', !MemberRequests::submit($tola, ['kind' => 'centre', 'centre' => 'Atlantis'])['ok']);
ck('requests: asking for the centre you are at is refused, in words', str_contains((string) MemberRequests::submit($tola, ['kind' => 'centre', 'centre' => 'Egbeda'])['error'], 'already at Egbeda'));
ck('requests: a details request is checked as the desk would check it — a phone too short is refused now, not at approval',
    !MemberRequests::submit($tola, ['kind' => 'details', 'phone' => '12'])['ok']);
$d = MemberRequests::submit($tola, ['kind' => 'details', 'phone' => '8031112222']);
ck('requests: a member asks to correct their phone', $d['ok']);
ck('requests: pausing needs a word on what and when', !MemberRequests::submit($tola, ['kind' => 'pause'])['ok']);
$p = MemberRequests::submit($tola, ['kind' => 'pause', 'message' => 'Pause from December']);
ck('requests: …and is taken with one', $p['ok']);
ck('requests: an unknown kind is refused', !MemberRequests::submit($tola, ['kind' => 'promote_me', 'message' => 'x'])['ok']);
$mine = MemberRequests::mine($tola);
ck('requests: the member sees their own requests, newest first, all waiting', count($mine) === 3 && $mine[0]['kind'] === 'pause' && !array_filter($mine, fn($r) => $r['status'] !== 'pending'));

/* ── deciding ──────────────────────────────────────────────────────────── */
$q = MemberRequests::queue('pending');
ck('requests: the Studio sees every waiting request with who asked, in a sentence', $q['pending'] === 3
    && str_contains($q['requests'][2]['summary'], 'Tola Ade asks to move to Ayobo') && $q['requests'][2]['member']['email'] === 'tola@mreq.test');
ck('requests: a member cannot decide their own (if they are also an admin)', !MemberRequests::decide((int) $a['id'], 'approve', '', 'tola@mreq.test', ['studio_role' => 'admin', 'self_id' => $tola])['ok']);
$ok = MemberRequests::decide((int) $a['id'], 'approve', '', 'boss@mreq.test', $adminCtx);
ck('requests: approved, the move is applied to their record', $ok['ok'] && $ok['status'] === 'approved' && MemberRoster::get($tola)['centre'] === 'Ayobo' && str_contains($ok['outcome'], 'centre'));
ck('requests: decided once', !MemberRequests::decide((int) $a['id'], 'approve', '', 'other@mreq.test', $adminCtx)['ok']);
ck('requests: approved, the phone is corrected in the desk’s format', MemberRequests::decide((int) $d['id'], 'approve', '', 'boss@mreq.test', $adminCtx)['ok']
    && MemberRoster::get($tola)['phone'] === '08031112222');
ck('requests: a reject needs a reason', !MemberRequests::decide((int) $p['id'], 'reject', '', 'boss@mreq.test', $adminCtx)['ok']);
ck('requests: rejected, the member sees why', MemberRequests::decide((int) $p['id'], 'reject', 'Talk to your coordinator first', 'boss@mreq.test', $adminCtx)['ok']
    && MemberRequests::mine($tola)[0]['reason'] === 'Talk to your coordinator first');

/* A request that cannot be applied any more goes back to waiting, untouched. */
$e = MemberRequests::submit($tola, ['kind' => 'centre', 'centre' => 'Egbeda']);
$eg = array_values(array_filter(Centres::all(), fn($c) => $c['name'] === 'Egbeda'))[0];
Centres::setStatus($eg['id'], 'retired', 'tester');
$bad = MemberRequests::decide((int) $e['id'], 'approve', '', 'boss@mreq.test', $adminCtx);
ck('requests: one that can no longer be applied (the centre retired since) says so, and waits — nothing changed',
    !$bad['ok'] && str_contains($bad['error'], 'retired') && MemberRoster::get($tola)['centre'] === 'Ayobo' && MemberRequests::queue('pending')['pending'] === 1);
Centres::setStatus($eg['id'], 'active', 'tester');
ck('requests: the member withdraws one still waiting', MemberRequests::withdraw($tola, (int) $e['id'])['ok'] && MemberRequests::queue('pending')['pending'] === 0);
ck('requests: and cannot withdraw one already decided', !MemberRequests::withdraw($tola, (int) $a['id'])['ok']);
for ($i = 0; $i < MemberRequests::OPEN_MAX; $i++) MemberRequests::submit($tola, ['kind' => 'other', 'message' => 'Question ' . $i]);
ck('requests: at most ' . MemberRequests::OPEN_MAX . ' open at once', !MemberRequests::submit($tola, ['kind' => 'other', 'message' => 'One more'])['ok']);

/* ── where people find it ─────────────────────────────────────────────── */
$root = dirname(__DIR__);
$view = (string) file_get_contents($root . '/portal/views/requests.php');
$idx = (string) file_get_contents($root . '/portal/index.php');
$memv = (string) file_get_contents($root . '/portal/views/membership.php');
ck('requests: a change to your record is asked for in Membership', str_contains($memv, 'id="memberRequests"') && str_contains($idx, '/portal/member-requests.js'));
ck('requests: the portal has a Finance requests page in the member’s own menu, for money only', str_contains($idx, "['requests', 'Finance requests'") && str_contains($idx, "views/requests.php")
    && str_contains($view, 'id="moneyRequests"') && !str_contains($view, 'id="memberRequests"'));
ck('requests: Membership shows how many of your requests are waiting', str_contains($idx, "['membership', (\$isOrg ? 'Membership' : 'Account'), \$mreqWaiting"));
ck('requests: money is no longer only on Today, which now points to Requests', !str_contains((string) file_get_contents($root . '/portal/views/today.php'), 'id="moneyRequests"'));
ck('requests: when finance is not set up, the member is told so — not "cannot be reached"', str_contains($view, 'data-mr-off') && str_contains((string) file_get_contents($root . '/portal/money-requests.js'), 'finance_configured === false'));
ck('requests: neither page carries an inline style', !str_contains($view, 'style=') && !str_contains($memv, 'style='));
$ep = (string) file_get_contents($root . '/portal/member-requests.php');
ck('requests: a member’s write needs their token and is rate-limited', str_contains($ep, "av_require_write(\$uid, 'member_request'"));
$api = (string) file_get_contents($root . '/admin/api.php');
ck('requests: deciding is management-only and a POST', str_contains($api, "'member_requests_list', 'member_request_decide'") && (bool) preg_match("~case 'member_request_decide':\\s*\\n\\s*if \\(\\\$method !== 'POST'\\)~", $api));
ck('requests: the Studio shows them under Approvals, "From members"', str_contains((string) file_get_contents($root . '/admin/index.php'), 'id="mreqList"'));

$qPdo->exec('DELETE FROM av_member_requests');
$qPdo->exec("DELETE FROM member_profiles WHERE user_id IN (SELECT id FROM lms_users WHERE email LIKE '%@mreq.test')");
$qPdo->exec("DELETE FROM lms_users WHERE email LIKE '%@mreq.test'");
$qPdo->exec("DELETE FROM av_centres WHERE name IN ('Egbeda','Ayobo')");
