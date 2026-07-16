<?php
/**
 * lib/Polls.php — Team Polls: lightweight collaborative decisions for the org.
 *
 * A member posts a question with 2–6 options; org members vote (one vote each,
 * changeable); everyone sees live tallies. The author can close a poll. Portable
 * DB layer (SQLite / MySQL / Postgres), same as the rest of the app.
 */
declare(strict_types=1);

final class Polls
{
    public static function ensure(): void
    {
        static $done = false;
        if ($done) return;
        $db = Database::pdo();
        $ddl = "CREATE TABLE IF NOT EXISTS team_polls (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            author_id INTEGER NOT NULL DEFAULT 0,
            question VARCHAR(300) NOT NULL DEFAULT '',
            options_json TEXT NOT NULL DEFAULT '[]',
            closed INTEGER NOT NULL DEFAULT 0,
            created_at VARCHAR(32) NOT NULL DEFAULT ''
        );
        CREATE TABLE IF NOT EXISTS team_poll_votes (
            poll_id INTEGER NOT NULL,
            user_id INTEGER NOT NULL,
            option_idx INTEGER NOT NULL DEFAULT 0,
            created_at VARCHAR(32) NOT NULL DEFAULT '',
            PRIMARY KEY (poll_id, user_id)
        );
        CREATE INDEX IF NOT EXISTS idx_poll_votes ON team_poll_votes(poll_id);";
        $drv = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
        $db->exec($drv === 'sqlite' ? $ddl : Database::translateDDL($ddl, $drv));
        $done = true;
    }

    /** Create a poll. Returns the new id or 0. */
    public static function create(int $authorId, string $question, array $options): int
    {
        self::ensure();
        $question = trim(mb_substr(trim($question), 0, 300));
        // Clean + de-dupe options, keep 2..6.
        $opts = [];
        foreach ($options as $o) {
            $o = trim(mb_substr((string) $o, 0, 120));
            if ($o !== '' && !in_array($o, $opts, true)) $opts[] = $o;
            if (count($opts) >= 6) break;
        }
        if ($authorId <= 0 || $question === '' || count($opts) < 2) return 0;
        Database::pdo()->prepare('INSERT INTO team_polls (author_id, question, options_json, closed, created_at) VALUES (?,?,?,0,?)')
            ->execute([$authorId, $question, json_encode(array_values($opts), JSON_UNESCAPED_UNICODE), gmdate('Y-m-d H:i:s')]);
        return (int) Database::pdo()->lastInsertId();
    }

    /** Cast (or change) a vote. Returns true on success. */
    public static function vote(int $uid, int $pollId, int $idx): bool
    {
        self::ensure();
        if ($uid <= 0 || $pollId <= 0 || $idx < 0) return false;
        $db = Database::pdo();
        $st = $db->prepare('SELECT options_json, closed FROM team_polls WHERE id = ?');
        $st->execute([$pollId]);
        $p = $st->fetch(PDO::FETCH_ASSOC);
        if (!$p || (int) $p['closed'] === 1) return false;
        $opts = json_decode((string) $p['options_json'], true) ?: [];
        if ($idx >= count($opts)) return false;
        // Upsert one vote per user (portable: delete then insert).
        $db->prepare('DELETE FROM team_poll_votes WHERE poll_id = ? AND user_id = ?')->execute([$pollId, $uid]);
        $db->prepare('INSERT INTO team_poll_votes (poll_id, user_id, option_idx, created_at) VALUES (?,?,?,?)')
            ->execute([$pollId, $uid, $idx, gmdate('Y-m-d H:i:s')]);
        return true;
    }

    /** Close a poll (author only). */
    public static function close(int $uid, int $pollId): bool
    {
        self::ensure();
        $st = Database::pdo()->prepare('UPDATE team_polls SET closed = 1 WHERE id = ? AND author_id = ?');
        $st->execute([$pollId, $uid]);
        return $st->rowCount() > 0;
    }

    /** Delete a poll + its votes (author only). */
    public static function remove(int $uid, int $pollId): bool
    {
        self::ensure();
        $db = Database::pdo();
        $own = $db->prepare('SELECT 1 FROM team_polls WHERE id = ? AND author_id = ?');
        $own->execute([$pollId, $uid]);
        if (!$own->fetchColumn()) return false;
        $db->prepare('DELETE FROM team_poll_votes WHERE poll_id = ?')->execute([$pollId]);
        $db->prepare('DELETE FROM team_polls WHERE id = ?')->execute([$pollId]);
        return true;
    }

    /** Recent polls with live tallies + the viewer's own vote. */
    public static function listPolls(int $viewerId, int $limit = 20): array
    {
        self::ensure();
        $limit = max(1, min(50, $limit));
        $db = Database::pdo();
        try {
            $rows = $db->query(
                'SELECT p.*, u.name AS author FROM team_polls p LEFT JOIN lms_users u ON u.id = p.author_id
                 ORDER BY p.closed ASC, p.id DESC LIMIT ' . $limit
            )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) { return []; }
        if (!$rows) return [];
        $ids = array_map(fn($r) => (int) $r['id'], $rows);
        // Tally votes for these polls.
        $tally = []; $mine = [];
        $in = implode(',', array_fill(0, count($ids), '?'));
        $vs = $db->prepare('SELECT poll_id, option_idx, COUNT(*) n FROM team_poll_votes WHERE poll_id IN (' . $in . ') GROUP BY poll_id, option_idx');
        $vs->execute($ids);
        foreach ($vs->fetchAll(PDO::FETCH_ASSOC) as $v) { $tally[(int) $v['poll_id']][(int) $v['option_idx']] = (int) $v['n']; }
        if ($viewerId > 0) {
            $mv = $db->prepare('SELECT poll_id, option_idx FROM team_poll_votes WHERE user_id = ? AND poll_id IN (' . $in . ')');
            $mv->execute(array_merge([$viewerId], $ids));
            foreach ($mv->fetchAll(PDO::FETCH_ASSOC) as $v) { $mine[(int) $v['poll_id']] = (int) $v['option_idx']; }
        }
        $out = [];
        foreach ($rows as $r) {
            $id = (int) $r['id'];
            $opts = json_decode((string) $r['options_json'], true) ?: [];
            $counts = $tally[$id] ?? [];
            $total = array_sum($counts);
            $options = [];
            foreach ($opts as $i => $text) {
                $c = (int) ($counts[$i] ?? 0);
                $options[] = ['text' => (string) $text, 'count' => $c, 'pct' => $total > 0 ? (int) round(100 * $c / $total) : 0];
            }
            $out[] = [
                'id'       => $id,
                'question' => (string) $r['question'],
                'author'   => (string) ($r['author'] ?: 'A member'),
                'mine'     => (int) $r['author_id'] === $viewerId,
                'closed'   => (int) $r['closed'] === 1,
                'total'    => $total,
                'my_vote'  => $mine[$id] ?? -1,
                'options'  => $options,
                'ago'      => self::ago((string) $r['created_at']),
            ];
        }
        return $out;
    }

    private static function ago(string $ts): string
    {
        $t = strtotime($ts . ' UTC') ?: 0; if (!$t) return '';
        $d = max(0, time() - $t);
        if ($d < 60) return 'just now';
        if ($d < 3600) return floor($d / 60) . 'm ago';
        if ($d < 86400) return floor($d / 3600) . 'h ago';
        return floor($d / 86400) . 'd ago';
    }
}
