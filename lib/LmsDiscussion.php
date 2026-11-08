<?php
/**
 * lib/LmsDiscussion.php — the Academy's lesson discussions.
 *
 * The Academy had none. A learner reading a lesson alone at eleven at night
 * had no way to ask the one question standing between them and the next
 * lesson, and an instructor had no way to see that nine people were stuck on
 * the same paragraph. That is the thing a cohort gives you and a video does
 * not, and it was the piece missing.
 *
 * The shape is deliberately small: one thread per LESSON, replies one level
 * deep, newest thread first and replies oldest first underneath. No votes, no
 * nesting beyond a reply, no private messages. A forum with five affordances
 * that people use beats one with twenty that they do not.
 *
 * Who may read and write is exactly who may READ THE LESSON — LmsRepository
 * ::canAccess. A discussion is part of the lesson, so it cannot be a second,
 * looser gate: a paid course's questions are not public, and a preview lesson
 * in an open course is as open as the lesson itself.
 *
 * Moderation belongs to the course's instructor and to an Academy admin, and
 * a removal is RECORDED rather than destructive: the row stays, the body goes
 * dark, and the thread still reads as a conversation rather than developing
 * holes. A learner may delete their own post, which is a different act and
 * says so.
 */
declare(strict_types=1);

final class LmsDiscussion
{
    /** The floor for a post. Short enough for "I had this too", long enough
     *  that an empty box is not a contribution. */
    public const MIN_BODY = 2;
    public const MAX_BODY = 4000;
    /** What one person may post in fifteen minutes. */
    public const RATE_POSTS = 10;
    public const RATE_WINDOW = 900;

    private PDO $db;
    private static bool $ready = false;

    public function __construct(?PDO $pdo = null) { $this->db = $pdo ?? Database::pdo(); }

    /* ══ Schema ═══════════════════════════════════════════════════════════ */

