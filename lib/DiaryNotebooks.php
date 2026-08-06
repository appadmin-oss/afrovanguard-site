<?php
/**
 * lib/DiaryNotebooks.php — Notebooks: the shelf a member's diary lives on.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY "NOTEBOOK" AND NOT "FOLDER"
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * A folder is a filesystem word. It says "a place where things are put" and
 * nothing about what is inside, which is why every product that calls its
 * containers folders ends up with a sidebar of nouns that mean nothing to
 * anybody but their author.
 *
 * A diary already has a real-world container and everyone alive has held one: a
 * notebook. You keep a notebook FOR something — the mentoring notebook, the
 * Lagos trip, the thing you are trying to work out. The word carries the intent
 * that "folder" drops, so the sidebar reads as a set of pursuits rather than a
 * set of directories. It also makes the rest of the model legible without a
 * manual: a notebook holds entries, an entry has tabs. Nobody needs that
 * explained.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHAT A NOTEBOOK IS, EXACTLY
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * One owner, a name, a colour, an optional description, and a position. Entries
 * point at one notebook or none — an entry with no notebook is not homeless, it
 * is in the diary itself, which is where most entries should stay. Notebooks are
 * a way to pull a THREAD out of the diary, not a filing requirement.
 *
 * ── DELIBERATELY FLAT ────────────────────────────────────────────────────────
 *
 * No nesting. A tree needs a mental model of where things live, and the moment
 * you have three levels you have the problem you built the tree to solve. Tags
 * ({@see DiaryJournal}) cut across notebooks and do the job nesting is usually
 * reached for, without any of the "which branch was that in" cost.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * SHARING
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * A notebook can be shared with named members at one of two levels:
 *
 *   viewer       can read every entry in it, including entries added later
 *   contributor  can also add their own entries to it
 *
 * Sharing the CONTAINER rather than the entries is the point. Per-entry sharing
 * already exists and it is the wrong shape for ongoing work: a mentor who should
 * see the mentoring notebook should not have to be re-granted access every time
 * a new entry lands in it. Access follows the notebook, so it covers entries
 * that do not exist yet.
 *
 * Two things a share never grants, both on purpose:
 *
 *   · Nobody but the owner can rename, recolour, archive or delete a notebook.
 *     A shared container with shared administration is a container that
 *     disappears one afternoon and nobody knows who did it.
 *   · A contributor's entries stay THEIRS. They can be seen by the notebook, but
 *     only their author can edit or delete them, and revoking the share does not
 *     transfer them to the owner.
 *
 * A read-only link token also exists, for the case where the reader has no
 * account at all. It exposes titles and bodies, never the member list.
 */
declare(strict_types=1);

final class DiaryNotebooks
{
    /** Share levels, weakest first. Order matters: `atLeast()` compares indexes. */
    public const ROLES = ['viewer', 'contributor'];

    /**
     * The palette a notebook spine can take.
     *
     * A fixed set, not a colour picker. Free choice produces sidebars of
     * near-identical muddy blues that carry no information; six deliberate,
     * mutually distinguishable colours mean the colour is actually a signal.
     * Values are the Afrovanguard/Africa GATES palette.
     */
    public const COLOURS = ['ink', 'green', 'gold', 'clay', 'sky', 'plum'];

    private static bool $ready = false;

    private PDO $db;

    public function __construct(?PDO $pdo = null) { $this->db = $pdo ?? Database::pdo(); }

    /* ── Schema ─────────────────────────────────────────────────────────── */

