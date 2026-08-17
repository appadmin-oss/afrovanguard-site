<?php
/**
 * lib/AvKnowledge.php — the organisation's own knowledge, editable in the Studio.
 *
 * `AiKnowledge` compiles facts the app can DERIVE (programmes, articles, events,
 * dues). But much of what the assistants need to know is not in any table: how
 * Afrovanguard defines an incorruptible lifestyle, what a mentor is expected to
 * cover in a first session, which behaviours matter for advancement, what to say
 * when someone misses three meetings.
 *
 * Historically that knowledge was written into the prompt strings themselves, so
 * changing it meant editing PHP and deploying. Here it becomes DATA: leadership
 * writes entries, tags them with a scope, and the assistants are fed the entries
 * relevant to the job in hand.
 *
 * Scopes let one knowledge base serve several assistants without drowning any of
 * them: an entry scoped `mentorship` reaches the mentorship prompts, `all`
 * reaches everything. Priority orders what survives the size bound, so the most
 * important entries are never the ones truncated away.
 *
 * Every read is fail-safe and every compile is bounded — a long knowledge base
 * must never quietly inflate the token cost of every AI call.
 */
declare(strict_types=1);

final class AvKnowledge
{
    /** Where an entry is allowed to appear. 'all' reaches every assistant. */
    const SCOPES = ['all', 'mentorship', 'meetings', 'levels', 'commitments', 'assistant'];

    /** Hard ceiling on the compiled block, in characters, per scope. */
    private const MAX_CHARS = 4000;

    private static bool $ready = false;
    /** @var array<string,string> per-request memo, keyed by scope */
    private static array $memo = [];

    public static function ensure(): void
    {
        if (self::$ready) return;
        self::$ready = true;
        try {
            $db = Database::pdo();
            Database::execSchema($db, "CREATE TABLE IF NOT EXISTS av_knowledge (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                title VARCHAR(200) NOT NULL DEFAULT '',
                body TEXT NOT NULL DEFAULT '',
                scope VARCHAR(24) NOT NULL DEFAULT 'all',
                priority INTEGER NOT NULL DEFAULT 50,
                active INTEGER NOT NULL DEFAULT 1,
                updated_by VARCHAR(191) NOT NULL DEFAULT '',
                created_at VARCHAR(32) NOT NULL DEFAULT '',
                updated_at VARCHAR(32) NOT NULL DEFAULT ''
            );
            CREATE INDEX IF NOT EXISTS idx_avkb_scope ON av_knowledge(scope, active, priority);");
        } catch (Throwable $e) { error_log('[kb] ensure: ' . $e->getMessage()); }
    }

    private static function scope(string $s): string
    {
        return in_array($s, self::SCOPES, true) ? $s : 'all';
    }

    /* ════════════════════════════════════════════════════════════════
       CRUD
       ════════════════════════════════════════════════════════════════ */

    /**
     * Create or update an entry. Pass id = 0 to create.
     * @return array{ok:bool, id:int, error:string}
     */
    public static function save(int $id, array $fields, string $actor = ''): array
    {
        $title = trim((string) ($fields['title'] ?? ''));
        $body  = trim((string) ($fields['body'] ?? ''));
        if ($title === '') return ['ok' => false, 'id' => 0, 'error' => 'A title is required.'];
        if ($body === '')  return ['ok' => false, 'id' => 0, 'error' => 'The body is empty — there is nothing to teach the assistant.'];
        if (mb_strlen($body) > 4000) {
            return ['ok' => false, 'id' => 0, 'error' => 'Too long (' . mb_strlen($body) . ' chars, max 4000). Split it into focused entries so the assistant can be fed only what is relevant.'];
        }

        $scope    = self::scope((string) ($fields['scope'] ?? 'all'));
        $priority = max(0, min(100, (int) ($fields['priority'] ?? 50)));
        $active   = !empty($fields['active']) ? 1 : 0;
        $now      = gmdate('c');

        try {
            self::ensure();
            $db = Database::pdo();
            if ($id > 0) {
                $st = $db->prepare('UPDATE av_knowledge SET title=?, body=?, scope=?, priority=?, active=?, updated_by=?, updated_at=? WHERE id=?');
                $st->execute([$title, $body, $scope, $priority, $active, $actor, $now, $id]);
                if ($st->rowCount() === 0 && !self::get($id)) {
                    return ['ok' => false, 'id' => 0, 'error' => 'That entry no longer exists.'];
                }
            } else {
                $db->prepare('INSERT INTO av_knowledge (title, body, scope, priority, active, updated_by, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?)')
                   ->execute([$title, $body, $scope, $priority, $active, $actor, $now, $now]);
                $id = (int) $db->lastInsertId();
            }
            self::invalidate();
            return ['ok' => true, 'id' => $id, 'error' => ''];
        } catch (Throwable $e) {
            error_log('[kb] save: ' . $e->getMessage());
            return ['ok' => false, 'id' => 0, 'error' => 'Could not save the entry.'];
        }
    }

