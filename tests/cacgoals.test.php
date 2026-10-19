<?php
/**
 * tests/cacgoals.test.php — CACENTRE's goals worked on here (lib/CacGoals.php),
 * against a fake CACENTRE that checks the token.
 *
 *   • A goal is mirrored here, owned by its owner's portal account, and given
 *     steps; a goal whose owner has no account here is left out.
 *   • Its open steps count toward the owner's commitment to it this week, so
 *     ticking one here counts there.
 *   • Run again: nothing doubles. Closed at CACENTRE: closed here.
 *   • CACENTRE down: nothing changes here.
 *
 * Run via tests/run.php (provides ck()).
 */
declare(strict_types=1);

$gp = Database::pdo();
CacGoals::ensure();
$gp->exec('DELETE FROM lms_users WHERE id BETWEEN 9910 AND 9919');
$gp->exec('DELETE FROM collab_tasks WHERE creator_id BETWEEN 9910 AND 9919');
$gp->exec('DELETE FROM team_goals WHERE cac_goal_id > 0 OR author_id BETWEEN 9910 AND 9919');
$gp->prepare('INSERT INTO lms_users (id, name, email, password_hash, role, status) VALUES (?,?,?,?,?,?)')->execute([9911, 'Ola Goal', 'ola.goal@mail.test', 'x', 'member', 'active']);
$ola = ['id' => 9911, 'name' => 'Ola Goal', 'email' => 'ola.goal@mail.test'];
/* An administrator, so a goal whose owner is not on the portal has a steward. */
$gp->prepare('INSERT INTO lms_users (id, name, email, password_hash, role, status) VALUES (?,?,?,?,?,?)')->execute([9910, 'Ada Steward', 'ada.steward@mail.test', 'x', 'admin', 'active']);

$oldUrl = getenv('CAC_SITE_URL'); $oldSecret = getenv('AV_SSO_SECRET');
putenv('CAC_SITE_URL='); putenv('AV_SSO_SECRET=');
ck('cac goals: not set up here → no request, says so', CacGoals::sync()['error'] === 'not-configured' && CacGoals::syncIfDue() === null);

$port = 0;
if ($s = @stream_socket_server('tcp://127.0.0.1:0', $e1, $e2)) { $nm = (string) stream_socket_get_name($s, false); $port = (int) substr($nm, strrpos($nm, ':') + 1); fclose($s); }
$state = tempnam(sys_get_temp_dir(), 'avcg');
$goal = ['id' => 41, 'code' => 'G-41', 'title' => 'Twelve sessions', 'unit' => 'count', 'target' => 12, 'baseline' => 0, 'direction' => 'increase', 'progress' => 4, 'started_on' => '2026-10-01', 'due_on' => '2026-10-31', 'status' => 'active', 'entity' => 'MAM Academy', 'owner_av' => 9911, 'owner_name' => 'Ola', 'commitment_id' => 7];
$orphan = ['id' => 42, 'code' => 'G-42', 'title' => 'Nobody here owns this', 'status' => 'active', 'owner_av' => 0, 'owner_emails' => ['stranger@cacentre.test'], 'owner_name' => 'Tunde Stranger', 'commitment_id' => 9];
/* Not linked at CACENTRE, but one of the owner's addresses there is a member here. */
$byMail = ['id' => 43, 'code' => 'G-43', 'title' => 'Owner known by email', 'status' => 'draft', 'owner_av' => 0, 'owner_emails' => ['ola@cacentre.test', 'OLA.GOAL@mail.test'], 'commitment_id' => 0];
/* No owner at all there: still comes across, held by the steward, and says so. */
$ownerless = ['id' => 44, 'code' => 'G-44', 'title' => 'Nobody owns this yet', 'status' => 'draft', 'owner_av' => 0, 'owner_emails' => [], 'owner_name' => '', 'commitment_id' => 0, 'due_on' => '2026-12-31'];
/* Past its date and not done there: still work to finish, so it stays open here, and says so. */
$missed = ['id' => 45, 'code' => 'G-45', 'title' => 'Ten home visits', 'status' => 'missed', 'owner_av' => 9911, 'commitment_id' => 0, 'due_on' => '2026-10-01'];
file_put_contents($state, json_encode(['goals' => [$goal, $orphan, $byMail, $ownerless, $missed]]));
$secret = str_repeat('s', 48);
$stub = $port > 0 ? @proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, AV_ROOT . '/tests/fixtures/cac-goals-stub.php'],
    [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'a'], 2 => ['file', '/dev/null', 'a']], $pipes, null, array_merge(getenv(), ['CAC_STUB_STATE' => $state, 'AV_SSO_SECRET' => $secret])) : null;
$up = false;
for ($i = 0; $i < 100 && $port > 0; $i++) { $c = @fsockopen('127.0.0.1', $port, $a, $b, 0.2); if ($c) { fclose($c); $up = true; break; } usleep(50000); }

