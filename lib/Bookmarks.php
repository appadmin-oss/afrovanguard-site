<?php
/**
 * lib/Bookmarks.php — a shared team link hub.
 *
 * Members save useful links (title, URL, short note); everyone sees the shared
 * collection. Author can remove their links. Portable DB layer.
 */
declare(strict_types=1);

final class Bookmarks
{
    public static function ensure(): void
    {
        static $done = false;
        if ($done) return;
        $db = Database::pdo();
        $ddl = "CREATE TABLE IF NOT EXISTS team_links (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            author_id INTEGER NOT NULL DEFAULT 0,
            title VARCHAR(200) NOT NULL DEFAULT '',
            url VARCHAR(600) NOT NULL DEFAULT '',
            note VARCHAR(300) NOT NULL DEFAULT '',
            created_at VARCHAR(32) NOT NULL DEFAULT ''
        );";
        $drv = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
        $db->exec($drv === 'sqlite' ? $ddl : Database::translateDDL($ddl, $drv));
        $done = true;
    }

    /** Accept only http(s) URLs; return the cleaned URL or ''. */
    private static function cleanUrl(string $url): string
    {
        $url = trim(mb_substr(trim($url), 0, 600));
        if ($url === '') return '';
        if (!preg_match('~^https?://~i', $url)) $url = 'https://' . $url;
        return filter_var($url, FILTER_VALIDATE_URL) ? $url : '';
    }

    /** Add a link. Returns new id or 0. */
    public static function add(int $authorId, string $title, string $url, string $note = ''): int
    {
        self::ensure();
        $url = self::cleanUrl($url);
        $title = trim(mb_substr(trim($title), 0, 200));
        $note = trim(mb_substr(trim($note), 0, 300));
        if ($authorId <= 0 || $url === '') return 0;
        if ($title === '') $title = preg_replace('~^https?://(www\.)?~i', '', $url);
        Database::pdo()->prepare('INSERT INTO team_links (author_id, title, url, note, created_at) VALUES (?,?,?,?,?)')
            ->execute([$authorId, $title, $url, $note, gmdate('Y-m-d H:i:s')]);
        return (int) Database::pdo()->lastInsertId();
    }

    /** Delete a link (author only). */
    public static function remove(int $uid, int $id): bool
    {
        self::ensure();
        if ($uid <= 0 || $id <= 0) return false;
        $st = Database::pdo()->prepare('DELETE FROM team_links WHERE id = ? AND author_id = ?');
        $st->execute([$id, $uid]);
        return $st->rowCount() > 0;
    }

    /** Shared links, newest first. */
    public static function listLinks(int $viewerId, int $limit = 60): array
    {
        self::ensure();
        $limit = max(1, min(200, $limit));
        try {
            $rows = Database::pdo()->query(
                'SELECT b.*, u.name AS author FROM team_links b LEFT JOIN lms_users u ON u.id = b.author_id
                 ORDER BY b.id DESC LIMIT ' . $limit
            )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) { return []; }
        $out = [];
        foreach ($rows as $r) {
            $url = (string) $r['url'];
            $host = parse_url($url, PHP_URL_HOST) ?: '';
            $out[] = [
                'id'     => (int) $r['id'],
                'title'  => (string) $r['title'],
                'url'    => $url,
                'host'   => preg_replace('~^www\.~i', '', $host),
                'note'   => (string) $r['note'],
                'author' => (string) ($r['author'] ?: 'A member'),
                'mine'   => (int) $r['author_id'] === $viewerId,
            ];
        }
        return $out;
    }
}
