<?php
/**
 * tools/backup-decrypt.php — turn a backup from lib/Backups.php back into a
 * database you can restore.
 *
 *   AV_BACKUP_KEY=… php tools/backup-decrypt.php main-20261027-020000.sqlite.gz.enc > main.sqlite
 *   php tools/backup-decrypt.php main-20261027-020000.sql.gz > main.sql      (not encrypted)
 *
 * A .sqlite result replaces db/diary.sqlite (or db/ngv.sqlite); a .sql
 * result is loaded with: mysql DBNAME < main.sql
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }
require_once dirname(__DIR__) . '/lib/bootstrap.php';

$file = (string) ($argv[1] ?? '');
if ($file === '' || !is_file($file)) { fwrite(STDERR, "Usage: php tools/backup-decrypt.php FILE > out\n"); exit(2); }
$blob = (string) file_get_contents($file);
if (str_ends_with($file, '.enc')) {
    $pass = (string) (getenv('AV_BACKUP_KEY') ?: Config::get('AV_BACKUP_KEY', ''));
    $blob = Backups::decrypt($blob, $pass);
    if ($blob === null) { fwrite(STDERR, "Could not decrypt: wrong AV_BACKUP_KEY, or the file is damaged.\n"); exit(1); }
}
$plain = gzdecode($blob);
if ($plain === false) { fwrite(STDERR, "Not a gzip file.\n"); exit(1); }
fwrite(STDOUT, $plain);
