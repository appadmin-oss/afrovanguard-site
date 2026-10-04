<?php
/**
 * tests/ngvtrainingwatch.test.php — the training fee, watched by staff.
 *
 * The participant has watched their own training fee month by month on the
 * dashboard since schedules existed; the console showed one person's
 * instalments as numbered dots and nothing across the programme. What is
 * pinned here:
 *
 *   • Every participant with a schedule is on the watch — and so is one whose
 *     schedule was STOPPED with charges left on the account, because those
 *     charges are still owed or still need a decision.
 *   • "Behind" counts instalments the way the participant reads them: money
 *     goes to the oldest instalment first, so ₦30,000 against three ₦20,000
 *     instalments is one behind, not three.
 *   • A waiver or write-off settles an instalment but is never called money
 *     received.
 *   • Filter and sort are allow-listed; an unknown value falls back rather than
 *     erroring, so a stale link still shows the list.
 *   • It costs three queries however long the roster is.
 *   • The console renders it, and one person's record shows their months the
 *     way their own dashboard does.
 *
 * Run via tests/run.php (provides ck() and render_page()).
 */
declare(strict_types=1);

$twPdo = NgvDb::pdo();
foreach (['ngv_charges', 'ngv_payments', 'ngv_participants', 'ngv_applications'] as $t) { try { $twPdo->exec('DELETE FROM ' . $t); } catch (Throwable $e) {} }
try { Database::metaSet('ngv_fees', ''); } catch (Throwable $e) {}
$twc = new ReflectionProperty('NgvLedger', 'cache'); $twc->setAccessible(true); $twc->setValue(null, null);
NgvLedger::saveSettings(['enabled' => true, 'accrueFrom' => '2026-01-01'], 'test');

$twPerson = static function (int $id, string $name, string $cohort = '') use ($twPdo): void {
    $twPdo->prepare('INSERT INTO ngv_participants (member_id,name,email,plan,status,cohort,start_date,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?)')
        ->execute([$id, $name, strtolower(str_replace(' ', '', $name)) . '@watch.test', 'Full Programme', 'active', $cohort, '2026-01-05', '2026-01-05', '2026-01-05']);
};
$twPerson(701, 'Ada Behind', '2026 Alpha');
$twPerson(702, 'Bola Paid', '2026 Alpha');
$twPerson(703, 'Chidi Stopped');
$twPerson(704, 'Dayo NoFee');
$twPerson(705, 'Efe Waived');

// Ada: ₦240,000 over 12 from Jan; three instalments posted by March, ₦30,000 paid.
NgvLedger::startTrainingFee(701, 12, 1, 240000, '2026-01-07');
NgvLedger::startTrainingFee(701, 12, 1, 240000, '2026-03-07');   // refused: one is running
$twPdo->exec("UPDATE ngv_participants SET training_from = '2026-01' WHERE member_id = 701");
$twRun = new ReflectionMethod('NgvLedger', 'accrueTraining'); $twRun->setAccessible(true);
$twRun->invoke(null, 701, '2026-03-07', 1);
NgvLedger::payment(701, 'programme', 30000, ['method' => 'transfer', 'reference' => 'TW-1'], 1);
// Bola: in full, paid.
NgvLedger::startTrainingFee(702, 1, 1, 100000, '2026-03-07');
NgvLedger::payment(702, 'programme', 100000, ['method' => 'cash'], 1);
// Chidi: two instalments posted, then the schedule stopped.
NgvLedger::startTrainingFee(703, 6, 1, 60000, '2026-02-07');
$twRun->invoke(null, 703, '2026-03-07', 1);
NgvLedger::stopTrainingFee(703);
// Efe: in full, half paid and half waived.
NgvLedger::startTrainingFee(705, 1, 1, 50000, '2026-03-07');
NgvLedger::payment(705, 'programme', 25000, ['method' => 'cash'], 1);
NgvLedger::waive(705, 'programme', 25000, 'Hardship — agreed with the family', 1);

$tw = NgvLedger::trainingWatch('all', '', 'behind', 200, '2026-03-20');
$byId = []; foreach ($tw['rows'] as $r) $byId[$r['member_id']] = $r;

ck('ngv training watch: everybody with a schedule is listed, nobody without one', isset($byId[701], $byId[702], $byId[705]) && !isset($byId[704]));
ck('ngv training watch: a stopped schedule with charges left on the account is still watched',
   isset($byId[703]) && $byId[703]['state'] === 'stopped' && $byId[703]['chargedN'] === 2 && $byId[703]['unpaid'] === 20000);
