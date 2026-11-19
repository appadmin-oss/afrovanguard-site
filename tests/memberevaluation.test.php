<?php
/**
 * tests/memberevaluation.test.php — how one member is doing, and NextGen
 * Vanguards as members.
 *
 *   • The member drawer reads attendance, commitments, growth and money side
 *     by side, and gives one standing with its reasons written out.
 *   • A judgement needs evidence: too few days at the gate is said, not
 *     scored; two absences out of two is not "under 70%".
 *   • The roster's attendance tiles and the drawer use the same rule, so a
 *     member in "Attendance under 70%" is one the drawer says needs attention.
 *   • A vanguard's programme is a block of their member record (status, track,
 *     points, probation, account); somebody who is not one has no such block.
 *   • NextGen Vanguard is part of Members in the Studio, not a section of its own.
 *
 * Run via tests/run.php (provides ck()).
 */
declare(strict_types=1);

$mePdo = Database::pdo();
GateAttendance::ensure();
try { NgvDb::pdo()->exec('DELETE FROM ngv_participants'); } catch (Throwable $e) {}
$meUser = static function (string $name) use ($mePdo): int {
    $email = strtolower(str_replace(' ', '.', $name)) . '.' . bin2hex(random_bytes(3)) . '@example.test';
    $mePdo->prepare("INSERT INTO lms_users (name, email, password_hash, role, status) VALUES (?, ?, 'x', 'member', 'active')")->execute([$name, $email]);
    return (int) $mePdo->lastInsertId();
};
$meDays = static function (int $id, array $statuses) use ($mePdo): void {
    $ins = $mePdo->prepare('INSERT INTO gate_attendance (member_id, day, status, late_minutes) VALUES (?, ?, ?, ?)');
    foreach ($statuses as $i => $st) $ins->execute([$id, gmdate('Y-m-d', strtotime('-' . (2 + $i) . ' days')), $st, $st === 'late' ? 12 : 0]);
};

$meGood = $meUser('Ada Steady');
$meDays($meGood, array_merge(array_fill(0, 9, 'present'), ['absent']));
$meWeak = $meUser('Bayo Away');
$meDays($meWeak, ['present', 'late', 'absent', 'late', 'absent', 'present', 'late', 'absent', 'late', 'absent']);
$meNew = $meUser('Chi New');
$meDays($meNew, ['absent', 'absent']);

$eG = MemberEvaluation::evaluate($meGood);
ck('evaluation: attendance over 90 days — came, on time, absent', $eG['ok'] && $eG['attendance']['rate'] === 90 && $eG['attendance']['punctuality'] === 90 && $eG['attendance']['absent'] === 1);
ck('evaluation: attending and on time reads "Doing well", and says why', $eG['standing'] === 'well' && (bool) array_filter($eG['strengths'], static fn($t) => str_contains($t, '90%')));
$eW = MemberEvaluation::evaluate($meWeak);
ck('evaluation: under 70% and often late reads "Needs attention", with both reasons written out',
    $eW['standing'] === 'attention' && count(array_filter($eW['concerns'], static fn($t) => str_contains($t, '60%') || str_contains($t, 'Late 4 times'))) === 2);
ck('evaluation: lateness is counted in minutes too', $eW['attendance']['late_minutes'] === 48);
$eN = MemberEvaluation::evaluate($meNew);
ck('evaluation: two absences out of two is not a pattern — too few days is said, not scored',
    $eN['attendance']['enough'] === false && $eN['standing'] === 'steady' && (bool) array_filter($eN['notes'], static fn($t) => str_contains($t, 'Too few days')));
ck('evaluation: commitments, growth and money are read beside attendance',
    isset($eG['commitments']['rate'], $eG['growth']['level'], $eG['money']['fines_owing_ngn']) || (array_key_exists('rate', $eG['commitments'] ?? []) && isset($eG['growth']['level']) && isset($eG['money'])));
ck('evaluation: nothing unavailable on a healthy database (a part that fails is named, not zeroed)', $eG['unavailable'] === []);
ck('evaluation: somebody who is not a vanguard has no NextGen Vanguard block', $eG['vanguard'] === null);

