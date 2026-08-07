<?php
/**
 * lib/DiaryOrganise.php — the finding half of the diary.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY THIS IS SEPARATE FROM NOTEBOOKS
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * A notebook answers "where does this belong" — one per entry, chosen once,
 * visible in a sidebar. Everything here answers "how do I get back to it" —
 * many per entry, applied late, and used through search rather than navigation.
 *
 * They are different questions and they fail differently. Notebooks fail by
 * multiplying: forty containers and no idea which one. Tags fail by drifting:
 * "mentoring", "Mentoring" and "mentor" as three separate things. So notebooks
 * are capped and deliberate, and tags are normalised hard and suggested from
 * what the member has already used — the two need opposite treatment, which is
 * the argument for two classes rather than one.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHAT IT ADDS
 * ══════════════════════════════════════════════════════════════════════════════
 *
 *   tags      cross-cutting labels, normalised, with the member's own vocabulary
 *             offered back as suggestions
 *   pin       a handful of entries held at the top, capped so pinning stays
 *             meaningful
 *   archive   out of the way without being destroyed — the honest alternative
 *             to a delete somebody will regret
 *   search    title + body + tab bodies, filtered by notebook, kind, tag, date
 *
 * ── TAGS ARE STORED IN THEIR OWN TABLE, NOT A COMMA STRING ───────────────────
 *
 * A comma-separated column is faster to build and it makes "every entry tagged
 * X" a LIKE '%x%' that matches "xylophone", makes renaming a tag a string
 * rewrite across every row, and makes counting them impossible. The join table
 * is three columns and removes all three problems.
 */
declare(strict_types=1);

final class DiaryOrganise
{
    /** More pinned entries than this and the pin means nothing. */
    public const MAX_PINNED = 8;

    /** Tags per entry. A dozen labels on one entry is not a label, it is prose. */
    public const MAX_TAGS_PER_ENTRY = 12;

    private static bool $ready = false;

    private PDO $db;

    public function __construct(?PDO $pdo = null) { $this->db = $pdo ?? Database::pdo(); }

    public static function ensure(): void
    {
        if (self::$ready) return;
        $db  = Database::pdo();
        $drv = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
        $ddl = "
        CREATE TABLE IF NOT EXISTS diary_tags (
            entry_id   INTEGER NOT NULL,
            user_id    INTEGER NOT NULL,
            tag        VARCHAR(40) NOT NULL,
            created_at VARCHAR(32) NOT NULL DEFAULT '',
            PRIMARY KEY (entry_id, tag)
        );
        CREATE INDEX IF NOT EXISTS idx_dtag_user ON diary_tags(user_id, tag);";
        $db->exec($drv === 'sqlite' ? $ddl : Database::translateDDL($ddl, $drv));

        foreach ([
            'pinned'   => 'INTEGER NOT NULL DEFAULT 0',
            'archived' => 'INTEGER NOT NULL DEFAULT 0',
        ] as $col => $decl) {
            try {
                if (!Database::columnExists('diary_entries', $col)) {
                    $db->exec('ALTER TABLE diary_entries ADD COLUMN ' . $col . ' ' . $decl);
                }
            } catch (Throwable $e) { /* already there */ }
        }
        self::$ready = true;
    }

    private static function now(): string { return gmdate('Y-m-d H:i:s'); }

    /**
     * One canonical form for a tag, so the same idea is never two tags.
     *
     * Lowercased, whitespace and punctuation collapsed to single hyphens,
     * trimmed to 40. "Mentoring", " mentoring ", "Mentoring!" and "mentoring"
     * all land on `mentoring`. This is the single most valuable line in the
     * file: without it a tag list becomes unusable within a month and no amount
     * of UI rescues it.
     */
    public static function normaliseTag(string $t): string
    {
        $t = mb_strtolower(trim($t));
        $t = preg_replace('/[^\p{L}\p{N}]+/u', '-', $t) ?? $t;
        $t = trim($t, '-');
        return mb_substr($t, 0, 40);
    }