    public static function remove(int $id): bool
    {
        if ($id <= 0) return false;
        try {
            self::ensure();
            Database::pdo()->prepare('DELETE FROM av_knowledge WHERE id = ?')->execute([$id]);
            self::invalidate();
            return true;
        } catch (Throwable $e) { error_log('[kb] remove: ' . $e->getMessage()); return false; }
    }

    public static function get(int $id): ?array
    {
        if ($id <= 0) return null;
        try {
            self::ensure();
            $st = Database::pdo()->prepare('SELECT * FROM av_knowledge WHERE id = ?');
            $st->execute([$id]);
            $r = $st->fetch(PDO::FETCH_ASSOC);
            return $r ? self::row($r) : null;
        } catch (Throwable $e) { return null; }
    }

    /** Every entry, newest-relevant first, for the Studio list. */
    public static function listAll(string $scope = ''): array
    {
        try {
            self::ensure();
            $sql = 'SELECT * FROM av_knowledge';
            $args = [];
            if ($scope !== '' && in_array($scope, self::SCOPES, true)) { $sql .= ' WHERE scope = ?'; $args[] = $scope; }
            $sql .= ' ORDER BY active DESC, priority DESC, title ASC';
            $st = Database::pdo()->prepare($sql);
            $st->execute($args);
            return array_map([self::class, 'row'], $st->fetchAll(PDO::FETCH_ASSOC) ?: []);
        } catch (Throwable $e) { error_log('[kb] list: ' . $e->getMessage()); return []; }
    }

    private static function row(array $r): array
    {
        return [
            'id'         => (int) $r['id'],
            'title'      => (string) $r['title'],
            'body'       => (string) $r['body'],
            'scope'      => (string) $r['scope'],
            'priority'   => (int) $r['priority'],
            'active'     => ((int) $r['active']) === 1,
            'updated_by' => (string) ($r['updated_by'] ?? ''),
            'updated_at' => (string) ($r['updated_at'] ?? ''),
        ];
    }

    /* ════════════════════════════════════════════════════════════════
       Compilation into a prompt block
       ════════════════════════════════════════════════════════════════ */

    /**
     * Active entries for a scope, highest priority first, as a bounded block.
     * `all`-scoped entries are always included alongside the requested scope.
     *
     * Truncation drops whole entries rather than cutting one mid-sentence — half
     * an instruction is worse than none, because the model will still act on it.
     */
    public static function asPromptBlock(string $scope = 'all'): string
    {
        $scope = self::scope($scope);
        if (isset(self::$memo[$scope])) return self::$memo[$scope];

        $text = '';
        try {
            self::ensure();
            $st = Database::pdo()->prepare(
                'SELECT title, body FROM av_knowledge WHERE active = 1 AND (scope = ? OR scope = ?) ORDER BY priority DESC, id ASC'
            );
            $st->execute([$scope, 'all']);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

            $parts = [];
            $used  = 0;
            foreach ($rows as $r) {
                $entry = '• ' . trim((string) $r['title']) . ': ' . trim((string) $r['body']);
                $len = mb_strlen($entry) + 1;
                if ($used + $len > self::MAX_CHARS) continue;   // skip, don't cut
                $parts[] = $entry;
                $used += $len;
            }
            if ($parts) {
                $text = "\n\nAFROVANGUARD KNOWLEDGE (written by leadership — authoritative; prefer it over your own assumptions):\n"
                      . implode("\n", $parts);
            }
        } catch (Throwable $e) {
            error_log('[kb] promptBlock: ' . $e->getMessage());
        }
        return self::$memo[$scope] = $text;
    }

    /** How many active entries a scope would contribute (for the Studio). */
    public static function countActive(string $scope = 'all'): int
    {
        try {
            self::ensure();
            $st = Database::pdo()->prepare('SELECT COUNT(*) FROM av_knowledge WHERE active = 1 AND (scope = ? OR scope = ?)');
            $st->execute([self::scope($scope), 'all']);
            return (int) $st->fetchColumn();
        } catch (Throwable $e) { return 0; }
    }

    /**
     * Undo primitive for the audit layer (AdminAudit::undo) — restores a deleted
     * entry. It comes back as a NEW row: the original id may have been reused, and
     * resurrecting an id would risk overwriting an unrelated entry.
     */
    public static function applyUndo(string $op, array $args): bool
    {
        if ($op !== 'restore') return false;
        $f = (array) ($args['fields'] ?? []);
        if (trim((string) ($f['title'] ?? '')) === '') return false;
        $res = self::save(0, $f, 'undo');
        return !empty($res['ok']);
    }

    public static function invalidate(): void
    {
        self::$memo = [];
        try { if (class_exists('AiKnowledge')) AiKnowledge::invalidate(); } catch (Throwable $e) {}
    }
}
