<?php
/**
 * portal/goals.php — JSON API for team goals / OKRs (org members only).
 *
 *   GET  ?action=list                       → { ok, goals:[…] }
 *   POST ?action=create   {title, target}   → { ok, id }
 *   POST ?action=progress {id, pct}         → { ok }
 *   POST ?action=close    {id}              → { ok }
 *   POST ?action=delete   {id}              → { ok }
 *
 * Writes are same-origin + CSRF + rate-limited; reads require an org member.
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_once AV_ROOT . '/lib/Goals.php';

$u = LmsAuth::user();
if (!$u) json_out(['ok' => false, 'error' => 'Please sign in.'], 401);
if (!LmsAuth::isOrgMember($u)) json_out(['ok' => false, 'error' => 'Goals are for Afrovanguard members.'], 403);
$uid = (int) $u['id'];

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = (string) ($_GET['action'] ?? 'list');
$body = [];
if ($method === 'POST') { $body = json_decode(file_get_contents('php://input') ?: '', true) ?: $_POST; }

$writeGuard = function () use ($uid) { av_require_write($uid, 'goals', 60); };

try {
    switch ($action) {
        case 'list':
            json_out(['ok' => true, 'goals' => Goals::listGoals($uid, 40)]);

        case 'create':
            if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required.'], 405);
            $writeGuard();
            $id = Goals::create($uid, (string) ($body['title'] ?? ''), (string) ($body['target'] ?? ''));
            if (!$id) json_out(['ok' => false, 'error' => 'Add an objective.'], 422);
            json_out(['ok' => true, 'id' => $id]);

        case 'edit':
            if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required.'], 405);
            $writeGuard();
            json_out(['ok' => Goals::edit($uid, (int) ($body['id'] ?? 0), (string) ($body['title'] ?? ''), (string) ($body['target'] ?? ''))]);

        case 'progress':
            if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required.'], 405);
            $writeGuard();
            json_out(['ok' => Goals::setProgress($uid, (int) ($body['id'] ?? 0), (int) ($body['pct'] ?? 0))]);

        case 'close':
            if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required.'], 405);
            $writeGuard();
            json_out(['ok' => Goals::close($uid, (int) ($body['id'] ?? 0))]);

        case 'delete':
            if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required.'], 405);
            $writeGuard();
            json_out(['ok' => Goals::remove($uid, (int) ($body['id'] ?? 0))]);

        default:
            json_out(['ok' => false, 'error' => 'Unknown action.'], 400);
    }
} catch (Throwable $e) {
    error_log('[goals] ' . $e->getMessage());
    json_out(['ok' => false, 'error' => av_is_prod() ? 'Server error.' : ('Server error: ' . $e->getMessage())], 500);
}
