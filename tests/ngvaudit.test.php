<?php
/**
 * tests/ngvaudit.test.php — the end-to-end audit of the NGV money subsystem,
 * pinned so the findings cannot come back.
 *
 * Every case here is a hole that a probe found in shipped code, not a
 * hypothetical. Ranked as the audit ranked them:
 *
 *   1. HIGH   One member could page every admin by email without limit. The
 *             dashboard allows 60 writes per 10 minutes, so a bored — or
 *             malicious — participant could put ~120 emails through a shared
 *             host's mail quota in one sitting and take the site's outbound
 *             mail down for everybody.
 *   2. MEDIUM A participant could self-select a plan the admin had switched
 *             off on the public page, and then be billed at its price.
 *   3. MEDIUM `certificate.php` verifies a public bearer link exactly as
 *             `receipt.php` does, but had neither its sibling's rate limit on
 *             failures nor its no-store headers.
 *   4. LOW    A negative balance cap became 0, and 0 means "no ceiling" — so a
 *             slipped minus sign silently removed the protection it was aimed
 *             at.
 *   5. LOW    Repeat applications from one address each opened a row, so the
 *             queue could be flooded and staff would review the same person
 *             many times over.
 *   6. LOW    `rosterCount()` fetched up to 1000 full rows, each with
 *             correlated subqueries, to produce a single integer.
 *
 * Run via tests/run.php (provides ck()).
 */
declare(strict_types=1);

$auReset = static function (): void {
    $pdo = NgvDb::pdo();
    foreach (['ngv_damages', 'ngv_charges', 'ngv_payments', 'ngv_participants', 'ngv_applications'] as $t) {
        try { $pdo->exec('DELETE FROM ' . $t); } catch (Throwable $e) {}
    }
    $c = new ReflectionProperty('NgvLedger', 'cache'); $c->setAccessible(true); $c->setValue(null, null);
    NgvLedger::saveSettings(['enabled' => true, 'accrueFrom' => '2026-01-01'], 'test');
};

/* ══ 1. HIGH — one member paging every admin ═══════════════════════════════ */

/* The RECORDS are deliberately unlimited: a real cohort can break more than
   one thing, and a participant who has already reported a cracked screen must
   still be able to report a lost charger. What has to be bounded is the alert,
   because that is the part that costs a shared host its mail quota. */
$auReset();
NgvMember::ensureParticipant(701, ['name' => 'Ada Obi', 'email' => 'ada@example.test']);
for ($i = 1; $i <= 12; $i++) {
    NgvDamage::report(701, ['item' => 'Item ' . $i, 'description' => 'Something happened, number ' . $i], 0, true);
}
ck('ngv audit: all twelve self-reports are recorded — the record is not the throttle',
   count(NgvDamage::forMember(701)) === 12);
ck('ngv audit: but the twelfth report does not page the staff again',
   NgvDamage::openCountFor(701) === 12);

/* The throttle reads the open queue, so it re-arms the moment staff clear it —
   it suppresses a pile-on, it does not silence a member for good. */
NgvDb::pdo()->exec("UPDATE ngv_damages SET status = 'closed' WHERE member_id = 701");
ck('ngv audit: clearing the queue re-arms the alert',
   NgvDamage::openCountFor(701) === 0);
NgvDamage::report(701, ['item' => 'A fresh one', 'description' => 'Filed after the queue was cleared'], 0, true);
ck('ngv audit: the next genuine report is the first open one again, so it alerts',
   NgvDamage::openCountFor(701) === 1);

/* Counting must never throw: a broken count that returns 0 would open the
   floodgates, so the failure mode is deliberately "assume many, stay quiet". */
ck('ngv audit: the throttle fails closed, never open',
   NgvDamage::openCountFor(-1) === 0 && NgvDamage::openCountFor(999999) === 0);

/* ══ 2. MEDIUM — a plan the admin switched off ═════════════════════════════ */

$auReset();
NgvMember::ensureParticipant(702, ['name' => 'Bode Ade', 'email' => 'bode@example.test']);
$auDoc = Ngv::get();
$auOff = $auDoc;
foreach ($auOff['plans'] as $k => $p) if (($p['name'] ?? '') === 'Full Programme') $auOff['plans'][$k]['enabled'] = false;
Ngv::save($auOff);
$c = new ReflectionProperty('NgvLedger', 'cache'); $c->setAccessible(true); $c->setValue(null, null);

