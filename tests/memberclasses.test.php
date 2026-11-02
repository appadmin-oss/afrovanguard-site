<?php
/**
 * tests/memberclasses.test.php — what KIND of member somebody is, and what
 * that kind pays (lib/MemberClasses.php).
 *
 * The first assertion is the one that matters most and it is deliberately
 * first: a deployment that never opens this screen is charged exactly what it
 * was charged before classes existed, to the naira. Everything below is a new
 * price somebody chose; that one is a price nobody chose, and it must not move.
 *
 *   • No class → the single dues price, unchanged.
 *   • A class with its own monthly price prices that member, and a year owed
 *     in one class is still priced at the year's rate.
 *   • A class that owes nothing is not obliged, and the reason names it.
 *   • A class change is DATED: the months already owed keep the price of the
 *     class in force on the day each one fell due. Backdating re-prices from
 *     the day the office says, not from today.
 *   • A member's own screen quotes their own price, and the offline desk
 *     accepts what that screen told them to pay.
 *
 * Run via tests/run.php (provides ck()).
 */
declare(strict_types=1);

$mcPdo = Database::pdo();
MemberRoster::ensure(); Membership::ensure(); MemberClasses::ensure();
$mcPdo->exec("DELETE FROM member_class_log WHERE user_id IN (SELECT id FROM lms_users WHERE email LIKE '%@mclass.test')");
$mcPdo->exec("DELETE FROM memberships WHERE user_id IN (SELECT id FROM lms_users WHERE email LIKE '%@mclass.test')");
$mcPdo->exec("DELETE FROM member_profiles WHERE user_id IN (SELECT id FROM lms_users WHERE email LIKE '%@mclass.test')");
$mcPdo->exec("DELETE FROM lms_users WHERE email LIKE '%@mclass.test'");
$mcPdo->exec("DELETE FROM member_classes WHERE class_key LIKE 'tst-%'");
MemberClasses::forget();
$mcKeep = Database::metaGet(Membership::SETTINGS_KEY);

