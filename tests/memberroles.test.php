<?php
/**
 * tests/memberroles.test.php — work roles as one list the office keeps
 * (lib/MemberRoles.php), and a task taken from the pool staying in sight.
 *
 *   • A role typed on a member is written in the list's spelling; a new one
 *     joins the list. Roles already on members are taken into it.
 *   • Renaming renames it on everybody who holds it; removing takes it off.
 *   • The roster filters by role; roles are given or taken in bulk.
 *   • Task matching reads what a role covers, not only its name.
 *   • A task taken from the pool is on the person's list in the pop-up.
 *
 * Run via tests/run.php (provides ck()).
 */
declare(strict_types=1);

$rp = Database::pdo();
MemberRoster::ensure();
MemberRoles::ensure();
Collab::myTasks(0, 1);   /* makes sure collab_tasks exists */
$rp->exec('DELETE FROM member_roles');
$rp->exec('DELETE FROM member_profiles WHERE user_id BETWEEN 9920 AND 9929');
$rp->exec('DELETE FROM collab_tasks WHERE creator_id BETWEEN 9920 AND 9929 OR assignee_id BETWEEN 9920 AND 9929');
$rp->exec('DELETE FROM lms_users WHERE id BETWEEN 9920 AND 9929');
foreach ([9921 => 'Ada Media', 9922 => 'Bayo Events', 9923 => 'Chi Both'] as $id => $n) {
    $rp->prepare('INSERT INTO lms_users (id, name, email, password_hash, role, status, last_login) VALUES (?,?,?,?,?,?,?)')
       ->execute([$id, $n, 'mr' . $id . '@afrovanguard.org.ng', 'x', 'member', 'active', gmdate('Y-m-d H:i:s')]);
}
/* A role already on a member before the list existed. */
$rp->prepare("INSERT INTO member_profiles (user_id, work_roles, about, source, updated_at) VALUES (9922, 'Events', '', 'test', ?)")->execute([gmdate('Y-m-d H:i:s')]);
ck('list: roles already held are taken into it', in_array('Events', array_column(MemberRoles::all(), 'name'), true));

/* ── Adding and spelling ─────────────────────────────────────────────── */
ck('list: a new role, with what it covers', MemberRoles::save(0, 'Media', 'Photos, video, posters, flyers and the socials')['ok']);
ck('list: a second role of the same name is refused', !MemberRoles::save(0, 'media', '')['ok']);
ck('list: a name with a comma is refused — commas separate roles', !MemberRoles::save(0, 'Media, Design', '')['ok']);
$r = MemberRoster::update(9921, ['work_roles' => 'media, Fundraising'], 'test');
ck('member: a role typed in any case is written as the list spells it', $r['ok'] && (MemberRoster::get(9921)['work_roles'] ?? '') === 'Media, Fundraising');
ck('member: a role the list did not have joins it', in_array('Fundraising', array_column(MemberRoles::all(), 'name'), true));
$counts = array_column(MemberRoles::all(), 'members', 'name');
ck('list: says how many hold each role', ($counts['Media'] ?? 0) === 1 && ($counts['Events'] ?? 0) === 1);

/* ── Roster and bulk ─────────────────────────────────────────────────── */
$ids = static fn(string $role) => array_map('intval', array_column(MemberRoster::roster(['work_role' => $role, 'kind' => 'all', 'page_size' => 200])['members'], 'id'));
ck('roster: filtered by a role, only its holders', $ids('Media') === [9921]);
ck('roster: rows carry their roles and description', (MemberRoster::roster(['work_role' => 'Media', 'kind' => 'all'])['members'][0]['work_roles'] ?? '') === 'Media, Fundraising');
$b = MemberRoster::bulk([9922, 9923], 'work_role_add', ['role' => 'Media'], 'test');
ck('bulk: a role given to several', $b['ok'] && $b['applied'] === 2 && in_array(9923, $ids('Media'), true));
ck('bulk: what they held stays', str_contains((string) (MemberRoster::get(9922)['work_roles'] ?? ''), 'Events'));
$b = MemberRoster::bulk([9921, 9922], 'work_role_add', ['role' => 'Media'], 'test');
ck('bulk: somebody who already holds it is unchanged', $b['unchanged'] === 2 && $b['applied'] === 0);
$b = MemberRoster::bulk([9923], 'work_role_remove', ['role' => 'media'], 'test');
ck('bulk: a role taken away, whatever its case', $b['applied'] === 1 && !in_array(9923, $ids('Media'), true));
ck('bulk: no role chosen is refused before anybody is touched', !MemberRoster::bulk([9921], 'work_role_add', ['role' => ''], 'test')['ok']);

