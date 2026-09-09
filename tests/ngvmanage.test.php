<?php
/**
 * tests/ngvmanage.test.php — managing participants: the holes that made the
 * console unable to run a real cohort.
 *
 *   • `start_date` drives every accrual, every instalment schedule and which
 *     side of the rollout guard somebody falls on — and could not be corrected
 *     at all. A wrong one at enrolment was permanent.
 *   • Name and email are SNAPSHOTS, and the email is where receipts, reminders
 *     and statements go. A member who changed it on the main site kept a dead
 *     address here for good, with every letter delivered nowhere.
 *   • A certificate is PUBLICLY verifiable and could not be withdrawn. A typo,
 *     or one issued to the wrong person, was permanent and public.
 *   • The roster was a flat 300 with no filter — fine for one cohort, unusable
 *     at three.
 *
 * Run via tests/run.php (provides ck() and reset_users()).
 */
declare(strict_types=1);

$mgReset = static function (): void {
    $pdo = NgvDb::pdo();
    foreach (['ngv_certifications', 'ngv_charges', 'ngv_payments', 'ngv_participants'] as $t) {
        try { $pdo->exec('DELETE FROM ' . $t); } catch (Throwable $e) {}
    }
    try { Database::metaSet('ngv_fees', ''); } catch (Throwable $e) {}
    $c = new ReflectionProperty('NgvLedger', 'cache'); $c->setAccessible(true); $c->setValue(null, null);
    NgvLedger::saveSettings(['enabled' => true, 'accrueFrom' => '2026-01-01'], 'test');
};

/* ══ The enrolment date ════════════════════════════════════════════════════ */

$mgReset();
NgvMember::ensureParticipant(501, ['name' => 'Ada Obi', 'email' => 'ada@example.test']);
NgvDb::pdo()->exec("UPDATE ngv_participants SET start_date = '2026-03-10', status = 'active' WHERE member_id = 501");
NgvLedger::accrueParticipant(NgvMember::participant(501), '2026-09-07');
$chargedBefore = (int) NgvLedger::balance(501)['charged'];

NgvMember::setAdmin(501, ['start_date' => '2026-07-01']);
ck('ngv manage: the enrolment date can be corrected',
   NgvMember::participant(501)['start_date'] === '2026-07-01');
/* The ledger's rule everywhere: a charge posted keeps the figure it was posted
   at. Fixing the date changes what happens NEXT. */
ck('ngv manage: …and charges already posted are untouched by the correction',
   (int) NgvLedger::balance(501)['charged'] === $chargedBefore);
ck('ngv manage: a date in the future is refused — nobody enrolled tomorrow',
   (function () { NgvMember::setAdmin(501, ['start_date' => '2099-01-01']);
                  return NgvMember::participant(501)['start_date'] === '2026-07-01'; })());
ck('ngv manage: so is anything that is not a date',
   (function () { NgvMember::setAdmin(501, ['start_date' => 'last tuesday']);
                  return NgvMember::participant(501)['start_date'] === '2026-07-01'; })());

/* ══ The name and email snapshot ═══════════════════════════════════════════ */

$mgReset();
NgvMember::ensureParticipant(502, ['name' => 'Bode Ade', 'email' => 'old@example.test']);
NgvMember::ensureParticipant(502, ['name' => 'Bode Ade-Cole', 'email' => 'new@example.test']);
$p = NgvMember::participant(502);
/* The email here is where receipts go. A stale one is not a cosmetic problem —
   it is every letter delivered nowhere, silently. */
ck('ngv manage: a fresher name and email refresh the snapshot',
   $p['email'] === 'new@example.test' && $p['name'] === 'Bode Ade-Cole');
ck('ngv manage: an empty seed never wipes what is there',
   (function () { NgvMember::ensureParticipant(502, ['name' => '', 'email' => '']);
                  $q = NgvMember::participant(502);
                  return $q['email'] === 'new@example.test' && $q['name'] === 'Bode Ade-Cole'; })());
ck('ngv manage: and a repeat with the same values is a no-op',
   (function () { NgvMember::ensureParticipant(502, ['name' => 'Bode Ade-Cole', 'email' => 'new@example.test']);
                  return NgvMember::participant(502)['email'] === 'new@example.test'; })());

/* ══ Revoking a certificate ════════════════════════════════════════════════ */

$mgReset();
NgvMember::ensureParticipant(503, ['name' => 'Chidi Eze', 'email' => 'chidi@example.test']);
NgvMember::addCertification(503, ['title' => 'Data Analysis Level 1', 'issued_by' => 'Afrovanguard', 'issued_on' => '2026-08-01']);
$cert = NgvMember::certifications(503)[0];
$code = (string) $cert['code'];
ck('ngv manage: a fresh certificate verifies and is not revoked',
   NgvMember::certForVerify((int) $cert['id'], $code)['revoked'] === false);
ck('ngv manage: revoking needs a reason — the holder can see it',
   empty(NgvMember::revokeCertification((int) $cert['id'], '   ', 1)['ok']));
ck('ngv manage: a certificate can be withdrawn',
   !empty(NgvMember::revokeCertification((int) $cert['id'], 'Issued to the wrong person', 1)['ok']));
ck('ngv manage: and cannot be withdrawn twice',
   empty(NgvMember::revokeCertification((int) $cert['id'], 'again', 1)['ok']));

/* THE property. The link is public and may already be with an employer.
   Deleting the row would turn a real link into "not verified", which reads as a
   forgery — the truth is that it was issued and then withdrawn. */
