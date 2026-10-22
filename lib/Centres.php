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
 *
 * ── CACENTRE DECIDES THE PLACES ────────────────────────────────────────────
 * CACENTRE's locations list (its Attendance → Desks and settings → Locations)
 * is the one list of places. The gate passes it here (integrations/
 * cacentre-gate.php, {op:"locations"}) whenever it changes, and
 * syncFromCacentre() applies it: each location is linked to a centre by its
 * id (cac_id), which stays when the name changes — a centre already here
 * under that name or a former one is linked; a new location becomes a centre;
 * a renamed one is renamed, with its members; one CACENTRE took off is
 * retired. Once the list has arrived, a centre is not added, renamed, retired
 * or deleted here by hand: the Studio says where to do it. The code is still
 * the portal's own.
 *
 * Each location's calendar — the days it meets, its terms, its holidays —
 * comes with it (cac_calendar), and the gate's absence sweep counts a
 * member's days by their centre's calendar (GateAttendance::isProgrammeDayFor).
 */
declare(strict_types=1);

final class Centres
{
    public const NAME_MAX = 60;
    public const MAX = 200;
    public const CAC_META = 'av_cac_locations';
    private const WHERE = 'Centres are CACENTRE’s locations: add, rename or close one in CACENTRE → Attendance → Desks and settings → Locations, and it arrives here within minutes.';

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
        /* Linked to a CACENTRE location (syncFromCacentre). */
        foreach (['cac_id' => "VARCHAR(16) NOT NULL DEFAULT ''", 'cac_calendar' => 'TEXT', 'cac_synced_at' => "VARCHAR(32) NOT NULL DEFAULT ''"] as $col => $decl) {
            if (!Database::columnExists('av_centres', $col)) {
                try { Database::pdo()->exec("ALTER TABLE av_centres ADD COLUMN {$col} {$decl}"); }
                catch (Throwable $e) { error_log('[centres] add ' . $col . ': ' . $e->getMessage()); }
            }
        }
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
        $sql = 'SELECT id, code, name, status, created_at, updated_at, cac_id, cac_calendar, cac_synced_at FROM av_centres'
             . ($withRetired ? '' : " WHERE status = 'active'") . ' ORDER BY name';
        return array_map(static function ($r) {
            $cal = json_decode((string) ($r['cac_calendar'] ?? ''), true);
            return ['id' => (int) $r['id'], 'code' => (string) $r['code'], 'name' => (string) $r['name'],
                'status' => (string) $r['status'], 'created_at' => (string) $r['created_at'], 'updated_at' => (string) $r['updated_at'],
                'cac_id' => (string) ($r['cac_id'] ?? ''), 'calendar' => is_array($cal) ? $cal : null, 'cac_synced_at' => (string) ($r['cac_synced_at'] ?? '')];
        }, Database::pdo()->query($sql)->fetchAll(PDO::FETCH_ASSOC));
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
        /* CACENTRE decides the places: a name typed on a member is not a new one. */
        if (self::cacentreActive() || count(self::rows(true)) >= self::MAX) return $name;
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
        return '“' . self::clean($name) . '” is not a centre. ' . (self::cacentreActive()
            ? 'Centres are CACENTRE’s locations: add it there, under Attendance → Desks and settings → Locations.'
            : 'Add it in Members → Centres first.');
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
        if (!$id && self::cacentreActive()) return ['ok' => false, 'code' => 'managed_by_cacentre', 'error' => self::WHERE];
        $linked = $id ? self::get($id) : null;
        if ($linked && $linked['cac_id'] !== '' && $name !== $linked['name']) {
            return ['ok' => false, 'code' => 'managed_by_cacentre', 'error' => $linked['name'] . ' is a CACENTRE location. Rename it there; the portal follows.'];
        }
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
        if ($c['cac_id'] !== '') return ['ok' => false, 'code' => 'managed_by_cacentre', 'error' => 'Whether ' . $c['name'] . ' is open is decided in CACENTRE. ' . self::WHERE];
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
        if ($c['cac_id'] !== '') return ['ok' => false, 'code' => 'managed_by_cacentre', 'error' => $c['name'] . ' is a CACENTRE location. Take it off CACENTRE’s list and it is retired here.'];
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

    /* ══ CACENTRE's locations ═══════════════════════════════════════════════ */

    /** Has CACENTRE's list arrived? From then on it decides the places. */
    public static function cacentreActive(): bool
    {
        $v = json_decode((string) (Database::metaGet(self::CAC_META) ?? ''), true);
        return is_array($v) && (string) ($v['version'] ?? '') !== '';
    }

    /** What the Studio shows about the list: its version and when it came. */
    public static function cacentreState(): ?array
    {
        $v = json_decode((string) (Database::metaGet(self::CAC_META) ?? ''), true);
        return is_array($v) && (string) ($v['version'] ?? '') !== '' ? $v : null;
    }

    /** A centre's calendar from CACENTRE, by any spelling of its name; null when it has none. */
    public static function calendarFor(string $name): ?array
    {
        self::ensure();
        $k = mb_strtolower(self::clean($name));
        if ($k === '') return null;
        foreach (self::rows(true) as $c) if (mb_strtolower($c['name']) === $k) return $c['calendar'];
        return null;
    }