/* The tiles and the drawer agree. */
$meIn = static function (string $seg, int $id) use ($mePdo): bool {
    [$w, $p] = MemberRoster::segmentSql($seg);
    $st = $mePdo->prepare("SELECT 1 FROM lms_users u WHERE u.id = ? AND $w"); $st->execute(array_merge([$id], $p));
    return (bool) $st->fetchColumn();
};
ck('roster: "Attendance under 70%" holds the member the drawer says needs attention', $meIn('att_low', $meWeak) && !$meIn('att_low', $meGood));
ck('roster: …and not somebody with two days counted (the same 5-day rule)', !$meIn('att_low', $meNew) && MemberRoster::ATT_MIN_DAYS === 5);
ck('roster: "Late 3+ times" in 30 days', $meIn('often_late', $meWeak) && !$meIn('often_late', $meGood));
ck('roster: "Attending and on time" holds the steady attender only', $meIn('att_strong', $meGood) && !$meIn('att_strong', $meWeak));
ck('roster: the three are segments, so they are tiles and lists like every other', isset(MemberRoster::SEGMENTS['att_low'], MemberRoster::SEGMENTS['often_late'], MemberRoster::SEGMENTS['att_strong']));

/* A vanguard is a member: their programme is part of their record. */
NgvDb::pdo()->prepare("INSERT INTO ngv_participants (member_id, name, email, status, track, cohort, start_date, created_at, updated_at) VALUES (?, 'Ada Steady', ?, 'active', 'Tech', '2026 Alpha', '2026-01-05', '2026-01-05', '2026-01-05')")
    ->execute([$meGood, 'p' . $meGood . '@example.test']);
GateAttendance::setProbation($meGood, '', 'Missed two reading reviews', 1);
$eV = MemberEvaluation::evaluate($meGood);
ck('vanguard: the programme is a block of the member\'s evaluation — status, track, cohort, start',
    is_array($eV['vanguard']) && $eV['vanguard']['status'] === 'active' && $eV['vanguard']['track'] === 'Tech' && $eV['vanguard']['cohort'] === '2026 Alpha' && $eV['vanguard']['start'] === '2026-01-05');
ck('vanguard: probation is a concern on their member record, not only on the NGV desk',
    $eV['vanguard']['probation'] !== null && $eV['standing'] === 'attention' && (bool) array_filter($eV['concerns'], static fn($t) => str_starts_with($t, 'On probation')));

/* Wired: the read, the drawer, the Studio. */
$meApi = (string) file_get_contents(AV_ROOT . '/admin/api.php');
$meJs = (string) file_get_contents(AV_ROOT . '/admin/app.js');
$meIdx = (string) file_get_contents(AV_ROOT . '/admin/index.php');
ck('wired: opening a member reads their evaluation with them (one request), and a failure is said', str_contains($meApi, "\$m['evaluation'] = MemberEvaluation::evaluate((int) \$m['id']);")
    && str_contains($meJs, 'Could not read their records just now.'));
ck('wired: the drawer opens on how they are doing, then the NextGen Vanguard block, then the details',
    strpos($meIdx, 'id="mdEval"') < strpos($meIdx, 'id="mdNgv"') && strpos($meIdx, 'id="mdNgv"') < strpos($meIdx, 'id="md_name"') && str_contains($meJs, 'memEvalDraw(m);'));
ck('wired: from a vanguard\'s record straight to their programme record', str_contains($meJs, "'/academy/ngv/members.php?m=' + encodeURIComponent("));

try { NgvDb::pdo()->exec('DELETE FROM ngv_participants'); } catch (Throwable $e) {}
$mePdo->exec('DELETE FROM gate_probation WHERE member_id IN (' . implode(',', [$meGood, $meWeak, $meNew]) . ')');
$mePdo->exec('DELETE FROM gate_attendance WHERE member_id IN (' . implode(',', [$meGood, $meWeak, $meNew]) . ')');
