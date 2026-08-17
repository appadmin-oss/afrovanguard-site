<?php
/**
 * lib/AvKnowledge.php — the editable half of what the AI knows.
 *
 * `AiKnowledge` compiles facts the app can DERIVE — programmes, articles,
 * events, today's celebration. It is automatic and cannot be edited, because
 * it is only ever a reflection of the database.
 *
 * This class holds the other half: the things the app cannot derive because
 * they are decisions, not data. What Afrovanguard is for. What the levels mean.
 * Where the line runs between what the AI may do and what only a human may do.
 * Previously those sentences lived inside PHP string literals scattered across
 * three classes, which meant they drifted apart and only a developer could
 * correct them.
 *
 * Here they are rows: titled, tagged, ordered by priority, individually
 * enabled, and editable from Studio. The defaults below are seeded once on
 * first use, so a fresh install is fully operational and an administrator who
 * deletes an entry does not find it resurrected on the next request.
 *
 * Every read is guarded — a missing table or a bad row degrades to a shorter
 * brief, never an error in an AI reply.
 */
declare(strict_types=1);

final class AvKnowledge
{
    private const SEEDED = 'av_knowledge_seeded';
    private static bool $ensured = false;
    private static ?string $memo = null;

    private static function db(): PDO { return Database::pdo(); }

    /** The entries a fresh install starts with. Seeded once; then fully editable. */
    private const SEED = [
        [
            'topic' => 'mission', 'priority' => 100, 'tags' => 'core,mission',
            'title' => 'What Afrovanguard is',
            'body'  => 'Afrovanguard is a Pan-African movement raising an incorruptible generation. It grows people through mentorship, service and leadership multiplication — not through titles or length of membership. The question the whole organisation is held to: is Afrovanguard actually producing the incorruptible generation it exists to raise?',
        ],
        [
            'topic' => 'dimensions', 'priority' => 90, 'tags' => 'core,growth',
            'title' => 'The four dimensions of development',
            'body'  => "A member is developed along four dimensions, and no single number summarises them:\n"
                     . "A. Character and incorruptibility — behavioural consistency, promises kept, failures owned. Never reduce this to a score; report evidence.\n"
                     . "B. Personal growth — education, career, business, skills, health, certifications.\n"
                     . "C. Organisational contribution — assigned work, deliverables, deadlines, participation. Distinguish activity from impact.\n"
                     . "D. Leadership multiplication — whether this person can raise people who can themselves raise people.",
        ],
        [
            'topic' => 'ladder', 'priority' => 85, 'tags' => 'core,levels',
            'title' => 'What the levels mean',
            'body'  => 'The ladder measures multiplication, not recruitment. Each level represents a doubling of the mentorship tree: a member with active mentees, whose mentees have their own active mentees, and so on. A person with ten registered mentees of whom one is active represents weaker leadership than a person with four who all meet regularly and have begun mentoring others. Simply listing names does not qualify anyone for advancement. The live thresholds are set by leadership — read them from the operating rules, never assume them.',
        ],
        [
            'topic' => 'division', 'priority' => 80, 'tags' => 'core,ai,boundaries',
            'title' => 'What the AI does and does not do',
            'body'  => "The AI observes, records, analyses, reminds, recommends and escalates.\n"
                     . "Humans interpret, discern, counsel, decide, correct and promote.\n"
                     . 'The line is not negotiable. The system exists so that responsibility, mentorship and multiplication stop disappearing unnoticed — not so that software can judge a person.',
        ],
        [
            'topic' => 'accountability', 'priority' => 75, 'tags' => 'core,commitments',
            'title' => 'No commitment should disappear',
            'body'  => 'Anything a member commits to — in a meeting, with a mentor, or to themselves — is recorded, given an owner and a deadline, and followed up. When a commitment is missed, the member is asked what was missed and why. A miss that the member reports themselves is a positive signal about character, not a negative one, and should be surfaced that way.',
        ],
        [
            'topic' => 'escalation', 'priority' => 70, 'tags' => 'core,mentorship',
            'title' => 'Escalation is support, not punishment',
            'body'  => 'When a mentorship relationship goes quiet the objective is early intervention. A first miss is a gentle check that nothing is wrong. Repeated misses bring in the mentor\'s own mentor, because a struggling mentor usually needs help rather than blame. Every escalation carries the evidence that triggered it so a human can disagree with it.',
        ],
        [
            'topic' => 'culture', 'priority' => 60, 'tags' => 'culture,meetings',
            'title' => 'Servant-leadership culture',
            'body'  => 'Members arrive early and serve before attending — thirty minutes for an ordinary gathering, two to three hours for a major event. Service comes before seniority.',
        ],
        [
            'topic' => 'privacy', 'priority' => 55, 'tags' => 'core,privacy',
            'title' => 'Handling personal disclosures',
            'body'  => 'Reasons a member gives for missing a commitment or a meeting often involve illness, money or family. Treat them as confidential. Never repeat a stated reason to anyone the member did not disclose it to, never summarise it into a public brief, and never use it as evidence for or against advancement.',
        ],
    ];

