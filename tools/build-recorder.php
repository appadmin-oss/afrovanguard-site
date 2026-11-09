<?php
/**
 * tools/build-recorder.php — package the Meeting Recorder extension for the
 * Google Admin console (self-hosted, force-installed; no Chrome Web Store).
 *
 *   php tools/build-recorder.php [--site=https://afrovanguard.org.ng] [--key=PATH] [--new-key]
 *                                [--out=downloads/meeting-recorder] [--unpacked=DIR]
 *
 * Writes, under --out:
 *   meeting-recorder-<version>.crx   the signed extension (CRX3)
 *   updates.xml                      what Chrome checks for new versions
 *   extension-id.txt                 the ID to enter in the Admin console
 *
 * THE KEY IS THE EXTENSION'S IDENTITY. Its ID is derived from it, and every
 * computer the Admin console installed the extension on only accepts updates
 * signed with the same key. Lose it and you publish a new extension under a
 * new ID. So it is never in the repository: default ~/.afrovanguard/
 * meeting-recorder-key.pem (or AV_REC_KEY). Back it up where the organisation
 * keeps its other secrets. --new-key makes one, and refuses if one exists.
 *
 * To release: raise "version" in extensions/meeting-recorder/manifest.json,
 * run this, commit downloads/meeting-recorder/, deploy. Chrome picks it up
 * within a few hours (or at once via chrome://extensions → Update).
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }

$root = dirname(__DIR__);
$opt = getopt('', ['site:', 'key:', 'new-key', 'out:', 'unpacked:', 'help']);
if (isset($opt['help'])) { echo preg_replace('/^ \* ?/m', '', explode('*/', explode('/**', (string) file_get_contents(__FILE__), 2)[1], 2)[0]); exit(0); }

$fail = static function (string $msg): void { fwrite(STDERR, "✗ $msg\n"); exit(1); };
$site = rtrim((string) ($opt['site'] ?? (getenv('SITE_URL') ?: 'https://afrovanguard.org.ng')), '/');
if (!preg_match('~^https://[a-z0-9.-]+(:[0-9]+)?$~i', $site) && !preg_match('~^http://(localhost|127\.0\.0\.1)(:[0-9]+)?$~', $site)) {
    $fail("--site must be an https origin with no path (got $site)");
}
$home = (string) (getenv('HOME') ?: sys_get_temp_dir());
$keyPath = (string) ($opt['key'] ?? (getenv('AV_REC_KEY') ?: $home . '/.afrovanguard/meeting-recorder-key.pem'));
$outDir = (string) ($opt['out'] ?? $root . '/downloads/meeting-recorder');
$src = $root . '/extensions/meeting-recorder';

/* ── the key ───────────────────────────────────────────────────────── */
if (isset($opt['new-key'])) {
    if (is_file($keyPath)) $fail("A key already exists at $keyPath. Using a new one would change the extension's ID. Remove --new-key.");
    @mkdir(dirname($keyPath), 0700, true);
    $k = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    if (!$k || !openssl_pkey_export($k, $pem)) $fail('OpenSSL could not make a key.');
    file_put_contents($keyPath, $pem);
    chmod($keyPath, 0600);
    echo "• New signing key written to $keyPath — BACK IT UP. It is the extension's identity.\n";
}
if (!is_file($keyPath)) $fail("No signing key at $keyPath. First release: add --new-key. Later releases: point --key (or AV_REC_KEY) at the backed-up key.");
$priv = openssl_pkey_get_private((string) file_get_contents($keyPath));
if (!$priv) $fail("$keyPath is not a private key OpenSSL can read.");
$details = openssl_pkey_get_details($priv);
if (($details['type'] ?? -1) !== OPENSSL_KEYTYPE_RSA) $fail('The key must be RSA.');
$pubDer = base64_decode(preg_replace('/-----[^-]+-----|\s+/', '', (string) $details['key']));
$crxId = substr(hash('sha256', $pubDer, true), 0, 16);
$extId = strtr(bin2hex($crxId), '0123456789abcdef', 'abcdefghijklmnop');

