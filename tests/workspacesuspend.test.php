<?php
/**
 * tests/workspacesuspend.test.php — H8: a departed staff member keeps
 * everything.
 *
 * The nightly directory sync only ever ADDED people. A Workspace account
 * that was suspended — somebody's last day — was counted "skipped" and
 * left alone, so they kept their member account, whatever password they
 * had set, their gate pass, and any Studio role tied to their address.
 *
 * The sweep for accounts MISSING from the directory is the dangerous
 * half: directoryUsers() pages but stops at a cap, and a truncated list
 * is indistinguishable from a complete one. Concluding "absent, so
 * departed" from a partial page would suspend every active member past
 * the cap. Both halves are pinned here.
 *
 * Run via tests/run.php (provides ck()).
 */
declare(strict_types=1);

$pdo = Database::pdo();
MemberRoster::ensure();
$dom = strtolower((string) Config::get('AV_ORG_DOMAIN', 'afrovanguard.org.ng'));

$wsMk = static function (string $local) use ($pdo, $dom): array {
    $email = $local . '@' . $dom;
    $pdo->prepare('DELETE FROM lms_users WHERE email = ?')->execute([$email]);
    $pdo->prepare("INSERT INTO lms_users (name, email, password_hash, role, status, email_verified) VALUES (?,?,?,'member','active',1)")
        ->execute(['WS ' . $local, $email, password_hash('x', PASSWORD_BCRYPT)]);
    return ['id' => (int) $pdo->lastInsertId(), 'email' => $email];
};
$wsStatus = static fn(int $id): string => (string) $pdo->query('SELECT status FROM lms_users WHERE id = ' . $id)->fetchColumn();

/* ══ Workspace says suspended ══════════════════════════════════════════ */

$left = $wsMk('departed');
$stay = $wsMk('still.here');

/* A live session, so the test can show it is ended rather than merely
   "unusable while suspended" — alive again the moment anybody reactivates. */
$pdo->prepare('INSERT INTO lms_sessions (user_id, token_hash, expires_at) VALUES (?,?,?)')
    ->execute([$left['id'], hash('sha256', str_repeat('c', 40)), gmdate('Y-m-d H:i:s', time() + 3600)]);

$ref = new ReflectionMethod('GoogleWorkspace', 'suspendLocally');
$ref->setAccessible(true);
$n = $ref->invoke(null, $left['email'], 'suspended in Google Workspace');

ck('workspace: a suspended Workspace account is suspended here', $n === 1 && $wsStatus($left['id']) === 'suspended');
ck('workspace: …and their sessions are ended, not just ignored',
   (int) $pdo->query('SELECT COUNT(*) FROM lms_sessions WHERE user_id = ' . $left['id'])->fetchColumn() === 0);
ck('workspace: …and it is audited, so a departure is visible', (function () use ($pdo, $left) {
    $r = $pdo->prepare("SELECT detail FROM lms_audit WHERE action = 'account_suspended' AND target = ? ORDER BY id DESC LIMIT 1");
    $r->execute([$left['email']]);
    $d = (string) ($r->fetchColumn() ?: '');
    return str_contains($d, 'gate pass') && str_contains($d, 'Workspace');
})());
ck('workspace: somebody still in Workspace is untouched', $wsStatus($stay['id']) === 'active');

/* Idempotent: a sync running every night must not keep acting. */
ck('workspace: suspending an already-suspended account does nothing',
   $ref->invoke(null, $left['email'], 'again') === 0);

/* The accounts above are finished with. They are cleared BEFORE the sync
   section, because the sweep below acts on every active organisation
   address, and leaving them around makes the count assertion depend on a
   fixture three screens up. */
$pdo->prepare('DELETE FROM lms_sessions WHERE user_id IN (?, ?)')->execute([$left['id'], $stay['id']]);
$pdo->prepare('DELETE FROM lms_users WHERE id IN (?, ?)')->execute([$left['id'], $stay['id']]);