/* ── Rename and remove ───────────────────────────────────────────────── */
$media = array_values(array_filter(MemberRoles::all(), fn($x) => $x['name'] === 'Media'))[0];
$r = MemberRoles::save($media['id'], 'Media & Design', 'Photos, video, posters, flyers and the socials');
ck('rename: renamed on everybody who holds it', $r['ok'] && $r['renamed'] === 2 && str_contains((string) MemberRoster::get(9921)['work_roles'], 'Media & Design') && $ids('Media') === []);
$fund = array_values(array_filter(MemberRoles::all(), fn($x) => $x['name'] === 'Fundraising'))[0];
$r = MemberRoles::delete($fund['id']);
ck('remove: taken off the members who held it', $r['ok'] && $r['removed_from'] === 1 && !str_contains((string) MemberRoster::get(9921)['work_roles'], 'Fundraising'));

/* ── Task matching reads what a role covers ──────────────────────────── */
TaskMatch::forget();
[, $why] = TaskMatch::score(TaskMatch::profiles()[9921], 'Make a flyer for the open day');
ck('matching: a task word in the role’s description counts, and says so', (bool) array_filter($why, fn($w) => str_contains($w, 'part of their Media & Design role')));

/* ── Taken from the pool: on their list ──────────────────────────────── */
$pool = Collab::addPoolTask(9922, 'Count the chairs in Hall B');
ck('take: a pool task can be taken', Collab::claimTask(9921, $pool) !== null);
$open = Todo::for(['id' => 9921, 'name' => 'Ada Media', 'email' => 'mr9921@afrovanguard.org.ng', 'role' => 'member'])['open'];
ck('take: it is on their list in the pop-up, though not due and given by nobody', in_array($pool, array_column($open, 'id'), true));
$secs = Todo::sections(Todo::for(['id' => 9921, 'name' => 'Ada Media', 'email' => 'mr9921@afrovanguard.org.ng', 'role' => 'member']));
ck('take: under "On your list", with a Done button', (bool) array_filter($secs, fn($s) => $s['key'] === 'open' && in_array('av' . $pool, array_column($s['items'], 'id'), true)));
$js = (string) file_get_contents(AV_ROOT . '/portal/todo-pop.js');
ck('take: the pop-up moves a taken task to the list instead of dropping it', str_contains($js, "key === 'open'") && str_contains($js, "kind === 'take' && r.task"));
ck('take: the Tasks page shows it at once', str_contains((string) file_get_contents(AV_ROOT . '/portal/tasks.js'), "addEventListener('av:tasks-changed'"));

/* ── Wiring ──────────────────────────────────────────────────────────── */
$api = (string) file_get_contents(AV_ROOT . '/admin/api.php');
ck('admin: roles are listed, saved and removed, management only', str_contains($api, "case 'roles_list':") && str_contains($api, "case 'role_save':") && str_contains($api, "case 'role_delete':")
   && str_contains($api, "'roles_list', 'role_save', 'role_delete',"));
$html = (string) file_get_contents(AV_ROOT . '/admin/index.php');
ck('admin: a Roles tab, a role filter, bulk give/take, and chips in the drawer', str_contains($html, 'data-mv="roles"') && str_contains($html, 'id="memWorkRole"')
   && str_contains($html, 'value="work_role_add"') && str_contains($html, 'id="md_role_chips"'));

$rp->exec('DELETE FROM collab_tasks WHERE creator_id BETWEEN 9920 AND 9929 OR assignee_id BETWEEN 9920 AND 9929');
$rp->exec('DELETE FROM member_profiles WHERE user_id BETWEEN 9920 AND 9929');
$rp->exec('DELETE FROM lms_users WHERE id BETWEEN 9920 AND 9929');
$rp->exec('DELETE FROM member_roles');
TaskMatch::forget();
