<?php
/**
 * lib/MemberRoles.php — the work roles members hold, as a list the office keeps.
 *
 * A member's work roles (member_profiles.work_roles — "Media, Events") were
 * typed fresh on every record, so one role was soon three ("Media", "media
 * team", "Medai") and nothing said what a role covered. Here each role has
 * one spelling and a description of the work it covers, kept by the office:
 *
 *   · a role typed on a member is written in the list's spelling, and a role
 *     the list does not have yet joins it — nobody has to set the list up first
 *   · renaming a role renames it on every member who holds it; removing it
 *     takes it off them
 *   · its description is what task matching reads besides the name
 *     (TaskMatch): "Media — photos, video, posters and the socials" lets a
 *     task about a flyer find the Media people
 *
 * Roles already on members when this first runs are taken into the list.
 */
declare(strict_types=1);

final class MemberRoles
{
    private const NAME_MAX = 40;
    private const DESC_MAX = 500;

    public static function ensure(): void
    {
        static $done = false; if ($done) return; $done = true;
        Database::execSchema(Database::pdo(), "CREATE TABLE IF NOT EXISTS member_roles (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name VARCHAR(40) NOT NULL DEFAULT '',
            description VARCHAR(500) NOT NULL DEFAULT '',
            created_at VARCHAR(32) NOT NULL DEFAULT '',
            updated_at VARCHAR(32) NOT NULL DEFAULT ''
        )");
    }

