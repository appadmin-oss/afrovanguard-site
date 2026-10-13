<?php
/**
 * tests/taskmatch.test.php — who a task should go to, from people's labels.
 *
 *   • Labels come from what the site knows: NGG role/course/faculty/centre,
 *     volunteer placements, member role.
 *   • A teaching task goes to the instructor of that course; an attendance
 *     register to an admin; a centre named in the task counts.
 *   • Open load counts against a person; spreading several tasks does not
 *     hand them all to one.
 *   • Assigning: the creator may; a pool task only by someone who allocates.
 *   • AI only ever picks from the shortlist and never sees an email or phone.
 *
 * Run via tests/run.php (provides ck()).
 */
declare(strict_types=1);

$tp = Database::pdo();
NggVolunteers::ensure(); VolunteerProjects::ensure();
Collab::addTask(1, 'warm-up', 1);   /* makes sure collab_tasks exists */
$tp->exec('DELETE FROM collab_tasks');
foreach (['ngg_volunteers', 'vol_assignments', 'vol_roster', 'vol_team_members'] as $t) $tp->exec('DELETE FROM ' . $t);
$tp->exec('DELETE FROM lms_users WHERE id BETWEEN 9800 AND 9819');
$mk = static function (int $id, string $name, string $email, string $role = 'learner') use ($tp): array {
    $tp->prepare('INSERT INTO lms_users (id, name, email, password_hash, role, status) VALUES (?,?,?,?,?,?)')->execute([$id, $name, $email, 'x', $role, 'active']);
    return ['id' => $id, 'name' => $name, 'email' => $email, 'role' => $role];
};
$seed = static function (string $vid, string $name, string $email, string $role, string $courses, string $faculty, string $centre, int $sg = 1) use ($tp): void {
    $tp->prepare("INSERT INTO ngg_volunteers (volunteer_id, name, email, role, role_label, centre, status, safeguarding_cleared, programme, faculty, courses, origin, seeded_at) VALUES (?,?,?,?,?,?,'active',?,'summer',?,?,'seed','x')")
        ->execute([$vid, $name, $email, $role, VolunteerSeeds::roleLabel(['role' => $role, 'courses' => $courses, 'faculty' => $faculty]), $centre, $sg, $faculty, $courses]);
};
$cook  = $mk(9801, 'Adeyemi Cook', 'cook@gmail.test');      $seed('V-1', 'Adeyemi Cook', 'cook@gmail.test', 'instructor', 'Catering', 'Creative Arts', 'Egbeda');
$piano = $mk(9802, 'Bimpe Keys', 'keys@gmail.test');        $seed('V-2', 'Bimpe Keys', 'keys@gmail.test', 'instructor', 'Piano', 'Music', 'Ayobo');
$clerk = $mk(9803, 'Chidi Clerk', 'clerk@gmail.test');      $seed('V-3', 'Chidi Clerk', 'clerk@gmail.test', 'admin', '', '', 'Egbeda');
$clerk2= $mk(9804, 'Dayo Desk', 'desk@gmail.test');         $seed('V-4', 'Dayo Desk', 'desk@gmail.test', 'admin', '', '', 'Ikotun');
$guide = $mk(9805, 'Efe Guide', 'guide@gmail.test');        $seed('V-5', 'Efe Guide', 'guide@gmail.test', 'mentor', '', 'Entrepreneurship', 'Ayobo', 0);
$media = $mk(9806, 'Funmi Lens', 'lens@gmail.test');
VolunteerProjects::assign([9806], (int) VolunteerProjects::bySlug('mediapro')['id'], 'media', 0);
$coord = $mk(9807, 'Gbenga Coord', 'gbenga@afrovanguard.org.ng', 'coordinator');
$learner = $mk(9808, 'Hauwa Learner', 'hauwa@gmail.test');
TaskMatch::forget();

$pr = TaskMatch::profiles();
ck('match: volunteers and org members are profiled, learners are not', isset($pr[9801], $pr[9806], $pr[9807]) && !isset($pr[9808]));
ck('match: NGG labels are read', in_array('Instructor — Catering', $pr[9801]['labels'], true) && $pr[9801]['centre'] === 'Egbeda' && in_array('NGG Summer School', $pr[9801]['labels'], true));
ck('match: a placement is a label', in_array('Media & communications · MediaPro', $pr[9806]['labels'], true));
ck('match: no email or phone in the summary', !str_contains(TaskMatch::summary($pr[9801]), '@'));

