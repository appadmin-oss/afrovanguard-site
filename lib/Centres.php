<?php
/**
 * lib/Centres.php — the places members belong to, as one list the office keeps.
 *
 * A member's centre (member_profiles.centre) was typed fresh on every record,
 * and the roster's centre filter listed whatever had been typed: "Egbeda",
 * "egbeda ", "Egbeda centre". NGG keeps its centres as records with a code
 * and a status (api/_lib/domain/registration.php); this is the same idea:
 *
 *   · a centre has one spelling and a short code (EG, AY), both unique
 *   · a member's centre is one on the list — or none
 *   · renaming a centre renames it on every member who belongs to it
 *   · a centre in use is retired, not deleted: its members keep it, nobody
 *     new is put there, and a delete says how many people it would strand
 *
 * Centres already on members when this first runs are taken into the list,
 * so nothing a member has today is refused tomorrow.
 */
declare(strict_types=1);

final class Centres
{
    public const NAME_MAX = 60;
    public const MAX = 200;

    public static function ensure(): void
    {
        static $done = false; if ($done) return; $done = true;
        Database::execSchema(Database::pdo(), "CREATE TABLE IF NOT EXISTS av_centres (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            code VARCHAR(8) NOT NULL DEFAULT '',
            name VARCHAR(60) NOT NULL DEFAULT '',
            status VARCHAR(12) NOT NULL DEFAULT 'active',
            created_at VARCHAR(32) NOT NULL DEFAULT '',
            updated_at VARCHAR(32) NOT NULL DEFAULT ''
        )");
        self::adoptExisting();
    }

    /** The centres already typed on members join the list, once. */
    private static function adoptExisting(): void
    {
        try {
            if (Database::metaGet('av_centres_adopted') === '1') return;
            if (class_exists('MemberRoster')) MemberRoster::ensure();
            $have = array_map('mb_strtolower', array_column(self::rows(true), 'name'));
            $names = Database::pdo()->query("SELECT DISTINCT centre FROM member_profiles WHERE centre <> ''")->fetchAll(PDO::FETCH_COLUMN);
            foreach ($names as $n) {
                $n = self::clean((string) $n);
                if ($n === '' || in_array(mb_strtolower($n), $have, true)) continue;
                self::insert($n, self::suggestCode($n));
                $have[] = mb_strtolower($n);
            }
            Database::metaSet('av_centres_adopted', '1');
        } catch (Throwable $e) { error_log('[centres] adopt: ' . $e->getMessage()); }
    }

    private static function now(): string { return gmdate('Y-m-d H:i:s'); }

    private static function clean(string $name): string
    {
        return mb_substr(trim(preg_replace('/\s+/u', ' ', $name) ?? ''), 0, self::NAME_MAX);
    }

    private static function rows(bool $withRetired): array
    {
        $sql = 'SELECT id, code, name, status, created_at, updated_at FROM av_centres'
             . ($withRetired ? '' : " WHERE status = 'active'") . ' ORDER BY name';
        return array_map(static fn($r) => ['id' => (int) $r['id'], 'code' => (string) $r['code'], 'name' => (string) $r['name'],
            'status' => (string) $r['status'], 'created_at' => (string) $r['created_at'], 'updated_at' => (string) $r['updated_at']],
            Database::pdo()->query($sql)->fetchAll(PDO::FETCH_ASSOC));
    }

    private static function insert(string $name, string $code): int
    {
        Database::pdo()->prepare('INSERT INTO av_centres (code, name, status, created_at, updated_at) VALUES (?,?,?,?,?)')
            ->execute([$code, $name, 'active', self::now(), self::now()]);
        return (int) Database::pdo()->lastInsertId();
    }

