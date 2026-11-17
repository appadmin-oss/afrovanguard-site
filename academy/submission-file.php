<?php
/**
 * academy/submission-file.php?id=N — a submitted file, to the people allowed it.
 *
 * Files live outside the web root (GradingWork::storeDir). This is the only way
 * to reach one, and it serves it only to the learner who submitted it, the
 * course team and Academy staff (CourseTeam::can 'mark'), and the peers the
 * system assigned to review it — GradingWork::canSee() is that rule. Anyone
 * else gets the same 404 as a file that does not exist, so ids reveal nothing.
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/bootstrap.php';

$user = LmsAuth::user();
$sub = GradingWork::submission((int) ($_GET['id'] ?? 0));
$path = $sub ? GradingWork::filePath($sub) : null;
if (!$user || !$sub || $path === null || !GradingWork::canSee($sub, (int) $user['id'])) {
    if (!headers_sent()) { http_response_code(404); header('Content-Type: text/plain; charset=utf-8'); }
    echo 'Not found.';
    return;
}
$name = preg_replace('/[^A-Za-z0-9._ -]/', '_', (string) $sub['file_name']) ?: 'submission';
header('Content-Type: ' . ((string) $sub['file_mime'] ?: 'application/octet-stream'));
header('Content-Length: ' . (string) filesize($path));
header('Content-Disposition: attachment; filename="' . $name . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
header("Content-Security-Policy: default-src 'none'; sandbox");
header('Referrer-Policy: no-referrer');
readfile($path);
