<?php
/**
 * lib/AdminAudit.php — one activity trail for admin actions, per area
 * (mentorship, academy, members, …), with reversible UNDO.
 *
 * Writes to the existing lms_audit table (so the Diary/Academy audit entries
 * already written via LmsRepository::audit() show up in the same trail), adding
 * area + an optional undo payload. UNDO dispatches to the owning domain class's
 * static applyUndo($op, $args) — only whitelisted classes can be called.
 */
declare(strict_types=1);

final class AdminAudit
{
    /** Domain classes allowed to receive an undo dispatch. */
    private const UNDOABLE = ['Mentorship'];

    private static bool $ready = false;

    private static function ensure(): void
    {
        if (self::$ready) return;
        $db = Database::pdo();
        // The table itself is created by LmsRepository; create defensively too.
        try {
            $db->exec("CREATE TABLE IF NOT EXISTS lms_audit (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                actor TEXT NOT NULL DEFAULT 'admin', action TEXT NOT NULL,
                target TEXT NOT NULL DEFAULT '', detail TEXT NOT NULL DEFAULT '',
                ip TEXT NOT NULL DEFAULT '', created_at TEXT NOT NULL DEFAULT (datetime('now'))
            )");
        } catch (Throwable $e) { /* MySQL/Postgres: table managed elsewhere */ }
        foreach ([['area', "VARCHAR(24) NOT NULL DEFAULT 'admin'"], ['undo', 'TEXT'], ['undone', 'INTEGER NOT NULL DEFAULT 0']] as [$col, $type]) {
            // `undo` is a RESERVED word in MySQL/MariaDB — quote every identifier so
            // the ADD COLUMN (and the reads/writes below) don't 1064 on those engines.
            try { if (!Database::columnExists('lms_audit', $col)) $db->exec('ALTER TABLE lms_audit ADD COLUMN ' . Database::quoteIdent($col) . ' ' . $type); }
            catch (Throwable $e) { /* exists / driver quirk */ }
        }
        self::$ready = true;
    }

    /**
     * Record an admin action.
     * $undo (optional): ['class'=>'Mentorship','op'=>'pair_status','args'=>[...],'label'=>'Re-open pairing']
     */
    public static function log(string $area, string $action, string $target = '', string $detail = '', ?array $undo = null, string $actor = 'admin'): void
    {
        self::ensure();
        $ip = function_exists('av_client_ip') ? av_client_ip() : '';
        $undoJson = ($undo && in_array($undo['class'] ?? '', self::UNDOABLE, true)) ? json_encode($undo, JSON_UNESCAPED_SLASHES) : null;
        try {
            $U = Database::quoteIdent('undo'); // reserved word in MySQL/MariaDB
            Database::pdo()->prepare("INSERT INTO lms_audit (actor, action, target, detail, ip, area, {$U}, undone) VALUES (?,?,?,?,?,?,?,0)")
                ->execute([$actor ?: 'admin', $action, mb_substr($target, 0, 300), mb_substr($detail, 0, 600), $ip, mb_substr($area, 0, 24), $undoJson]);
        } catch (Throwable $e) { error_log('[adminaudit] write skipped: ' . $e->getMessage()); }
    }

    /** Recent entries, newest first; optional area filter. */
    public static function recent(string $area = '', int $limit = 80): array
    {
        self::ensure();
        $limit = max(1, min(300, $limit));
        $sql = 'SELECT id, actor, action, target, detail, area, ' . Database::quoteIdent('undo') . ', undone, created_at FROM lms_audit';
        $args = [];
        if ($area !== '' && $area !== 'all') { $sql .= ' WHERE area = ?'; $args[] = $area; }
        $sql .= ' ORDER BY id DESC LIMIT ' . $limit;
        $st = Database::pdo()->prepare($sql); $st->execute($args);
        return array_map(function ($r) {
            $undo = $r['undo'] ? json_decode((string) $r['undo'], true) : null;
            return [
                'id' => (int) $r['id'], 'actor' => (string) $r['actor'], 'action' => (string) $r['action'],
                'target' => (string) $r['target'], 'detail' => (string) $r['detail'], 'area' => (string) ($r['area'] ?? 'admin'),
                'created_at' => (string) $r['created_at'], 'undone' => (int) ($r['undone'] ?? 0),
                'can_undo' => (bool) ($undo && empty($r['undone'])), 'undo_label' => is_array($undo) ? (string) ($undo['label'] ?? 'Undo') : '',
            ];
        }, $st->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /** Distinct areas present in the trail (for the filter chips). */
    public static function areas(): array
    {
        self::ensure();
        try { return array_values(array_filter(array_map(fn($r) => (string) $r['area'], Database::pdo()->query('SELECT DISTINCT area FROM lms_audit')->fetchAll(PDO::FETCH_ASSOC) ?: []))); }
        catch (Throwable $e) { return []; }
    }

    /** Reverse a previously-logged action. Returns ['ok'=>bool,'error'?]. */
    public static function undo(int $id, string $actor = 'admin'): array
    {
        self::ensure();
        $st = Database::pdo()->prepare('SELECT * FROM lms_audit WHERE id = ?'); $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) return ['ok' => false, 'error' => 'Entry not found.'];
        if (!empty($row['undone'])) return ['ok' => false, 'error' => 'Already undone.'];
        $undo = $row['undo'] ? json_decode((string) $row['undo'], true) : null;
        if (!is_array($undo)) return ['ok' => false, 'error' => 'This action can’t be undone.'];
        $class = (string) ($undo['class'] ?? '');
        if (!in_array($class, self::UNDOABLE, true) || !class_exists($class) || !method_exists($class, 'applyUndo')) {
            return ['ok' => false, 'error' => 'This action can’t be undone.'];
        }
        $ok = false;
        try { $ok = (bool) $class::applyUndo((string) ($undo['op'] ?? ''), (array) ($undo['args'] ?? [])); }
        catch (Throwable $e) { error_log('[adminaudit] undo: ' . $e->getMessage()); return ['ok' => false, 'error' => 'Undo failed.']; }
        if (!$ok) return ['ok' => false, 'error' => 'Undo failed.'];
        Database::pdo()->prepare('UPDATE lms_audit SET undone = 1 WHERE id = ?')->execute([$id]);
        self::log((string) ($row['area'] ?? 'admin'), 'undo', (string) $row['action'], 'Reverted: ' . (string) $row['detail'], null, $actor);
        return ['ok' => true];
    }
}
