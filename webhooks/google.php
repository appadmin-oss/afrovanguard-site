<?php
/**
 * webhooks/google.php — receiver for Google Calendar/Drive push notifications.
 *
 * Google POSTs here whenever a watched resource changes (see lib/GoogleWatch).
 * The body is empty; everything is in X-Goog-* headers. We validate the channel
 * against our registry (channel id + token must match), then emit an in-process
 * event that the automation layer (lib/AvAutomation) reacts to. We answer 200
 * fast; an unknown channel gets 404 so Google stops sending to it.
 *
 * Served at /webhooks/google (see .htaccess + router.php). No auth cookie —
 * Google is authenticated by the unguessable channel id + token it echoes back.
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/bootstrap.php';

// Google always POSTs push notifications.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') { http_response_code(405); exit; }

$channelId = (string) ($_SERVER['HTTP_X_GOOG_CHANNEL_ID'] ?? '');
$token     = (string) ($_SERVER['HTTP_X_GOOG_CHANNEL_TOKEN'] ?? '');
$state     = (string) ($_SERVER['HTTP_X_GOOG_RESOURCE_STATE'] ?? '');
$resource  = (string) ($_SERVER['HTTP_X_GOOG_RESOURCE_ID'] ?? '');

if ($channelId === '') { http_response_code(400); exit; }

$row = GoogleWatch::findByChannelId($channelId);
// Unknown or mismatched channel → 404 so Google retires it (never leak which failed).
if (!$row || !hash_equals((string) $row['token'], $token)) { http_response_code(404); exit; }

// 'sync' is the handshake Google sends right after a watch is created — ack only.
if ($state === 'sync') { http_response_code(200); exit; }

$eventName = $row['kind'] === 'drive' ? 'workspace.drive_changed' : 'workspace.calendar_changed';
try {
    Events::emit($eventName, [
        'user_id'     => (int) $row['user_id'],
        'channel_id'  => $channelId,
        'resource_id' => $resource,
        'state'       => $state,
    ]);
} catch (Throwable $e) {
    error_log('[webhook google] emit failed: ' . $e->getMessage());
}

http_response_code(200);
echo 'ok';