    public static function ensure(): void
    {
        if (self::$ready) return;
        $db  = Database::pdo();
        $drv = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
        $ddl = "
        CREATE TABLE IF NOT EXISTS diary_notebooks (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            owner_id    INTEGER NOT NULL,
            name        VARCHAR(120) NOT NULL DEFAULT '',
            description VARCHAR(400) NOT NULL DEFAULT '',
            colour      VARCHAR(16)  NOT NULL DEFAULT 'ink',
            sort        INTEGER NOT NULL DEFAULT 0,
            archived    INTEGER NOT NULL DEFAULT 0,
            share_token VARCHAR(64) NOT NULL DEFAULT '',
            created_at  VARCHAR(32) NOT NULL DEFAULT '',
            updated_at  VARCHAR(32) NOT NULL DEFAULT ''
        );
        CREATE INDEX IF NOT EXISTS idx_nb_owner ON diary_notebooks(owner_id, archived, sort);
        CREATE TABLE IF NOT EXISTS diary_notebook_members (
            notebook_id INTEGER NOT NULL,
            user_id     INTEGER NOT NULL,
            role        VARCHAR(16) NOT NULL DEFAULT 'viewer',
            created_at  VARCHAR(32) NOT NULL DEFAULT '',
            PRIMARY KEY (notebook_id, user_id)
        );
        CREATE INDEX IF NOT EXISTS idx_nbm_user ON diary_notebook_members(user_id);";
        $db->exec($drv === 'sqlite' ? $ddl : Database::translateDDL($ddl, $drv));

        // The entry side of the relationship. Added to the existing table rather
        // than a join table: an entry lives in at most one notebook, and a join
        // table for a to-one relationship is a way to end up with two.
        self::addCol('diary_entries', 'notebook_id', 'INTEGER NOT NULL DEFAULT 0');
        self::$ready = true;
    }

    private static function addCol(string $table, string $col, string $decl): void
    {
        try {
            if (!Database::columnExists($table, $col)) {
                Database::pdo()->exec('ALTER TABLE ' . $table . ' ADD COLUMN ' . $col . ' ' . $decl);
            }
        } catch (Throwable $e) { /* already there / race */ }
    }

    private static function now(): string { return gmdate('Y-m-d H:i:s'); }

    private static function cleanColour(string $c): string
    {
        $c = strtolower(trim($c));
        return in_array($c, self::COLOURS, true) ? $c : 'ink';
    }

    private static function cleanRole(string $r): string
    {
        $r = strtolower(trim($r));
        return in_array($r, self::ROLES, true) ? $r : 'viewer';
    }

    /* ── Owning ─────────────────────────────────────────────────────────── */

