<?php
/**
 * lib/RecorderBuild.php — sign and publish the Meeting Recorder extension.
 *
 * Used by the Studio page (admin/recorder.php: one button, no SSH) and by the
 * command line (tools/build-recorder.php). Both produce the same thing in
 * downloads/meeting-recorder/:
 *
 *   meeting-recorder-<version>.crx   the signed extension (CRX3)
 *   updates.xml                      what Chrome checks for new versions
 *   extension-id.txt                 the ID to enter in the Google Admin console
 *
 * THE KEY IS THE EXTENSION'S IDENTITY. Its ID is derived from it, and every
 * installed copy only accepts updates signed with the same key. On the server
 * it lives in db/private/recorder/ (never web-served; AV_REC_KEY moves it).
 * The Studio page lets a superadmin download it once for safe keeping, and
 * restore it after moving hosts.
 */
declare(strict_types=1);

final class RecorderBuild
{
    public static function keyPath(): string
    {
        return (string) (getenv('AV_REC_KEY') ?: AV_ROOT . '/db/private/recorder/meeting-recorder-key.pem');
    }

    public static function outDir(): string
    {
        return (string) (getenv('AV_REC_DOWNLOADS') ?: AV_ROOT . '/downloads/meeting-recorder');
    }

    public static function srcDir(): string { return AV_ROOT . '/extensions/meeting-recorder'; }

    /** What the host can do, and what is already published. Never includes the key. */
    public static function status(): array
    {
        $key = self::keyPath();
        $man = json_decode((string) @file_get_contents(self::srcDir() . '/manifest.json'), true) ?: [];
        $out = self::outDir();
        $built = '';
        if (is_file($out . '/updates.xml') && preg_match("~<updatecheck [^>]*version='([0-9.]+)'~", (string) file_get_contents($out . '/updates.xml'), $m)) $built = $m[1];
        $id = '';
        if (is_file($key) && ($pk = openssl_pkey_get_private((string) file_get_contents($key)))) $id = self::identity($pk)['id'];
        $probe = is_dir($out) ? $out : dirname($out);
        return [
            'openssl' => extension_loaded('openssl'), 'zip' => class_exists('ZipArchive'),
            'has_key' => is_file($key), 'extension_id' => $id,
            'source_version' => (string) ($man['version'] ?? ''), 'built_version' => $built,
            'writable' => is_dir($probe) && is_writable($probe),
            'site' => rtrim(defined('SITE_URL') ? SITE_URL : '', '/'),
            'update_url' => rtrim(defined('SITE_URL') ? SITE_URL : '', '/') . '/downloads/meeting-recorder/updates.xml',
        ];
    }

    /** Make the signing key. Refuses if one exists: a new key is a new extension. */
    public static function createKey(): array
    {
        $path = self::keyPath();
        if (is_file($path)) return ['ok' => false, 'error' => 'A signing key already exists. A new one would change the extension’s ID.'];
        if (!extension_loaded('openssl')) return ['ok' => false, 'error' => 'This host’s PHP has no OpenSSL.'];
        $k = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        if (!$k || !openssl_pkey_export($k, $pem)) return ['ok' => false, 'error' => 'OpenSSL could not make a key on this host.'];
        return self::saveKey($pem);
    }

    /** Put back a key downloaded earlier (moving hosts). Only when none is there. */
    public static function restoreKey(string $pem): array
    {
        if (is_file(self::keyPath())) return ['ok' => false, 'error' => 'A signing key is already in place.'];
        $pk = openssl_pkey_get_private($pem);
        $d = $pk ? openssl_pkey_get_details($pk) : null;
        if (!$d || ($d['type'] ?? -1) !== OPENSSL_KEYTYPE_RSA) return ['ok' => false, 'error' => 'That file is not the recorder’s signing key.'];
        return self::saveKey($pem);
    }