$first = static fn(string $t) => TaskMatch::shortlist($t, 3)[0]['id'] ?? 0;
ck('match: teaching catering → the catering instructor', $first('Teach the catering practical for the new class') === 9801);
ck('match: piano lesson → the piano instructor', $first('Prepare a piano lesson plan') === 9802);
ck('match: an attendance register → an admin', in_array($first('Collate the attendance register and file the report'), [9803, 9804], true));
ck('match: the centre named decides between two admins', $first('Record attendance at Ikotun') === 9804);
ck('match: social media → the media volunteer', $first('Design a flyer and post it on Instagram') === 9806);
ck('match: mentoring → the mentor', $first('Mentor the teens on career choices') === 9805);
$sh = TaskMatch::shortlist('Teach the catering practical', 3);
ck('match: the reason is given in words', str_contains(implode(' ', $sh[0]['why']), 'Instructor — Catering'));

/* Load */
for ($i = 0; $i < 6; $i++) Collab::addTask(9807, 'busy ' . $i, 9803);
TaskMatch::forget();
ck('match: the busy admin loses to the free one when the fit is the same', $first('Collate the attendance register') === 9804);
[$bs, $bwhy] = TaskMatch::score(TaskMatch::profiles()[9803], 'Collate the attendance register');
ck('match: and the reason says so', str_contains(implode(' ', $bwhy), '6 open tasks') && $bs < 3);
$tp->exec('DELETE FROM collab_tasks');
TaskMatch::forget();

$sp = TaskMatch::spread([['title' => 'File the register at Egbeda'], ['title' => 'File the register'], ['title' => 'File the register again']]);
$ids = array_map(fn($x) => $x['id'] ?? 0, $sp);
ck('match: spreading three admin tasks uses both admins', count(array_unique($ids)) === 2 && $ids[0] === 9803);
$sp2 = TaskMatch::spread([['title' => 'Do the thing', 'kind' => 'teaching']]);
ck('match: a kind from the planner is used when the title says little', in_array($sp2[0]['id'] ?? 0, [9801, 9802], true));
ck('match: nothing relevant → no suggestion rather than a random one', TaskMatch::spread([['title' => 'Zzzz qqqq']])[0] === null);

$s = TaskMatch::suggest('Teach the catering practical', 3);
ck('match: suggestions work with no AI, by labels', $s['ok'] && ($s['suggestions'][0]['id'] ?? 0) === 9801 && in_array($s['via'], ['labels', 'ai'], true));
ck('match: an empty title is refused', !TaskMatch::suggest('  ')['ok']);
$src = (string) file_get_contents(AV_ROOT . '/lib/TaskMatch.php');
ck('match: AI is only given the shortlist, and only shortlist ids are accepted', str_contains($src, "if (!isset(\$by[\$id]) || isset(\$out[\$id])) continue;"));
ck('match: the AI prompt carries names and labels, not emails', !preg_match('/\$lines\[\][^;]*email/', $src));

/* Assigning */
$own = Collab::addTask(9807, 'Book the hall', 9807);
ck('assign: the creator may give their task to someone', (Collab::assignTask(9807, $own, 9801, false)['assignee_id'] ?? 0) === 9801);
$pool = Collab::addPoolTask(9801, 'Sort the chairs');
ck('assign: a pool task cannot be handed out by someone who does not allocate', Collab::assignTask(9802, $pool, 9803, false) === null);
ck('assign: but can by someone who does', (Collab::assignTask(9807, $pool, 9803, true)['assignee_id'] ?? 0) === 9803);
ck('assign: not to an account that does not exist', Collab::assignTask(9807, $own, 999999, true) === null);
$r = Collab::roster(200, true);
$c = array_values(array_filter($r, fn($x) => $x['id'] === 9801))[0] ?? [];
ck('assign: the allocator’s roster includes volunteers, with their labels', str_contains((string) ($c['labels'] ?? ''), 'Catering'));
ck('assign: the plain roster stays organisation-only', !in_array(9801, array_column(Collab::roster(200, false), 'id'), true));

$tp->exec('DELETE FROM collab_tasks');
foreach (['ngg_volunteers', 'vol_assignments', 'vol_roster'] as $t) $tp->exec('DELETE FROM ' . $t);
$tp->exec('DELETE FROM lms_users WHERE id BETWEEN 9800 AND 9819');
TaskMatch::forget();
