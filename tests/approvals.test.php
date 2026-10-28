<?php
/**
 * tests/approvals.test.php — an admin's sensitive member acts wait for a
 * second person (lib/Approvals.php), as NGG's club admin's do.
 *
 *   • Suspending, singly or in a crowd, and ending dues in a crowd, are kept —
 *     not refused, not run — when an admin asks. A Super Admin's run at once.
 *   • Nothing changes until somebody else approves; the requester never can.
 *   • Approved, it runs as the requester, so the desk's own checks still hold.
 *   • It runs once, however many press Approve.
 */
declare(strict_types=1);

$aPdo = Database::pdo();
MemberRoster::ensure();
Approvals::ensure();
$aPdo->exec('DELETE FROM av_approvals');
$aPdo->exec("DELETE FROM member_profiles WHERE user_id IN (SELECT id FROM lms_users WHERE email LIKE '%@approvals.test')");
$aPdo->exec("DELETE FROM lms_users WHERE email LIKE '%@approvals.test'");

$mk = static fn(string $n, string $e) => (int) MemberRoster::create(['name' => $n, 'email' => $e], 'tester')['id'];
$ada = $mk('Ada Okon', 'ada@approvals.test');
$ben = $mk('Ben Musa', 'ben@approvals.test');
$req = $mk('Rita Admin', 'rita@approvals.test');           // the admin who asks
$adminCtx = ['studio_role' => 'admin', 'self_id' => $req];
$superCtx = ['studio_role' => 'superadmin', 'self_id' => 0];
$status = static fn(int $id) => (string) MemberRoster::get($id)['status'];
/* How admin/api.php runs an op, out of the queue or straight from the screen. */
$run = static function (string $op, array $args, string $actor, array $ctx): array {
    if ($op === 'roster_bulk') return MemberRoster::bulk((array) $args['ids'], (string) $args['action'], (array) ($args['value'] ?? []), $actor, $ctx);
    return MemberRoster::update((int) $args['id'], array_diff_key($args, ['id' => 1]), $actor, $ctx);
};

/* ── what waits, and what does not ────────────────────────────────────── */
$bulkSuspend = ['ids' => [$ada, $ben, $req], 'action' => 'status', 'value' => ['status' => 'suspended']];
$held = Approvals::intercept('roster_bulk', $bulkSuspend, $adminCtx, 'rita@approvals.test');
ck('approvals: an admin suspending members in a crowd is kept for a second person', ($held['code'] ?? '') === 'approval_pending' && (int) $held['approval_id'] > 0);
ck('approvals: …and nothing has changed', $status($ada) === 'active' && $status($ben) === 'active');
ck('approvals: the request says what it would do, naming who — and not the requester, whose own record the desk refuses anyway',
    str_contains($held['summary'], 'Suspend 2 members') && str_contains($held['summary'], 'Ada Okon') && !str_contains($held['summary'], 'Rita'));
ck('approvals: a Super Admin’s suspension runs at once', Approvals::intercept('roster_bulk', $bulkSuspend, $superCtx, 'boss@approvals.test') === null);
ck('approvals: reactivating needs nobody', Approvals::intercept('roster_bulk', ['ids' => [$ada], 'action' => 'status', 'value' => ['status' => 'active']], $adminCtx, 'rita@approvals.test') === null);
ck('approvals: an ordinary edit needs nobody', Approvals::intercept('roster_update', ['id' => $ada, 'phone' => '0803'], $adminCtx, 'rita@approvals.test') === null);
ck('approvals: asking to suspend only yourself is not queued — the desk refuses it with its own reason',
    Approvals::intercept('roster_bulk', ['ids' => [$req], 'action' => 'status', 'value' => ['status' => 'suspended']], $adminCtx, 'rita@approvals.test') === null);
$one = Approvals::intercept('roster_update', ['id' => $ben, 'status' => 'suspended'], $adminCtx, 'rita@approvals.test');
ck('approvals: suspending one member from their record waits too', ($one['code'] ?? '') === 'approval_pending' && str_contains($one['summary'], 'Ben Musa'));
$dues = Approvals::intercept('roster_bulk', ['ids' => [$ada, $ben], 'action' => 'membership_cancel', 'value' => ['note' => 'Left the city']], $adminCtx, 'rita@approvals.test');
ck('approvals: ending dues in a crowd waits, with the reason given', ($dues['code'] ?? '') === 'approval_pending' && str_contains($dues['summary'], 'Left the city'));
ck('approvals: the queue counts what is waiting', Approvals::pendingCount() === 3);