    private function ownsEntry(int $userId, int $entryId): bool
    {
        $st = $this->db->prepare('SELECT 1 FROM diary_entries WHERE id = ? AND author_id = ?');
        $st->execute([$entryId, $userId]);
        return (bool) $st->fetchColumn();
    }

    /* ── Tags ───────────────────────────────────────────────────────────── */

    /** Replace an entry's tags wholesale. @return list<string> what was stored */
    public function setTags(int $userId, int $entryId, array $tags): array
    {
        self::ensure();
        if (!$this->ownsEntry($userId, $entryId)) return [];

        $clean = [];
        foreach ($tags as $t) {
            $n = self::normaliseTag((string) $t);
            if ($n !== '') $clean[$n] = $n;                        // dedupe by value
            if (count($clean) >= self::MAX_TAGS_PER_ENTRY) break;
        }
        $clean = array_values($clean);

        $this->db->prepare('DELETE FROM diary_tags WHERE entry_id = ?')->execute([$entryId]);
        if ($clean !== []) {
            $ins = $this->db->prepare('INSERT INTO diary_tags (entry_id,user_id,tag,created_at) VALUES (?,?,?,?)');
            foreach ($clean as $t) $ins->execute([$entryId, $userId, $t, self::now()]);
        }
        return $clean;
    }