$a = $byId[701] ?? [];
ck('ngv training watch: three of twelve charged, ₦30,000 received, ₦30,000 unpaid',
   ($a['chargedN'] ?? 0) === 3 && ($a['months'] ?? 0) === 12 && ($a['received'] ?? 0) === 30000 && ($a['unpaid'] ?? 0) === 30000);
ck('ngv training watch: payments go to the oldest instalment first — one and a half paid is two behind, not three',
   ($a['behindN'] ?? 0) === 2);
ck('ngv training watch: the next instalment and what is left to charge',
   ($a['next']['period'] ?? '') === '2026-04' && ($a['next']['amount'] ?? 0) === 20000 && ($a['toCharge'] ?? 0) === 180000);
ck('ngv training watch: a paid-in-full fee is complete and up to date', ($byId[702]['state'] ?? '') === 'complete' && ($byId[702]['unpaid'] ?? 1) === 0);
ck('ngv training watch: a waiver settles the fee but is never called money received',
   ($byId[705]['received'] ?? 0) === 25000 && ($byId[705]['setAside'] ?? 0) === 25000 && ($byId[705]['unpaid'] ?? 1) === 0 && ($byId[705]['state'] ?? '') === 'complete');
ck('ngv training watch: the most unpaid comes first', ($tw['rows'][0]['member_id'] ?? 0) === 701);
ck('ngv training watch: the totals add up across the list',
   $tw['totals']['people'] === 4 && $tw['totals']['unpaid'] === 50000 && $tw['totals']['received'] === 155000 && $tw['totals']['setAside'] === 25000);
ck('ngv training watch: counts per filter', $tw['counts']['behind'] === 2 && $tw['counts']['stopped'] === 1 && $tw['counts']['complete'] === 2 && $tw['counts']['all'] === 4);
ck('ngv training watch: "behind" lists exactly those who owe instalments',
   array_column(NgvLedger::trainingWatch('behind', '', 'name', 200, '2026-03-20')['rows'], 'member_id') === [701, 703]);
ck('ngv training watch: search by name or cohort', array_column(NgvLedger::trainingWatch('all', 'alpha', 'name', 200, '2026-03-20')['rows'], 'member_id') === [701, 702]);
$twOdd = NgvLedger::trainingWatch("x' OR 1=1", '', 'DROP', 200, '2026-03-20');
ck('ngv training watch: an unknown filter or sort falls back rather than erroring', $twOdd['filter'] === 'all' && $twOdd['sort'] === 'behind' && count($twOdd['rows']) === 4);

// Three queries, whatever the roster.
$twSrc = (string) file_get_contents(dirname(__DIR__) . '/lib/NgvLedger.php');
$twBody = substr($twSrc, (int) strpos($twSrc, 'public static function trainingWatch('), 6000);
$twBody = substr($twBody, 0, (int) strpos($twBody, "\n    }\n") ?: 6000);
ck('ngv training watch: three queries, none inside the per-person loop',
   substr_count($twBody, '->query(') === 3 && !str_contains($twBody, 'self::balance(') && !str_contains($twBody, 'self::charges(')
   && !str_contains($twBody, 'trainingSchedule(') && !str_contains($twBody, '->prepare('));

// The console renders it — the table, and one person's months.
$twSecret = av_secret();
$twExp = (string) (time() + 600); $twNonce = 'twnonce';
$_COOKIE[AV_ADMIN_COOKIE] = $twExp . '.' . $twNonce . '.admin.' . hash_hmac('sha256', $twExp . '.' . $twNonce . '.admin', $twSecret);
$twHtml = render_page(dirname(__DIR__) . '/academy/ngv/members.php', ['tf' => 'behind', 'm' => '701']);
unset($_COOKIE[AV_ADMIN_COOKIE]);
ck('ngv training watch: the console renders without throwing', $twHtml !== '' && !str_contains($twHtml, 'render_page: threw'));
ck('ngv training watch: the console has a Training fees section, filtered to who is behind',
   str_contains($twHtml, 'id="training"') && str_contains($twHtml, 'Ada Behind') && str_contains($twHtml, 'Chidi Stopped')
   && !str_contains(substr($twHtml, (int) strpos($twHtml, 'id="training"'), 9000), 'Bola Paid'));
ck('ngv training watch: one person\'s record shows their months, not numbered dots',
   str_contains($twHtml, 'class="tw-sched"') && str_contains($twHtml, 'tw-inst--charged') && str_contains($twHtml, 'tw-inst--upcoming')
   && !str_contains($twHtml, 'class="inst inst--'));
