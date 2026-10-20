<?php
/**
 * tests/todo.test.php — the to-do pop-up's list, "suggested for you", and the
 * sweep that gives an unclaimed pool task to whoever fits it.
 *
 * Run via tests/run.php (provides ck()).
 */
declare(strict_types=1);

$tdp = Database::pdo();
NggVolunteers::ensure(); TaskMatch::ensure();
$tdp->exec('DELETE FROM collab_tasks');
$tdp->exec('DELETE FROM ngg_volunteers');
$tdp->exec('DELETE FROM lms_users WHERE id BETWEEN 9950 AND 9969');
$mk = static function (int $id, string $n, string $e, string $role = 'learner') use ($tdp): array {
    $tdp->prepare('INSERT INTO lms_users (id, name, email, password_hash, role, status) VALUES (?,?,?,?,?,?)')->execute([$id, $n, $e, 'x', $role, 'active']);
    return ['id' => $id, 'name' => $n, 'email' => $e, 'role' => $role];
};
$seedV = static function (string $vid, string $n, string $e, string $role, string $courses, string $centre) use ($tdp): void {
    $tdp->prepare("INSERT INTO ngg_volunteers (volunteer_id, name, email, role, role_label, centre, status, safeguarding_cleared, programme, faculty, courses, origin, seeded_at) VALUES (?,?,?,?,?,?,'active',1,'summer','',?,'seed','x')")
        ->execute([$vid, $n, $e, $role, VolunteerSeeds::roleLabel(['role' => $role, 'courses' => $courses]), $centre, $courses]);
};
$boss  = $mk(9951, 'Kemi Coord', 'kemi.td@afrovanguard.org.ng', 'coordinator');
$cook  = $mk(9952, 'Ade Cook', 'ade.td@gmail.test');   $seedV('TD-1', 'Ade Cook', 'ade.td@gmail.test', 'instructor', 'Catering', 'Egbeda');
$clerk = $mk(9953, 'Chi Clerk', 'chi.td@gmail.test');  $seedV('TD-2', 'Chi Clerk', 'chi.td@gmail.test', 'admin', '', 'Ikotun');
$learner = $mk(9954, 'Lola Learner', 'lola.td@gmail.test');
TaskMatch::forget();

$today = gmdate('Y-m-d'); $yday = gmdate('Y-m-d', time() - 86400); $next = gmdate('Y-m-d', time() + 5 * 86400);
$given = Collab::addTask(9951, 'Prepare the catering practical', 9952, $next);
$own   = Collab::addTask(9952, 'Buy flour', 9952, $yday);
$later = Collab::addTask(9952, 'Plan next month', 9952, $next);
$pool1 = Collab::addPoolTask(9951, 'Teach the catering class at Egbeda');
$pool2 = Collab::addPoolTask(9951, 'Collate the attendance register at Ikotun');

$d = Todo::for($cook);
ck('todo: a task somebody gave them is "just assigned", with who from', ($d['assigned'][0]['id'] ?? 0) === $given && $d['assigned'][0]['from'] === 'Kemi Coord');
ck('todo: their own overdue task is due', array_column($d['due'], 'id') === [$own]);
ck('todo: a task due later is not nagged about', !in_array($later, array_column($d['due'], 'id'), true));
ck('todo: the pool task that fits them is suggested, with why', ($d['suggested'][0]['id'] ?? 0) === $pool1 && str_contains($d['suggested'][0]['why'], 'Catering'));
ck('todo: the register is not suggested to the cook', !in_array($pool2, array_column($d['suggested'], 'id'), true));
$sec = Todo::sections($d);
$keys = array_column($sec, 'key');
ck('todo: sections in the order a day reads them', $keys === ['assigned', 'due', 'suggested']);
$it = $sec[2]['items'][0];
ck('todo: a suggestion carries its own action, so the pop-up knows nothing about the site', $it['take']['action'] === 'todo_take' && $it['take']['body'] === ['id' => $pool1]);
ck('todo: an overdue task is marked urgent, with an Overdue badge and when it was due', $sec[1]['items'][0]['urgent'] === true && $sec[1]['items'][0]['badge']['text'] === 'Overdue' && str_starts_with($sec[1]['items'][0]['meta'], 'Was due '));
ck('todo: every item says what kind it is, for its icon', !array_filter(array_merge(...array_column($sec, 'items')), fn($i) => empty($i['kind'])));
ck('todo: ticking their own task can be undone', !empty($sec[1]['items'][0]['tick']['undo']));
ck('todo: a learner who is neither member nor volunteer is offered nothing to take', !Todo::mayTake($learner) && Todo::for($learner)['suggested'] === []);
ck('todo: a volunteer may take', Todo::mayTake($cook));
Collab::claimTask(9952, $pool1);
ck('todo: a pool task they took themselves is not "assigned to you"', !in_array($pool1, array_column(Todo::for($cook)['assigned'], 'id'), true));
Collab::releaseTask(9952, $pool1);
AvRules::save(['tasks.todo_popup' => '0'], 'test');
ck('todo: switched off in the Studio, the list is empty and says so', Todo::for($cook)['enabled'] === false && Todo::for($cook)['assigned'] === []);
AvRules::save(['tasks.todo_popup' => '1'], 'test');

