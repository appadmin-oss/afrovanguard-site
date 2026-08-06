<?php
/**
 * lib/DiaryTabs.php — tabs inside a diary entry, the way Google Docs has tabs.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHAT A TAB IS HERE, AND WHY THIS IS THE RIGHT FEATURE FOR IT
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * In Google Docs a tab is a named sub-document inside one document: it has its
 * own body, it appears in a rail beside the page, and switching between tabs
 * never leaves the document. That shape was chosen there because some documents
 * are one thing with several parts, and splitting them into separate files loses
 * the fact that they belong together.
 *
 * A diary has exactly that problem, and it has it constantly:
 *
 *   · a weekly review that is really Monday, Tuesday, Wednesday…
 *   · a project log with Notes, Decisions, and What's next
 *   · a trip with a day per tab
 *   · a mentoring session with Agenda, What we discussed, Actions
 *
 * Today each of those is either one long undifferentiated wall of text, or five
 * separate entries that lose the thread. Tabs give the entry an internal
 * structure without inventing a second content type — the entry is still one
 * entry, one date, one place in the notebook, one thing to share.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE FIRST TAB IS THE ENTRY ITSELF
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * `diary_entries.body` is not abandoned and copied into a tab table. It REMAINS
 * the content of the first tab, and this class only stores tabs 2..n.
 *
 * That is the whole design decision, and it is what keeps the change safe:
 * every existing reader — the public feed, the export, the share link, the
 * moderation queue, the promotion-to-article path — keeps working untouched on
 * entries that have no tabs, which is all of them today. An entry only grows a
 * tab table row when somebody actually adds a second tab. Nothing migrates,
 * nothing backfills, and a bug in this file cannot lose an existing entry's
 * text because this file never owns it.
 *
 * The cost is one branch in `all()` — tab 0 is synthesised from the entry — and
 * that is a much smaller price than a migration over everybody's diary.
 */
declare(strict_types=1);

final class DiaryTabs
{
    /** Enough for a day-per-tab fortnight. Past this, it wants to be a notebook. */
    public const MAX_PER_ENTRY = 20;

    private static bool $ready = false;

    private PDO $db;

    public function __construct(?PDO $pdo = null) { $this->db = $pdo ?? Database::pdo(); }

    public static function ensure(): void
    {
        if (self::$ready) return;
        $db  = Database::pdo();
        $drv = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
        $ddl = "
        CREATE TABLE IF NOT EXISTS diary_entry_tabs (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            entry_id   INTEGER NOT NULL,
            title      VARCHAR(80) NOT NULL DEFAULT '',
            body       TEXT NOT NULL DEFAULT '',
            sort       INTEGER NOT NULL DEFAULT 1,
            created_at VARCHAR(32) NOT NULL DEFAULT '',
            updated_at VARCHAR(32) NOT NULL DEFAULT ''
        );
        CREATE INDEX IF NOT EXISTS idx_dtab_entry ON diary_entry_tabs(entry_id, sort);";
        $db->exec($drv === 'sqlite' ? $ddl : Database::translateDDL($ddl, $drv));

        // The name of tab 0. Stored on the entry because tab 0 IS the entry —
        // see the class note. Defaults to empty, which the reader renders as
        // "Entry" rather than a blank tab.
        try {
            if (!Database::columnExists('diary_entries', 'first_tab_title')) {
                $db->exec("ALTER TABLE diary_entries ADD COLUMN first_tab_title VARCHAR(80) NOT NULL DEFAULT ''");
            }
        } catch (Throwable $e) { /* already there */ }

        self::$ready = true;
    }

    private static function now(): string { return gmdate('Y-m-d H:i:s'); }

    private static function cleanTitle(string $t): string
    {
        $t = trim(preg_replace('/\s+/u', ' ', $t) ?? $t);
        return mb_substr($t, 0, 80);
    }

    /** The author of an entry, or 0. */
    private function authorOf(int $entryId): int
    {
        $st = $this->db->prepare('SELECT author_id FROM diary_entries WHERE id = ?');
        $st->execute([$entryId]);
        return (int) ($st->fetchColumn() ?: 0);
    }

