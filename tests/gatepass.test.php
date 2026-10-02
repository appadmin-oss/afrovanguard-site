<?php
/**
 * tests/gatepass.test.php — the member pass for the CACENTRE gate.
 *
 * The gate (cacentre-site/gate, a Cloudflare Worker) accepts a pass only if
 * it is HS256 under hex(HMAC(secret, "cacentre-gate/v1/member-pass")), from
 * iss "afrovanguard" to aud "cacentre-gate", and lives no more than 31 days.
 * These checks hold this side to exactly those rules, and use the same
 * fixture the Worker's own tests use for the key derivation.
 */
declare(strict_types=1);

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

putenv('GATE_PASS_SECRET=');
ck('GatePass: off without a secret', !GatePass::ready());
putenv('GATE_PASS_SECRET=changeme-changeme-changeme-changeme');
ck('GatePass: a placeholder secret counts as unset', !GatePass::ready());
$secret = str_repeat('g', 48);
putenv('GATE_PASS_SECRET=' . $secret);
putenv('GATE_URL=https://gate.example.test/');
putenv('GATE_PASS_TTL=');
putenv('GATE_PASS_ROLES=');
ck('GatePass: a real secret is usable', GatePass::ready());

ck('GatePass: purpose keys match the Worker (fixture from gate/test/units.test.ts)',
   GatePass::key('admin', 'test-cac-secret') === 'b10ebd146ec5150d619e5d2073b5b50e088f6416a92816300d49eeb766b3a78a');

$member = ['id' => 42, 'name' => 'Ada Obi', 'role' => 'member', 'status' => 'active', 'email' => 'ada@example.com'];
ck('GatePass: members get one', GatePass::eligible($member));
ck('GatePass: learners do not', !GatePass::eligible(['role' => 'learner'] + $member));
ck('GatePass: suspended accounts do not', !GatePass::eligible(['status' => 'suspended'] + $member));
ck('GatePass: nobody signed in does not', !GatePass::eligible(null));

$now = time();
$p = GatePass::mint($member, $now);
ck('GatePass: the URL is the gate\'s /p/ route', $p['url'] === 'https://gate.example.test/p/' . $p['token']);
ck('GatePass: the token has the shape the gate\'s reader matches',
   (bool) preg_match('~^eyJ[A-Za-z0-9_-]+\.eyJ[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+$~', $p['token']));
$hdr = json_decode(base64_decode(strtr(explode('.', $p['token'])[0], '-_', '+/')), true);
ck('GatePass: HS256', ($hdr['alg'] ?? '') === 'HS256');
$c = (array) JWT::decode($p['token'], new Key(hash_hmac('sha256', 'cacentre-gate/v1/member-pass', $secret), 'HS256'));
ck('GatePass: signed with the member-pass purpose key, not the raw secret', ($c['sub'] ?? '') === '42');
$raw = false;
try { JWT::decode($p['token'], new Key($secret, 'HS256')); $raw = true; } catch (Throwable $e) {}
ck('GatePass: the raw secret does not verify it', !$raw);
ck('GatePass: iss/aud as the gate expects', $c['iss'] === 'afrovanguard' && $c['aud'] === 'cacentre-gate');
ck('GatePass: twelve hours by default', $c['exp'] - $c['iat'] === 12 * 3600 && $c['iat'] === $now);
ck('GatePass: carries a name, role and jti', $c['name'] === 'Ada Obi' && $c['role'] === 'Member' && strlen((string) $c['jti']) === 18);
ck('GatePass: no email or phone in a thing shown on a screen', !isset($c['email']) && !isset($c['phone']));
putenv('GATE_PASS_TTL=' . (400 * 86400));
ck('GatePass: TTL is capped at the gate\'s 31 days', GatePass::ttl() === 31 * 86400);
putenv('GATE_PASS_TTL=');
$threw = false;
try { GatePass::mint(['role' => 'learner'] + $member); } catch (RuntimeException $e) { $threw = true; }
ck('GatePass: refuses to mint for a learner', $threw);

$svg = GatePass::svg($p['url']);
ck('GatePass: renders the QR as inline SVG', str_starts_with(ltrim($svg), '<svg') && str_contains($svg, '</svg>'));

/* Revocation: signed exactly as the gate's /v1/issuers/av/revoke verifies it. */
$seen = [];
$r = GatePass::revoke(42, 1751980000, function (string $url, array $h, string $body) use (&$seen) { $seen = [$url, $h, $body]; return [200, '{"ok":true}']; });
$hh = [];
foreach ($seen[1] as $line) { [$k, $v] = explode(': ', $line, 2); $hh[$k] = $v; }
ck('GatePass: revoke posts to the gate', $r['ok'] && $seen[0] === 'https://gate.example.test/v1/issuers/av/revoke');
ck('GatePass: revoke body is {sub, before}', json_decode($seen[2], true) === ['sub' => '42', 'before' => 1751980000]);
ck('GatePass: revoke is signed "ts.body" under the admin purpose key',
   $hh['X-Gate-Signature'] === 'sha256=' . hash_hmac('sha256', $hh['X-Gate-Timestamp'] . '.' . $seen[2], hash_hmac('sha256', 'cacentre-gate/v1/admin', $secret)));
$r = GatePass::revoke(42, null, fn() => [401, '{"ok":false}']);
ck('GatePass: a refused revocation is reported, not swallowed', !$r['ok']);

$page = (string) file_get_contents(AV_ROOT . '/gate-pass.php');
ck('GatePass: the page is never cached', str_contains($page, 'no-store'));
ck('GatePass: the page sends signed-out visitors to sign in', str_contains($page, "av_login_url('/gate-pass')"));
ck('GatePass: routed at /gate-pass', str_contains((string) file_get_contents(AV_ROOT . '/router.php'), "'/gate-pass.php'"));

foreach (['GATE_PASS_SECRET', 'GATE_URL', 'GATE_PASS_TTL', 'GATE_PASS_ROLES'] as $k) putenv($k);