    /** @return array{ok:bool,id?:int,notebook?:array,error?:string} */
    public function create(int $ownerId, string $name, string $description = '', string $colour = 'ink'): array
    {
        self::ensure();
        $name = trim(mb_substr($name, 0, 120));
        if ($name === '') return ['ok' => false, 'error' => 'Give the notebook a name.'];

        // A cap, because an unbounded sidebar is not organisation. Somebody with
        // sixty notebooks has the problem notebooks were meant to solve.
        $c = $this->db->prepare('SELECT COUNT(*) FROM diary_notebooks WHERE owner_id = ? AND archived = 0');
        $c->execute([$ownerId]);
        $count = (int) $c->fetchColumn();
        if ($count >= 40) return ['ok' => false, 'error' => 'That is 40 notebooks already — archive one first.'];

        $sortQ = $this->db->prepare('SELECT COALESCE(MAX(sort),0) + 1 FROM diary_notebooks WHERE owner_id = ?');
        $sortQ->execute([$ownerId]);
        $sort = (int) $sortQ->fetchColumn();

        $this->db->prepare('INSERT INTO diary_notebooks (owner_id,name,description,colour,sort,archived,share_token,created_at,updated_at)
                            VALUES (?,?,?,?,?,0,\'\',?,?)')
            ->execute([$ownerId, $name, trim(mb_substr($description, 0, 400)), self::cleanColour($colour), $sort, self::now(), self::now()]);
        $id = (int) $this->db->lastInsertId();
        return ['ok' => true, 'id' => $id, 'notebook' => $this->one($ownerId, $id) ?? []];
    }

    /** Owner-only edits. Returns false when the notebook is not theirs. */
    public function update(int $ownerId, int $id, array $in): bool
    {
        self::ensure();
        $own = $this->db->prepare('SELECT 1 FROM diary_notebooks WHERE id = ? AND owner_id = ?');
        $own->execute([$id, $ownerId]);
        if (!$own->fetchColumn()) return false;

        $set = []; $args = [];
        if (array_key_exists('name', $in)) {
            $n = trim(mb_substr((string) $in['name'], 0, 120));
            if ($n === '') return false;                       // a nameless notebook is unfindable
            $set[] = 'name = ?'; $args[] = $n;
        }
        if (array_key_exists('description', $in)) { $set[] = 'description = ?'; $args[] = trim(mb_substr((string) $in['description'], 0, 400)); }
        if (array_key_exists('colour', $in))      { $set[] = 'colour = ?';      $args[] = self::cleanColour((string) $in['colour']); }
        if (array_key_exists('archived', $in))    { $set[] = 'archived = ?';    $args[] = !empty($in['archived']) ? 1 : 0; }
        if (array_key_exists('sort', $in))        { $set[] = 'sort = ?';        $args[] = max(0, min(9999, (int) $in['sort'])); }
        if ($set === []) return false;

        $set[] = 'updated_at = ?'; $args[] = self::now();
        $args[] = $id; $args[] = $ownerId;
        $this->db->prepare('UPDATE diary_notebooks SET ' . implode(', ', $set) . ' WHERE id = ? AND owner_id = ?')->execute($args);
        return true;
    }

    /**
     * Delete a notebook. Its entries are NOT deleted — they return to the diary.
     *
     * Deleting somebody's writing because they tidied a shelf is the kind of
     * data loss people never forgive, and the confirm dialog that would be
     * needed to make it safe is a dialog nobody reads. Entries survive; only the
     * grouping goes.
     */
    public function delete(int $ownerId, int $id): bool
    {
        self::ensure();
        $own = $this->db->prepare('SELECT 1 FROM diary_notebooks WHERE id = ? AND owner_id = ?');
        $own->execute([$id, $ownerId]);
        if (!$own->fetchColumn()) return false;

        $this->db->prepare('UPDATE diary_entries SET notebook_id = 0 WHERE notebook_id = ?')->execute([$id]);
        $this->db->prepare('DELETE FROM diary_notebook_members WHERE notebook_id = ?')->execute([$id]);
        $this->db->prepare('DELETE FROM diary_notebooks WHERE id = ? AND owner_id = ?')->execute([$id, $ownerId]);
        return true;
    }

    /* ── Reading ────────────────────────────────────────────────────────── */

    /**
     * Every notebook this member can see: their own, plus ones shared with them.
     *
     * Shared notebooks carry `owner_name` and are flagged `mine = false`, because
     * a sidebar that mixes the two without saying so is how somebody writes a
     * private entry into their mentor's notebook.
     *
     * @return list<array>
     */
    public function forUser(int $userId, bool $includeArchived = false): array
    {
        self::ensure();
        $rows = [];

        $sql = 'SELECT n.*, 1 AS mine, \'\' AS role FROM diary_notebooks n WHERE n.owner_id = ?'
             . ($includeArchived ? '' : ' AND n.archived = 0')
             . ' ORDER BY n.sort, n.id';
        $st = $this->db->prepare($sql);
        $st->execute([$userId]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) $rows[] = $this->shape($r, true, '');

        $st = $this->db->prepare(
            'SELECT n.*, 0 AS mine, m.role AS role, u.name AS owner_name
             FROM diary_notebook_members m
             JOIN diary_notebooks n ON n.id = m.notebook_id
             JOIN lms_users u ON u.id = n.owner_id
             WHERE m.user_id = ? AND n.archived = 0
             ORDER BY n.name'
        );
        $st->execute([$userId]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $s = $this->shape($r, false, (string) $r['role']);
            $s['owner_name'] = (string) ($r['owner_name'] ?? '');
            $rows[] = $s;
        }

        // Entry counts, scoped to what the viewer may actually see. An owner
        // counts everything in the notebook; a viewer counts the same, because
        // the whole point of a shared notebook is that its contents are shared.
        $ids = array_map(static fn (array $r): int => $r['id'], $rows);
        if ($ids !== []) {
            $in = implode(',', array_fill(0, count($ids), '?'));
            $c = $this->db->prepare("SELECT notebook_id, COUNT(*) AS n FROM diary_entries WHERE notebook_id IN ($in) GROUP BY notebook_id");
            $c->execute($ids);
            $counts = [];
            foreach ($c->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) $counts[(int) $r['notebook_id']] = (int) $r['n'];
            foreach ($rows as &$r) $r['entries'] = $counts[$r['id']] ?? 0;
            unset($r);
        }
        return $rows;
    }

    /** One notebook, if this member may see it at all. */
    public function one(int $userId, int $id): ?array
    {
        self::ensure();
        $st = $this->db->prepare('SELECT * FROM diary_notebooks WHERE id = ?');
        $st->execute([$id]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r) return null;
        if ((int) $r['owner_id'] === $userId) return $this->shape($r, true, '');
        $role = $this->roleFor($userId, $id);
        return $role === '' ? null : $this->shape($r, false, $role);
    }

    private function shape(array $r, bool $mine, string $role): array
    {
        return [
            'id'          => (int) $r['id'],
            'owner_id'    => (int) $r['owner_id'],
            'name'        => (string) $r['name'],
            'description' => (string) $r['description'],
            'colour'      => (string) $r['colour'],
            'sort'        => (int) $r['sort'],
            'archived'    => (int) $r['archived'] === 1,
            'shared_link' => (string) $r['share_token'] !== '',
            'mine'        => $mine,
            'role'        => $mine ? 'owner' : $role,
            'can_write'   => $mine || $role === 'contributor',
            'entries'     => 0,
        ];
    }

    /* ── Sharing ────────────────────────────────────────────────────────── */

    /** '' when this member has no share at all. */
    public function roleFor(int $userId, int $notebookId): string
    {
        self::ensure();
        $st = $this->db->prepare('SELECT role FROM diary_notebook_members WHERE notebook_id = ? AND user_id = ?');
        $st->execute([$notebookId, $userId]);
        return (string) ($st->fetchColumn() ?: '');
    }

    /** True when this member may READ everything in the notebook. */
    public function canRead(int $userId, int $notebookId): bool
    {
        if ($notebookId <= 0) return false;
        self::ensure();
        $st = $this->db->prepare('SELECT owner_id FROM diary_notebooks WHERE id = ?');
        $st->execute([$notebookId]);
        $owner = (int) ($st->fetchColumn() ?: 0);
        if ($owner === 0) return false;
        return $owner === $userId || $this->roleFor($userId, $notebookId) !== '';
    }

    /** True when this member may ADD entries to it. */
    public function canWrite(int $userId, int $notebookId): bool
    {
        if ($notebookId <= 0) return false;
        self::ensure();
        $st = $this->db->prepare('SELECT owner_id FROM diary_notebooks WHERE id = ?');
        $st->execute([$notebookId]);
        $owner = (int) ($st->fetchColumn() ?: 0);
        if ($owner === 0) return false;
        return $owner === $userId || $this->roleFor($userId, $notebookId) === 'contributor';
    }

    /**
     * Owner grants a member access by email.
     *
     * @return array{ok:bool,user?:array,error?:string}
     */
    public function share(int $ownerId, int $notebookId, string $email, string $role = 'viewer'): array
    {
        self::ensure();
        $own = $this->db->prepare('SELECT name FROM diary_notebooks WHERE id = ? AND owner_id = ?');
        $own->execute([$notebookId, $ownerId]);
        $nb = $own->fetch(PDO::FETCH_ASSOC);
        if (!$nb) return ['ok' => false, 'error' => 'Notebook not found.'];

        $email = strtolower(trim($email));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) return ['ok' => false, 'error' => 'Enter a valid email.'];

        $u = $this->db->prepare('SELECT id, name FROM lms_users WHERE LOWER(email) = ?');
        $u->execute([$email]);
        $target = $u->fetch(PDO::FETCH_ASSOC);
        if (!$target) return ['ok' => false, 'error' => 'No member has that email.'];
        if ((int) $target['id'] === $ownerId) return ['ok' => false, 'error' => 'That notebook is already yours.'];

        $role = self::cleanRole($role);
        // Portable upsert. `INSERT OR IGNORE` is SQLite-only — the existing
        // per-entry share uses it and would be a syntax error the moment this
        // database becomes MySQL. Delete-then-insert works everywhere and also
        // gives us role CHANGES for free.
        $this->db->prepare('DELETE FROM diary_notebook_members WHERE notebook_id = ? AND user_id = ?')
                 ->execute([$notebookId, (int) $target['id']]);
        $this->db->prepare('INSERT INTO diary_notebook_members (notebook_id,user_id,role,created_at) VALUES (?,?,?,?)')
                 ->execute([$notebookId, (int) $target['id'], $role, self::now()]);

        $this->notifyShared((int) $target['id'], $email, (string) $target['name'], $ownerId, (string) $nb['name'], $role);

        return ['ok' => true, 'user' => [
            'id' => (int) $target['id'], 'name' => (string) $target['name'],
            'email' => $email, 'role' => $role,
        ]];
    }