    /** Idempotently provision the table, then seed the starting entries once. */
    public static function ensure(): void
    {
        if (self::$ensured) return;
        self::$ensured = true;
        try {
            Database::execSchema(self::db(), "
                CREATE TABLE IF NOT EXISTS av_knowledge (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    topic TEXT NOT NULL DEFAULT '',
                    title TEXT NOT NULL DEFAULT '',
                    body TEXT NOT NULL DEFAULT '',
                    tags TEXT NOT NULL DEFAULT '',
                    priority INTEGER NOT NULL DEFAULT 50,
                    enabled INTEGER NOT NULL DEFAULT 1,
                    updated_by TEXT NOT NULL DEFAULT '',
                    created_at TEXT NOT NULL DEFAULT (datetime('now')),
                    updated_at TEXT NOT NULL DEFAULT (datetime('now'))
                );
                CREATE INDEX IF NOT EXISTS ix_avk_enabled ON av_knowledge(enabled, priority);
            ");
            self::seed();
        } catch (\Throwable $e) { error_log('[knowledge] ensure: ' . $e->getMessage()); }
    }

    /** Insert the starting entries exactly once, tracked by a meta flag. */
    private static function seed(): void
    {
        try {
            if ((string) (Database::metaGet(self::SEEDED) ?? '') === '1') return;
            $st = self::db()->prepare(
                'INSERT INTO av_knowledge (topic, title, body, tags, priority, enabled, updated_by, created_at, updated_at) '
                . 'VALUES (?,?,?,?,?,1,?,' . Database::nowExpr() . ',' . Database::nowExpr() . ')'
            );
            foreach (self::SEED as $s) {
                $st->execute([$s['topic'], $s['title'], $s['body'], $s['tags'], (int) $s['priority'], 'seed']);
            }
            Database::metaSet(self::SEEDED, '1');
            self::flush();
        } catch (\Throwable $e) { error_log('[knowledge] seed: ' . $e->getMessage()); }
    }

    /** Forget the per-request memo and the AI brief cache. */
    public static function flush(): void
    {
        self::$memo = null;
        if (class_exists('AiKnowledge')) { try { AiKnowledge::invalidate(); } catch (\Throwable $e) {} }
    }

    /** Every entry, highest priority first. Admin view — includes disabled rows. */
    public static function all(): array
    {
        self::ensure();
        try {
            $rows = self::db()->query(
                'SELECT id, topic, title, body, tags, priority, enabled, updated_by, updated_at '
                . 'FROM av_knowledge ORDER BY priority DESC, id ASC'
            )->fetchAll() ?: [];
            return array_map(static fn(array $r) => [
                'id'         => (int) $r['id'],
                'topic'      => (string) $r['topic'],
                'title'      => (string) $r['title'],
                'body'       => (string) $r['body'],
                'tags'       => (string) $r['tags'],
                'priority'   => (int) $r['priority'],
                'enabled'    => (int) $r['enabled'] === 1,
                'updated_by' => (string) $r['updated_by'],
                'updated_at' => (string) $r['updated_at'],
            ], $rows);
        } catch (\Throwable $e) { error_log('[knowledge] all: ' . $e->getMessage()); return []; }
    }

    public static function get(int $id): ?array
    {
        foreach (self::all() as $r) if ($r['id'] === $id) return $r;
        return null;
    }

    /**
     * Create ($id = 0) or update an entry. Returns ['ok'=>bool, 'id'|'error'].
     * A blank title or body is rejected — an empty fact is worse than none.
     */
    public static function save(int $id, array $in, string $actor = 'admin'): array
    {
        self::ensure();
        $title = trim((string) ($in['title'] ?? ''));
        $body  = trim((string) ($in['body'] ?? ''));
        if ($title === '') return ['ok' => false, 'error' => 'Give the entry a title.'];
        if ($body === '')  return ['ok' => false, 'error' => 'Give the entry a body — this is what the AI reads.'];
        if (mb_strlen($body) > 6000) return ['ok' => false, 'error' => 'Body is too long (6000 characters maximum).'];

        $topic    = mb_substr(trim((string) ($in['topic'] ?? '')), 0, 60);
        $tags     = mb_substr(trim((string) ($in['tags'] ?? '')), 0, 200);
        $priority = max(0, min(100, (int) ($in['priority'] ?? 50)));
        $enabled  = !isset($in['enabled']) || (bool) $in['enabled'] ? 1 : 0;

        try {
            if ($id > 0) {
                self::db()->prepare(
                    'UPDATE av_knowledge SET topic=?, title=?, body=?, tags=?, priority=?, enabled=?, updated_by=?, updated_at=' . Database::nowExpr() . ' WHERE id=?'
                )->execute([$topic, mb_substr($title, 0, 200), $body, $tags, $priority, $enabled, mb_substr($actor, 0, 120), $id]);
            } else {
                self::db()->prepare(
                    'INSERT INTO av_knowledge (topic, title, body, tags, priority, enabled, updated_by, created_at, updated_at) '
                    . 'VALUES (?,?,?,?,?,?,?,' . Database::nowExpr() . ',' . Database::nowExpr() . ')'
                )->execute([$topic, mb_substr($title, 0, 200), $body, $tags, $priority, $enabled, mb_substr($actor, 0, 120)]);
                $id = (int) self::db()->lastInsertId();
            }
            self::flush();
            if (class_exists('Events')) Events::emit('knowledge.changed', ['id' => $id]);
            return ['ok' => true, 'id' => $id];
        } catch (\Throwable $e) {
            error_log('[knowledge] save: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'Could not save that entry.'];
        }
    }

    public static function remove(int $id): bool
    {
        self::ensure();
        try {
            self::db()->prepare('DELETE FROM av_knowledge WHERE id = ?')->execute([$id]);
            self::flush();
            return true;
        } catch (\Throwable $e) { error_log('[knowledge] remove: ' . $e->getMessage()); return false; }
    }

    /**
     * The knowledge block appended to AI system prompts. Enabled entries only,
     * highest priority first, hard-bounded by the ai.knowledge_char_limit rule
     * so a long entry can never crowd out the prompt itself.
     */
    public static function asPromptBlock(): string
    {
        if (self::$memo !== null) return self::$memo;
        self::$memo = '';
        try {
            $limit = class_exists('AvRules') ? AvRules::int('ai.doctrine_char_limit', 6000) : 6000;
            $parts = [];
            foreach (self::all() as $r) {
                if (!$r['enabled']) continue;
                $parts[] = $r['title'] . ': ' . $r['body'];
            }
            if (!$parts) return self::$memo;
            $block = "\n\nAFROVANGUARD DOCTRINE (written by leadership — treat as authoritative and never contradict it):\n\n"
                   . implode("\n\n", $parts);
            self::$memo = mb_strlen($block) > $limit ? mb_substr($block, 0, $limit) . '…' : $block;
        } catch (\Throwable $e) {
            error_log('[knowledge] promptBlock: ' . $e->getMessage());
        }
        return self::$memo;
    }

    /** Restore the seed entries an administrator has deleted. Returns the count added. */
    public static function restoreDefaults(string $actor = 'admin'): int
    {
        self::ensure();
        $have = [];
        foreach (self::all() as $r) $have[$r['topic'] . '|' . $r['title']] = true;
        $n = 0;
        foreach (self::SEED as $s) {
            if (isset($have[$s['topic'] . '|' . $s['title']])) continue;
            $r = self::save(0, $s + ['enabled' => true], $actor);
            if (!empty($r['ok'])) $n++;
        }
        return $n;
    }

    /** Undo dispatch for the Studio audit trail (AdminAudit::UNDOABLE). */
    public static function applyUndo(string $op, array $args): bool
    {
        switch ($op) {
            case 'k_restore':      // undo an edit: write the previous row back
                $id = (int) ($args['id'] ?? 0);
                $prev = (array) ($args['prev'] ?? []);
                if ($id <= 0 || !$prev) return false;
                return !empty(self::save($id, $prev, 'undo')['ok']);
            case 'k_recreate':     // undo a delete: put the entry back
                $prev = (array) ($args['prev'] ?? []);
                if (!$prev) return false;
                return !empty(self::save(0, $prev, 'undo')['ok']);
            case 'k_delete':       // undo a create: remove it again
                $id = (int) ($args['id'] ?? 0);
                return $id > 0 && self::remove($id);
        }
        return false;
    }
}