/* The sweep */
AvRules::save(['tasks.auto_assign_hours' => '0'], 'test');
ck('sweep: 0 hours is off', TaskMatch::autoAssign()['off'] === true);
AvRules::save(['tasks.auto_assign_hours' => '24'], 'test');
ck('sweep: a task posted just now is left for people to take', TaskMatch::autoAssign()['assigned'] === 0);
$stale = gmdate('Y-m-d H:i:s', time() - 30 * 3600);
$tdp->prepare('UPDATE collab_tasks SET created_at = ? WHERE id IN (?, ?)')->execute([$stale, $pool1, $pool2]);
$pool3 = Collab::addPoolTask(9951, 'Zzzz qqqq'); $tdp->prepare('UPDATE collab_tasks SET created_at = ? WHERE id = ?')->execute([$stale, $pool3]);
/* The sweep gives without asking, so only to people here lately (TaskMatch::present). */
$tdp->prepare('UPDATE lms_users SET last_login = ? WHERE id IN (9952, 9953)')->execute([gmdate('Y-m-d H:i:s', time() - 2 * 86400)]);
TaskMatch::forget();
$r = TaskMatch::autoAssign();
$who = static fn(int $id) => (int) $tdp->query('SELECT assignee_id FROM collab_tasks WHERE id = ' . $id)->fetchColumn();
ck('sweep: a day unclaimed, the catering class goes to the catering instructor', $who($pool1) === 9952);
ck('sweep: the register goes to the admin volunteer', $who($pool2) === 9953);
ck('sweep: a task nobody fits stays in the pool', $who($pool3) === 0 && $r['assigned'] === 2 && $r['left'] === 1);
$n = $tdp->query("SELECT body FROM user_notifications WHERE user_id = 9953 ORDER BY id DESC LIMIT 1")->fetchColumn();
ck('sweep: the person is told why it came to them, and that they can hand it back', is_string($n) && str_contains($n, 'fits you') && str_contains($n, 'Hand it back'));
Collab::releaseTask(9953, $pool2);
TaskMatch::forget();
TaskMatch::autoAssign();
ck('sweep: handed back, it is not pushed on anybody again', $who($pool2) === 0);
ck('sweep: and the one it was given to sees it as just assigned', in_array($pool1, array_column(Todo::for($cook)['assigned'], 'id'), true));
$tdp->prepare('UPDATE lms_users SET last_login = ? WHERE id = 9952')->execute([gmdate('Y-m-d H:i:s', time() - 60 * 86400)]);
$pool4 = Collab::addPoolTask(9951, 'Teach the catering class at Egbeda again'); $tdp->prepare('UPDATE collab_tasks SET created_at = ? WHERE id = ?')->execute([$stale, $pool4]);
TaskMatch::forget();
TaskMatch::autoAssign();
ck('sweep: somebody not seen for two months is not handed work, however well they fit', $who($pool4) === 0);
$tdp->prepare('UPDATE lms_users SET last_login = ? WHERE id = 9952')->execute([gmdate('Y-m-d H:i:s')]);