    /** id => ['id','name','description'] from the table, by name. */
    private static function rows(): array
    {
        self::ensure();
        $out = [];
        foreach (Database::pdo()->query('SELECT id, name, description FROM member_roles ORDER BY name')->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int) $r['id']] = ['id' => (int) $r['id'], 'name' => (string) $r['name'], 'description' => (string) $r['description']];
        }
        return $out;
    }

    /** Every member's roles: user_id => [role…]. */
    private static function held(): array
    {
        $out = [];
        try {
            if (class_exists('MemberRoster')) MemberRoster::ensure();
            foreach (Database::pdo()->query("SELECT user_id, work_roles FROM member_profiles WHERE work_roles <> ''")->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $out[(int) $r['user_id']] = MemberRoster::workRoles((string) $r['work_roles']);
            }
        } catch (Throwable $e) {}
        return $out;
    }

    /** The list's spelling of a role, or '' when it is not on the list. */
    public static function canonical(string $name): string
    {
        $k = mb_strtolower(trim($name));
        foreach (self::rows() as $r) if (mb_strtolower($r['name']) === $k) return $r['name'];
        return '';
    }

    /**
     * Roles as a member should hold them: the list's spelling, and any role
     * the list does not have joins it. Returns the cleaned names.
     */
    public static function adopt(array $names): array
    {
        $out = [];
        foreach ($names as $n) {
            $n = trim((string) $n);
            if ($n === '') continue;
            $c = self::canonical($n);
            if ($c === '') { self::insert($n, ''); $c = $n; }
            if (!in_array(mb_strtolower($c), array_map('mb_strtolower', $out), true)) $out[] = $c;
        }
        return $out;
    }

    /**
     * The list, each with how many members hold it, by name:
     * [['id','name','description','members']…]. Roles found on members but
     * not on the list are taken into it first.
     */
    public static function all(): array
    {
        $held = self::held();
        $known = [];
        foreach (self::rows() as $r) $known[mb_strtolower($r['name'])] = true;
        foreach ($held as $roles) foreach ($roles as $n) {
            if (!isset($known[mb_strtolower($n)])) { self::insert($n, ''); $known[mb_strtolower($n)] = true; }
        }
        $count = [];
        foreach ($held as $roles) foreach ($roles as $n) $count[mb_strtolower($n)] = ($count[mb_strtolower($n)] ?? 0) + 1;
        return array_values(array_map(static fn($r) => $r + ['members' => $count[mb_strtolower($r['name'])] ?? 0], self::rows()));
    }

    /** name (lower case) => description, for the roles that have one. */
    private static ?array $desc = null;

    public static function descriptions(): array
    {
        if (self::$desc !== null) return self::$desc;
        self::$desc = [];
        try { foreach (self::rows() as $r) if ($r['description'] !== '') self::$desc[mb_strtolower($r['name'])] = $r['description']; } catch (Throwable $e) {}
        return self::$desc;
    }

    /**
     * Add a role (id 0) or change one: its name — renamed on every member who
     * holds it — and its description.
     */
    public static function save(int $id, string $name, string $description, string $actor = ''): array
    {
        self::ensure();
        $name = mb_substr(trim((string) preg_replace('/\s+/u', ' ', $name)), 0, self::NAME_MAX);
        $description = mb_substr(trim((string) preg_replace('/\s+/u', ' ', $description)), 0, self::DESC_MAX);
        if ($name === '') return ['ok' => false, 'error' => 'Give the role a name.'];
        if (preg_match('/[,;]/', $name)) return ['ok' => false, 'error' => 'A role name cannot have a comma or semicolon in it — they separate roles.'];
        $rows = self::rows();
        foreach ($rows as $r) {
            if ($r['id'] !== $id && mb_strtolower($r['name']) === mb_strtolower($name)) return ['ok' => false, 'error' => 'There is already a role called ' . $r['name'] . '.'];
        }
        $now = gmdate('Y-m-d H:i:s');
        if ($id <= 0) {
            $newId = self::insert($name, $description);
            self::audit('role.create', $name, $actor);
            return ['ok' => true, 'id' => $newId, 'renamed' => 0];
        }
        if (!isset($rows[$id])) return ['ok' => false, 'error' => 'No such role.'];
        $old = $rows[$id]['name'];
        Database::pdo()->prepare('UPDATE member_roles SET name = ?, description = ?, updated_at = ? WHERE id = ?')->execute([$name, $description, $now, $id]);
        self::$desc = null;
        $renamed = $old !== $name ? self::rewrite($old, $name) : 0;
        self::audit('role.update', $old . ($old !== $name ? ' → ' . $name . ' (' . $renamed . ' members)' : ''), $actor);
        return ['ok' => true, 'id' => $id, 'renamed' => $renamed];
    }

    /** Remove a role from the list and from every member who holds it. */
    public static function delete(int $id, string $actor = ''): array
    {
        $rows = self::rows();
        if (!isset($rows[$id])) return ['ok' => false, 'error' => 'No such role.'];
        $n = self::rewrite($rows[$id]['name'], null);
        Database::pdo()->prepare('DELETE FROM member_roles WHERE id = ?')->execute([$id]);
        self::$desc = null;
        self::audit('role.delete', $rows[$id]['name'] . ' (' . $n . ' members)', $actor);
        return ['ok' => true, 'removed_from' => $n];
    }

    /** Member ids holding a role. */
    public static function holders(string $name): array
    {
        $k = mb_strtolower(trim($name));
        $out = [];
        foreach (self::held() as $id => $roles) if (in_array($k, array_map('mb_strtolower', $roles), true)) $out[] = $id;
        return $out;
    }

    /* ── internals ─────────────────────────────────────────────────────── */

    private static function insert(string $name, string $description): int
    {
        self::ensure();
        self::$desc = null;
        $now = gmdate('Y-m-d H:i:s');
        Database::pdo()->prepare('INSERT INTO member_roles (name, description, created_at, updated_at) VALUES (?,?,?,?)')
            ->execute([mb_substr($name, 0, self::NAME_MAX), mb_substr($description, 0, self::DESC_MAX), $now, $now]);
        return (int) Database::pdo()->lastInsertId();
    }

    /** Rename ($to) or remove ($to null) a role on every member holding it. Returns how many. */
    private static function rewrite(string $from, ?string $to): int
    {
        $k = mb_strtolower($from);
        $n = 0;
        $st = Database::pdo()->prepare('UPDATE member_profiles SET work_roles = ?, updated_at = ? WHERE user_id = ?');
        foreach (self::held() as $id => $roles) {
            $hit = false; $next = [];
            foreach ($roles as $r) {
                if (mb_strtolower($r) === $k) { $hit = true; if ($to !== null) $next[] = $to; }
                else $next[] = $r;
            }
            if (!$hit) continue;
            $st->execute([implode(', ', MemberRoster::workRoles($next)), gmdate('Y-m-d H:i:s'), $id]);
            $n++;
        }
        return $n;
    }

    private static function audit(string $what, string $detail, string $actor): void
    {
        try { (new LmsRepository())->audit($what, '', $detail, $actor !== '' ? $actor : 'admin'); } catch (Throwable $e) {}
    }
}
