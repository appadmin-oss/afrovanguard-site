<?php
/**
 * workspace.php — /workspace, kept only so old links and bookmarks still land.
 *
 * Google Workspace is part of the portal now (lib/GoogleHub.php): the inbox,
 * files and quick actions on Today, the member's calendar in the calendar,
 * Google Chat in Team Chat, the apps in the Suite, Meet in Meetings, and the
 * connected account in the side bar. This page used to be a second home with
 * all of that; it sends people to the first one, carrying any notice from the
 * Google connect round-trip with them.
 */
declare(strict_types=1);
require_once __DIR__ . '/lib/bootstrap.php';

$q = [];
if (isset($_GET['connected'])) $q['google'] = 'connected';
if (isset($_GET['e']) && preg_match('/^[a-z_]{1,40}$/', (string) $_GET['e'])) $q['google_e'] = (string) $_GET['e'];
header('Cache-Control: no-store');
header('Location: /portal/' . ($q ? '?' . http_build_query($q) : ''), true, 302);
exit;
