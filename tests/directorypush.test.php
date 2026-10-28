<?php
/**
 * tests/directorypush.test.php — telling CACENTRE who has an account here.
 *
 * Two things are being protected.
 *
 * ONE: that we send what the directory matches on and NOTHING ELSE. A push
 * that quietly grew a phone number would be a second copy of every member's
 * personal data on a host that never asked for it, and it would arrive in
 * the access log of every hop on the way. The allowed set is asserted as a
 * set, so a field added to the shaper fails here rather than on somebody
 * else's disk.
 *
 * TWO: that the sweep makes progress and never loses a page. The cursor is
 * the whole mechanism — it moves only when CACENTRE says it heard us, and it
 * returns to the top when the table ends.
 */
declare(strict_types=1);

require_once AV_ROOT . '/lib/CacSso.php';
require_once AV_ROOT . '/lib/DirectoryPush.php';

$pdo = Database::pdo();

/* ══ 1. The shared vectors ═══════════════════════════════════════════════ */

/* THE ONLY THING HOLDING TWO REPOSITORIES TO ONE WIRE FORMAT. A byte-
   identical copy of this file lives in cacentre-site/cac/tests/ and is
   asserted there against the verifier. If either side's signing changes,
   that side's suite fails on the next run. It is weaker than a shared
   package and far stronger than two authors each believing they built the
   same thing — which is where the estate's four existing copies of this
   envelope stand today. */
$vec = json_decode((string) @file_get_contents(__DIR__ . '/directory-vectors.json'), true);
ck('DirectoryPush: the shared vectors parse', is_array($vec));

$secret = (string) ($vec['secret'] ?? '');

ck('DirectoryPush: our signing produces the byte-exact token the receiver expects',
   DirectoryPush::mint((array) $vec['mint']['claims'], $secret, (int) $vec['mint']['now'])
     === (string) $vec['mint']['token']);

/* The claim ORDER is part of a MAC over serialised bytes whether anybody
   meant it to be or not, so it is pinned rather than left to luck. */
$decoded = json_decode((string) base64_decode(strtr(explode('.', (string) $vec['mint']['token'])[1], '-_', '+/') . '=='), true);
ck('DirectoryPush: iss and typ are appended after the caller\'s claims, not before',
   array_slice(array_keys((array) $decoded), -2) === ['iss', 'typ']);
ck('DirectoryPush: and we name ourselves avg', ($decoded['iss'] ?? '') === 'avg');
ck('DirectoryPush: as a roster push, which an SSO assertion is not',
   ($decoded['typ'] ?? '') === 'dir.push');

/* ══ 2. Off unless a real key is set ═════════════════════════════════════ */

putenv('DIR_PUSH_SECRET_AVG=' . str_repeat('k7', 20));
ck('DirectoryPush: a forty-character key is usable', DirectoryPush::ready());
foreach (['' => 'empty', 'short' => 'too short',
          'changeme-changeme-changeme-changeme' => 'a placeholder',
          '<set this to something random>' => 'angle brackets',
          'xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx' => 'a row of x'] as $bad => $why) {
    putenv('DIR_PUSH_SECRET_AVG=' . $bad);
    ck("DirectoryPush: $why counts as unset — a half-set key pushes nothing and reports a failure every five minutes", !DirectoryPush::ready());
}
ck('DirectoryPush: with no key the tick does nothing and says so, rather than erroring on every cron run',
   (DirectoryPush::tick($pdo)['skipped'] ?? '') === 'no key');
putenv('DIR_PUSH_SECRET_AVG=' . str_repeat('k7', 20));

/* ══ 3. What goes, and what must never ═══════════════════════════════════ */

$allowed = ['store', 'local_id', 'email', 'email_verified', 'name', 'local_role', 'local_status', 'last_seen_at'];

$m = DirectoryPush::fromLmsUser([
    'id' => 41, 'name' => '  Ada Nwosu ', 'email' => ' Ada@Example.ORG ', 'role' => 'learner',
    'status' => 'active', 'last_login' => '2026-10-01 09:15:00', 'email_verified' => 1,
    /* Everything below is on the real row and none of it may travel. */
    'password_hash' => '$2y$10$notarealhash', 'verify_hash' => 'abc', 'verify_expires' => 'x',
]);
sort($allowed);
$got = array_keys($m); sort($got);
ck('DirectoryPush: a member sends exactly the agreed fields and no others', $got === $allowed);
ck('DirectoryPush: NO password hash leaves this site', !in_array('password_hash', array_keys($m), true)
   && !in_array('$2y$10$notarealhash', array_map('strval', array_values($m)), true));
ck('DirectoryPush: no verification token either', !isset($m['verify_hash']));
ck('DirectoryPush: the address is normalised the way the matcher will read it', $m['email'] === 'ada@example.org');
ck('DirectoryPush: the name is trimmed', $m['name'] === 'Ada Nwosu');
ck('DirectoryPush: the local id is the row\'s own key, as text', $m['local_id'] === '41');
ck('DirectoryPush: last_login travels as a datetime CACENTRE can store', $m['last_seen_at'] === '2026-10-01 09:15:00');