    /** A free code from a name: "Egbeda" → EG, then EG2, EG3… */
    public static function suggestCode(string $name): string
    {
        $base = strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $name) ?? '', 0, 2)) ?: 'CT';
        $taken = array_column(self::rows(true), 'code');
        for ($i = 1, $c = $base; in_array($c, $taken, true); $i++) $c = $base . ($i + 1);
        return $c;
    }

    /** Every centre, with how many members each holds. Retired ones last. */
    public static function all(bool $withRetired = true): array
    {
        self::ensure();
        $counts = [];
        foreach (Database::pdo()->query("SELECT LOWER(centre) c, COUNT(*) n FROM member_profiles WHERE centre <> '' GROUP BY LOWER(centre)")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $counts[(string) $r['c']] = (int) $r['n'];
        }
        $rows = self::rows($withRetired);
        foreach ($rows as &$r) $r['members'] = $counts[mb_strtolower($r['name'])] ?? 0;
        unset($r);
        usort($rows, static fn($a, $b) => [$a['status'] !== 'active', mb_strtolower($a['name'])] <=> [$b['status'] !== 'active', mb_strtolower($b['name'])]);
        return $rows;
    }

    /** Active centre names, for a picker. */
    public static function names(): array { return array_column(self::all(false), 'name'); }

    public static function get(int $id): ?array
    {
        foreach (self::all() as $c) if ($c['id'] === $id) return $c;
        return null;
    }

    /**
     * The list's spelling of a centre a member may be put in: '' for none,
     * the name for an active centre, and a retired one only when it is the
     * centre the member already has. null when it is not on the list.
     */
    public static function canonical(string $name, string $current = ''): ?string
    {
        self::ensure();
        $k = mb_strtolower(self::clean($name));
        if ($k === '') return '';
        foreach (self::rows(true) as $c) {
            if (mb_strtolower($c['name']) !== $k) continue;
            if ($c['status'] === 'active' || mb_strtolower(self::clean($current)) === $k) return $c['name'];
            return null;
        }
        return null;
    }

    /** Is this a retired centre? A member is not put in one (unless already there). */
    public static function isRetired(string $name): bool
    {
        self::ensure();
        $k = mb_strtolower(self::clean($name));
        foreach (self::rows(true) as $c) if (mb_strtolower($c['name']) === $k) return $c['status'] !== 'active';
        return false;
    }

    /**
     * The list's spelling of a centre typed on a member, adding it to the list
     * when it is new — as a work role typed on a member joins MemberRoles. A
     * centre is never two spellings: "egbeda " is Egbeda.
     */
    public static function adopt(string $name): string
    {
        self::ensure();
        $name = self::clean($name);
        if ($name === '') return '';
        foreach (self::rows(true) as $c) if (mb_strtolower($c['name']) === mb_strtolower($name)) return $c['name'];
        if (count(self::rows(true)) >= self::MAX) return $name;
        self::insert($name, self::suggestCode($name));
        self::audit('centre.create', $name, 'added from a member record', 'system');
        return $name;
    }

    /** Why a centre is refused, in words a form can show. */
    public static function refusal(string $name): string
    {
        self::ensure();
        $k = mb_strtolower(self::clean($name));
        foreach (self::rows(true) as $c) {
            if (mb_strtolower($c['name']) === $k && $c['status'] !== 'active') return $c['name'] . ' is retired. Choose a centre that is open.';
        }
        return '“' . self::clean($name) . '” is not a centre. Add it in Members → Centres first.';
    }

    /**
     * Add a centre, or rename / recode one. A rename is carried onto every
     * member who belongs to it.
     *
     * @param array{id?:int|string,name?:string,code?:string} $d
     */
    public static function save(array $d, string $actor): array
    {
        self::ensure();
        $id = (int) ($d['id'] ?? 0);
        $name = self::clean((string) ($d['name'] ?? ''));
        $code = strtoupper(trim((string) ($d['code'] ?? '')));
        if (mb_strlen($name) < 2) return ['ok' => false, 'error' => 'A centre needs a name of at least two letters.'];
        if ($code === '') $code = $id ? (string) (self::get($id)['code'] ?? '') : self::suggestCode($name);
        if (!preg_match('/^[A-Z][A-Z0-9]{0,7}$/', $code)) return ['ok' => false, 'error' => 'A code is up to 8 letters and digits, starting with a letter, like EG.'];
        $all = self::rows(true);
        if (!$id && count($all) >= self::MAX) return ['ok' => false, 'error' => 'At most ' . self::MAX . ' centres.'];
        foreach ($all as $c) {
            if ($c['id'] === $id) continue;
            if (mb_strtolower($c['name']) === mb_strtolower($name)) return ['ok' => false, 'code' => 'name_taken', 'error' => $c['name'] . ' is already a centre.'];
            if ($c['code'] === $code) return ['ok' => false, 'code' => 'code_taken', 'error' => $code . ' is already the code of ' . $c['name'] . '.'];
        }
        $pdo = Database::pdo();
        if (!$id) {
            $id = self::insert($name, $code);
            self::audit('centre.create', $name, $code, $actor);
            return ['ok' => true, 'id' => $id, 'centre' => self::get($id)];
        }
        $was = null;
        foreach ($all as $c) if ($c['id'] === $id) $was = $c;
        if (!$was) return ['ok' => false, 'error' => 'No such centre.'];
        $moved = 0;
        $pdo->beginTransaction();
        try {
            $pdo->prepare('UPDATE av_centres SET name = ?, code = ?, updated_at = ? WHERE id = ?')->execute([$name, $code, self::now(), $id]);
            if ($name !== $was['name']) {
                $st = $pdo->prepare('UPDATE member_profiles SET centre = ? WHERE LOWER(centre) = ?');
                $st->execute([$name, mb_strtolower($was['name'])]);
                $moved = $st->rowCount();
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            return ['ok' => false, 'error' => 'Nothing was changed: ' . $e->getMessage()];
        }
        if ($name !== $was['name'] || $code !== $was['code']) {
            self::audit('centre.update', $was['name'], ($name !== $was['name'] ? 'renamed to ' . $name . ', ' . $moved . ' members moved with it' : '') . ($code !== $was['code'] ? ' code ' . $was['code'] . ' → ' . $code : ''), $actor);
        }
        return ['ok' => true, 'id' => $id, 'moved' => $moved, 'centre' => self::get($id)];
    }

    /** Retire (or reopen) a centre. Its members keep it; nobody new is put there. */
    public static function setStatus(int $id, string $status, string $actor): array
    {
        self::ensure();
        if (!in_array($status, ['active', 'retired'], true)) return ['ok' => false, 'error' => 'A centre is active or retired.'];
        $c = self::get($id);
        if (!$c) return ['ok' => false, 'error' => 'No such centre.'];
        if ($c['status'] === $status) return ['ok' => true, 'unchanged' => true];
        Database::pdo()->prepare('UPDATE av_centres SET status = ?, updated_at = ? WHERE id = ?')->execute([$status, self::now(), $id]);
        self::audit($status === 'retired' ? 'centre.retire' : 'centre.reopen', $c['name'], '', $actor);
        return ['ok' => true, 'centre' => self::get($id)];
    }

    /** Who still uses a centre: members, and the gate's attendance record. */
    public static function uses(string $name): array
    {
        $k = mb_strtolower($name);
        $st = Database::pdo()->prepare('SELECT COUNT(*) FROM member_profiles WHERE LOWER(centre) = ?');
        $st->execute([$k]);
        $out = ['members' => (int) $st->fetchColumn(), 'attendance' => 0];
        try {
            if (Database::tableExists('gate_attendance')) {
                $st = Database::pdo()->prepare('SELECT COUNT(*) FROM gate_attendance WHERE LOWER(centre) = ?');
                $st->execute([$k]);
                $out['attendance'] = (int) $st->fetchColumn();
            }
        } catch (Throwable $e) {}
        return $out;
    }

    /**
     * Delete a centre nothing uses. One in use is refused with what uses it —
     * retiring it is the answer, and keeps every record resolving.
     */
    public static function delete(int $id, string $actor): array
    {
        self::ensure();
        $c = self::get($id);
        if (!$c) return ['ok' => false, 'error' => 'No such centre.'];
        $u = self::uses($c['name']);
        if ($u['members'] > 0 || $u['attendance'] > 0) {
            return ['ok' => false, 'code' => 'centre_in_use', 'uses' => $u, 'error' => $c['name'] . ' is in use — '
                . implode(' and ', array_filter([$u['members'] ? $u['members'] . ' member' . ($u['members'] === 1 ? '' : 's') : '', $u['attendance'] ? $u['attendance'] . ' attendance record' . ($u['attendance'] === 1 ? '' : 's') : '']))
                . '. Retire it instead: its members keep it, and nobody new is put there.'];
        }
        Database::pdo()->prepare('DELETE FROM av_centres WHERE id = ?')->execute([$id]);
        self::audit('centre.delete', $c['name'], '', $actor);
        return ['ok' => true];
    }

    private static function audit(string $action, string $target, string $detail, string $actor): void
    {
        try { if (class_exists('AdminAudit')) AdminAudit::log('members', $action, $target, trim($detail), null, $actor); }
        catch (Throwable $e) {}
    }
}
