<?php
/**
 * portal/directory.php — JSON API for the member directory + profile cards.
 *
 *   GET  ?action=card&id=N        → { ok, card }
 *   GET  ?action=list&q=          → { ok, members:[…] }
 *   POST ?action=set_skills {skills} → { ok, card }
 *
 * Org members only; the write is same-origin + CSRF + rate-limited.
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_once AV_ROOT . '/lib/MemberDirectory.php';

$u = LmsAuth::user();
if (!$u) json_out(['ok' => false, 'error' => 'Please sign in.'], 401);
if (!LmsAuth::isOrgMember($u)) json_out(['ok' => false, 'error' => 'The directory is for Afrovanguard members.'], 403);
$uid = (int) $u['id'];

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = (string) ($_GET['action'] ?? 'list');
$body = [];
if ($method === 'POST') { $body = json_decode(file_get_contents('php://input') ?: '', true) ?: $_POST; }

try {
    switch ($action) {
        case 'card':
            $card = MemberDirectory::card($uid, (int) ($_GET['id'] ?? 0));
            if (!$card) json_out(['ok' => false, 'error' => 'Member not found.'], 404);
            json_out(['ok' => true, 'card' => $card]);

        case 'list':
            json_out(['ok' => true, 'members' => MemberDirectory::listMembers($uid, (string) ($_GET['q'] ?? ''), 200)]);

        case 'set_skills':
            if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required.'], 405);
            av_require_write($uid, 'directory', 30);
            MemberDirectory::setSkills($uid, (string) ($body['skills'] ?? ''));
            json_out(['ok' => true, 'card' => MemberDirectory::card($uid, $uid)]);

        default:
            json_out(['ok' => false, 'error' => 'Unknown action.'], 400);
    }
} catch (Throwable $e) {
    error_log('[directory] ' . $e->getMessage());
    json_out(['ok' => false, 'error' => av_is_prod() ? 'Server error.' : ('Server error: ' . $e->getMessage())], 500);
}
