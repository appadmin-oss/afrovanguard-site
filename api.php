<?php
/**
 * api.php — public Team / People directory (native, DB-backed).
 *
 * Replaces the old Google Apps Script proxy. Data is managed in the Studio
 * (admin) and stored in the app database; see lib/people.php.
 *
 *   GET ?action=members          → all active members
 *   GET ?action=member&id=N      → single member profile
 *   GET ?action=votm             → current Volunteer of the Month
 *   GET ?action=celebrations     → today's auto-celebrations (holidays/birthdays/VOTM)
 */
declare(strict_types=1);
require_once __DIR__ . '/lib/bootstrap.php';
require_once AV_ROOT . '/lib/people.php';

if (function_exists('send_security_headers')) send_security_headers('public');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'OPTIONS') { http_response_code(204); exit; }
if ($method !== 'GET') json_out(['status' => 'error', 'error' => 'GET required.'], 405);
if (function_exists('av_rate_ok') && !av_rate_ok('public_api', 120, 60)) {
    json_out(['status' => 'error', 'error' => 'Too many requests.'], 429);
}

$action = (string) ($_GET['action'] ?? 'members');
if (!in_array($action, ['members', 'member', 'votm', 'celebrations', 'page_content'], true)) {
    json_out(['status' => 'error', 'error' => 'Unknown action.'], 400);
}

try {
    $pdo = Database::pdo();
    if ($action === 'page_content') {
        // Admin-authored content overrides for an editable public page
        // (applied client-side by assets/site/page-edits.js).
        $slug = preg_replace('/[^a-z0-9\-]/', '', strtolower((string) ($_GET['page'] ?? '')));
        if ($slug === '' || strlen($slug) > 40) json_out(['status' => 'error', 'error' => 'A page slug is required.'], 400);
        $edits = json_decode((string) (Database::metaGet('page_edits:' . $slug) ?: '{}'), true);
        $out = ['status' => 'ok', 'page' => $slug, 'edits' => (is_array($edits) && $edits) ? $edits : (object) []];
    } elseif ($action === 'members') {
        $out = av_team_members($pdo);
    } elseif ($action === 'member') {
        $id = (int) ($_GET['id'] ?? 0);
        if ($id < 1) json_out(['status' => 'error', 'error' => 'A member id is required.'], 400);
        $out = av_team_one($pdo, $id);
    } elseif ($action === 'votm') {
        $out = av_votm($pdo);
    } else { // celebrations
        if (is_file(AV_ROOT . '/lib/celebrations.php')) {
            require_once AV_ROOT . '/lib/celebrations.php';
            $out = ['status' => 'ok', 'celebration' => av_celebration_today($pdo)];
        } else {
            $out = ['status' => 'ok', 'celebration' => null];
        }
    }
} catch (Throwable $e) {
    error_log('[api] ' . $e->getMessage());
    json_out(['status' => 'error', 'error' => 'Directory is temporarily unavailable.'], 500);
}

header('Cache-Control: public, max-age=60, stale-while-revalidate=30');
json_out($out, ($out['status'] ?? 'ok') === 'ok' ? 200 : 404);