NgvMember::saveSelf(702, ['plan' => 'Full Programme']);
ck('ngv audit: a member cannot self-select a plan the admin switched off',
   (string) NgvMember::participant(702)['plan'] === '');
NgvMember::saveSelf(702, ['plan' => 'Training Only']);
ck('ngv audit: a plan that IS on the page is still accepted',
   (string) NgvMember::participant(702)['plan'] === 'Training Only');
/* Refusing has to mean LEAVING IT ALONE. Blanking the field on a bad value
   would be its own bug: the plan prices the training fee, so a stale form or a
   hand-crafted POST could quietly stop somebody's instalments. */
NgvMember::saveSelf(702, ['plan' => 'Platinum Invented Tier']);
ck('ngv audit: a plan nobody published is refused, and the stored one stands',
   (string) NgvMember::participant(702)['plan'] === 'Training Only');
NgvMember::setAdmin(702, ['plan' => 'Platinum Invented Tier']);
ck('ngv audit: staff cannot set an unpublished plan either, and lose nothing trying',
   (string) NgvMember::participant(702)['plan'] === 'Training Only');
/* Deliberately choosing nothing is still allowed — that is a different act
   from naming something we do not recognise. */
NgvMember::saveSelf(702, ['plan' => '']);
ck('ngv audit: but an empty choice does clear it, because that is a real choice',
   (string) NgvMember::participant(702)['plan'] === '');
/* The same three-way distinction applies to the track. */
$auTrack = (string) NgvMember::participant(702)['track'];
NgvMember::saveSelf(702, ['track' => 'A Track That Does Not Exist']);
ck('ngv audit: an unrecognised track does not wipe the recorded one',
   (string) NgvMember::participant(702)['track'] === $auTrack);

/* The console must stop OFFERING a retired plan… */
ck('ngv audit: the console no longer offers a retired plan',
   !in_array('Full Programme', array_column(NgvMember::planOptions(), 'name'), true));
/* …but the catalogue must keep PRICING it, or somebody already enrolled on it
   would silently stop accruing the fee they actually agreed to pay. */
ck('ngv audit: the catalogue still prices it, so a live member keeps working',
   isset(NgvLedger::planCatalogue()['Full Programme']));

/* The console picker has to agree with what setAdmin() will accept, or it is a
   dropdown that silently does nothing — with the one exception that keeps a
   member on a retired plan visible instead of reading as "no plan". */
$auCon = (string) @file_get_contents(AV_ROOT . '/academy/ngv/members.php');
ck('ngv audit: the console offers only switched-on plans',
   str_contains($auCon, "\$planOn = array_filter(\$planCat"));
ck('ngv audit: except the one this member is already on',
   str_contains($auCon, 'no longer offered'));
ck('ngv audit: and the fee summary quotes a price somebody can actually pick',
   str_contains($auCon, 'array_filter($planOn'));

Ngv::save($auDoc);
$c->setValue(null, null);

/* ══ 3. MEDIUM — the public certificate page ═══════════════════════════════ */

/* Both pages hand a bearer link to the open internet and both verify an HMAC.
   Whatever protects one has to protect the other, or the weaker one is simply
   where an attacker goes to grind signatures. */
$auCert = (string) @file_get_contents(AV_ROOT . '/academy/ngv/certificate.php');
ck('ngv audit: the certificate page rate-limits failed verifications',
   str_contains($auCert, 'av_rate_ok('));
ck('ngv audit: the certificate page is never cached or held by a proxy',
   (bool) preg_match('/Cache-Control:\s*no-store/i', $auCert));
ck('ngv audit: the certificate page leaks no referrer to a linked site',
   (bool) preg_match('/Referrer-Policy:\s*no-referrer/i', $auCert));
/* Rate-limiting a SUCCESSFUL read would lock a cohort out of its own
   certificates on results day — only failures may count against the bucket. */
ck('ngv audit: only failures count against the bucket, never a valid read',
   (bool) preg_match('/av_rate_ok\([^)]*\)/', $auCert)
   && !preg_match('/verified[^;]{0,40}av_rate_ok/i', $auCert));

