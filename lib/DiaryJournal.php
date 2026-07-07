<?php
/**
 * lib/DiaryJournal.php — member-contributed Vanguard Diary.
 *
 * Three entry kinds, matching the redesign spec:
 *   event   — a public happening at a CACENTRE hub (backdatable) → moderation
 *             queue → on approval, PROMOTED into the Diary's "Events" stream.
 *   public  — a reflection to inspire the movement → moderation queue → on
 *             approval, PROMOTED into the public Diary feed (`articles`).
 *   private — personal reflection. AUTHOR-ONLY; never returned to anyone else.
 *
 * Privacy guarantee: member entries live in their own table. Private rows are
 * only ever queried scoped to their author, so they can never reach a public
 * surface (listing, RSS, sitemap, OG). Public + event entries surface only
 * once an admin approves them — approval copies the row into the editorial
 * `articles` table; nothing else crosses over.
 */
declare(strict_types=1);

final class DiaryJournal
{
    private PDO $db;
    public function __construct(?PDO $pdo = null) { $this->db = $pdo ?? Database::pdo(); }

    public const KINDS = ['event', 'private', 'public'];

    /** The category approved member voices publish under, on the public feed. */
    private const PUBLIC_CATEGORY = 'Vanguard Voices';

    /** Create an entry for a member. Public entries enter the moderation queue. */
    public function create(int $authorId, string $kind, string $title, string $body, string $entryDate): array
    {
        $kind  = in_array($kind, self::KINDS, true) ? $kind : 'private';
        $title = trim(mb_substr(trim($title), 0, 160));
        $body  = trim($body);
        if ($body === '')               return ['ok' => false, 'error' => 'Write something before saving.'];
        if (mb_strlen($body) > 20000)    return ['ok' => false, 'error' => 'That entry is a little long — trim it under 20,000 characters.'];
        $entryDate = self::normalizeDate($entryDate);

        // Event + Public are public BY DEFAULT — they enter the moderation queue
        // and become publicly visible on approval. Private stays author-only.
        $isPublic = in_array($kind, ['public', 'event'], true);
        $status   = $isPublic ? 'pending' : 'logged';

        // Submission integrity — PUBLIC-BOUND entries only (a private journal is
        // the member's own space). Spam/gibberish is rejected inline; the full
        // guard summary (incl. AI-likelihood) is stored for the moderator, who
        // makes the human call — flags inform, they never auto-reject.
        $guardNote = null;
        if ($isPublic) {
            require_once __DIR__ . '/ContentGuard.php';
            $g = ContentGuard::gate($title . "\n" . $body, ['label' => 'entry', 'max' => 20000]);
            if (!$g['ok']) return ['ok' => false, 'error' => $g['error']];
            if (!empty($g['analysis']['flags']) || $g['analysis']['ai'] >= 40 || $g['analysis']['spam'] >= 25) {
                $guardNote = '[guard] ' . $g['analysis']['summary'];
            }
        }

        $now = date('Y-m-d H:i:s');
        $this->db->prepare(
            'INSERT INTO diary_entries (author_id, kind, title, body, entry_date, status, review_note, created_at, updated_at)
             VALUES (?,?,?,?,?,?,?,?,?)'
        )->execute([$authorId, $kind, $title, $body, $entryDate, $status, $guardNote, $now, $now]);

        return ['ok' => true, 'id' => (int) $this->db->lastInsertId(), 'kind' => $kind, 'status' => $status];
    }

    /** A member's own stream (all kinds, newest entry-date first). */
    public function mine(int $authorId): array
    {
        $st = $this->db->prepare(
            'SELECT id, kind, title, body, entry_date, status, published_slug, review_note, created_at
             FROM diary_entries WHERE author_id = ? ORDER BY entry_date DESC, id DESC'
        );
        $st->execute([$authorId]);
        return $st->fetchAll();
    }

    /** Delete a member's OWN entry (scoped to author — a member can't touch another's). */
    public function deleteOwn(int $authorId, int $id): bool
    {
        $st = $this->db->prepare('DELETE FROM diary_entries WHERE id = ? AND author_id = ?');
        $st->execute([$id, $authorId]);
        return $st->rowCount() > 0;
    }

    /* ── Moderation (admin) ───────────────────────────────────────────────
       Only public submissions are ever exposed here. Private + event entries
       are never returned to the queue — they stay invisible to everyone. ── */

    public function pendingPublic(): array
    {
        return $this->db->query(
            "SELECT e.id, e.kind, e.title, e.body, e.entry_date, e.created_at, e.review_note,
                    u.name AS author_name, u.email AS author_email
             FROM diary_entries e JOIN lms_users u ON u.id = e.author_id
             WHERE e.kind IN ('public','event') AND e.status = 'pending'
             ORDER BY e.created_at ASC"
        )->fetchAll();
    }

