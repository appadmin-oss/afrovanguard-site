<?php
/**
 * lib/Backups.php — a daily copy of both databases, kept here and off-site.
 *
 * Members, payments, consents and the audit trail live in two databases (the
 * main one and NGV's). Until now nothing copied them: a bad migration, a
 * hacked host or a deleted cPanel account lost them for good (NDPA 2023 s.39:
 * security, which includes being able to restore).
 *
 * Once a day, from tasks/cron.php:
 *   1. each database is copied — SQLite with VACUUM INTO (a consistent copy
 *      while the site runs), MySQL as SQL (CREATE TABLE + INSERTs) through
 *      PDO, since shared hosts often have no mysqldump;
 *   2. gzipped, and encrypted with AES-256-GCM when AV_BACKUP_KEY is set;
 *   3. kept in db/private/backups (web-denied by db/.htaccess), newest 14;
 *   4. sent to the Drive folder AV_BACKUP_DRIVE_FOLDER — only when encrypted:
 *      a plain copy of every member's record is never sent anywhere.
 *
 * Restore: php tools/backup-decrypt.php FILE > out.sql (or out.sqlite).
 */
declare(strict_types=1);

final class Backups
{
    const KEEP = 14;
    const MAGIC = 'AVBK1';

    public static function dir(): string
    {
        $d = (string) (Config::get('AV_BACKUP_DIR', '') ?: (defined('AV_ROOT') ? AV_ROOT : dirname(__DIR__)) . '/db/private/backups');
        if (!is_dir($d)) @mkdir($d, 0700, true);
        return $d;
    }

    private static function key(): string
    {
        $k = (string) Config::get('AV_BACKUP_KEY', '');
        return strlen($k) >= 16 ? hash('sha256', 'avbackup|' . $k, true) : '';
    }

    /** Due once a day; $force runs it now. */
    public static function runIfDue(bool $force = false): array
    {
        $last = (string) (Database::metaGet('backup_last') ?? '');
        if (!$force && $last !== '' && strtotime($last) > time() - 86400 + 600) return ['skipped' => true, 'last' => $last];
        $r = self::run();
        if ($r['ok']) Database::metaSet('backup_last', gmdate('c'));
        return $r;
    }

    public static function run(): array
    {
        $stamp = gmdate('Ymd-His');
        $out = ['ok' => true, 'files' => [], 'encrypted' => self::key() !== '', 'offsite' => []];
        $dbs = ['main' => Database::pdo()];
        if (class_exists('NgvDb')) { try { $dbs['ngv'] = NgvDb::pdo(); } catch (Throwable $e) { error_log('[backup] ngv: ' . $e->getMessage()); } }
        foreach ($dbs as $name => $pdo) {
            try {
                [$bytes, $ext] = self::dump($pdo);
                $blob = gzencode($bytes, 6);
                if ($blob === false) throw new RuntimeException('gzip failed');
                $ext .= '.gz';
                if (($k = self::key()) !== '') { $blob = self::encrypt($blob, $k); $ext .= '.enc'; }
                $file = self::dir() . '/' . $name . '-' . $stamp . '.' . $ext;
                if (file_put_contents($file, $blob, LOCK_EX) === false) throw new RuntimeException('could not write ' . basename($file));
                @chmod($file, 0600);
                $out['files'][] = basename($file);
                if ($out['encrypted'] && ($folder = (string) Config::get('AV_BACKUP_DRIVE_FOLDER', '')) !== '' && class_exists('GoogleWorkspace')) {
                    $up = GoogleWorkspace::driveUpload($folder, basename($file), 'application/octet-stream', $blob);
                    $out['offsite'][basename($file)] = $up ? 'sent' : 'failed';
                }
            } catch (Throwable $e) {
                $out['ok'] = false; $out['errors'][$name] = $e->getMessage();
                error_log('[backup] ' . $name . ': ' . $e->getMessage());
            }
        }
        self::prune();
        if (!$out['encrypted']) $out['note'] = 'Set AV_BACKUP_KEY to encrypt backups and send them off-site.';
        return $out;
    }

    /** @return array{0:string,1:string} the copy and its extension */
    private static function dump(PDO $pdo): array
    {
        $drv = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($drv === 'sqlite') {
            $tmp = tempnam(sys_get_temp_dir(), 'avbk');
            @unlink($tmp);
            $pdo->exec('VACUUM INTO ' . $pdo->quote($tmp));
            $bytes = (string) file_get_contents($tmp);
            @unlink($tmp);
            return [$bytes, 'sqlite'];
        }
        $sql = "-- Afrovanguard backup " . gmdate('c') . "\nSET FOREIGN_KEY_CHECKS=0;\n";
        foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $t) {
            $q = '`' . str_replace('`', '``', (string) $t) . '`';
            $create = $pdo->query('SHOW CREATE TABLE ' . $q)->fetch(PDO::FETCH_NUM);
            $sql .= "\nDROP TABLE IF EXISTS $q;\n" . $create[1] . ";\n";
            $st = $pdo->query('SELECT * FROM ' . $q);
            while ($row = $st->fetch(PDO::FETCH_NUM)) {
                $sql .= "INSERT INTO $q VALUES (" . implode(',', array_map(static fn($v) => $v === null ? 'NULL' : $pdo->quote((string) $v), $row)) . ");\n";
            }
        }
        return [$sql . "SET FOREIGN_KEY_CHECKS=1;\n", 'sql'];
    }

    public static function encrypt(string $plain, string $key): string
    {
        $iv = random_bytes(12); $tag = '';
        $c = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($c === false) throw new RuntimeException('encryption failed');
        return self::MAGIC . $iv . $tag . $c;
    }

    public static function decrypt(string $blob, string $passphrase): ?string
    {
        if (strncmp($blob, self::MAGIC, 5) !== 0) return null;
        $key = hash('sha256', 'avbackup|' . $passphrase, true);
        $p = openssl_decrypt(substr($blob, 33), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr($blob, 5, 12), substr($blob, 17, 16));
        return $p === false ? null : $p;
    }

    /** Newest KEEP per database; older ones go. */
    private static function prune(): void
    {
        $by = [];
        foreach (glob(self::dir() . '/*-????????-??????.*') ?: [] as $f) $by[explode('-', basename($f))[0]][] = $f;
        foreach ($by as $files) {
            rsort($files);
            foreach (array_slice($files, self::KEEP) as $old) @unlink($old);
        }
    }

    /** For the Studio's health panel. */
    public static function status(): array
    {
        $files = glob(self::dir() . '/*-????????-??????.*') ?: [];
        rsort($files);
        return [
            'last' => Database::metaGet('backup_last'),
            'count' => count($files),
            'newest' => $files ? basename($files[0]) : null,
            'encrypted' => self::key() !== '',
            'offsite' => self::key() !== '' && (string) Config::get('AV_BACKUP_DRIVE_FOLDER', '') !== '',
        ];
    }
}