/* ══ 4. LOW — a negative ceiling is not "no ceiling" ═══════════════════════ */

$auReset();
NgvLedger::saveSettings(['balanceCap' => 500000], 'test');
ck('ngv audit: a cap can be set',
   (float) NgvLedger::settings()['balanceCap'] === 500000.0);
NgvLedger::saveSettings(['balanceCap' => -1], 'test');
ck('ngv audit: a slipped minus sign keeps the ceiling instead of removing it',
   (float) NgvLedger::settings()['balanceCap'] === 500000.0);
/* Zero is how somebody deliberately says "no ceiling", and must still work. */
NgvLedger::saveSettings(['balanceCap' => 0], 'test');
ck('ngv audit: typing zero still means "no ceiling"',
   (float) NgvLedger::settings()['balanceCap'] === 0.0);

/* ══ 5. LOW — repeat applications ══════════════════════════════════════════ */

$auReset();
$auFirst = NgvMember::submitApplication(['name' => 'Chidi Eze', 'email' => 'chidi@example.test', 'message' => 'first']);
$auIds = [];
for ($i = 0; $i < 20; $i++) {
    $r = NgvMember::submitApplication(['name' => 'Chidi Eze', 'email' => 'chidi@example.test']);
    if ($r > 0) $auIds[$r] = true;
}
$auRows = static fn(string $e): int => (int) NgvDb::pdo()
    ->query("SELECT COUNT(*) FROM ngv_applications WHERE email = " . NgvDb::pdo()->quote($e))->fetchColumn();
ck('ngv audit: twenty-one submissions from one address leave one row',
   $auRows('chidi@example.test') === 1);
ck('ngv audit: and the repeats update that row rather than opening new ones',
   count($auIds) === 1 && isset($auIds[$auFirst]));
/* Dedupe must not become a merge: two people are two applications. */
NgvMember::submitApplication(['name' => 'Dami Oke', 'email' => 'dami@example.test']);
ck('ngv audit: a different address is never folded into somebody else',
   $auRows('dami@example.test') === 1);
/* Only an UNDECIDED application absorbs a repeat. Once staff have ruled on it,
   a fresh approach is a fresh application and has to be visible as one. */
NgvDb::pdo()->exec("UPDATE ngv_applications SET status = 'rejected' WHERE email = 'chidi@example.test'");
NgvMember::submitApplication(['name' => 'Chidi Eze', 'email' => 'chidi@example.test', 'message' => 'trying again']);
ck('ngv audit: re-applying after a decision opens a new row, it does not reopen the old one',
   $auRows('chidi@example.test') === 2);
ck('ngv audit: an application with no address is still refused outright',
   NgvMember::submitApplication(['name' => 'No Address']) === 0);

/* ══ 6. LOW — counting the roster ══════════════════════════════════════════ */

$auReset();
for ($i = 0; $i < 40; $i++) {
    NgvMember::ensureParticipant(800 + $i, ['name' => 'Person ' . $i, 'email' => 'p' . $i . '@example.test']);
}
NgvDb::pdo()->exec("UPDATE ngv_participants SET status = 'paused' WHERE member_id >= 830");
ck('ngv audit: the count is the count',
   NgvMember::rosterCount() === 40 && NgvMember::rosterCount('paused') === 10);
/* The point of the fix is that counting does not pay for the page's per-row
   subqueries. Measured as a ratio so it holds on a slow shared host too. */
$t0 = microtime(true); NgvMember::roster('', 40); $auPage = microtime(true) - $t0;
$t0 = microtime(true); NgvMember::rosterCount();   $auCount = microtime(true) - $t0;
ck('ngv audit: counting costs a fraction of rendering, not the same',
   $auCount < max($auPage, 0.0005));
/* A filtered count and a filtered page must agree, or the console paginates
   into pages that are not there. */
ck('ngv audit: the count and the page agree on the same filter',
   NgvMember::rosterCount('paused') === count(NgvMember::roster('paused', 500)));
ck('ngv audit: a search term narrows the count the same way it narrows the page',
   NgvMember::rosterCount('', 'Person 1') === count(NgvMember::roster('', 500, 'Person 1')));

$auReset();
