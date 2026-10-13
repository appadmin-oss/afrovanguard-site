<?php
/**
 * webhooks/google-meet.php — Google Meet events by Pub/Sub PUSH (optional).
 *
 * Only for a site that uses a push subscription instead of the cron-drained
 * pull one (lib/MeetEvents). Pub/Sub signs each push with a Google OIDC token
 * for the audience set on the subscription; anything without a valid one is
 * refused. The message is a nudge: MeetEvents reads the meeting again from
 * Google. Answers fast; 204 tells Pub/Sub it is delivered.
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') { http_response_code(405); exit; }
if (MeetEvents::pushAudience() === '') { http_response_code(404); exit; }
$auth = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
if (MeetEvents::verifyPush($auth) === null) { http_response_code(401); exit; }

$body = json_decode((string) file_get_contents('php://input'), true);
$msg = is_array($body) ? (array) ($body['message'] ?? []) : [];
try { MeetEvents::handle($msg); } catch (Throwable $e) { error_log('[meet-push] ' . $e->getMessage()); }
http_response_code(204);
