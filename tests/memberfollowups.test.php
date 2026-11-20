<?php
/**
 * tests/memberfollowups.test.php — acting on an evaluation, and seeing it early.
 *
 *   • A member who needs attention is followed up by a named admin by a day;
 *     one open at a time; closed with what happened, which goes into the
 *     member's notes; every open and close is audited.
 *   • Follow-ups due (or late) are a roster list, a dashboard tile and an item
 *     on the Overview's Today queue; a late one is a concern on the record.
 *   • The trend: the last 30 days against the 60 before — slipping is a
 *     concern before the 90-day rate falls; too few days says nothing.
 *   • A first-year member with a concern is flagged to follow up early.
 *
 * Run via tests/run.php (provides ck()).
 */
declare(strict_types=1);

$fuPdo = Database::pdo();
MemberFollowUps::ensure(); MemberDesk::ensure(); GateAttendance::ensure(); AdminRoles::ensure();
$fuPdo->exec("DELETE FROM admin_users WHERE email LIKE 'fu.%'");
AdminRoles::add('fu.admin@afrovanguard.org.ng', 'admin', 'test');
AdminRoles::add('fu.editor@afrovanguard.org.ng', 'editor', 'test');
$fuPdo->prepare("INSERT INTO lms_users (name, email, password_hash, role, status) VALUES ('Femi Follow', ?, 'x', 'member', 'active')")->execute(['femi.' . bin2hex(random_bytes(3)) . '@example.test']);
$fuId = (int) $fuPdo->lastInsertId();
$today = MemberFollowUps::today(); $in7 = gmdate('Y-m-d', strtotime($today . ' +7 days'));

ck('follow-up: only an admin may own one (an editor cannot open the member desk)',
    in_array('fu.admin@afrovanguard.org.ng', MemberFollowUps::team(), true) && !in_array('fu.editor@afrovanguard.org.ng', MemberFollowUps::team(), true));
$bad = MemberFollowUps::open($fuId, 'Call', 'fu.editor@afrovanguard.org.ng', gmdate('Y-m-d', strtotime($today . ' -1 day')), 'fu.admin@afrovanguard.org.ng');
ck('follow-up: the whole request is checked first — owner and a day in the past both refused, nothing written',
    empty($bad['ok']) && isset($bad['errors']['owner'], $bad['errors']['due_on']) && MemberFollowUps::forMember($fuId) === []);
ck('follow-up: due within 60 days', isset(MemberFollowUps::open($fuId, 'Call home', 'fu.admin@afrovanguard.org.ng', gmdate('Y-m-d', strtotime($today . ' +90 days')), 'x')['errors']['due_on']));
$ok = MemberFollowUps::open($fuId, 'Came on 40% of days', 'fu.admin@afrovanguard.org.ng', $in7, 'fu.admin@afrovanguard.org.ng');
ck('follow-up: opened with a reason, an owner and a day', !empty($ok['ok']) && ($ok['followups'][0]['status'] ?? '') === 'open' && $ok['followups'][0]['due_on'] === $in7);
ck('follow-up: one open at a time, so two admins do not call the same family', (MemberFollowUps::open($fuId, 'Again', 'fu.admin@afrovanguard.org.ng', $in7, 'x')['code'] ?? '') === 'already_open');
ck('follow-up: not due yet is not on the due list', !in_array($fuId, array_column(MemberFollowUps::due(), 'user_id'), true));

$fuPdo->prepare('UPDATE member_followups SET due_on = ? WHERE user_id = ?')->execute([gmdate('Y-m-d', strtotime($today . ' -2 days')), $fuId]);
ck('follow-up: late, it is due — on the list and in the count', in_array($fuId, array_column(MemberFollowUps::due(), 'user_id'), true) && MemberFollowUps::dueCount() >= 1);
[$w, $p] = MemberRoster::segmentSql('followup_due');
$st = $fuPdo->prepare("SELECT 1 FROM lms_users u WHERE u.id = ? AND $w"); $st->execute(array_merge([$fuId], $p));
ck('follow-up: …and a roster list (a tile on the Members dashboard)', (bool) $st->fetchColumn() && isset(MemberRoster::SEGMENTS['followup_due'], MemberRoster::SEGMENTS['followup_open']));
$ev = MemberEvaluation::evaluate($fuId);
ck('follow-up: a late follow-up is a concern on the member\'s record', $ev['standing'] === 'attention' && (bool) array_filter($ev['concerns'], static fn($t) => str_starts_with($t, 'Follow-up by fu.admin')));
$td = StudioToday::for('admin');
ck('follow-up: the Overview\'s Today queue says how many are due', (bool) array_filter($td['items'], static fn($i) => $i['key'] === 'followups' && $i['n'] >= 1 && $i['go'] === 'members'));

