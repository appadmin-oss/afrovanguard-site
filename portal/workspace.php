<?php
/**
 * portal/workspace.php — live Google Workspace data for the member portal.
 *
 * Members-only JSON: real upcoming Calendar events, real Drive files, and (with
 * domain-wide delegation) the user directory — pulled server-side via
 * GoogleWorkspace. Fetched progressively by the portal so a slow/missing Google
 * never blocks the page; an unconfigured Workspace simply returns configured=false
 * and the portal keeps its launchpad + embeds.
 *
 *   GET ?action=events     → {ok, configured, events:[…]}
 *   GET ?action=files      → {ok, configured, files:[…]}
 *   GET ?action=directory  → {ok, configured, users:[…]}  (admins only)
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/bootstrap.php';

$u = LmsAuth::user();
if (!$u) json_out(['ok' => false, 'error' => 'Please sign in.'], 401);
if (!LmsAuth::isOrgMember($u)) json_out(['ok' => false, 'error' => 'Members only.'], 403);

$configured = GoogleWorkspace::configured();
$action = (string) ($_GET['action'] ?? 'events');

try {
    switch ($action) {
        case 'events':
            json_out(['ok' => true, 'configured' => $configured, 'events' => $configured ? GoogleWorkspace::calendarEvents(null, 8) : []]);
        case 'files':
            json_out(['ok' => true, 'configured' => $configured, 'files' => $configured ? GoogleWorkspace::driveFiles(null, 12) : []]);
        case 'directory':
            if (!LmsAuth::atLeast($u, 'admin')) json_out(['ok' => false, 'error' => 'Admins only.'], 403);
            json_out(['ok' => true, 'configured' => $configured, 'users' => $configured ? GoogleWorkspace::directoryUsers(200) : []]);
        default:
            json_out(['ok' => false, 'error' => 'Unknown action.'], 400);
    }
} catch (Throwable $e) {
    error_log('[portal workspace] ' . $e->getMessage());
    json_out(['ok' => false, 'error' => 'Server error.'], 500);
}