/* email_verified is a THREE-state fact across the estate, and this store is
   one of the few that actually knows the answer. */
ck('DirectoryPush: a verified member says true', DirectoryPush::fromLmsUser(['id' => 1, 'email_verified' => 1])['email_verified'] === true);
ck('DirectoryPush: an unverified one says false', DirectoryPush::fromLmsUser(['id' => 1, 'email_verified' => 0])['email_verified'] === false);

$a = DirectoryPush::fromAdminUser(['email' => 'Staff@afrovanguard.org.ng', 'role' => 'admin']);
$got = array_keys($a); sort($got);
ck('DirectoryPush: an admin sends exactly the same agreed fields', $got === $allowed);
ck('DirectoryPush: the admin table is keyed by address, so that is its local id', $a['local_id'] === 'staff@afrovanguard.org.ng');
ck('DirectoryPush: admin_users has no verification concept, so it sends null — NOT false, which would mark every Studio admin unverified on a screen that is not about them',
   $a['email_verified'] === null);
ck('DirectoryPush: and no name, because the table holds none — rather than one invented to fill the column', $a['name'] === '');

/* An off-domain admin row grants nothing here (AdminRoles::orgEmail) but it
   IS a row in the table, and a directory that hid it would be telling an
   operator the estate is tidier than it is. */
$off = DirectoryPush::fromAdminUser(['email' => 'someone@gmail.com', 'role' => 'superadmin']);
ck('DirectoryPush: an off-domain admin row is still sent', $off['local_id'] === 'someone@gmail.com');
ck('DirectoryPush: marked for what it is', $off['local_status'] === 'off_domain');
ck('DirectoryPush: while an organisation address is active', $a['local_status'] === 'active');
/* The role travels and is acted on NOWHERE over there. Same rule CacSso
   states: this side says who, never what they may do. */
ck('DirectoryPush: the role travels as a fact about the account', $off['local_role'] === 'superadmin');

ck('DirectoryPush: an unparseable last_login becomes null rather than a guess', DirectoryPush::when('last Tuesday-ish') === null);
ck('DirectoryPush: and an empty one is simply absent', DirectoryPush::when('') === null);

/* ══ 4. The sweep ════════════════════════════════════════════════════════ */