    public function ensure(): void
    {
        if (self::$ready) return;
        self::$ready = true;
        $drv = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);
        $ddl = "CREATE TABLE IF NOT EXISTS lesson_posts (
                  id INTEGER PRIMARY KEY AUTOINCREMENT,
                  course_id INTEGER NOT NULL,
                  lesson_id INTEGER NOT NULL,
                  user_id INTEGER NOT NULL,
                  parent_id INTEGER NOT NULL DEFAULT 0,
                  body TEXT NOT NULL DEFAULT '',
                  status VARCHAR(16) NOT NULL DEFAULT 'visible',
                  removed_by INTEGER NOT NULL DEFAULT 0,
                  removed_reason VARCHAR(200) NOT NULL DEFAULT '',
                  created_at TEXT NOT NULL DEFAULT (datetime('now')),
                  updated_at TEXT NOT NULL DEFAULT (datetime('now'))
                );";
        try {
            $this->db->exec($drv === 'sqlite' ? $ddl : Database::translateDDL($ddl, $drv));
            $this->db->exec('CREATE INDEX IF NOT EXISTS idx_lesson_posts_lesson ON lesson_posts (lesson_id, parent_id, id)');
            $this->db->exec('CREATE INDEX IF NOT EXISTS idx_lesson_posts_course ON lesson_posts (course_id, id)');
        } catch (Throwable $e) {
            error_log('[lmsdiscussion] schema: ' . $e->getMessage());
        }
    }

    /* ══ Reading ══════════════════════════════════════════════════════════ */

    /**
     * One lesson's thread: top-level posts newest first, each with its
     * replies oldest first.
     *
     * TWO queries, whatever the size of the thread — the posts and then every
     * reply to the posts on this page. A reply query per post is the shape
     * that turns a popular lesson into a slow one.
     */
    public function thread(int $lessonId, int $limit = 50, int $viewerId = 0, bool $canModerate = false): array
    {
        $this->ensure();
        $limit = max(1, min(200, $limit));
        try {
            $s = $this->db->prepare(
                "SELECT p.*, u.name AS author, u.role AS author_role
                   FROM lesson_posts p LEFT JOIN lms_users u ON u.id = p.user_id
                  WHERE p.lesson_id = ? AND p.parent_id = 0
                  ORDER BY p.id DESC LIMIT ?"
            );
            $s->bindValue(1, $lessonId, PDO::PARAM_INT);
            $s->bindValue(2, $limit, PDO::PARAM_INT);
            $s->execute();
            $tops = $s->fetchAll();
            if (!$tops) return [];
            $ids = array_map(static fn($r) => (int) $r['id'], $tops);
            $in = implode(',', array_fill(0, count($ids), '?'));
            $r = $this->db->prepare(
                "SELECT p.*, u.name AS author, u.role AS author_role
                   FROM lesson_posts p LEFT JOIN lms_users u ON u.id = p.user_id
                  WHERE p.parent_id IN ($in) ORDER BY p.id"
            );
            $r->execute($ids);
            $kids = [];
            foreach ($r->fetchAll() as $row) $kids[(int) $row['parent_id']][] = $this->shape($row, $viewerId, $canModerate);
            $out = [];
            foreach ($tops as $t) {
                $p = $this->shape($t, $viewerId, $canModerate);
                $p['replies'] = $kids[(int) $t['id']] ?? [];
                $out[] = $p;
            }
            return $out;
        } catch (Throwable $e) {
            error_log('[lmsdiscussion] thread: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * What a viewer is allowed to know about one post.
     *
     * A removed post keeps its place and loses its words. Saying "removed by
     * the instructor" where the words were is honest about what happened; a
     * row that silently disappears makes a reply to it read as a reply to
     * nothing, and makes moderation look like a bug.
     */
    private function shape(array $r, int $viewerId, bool $canModerate): array
    {
        $removed = (string) $r['status'] !== 'visible';
        $mine = $viewerId > 0 && (int) $r['user_id'] === $viewerId;
        return [
            'id'        => (int) $r['id'],
            'parent_id' => (int) $r['parent_id'],
            'user_id'   => (int) $r['user_id'],
            'author'    => $removed ? '' : (string) ($r['author'] ?? 'A learner'),
            'is_staff'  => !$removed && in_array((string) ($r['author_role'] ?? ''), ['instructor', 'admin'], true),
            'body'      => $removed ? '' : (string) $r['body'],
            'removed'   => $removed,
            'removed_as'=> $removed ? (string) $r['status'] : '',
            'reason'    => $removed && $canModerate ? (string) $r['removed_reason'] : '',
            'mine'      => $mine,
            'can_delete'=> (!$removed && $mine) || (!$removed && $canModerate),
            'at'        => (string) $r['created_at'],
        ];
    }

    /**
     * How many QUESTIONS a lesson has — top-level posts only.
     *
     * The heading this feeds says "4 questions", so counting replies in it
     * would make a single question with three answers read as four people
     * asking.
     */
    public function count(int $lessonId): int
    {
        $this->ensure();
        try {
            $s = $this->db->prepare("SELECT COUNT(*) FROM lesson_posts WHERE lesson_id = ? AND parent_id = 0 AND status = 'visible'");
            $s->execute([$lessonId]);
            return (int) $s->fetchColumn();
        } catch (Throwable $e) { return 0; }
    }

    /**
     * The questions waiting across a whole course, newest first.
     *
     * This is the instructor's half of the feature and the reason it is worth
     * having: a thread nobody with an answer ever reads is a worse experience
     * than no thread at all. A top-level post with no reply is "unanswered".
     */
    public function unanswered(int $courseId, int $limit = 50): array
    {
        $this->ensure();
        $limit = max(1, min(200, $limit));
        try {
            $s = $this->db->prepare(
                "SELECT p.*, u.name AS author, l.title AS lesson_title, l.slug AS lesson_slug,
                        (SELECT COUNT(*) FROM lesson_posts r WHERE r.parent_id = p.id AND r.status = 'visible') AS replies
                   FROM lesson_posts p
                   LEFT JOIN lms_users u ON u.id = p.user_id
                   LEFT JOIN lessons l ON l.id = p.lesson_id
                  WHERE p.course_id = ? AND p.parent_id = 0 AND p.status = 'visible'
                  ORDER BY replies ASC, p.id DESC LIMIT ?"
            );
            $s->bindValue(1, $courseId, PDO::PARAM_INT);
            $s->bindValue(2, $limit, PDO::PARAM_INT);
            $s->execute();
            return array_map(static fn($r) => [
                'id' => (int) $r['id'], 'author' => (string) ($r['author'] ?? 'A learner'),
                'body' => (string) $r['body'], 'at' => (string) $r['created_at'],
                'replies' => (int) $r['replies'],
                'lesson_title' => (string) ($r['lesson_title'] ?? ''), 'lesson_slug' => (string) ($r['lesson_slug'] ?? ''),
            ], $s->fetchAll());
        } catch (Throwable $e) {
            error_log('[lmsdiscussion] unanswered: ' . $e->getMessage());
            return [];
        }
    }

    /* ══ Writing ══════════════════════════════════════════════════════════ */

    /**
     * Post a question, or a reply to one.
     *
     * The caller has already established that this person may READ the
     * lesson; that is the only gate, because a discussion is part of its
     * lesson. A reply must belong to a top-level post on the SAME lesson —
     * without that check a reply id from another course threads a post into
     * a conversation its author cannot see.
     */
    public function post(int $userId, int $courseId, int $lessonId, string $body, int $parentId = 0): array
    {
        $this->ensure();
        if ($userId <= 0) return ['ok' => false, 'error' => 'Sign in to join the discussion.'];
        $body = trim(preg_replace('/\r\n?/', "\n", $body) ?? '');
        if (mb_strlen($body) < self::MIN_BODY) return ['ok' => false, 'error' => 'Write your question first.'];
        if (mb_strlen($body) > self::MAX_BODY) {
            return ['ok' => false, 'code' => 'too_long',
                    'error' => 'That is ' . mb_strlen($body) . ' characters and the most we keep is '
                             . self::MAX_BODY . '. Shorten it rather than losing the end of it — nothing has been posted.'];
        }
        if ($parentId > 0) {
            $s = $this->db->prepare('SELECT lesson_id, parent_id, status FROM lesson_posts WHERE id = ?');
            $s->execute([$parentId]);
            $p = $s->fetch();
            if (!$p || (int) $p['lesson_id'] !== $lessonId || (int) $p['parent_id'] !== 0) {
                return ['ok' => false, 'error' => 'That question is no longer here.'];
            }
            if ((string) $p['status'] !== 'visible') return ['ok' => false, 'error' => 'That question was removed.'];
        }
        $now = gmdate('Y-m-d H:i:s');
        try {
            $this->db->prepare(
                'INSERT INTO lesson_posts (course_id, lesson_id, user_id, parent_id, body, status, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, \'visible\', ?, ?)'
            )->execute([$courseId, $lessonId, $userId, $parentId, $body, $now, $now]);
            return ['ok' => true, 'id' => (int) $this->db->lastInsertId()];
        } catch (Throwable $e) {
            error_log('[lmsdiscussion] post: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'That could not be posted.'];
        }
    }

    /**
     * Take a post down.
     *
     * Two different acts, recorded differently, because they mean different
     * things to everybody who reads the thread afterwards: an author deleting
     * their own question ("deleted"), and a moderator removing somebody
     * else's ("removed"). A moderator must say why — not shown to the room,
     * but on the record for the next moderator.
     */
    public function remove(int $postId, int $actorId, bool $isModerator, string $reason = ''): array
    {
        $this->ensure();
        $s = $this->db->prepare('SELECT id, user_id, status FROM lesson_posts WHERE id = ?');
        $s->execute([$postId]);
        $p = $s->fetch();
        if (!$p) return ['ok' => false, 'error' => 'No such post.'];
        if ((string) $p['status'] !== 'visible') return ['ok' => true, 'already' => true];
        $mine = (int) $p['user_id'] === $actorId;
        if (!$mine && !$isModerator) return ['ok' => false, 'code' => 'not_yours', 'error' => 'That is not yours to remove.'];
        $status = $mine ? 'deleted' : 'removed';
        if (!$mine && trim($reason) === '') {
            return ['ok' => false, 'error' => 'Say why you are removing it — it goes on the record, not to the room.'];
        }
        try {
            $this->db->prepare('UPDATE lesson_posts SET status = ?, removed_by = ?, removed_reason = ?, updated_at = ? WHERE id = ?')
                ->execute([$status, $actorId, mb_substr(trim($reason), 0, 200), gmdate('Y-m-d H:i:s'), $postId]);
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => 'That could not be removed.'];
        }
        return ['ok' => true, 'status' => $status];
    }

    /** May this person moderate this course — its instructor, or an admin? */
    public static function canModerate(?array $user, array $course): bool
    {
        if (!$user) return false;
        if ((string) ($user['role'] ?? '') === 'admin') return true;
        return (int) ($course['instructor_id'] ?? 0) > 0
            && (int) $course['instructor_id'] === (int) $user['id'];
    }
}