/* ══ The SYNC itself, not only the piece under it ══════════════════════

   The first version of this file reached past syncDirectoryUsers() and
   called the suspend helper by reflection. Mutation testing showed what
   that was worth: putting the old `skipped++` back at the call site
   broke nothing — the original defect, returning in silence. The sync is
   driven for real now, against a stand-in directory. */

$gone = $wsMk('gone.from.workspace');
$shut = $wsMk('shut.down');
$here = $wsMk('present');

$dirRow = static fn(string $email, bool $suspended = false): array =>
    ['name' => 'Dir ' . $email, 'email' => $email, 'photo' => '', 'is_admin' => false, 'suspended' => $suspended];

/* A COMPLETE listing: present is there, shut.down is there but suspended,
   gone.from.workspace is not there at all. */
GoogleWorkspace::useDirectoryFetcher(static fn(int $max): array => [[
    $dirRow($here['email']),
    $dirRow($shut['email'], true),
], true]);

$r = GoogleWorkspace::syncDirectoryUsers();

ck('workspace sync: it suspends the account Workspace suspended',
   $wsStatus($shut['id']) === 'suspended');
ck('workspace sync: …and the one that has vanished from the directory',
   $wsStatus($gone['id']) === 'suspended');
ck('workspace sync: …and leaves everybody still there alone',
   $wsStatus($here['id']) === 'active');
ck('workspace sync: …and says how many it closed', ($r['suspended'] ?? 0) === 2 && empty($r['absent_not_swept']));

/* A TRUNCATED listing: the same absence, and it must conclude nothing. */
$back = $wsMk('back.again');
GoogleWorkspace::useDirectoryFetcher(static fn(int $max): array => [[$dirRow($here['email'])], false]);

$r2 = GoogleWorkspace::syncDirectoryUsers();

ck('workspace sync: a truncated listing suspends nobody for being absent',
   $wsStatus($back['id']) === 'active' && ($r2['suspended'] ?? 0) === 0);
ck('workspace sync: …and says so, rather than going quiet',
   ($r2['absent_not_swept'] ?? 0) >= 1 && ($r2['directory_complete'] ?? true) === false);

GoogleWorkspace::useDirectoryFetcher(null);
$pdo->prepare('DELETE FROM lms_users WHERE id IN (?, ?, ?, ?)')
    ->execute([$gone['id'], $shut['id'], $here['id'], $back['id']]);

/* ══ The sweep is the dangerous half ═══════════════════════════════════ */

$src = (string) file_get_contents(AV_ROOT . '/lib/GoogleWorkspace.php');

ck('workspace: absence is only acted on when the listing is COMPLETE',
   str_contains($src, 'if (!$complete) { $sum[\'absent_not_swept\']++; continue; }'));
ck('workspace: …and completeness means the paging actually ran out',
   str_contains($src, 'self::$lastListComplete = ($page === \'\');'));
ck('workspace: …which starts false, so an error cannot read as "nobody is in the directory"',
   preg_match('~private static bool \$lastListComplete = false;~', $src) === 1
   && str_contains($src, 'self::$lastListComplete = false;'));
ck('workspace: a partial listing is REPORTED rather than silently skipped',
   str_contains($src, "'absent_not_swept' => 0") && str_contains($src, "'directory_complete' => \$complete"));

/* Only the organisation's own addresses: a member on gmail is not in
   Workspace and absence says nothing about them. */
ck('workspace: the sweep only ever looks at organisation addresses',
   preg_match("~SELECT email FROM lms_users WHERE status = 'active' AND LOWER\\(email\\) LIKE~", $src) === 1);

/* Through the one path that also withdraws the pass. */
ck('workspace: suspension goes through MemberRoster::update, not a bare UPDATE',
   str_contains($src, "MemberRoster::update(\$id, ['status' => 'suspended']")
   && !preg_match("~UPDATE lms_users SET status = 'suspended'~", $src));

$pdo->exec("DELETE FROM lms_audit WHERE action = 'account_suspended'");
