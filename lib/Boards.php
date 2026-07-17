<?php
/**
 * lib/Boards.php — a shared team Kanban board.
 *
 * One board for the org with three columns (To do / Doing / Done). Members add
 * cards, move them across columns, and clear them. Portable DB layer.
 */
declare(strict_types=1);

final class Boards
{
    /** Canonical columns, in order. */
    public const COLS = ['todo' => 'To do', 'doing' => 'Doing', 'done' => 'Done'];

    public static function ensure(): void
    {
        static $done = false;
        if ($done) return;
        $db = Database::pdo();
        $ddl = "CREATE TABLE IF NOT EXISTS team_cards (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            author_id INTEGER NOT NULL DEFAULT 0,
            col VARCHAR(12) NOT NULL DEFAULT 'todo',
            title VARCHAR(300) NOT NULL DEFAULT '',
            position INTEGER NOT NULL DEFAULT 0,
            created_at VARCHAR(32) NOT NULL DEFAULT ''
        );
        CREATE INDEX IF NOT EXISTS idx_cards_col ON team_cards(col);";
        $drv = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
        $db->exec($drv === 'sqlite' ? $ddl : Database::translateDDL($ddl, $drv));
        $done = true;
    }

    private static function validCol(string $c): bool { return isset(self::COLS[$c]); }

    /** Add a card to a column. Returns new id or 0. */
    public static function add(int $authorId, string $title, string $col = 'todo'): int
    {
        self::ensure();
        $title = trim(mb_substr(trim($title), 0, 300));
        if (!self::validCol($col)) $col = 'todo';
        if ($authorId <= 0 || $title === '') return 0;
        $db = Database::pdo();
        $pos = (int) $db->query("SELECT COALESCE(MAX(position),0)+1 FROM team_cards WHERE col = " . $db->quote($col))->fetchColumn();
        $db->prepare('INSERT INTO team_cards (author_id, col, title, position, created_at) VALUES (?,?,?,?,?)')
            ->execute([$authorId, $col, $title, $pos, gmdate('Y-m-d H:i:s')]);
        return (int) $db->lastInsertId();
    }

    /** Move a card to another column (appends to the end). */
    public static function move(int $cardId, string $col): bool
    {
        self::ensure();
        if ($cardId <= 0 || !self::validCol($col)) return false;
        $db = Database::pdo();
        $pos = (int) $db->query("SELECT COALESCE(MAX(position),0)+1 FROM team_cards WHERE col = " . $db->quote($col))->fetchColumn();
        $st = $db->prepare('UPDATE team_cards SET col = ?, position = ? WHERE id = ?');
        $st->execute([$col, $pos, $cardId]);
        return $st->rowCount() > 0;
    }

    /** Rename a card's title (author only). */
    public static function rename(int $uid, int $cardId, string $title): bool
    {
        self::ensure();
        $title = trim(mb_substr(trim($title), 0, 300));
        if ($uid <= 0 || $cardId <= 0 || $title === '') return false;
        $st = Database::pdo()->prepare('UPDATE team_cards SET title = ? WHERE id = ? AND author_id = ?');
        $st->execute([$title, $cardId, $uid]);
        return $st->rowCount() > 0;
    }

    /** Delete a card (author only). Moving stays open to the whole team. */
    public static function remove(int $uid, int $cardId): bool
    {
        self::ensure();
        if ($uid <= 0 || $cardId <= 0) return false;
        $st = Database::pdo()->prepare('DELETE FROM team_cards WHERE id = ? AND author_id = ?');
        $st->execute([$cardId, $uid]);
        return $st->rowCount() > 0;
    }

    /** The whole board grouped by column, with counts. */
    public static function board(int $viewerId = 0): array
    {
        self::ensure();
        $db = Database::pdo();
        $cols = [];
        foreach (self::COLS as $key => $label) $cols[$key] = ['key' => $key, 'label' => $label, 'cards' => []];
        try {
            $rows = $db->query(
                'SELECT c.*, u.name AS author FROM team_cards c LEFT JOIN lms_users u ON u.id = c.author_id
                 ORDER BY c.position ASC, c.id ASC'
            )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) { $rows = []; }
        foreach ($rows as $r) {
            $key = (string) $r['col'];
            if (!isset($cols[$key])) $key = 'todo';
            $cols[$key]['cards'][] = [
                'id'     => (int) $r['id'],
                'title'  => (string) $r['title'],
                'author' => (string) ($r['author'] ?: 'A member'),
                'mine'   => (int) $r['author_id'] === $viewerId,
            ];
        }
        return array_values($cols);
    }
}