    private static function saveKey(string $pem): array
    {
        $path = self::keyPath();
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0700, true)) return ['ok' => false, 'error' => 'Could not create ' . $dir . '.'];
        if (!is_file($dir . '/.htaccess')) @file_put_contents($dir . '/.htaccess', "Require all denied\n<IfModule !mod_authz_core.c>\n  Order allow,deny\n  Deny from all\n</IfModule>\n");
        if (@file_put_contents($path, $pem, LOCK_EX) === false) return ['ok' => false, 'error' => 'Could not write the key.'];
        @chmod($path, 0600);
        return ['ok' => true];
    }

    /** The key's public half, CRX id and extension ID. */
    private static function identity($priv): array
    {
        $details = openssl_pkey_get_details($priv);
        $pubDer = base64_decode((string) preg_replace('/-----[^-]+-----|\s+/', '', (string) $details['key']));
        $crxId = substr(hash('sha256', $pubDer, true), 0, 16);
        return ['der' => $pubDer, 'crx_id' => $crxId, 'id' => strtr(bin2hex($crxId), '0123456789abcdef', 'abcdefghijklmnop')];
    }

    /**
     * Build and sign for $site. Returns ['ok', 'id', 'version', 'crx', 'bytes', 'update_url'] or ['ok' => false, 'error'].
     * $unpacked, if given, also writes the prepared folder (for Load unpacked).
     */
    public static function build(string $site, ?string $keyPath = null, ?string $outDir = null, ?string $unpacked = null): array
    {
        $site = rtrim($site, '/');
        if (!preg_match('~^https://[a-z0-9.-]+(:[0-9]+)?$~i', $site) && !preg_match('~^http://(localhost|127\.0\.0\.1)(:[0-9]+)?$~', $site)) {
            return ['ok' => false, 'error' => 'The site address must be an https origin with no path (got ' . $site . ').'];
        }
        if (!extension_loaded('openssl') || !class_exists('ZipArchive')) return ['ok' => false, 'error' => 'This host’s PHP needs the OpenSSL and Zip extensions.'];
        $keyPath = $keyPath ?? self::keyPath();
        $outDir = $outDir ?? self::outDir();
        if (!is_file($keyPath)) return ['ok' => false, 'error' => 'There is no signing key yet.'];
        $priv = openssl_pkey_get_private((string) file_get_contents($keyPath));
        if (!$priv || (openssl_pkey_get_details($priv)['type'] ?? -1) !== OPENSSL_KEYTYPE_RSA) return ['ok' => false, 'error' => 'The signing key cannot be read.'];
        $idn = self::identity($priv);

        $src = self::srcDir();
        $manifest = json_decode((string) @file_get_contents($src . '/manifest.json'), true);
        if (!is_array($manifest)) return ['ok' => false, 'error' => 'extensions/meeting-recorder/manifest.json is missing or not valid.'];
        $version = (string) $manifest['version'];
        $codebase = $site . '/downloads/meeting-recorder/meeting-recorder-' . $version . '.crx';
        $manifest['key'] = base64_encode($idn['der']);            // same ID when loaded unpacked
        $manifest['update_url'] = $site . '/downloads/meeting-recorder/updates.xml';
        $manifestJson = str_replace('__SITE__', $site, (string) json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $files = [];
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($src) + 1));
            if (preg_match('~(^|/)(\.|tests?/|README|package\.json$)~', $rel)) continue;
            $files[$rel] = $rel === 'manifest.json' ? $manifestJson : (string) file_get_contents($f->getPathname());
        }
        if (!isset($files['config.js'])) return ['ok' => false, 'error' => 'config.js is missing from the extension.'];
        $files['config.js'] = str_replace('__SITE__', $site, $files['config.js']);
        ksort($files);
        foreach ($files as $rel => $body) if (str_contains($body, '__SITE__')) return ['ok' => false, 'error' => $rel . ' still has a __SITE__ placeholder.'];

        if ($unpacked !== null) {
            $u = rtrim($unpacked, '/');
            foreach ($files as $rel => $body) { @mkdir(dirname("$u/$rel"), 0775, true); file_put_contents("$u/$rel", $body); }
        }

        $zipPath = tempnam(sys_get_temp_dir(), 'avrec');
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) return ['ok' => false, 'error' => 'Could not write a zip in the host’s temp folder.'];
        foreach ($files as $rel => $body) {
            $zip->addFromString($rel, $body);
            if (method_exists($zip, 'setMtimeName')) $zip->setMtimeName($rel, 315532800);   // same source, same zip
        }
        $zip->close();
        $zipBytes = (string) file_get_contents($zipPath);
        @unlink($zipPath);

        // CRX3: "Cr24", version 3, a CrxFileHeader protobuf, then the zip.
        $varint = static function (int $n): string { $o = ''; do { $b = $n & 0x7f; $n >>= 7; $o .= chr($n ? $b | 0x80 : $b); } while ($n); return $o; };
        $field = static fn(int $no, string $bytes): string => $varint(($no << 3) | 2) . $varint(strlen($bytes)) . $bytes;
        $signedData = $field(1, $idn['crx_id']);                                  // SignedData { crx_id }
        $toSign = "CRX3 SignedData\x00" . pack('V', strlen($signedData)) . $signedData . $zipBytes;
        if (!openssl_sign($toSign, $sig, $priv, OPENSSL_ALGO_SHA256)) return ['ok' => false, 'error' => 'Signing failed.'];
        $header = $field(2, $field(1, $idn['der']) . $field(2, $sig))              // sha256_with_rsa { public_key, signature }
                . $field(10000, $signedData);                                     // signed_header_data
        $crx = 'Cr24' . pack('V', 3) . pack('V', strlen($header)) . $header . $zipBytes;

        if (!is_dir($outDir) && !@mkdir($outDir, 0775, true)) return ['ok' => false, 'error' => 'Could not create ' . $outDir . ' — check the folder permissions.'];
        $crxName = 'meeting-recorder-' . $version . '.crx';
        $xml = "<?xml version='1.0' encoding='UTF-8'?>\n<gupdate xmlns='http://www.google.com/update2/response' protocol='2.0'>\n"
             . "  <app appid='{$idn['id']}'>\n    <updatecheck codebase='" . htmlspecialchars($codebase, ENT_QUOTES) . "' version='{$version}' />\n  </app>\n</gupdate>\n";
        // The package first, the pointer to it last: Chrome never reads an updates.xml naming a file not yet there.
        if (@file_put_contents("$outDir/$crxName.tmp", $crx) === false || !@rename("$outDir/$crxName.tmp", "$outDir/$crxName")) {
            return ['ok' => false, 'error' => 'Could not write into ' . $outDir . ' — check the folder permissions.'];
        }
        file_put_contents("$outDir/extension-id.txt", $idn['id'] . "\n");
        file_put_contents("$outDir/updates.xml.tmp", $xml);
        rename("$outDir/updates.xml.tmp", "$outDir/updates.xml");
        foreach (glob("$outDir/meeting-recorder-*.crx") ?: [] as $old) if (basename($old) !== $crxName) @unlink($old);

        return ['ok' => true, 'id' => $idn['id'], 'version' => $version, 'crx' => "$outDir/$crxName", 'bytes' => strlen($crx), 'update_url' => $manifest['update_url']];
    }
}