/* ── Tailored: a member, a volunteer, a learner ── */
$mem = $mk(9960, 'Mo Member', 'mo.td@afrovanguard.org.ng', 'member');
$md = Todo::for($mem);
ck('member: the role reads as a member', $md['role']['member'] && !$md['role']['volunteer'] && $md['role']['label'] === 'Member');
ck('member: dues never paid are on the list, under their membership', ($md['member'][0]['kind'] ?? '') === 'dues' && ($md['member'][0]['href'] ?? '') === '#membership');
ck('member: …but dues alone do not pop the list open every day', empty($md['member'][0]['nudge']));
ck('member: no volunteering section for somebody who does not volunteer', $md['volunteer'] === []);
Commitments::ensure();
$tdp->prepare("INSERT INTO commitments (source_kind, source_id, title, member_id, due, status, created_at) VALUES ('meeting', 0, 'Send the venue list', ?, ?, 'open', ?)")->execute([9960, $yday, gmdate('Y-m-d H:i:s')]);
$cm = (int) $tdp->lastInsertId();
$md = Todo::for($mem);
$act = array_values(array_filter($md['member'], fn($x) => ($x['kind'] ?? '') === 'action'))[0] ?? null;
ck('member: an overdue action they agreed to, with Done that posts to the commitments door', $act && $act['urgent'] && $act['acts'][0]['url'] === '/portal/commitments.php' && $act['acts'][0]['body'] === ['id' => $cm]);
$ms = Todo::sections($md);
ck('member: the section is called Your membership and says when to open', ($ms[0]['key'] ?? '') === 'member' && $ms[0]['title'] === 'Your membership' && $ms[0]['nudge'] === true && $ms[0]['ctx'] === 'member');

/* a volunteer with a meeting to answer and a shift tomorrow */
$team = Volunteers::createTeam($boss, 'TD Outreach');
Volunteers::setMember($boss, $team['id'], $clerk['email'], 'member');
$when = (new DateTime('+2 days', new DateTimeZone(AV_TZ)))->format('Y-m-d\TH:i');
MeetingMinutes::scheduleForTeam($boss, (int) $team['id'], ['title' => 'TD planning call', 'when' => $when, 'duration' => 45, 'frequency' => 'once']);
$tom = (new DateTime('+1 day', new DateTimeZone(AV_TZ)))->format('Y-m-d\TH:i');
$op = VolunteerWork::post($boss, ['title' => 'TD set-up day', 'when' => $tom, 'duration' => 120, 'slots' => 3, 'location' => 'Ikotun', 'team_id' => $team['id']]);
VolunteerWork::signup($clerk, (int) $op['id']);
$vd = Todo::for($clerk);
ck('volunteer: the role names what they volunteer as', $vd['role']['volunteer'] && str_contains($vd['role']['label'], 'Summer School'));
$kinds = array_column($vd['volunteer'], 'kind');
ck('volunteer: a meeting not yet answered is asked, with Coming / Can\'t come', in_array('rsvp', $kinds, true));
$rs = array_values(array_filter($vd['volunteer'], fn($x) => $x['kind'] === 'rsvp'))[0] ?? [];
ck('volunteer: …which post the RSVP to the volunteer door', ($rs['acts'][0]['url'] ?? '') === '/portal/volunteer.php' && ($rs['acts'][0]['body']['answer'] ?? '') === 'yes' && ($rs['acts'][1]['body']['answer'] ?? '') === 'no');
ck('volunteer: tomorrow\'s shift is on the list, with where', in_array('shift', $kinds, true) && str_contains(implode(' ', array_column($vd['volunteer'], 'meta')), 'Ikotun'));
$vs = Todo::sections($vd);
ck('volunteer: Volunteering is its own section, for the Volunteer dashboard to lead with', in_array('volunteer', array_column($vs, 'key'), true) && array_values(array_filter($vs, fn($x) => $x['key'] === 'volunteer'))[0]['ctx'] === 'volunteer');
MeetingMinutes::rsvp($clerk, (int) $rs['acts'][0]['body']['meeting_id'], (string) $rs['acts'][0]['body']['occurrence'], 'yes', '');
ck('volunteer: answered, it is not asked again', !in_array('rsvp', array_column(Todo::for($clerk)['volunteer'], 'kind'), true));

$ld = Todo::for($learner);
ck('learner: a learner is a learner, with nothing invented for them', $ld['role']['label'] === 'Learner' && $ld['member'] === [] && $ld['volunteer'] === []);

$js = (string) file_get_contents(AV_ROOT . '/portal/todo-pop.js');
ck('pop-up: on the Volunteer dashboard the volunteering section goes first', str_contains($js, "if (h === 'volunteer') return 'volunteer';") && str_contains($js, 'function ordered()'));
ck('pop-up: a button may post to another door of the same site', str_contains($js, '(spec.url || src)'));
ck('pop-up: it opens for what is worth opening for, as the server says', str_contains($js, '!!s.nudge'));

$tdp->exec('DELETE FROM commitments WHERE member_id = 9960');
AvRules::save(['tasks.auto_assign_hours' => '24'], 'test');
$tdp->exec('DELETE FROM collab_tasks');
$tdp->exec('DELETE FROM ngg_volunteers');
$tdp->exec('DELETE FROM lms_users WHERE id BETWEEN 9950 AND 9969');
TaskMatch::forget();