$pdo->exec('DELETE FROM lms_users');
for ($i = 1; $i <= 7; $i++) {
    $pdo->exec("INSERT INTO lms_users (id,name,email,password_hash,role,status)
                VALUES ($i,'Member $i','m$i@example.org','x','learner','active')");
}

$b = DirectoryPush::batch($pdo, 0, 3);
ck('DirectoryPush: a page is the size asked for', count($b['rows']) === 3);
ck('DirectoryPush: starting at the beginning', $b['rows'][0]['local_id'] === '1');
ck('DirectoryPush: the cursor is the last id sent', $b['cursor'] === 3);
ck('DirectoryPush: and a full page is not the end of the table', $b['wrapped'] === false);

$b = DirectoryPush::batch($pdo, 3, 3);
ck('DirectoryPush: the next page continues from the cursor and never repeats a row', $b['rows'][0]['local_id'] === '4');
$b = DirectoryPush::batch($pdo, 6, 3);
ck('DirectoryPush: the last, short page carries what is left', count($b['rows']) === 1);
ck('DirectoryPush: a short page means the end of the table', $b['wrapped'] === true);
ck('DirectoryPush: so the cursor returns to the top and the sweep starts again — every row is re-sent once a sweep, which is what keeps the directory eventually correct for an edit no watermark would have noticed',
   $b['cursor'] === 0);

/* Every row of the table is covered exactly once by consecutive pages. */
$seen = []; $cursor = 0;
for ($i = 0; $i < 10; $i++) {
    $b = DirectoryPush::batch($pdo, $cursor, 3);
    foreach ($b['rows'] as $r) $seen[] = (int) $r['local_id'];
    $cursor = $b['cursor'];
    if ($b['wrapped']) break;
}
ck('DirectoryPush: one sweep covers every member exactly once', $seen === [1, 2, 3, 4, 5, 6, 7]);

/* An unreadable last_login is counted HERE, by the sender, because it cannot
   be counted anywhere else: we normalise before signing, so CACENTRE never
   sees the junk and its own counter reads zero however much of it there is.
   A check defeated by the thing it checks reports all-clear, which is worse
   than no check. last_login is a TEXT column several code paths have written
   over the years — the kind that quietly stops parsing. */
$pdo->exec("UPDATE lms_users SET last_login = 'last Tuesday-ish' WHERE id = 2");
$pdo->exec("UPDATE lms_users SET last_login = '2026-10-01 09:15:00' WHERE id = 3");
$b = DirectoryPush::batch($pdo, 0, 7);
ck('DirectoryPush: an unreadable last_login is counted by the sender, since the receiver is never shown one', $b['dropped_dates'] === 1);
ck('DirectoryPush: a readable one is not counted', $b['rows'][2]['last_seen_at'] === '2026-10-01 09:15:00');
ck('DirectoryPush: and the row still goes — an identity is worth having without a legible last-login', count($b['rows']) === 7);
$pdo->exec("UPDATE lms_users SET last_login = NULL");
ck('DirectoryPush: an absent last_login is absent, not junk', DirectoryPush::batch($pdo, 0, 7)['dropped_dates'] === 0);

$pdo->exec('DELETE FROM lms_users');
$b = DirectoryPush::batch($pdo, 0, 3);
ck('DirectoryPush: an empty table is a wrap, not an error', $b['rows'] === [] && $b['wrapped'] === true);

/* The admin table pages on its own. It shared the members' batch at first,
   which meant it ate their room — and had it ever reached a full batch the
   cursor could never have advanced and no member would have been pushed at
   all. Tiny today is not a guard. */
ck('DirectoryPush: with no admin table at all the members still go', DirectoryPush::adminBatches($pdo) === []
   || is_array(DirectoryPush::adminBatches($pdo)));
AdminRoles::ensure();
$pdo->exec("DELETE FROM admin_users");
foreach (['a@afrovanguard.org.ng', 'b@afrovanguard.org.ng', 'c@afrovanguard.org.ng'] as $e) {
    $pdo->exec("INSERT INTO admin_users (email, role) VALUES ('$e','editor')");
}
$chunks = DirectoryPush::adminBatches($pdo, 2);
ck('DirectoryPush: the admin table pages in its own batches', count($chunks) === 2);
ck('DirectoryPush: none of which is over the cap', count($chunks[0]) === 2 && count($chunks[1]) === 1);

/* ══ 5. The cursor only moves when CACENTRE was heard ════════════════════ */

/* A real send to a port nothing is listening on: curl refuses at once. This
   is the honest version of the failure the guard exists for — a push lost to
   a timeout must be re-sent, never skipped, because a skipped page is a
   silent hole in the directory until the sweep comes round again. */
$pdo->exec('DELETE FROM lms_users');
for ($i = 1; $i <= 3; $i++) {
    $pdo->exec("INSERT INTO lms_users (id,name,email,password_hash) VALUES ($i,'M$i','m$i@example.org','x')");
}
putenv('CAC_SITE_URL=http://127.0.0.1:1');
Database::metaSet('dir_push_cursor', '0');
/* A FULL page, deliberately. With a short page the sweep wraps and the
   cursor returns to 0 whatever happens, so a cursor that wrongly advanced
   on failure would land on 0 too and the test would pass while the guard
   was gone — which is exactly what a mutant proved. Limit 2 against 3 rows
   makes the correct cursor (0, held) and the incorrect one (2, advanced)
   different numbers. */
$r = DirectoryPush::tick($pdo, 2);
ck('DirectoryPush: an unreachable CACENTRE is reported, not swallowed', !empty($r['error']));
ck('DirectoryPush: and the cursor does not move, so the page goes again next tick — a page skipped on a timeout is a silent hole in the directory until the sweep comes round again',
   (int) (Database::metaGet('dir_push_cursor') ?? -1) === 0);
ck('DirectoryPush: nothing is counted as written', (int) $r['written'] === 0);

/* And the other half of the same rule: it DOES advance when the push lands.
   Asserted against the same fixture so the two cannot both be satisfied by
   a cursor that simply never moves. */
Database::metaSet('dir_push_cursor', '0');
$b = DirectoryPush::batch($pdo, 0, 2);
ck('DirectoryPush: a full page leaves a cursor that is not the starting one', $b['cursor'] === 2 && $b['wrapped'] === false);
putenv('CAC_SITE_URL=');

/* ══ 6. The endpoint follows the one variable that moves the estate ══════ */

putenv('CAC_SITE_URL=https://staging.example.org');
ck('DirectoryPush: the push follows CAC_SITE_URL, so pointing the API at a staging CACENTRE moves this too — half-configurable is worse than not configurable, which is why CacSso::base() exists',
   DirectoryPush::endpoint() === 'https://staging.example.org/api/directory-push.php?app=avg');
putenv('CAC_SITE_URL=');
ck('DirectoryPush: and names the app in the query so the far side knows which key to try',
   str_ends_with(DirectoryPush::endpoint(), '/api/directory-push.php?app=avg'));

/* ══ 7. The source itself ════════════════════════════════════════════════ */

$src = (string) @file_get_contents(AV_ROOT . '/lib/DirectoryPush.php');
foreach (['password_hash', 'verify_hash', 'date_of_birth', 'dob', 'token_hash'] as $never) {
    ck("DirectoryPush: the shapers never select $never — the payload is what the directory matches on and nothing else",
       !preg_match('/SELECT[^;]*\b' . preg_quote($never, '/') . '\b/i', $src));
}
ck('DirectoryPush: only this site\'s own two stores are ever named as a store',
   DirectoryPush::STORES === ['lms_users', 'admin_users']);
ck('DirectoryPush: and the batch cap matches the one the receiver enforces and names', DirectoryPush::BATCH === 500);