    /**
     * Apply CACENTRE's list. Checked whole before anything is written; then
     * one transaction and one audit line.
     *
     * @param array{version?:string,tz?:string,locations?:array} $in
     */
    public static function syncFromCacentre(array $in): array
    {
        self::ensure();
        $version = mb_substr(trim((string) ($in['version'] ?? '')), 0, 64);
        $list = is_array($in['locations'] ?? null) ? array_slice($in['locations'], 0, self::MAX) : null;
        if ($version === '' || $list === null) return ['ok' => false, 'error' => 'A version and a list of locations are needed.'];
        $clean = []; $seen = [];
        foreach ($list as $l) {
            $id = (string) ($l['id'] ?? ''); $name = self::clean((string) ($l['name'] ?? ''));
            if (!preg_match('/^L[0-9a-f]{10}$/', $id) || mb_strlen($name) < 2 || isset($seen[$id])) return ['ok' => false, 'error' => 'The list has a location with no id or name, or one listed twice. Nothing was changed.'];
            $seen[$id] = true;
            $clean[] = ['id' => $id, 'name' => $name, 'status' => (string) ($l['status'] ?? '') === 'retired' ? 'retired' : 'active',
                        'aliases' => array_values(array_filter(array_map(static fn($a) => self::clean((string) $a), (array) ($l['aliases'] ?? [])))),
                        'calendar' => is_array($l['calendar'] ?? null) ? $l['calendar'] : null];
        }
        $pdo = Database::pdo();
        $report = ['created' => [], 'linked' => [], 'renamed' => [], 'retired' => [], 'reopened' => [], 'members' => 0];
        $pdo->beginTransaction();
        try {
            foreach ($clean as $l) {
                $all = self::rows(true);
                $c = null;
                foreach ($all as $r) if ($r['cac_id'] === $l['id']) $c = $r;
                if (!$c) {
                    foreach (array_merge([$l['name']], $l['aliases']) as $n) {
                        foreach ($all as $r) if ($r['cac_id'] === '' && mb_strtolower($r['name']) === mb_strtolower($n)) { $c = $r; break 2; }
                    }
                    if ($c) { $pdo->prepare('UPDATE av_centres SET cac_id = ? WHERE id = ?')->execute([$l['id'], $c['id']]); $report['linked'][] = $l['name']; }
                }
                if (!$c && $l['status'] === 'retired') continue;
                $cal = $l['calendar'] ? json_encode($l['calendar'], JSON_UNESCAPED_SLASHES) : ($c && $c['calendar'] ? json_encode($c['calendar'], JSON_UNESCAPED_SLASHES) : '');
                if (!$c) {
                    $pdo->prepare('INSERT INTO av_centres (code, name, status, created_at, updated_at, cac_id, cac_calendar, cac_synced_at) VALUES (?,?,?,?,?,?,?,?)')
                        ->execute([self::suggestCode($l['name']), $l['name'], 'active', self::now(), self::now(), $l['id'], $cal, self::now()]);
                    $report['created'][] = $l['name'];
                    continue;
                }
                $name = $c['name'];
                if ($l['status'] === 'active' && $c['name'] !== $l['name']) {
                    foreach ($all as $r) if ($r['id'] !== $c['id'] && mb_strtolower($r['name']) === mb_strtolower($l['name'])) {
                        throw new RuntimeException('The portal already has another centre called ' . $l['name'] . '. Rename it in Members → Centres; the list is taken on the next try.');
                    }
                    $st = $pdo->prepare('UPDATE member_profiles SET centre = ? WHERE LOWER(centre) = ?');
                    $st->execute([$l['name'], mb_strtolower($c['name'])]);
                    $report['members'] += $st->rowCount();
                    $report['renamed'][] = $c['name'] . ' → ' . $l['name'];
                    $name = $l['name'];
                }
                $status = $l['status'];
                if ($status !== $c['status']) $report[$status === 'retired' ? 'retired' : 'reopened'][] = $l['name'];
                $pdo->prepare('UPDATE av_centres SET name = ?, status = ?, cac_calendar = ?, cac_synced_at = ?, updated_at = ? WHERE id = ?')
                    ->execute([$name, $status, $cal, self::now(), ($name !== $c['name'] || $status !== $c['status']) ? self::now() : $c['updated_at'], $c['id']]);
            }
            Database::metaSet(self::CAC_META, json_encode(['version' => $version, 'tz' => (string) ($in['tz'] ?? ''), 'at' => gmdate('Y-m-d\TH:i:s\Z')]));
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            return ['ok' => false, 'error' => $e->getMessage()];
        }
        if ($report['created'] || $report['renamed'] || $report['retired'] || $report['reopened'] || $report['linked']) {
            self::audit('centre.cacentre', 'CACENTRE ' . $version, implode('; ', array_filter([
                $report['created'] ? 'added ' . implode(', ', $report['created']) : '',
                $report['linked'] ? 'linked ' . implode(', ', $report['linked']) : '',
                $report['renamed'] ? 'renamed ' . implode(', ', $report['renamed']) . ' (' . $report['members'] . ' members moved)' : '',
                $report['retired'] ? 'retired ' . implode(', ', $report['retired']) : '',
                $report['reopened'] ? 'reopened ' . implode(', ', $report['reopened']) : '',
            ])), 'cacentre');
        }
        return ['ok' => true, 'version' => $version] + $report;
    }

    private static function audit(string $action, string $target, string $detail, string $actor): void
    {
        try { if (class_exists('AdminAudit')) AdminAudit::log('members', $action, $target, trim($detail), null, $actor); }
        catch (Throwable $e) {}
    }
}
