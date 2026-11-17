<?php
/**
 * tests/recorderbuild.test.php — publishing the Meeting Recorder (lib/RecorderBuild.php),
 * which the Studio page runs on a host with no SSH.
 *
 *   1. The key is made once; a second "make" is refused (it would change the ID).
 *   2. The package is a valid CRX3: its signature verifies against its own
 *      public key over the exact bytes Chrome checks, and its ID matches.
 *   3. updates.xml names the package and version; the manifest inside carries
 *      the site, the update URL and the key; no placeholder survives.
 *   4. A restore refuses junk and refuses to replace a key that is there.
 *   5. The Studio page is for superadmins only.
 *
 * Run via tests/run.php (provides ck()).
 */
declare(strict_types=1);
require_once AV_ROOT . '/lib/RecorderBuild.php';

$tmp = sys_get_temp_dir() . '/av-recbuild-' . getmypid();
@mkdir($tmp, 0700, true);
putenv("AV_REC_KEY=$tmp/key/meeting-recorder-key.pem");
putenv("AV_REC_DOWNLOADS=$tmp/out");

$st = RecorderBuild::status();
ck('before anything: no key', !$st['has_key'] && $st['extension_id'] === '');
ck('building with no key is refused', empty(RecorderBuild::build('https://afrovanguard.org.ng')['ok']));
ck('the key is made', !empty(RecorderBuild::createKey()['ok']) && is_file("$tmp/key/meeting-recorder-key.pem"));
ck('the key folder denies the web', is_file("$tmp/key/.htaccess"));
ck('the key file is private', (fileperms("$tmp/key/meeting-recorder-key.pem") & 0077) === 0);
$first = file_get_contents("$tmp/key/meeting-recorder-key.pem");
ck('a second key is refused', empty(RecorderBuild::createKey()['ok']) && file_get_contents("$tmp/key/meeting-recorder-key.pem") === $first);

ck('a site with a path is refused', empty(RecorderBuild::build('https://afrovanguard.org.ng/portal')['ok']));
ck('plain http to a real host is refused', empty(RecorderBuild::build('http://afrovanguard.org.ng')['ok']));
$r = RecorderBuild::build('https://afrovanguard.org.ng/');
ck('it builds', !empty($r['ok']), (string) ($r['error'] ?? ''));
ck('the ID is 32 letters a–p', (bool) preg_match('/^[a-p]{32}$/', (string) $r['id']));
ck('status reports the ID and version', RecorderBuild::status()['extension_id'] === $r['id'] && RecorderBuild::status()['built_version'] === $r['version']);

/* Read the CRX the way Chrome does. */
$crx = (string) file_get_contents($r['crx']);
ck('the package starts Cr24, version 3', substr($crx, 0, 4) === 'Cr24' && unpack('V', substr($crx, 4, 4))[1] === 3);
$hl = unpack('V', substr($crx, 8, 4))[1];
$header = substr($crx, 12, $hl);
$zipBytes = substr($crx, 12 + $hl);
$rd = static function (string $s, int &$p): int { $n = 0; $sh = 0; do { $c = ord($s[$p++]); $n |= ($c & 0x7f) << $sh; $sh += 7; } while ($c & 0x80); return $n; };
$fields = static function (string $s) use ($rd): array { $p = 0; $o = []; while ($p < strlen($s)) { $k = $rd($s, $p); $l = $rd($s, $p); $o[$k >> 3][] = substr($s, $p, $l); $p += $l; } return $o; };
$h = $fields($header);
$proof = $fields($h[2][0]);
$pub = $proof[1][0]; $sig = $proof[2][0]; $signed = $h[10000][0];
$pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($pub), 64) . "-----END PUBLIC KEY-----\n";
ck('the signature verifies over what Chrome checks', openssl_verify("CRX3 SignedData\x00" . pack('V', strlen($signed)) . $signed . $zipBytes, $sig, $pem, OPENSSL_ALGO_SHA256) === 1);
ck('the signed crx_id is the key hash', $fields($signed)[1][0] === substr(hash('sha256', $pub, true), 0, 16));
ck('the ID comes from that key', strtr(substr(hash('sha256', $pub), 0, 32), '0123456789abcdef', 'abcdefghijklmnop') === $r['id']);

$zf = "$tmp/check.zip"; file_put_contents($zf, $zipBytes);
$z = new ZipArchive(); $z->open($zf);
$man = json_decode((string) $z->getFromName('manifest.json'), true);
ck('the manifest carries the update URL', ($man['update_url'] ?? '') === 'https://afrovanguard.org.ng/downloads/meeting-recorder/updates.xml');
ck('the manifest carries the public key', ($man['key'] ?? '') === base64_encode($pub));
ck('the manifest may reach the site', in_array('https://afrovanguard.org.ng/*', (array) ($man['host_permissions'] ?? []), true));
ck('config.js points at the site', str_contains((string) $z->getFromName('config.js'), "'https://afrovanguard.org.ng'"));
$names = []; for ($i = 0; $i < $z->numFiles; $i++) $names[] = $z->getNameIndex($i);
ck('no placeholder survives anywhere', !array_filter($names, static fn($n) => str_contains((string) $z->getFromName($n), '__SITE__')));
ck('package.json and tests are not shipped', !in_array('package.json', $names, true) && !preg_grep('~test~i', $names));
$z->close();

$xml = (string) file_get_contents("$tmp/out/updates.xml");
ck('updates.xml names this ID, version and package', str_contains($xml, "appid='{$r['id']}'") && str_contains($xml, "version='{$r['version']}'")
    && str_contains($xml, "codebase='https://afrovanguard.org.ng/downloads/meeting-recorder/meeting-recorder-{$r['version']}.crx'"));
ck('building again gives the same package', RecorderBuild::build('https://afrovanguard.org.ng')['ok'] && hash_file('sha256', $r['crx']) !== '' && strlen((string) file_get_contents($r['crx'])) === strlen($crx));

ck('restoring over an existing key is refused', empty(RecorderBuild::restoreKey($first)['ok']));
unlink("$tmp/key/meeting-recorder-key.pem");
ck('restoring junk is refused', empty(RecorderBuild::restoreKey('not a key')['ok']));
ck('restoring the downloaded key works', !empty(RecorderBuild::restoreKey($first)['ok']));
ck('and gives back the same ID', RecorderBuild::status()['extension_id'] === $r['id']);

/* The Studio page: superadmins only. */
$src = (string) file_get_contents(AV_ROOT . '/admin/recorder.php');
ck('the Studio page refuses anyone but a superadmin before doing anything',
    strpos($src, "if (\$role !== 'superadmin')") !== false && strpos($src, "if (\$role !== 'superadmin')") < strpos($src, "REQUEST_METHOD"));
ck('every Studio action checks CSRF and origin', str_contains($src, 'require_same_origin();') && str_contains($src, "av_csrf_valid((string) (\$_POST['csrf']"));

putenv('AV_REC_KEY'); putenv('AV_REC_DOWNLOADS');
exec('rm -rf ' . escapeshellarg($tmp));