    /** @return list<string> */
    public function tagsFor(int $entryId): array
    {
        self::ensure();
        $st = $this->db->prepare('SELECT tag FROM diary_tags WHERE entry_id = ? ORDER BY tag');
        $st->execute([$entryId]);
        return array_map(static fn (array $r): string => (string) $r['tag'], $st->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /**
     * Tags for many entries at once — the list view needs them per row.
     *
     * @param list<int> $entryIds
     * @return array<int,list<string>>
     */
    public function tagsForMany(array $entryIds): array
    {
        self::ensure();
        $ids = array_values(array_unique(array_map('intval', $entryIds)));
        $out = array_fill_keys($ids, []);
        if ($ids === []) return $out;
        $in = implode(',', array_fill(0, count($ids), '?'));
        $st = $this->db->prepare("SELECT entry_id, tag FROM diary_tags WHERE entry_id IN ($in) ORDER BY tag");
        $st->execute($ids);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) $out[(int) $r['entry_id']][] = (string) $r['tag'];
        return $out;
    }

    /**
     * The member's own tag vocabulary with counts, most used first.
     *
     * Offering these back at the point of tagging is what actually prevents
     * drift — normalisation catches the typo, but only a suggestion list stops
     * somebody inventing "1-1" when they already use "one-to-one".
     *
     * @return list<array{tag:string,n:int}>
     */
    public function vocabulary(int $userId, int $limit = 60): array
    {
        self::ensure();
        $lim = max(1, min(300, $limit));
        $st = $this->db->prepare(
            'SELECT tag, COUNT(*) AS n FROM diary_tags WHERE user_id = ?
             GROUP BY tag ORDER BY n DESC, tag ASC LIMIT ' . $lim
        );
        $st->execute([$userId]);
        return array_map(static fn (array $r): array => ['tag' => (string) $r['tag'], 'n' => (int) $r['n']],
            $st->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /** Rename a tag everywhere it appears for this member. */
    public function renameTag(int $userId, string $from, string $to): int
    {
        self::ensure();
        $from = self::normaliseTag($from);
        $to   = self::normaliseTag($to);
        if ($from === '' || $to === '' || $from === $to) return 0;

        // Entries that already carry the destination would violate the primary
        // key on rename, so they lose the source tag instead of colliding.
        $dupe = $this->db->prepare(
            'DELETE FROM diary_tags WHERE user_id = ? AND tag = ? AND entry_id IN
             (SELECT entry_id FROM diary_tags WHERE user_id = ? AND tag = ?)'
        );
        $dupe->execute([$userId, $from, $userId, $to]);

        $st = $this->db->prepare('UPDATE diary_tags SET tag = ? WHERE user_id = ? AND tag = ?');
        $st->execute([$to, $userId, $from]);
        return $st->rowCount();
    }

    /* ── Pin / archive ──────────────────────────────────────────────────── */

    /** @return array{ok:bool,pinned?:bool,error?:string} */
    public function pin(int $userId, int $entryId, bool $on): array
    {
        self::ensure();
        if (!$this->ownsEntry($userId, $entryId)) return ['ok' => false, 'error' => 'That entry is not yours.'];
        if ($on) {
            $c = $this->db->prepare('SELECT COUNT(*) FROM diary_entries WHERE author_id = ? AND pinned = 1 AND id <> ?');
            $c->execute([$userId, $entryId]);
            if ((int) $c->fetchColumn() >= self::MAX_PINNED) {
                return ['ok' => false, 'error' => 'You already have ' . self::MAX_PINNED . ' pinned — unpin one first.'];
            }
        }
        $this->db->prepare('UPDATE diary_entries SET pinned = ? WHERE id = ? AND author_id = ?')
                 ->execute([$on ? 1 : 0, $entryId, $userId]);
        return ['ok' => true, 'pinned' => $on];
    }

    public function archive(int $userId, int $entryId, bool $on): bool
    {
        self::ensure();
        if (!$this->ownsEntry($userId, $entryId)) return false;
        // Archiving unpins: an entry cannot be both out of the way and held at
        // the top, and leaving it pinned is how an archive quietly does nothing.
        //
        // Two statements rather than one `CASE WHEN ? = 1`. With
        // ATTR_EMULATE_PREPARES = false PDO sends the bound value as TEXT, and
        // SQLite's type affinity rules make `'1' = 1` FALSE in a comparison with
        // no column to borrow affinity from — so the CASE silently never fired
        // and archiving left the entry pinned to the top of the list. A
        // conditional that quietly evaluates the wrong way is worth avoiding
        // even where it does work.
        $this->db->prepare('UPDATE diary_entries SET archived = ? WHERE id = ? AND author_id = ?')
                 ->execute([$on ? 1 : 0, $entryId, $userId]);
        if ($on) {
            $this->db->prepare('UPDATE diary_entries SET pinned = 0 WHERE id = ? AND author_id = ?')
                     ->execute([$entryId, $userId]);
        }
        return true;
    }

    /* ── Search ─────────────────────────────────────────────────────────── */

    /**
     * Find the member's entries.
     *
     * Scoped to entries they WROTE. Notebook sharing grants reading through the
     * notebook view; it deliberately does not fold other people's writing into
     * a personal search, because "my diary" returning somebody else's entry is
     * alarming even when the access is legitimate.
     *
     * Text matching covers the title, the body, AND tab bodies — an entry whose
     * answer is on its third tab must be findable, or tabs become a place things
     * go missing.
     *
     * @param array{q?:string,notebook?:int,kind?:string,tag?:string,from?:string,
     *              to?:string,archived?:bool,limit?:int} $f
     * @return list<array>
     */
    public function search(int $userId, array $f = []): array
    {
        self::ensure();
        DiaryTabs::ensure();

        $where = ['e.author_id = ?'];
        $args  = [$userId];

        $where[] = 'e.archived = ?';
        $args[]  = !empty($f['archived']) ? 1 : 0;

        if (isset($f['notebook'])) {
            $nb = (int) $f['notebook'];
            if ($nb >= 0) { $where[] = 'e.notebook_id = ?'; $args[] = $nb; }
        }
        if (!empty($f['kind']) && in_array((string) $f['kind'], DiaryJournal::KINDS, true)) {
            $where[] = 'e.kind = ?'; $args[] = (string) $f['kind'];
        }
        if (!empty($f['from'])) { $where[] = 'e.entry_date >= ?'; $args[] = (string) $f['from']; }
        if (!empty($f['to']))   { $where[] = 'e.entry_date <= ?'; $args[] = (string) $f['to']; }

        if (!empty($f['tag'])) {
            $where[] = 'EXISTS (SELECT 1 FROM diary_tags t WHERE t.entry_id = e.id AND t.tag = ?)';
            $args[]  = self::normaliseTag((string) $f['tag']);
        }

        $q = trim((string) ($f['q'] ?? ''));
        if ($q !== '') {
            // LIKE with escaped wildcards. Not FTS: SQLite's FTS5 is not
            // guaranteed present on shared hosting and MySQL/Postgres would each
            // need their own syntax. A member's own diary is hundreds of rows,
            // not millions — LIKE is the honest tool at this size, and pretending
            // otherwise would buy a portability problem for no user-visible gain.
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $q) . '%';
            $where[] = '(e.title LIKE ? ESCAPE \'\\\' OR e.body LIKE ? ESCAPE \'\\\''
                     . ' OR EXISTS (SELECT 1 FROM diary_entry_tabs tb WHERE tb.entry_id = e.id'
                     . ' AND (tb.title LIKE ? ESCAPE \'\\\' OR tb.body LIKE ? ESCAPE \'\\\')))';
            array_push($args, $like, $like, $like, $like);
        }

        $lim = max(1, min(200, (int) ($f['limit'] ?? 80)));
        $sql = 'SELECT e.* FROM diary_entries e WHERE ' . implode(' AND ', $where)
             . ' ORDER BY e.pinned DESC, e.entry_date DESC, e.id DESC LIMIT ' . $lim;

        $st = $this->db->prepare($sql);
        $st->execute($args);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // Tags and tab counts in two queries rather than two per row.
        $ids   = array_map(static fn (array $r): int => (int) $r['id'], $rows);
        $tags  = $this->tagsForMany($ids);
        $tabs  = (new DiaryTabs($this->db))->countsFor($ids);

        return array_map(static function (array $r) use ($tags, $tabs): array {
            $id = (int) $r['id'];
            return [
                'id'          => $id,
                'kind'        => (string) $r['kind'],
                'title'       => (string) $r['title'],
                'body'        => (string) $r['body'],
                'entry_date'  => (string) $r['entry_date'],
                'status'      => (string) $r['status'],
                'notebook_id' => (int) ($r['notebook_id'] ?? 0),
                'pinned'      => (int) ($r['pinned'] ?? 0) === 1,
                'archived'    => (int) ($r['archived'] ?? 0) === 1,
                'tags'        => $tags[$id] ?? [],
                'tab_count'   => $tabs[$id] ?? 1,
                'excerpt'     => DiaryJournal::excerpt((string) $r['body']),
            ];
        }, $rows);
    }

    /**
     * What the sidebar needs in one call: counts per bucket.
     *
     * @return array{all:int,pinned:int,archived:int,untagged:int,unfiled:int}
     */
    public function counts(int $userId): array
    {
        self::ensure();
        $one = function (string $sql, array $args) : int {
            $st = $this->db->prepare($sql); $st->execute($args); return (int) $st->fetchColumn();
        };
        return [
            'all'      => $one('SELECT COUNT(*) FROM diary_entries WHERE author_id = ? AND archived = 0', [$userId]),
            'pinned'   => $one('SELECT COUNT(*) FROM diary_entries WHERE author_id = ? AND archived = 0 AND pinned = 1', [$userId]),
            'archived' => $one('SELECT COUNT(*) FROM diary_entries WHERE author_id = ? AND archived = 1', [$userId]),
            'unfiled'  => $one('SELECT COUNT(*) FROM diary_entries WHERE author_id = ? AND archived = 0 AND notebook_id = 0', [$userId]),
            'untagged' => $one('SELECT COUNT(*) FROM diary_entries e WHERE e.author_id = ? AND e.archived = 0
                                AND NOT EXISTS (SELECT 1 FROM diary_tags t WHERE t.entry_id = e.id)', [$userId]),
        ];
    }
}