/* ── the files ─────────────────────────────────────────────────────── */
$manifest = json_decode((string) file_get_contents($src . '/manifest.json'), true);
if (!is_array($manifest)) $fail('manifest.json is not valid JSON.');
$version = (string) $manifest['version'];
$codebase = $site . '/downloads/meeting-recorder/meeting-recorder-' . $version . '.crx';
$manifest['key'] = base64_encode($pubDer);               // same ID when loaded unpacked
$manifest['update_url'] = $site . '/downloads/meeting-recorder/updates.xml';
$manifestJson = str_replace('__SITE__', $site, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

$files = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($src) + 1));
    if (preg_match('~(^|/)(\.|tests?/|README|package\.json$)~', $rel)) continue;
    $files[$rel] = $rel === 'manifest.json' ? $manifestJson : (string) file_get_contents($f->getPathname());
}
$files['config.js'] = str_replace('__SITE__', $site, $files['config.js']);
ksort($files);
foreach ($files as $rel => $body) {
    if (str_contains($body, '__SITE__')) $fail("$rel still has a __SITE__ placeholder.");
}

if (isset($opt['unpacked'])) {
    $u = rtrim((string) $opt['unpacked'], '/');
    foreach ($files as $rel => $body) { @mkdir(dirname("$u/$rel"), 0775, true); file_put_contents("$u/$rel", $body); }
    echo "• Unpacked copy for chrome://extensions → Load unpacked: $u\n";
}

/* ── zip, then sign (CRX3) ─────────────────────────────────────────── */
$zipPath = tempnam(sys_get_temp_dir(), 'avrec') . '.zip';
$zip = new ZipArchive();
if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) $fail('Could not write the zip.');
foreach ($files as $rel => $body) {
    $zip->addFromString($rel, $body);
    $zip->setMtimeName($rel, 315532800);                 // fixed time: the same source gives the same zip
}
$zip->close();
$zipBytes = (string) file_get_contents($zipPath);
@unlink($zipPath);

$varint = static function (int $n): string { $o = ''; do { $b = $n & 0x7f; $n >>= 7; $o .= chr($n ? $b | 0x80 : $b); } while ($n); return $o; };
$field = static fn(int $no, string $bytes): string => $varint(($no << 3) | 2) . $varint(strlen($bytes)) . $bytes;

$signedData = $field(1, $crxId);                          // SignedData { crx_id }
$toSign = "CRX3 SignedData\x00" . pack('V', strlen($signedData)) . $signedData . $zipBytes;
if (!openssl_sign($toSign, $sig, $priv, OPENSSL_ALGO_SHA256)) $fail('Signing failed.');
$header = $field(2, $field(1, $pubDer) . $field(2, $sig)) // sha256_with_rsa { public_key, signature }
        . $field(10000, $signedData);                     // signed_header_data
$crx = 'Cr24' . pack('V', 3) . pack('V', strlen($header)) . $header . $zipBytes;

@mkdir($outDir, 0775, true);
$crxName = 'meeting-recorder-' . $version . '.crx';
file_put_contents("$outDir/$crxName", $crx);
file_put_contents("$outDir/extension-id.txt", $extId . "\n");
file_put_contents("$outDir/updates.xml", <<<XML
<?xml version='1.0' encoding='UTF-8'?>
<gupdate xmlns='http://www.google.com/update2/response' protocol='2.0'>
  <app appid='{$extId}'>
    <updatecheck codebase='{$codebase}' version='{$version}' />
  </app>
</gupdate>

XML);
foreach (glob("$outDir/meeting-recorder-*.crx") ?: [] as $oldCrx) if (basename($oldCrx) !== $crxName) @unlink($oldCrx);

printf("✓ Meeting Recorder %s for %s\n  ID:          %s\n  Package:     %s (%d KB)\n  Update URL:  %s\n",
    $version, $site, $extId, "$outDir/$crxName", (int) ceil(strlen($crx) / 1024), $manifest['update_url']);
echo "\nIn the Google Admin console: Devices → Chrome → Apps & extensions → Users & browsers →\n"
   . "pick the staff organisational unit → (+) → Add Chrome app or extension by ID →\n"
   . "From a custom URL → ID above, URL = the Update URL above → Installation policy: Force install.\n";
