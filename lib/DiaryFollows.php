<?php
/**
 * lib/DiaryFollows.php — following a Diary author.
 *
 * The Follow button on an entry's author card. A signed-in member follows with
 * their account; a reader without one leaves an email. Either way the follow
 * is one row keyed by (author, email), and what it buys is one email when that
 * author publishes a NEW entry — not on every edit: `diary.published` fires on
 * every save of a published entry, so each article is mailed at most once
 * (diary_follow_sent). Every email carries a one-click unfollow link (a token
 * per follow) and List-Unsubscribe, so it travels as bulk mail and never spends
 * the Apps Script allowance kept for sign-in codes.
 */
declare(strict_types=1);

final class DiaryFollows
{
    /** Per publication, so one big list cannot stall a request on shared hosting. */
    public const MAX_PER_PUBLICATION = 150;
    private static bool $ready = false;
    private PDO $db;

    public function __construct(?PDO $pdo = null) { $this->db = $pdo ?? Database::pdo(); }

    public static function ensure(): void
    {
        if (self::$ready) return; self::$ready = true;
        $db = Database::pdo();
        $db->exec('CREATE TABLE IF NOT EXISTS diary_follows (
            id INTEGER PRIMARY KEY ' . (Database::driver() === 'mysql' ? 'AUTO_INCREMENT' : 'AUTOINCREMENT') . ',
            author_slug VARCHAR(120) NOT NULL,
            email VARCHAR(190) NOT NULL,
            user_id INTEGER NOT NULL DEFAULT 0,
            token VARCHAR(64) NOT NULL,
            created_at VARCHAR(19) NOT NULL
        )');
        try { $db->exec('CREATE UNIQUE INDEX ux_diary_follows ON diary_follows (author_slug, email)'); } catch (Throwable $e) { /* exists */ }
        $db->exec('CREATE TABLE IF NOT EXISTS diary_follow_sent (
            article_slug VARCHAR(190) NOT NULL PRIMARY KEY,
            sent INTEGER NOT NULL DEFAULT 0,
            sent_at VARCHAR(19) NOT NULL
        )');
    }

    public static function cleanSlug(string $s): string { return substr(preg_replace('/[^a-z0-9\-]/', '', strtolower($s)) ?? '', 0, 120); }

    /** → ['ok'=>true,'following'=>true,'count'=>n] | ['ok'=>false,'error'=>…] */
    public function follow(string $author, string $email, int $userId = 0): array
    {
        self::ensure();
        $author = self::cleanSlug($author);
        $email = strtolower(trim($email));
        if ($author === '') return ['ok' => false, 'error' => 'That author could not be found.'];
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return ['ok' => false, 'error' => 'Enter a valid email address.'];
        $st = $this->db->prepare('SELECT id FROM diary_follows WHERE author_slug = ? AND email = ?');
        $st->execute([$author, $email]);
        if (!$st->fetchColumn()) {
            $this->db->prepare('INSERT INTO diary_follows (author_slug, email, user_id, token, created_at) VALUES (?,?,?,?,?)')
                ->execute([$author, $email, max(0, $userId), bin2hex(random_bytes(20)), date('Y-m-d H:i:s')]);
        }
        return ['ok' => true, 'following' => true, 'count' => $this->count($author)];
    }

    public function unfollow(string $author, string $email): array
    {
        self::ensure();
        $author = self::cleanSlug($author);
        $this->db->prepare('DELETE FROM diary_follows WHERE author_slug = ? AND email = ?')->execute([$author, strtolower(trim($email))]);
        return ['ok' => true, 'following' => false, 'count' => $this->count($author)];
    }

    /** The one-click link in every email. → the author it stopped, or '' */
    public function stopByToken(string $token): string
    {
        self::ensure();
        if (!preg_match('/^[a-f0-9]{40}$/', $token)) return '';
        $st = $this->db->prepare('SELECT author_slug FROM diary_follows WHERE token = ?');
        $st->execute([$token]);
        $a = (string) ($st->fetchColumn() ?: '');
        if ($a !== '') $this->db->prepare('DELETE FROM diary_follows WHERE token = ?')->execute([$token]);
        return $a;
    }

    public function isFollowing(string $author, string $email): bool
    {
        self::ensure();
        if ($email === '') return false;
        $st = $this->db->prepare('SELECT 1 FROM diary_follows WHERE author_slug = ? AND email = ?');
        $st->execute([self::cleanSlug($author), strtolower(trim($email))]);
        return (bool) $st->fetchColumn();
    }

    public function count(string $author): int
    {
        self::ensure();
        $st = $this->db->prepare('SELECT COUNT(*) FROM diary_follows WHERE author_slug = ?');
        $st->execute([self::cleanSlug($author)]);
        return (int) $st->fetchColumn();
    }

    /**
     * Email the followers of a newly published entry's author. Once per article,
     * however many times it is saved afterwards. → how many were sent.
     */
    public function notifyPublished(string $articleSlug, ?DiaryRepository $repo = null): int
    {
        self::ensure();
        $articleSlug = substr(preg_replace('/[^a-z0-9\-]/', '', strtolower($articleSlug)) ?? '', 0, 190);
        if ($articleSlug === '') return 0;
        $repo = $repo ?? new DiaryRepository($this->db);
        $a = $repo->bySlug($articleSlug);
        if (!$a || ($a['status'] ?? 'published') !== 'published') return 0;

        // Claim the article first, so two saves in quick succession cannot both mail.
        try {
            $this->db->prepare('INSERT INTO diary_follow_sent (article_slug, sent, sent_at) VALUES (?,0,?)')->execute([$articleSlug, date('Y-m-d H:i:s')]);
        } catch (Throwable $e) { return 0; }    // already claimed: mailed before

        $au = $repo->authorCard($a);
        $st = $this->db->prepare('SELECT email, token FROM diary_follows WHERE author_slug = ? ORDER BY id LIMIT ' . self::MAX_PER_PUBLICATION);
        $st->execute([self::cleanSlug((string) $au['slug'])]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $site = defined('SITE_URL') ? rtrim(SITE_URL, '/') : '';
        $url = function_exists('diary_url') ? diary_url($articleSlug . '/') : $site . '/diary/' . $articleSlug . '/';
        $n = 0;
        foreach ($rows as $r) {
            $stop = $site . '/diary/api.php?action=follow.stop&t=' . $r['token'];
            $html = Mailer::shell('New from ' . $au['name'], [
                e($au['name']) . ' just published in the Afrovanguard Diary:',
                '<strong>' . e((string) $a['title']) . '</strong>' . (trim((string) ($a['dek'] ?? '')) !== '' ? '<br>' . e((string) $a['dek']) : ''),
                'You get this because you follow ' . e($au['name']) . '. <a href="' . e($stop) . '">Stop following</a>.',
            ], ['url' => $url, 'text' => 'Read the entry'], 'New in the Diary: ' . (string) $a['title']);
            $ok = Mailer::send((string) $r['email'], 'New from ' . $au['name'] . ': ' . (string) $a['title'], $html, [
                'bulk' => true,
                'headers' => ['List-Unsubscribe' => '<' . $stop . '>', 'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click'],
            ]);
            if ($ok) $n++;
        }
        $this->db->prepare('UPDATE diary_follow_sent SET sent = ? WHERE article_slug = ?')->execute([$n, $articleSlug]);
        return $n;
    }
}
