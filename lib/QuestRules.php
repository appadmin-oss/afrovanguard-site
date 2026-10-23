<?php
/**
 * lib/QuestRules.php — every priced line of the Vanguard Quest, switchable.
 *
 * The registry (lib/quest/*.json, vendored from van-quest) carries the Pyramid
 * Manual's prices: sixteen awardable actions and "mentee rises", seven point
 * deductions, thirty-one fines. The owner's instruction on 22 October 2026 was
 * that every rule must be toggleable, so each line can be switched off or
 * re-priced here without touching the registry — the registry stays the
 * manual's word, and this table records where leadership departed from it,
 * who did, and when.
 *
 * The Quest's GLOBAL switches (deductions on, monthly ceilings, the pyramid's
 * floors, money) are AvRules entries in the "Vanguard Quest" group, so they
 * share the Studio's validation, undo and audit with every other rule. What is
 * here is the per-line layer AvRules has no shape for.
 *
 * Reads are fail-safe: a broken table yields the registry's prices, never an
 * exception into the request.
 */
declare(strict_types=1);

final class QuestRules
{
    /** kind => [registry name, list key, value key, ceiling for a re-price] */
    public const KINDS = [
        'award'     => ['awardable-actions', 'actions',    'points', 1000],
        'deduction' => ['deductions',        'deductions', 'points', 1000],
        'fine'      => ['fines',             'fines',      'amount', 100000],
    ];

    private static bool $ready = false;
    /** @var array<string,array<string,array>>|null */
    private static ?array $memo = null;

    public static function ensure(): void
    {
        if (self::$ready) return;
        self::$ready = true;
        try {
            $db = Database::pdo();
            Database::execSchema($db, "CREATE TABLE IF NOT EXISTS quest_rule_items (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                kind       VARCHAR(12) NOT NULL,
                item_id    VARCHAR(40) NOT NULL,
                enabled    INTEGER NOT NULL DEFAULT 1,
                value      INTEGER,
                note       VARCHAR(300) NOT NULL DEFAULT '',
                updated_by INTEGER NOT NULL DEFAULT 0,
                updated_at VARCHAR(32) NOT NULL DEFAULT ''
            )");
            Database::ensureIndex($db, 'ux_quest_rule_item', 'quest_rule_items', 'kind, item_id', true);
            Database::execSchema($db, "CREATE TABLE IF NOT EXISTS quest_rule_log (
                id       INTEGER PRIMARY KEY AUTOINCREMENT,
                kind     VARCHAR(12) NOT NULL,
                item_id  VARCHAR(40) NOT NULL,
                what     VARCHAR(200) NOT NULL DEFAULT '',
                note     VARCHAR(300) NOT NULL DEFAULT '',
                by_uid   INTEGER NOT NULL DEFAULT 0,
                done_at  VARCHAR(32) NOT NULL DEFAULT ''
            )");
        } catch (Throwable $e) {
            error_log('[questrules] ensure: ' . $e->getMessage());
        }
    }

    /** Leadership's call, not the committee's: prices are policy. */
    public static function canEdit(?array $user): bool
    {
        return class_exists('Conduct') && Conduct::isSuperior($user);
    }

    /** Drop the per-request cache. */
    public static function forget(): void { self::$memo = null; }

    /**
     * Every line of one kind, effective: the registry's row, plus
     * `base` (the manual's price), `value` (what applies now), `enabled`,
     * `overridden` and `override_note`. In registry order.
     * @return array<string,array>
     */
    public static function items(string $kind): array
    {
        if (!isset(self::KINDS[$kind])) return [];
        if (self::$memo !== null && isset(self::$memo[$kind])) return self::$memo[$kind];
        [, , $valueKey] = self::KINDS[$kind];
        $rows = match ($kind) {
            'award'     => Quest::awards(),
            'deduction' => Quest::deductions(),
            'fine'      => Quest::fines(),
        };
        $over = self::overrides($kind);
        $out = [];
        foreach ($rows as $id => $r) {
            $base = isset($r[$valueKey]) && $r[$valueKey] !== null ? (int) $r[$valueKey] : null;
            $o = $over[$id] ?? null;
            $r['base'] = $base;
            $r['value'] = ($o !== null && $o['value'] !== null) ? (int) $o['value'] : $base;
            $r['enabled'] = $o === null ? true : (bool) $o['enabled'];
            $r['overridden'] = $o !== null && (!$o['enabled'] || ($o['value'] !== null && (int) $o['value'] !== $base));
            $r['override_note'] = $o['note'] ?? '';
            $out[(string) $id] = $r;
        }
        self::$memo ??= [];
        return self::$memo[$kind] = $out;
    }

    /** One line, effective, or null when it is not in the registry. */
    public static function item(string $kind, string $id): ?array
    {
        return self::items($kind)[$id] ?? null;
    }

    /** One line, only if it may be used right now. */
    public static function usable(string $kind, string $id): ?array
    {
        $i = self::item($kind, $id);
        return $i !== null && $i['enabled'] ? $i : null;
    }

    /** @return array<string,array{enabled:int,value:?int,note:string}> */
    private static function overrides(string $kind): array
    {
        self::ensure();
        $out = [];
        try {
            $st = Database::pdo()->prepare('SELECT item_id, enabled, value, note FROM quest_rule_items WHERE kind = ?');
            $st->execute([$kind]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
                $out[(string) $r['item_id']] = [
                    'enabled' => (int) $r['enabled'],
                    'value'   => $r['value'] === null ? null : (int) $r['value'],
                    'note'    => (string) $r['note'],
                ];
            }
        } catch (Throwable $e) { /* the registry's prices stand */ }
        return $out;
    }