    public function unshare(int $ownerId, int $notebookId, int $userId): bool
    {
        self::ensure();
        $own = $this->db->prepare('SELECT 1 FROM diary_notebooks WHERE id = ? AND owner_id = ?');
        $own->execute([$notebookId, $ownerId]);
        if (!$own->fetchColumn()) return false;
        $this->db->prepare('DELETE FROM diary_notebook_members WHERE notebook_id = ? AND user_id = ?')
                 ->execute([$notebookId, $userId]);
        return true;
    }

    /** @return list<array{id:int,name:string,email:string,role:string}> */
    public function members(int $ownerId, int $notebookId): array
    {
        self::ensure();
        $st = $this->db->prepare(
            'SELECT u.id, u.name, u.email, m.role
             FROM diary_notebook_members m
             JOIN diary_notebooks n ON n.id = m.notebook_id AND n.owner_id = ?
             JOIN lms_users u ON u.id = m.user_id
             WHERE m.notebook_id = ? ORDER BY u.name'
        );
        $st->execute([$ownerId, $notebookId]);
        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'], 'name' => (string) $r['name'],
            'email' => (string) $r['email'], 'role' => (string) $r['role'],
        ], $st->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /** Mint (or return) a read-only link for the whole notebook. */
    public function linkToken(int $ownerId, int $notebookId): ?string
    {
        self::ensure();
        $st = $this->db->prepare('SELECT share_token FROM diary_notebooks WHERE id = ? AND owner_id = ?');
        $st->execute([$notebookId, $ownerId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row === false) return null;
        $tok = (string) ($row['share_token'] ?? '');
        if ($tok === '') {
            $tok = bin2hex(random_bytes(16));
            $this->db->prepare('UPDATE diary_notebooks SET share_token = ?, updated_at = ? WHERE id = ? AND owner_id = ?')
                     ->execute([$tok, self::now(), $notebookId, $ownerId]);
        }
        return $tok;
    }

    public function revokeLink(int $ownerId, int $notebookId): bool
    {
        self::ensure();
        $st = $this->db->prepare("UPDATE diary_notebooks SET share_token = '', updated_at = ? WHERE id = ? AND owner_id = ?");
        $st->execute([self::now(), $notebookId, $ownerId]);
        return $st->rowCount() > 0;
    }

    /**
     * A notebook by its link token — read-only, no session needed.
     *
     * Returns the notebook and its entries. Never returns the member list: who
     * else can see somebody's notebook is not public information, and a share
     * link is by definition held by people you have not vetted.
     */
    public function byLinkToken(string $token, int $limit = 100): ?array
    {
        self::ensure();
        if (!preg_match('/^[a-f0-9]{24,64}$/', $token)) return null;
        $st = $this->db->prepare(
            'SELECT n.*, u.name AS owner_name FROM diary_notebooks n
             JOIN lms_users u ON u.id = n.owner_id
             WHERE n.share_token = ? AND n.archived = 0 LIMIT 1'
        );
        $st->execute([$token]);
        $n = $st->fetch(PDO::FETCH_ASSOC);
        if (!$n) return null;

        $lim = max(1, min(300, $limit));
        $e = $this->db->prepare(
            'SELECT e.id, e.title, e.body, e.entry_date, e.kind, u.name AS author_name
             FROM diary_entries e JOIN lms_users u ON u.id = e.author_id
             WHERE e.notebook_id = ? ORDER BY e.entry_date DESC, e.id DESC LIMIT ' . $lim
        );
        $e->execute([(int) $n['id']]);

        return [
            'notebook' => [
                'name'        => (string) $n['name'],
                'description' => (string) $n['description'],
                'colour'      => (string) $n['colour'],
                'owner_name'  => (string) $n['owner_name'],
            ],
            'entries' => $e->fetchAll(PDO::FETCH_ASSOC) ?: [],
        ];
    }

    /* ── Moving entries ─────────────────────────────────────────────────── */

    /**
     * Move one of the member's own entries into a notebook (0 = back to the diary).
     *
     * The author check and the notebook check are separate on purpose: you may
     * only move entries you WROTE, and only into notebooks you may WRITE TO.
     * Either alone would let somebody either file other people's writing or
     * push their own into a notebook they were only given reading rights on.
     */
    public function moveEntry(int $userId, int $entryId, int $notebookId): bool
    {
        self::ensure();
        $own = $this->db->prepare('SELECT 1 FROM diary_entries WHERE id = ? AND author_id = ?');
        $own->execute([$entryId, $userId]);
        if (!$own->fetchColumn()) return false;
        if ($notebookId !== 0 && !$this->canWrite($userId, $notebookId)) return false;

        $this->db->prepare('UPDATE diary_entries SET notebook_id = ? WHERE id = ? AND author_id = ?')
                 ->execute([$notebookId, $entryId, $userId]);
        return true;
    }

    /** @return int how many actually moved */
    public function moveMany(int $userId, array $entryIds, int $notebookId): int
    {
        $n = 0;
        foreach (array_slice($entryIds, 0, 200) as $id) {
            if ($this->moveEntry($userId, (int) $id, $notebookId)) $n++;
        }
        return $n;
    }

    /* ── Notification ───────────────────────────────────────────────────── */

    /**
     * Tell somebody a notebook has been shared with them. Best-effort.
     *
     * Both channels, because they answer different questions: the in-app
     * notification is what makes it discoverable next time they open the portal,
     * and the email is what reaches somebody who is not going to open the portal
     * unless something tells them to.
     */
    private function notifyShared(int $userId, string $email, string $name, int $ownerId, string $notebook, string $role): void
    {
        $ownerName = '';
        try {
            $st = $this->db->prepare('SELECT name FROM lms_users WHERE id = ?');
            $st->execute([$ownerId]);
            $ownerName = trim((string) ($st->fetchColumn() ?: ''));
        } catch (Throwable $e) {}
        $who = $ownerName !== '' ? $ownerName : 'A member';

        if (class_exists('Notifications')) {
            try {
                Notifications::push(
                    $userId, 'diary',
                    $who . ' shared a notebook with you',
                    '“' . $notebook . '” is now in your diary under Shared with me.',
                    '/portal/#diary',
                    'nb-share-' . $userId . '-' . $notebook
                );
            } catch (Throwable $e) { /* best-effort */ }
        }

        if (class_exists('Mailer')) {
            try {
                $site = defined('SITE_URL') ? rtrim(SITE_URL, '/') : '';
                $verb = $role === 'contributor'
                    ? 'You can read it and add your own entries to it.'
                    : 'You can read everything in it, including entries added later.';
                $html = Mailer::shell(
                    'A notebook has been shared with you',
                    [
                        htmlspecialchars($who) . ' shared the notebook <strong>' . htmlspecialchars($notebook) . '</strong> with you.',
                        $verb,
                        'It will appear in your diary under “Shared with me”.',
                    ],
                    ['text' => 'Open your diary', 'url' => $site . '/portal/#diary'],
                    $who . ' shared a notebook with you on Afrovanguard.'
                );
                Mailer::send($email, $who . ' shared “' . $notebook . '” with you', $html);
            } catch (Throwable $e) { error_log('[notebooks] share mail: ' . $e->getMessage()); }
        }
    }
}