$mcMk = function (string $name, string $joined = '2026-01-15') use ($mcPdo): int {
    $mcPdo->prepare('INSERT INTO lms_users (name, email, password_hash, role, status, created_at) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute([$name, strtolower(str_replace(' ', '.', $name)) . '@mclass.test', 'x', 'member', 'active', $joined . ' 09:00:00']);
    $id = (int) $mcPdo->lastInsertId();
    $mcPdo->prepare("UPDATE lms_users SET level = 'O' WHERE id = ?")->execute([$id]);
    $mcPdo->prepare("INSERT INTO member_profiles (user_id, joined_on, phone, centre, notes, work_roles, about, source, updated_at) VALUES (?, ?, '', '', '', '', '', '', '')")->execute([$id, $joined]);
    return $id;
};
Database::metaSet(Membership::SETTINGS_KEY, (string) json_encode(
    ['enabled' => true, 'monthly_ngn' => 1000, 'annual_ngn' => 12000, 'from_level' => 'O', 'start' => '2026-01-01']));
Membership::forgetSettings();
$mcAt = static fn(string $d) => (int) strtotime($d . ' 12:00:00 UTC');

/* ── Nothing changes until the office uses it ────────────────────────── */
$mcPlain = $mcMk('Plain Member', '2026-03-05');
$mcA = Membership::arrearsFor([$mcPlain], $mcAt('2026-10-23'))[$mcPlain] ?? null;
ck('classes: a member in no class is priced exactly as before — 8 months at ₦1,000',
   $mcA && $mcA['months'] === 8 && $mcA['amount_ngn'] === 8000);
ck('classes: …and the standard price is still the standard price',
   Membership::price(8) === 8000 && Membership::price(12) === 12000 && Membership::price(14) === 14000);
ck('classes: …with no class, a member is quoted the standard price',
   Membership::price(12, $mcPlain) === 12000 && Membership::monthlyFor($mcPlain) === 1000);

/* ── A class with its own price ──────────────────────────────────────── */
$mcStu = MemberClasses::save(['name' => 'Tst Student', 'class_key' => 'tst-student', 'monthly_ngn' => 250, 'owes' => 1], 'test');
$mcWrk = MemberClasses::save(['name' => 'Tst Working', 'class_key' => 'tst-working', 'monthly_ngn' => 2000, 'annual_ngn' => 20000, 'owes' => 1], 'test');
$mcPat = MemberClasses::save(['name' => 'Tst Patron', 'class_key' => 'tst-patron', 'monthly_ngn' => 9999, 'owes' => 0], 'test');
ck('classes: the office can add a class', !empty($mcStu['ok']) && !empty($mcWrk['ok']) && !empty($mcPat['ok'])
   && $mcStu['class_key'] === 'tst-student');
ck('classes: a monthly price with no yearly one is twelve months, not a free year',
   MemberClasses::priceMonths(12, 'tst-student') === 3000 && MemberClasses::priceMonths(1, 'tst-student') === 250);
ck('classes: a yearly price of its own is what a year costs',
   MemberClasses::priceMonths(12, 'tst-working') === 20000 && MemberClasses::priceMonths(13, 'tst-working') === 22000);
ck('classes: a class that owes nothing costs nothing, whatever is typed in its price',
   MemberClasses::priceMonths(12, 'tst-patron') === 0 && MemberClasses::priceOf('tst-patron')['owes'] === false);

$mcStudent = $mcMk('Sade Student', '2026-03-05');
ck('classes: putting a member in a class is recorded',
   !empty(MemberClasses::assign($mcStudent, 'tst-student', '2026-03-05', 'test', 'joined as a student')['ok'])
   && MemberClasses::of($mcStudent) === 'tst-student');
$mcB = Membership::arrearsFor([$mcStudent], $mcAt('2026-10-23'))[$mcStudent] ?? null;
ck('classes: …and their arrears are priced at their class — 8 months at ₦250',
   $mcB && $mcB['months'] === 8 && $mcB['amount_ngn'] === 2000);
ck('classes: …their own screen quotes their own price, not the standard one',
   Membership::price(1, $mcStudent) === 250 && Membership::monthlyFor($mcStudent) === 250
   && OfflinePayments::duesPrice(1, $mcStudent) === 250 && OfflinePayments::duesPrice(1) === 1000);

/* ── A class that owes nothing is not asked ──────────────────────────── */
$mcPatron = $mcMk('Tunde Patron', '2026-03-05');
MemberClasses::assign($mcPatron, 'tst-patron', '2026-03-05', 'test');
$mcOb = Membership::obligation($mcPatron);
ck('classes: a patron owes no dues, and the reason names the class',
   $mcOb['owes'] === false && $mcOb['code'] === 'class' && str_contains($mcOb['why'], 'Tst Patron'));
ck('classes: …and they are not in the obliged list at all',
   !isset(Membership::arrearsFor([$mcPatron], $mcAt('2026-10-23'))[$mcPatron]));
$mcObS = Membership::obligation($mcStudent);
ck('classes: a student does owe, and the reason carries their price',
   $mcObS['owes'] === true && str_contains($mcObS['why'], '₦250'));

/* ── The past keeps the price it was charged at ──────────────────────── */
/* Sade finishes school in July and moves onto the working rate. The four
   months she was a student stay at ₦250; the months after are ₦2,000. This
   is the whole reason the change is dated rather than overwritten. */
MemberClasses::assign($mcStudent, 'tst-working', '2026-07-05', 'test', 'started work');
$mcC = Membership::arrearsFor([$mcStudent], $mcAt('2026-10-23'))[$mcStudent] ?? null;
ck('classes: a class change re-prices the months after it and NOT the months before',
   $mcC && $mcC['months'] === 8 && $mcC['amount_ngn'] === (4 * 250) + (4 * 2000));
ck('classes: …and the arrears say which months were priced how',
   $mcC && count($mcC['classes']) === 2
   && $mcC['classes'][0] === ['class' => 'tst-student', 'months' => 4, 'amount_ngn' => 1000]
   && $mcC['classes'][1] === ['class' => 'tst-working', 'months' => 4, 'amount_ngn' => 8000]);
ck('classes: the class in force on a day is read from the log, not from today',
   MemberClasses::onDay($mcStudent, '2026-05-05') === 'tst-student'
   && MemberClasses::onDay($mcStudent, '2026-07-05') === 'tst-working'
   && MemberClasses::onDay($mcStudent, '2026-01-01') === '');

/* A member who has been in ONE class for a year is still charged the year's
   rate: the discount is for the span, not for when it is paid. */
$mcYear = $mcMk('Yemi Year', '2025-09-01');
MemberClasses::assign($mcYear, 'tst-working', '2025-09-01', 'test');
/* Dues began on 1 Jan 2026, so that is the anchor however long ago she
   joined; read far enough ahead to owe more than a year. */
$mcD = Membership::arrearsFor([$mcYear], $mcAt('2027-02-15'))[$mcYear] ?? null;
ck('classes: fourteen months owed in one class is a year plus two, at that class\'s rates',
   $mcD && $mcD['months'] === 14 && $mcD['amount_ngn'] === 20000 + (2 * 2000)
   && $mcD['classes'] === [['class' => 'tst-working', 'months' => 14, 'amount_ngn' => 24000]]);

/* ── What assign refuses ─────────────────────────────────────────────── */
ck('classes: a class that does not exist is refused',
   (MemberClasses::assign($mcStudent, 'tst-nope', '2026-01-01', 'test')['code'] ?? '') === 'no_class');
ck('classes: a day that has not come is refused — a price nobody is on is a plan',
   (MemberClasses::assign($mcStudent, 'tst-student', gmdate('Y-m-d', time() + 86400 * 3), 'test')['code'] ?? '') === 'future');
ck('classes: a day that is not a day is refused',
   (MemberClasses::assign($mcStudent, 'tst-student', 'next week', 'test')['code'] ?? '') === 'bad_day');
MemberClasses::save(['id' => MemberClasses::get('tst-patron')['id'], 'name' => 'Tst Patron', 'owes' => 0, 'archived' => 1], 'test');
ck('classes: an archived class takes nobody new',
   (MemberClasses::assign($mcStudent, 'tst-patron', '2026-09-01', 'test')['code'] ?? '') === 'archived');
ck('classes: …but a member already in it still prices, because their past months must',
   MemberClasses::priceOf('tst-patron')['owes'] === false && MemberClasses::get('tst-patron') !== null);
ck('classes: a negative price is refused', empty(MemberClasses::save(['name' => 'Tst Bad', 'monthly_ngn' => -1], 'test')['ok']));
ck('classes: a price above the ceiling is refused',
   empty(MemberClasses::save(['name' => 'Tst Huge', 'monthly_ngn' => MemberClasses::PRICE_MAX + 1], 'test')['ok']));
ck('classes: two classes cannot share a key',
   (MemberClasses::save(['name' => 'Tst Student Again', 'class_key' => 'tst-student'], 'test')['code'] ?? '') === 'exists');

/* The key is what the history points at. Renaming a class must not move it,
   or every month already priced would be orphaned. */
MemberClasses::save(['id' => MemberClasses::get('tst-student')['id'], 'name' => 'Tst Learner', 'monthly_ngn' => 250, 'owes' => 1], 'test');
ck('classes: renaming a class keeps its key, so the months already priced still find it',
   MemberClasses::get('tst-student') !== null && MemberClasses::label('tst-student') === 'Tst Learner'
   && MemberClasses::onDay($mcStudent, '2026-05-05') === 'tst-student');

/* ── The filter, so "who is on the student rate" is answerable ───────── */
$mcFiltered = MemberRoster::roster(['member_class' => 'tst-working', 'page_size' => 50]);
$mcIds = array_column($mcFiltered['members'] ?? [], 'id');
ck('classes: the roster filters by class',
   in_array($mcStudent, $mcIds, true) && in_array($mcYear, $mcIds, true) && !in_array($mcPlain, $mcIds, true));
$mcNone = MemberRoster::roster(['member_class' => 'none', 'page_size' => 200]);
ck('classes: …and "in no class" finds the members nobody has classed',
   in_array($mcPlain, array_column($mcNone['members'] ?? [], 'id'), true)
   && !in_array($mcStudent, array_column($mcNone['members'] ?? [], 'id'), true));
$mcRow = null;
foreach ($mcFiltered['members'] ?? [] as $r) if ((int) $r['id'] === $mcStudent) $mcRow = $r;
ck('classes: …and a roster row carries the class and its name, not just its key',
   $mcRow && $mcRow['member_class'] === 'tst-working' && $mcRow['member_class_label'] === 'Tst Working');

/* ── The office can actually reach it ────────────────────────────────── */
$mcApi = (string) file_get_contents(AV_ROOT . '/admin/api.php');
ck('classes: the Studio has the three actions, and the PRICES are the Super Admin\'s',
   str_contains($mcApi, "case 'member_classes':") && str_contains($mcApi, "case 'member_class_save':")
   && str_contains($mcApi, "case 'member_class_assign':")
   && preg_match('/\$superadminOnly = \[(.|\n)*?\];/', $mcApi, $mcSu) && str_contains($mcSu[0], "'member_class_save'")
   && preg_match('/\$managementOnly = \[(.|\n)*?\];/', $mcApi, $mcMg) && str_contains($mcMg[0], "'member_class_assign'")
   && !str_contains($mcSu[0], "'member_class_assign'"));
$mcHtml = (string) file_get_contents(AV_ROOT . '/admin/index.php');
$mcJs   = (string) file_get_contents(AV_ROOT . '/admin/app.js');
ck('classes: …a pane to keep the list, a filter on the roster, and a control on the member',
   str_contains($mcHtml, 'data-pane="classes"') && str_contains($mcHtml, 'data-mv="classes"')
   && str_contains($mcHtml, 'id="memClass"') && str_contains($mcHtml, 'id="mdClass"')
   && str_contains($mcJs, "post('member_class_assign'") && str_contains($mcJs, "post('member_class_save'"));
ck('classes: …and the member sees their own class on their own card',
   str_contains((string) file_get_contents(AV_ROOT . '/portal/views/membership.php'), 'Membership::classOf'));

/* ── Clean up ────────────────────────────────────────────────────────── */
$mcPdo->exec("DELETE FROM member_class_log WHERE user_id IN (SELECT id FROM lms_users WHERE email LIKE '%@mclass.test')");
$mcPdo->exec("DELETE FROM memberships WHERE user_id IN (SELECT id FROM lms_users WHERE email LIKE '%@mclass.test')");
$mcPdo->exec("DELETE FROM member_profiles WHERE user_id IN (SELECT id FROM lms_users WHERE email LIKE '%@mclass.test')");
$mcPdo->exec("DELETE FROM lms_users WHERE email LIKE '%@mclass.test'");
$mcPdo->exec("DELETE FROM member_classes WHERE class_key LIKE 'tst-%'");
MemberClasses::forget();
if ($mcKeep !== null) Database::metaSet(Membership::SETTINGS_KEY, $mcKeep);
Membership::forgetSettings();