if (!is_resource($stub) || !$up) {
    echo "  (skipped: could not start the fake CACENTRE)\n";
} else {
    putenv('CAC_SITE_URL=http://127.0.0.1:' . $port); putenv('AV_SSO_SECRET=' . $secret);
    $mirror = static fn(int $cac) => ($q = $gp->query('SELECT * FROM team_goals WHERE cac_goal_id = ' . $cac)->fetch(PDO::FETCH_ASSOC)) ?: null;

    $r = CacGoals::sync();
    ck('cac goals: read over the bridge', $r['ok'] && $r['goals'] === 5);
    $ms45 = $mirror(45);
    ck('cac goals: a goal that missed its date there is still open here — it is not done', $ms45 && (int) $ms45['closed'] === 0);
    ck('cac goals: …and its line says it missed its date', $ms45 && str_contains((string) $ms45['target'], 'missed its date'));
    /* A new version reads at once, not an hour after the old one did. */
    Database::metaSet('cac_goals_synced_at', (string) time()); Database::metaSet('cac_goals_ver', '1');
    ck('cac goals: a new version of the sync reads CACENTRE at once', CacGoals::syncIfDue() !== null && Database::metaGet('cac_goals_ver') === (string) CacGoals::VERSION);
    ck('cac goals: …and then waits its hour', CacGoals::syncIfDue() === null);
    $cs = CacGoals::status();
    ck('cac goals: the Tasks page can say what CACENTRE last answered', $cs['ready'] && $cs['last']['ok'] && $cs['last']['goals'] === 5);
    ck('cac goals: an owner CACENTRE has not linked is found by an address it has for them', ($bm = $mirror(43)) && (int) $bm['author_id'] === 9911);
    ck('cac goals: a goal still in draft there is open here, as the console lists it', $bm && (int) $bm['closed'] === 0);
    $m = $mirror(41);
    ck('cac goals: mirrored here, owned by the owner’s portal account', $m && (int) $m['author_id'] === 9911 && $m['title'] === 'G-41 · Twelve sessions' && (int) $m['closed'] === 0);
    ck('cac goals: its measure in a line', $m && $m['target'] === '12 count by 31 Oct 2026 · MAM Academy');
    $stew = (int) $gp->query("SELECT id FROM lms_users WHERE status = 'active' AND role = 'admin' ORDER BY id LIMIT 1")->fetchColumn();
    $om = $mirror(42);
    ck('cac goals: an owner who is not on the portal does not keep the goal off it — the steward holds it',
       $stew > 0 ? ($om && (int) $om['author_id'] === $stew && $r['stewarded'] === 2) : ($om === null && $r['no_owner'] === 1));
    $no = $mirror(44);
    ck('cac goals: a goal with no owner at all comes across too, held by the steward, and says it has none',
       $no && (int) $no['author_id'] === $stew && (int) $no['closed'] === 0 && str_contains((string) $no['target'], 'no owner at CACENTRE yet'));
    ck('cac goals: …with the owner\'s name on it, and no commitment of theirs counted', !$om || (str_contains((string) $om['target'], 'owner at CACENTRE: Tunde Stranger') && (int) $om['cac_commitment_id'] === 0));
    $ms = GoalTrack::shape($m, 9911);
    ck('cac goals: the mirror carries CACENTRE’s measure — 4 of 12 count is a third', $ms['measured'] && $ms['target_value'] === 12.0 && $ms['current_value'] === 4.0 && $ms['pct'] === 33);
    ck('cac goals: and its dates, so its pace is judged as CACENTRE judges it', $ms['due_on'] === '2026-10-31' && $ms['start_on'] === '2026-10-01');
    ck('cac goals: measured there, so nobody is asked to check in on it here', $ms['mirror'] && !$ms['checkin_due']);
    $steps = $m ? $gp->query('SELECT * FROM collab_tasks WHERE goal_id = ' . (int) $m['id'])->fetchAll(PDO::FETCH_ASSOC) : [];
    ck('cac goals: given steps here (three goals a run; the fourth next time)', $r['planned'] === ($stew > 0 ? 3 : 2) && count($steps) >= 1);
    ck('cac goals: every open step counts toward this week’s commitment', $steps && count(array_filter($steps, static fn($t) => (int) $t['cac_commitment_id'] === 7)) === count($steps) && $r['attached'] === count($steps));

    $t = (int) ($steps[0]['id'] ?? 0);
    Collab::toggleTask((int) $steps[0]['assignee_id'] ?: 9911, $t);
    $w = CacWeek::forMember($ola);
    ck('cac goals: ticking a step here counts there', (float) ($w['commitments'][0]['delivered'] ?? 0) === 1.0);

    $r = CacGoals::sync();
    ck('cac goals: run again → nothing doubles', (int) $gp->query('SELECT COUNT(*) FROM team_goals WHERE cac_goal_id = 41')->fetchColumn() === 1 && $r['attached'] === 0);

    $goal['status'] = 'achieved';
    file_put_contents($state, json_encode(['goals' => [$goal]] + (json_decode((string) file_get_contents($state), true) ?: [])));
    $r = CacGoals::sync();
    ck('cac goals: closed at CACENTRE → closed here', (int) ($mirror(41)['closed'] ?? 0) === 1 && $r['closed'] === 1);

    $st = json_decode((string) file_get_contents($state), true); $st['down'] = true; file_put_contents($state, json_encode($st));
    $before = $gp->query('SELECT COUNT(*) FROM team_goals')->fetchColumn();
    $r = CacGoals::sync();
    ck('cac goals: CACENTRE down → nothing changes here', !$r['ok'] && $gp->query('SELECT COUNT(*) FROM team_goals')->fetchColumn() === $before);

    $tok = json_decode((string) base64_decode(strtr(explode('.', CacSso::mint(['id' => 0], 'goals'))[1], '-_', '+/')), true);
    ck('cac goals: the token is for the goals door only', ($tok['aud'] ?? '') === 'goals');
}
if (is_resource($stub)) { proc_terminate($stub); proc_close($stub); }
@unlink($state); @unlink($state . '.log');
putenv('CAC_SITE_URL=' . ($oldUrl === false ? '' : $oldUrl)); putenv('AV_SSO_SECRET=' . ($oldSecret === false ? '' : $oldSecret));
$gp->exec('DELETE FROM collab_tasks WHERE creator_id BETWEEN 9910 AND 9919');
$gp->exec('DELETE FROM team_goals WHERE cac_goal_id > 0 OR author_id BETWEEN 9910 AND 9919');
$gp->exec('DELETE FROM lms_users WHERE id BETWEEN 9910 AND 9919');
