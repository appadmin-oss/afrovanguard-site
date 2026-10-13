<?php
/**
 * tests/fixtures/cac-week-stub.php — CACENTRE's api/afrovanguard-week.php,
 * enough of it for tests/cacweek.test.php, served with `php -S`. It checks
 * the token the way CACENTRE does (HMAC with AV_SSO_SECRET) and keeps one
 * commitment in the JSON file named by CAC_STUB_STATE; every request is
 * appended to <state>.log as {method, uid, sub, body}.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli-server') { http_response_code(403); exit; }
header('Content-Type: application/json');
$file = (string) getenv('CAC_STUB_STATE');
$st = json_decode((string) @file_get_contents($file), true) ?: [];
$out = static function (int $code, array $b) { http_response_code($code); echo json_encode($b); return true; };
if (!empty($st['down'])) return $out(503, ['ok' => false, 'error' => 'unavailable']);
$p = explode('.', (string) ($_GET['t'] ?? ''));
$unb = static fn(string $s) => (string) base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4));
if (count($p) !== 3 || !hash_equals(hash_hmac('sha256', $p[1], (string) getenv('AV_SSO_SECRET'), true), $unb($p[2]))) return $out(403, ['ok' => false, 'error' => 'bad-signature']);
$c = json_decode($unb($p[1]), true);
$body = json_decode((string) file_get_contents('php://input'), true);
file_put_contents($file . '.log', json_encode(['method' => $_SERVER['REQUEST_METHOD'], 'uid' => $c['uid'] ?? 0, 'sub' => $c['sub'] ?? '', 'body' => $body]) . "\n", FILE_APPEND);
$st += ['tasks' => []];
if (is_array($body)) {
    $cid = (int) ($body['commitment_id'] ?? 0);
    if (in_array($body['action'] ?? '', ['link', 'unlink'], true) && $cid !== 7) return $out(404, ['ok' => false, 'error' => 'not-yours']);
    $tid = (int) ($body['task']['id'] ?? $body['task_id'] ?? 0);
    if (($body['action'] ?? '') === 'link') $st['tasks'][$tid] = !empty($body['task']['done']);
    if (($body['action'] ?? '') === 'unlink') unset($st['tasks'][$tid]);
    if (($body['action'] ?? '') === 'state' && array_key_exists($tid, $st['tasks'])) $st['tasks'][$tid] = !empty($body['task']['done']);
    file_put_contents($file, json_encode($st));
}
return $out(200, ['ok' => true, 'linked' => true, 'person' => 'Ada', 'week' => ['start' => '2026-10-12', 'end' => '2026-10-18'], 'commitments' => [[
    'id' => 7, 'statement' => 'Run 3 sessions', 'period_kind' => 'weekly', 'period_start' => '2026-10-12', 'period_end' => '2026-10-18',
    'target' => 3, 'unit' => 'count', 'delivered' => count(array_filter($st['tasks'])), 'status' => 'open', 'posting' => 'Coordinator',
    'av_tasks' => array_map('intval', array_keys($st['tasks'])),
    'goal' => ['id' => 1, 'code' => 'G-1', 'title' => 'Twelve sessions', 'unit' => 'count', 'target' => 12, 'progress' => 4, 'status' => 'active', 'due_on' => '2026-10-31', 'secret' => 'not for the portal'],
]]]);