    /**
     * Switch a line on or off, re-price it, or both. A price equal to the
     * manual's is stored as "no override" so the line reads as the manual's.
     *
     * @param array{enabled?:bool, value?:int|null} $change
     */
    public static function set(string $kind, string $id, array $change, array $by, string $note = ''): array
    {
        if (!self::canEdit($by)) return ['ok' => false, 'error' => 'Only a superadmin changes the Quest\'s prices and switches.'];
        $cur = self::item($kind, $id);
        if ($cur === null) return ['ok' => false, 'error' => 'There is no such line in the catalogue.'];
        $cap = self::KINDS[$kind][3];

        $enabled = array_key_exists('enabled', $change) ? (bool) $change['enabled'] : (bool) $cur['enabled'];
        $value = array_key_exists('value', $change) ? $change['value'] : $cur['value'];
        if ($value !== null) {
            $value = (int) $value;
            $min = $kind === 'fine' ? 0 : 1;
            if ($value < $min || $value > $cap) {
                return ['ok' => false, 'error' => 'A price must be between ' . $min . ' and ' . number_format($cap) . '.'];
            }
        }
        $stored = ($value === $cur['base']) ? null : $value;
        $note = mb_substr(trim($note), 0, 300);

        $words = [];
        if ($enabled !== (bool) $cur['enabled']) $words[] = $enabled ? 'switched on' : 'switched off';
        if ($value !== $cur['value']) $words[] = 'price ' . self::fmt($kind, $cur['value']) . ' → ' . self::fmt($kind, $value);
        if (!$words) return ['ok' => true, 'unchanged' => true];

        self::ensure();
        try {
            $db = Database::pdo();
            $now = gmdate('Y-m-d H:i:s');
            if ($enabled && $stored === null) {
                /* Back to the manual: no override row at all. */
                $db->prepare('DELETE FROM quest_rule_items WHERE kind = ? AND item_id = ?')->execute([$kind, $id]);
            } else {
                $db->prepare(Database::insertIgnore('quest_rule_items', ['kind', 'item_id', 'enabled', 'value', 'note', 'updated_by', 'updated_at']))
                   ->execute([$kind, $id, $enabled ? 1 : 0, $stored, $note, (int) $by['id'], $now]);
                $db->prepare('UPDATE quest_rule_items SET enabled = ?, value = ?, note = ?, updated_by = ?, updated_at = ? WHERE kind = ? AND item_id = ?')
                   ->execute([$enabled ? 1 : 0, $stored, $note, (int) $by['id'], $now, $kind, $id]);
            }
            $db->prepare('INSERT INTO quest_rule_log (kind, item_id, what, note, by_uid, done_at) VALUES (?,?,?,?,?,?)')
               ->execute([$kind, $id, implode('; ', $words), $note, (int) $by['id'], $now]);
        } catch (Throwable $e) {
            error_log('[questrules] set: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'That could not be saved.'];
        }
        self::forget();
        if (class_exists('AdminAudit')) {
            try { AdminAudit::log('quest', 'rule', $kind . ':' . $id, implode('; ', $words), null, 'user#' . (int) $by['id']); }
            catch (Throwable $e) { /* best-effort */ }
        }
        return ['ok' => true, 'item' => self::item($kind, $id), 'change' => implode('; ', $words)];
    }

    /** Back to the manual's price, switched on. */
    public static function reset(string $kind, string $id, array $by): array
    {
        $cur = self::item($kind, $id);
        if ($cur === null) return ['ok' => false, 'error' => 'There is no such line in the catalogue.'];
        return self::set($kind, $id, ['enabled' => true, 'value' => $cur['base']], $by, 'Back to the manual.');
    }

    /** @return array<int,array> newest first */
    public static function history(int $limit = 50, string $kind = '', string $id = ''): array
    {
        self::ensure();
        try {
            $sql = 'SELECT l.*, u.name AS by_name FROM quest_rule_log l LEFT JOIN lms_users u ON u.id = l.by_uid';
            $w = []; $a = [];
            if ($kind !== '') { $w[] = 'l.kind = ?'; $a[] = $kind; }
            if ($id !== '') { $w[] = 'l.item_id = ?'; $a[] = $id; }
            if ($w) $sql .= ' WHERE ' . implode(' AND ', $w);
            $sql .= ' ORDER BY l.id DESC LIMIT ' . max(1, min(500, $limit));
            $st = Database::pdo()->prepare($sql);
            $st->execute($a);
            return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) { return []; }
    }

    /** How far the live catalogue has moved from the manual. */
    public static function drift(): array
    {
        $out = [];
        foreach (array_keys(self::KINDS) as $k) {
            $items = self::items($k);
            $out[$k] = [
                'total'    => count($items),
                'off'      => count(array_filter($items, static fn($i) => !$i['enabled'])),
                'repriced' => count(array_filter($items, static fn($i) => $i['value'] !== $i['base'])),
            ];
        }
        return $out;
    }

    private static function fmt(string $kind, ?int $v): string
    {
        if ($v === null) return 'priced each time';
        return $kind === 'fine' ? '₦' . number_format($v) : $v . ' pts';
    }
}
