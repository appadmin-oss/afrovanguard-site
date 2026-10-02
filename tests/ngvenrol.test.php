<?php
/**
 * tests/ngvenrol.test.php — nobody becomes a vanguard by looking, and a status
 * change is a money event.
 *
 *   • Opening the NGV dashboard (or its "preview") enrolled whoever opened it,
 *     as ACTIVE: fees accrued, the gate expected them, approval was skipped.
 *   • A withdrawn vanguard stayed a vanguard and a member, card and all.
 *   • Reactivating somebody charged every month since they started, including
 *     the months they were away.
 *
 * Run via tests/run.php (provides ck()).
 */
declare(strict_types=1);

$enReset = static function (): void {
    $pdo = NgvDb::pdo();
    foreach (['ngv_certifications', 'ngv_charges', 'ngv_payments', 'ngv_participants'] as $t) {
        try { $pdo->exec('DELETE FROM ' . $t); } catch (Throwable $e) {}
    }
    try { Database::metaSet('ngv_fees', ''); } catch (Throwable $e) {}
    $c = new ReflectionProperty('NgvLedger', 'cache'); $c->setAccessible(true); $c->setValue(null, null);
    NgvLedger::saveSettings(['enabled' => true, 'accrueFrom' => '2026-01-01'], 'test');
};

/* ── Looking does not enrol ─────────────────────────────────────────────── */
$enReset();
NgvMember::saveSelf(701, ['track' => 'x', 'note' => 'hello']);
ck('ngv enrol: saving from the dashboard does not create a participant', NgvMember::participant(701) === null);
ck('ngv enrol: …so somebody not enrolled is not a vanguard', !NgvMember::isVanguard(701));

$dash = (string) file_get_contents(dirname(__DIR__) . '/academy/ngv/_dashboard-data.php');
ck('ngv enrol: the dashboard data never enrols (no ensureParticipant)',
   !preg_match('/NgvMember::ensureParticipant\s*\(/', (string) preg_replace('~/\*.*?\*/~s', '', $dash)));
$page = (string) file_get_contents(dirname(__DIR__) . '/academy/ngv/dashboard.php');
ck('ngv enrol: a preview is staff\'s, and somebody not enrolled is sent to apply',
   str_contains($page, "\$ngvPreview = !empty(\$_GET['preview']) && function_exists('av_admin_role') && av_admin_role() !== '';")
   && str_contains($page, 'you are not on NextGen Vanguard yet'));
ck('ngv enrol: and every dashboard action refuses somebody not enrolled',
   strpos($page, "if (!NgvMember::isVanguard(\$uid))") < strpos($page, "'fee_request'"));

/* ── Status decides ─────────────────────────────────────────────────────── */
$enReset();
NgvMember::ensureParticipant(702, ['name' => 'Ada', 'email' => 'ada@example.test']);
ck('ngv enrol: staff enrolment makes an active vanguard', NgvMember::isVanguard(702));
NgvMember::setAdmin(702, ['status' => 'withdrawn']);
ck('ngv enrol: a withdrawn vanguard is not one any more', !NgvMember::isVanguard(702));
ck('ngv enrol: …nor counted as a member', !isset(MemberRoster::vanguardIds()[702]));
NgvMember::setAdmin(702, ['status' => 'completed']);
ck('ngv enrol: somebody who completed the programme still is', NgvMember::isVanguard(702) && isset(MemberRoster::vanguardIds()[702]));

/* ── Coming back is not billed for being away ───────────────────────────── */
$enReset();
NgvMember::ensureParticipant(703, ['name' => 'Bisi', 'email' => 'bisi@example.test']);
NgvDb::pdo()->exec("UPDATE ngv_participants SET start_date = '2026-01-05' WHERE member_id = 703");
NgvLedger::accrueParticipant(NgvMember::participant(703), '2026-02-20');
NgvDb::pdo()->exec("UPDATE ngv_participants SET status = 'withdrawn' WHERE member_id = 703");
$periods = static fn(): array => NgvDb::pdo()->query("SELECT period FROM ngv_charges WHERE member_id = 703 AND kind = 'commitment' ORDER BY period")->fetchAll(PDO::FETCH_COLUMN);
$before = $periods();
NgvMember::setAdmin(703, ['status' => 'active']);
NgvLedger::accrueParticipant(NgvMember::participant(703));
$after = $periods();
$thisMonth = date('Y-m');
$added = array_values(array_diff($after, $before));
ck('ngv enrol: reactivating does not back-charge the months away (charged: ' . implode(',', $added) . ')',
   $before !== [] && array_filter($added, static fn($k) => $k < $thisMonth) === []);
ck('ngv enrol: …and charges resume from the day they came back', NgvMember::participant(703)['accrue_from'] === date('Y-m-d'));