$vr = NgvMember::certForVerify((int) $cert['id'], $code);
ck('ngv manage: the public link still verifies, and says it was withdrawn',
   $vr !== null && $vr['revoked'] === true && $vr['revokeReason'] === 'Issued to the wrong person');
ck('ngv manage: a revoked certificate leaves the holder\'s list',
   count(NgvMember::certifications(503)) === 0);
ck('ngv manage: …but staff can still see it — a withdrawn one is part of the record',
   count(NgvMember::certifications(503, true)) === 1);
ck('ngv manage: and it stops being counted as earned',
   (int) NgvMember::stats()['certs'] === 0
   && (int) NgvMember::roster('', 10)[0]['cert_count'] === 0);

/* Rendered for real: the failure mode is a withdrawn certificate that prints
   looking valid. */
$certRender = static function (int $id, string $c): string {
    $keep = $_GET; $_GET = ['id' => (string) $id, 'c' => $c];
    ob_start();
    try { require AV_ROOT . '/academy/ngv/certificate.php'; } catch (Throwable $e) {}
    $h = (string) ob_get_clean(); $_GET = $keep; return $h;
};
$html = $certRender((int) $cert['id'], $code);
ck('ngv manage: the withdrawn certificate page says so rather than showing a forgery notice',
   strpos($html, 'has been withdrawn') !== false
   && strpos($html, 'Issued to the wrong person') !== false
   && strpos($html, 'Verified certificate') === false
   && strpos($html, 'not verified') === false);
ck('ngv manage: it carries a watermark that survives being printed',
   strpos($html, 'WITHDRAWN') !== false && strpos($html, 'print-color-adjust:exact') !== false);
ck('ngv manage: a tampered link still reveals nothing',
   strpos($certRender((int) $cert['id'], 'NGV-0000000000'), 'not verified') !== false);

/* ══ The roster ════════════════════════════════════════════════════════════ */

$mgReset();
$mk = static function (int $id, string $name, string $status, string $cohort, string $track): void {
    NgvMember::ensureParticipant($id, ['name' => $name, 'email' => strtolower(str_replace(' ', '', $name)) . '@example.test']);
    NgvDb::pdo()->prepare('UPDATE ngv_participants SET status = ?, cohort = ?, track = ? WHERE member_id = ?')
        ->execute([$status, $cohort, $track, $id]);
};
$mk(510, 'Ada Obi', 'active', '2026 Alpha', 'TECHOME Africa');
$mk(511, 'Bode Ade', 'paused', '2026 Alpha', '');
$mk(512, 'Dami Ola', 'active', '2027 Beta', 'GATES');
$names = static fn(array $rs): array => array_map(static fn($x) => (string) $x['name'], $rs);

ck('ngv manage: an unfiltered roster is everybody', count(NgvMember::roster()) === 3);
ck('ngv manage: by status', $names(NgvMember::roster('paused')) === ['Bode Ade']);
ck('ngv manage: by cohort', count(NgvMember::roster('', 200, '', '2026 Alpha')) === 2);
ck('ngv manage: by name', $names(NgvMember::roster('', 200, 'ola')) === ['Dami Ola']);
ck('ngv manage: by email', count(NgvMember::roster('', 200, 'bodeade@')) === 1);
ck('ngv manage: by track — the thing a track lead actually searches',
   $names(NgvMember::roster('', 200, 'GATES')) === ['Dami Ola']);
ck('ngv manage: filters combine', count(NgvMember::roster('active', 200, '', '2026 Alpha')) === 1);
/* A wildcard typed into a search box is a character somebody typed, not an
   operator they meant. `A%e` discriminates: stripped it becomes `Ae`, which
   matches nobody here; left as an operator `%A%e%` would match "Bode Ade". */
ck('ngv manage: a wildcard in the search box is a character, not an operator',
   count(NgvMember::roster('', 200, 'A%e')) === 0
   && count(NgvMember::roster('', 200, 'Ade')) === 1);
ck('ngv manage: nothing matching returns nothing, not everything',
   NgvMember::roster('', 200, 'zzzznobody') === []);
ck('ngv manage: the count matches the filter, so the console can say "50 of 214"',
   NgvMember::rosterCount('paused') === 1 && NgvMember::rosterCount() === 3);
ck('ngv manage: cohorts are read from the data, not from a list to maintain',
   NgvMember::cohorts() === ['2026 Alpha', '2027 Beta']);

/* ══ Applications ══════════════════════════════════════════════════════════ */

/* `reviewing` and `accepted` existed in the vocabulary with no way to reach
   them, so every application sat at `new` until somebody enrolled or rejected
   it — and a queue with one state cannot show who has already been looked at. */
$consoleSrc = (string) @file_get_contents(AV_ROOT . '/academy/ngv/members.php');
$reachable = [];
if (preg_match_all('/data-status="([a-z_]+)"/', $consoleSrc, $mm)) $reachable = array_unique($mm[1]);
foreach (['reviewing', 'accepted', 'rejected'] as $st) {
    ck('ngv manage: the application status "' . $st . '" is reachable from the console',
       in_array($st, $reachable, true));
}
ck('ngv manage: every application status the domain accepts is one the console can set or reach',
   array_values(array_diff(['new', 'reviewing', 'accepted', 'rejected', 'enrolled'],
       array_merge($reachable, ['new', 'enrolled']))) === []);

$mgReset();
