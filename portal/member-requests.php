<?php
/**
 * portal/member-requests.php — a member's requests for a change to their record.
 *
 *   GET                    → this member's requests and where each stands.
 *   POST ?action=create    → kind (centre | details | pause | other) and what goes with it.
 *   POST ?action=withdraw  → id; only one still waiting.
 *
 * Nothing here changes the record. An admin decides in the Studio → Members →
 * Approvals (lib/MemberRequests.php).
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$u = LmsAuth::user();
if (!$u) json_out(['ok' => false, 'error' => 'Please sign in.'], 401);
$uid = (int) $u['id'];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    json_out(['ok' => true, 'requests' => MemberRequests::mine($uid)]);
}

/* A request costs an admin's attention: a few an hour is plenty. */
av_require_write($uid, 'member_request', 10, 3600);
$body = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($body)) $body = $_POST;

$action = (string) ($_GET['action'] ?? 'create');
$r = $action === 'withdraw' ? MemberRequests::withdraw($uid, (int) ($body['id'] ?? 0)) : MemberRequests::submit($uid, $body);
json_out($r, ($r['ok'] ?? false) ? 200 : 422);