    /**
     * Every tab of an entry, in order, including the synthesised first one.
     *
     * @return list<array{id:int,title:string,body:string,sort:int,is_first:bool}>
     */
    public function all(int $entryId): array
    {
        self::ensure();
        $st = $this->db->prepare('SELECT body, first_tab_title FROM diary_entries WHERE id = ?');
        $st->execute([$entryId]);
        $e = $st->fetch(PDO::FETCH_ASSOC);
        if (!$e) return [];

        $out = [[
            'id'       => 0,                                  // 0 means "the entry itself"
            'title'    => (string) ($e['first_tab_title'] ?? '') !== '' ? (string) $e['first_tab_title'] : 'Entry',
            'body'     => (string) $e['body'],
            'sort'     => 0,
            'is_first' => true,
        ]];

        $t = $this->db->prepare('SELECT id, title, body, sort FROM diary_entry_tabs WHERE entry_id = ? ORDER BY sort, id');
        $t->execute([$entryId]);
        foreach ($t->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $out[] = [
                'id'       => (int) $r['id'],
                'title'    => (string) $r['title'] !== '' ? (string) $r['title'] : 'Untitled tab',
                'body'     => (string) $r['body'],
                'sort'     => (int) $r['sort'],
                'is_first' => false,
            ];
        }
        return $out;
    }

    /** How many tabs an entry has, counting the first. Cheap; for list badges. */
    public function countFor(int $entryId): int
    {
        self::ensure();
        $st = $this->db->prepare('SELECT COUNT(*) FROM diary_entry_tabs WHERE entry_id = ?');
        $st->execute([$entryId]);
        return 1 + (int) $st->fetchColumn();
    }

