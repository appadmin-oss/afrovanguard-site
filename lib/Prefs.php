<?php
/**
 * lib/Prefs.php — small per-user key/value preferences store (timezone, etc.).
 * Portable DB layer.
 */
declare(strict_types=1);

final class Prefs
{
    /** Timezones offered in the picker (label => IANA id). Keep short + relevant. */
    public const TIMEZONES = [
        'Lagos (WAT, UTC+1)'      => 'Africa/Lagos',
        'Accra (GMT, UTC+0)'      => 'Africa/Accra',
        'Nairobi (EAT, UTC+3)'    => 'Africa/Nairobi',
        'Johannesburg (UTC+2)'    => 'Africa/Johannesburg',
        'London (UK)'             => 'Europe/London',
        'New York (US East)'      => 'America/New_York',
        'UTC'                     => 'UTC',
    ];

    public static function ensure(): void
    {
        static $done = false;
        if ($done) return;
        $db = Database::pdo();
        $ddl = "CREATE TABLE IF NOT EXISTS user_prefs (
            user_id INTEGER NOT NULL,
            pref_key VARCHAR(40) NOT NULL,
            pref_value VARCHAR(200) NOT NULL DEFAULT '',
            PRIMARY KEY (user_id, pref_key)
        );";
        $drv = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
        Database::execSchema($db, $ddl);
        $done = true;
    }

    public static function get(int $uid, string $key, string $default = ''): string
    {
        self::ensure();
        if ($uid <= 0) return $default;
        try {
            $st = Database::pdo()->prepare('SELECT pref_value FROM user_prefs WHERE user_id = ? AND pref_key = ?');
            $st->execute([$uid, $key]);
            $v = $st->fetchColumn();
            return $v === false ? $default : (string) $v;
        } catch (Throwable $e) { return $default; }
    }

    public static function set(int $uid, string $key, string $value): bool
    {
        self::ensure();
        if ($uid <= 0 || $key === '') return false;
        $db = Database::pdo();
        $value = mb_substr($value, 0, 200);
        // Portable upsert.
        $db->prepare('DELETE FROM user_prefs WHERE user_id = ? AND pref_key = ?')->execute([$uid, $key]);
        $db->prepare('INSERT INTO user_prefs (user_id, pref_key, pref_value) VALUES (?,?,?)')->execute([$uid, $key, $value]);
        return true;
    }

    /** Validate + save a timezone; rejects unknown IANA ids. */
    public static function setTimezone(int $uid, string $tz): bool
    {
        if (!in_array($tz, self::TIMEZONES, true)) return false;
        return self::set($uid, 'tz', $tz);
    }
}