/* ── deciding ──────────────────────────────────────────────────────────── */
$own = Approvals::decide((int) $held['approval_id'], 'approve', '', 'rita@approvals.test', $run);
ck('approvals: the requester cannot approve their own request', !$own['ok'] && $own['code'] === 'own_request' && $status($ada) === 'active');
ck('approvals: …and the queue does not offer it to them', !array_filter(Approvals::list('pending', 'rita@approvals.test')['approvals'], fn($a) => $a['can_decide']));
ck('approvals: a reject needs a reason', !Approvals::decide((int) $one['approval_id'], 'reject', '  ', 'boss@approvals.test', $run)['ok']);
$rej = Approvals::decide((int) $one['approval_id'], 'reject', 'Talk to him first', 'boss@approvals.test', $run);
ck('approvals: rejected, nothing happens and the reason is kept', $rej['ok'] && $rej['status'] === 'rejected' && $status($ben) === 'active'
    && Approvals::list('rejected', 'x')['approvals'][0]['reason'] === 'Talk to him first');

$ok = Approvals::decide((int) $held['approval_id'], 'approve', '', 'boss@approvals.test', $run);
ck('approvals: approved by somebody else, it runs as it was asked', $ok['ok'] && $ok['status'] === 'approved' && $status($ada) === 'suspended' && $status($ben) === 'suspended');
$res = $ok['result'];
$mine = array_values(array_filter($res['results'] ?? [], fn($x) => $x['id'] === $req))[0] ?? [];
ck('approvals: …as the requester — their own record is still refused by the desk', ($mine['code'] ?? '') === 'own_account' && $status($req) === 'active');
ck('approvals: the outcome is kept on the request', str_contains(Approvals::list('approved', 'x')['approvals'][0]['outcome'], '2 changed'));
$again = Approvals::decide((int) $held['approval_id'], 'approve', '', 'other@approvals.test', $run);
ck('approvals: it runs once — a second approval is refused', !$again['ok'] && $again['code'] === 'already_decided');

/* ── withdraw, expire ──────────────────────────────────────────────────── */
ck('approvals: only the requester withdraws', !Approvals::withdraw((int) $dues['approval_id'], 'boss@approvals.test')['ok']);
ck('approvals: the requester withdraws a request nobody has decided', Approvals::withdraw((int) $dues['approval_id'], 'rita@approvals.test')['ok']
    && Approvals::pendingCount() === 0);
$old = Approvals::intercept('roster_update', ['id' => $ada, 'status' => 'suspended'], $adminCtx, 'rita@approvals.test');
ck('approvals: (a member already suspended is not queued again)', $old === null);
MemberRoster::update($ada, ['status' => 'active'], 'tester');
$old = Approvals::intercept('roster_update', ['id' => $ada, 'status' => 'suspended'], $adminCtx, 'rita@approvals.test');
$aPdo->prepare('UPDATE av_approvals SET created_at = ? WHERE id = ?')->execute([gmdate('Y-m-d H:i:s', time() - (Approvals::EXPIRE_DAYS + 1) * 86400), (int) $old['approval_id']]);
ck('approvals: a request nobody decided in ' . Approvals::EXPIRE_DAYS . ' days expires', Approvals::list('expired', 'x')['approvals'][0]['id'] === (int) $old['approval_id']
    && !Approvals::decide((int) $old['approval_id'], 'approve', '', 'boss@approvals.test', $run)['ok'] && $status($ada) === 'active');

/* ── the seam and the screen ──────────────────────────────────────────── */
$api = (string) file_get_contents(dirname(__DIR__) . '/admin/api.php');
$seam = strpos($api, 'Approvals::intercept(');
ck('approvals: the queue is asked after the permission checks and before any handler', $seam !== false
    && $seam > strpos($api, "in_array(\$action, \$managementOnly, true)") && $seam < strpos($api, 'switch ($action)'));
ck('approvals: both member writes go through one runner, so the queue and the screen run them the same way',
    str_contains($api, "\$runMemberOp('roster_update'") && str_contains($api, "\$runMemberOp('roster_bulk'") && str_contains($api, '$runMemberOp);'));
ck('approvals: deciding is management-only and a POST', (bool) preg_match("~'approvals_list', 'approval_decide', 'approval_withdraw'~", $api)
    && (bool) preg_match("~case 'approval_decide':\\s*\\n\\s*if \\(\\\$method !== 'POST'\\)~", $api));
$ui = (string) file_get_contents(dirname(__DIR__) . '/admin/index.php');
$js = (string) file_get_contents(dirname(__DIR__) . '/admin/app.js');
ck('approvals: Members has an Approvals section with a waiting count', str_contains($ui, 'data-mv="approvals"') && str_contains($ui, 'id="apprBadge"'));
ck('approvals: the bulk bar, the row menu and the record say "sent for approval", never "done"', substr_count($js, "approval_pending") >= 2 && str_contains($js, 'Sent for approval'));
$pane = substr($ui, (int) strpos($ui, 'data-pane="approvals"'), 1500);
ck('approvals: the Approvals section carries no inline style', !str_contains($pane, 'style='));

$aPdo->exec('DELETE FROM av_approvals');
$aPdo->exec("DELETE FROM member_profiles WHERE user_id IN (SELECT id FROM lms_users WHERE email LIKE '%@approvals.test')");
$aPdo->exec("DELETE FROM lms_users WHERE email LIKE '%@approvals.test'");
