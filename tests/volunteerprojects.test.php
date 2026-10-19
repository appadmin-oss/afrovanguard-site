<?php
/**
 * tests/volunteerprojects.test.php — volunteers serve projects, as a type.
 *
 *   • The site's projects are there from the start; the office adds more.
 *   • Placing a member on a project makes them a volunteer, with their type;
 *     placing them again changes the type, never a second row.
 *   • NGG's project is NGG's to fill; it refuses a placement here.
 *   • Hours carry the project: logged ones only to a project you serve,
 *     meetings to the team's project; they read per project.
 *   • The Studio's bulk "Make volunteers" places on a project; the roster
 *     filters volunteers; taking somebody off ends every placement.
 *
 * Run via tests/run.php (provides ck()).
 */
declare(strict_types=1);

$pp = Database::pdo();
VolunteerProjects::ensure(); Notifications::ensure();
foreach (['vol_assignments', 'vol_roster', 'vol_team_members'] as $t) { try { $pp->exec('DELETE FROM ' . $t . ' WHERE user_id BETWEEN 9700 AND 9799'); } catch (Throwable $e) {} }
$pp->exec('DELETE FROM vol_hours WHERE user_id BETWEEN 9700 AND 9799');
$pp->exec("DELETE FROM vol_projects WHERE slug LIKE 'vp-test%'");
$pp->exec('DELETE FROM lms_users WHERE id BETWEEN 9700 AND 9799');
$mk = static function (int $id, string $name) use ($pp): array {
    $email = strtolower(str_replace(' ', '.', $name)) . '@afrovanguard.org.ng';
    $pp->prepare('INSERT INTO lms_users (id, name, email, password_hash, role, status) VALUES (?,?,?,?,?,?)')->execute([$id, $name, $email, 'x', 'member', 'active']);
    return ['id' => $id, 'name' => $name, 'email' => $email, 'role' => 'member'];
};
$tola = $mk(9701, 'Tola Media');
$uche = $mk(9702, 'Uche Mentor');

$slugs = array_column(VolunteerProjects::projects(false), 'slug');
ck('Projects: the site\'s projects are there from the start', !array_diff(['afrovanguard', 'ngg', 'ngv', 'sts', 'techhome', 'mediapro', 'bec', 'career-hub', 'kap', 'africa-gates'], $slugs));
$np = VolunteerProjects::saveProject(['name' => 'VP Test Health Drive', 'blurb' => 'Clinics'], 'tester');
ck('Projects: the office adds one', $np['ok'] && ($p = VolunteerProjects::byId($np['id'])) && $p['slug'] === 'vp-test-health-drive');
ck('Projects: a name is needed', !VolunteerProjects::saveProject(['name' => ' '])['ok']);

$sts = (int) VolunteerProjects::bySlug('sts')['id'];
$media = (int) VolunteerProjects::bySlug('mediapro')['id'];
ck('Placing: a member is not a volunteer before', !Volunteers::isVolunteer($tola));
$a = VolunteerProjects::assign([9701], $media, 'media', 1, 'Shoots the events');
ck('Placing: on a project as a type makes them a volunteer', $a['ok'] && $a['placed'] === 1 && $a['new_volunteers'] === 1 && Volunteers::isVolunteer($tola));
$f = VolunteerProjects::forUser($tola);
ck('Placing: …their project and type, in words', count($f) === 1 && $f[0]['name'] === 'MediaPro' && $f[0]['type_label'] === 'Media & communications');
VolunteerProjects::assign([9701], $media, 'events', 1);
ck('Placing: again on the same project changes the type — one row', count(VolunteerProjects::forUser($tola)) === 1 && VolunteerProjects::forUser($tola)[0]['type'] === 'events'
    && (int) $pp->query('SELECT COUNT(*) FROM vol_assignments WHERE user_id = 9701')->fetchColumn() === 1);
VolunteerProjects::assign([9701], $sts, 'mentor', 1);
ck('Placing: a second project', count(VolunteerProjects::forUser($tola)) === 2);
ck('Placing: an unknown type, or NGG\'s project, is refused', !VolunteerProjects::assign([9701], $sts, 'wizard', 1)['ok'] && !VolunteerProjects::assign([9701], VolunteerProjects::nggId(), 'mentor', 1)['ok']);

/* Hours carry the project */
ck('Hours: logged to a project they serve', Volunteers::logHours(9701, gmdate('Y-m-d'), 2, 'Photographed the showcase', $media)['ok']);
ck('Hours: …not to one they do not', !Volunteers::logHours(9701, gmdate('Y-m-d'), 2, 'Something', (int) VolunteerProjects::bySlug('bec')['id'])['ok']);
Volunteers::logHours(9701, gmdate('Y-m-d'), 1, 'General help', 0);
$by = array_column(VolunteerProjects::hoursByProject(9701), 'hours', 'name');
ck('Hours: read per project', ($by['MediaPro'] ?? 0) == 2 && ($by['Other'] ?? 0) == 1);
ck('Hours: the CSV names the project', str_contains(Volunteers::hoursCsv(9701), ',MediaPro,'));

/* The Studio */
$b = MemberRoster::bulk([9702], 'volunteer_add', ['project_id' => $sts, 'type' => 'mentor'], 'tester', ['self_id' => 1]);
ck('Studio: "Make volunteers" places on the chosen project', $b['ok'] && $b['applied'] === 1 && VolunteerProjects::forUser($uche)[0]['slug'] === 'sts');
ck('Studio: the member record names their projects', count(MemberRoster::get(9702)['volunteer_projects']) === 1);
$ids = array_column(MemberRoster::roster(['volunteer' => 'yes', 'q' => 'afrovanguard.org.ng', 'page_size' => 200])['members'], 'id');
ck('Studio: the roster filters volunteers', in_array(9701, array_map('intval', $ids), true) && in_array(9702, array_map('intval', $ids), true));
ck('Studio: a project lists its volunteers', in_array('Uche Mentor', array_column(VolunteerProjects::volunteersOf($sts), 'name'), true));
$rm = MemberRoster::bulk([9701], 'volunteer_remove', [], 'tester', ['self_id' => 1]);
ck('Studio: taken off, every placement ends; hours stay', $rm['applied'] === 1 && VolunteerProjects::forUser($tola) === [] && !Volunteers::isVolunteer($tola)
    && (int) $pp->query('SELECT COUNT(*) FROM vol_hours WHERE user_id = 9701')->fetchColumn() === 2);

$pp->exec('DELETE FROM vol_assignments WHERE user_id BETWEEN 9700 AND 9799');
$pp->exec('DELETE FROM vol_roster WHERE user_id BETWEEN 9700 AND 9799');
$pp->exec('DELETE FROM vol_hours WHERE user_id BETWEEN 9700 AND 9799');
$pp->exec("DELETE FROM vol_projects WHERE slug LIKE 'vp-test%'");
$pp->exec('DELETE FROM lms_users WHERE id BETWEEN 9700 AND 9799');