    public function moderationCount(): int
    {
        return (int) $this->db->query(
            "SELECT COUNT(*) FROM diary_entries WHERE kind IN ('public','event') AND status = 'pending'"
        )->fetchColumn();
    }

    /**
     * Approve a public entry: promote it into the public Diary feed (`articles`)
     * through the editorial repository, then mark it approved. Returns the slug.
     */
    public function approve(int $id, ?DiaryRepository $repo = null): array
    {
        $repo = $repo ?? new DiaryRepository($this->db);
        $st = $this->db->prepare(
            "SELECT e.*, u.name AS author_name FROM diary_entries e JOIN lms_users u ON u.id = e.author_id
             WHERE e.id = ? AND e.kind IN ('public','event') AND e.status = 'pending'"
        );
        $st->execute([$id]);
        $e = $st->fetch();
        if (!$e) return ['ok' => false, 'error' => 'Entry not found or already handled.'];

        $title    = $e['title'] !== '' ? $e['title'] : self::titleFromBody($e['body']);
        $slug     = $this->uniqueSlug($title);
        $isEvent  = ($e['kind'] === 'event');
        $category = $isEvent ? 'Events' : self::PUBLIC_CATEGORY;

        $repo->save([
            'slug'         => $slug,
            'title'        => $title,
            'dek'          => self::excerpt($e['body'], 180),
            'category'     => $category,
            'authors_html' => e($e['author_name']),                 // escaped: members aren't trusted with HTML
            'published'    => date('M j, Y', strtotime($e['entry_date']) ?: time()),
            'published_at' => $e['entry_date'],
            'read_minutes' => self::readMinutes($e['body']),
            'gradient'     => $isEvent ? 'g-sky' : 'g-gold',
            'mc_title'     => $category,
            'cover_url'    => null,
            'og_image'     => null,
            'body_html'    => self::bodyToHtml($e['body']),         // sanitised plain-text → HTML
            'featured'     => 0,
            'status'       => 'published',
            'format'       => 'standard',
            'sections'     => [],
            'related'      => [],
        ]);

        $now = Database::nowExpr();
        $this->db->prepare(
            "UPDATE diary_entries SET status = 'approved', published_slug = ?, updated_at = {$now} WHERE id = ?"
        )->execute([$slug, $id]);
        return ['ok' => true, 'slug' => $slug];
    }

    public function reject(int $id, string $note = ''): bool
    {
        $now = Database::nowExpr();
        $st = $this->db->prepare(
            "UPDATE diary_entries SET status = 'rejected', review_note = ?, updated_at = {$now}
             WHERE id = ? AND kind IN ('public','event') AND status = 'pending'"
        );
        $st->execute([mb_substr(trim($note), 0, 400), $id]);
        return $st->rowCount() > 0;
    }

    /* ── helpers ──────────────────────────────────────────────────────── */

    /** Backdating only: clamp to a real date no later than today. */
    private static function normalizeDate(string $d): string
    {
        $ts = strtotime($d);
        if ($ts === false) $ts = time();
        if ($ts > time())  $ts = time();
        return date('Y-m-d', $ts);
    }

    /** Plain text → safe HTML: escape, blank-line paragraphs, single newlines → <br>. */
    public static function bodyToHtml(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", trim($text));
        $out = [];
        foreach (preg_split('/\n{2,}/', $text) ?: [] as $block) {
            $block = trim($block);
            if ($block !== '') $out[] = '<p>' . nl2br(e($block)) . '</p>';
        }
        return $out ? implode("\n", $out) : '<p></p>';
    }

    public static function excerpt(string $text, int $len = 180): string
    {
        $t = trim((string) preg_replace('/\s+/', ' ', $text));
        return mb_strlen($t) <= $len ? $t : rtrim(mb_substr($t, 0, $len - 1)) . '…';
    }

    private static function titleFromBody(string $body): string
    {
        return self::excerpt($body, 60) ?: 'Untitled entry';
    }

    private static function readMinutes(string $text): int
    {
        return max(1, (int) round(str_word_count(strip_tags($text)) / 200));
    }

    /** A feed slug that won't collide with an existing article. */
    private function uniqueSlug(string $title): string
    {
        $base = slugify($title) ?: 'entry';
        $slug = $base; $n = 1;
        $st = $this->db->prepare('SELECT 1 FROM articles WHERE slug = ?');
        do {
            $st->execute([$slug]);
            if (!$st->fetchColumn()) return $slug;
            $slug = $base . '-' . (++$n);
        } while ($n < 500);
        return $base . '-' . substr((string) time(), -5);
    }
}
