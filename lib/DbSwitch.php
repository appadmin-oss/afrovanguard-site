<?php
/**
 * lib/DbSwitch.php — move the site onto the database it was copied to, from
 * the Studio, and back.
 *
 * Why it exists. The migration (lib/DbMigrateJob.php) fills a MySQL or
 * PostgreSQL database and stops: pointing the site at it was "a hand edit to
 * .env or .htaccess". On shared hosting with no shell, whoever runs the site
 * could copy their data and then had no way to use it (owner, 2026-11-13:
 * "the migration only copies the database content, there's no switch").
 *
 * How. The connection is written to ONE small file of its own, never into the
 * operator's .env:
 *
 *   ../.av-database.php   above the web root, when the host lets us write there
 *   ./.av-database.php    otherwise: a dotfile, which .htaccess refuses to serve
 *
 * It is a PHP file that returns an array, so even a server that served it
 * would run it and print nothing. Mode 0600. bootstrap.php calls apply() on
 * every request, which puts the connection into the environment the way a
 * .env line would — but ONLY where the host's own settings (SetEnv, .env)
 * choose no database. Settings the host makes always win; the Studio says so.
 *
 * AV_DB_STRICT is set with it: once the site has moved, it must never fall
 * back to the old SQLite file without saying so, or the two copies diverge.
 *
 * Switching back deletes the file: the site is on its SQLite file again. What
 * was written to the server database meanwhile stays there.
 */
declare(strict_types=1);

final class DbSwitch
{
    private const NAME = '.av-database.php';
    /** The environment names that choose a database. */
    private const KEYS = ['AV_DB_DRIVER', 'AV_DB_DSN', 'AV_DB_HOST', 'AV_DB_PORT', 'AV_DB_NAME', 'AV_DB_USER', 'AV_DB_PASS'];

    /** Did apply() put the connection into the environment on this request? */
    private static bool $applied = false;
    /** Did the host's own settings already choose a database before apply() ran? */
    private static ?bool $hostChose = null;

    /** Where the file is, if it exists: above the web root first. */
    public static function path(): ?string
    {
        foreach (self::candidates() as $p) if (@is_file($p)) return $p;
        return null;
    }

    /** Where a new file would be written: above the web root when writable. */
    public static function writePath(): ?string
    {
        foreach (self::candidates() as $p) if (@is_writable(dirname($p))) return $p;
        return null;
    }

    private static function candidates(): array
    {
        $over = getenv('AV_DB_SWITCH_FILE');
        $out = (is_string($over) && $over !== '') ? [$over] : [];
        $out[] = dirname(AV_ROOT) . '/' . self::NAME;
        $out[] = AV_ROOT . '/' . self::NAME;
        return $out;
    }

    /** The saved choice, or null. */
    public static function read(): ?array
    {
        $p = self::path();
        if ($p === null) return null;
        try { $c = (static fn(string $f) => include $f)($p); } catch (Throwable $e) { error_log('[db-switch] unreadable: ' . $e->getMessage()); return null; }
        if (!is_array($c) || !in_array($c['driver'] ?? '', ['mysql', 'pgsql'], true) || trim((string) ($c['name'] ?? '')) === '') return null;
        return $c;
    }

    /**
     * Called by bootstrap.php on every request, after the .env loader.
     * Fills the environment from the saved choice unless the host has
     * already chosen a database.
     */
    public static function apply(): void
    {
        self::$hostChose = false;
        foreach (self::KEYS as $k) { $v = getenv($k); if ($v !== false && $v !== '') { self::$hostChose = true; break; } }
        if (self::$hostChose) return;
        $c = self::read();
        if ($c === null) return;
        $set = ['AV_DB_DRIVER' => $c['driver'], 'AV_DB_HOST' => (string) ($c['host'] ?? ''), 'AV_DB_PORT' => (string) ($c['port'] ?? ''),
                'AV_DB_NAME' => (string) $c['name'], 'AV_DB_USER' => (string) ($c['user'] ?? ''), 'AV_DB_PASS' => (string) ($c['pass'] ?? '')];
        if (getenv('AV_DB_STRICT') === false || getenv('AV_DB_STRICT') === '') $set['AV_DB_STRICT'] = '1';
        foreach ($set as $k => $v) { if ($v === '') continue; putenv($k . '=' . $v); $_ENV[$k] = $v; }
        self::$applied = true;
    }

    /** What the Studio shows. Never the password. */
    public static function status(): array
    {
        $c = self::read();
        $p = self::path();
        $w = self::writePath();
        return [
            'active'      => self::$applied,
            'saved'       => $c !== null,
            'target'      => $c ? self::label($c) : '',
            'switched_at' => (string) ($c['switched_at'] ?? ''),
            'switched_by' => (string) ($c['switched_by'] ?? ''),
            'host_chose'  => (bool) self::$hostChose,
            'where'       => $p !== null ? (str_starts_with($p, AV_ROOT . '/') ? 'site folder' : 'above the web root') : '',
            'can_write'   => $w !== null,
        ];
    }

    public static function label(array $c): string
    {
        $drv = ($c['driver'] ?? '') === 'pgsql' ? 'PostgreSQL' : 'MySQL';
        return $drv . ' “' . (string) ($c['name'] ?? '') . '” on ' . ((string) ($c['host'] ?? '') ?: 'localhost');
    }

    /**
     * Save the choice. $c: driver, host, port, name, user, pass. Written to a
     * temporary file and renamed, so a half-written file is never read; then
     * read back, so a file the host will not let PHP read is caught here and
     * not on the next request.
     */
    public static function write(array $c, string $by): string
    {
        $path = self::writePath();
        if ($path === null) throw new RuntimeException('This host does not let the site write its own settings (the folder above the site, and the site folder, are read-only). Ask the host to allow it, or set AV_DB_* in .env by hand.');
        $data = [
            'driver' => $c['driver'] === 'pgsql' ? 'pgsql' : 'mysql',
            'host' => trim((string) ($c['host'] ?? '')), 'port' => trim((string) ($c['port'] ?? '')),
            'name' => trim((string) ($c['name'] ?? '')), 'user' => trim((string) ($c['user'] ?? '')), 'pass' => (string) ($c['pass'] ?? ''),
            'switched_at' => gmdate('Y-m-d H:i:s') . ' UTC', 'switched_by' => $by,
        ];
        $php = "<?php\n// Written by the Studio (Database → Switch). Delete this file, or use\n// \"Switch back\", to return the site to its SQLite file. lib/DbSwitch.php\nreturn " . var_export($data, true) . ";\n";
        $tmp = $path . '.tmp-' . bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, $php, LOCK_EX) === false) throw new RuntimeException('Could not write ' . basename($path) . '.');
        @chmod($tmp, 0600);
        if (!@rename($tmp, $path)) { @unlink($tmp); throw new RuntimeException('Could not put ' . basename($path) . ' in place.'); }
        if (function_exists('opcache_invalidate')) @opcache_invalidate($path, true);
        $back = self::read();
        if ($back === null || $back['name'] !== $data['name'] || $back['pass'] !== $data['pass']) {
            @unlink($path);
            throw new RuntimeException('The settings file was written but could not be read back, so the site was not switched.');
        }
        return $path;
    }

    /** Back to SQLite: the file goes. Returns the label of what it pointed at. */
    public static function clear(): string
    {
        $c = self::read();
        $p = self::path();
        if ($p !== null) {
            if (!@unlink($p)) throw new RuntimeException('Could not remove ' . basename($p) . '.');
            if (function_exists('opcache_invalidate')) @opcache_invalidate($p, true);
        }
        return $c ? self::label($c) : '';
    }
}