$fid = (int) $ok['followups'][0]['id'];
ck('follow-up: closing needs what happened', empty(MemberFollowUps::close($fid, '', 'done', 'x')['ok']));
$cl = MemberFollowUps::close($fid, 'Transport problem until January; back on Saturday.', 'done', 'fu.admin@afrovanguard.org.ng');
ck('follow-up: closed as done', !empty($cl['ok']) && $cl['followups'][0]['status'] === 'done' && $cl['followups'][0]['closed_by'] === 'fu.admin@afrovanguard.org.ng');
ck('follow-up: …and the outcome is in the member\'s notes, dated and signed', (bool) array_filter(MemberDesk::notes($fuId), static fn($n) => str_contains($n['body'], 'Transport problem until January') && $n['author'] === 'fu.admin@afrovanguard.org.ng'));
ck('follow-up: a closed one cannot be closed again', (MemberFollowUps::close($fid, 'Again', 'done', 'x')['code'] ?? '') === 'closed');
ck('follow-up: a new one can start once the last is closed', !empty(MemberFollowUps::open($fuId, 'Check in after January', 'fu.admin@afrovanguard.org.ng', $in7, 'x')['ok']));

/* The trend, from the gate's rows. */
$row = static fn(int $ago, string $s) => ['day' => gmdate('Y-m-d', strtotime('-' . $ago . ' days')), 'status' => $s];
$slip = array_merge(array_map(static fn($i) => $row(40 + $i, 'present'), range(0, 5)), [$row(3, 'absent'), $row(5, 'absent'), $row(8, 'present'), $row(10, 'absent')]);
ck('trend: 100% before, 25% in the last 30 days is slipping', MemberEvaluation::trend($slip) === ['recent' => 25, 'before' => 100, 'direction' => 'slipping']);
$up = array_merge(array_map(static fn($i) => $row(40 + $i, 'absent'), range(0, 3)), array_map(static fn($i) => $row(2 + $i, 'late'), range(0, 3)));
ck('trend: …the other way is improving, and lateness counts as came', (MemberEvaluation::trend($up)['direction'] ?? '') === 'improving');
ck('trend: too few days on either side says nothing', MemberEvaluation::trend([$row(3, 'absent'), $row(40, 'present'), $row(41, 'present'), $row(42, 'present')]) === null);
ck('trend: slipping is a concern even while the 90-day rate is not low',
    (bool) array_filter(MemberEvaluation::standing(['attendance' => ['enough' => true, 'rate' => 80, 'punctuality' => 70, 'counted' => 10, 'late_30' => 0, 'trend' => ['recent' => 50, 'before' => 95, 'direction' => 'slipping']]])['concerns'],
        static fn($t) => str_starts_with($t, 'Attendance slipping')));
ck('first year: a new member with a concern is flagged to follow up early',
    (bool) array_filter(MemberEvaluation::standing(['tenure' => ['first_year' => true], 'money' => ['fines_owing_ngn' => 500, 'fines_open' => 1, 'dues_owes' => false, 'dues_months' => 0]])['notes'], static fn($t) => str_contains($t, 'first year'))
    && ($ev['tenure']['first_year'] ?? null) === true);

$api = (string) file_get_contents(AV_ROOT . '/admin/api.php');
ck('wired: the follow-up actions are management-only (not for editors)', (bool) preg_match("/\\\$managementOnly = \\[.*'member_followup_open', 'member_followup_close'/s", $api));

$fuPdo->exec('DELETE FROM member_followups WHERE user_id = ' . $fuId);
$fuPdo->exec('DELETE FROM member_notes WHERE user_id = ' . $fuId);
$fuPdo->exec("DELETE FROM admin_users WHERE email LIKE 'fu.%'");