    /**
     * Tab counts for many entries at once.
     *
     * The list view needs a badge per entry and doing that one query at a time
     * is the classic N+1 that turns a 40-entry diary into 41 round trips.
     *
     * @param list<int> $entryIds
     * @return array<int,int> entry id => tab count (1 when it has no extra tabs)
     */
    public function countsFor(array $entryIds): array
    {
        self::ensure();
        $ids = array_values(array_unique(array_map('intval', $entryIds)));
        $out = array_fill_keys($ids, 1);
        if ($ids === []) return $out;
        $in = implode(',', array_fill(0, count($ids), '?'));
        $st = $this->db->prepare("SELECT entry_id, COUNT(*) AS n FROM diary_entry_tabs WHERE entry_id IN ($in) GROUP BY entry_id");
        $st->execute($ids);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $out[(int) $r['entry_id']] = 1 + (int) $r['n'];
        }
        return $out;
    }

    /**
     * Add a tab. Only the entry's author may.
     *
     * @return array{ok:bool,id?:int,tab?:array,error?:string}
     */
    public function add(int $userId, int $entryId, string $title = '', string $body = ''): array
    {
        self::ensure();
        if ($this->authorOf($entryId) !== $userId) return ['ok' => false, 'error' => 'That entry is not yours.'];

        $st = $this->db->prepare('SELECT COUNT(*) FROM diary_entry_tabs WHERE entry_id = ?');
        $st->execute([$entryId]);
        $have = 1 + (int) $st->fetchColumn();
        if ($have >= self::MAX_PER_ENTRY) {
            return ['ok' => false, 'error' => 'That is ' . self::MAX_PER_ENTRY . ' tabs — this wants to be a notebook, not one entry.'];
        }

        $title = self::cleanTitle($title);
        if ($title === '') $title = 'Tab ' . $have;   // 1-based including the first

        $s = $this->db->prepare('SELECT COALESCE(MAX(sort),0) + 1 FROM diary_entry_tabs WHERE entry_id = ?');
        $s->execute([$entryId]);
        $sort = max(1, (int) $s->fetchColumn());

        $this->db->prepare('INSERT INTO diary_entry_tabs (entry_id,title,body,sort,created_at,updated_at) VALUES (?,?,?,?,?,?)')
                 ->execute([$entryId, $title, $body, $sort, self::now(), self::now()]);
        $id = (int) $this->db->lastInsertId();
        $this->touchEntry($entryId);

        return ['ok' => true, 'id' => $id, 'tab' => ['id' => $id, 'title' => $title, 'body' => $body, 'sort' => $sort, 'is_first' => false]];
    }

    /**
     * Save a tab's title and/or body.
     *
     * `$tabId === 0` addresses the FIRST tab, which lives on the entry. Callers
     * do not need to know which storage a tab is in — that is the point of
     * synthesising tab 0 in `all()`, and it would be undone by making the caller
     * branch here.
     */
    public function save(int $userId, int $entryId, int $tabId, ?string $title = null, ?string $body = null): bool
    {
        self::ensure();
        if ($this->authorOf($entryId) !== $userId) return false;

        if ($tabId === 0) {
            $set = []; $args = [];
            if ($title !== null) { $set[] = 'first_tab_title = ?'; $args[] = self::cleanTitle($title); }
            if ($body !== null)  { $set[] = 'body = ?';            $args[] = $body; }
            if ($set === []) return false;
            $set[] = 'updated_at = ?'; $args[] = self::now();
            $args[] = $entryId; $args[] = $userId;
            $this->db->prepare('UPDATE diary_entries SET ' . implode(', ', $set) . ' WHERE id = ? AND author_id = ?')->execute($args);
            return true;
        }

        $set = []; $args = [];
        if ($title !== null) { $set[] = 'title = ?'; $args[] = self::cleanTitle($title); }
        if ($body !== null)  { $set[] = 'body = ?';  $args[] = $body; }
        if ($set === []) return false;
        $set[] = 'updated_at = ?'; $args[] = self::now();
        $args[] = $tabId; $args[] = $entryId;
        $st = $this->db->prepare('UPDATE diary_entry_tabs SET ' . implode(', ', $set) . ' WHERE id = ? AND entry_id = ?');
        $st->execute($args);
        if ($st->rowCount() > 0) { $this->touchEntry($entryId); return true; }
        return false;
    }

    /**
     * Delete a tab. The first tab cannot be deleted — it is the entry.
     *
     * Deleting an entry is a different, deliberate act with its own button; it
     * must not be reachable by removing the last tab, which is the kind of thing
     * somebody does quickly while tidying.
     */
    public function remove(int $userId, int $entryId, int $tabId): bool
    {
        self::ensure();
        if ($tabId === 0) return false;
        if ($this->authorOf($entryId) !== $userId) return false;
        $st = $this->db->prepare('DELETE FROM diary_entry_tabs WHERE id = ? AND entry_id = ?');
        $st->execute([$tabId, $entryId]);
        if ($st->rowCount() === 0) return false;
        $this->touchEntry($entryId);
        return true;
    }

    /**
     * Reorder. `$order` is tab ids in the wanted order; tab 0 is ignored because
     * the first tab is always first — it is the entry, and an entry whose body
     * is in the middle of its own tab strip is a puzzle, not a document.
     *
     * @param list<int> $order
     */
    public function reorder(int $userId, int $entryId, array $order): bool
    {
        self::ensure();
        if ($this->authorOf($entryId) !== $userId) return false;

        $have = $this->db->prepare('SELECT id FROM diary_entry_tabs WHERE entry_id = ?');
        $have->execute([$entryId]);
        $valid = array_map('intval', array_column($have->fetchAll(PDO::FETCH_ASSOC) ?: [], 'id'));
        if ($valid === []) return false;

        $up = $this->db->prepare('UPDATE diary_entry_tabs SET sort = ? WHERE id = ? AND entry_id = ?');
        $i = 1;
        foreach ($order as $id) {
            $id = (int) $id;
            if ($id === 0 || !in_array($id, $valid, true)) continue;   // ignore tab 0 and anything foreign
            $up->execute([$i++, $id, $entryId]);
        }
        // Anything the caller omitted keeps a stable place at the end rather than
        // collapsing to sort 0 and jumping to the front.
        foreach ($valid as $id) {
            if (!in_array($id, array_map('intval', $order), true)) $up->execute([$i++, $id, $entryId]);
        }
        $this->touchEntry($entryId);
        return true;
    }

    /** Delete every tab of an entry — for when the entry itself goes. */
    public function purge(int $entryId): void
    {
        self::ensure();
        try { $this->db->prepare('DELETE FROM diary_entry_tabs WHERE entry_id = ?')->execute([$entryId]); }
        catch (Throwable $e) { /* best-effort */ }
    }

    private function touchEntry(int $entryId): void
    {
        try { $this->db->prepare('UPDATE diary_entries SET updated_at = ? WHERE id = ?')->execute([self::now(), $entryId]); }
        catch (Throwable $e) {}
    }
}
