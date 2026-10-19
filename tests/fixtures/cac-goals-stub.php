<?php
/**
 * tests/fixtures/cac-goals-stub.php — CACENTRE's api/afrovanguard-goals.php,
 * for tests/cacgoals.test.php, served with `php -S`. It checks the token the
 * way CACENTRE does (signed with AV_SSO_SECRET, for the goals door only) and
 * answers with the goals in the JSON file named by CAC_STUB_STATE. Every other
 * path is the week door (cac-week-stub.php), so steps can be counted too.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli-server') { http_response_code(403); exit; }
if (parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH) !== '/api/afrovanguard-goals.php') return require __DIR__ . '/cac-week-stub.php';
header('Content-Type: application/json');
$st = json_decode((string) @file_get_contents((string) getenv('CAC_STUB_STATE')), true) ?: [];
$out = static function (int $code, array $b) { http_response_code($code); echo json_encode($b); return true; };
if (!empty($st['down'])) return $out(503, ['ok' => false, 'error' => 'unavailable']);
$p = explode('.', (string) ($_GET['t'] ?? ''));
$unb = static fn(string $s) => (string) base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4));
if (count($p) !== 3 || !hash_equals(hash_hmac('sha256', $p[1], (string) getenv('AV_SSO_SECRET'), true), $unb($p[2]))) return $out(403, ['ok' => false, 'error' => 'bad-signature']);
$c = json_decode($unb($p[1]), true);
if (($c['aud'] ?? '') !== 'goals') return $out(403, ['ok' => false, 'error' => 'wrong-door']);
return $out(200, ['ok' => true, 'goals' => array_values((array) ($st['goals'] ?? []))]);
