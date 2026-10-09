<?php
/**
 * lib/MentorPortal.php — the read model behind /mentorship/mentor/.
 *
 * ── WHAT THE HANDOFF SHIPPED, AND WHAT THIS IS ──────────────────────────────
 * The drop-in was a SKELETON: signatures, return shapes, and one worked roster
 * query, with an instruction at the top to check every table and column before
 * using it. Checked. The query named five columns and one table this database
 * does not have, and its date maths (NOW, DATEDIFF, DATE_ADD) is MySQL-only
 * while this application also runs on SQLite and Postgres. The SHAPE of that
 * query — one pre-aggregated subquery per pairing, filters and sorts in SQL,
 * counts in the same pass — is right, and is kept. The SQL is rewritten.
 *
 *   users            → lms_users
 *   u.chapter        → the pairing's COHORT name (mentor_cohorts), which is what
 *                      a chapter is here; there is no chapter on an account
 *   m.track          → added (see ensure); it is the pairing's focus, which the
 *                      roster searches and the case file prints
 *   m.stage          → added; 1..6, the six-step pairing stage §4 draws
 *   m.started_at     → added, backfilled from created_at
 *   m.ends_at        → added; empty means no planned end
 *   s.status         → s.attendance. Both columns exist on mentor_sessions and
 *                      Mentorship::markAttendance writes BOTH; attendance is
 *                      the one the rest of the code reads
 *   DATEDIFF/NOW     → cutoff timestamps computed in PHP and compared as
 *                      strings. Every stored timestamp here is
 *                      'Y-m-d H:i:s' in UTC, which sorts and compares
 *                      correctly as text on all three engines
 *
 * ── EVERY METHOD IS SCOPED TO ONE MENTOR ────────────────────────────────────
 * The mentor id comes from the session, never from the request. ownsPairing()
 * is the gate, and mentorship/api.php calls it on every id it is handed —
 * including each id inside a bulk action. A mentor who edits the id in a URL
 * gets a 404, not somebody else's mentee.
 */
declare(strict_types=1);

final class MentorPortal
{
    public const PER_PAGE = 25;

    /** The six steps a pairing walks, in order. Stored as 1..6. */
    public const STAGES = ['Matched', 'Kick-off', 'Goals agreed', 'Meeting regularly', 'Mid-point review', 'Planned close'];

    /**
     * Goals are ROWS, not a paragraph.
     *
     * The handoff keeps them in one free-text column, which is how the old
     * page of browser dialogs stored them. A paragraph cannot be half done: "Goals
     * agreed" becomes a tick box, neither side can say which of the three
     * things actually happened, and the closing conversation — "look back at
     * the goals together", step 2 of a planned close — has nothing to look
     * back AT. So a pairing carries up to three live goals, each with how you
     * will both know it happened and, if you set one, a date.
     *
     * mentorships.goals is kept in step on every write (the live titles,
     * joined). The mentee's own portal, the roster's "Goals not set" filter
     * and Today's "Agree goals with …" all read that column and none of them
     * had to change.
     */
    public const GOAL_MAX = 3;
    public const GOAL_STATUS = ['open' => 'Working on it', 'met' => 'Met', 'dropped' => 'Set aside'];

    /** The seven Vanguard Quest values, in the order the spec lists them. */
    public const VALUES = [
        'individuation' => 'Individuation',
        'faith'         => 'Faith',
        'diligence'     => 'Diligence',
        'accountability'=> 'Accountability',
        'responsibility'=> 'Responsibility',
        'culture'       => 'Cultural Appreciation',
        'communal'      => 'Communal Spirit',
    ];

    /**
     * What each value is taught by, and the two things a mentor is prompted to
     * look for. The owner's wording, from the prototype — a value is a thing
     * young people have been told in a particular sentence, and a mentor
     * observing it should be reading the same sentence they were taught.
     */
    public const VALUE_MOTTO = [
        'individuation'  => 'Stop copying. Become somebody.',
        'faith'          => 'Believe something worth dying for.',
        'diligence'      => 'Talent is cheap. Discipline is rare.',
        'accountability' => 'Stop explaining. Start answering.',
        'responsibility' => 'If you see it, own it.',
        'culture'        => 'You cannot build the future if you are ashamed of your roots.',
        'communal'       => 'If your success ends with you, it is too small.',
    ];
    public const VALUE_CHIPS = [
        'individuation'  => ['Made a choice that was clearly their own', 'Explained who they are becoming'],
        'faith'          => ['Acted on a conviction under pressure', 'Named what they believe and why'],
        'diligence'      => ['Finished what they promised, on time', 'Arrived prepared'],
        'accountability' => ['Owned a mistake without excuses', 'Repaired something they got wrong'],
        'responsibility' => ['Fixed a problem nobody assigned', 'Took on a task without being asked'],
        'culture'        => ['Explained a tradition and what it teaches', 'Brought culture into their work'],
        'communal'       => ['Helped a peer succeed', 'Shared credit with the team'],
    ];
    public const LEVELS = ['Not seen', 'Emerging', 'Demonstrated', 'Exemplary'];

    /** What a mentor must pass before they can accept anybody. */
    public const MODULES = [
        'safeguarding' => ['title' => 'Safeguarding young people', 'required' => true,  'lapses_days' => 365],
        'standard'     => ['title' => 'The Afrovanguard mentorship standard', 'required' => true,  'lapses_days' => 0],
        'goals'        => ['title' => 'Goal setting that holds', 'required' => true,  'lapses_days' => 0],
        'endings'      => ['title' => 'Planned endings', 'required' => true,  'lapses_days' => 0],
        'difficult'    => ['title' => 'Difficult conversations', 'required' => false, 'lapses_days' => 0],
        'cultures'     => ['title' => 'Mentoring across cultures', 'required' => false, 'lapses_days' => 0],
    ];
    public const PASS_MARK = 80;

    /** Messages reach a young person between these hours, local time. */
    public const SEND_FROM = 8;
    public const SEND_TO   = 20;

    private PDO $db;
    private int $mentorId;

    public function __construct(int $mentorId)
    {
        $this->mentorId = $mentorId;
        self::ensure();
        $this->db = Database::pdo();
    }

    public function mentorId(): int { return $this->mentorId; }

    /* ══ SCHEMA ══════════════════════════════════════════════════════════════
       Additive, and run once per request. Everything here is a column or table
       the portal needs that the mentorship core never had; nothing is renamed
       or dropped, so an installation that upgrades keeps every pairing, every
       session and every logged hour exactly as it was. */

    private static bool $ready = false;

    public static function ensure(): void
    {
        if (self::$ready) return; self::$ready = true;
        Mentorship::ensure();
        $db = Database::pdo();

        $ddl = "CREATE TABLE IF NOT EXISTS mentor_values (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            mentorship_id INTEGER NOT NULL,
            value_key VARCHAR(24) NOT NULL DEFAULT '',
            level INTEGER NOT NULL DEFAULT 0,
            evidence TEXT NOT NULL DEFAULT '',
            month_key VARCHAR(7) NOT NULL DEFAULT '',
            observed_at VARCHAR(32) NOT NULL DEFAULT ''
        );
        CREATE TABLE IF NOT EXISTS mentor_checkins (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            mentorship_id INTEGER NOT NULL,
            week INTEGER NOT NULL DEFAULT 0,
            due_at VARCHAR(32) NOT NULL DEFAULT '',
            sent_at VARCHAR(32) NOT NULL DEFAULT '',
            q0 INTEGER NOT NULL DEFAULT -1,
            q1 INTEGER NOT NULL DEFAULT -1,
            q2 INTEGER NOT NULL DEFAULT -1,
            flagged INTEGER NOT NULL DEFAULT 0,
            cleared_at VARCHAR(32) NOT NULL DEFAULT ''
        );
        CREATE TABLE IF NOT EXISTS mentor_messages (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            mentorship_id INTEGER NOT NULL,
            sender_id INTEGER NOT NULL DEFAULT 0,
            body TEXT NOT NULL DEFAULT '',
            created_at VARCHAR(32) NOT NULL DEFAULT '',
            send_after VARCHAR(32) NOT NULL DEFAULT ''
        );
        CREATE TABLE IF NOT EXISTS mentor_concerns (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            case_no VARCHAR(24) NOT NULL DEFAULT '',
            mentor_id INTEGER NOT NULL DEFAULT 0,
            mentorship_id INTEGER NOT NULL DEFAULT 0,
            category VARCHAR(40) NOT NULL DEFAULT '',
            facts TEXT NOT NULL DEFAULT '',
            status VARCHAR(16) NOT NULL DEFAULT 'open',
            created_at VARCHAR(32) NOT NULL DEFAULT ''
        );
        CREATE TABLE IF NOT EXISTS mentor_academy (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL DEFAULT 0,
            module_key VARCHAR(32) NOT NULL DEFAULT '',
            score INTEGER NOT NULL DEFAULT 0,
            passed INTEGER NOT NULL DEFAULT 0,
            completed_at VARCHAR(32) NOT NULL DEFAULT ''
        );
        CREATE TABLE IF NOT EXISTS mentor_notes (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            mentor_id INTEGER NOT NULL DEFAULT 0,
            body TEXT NOT NULL DEFAULT '',
            created_at VARCHAR(32) NOT NULL DEFAULT '',
            ack_at VARCHAR(32) NOT NULL DEFAULT ''
        );
        CREATE TABLE IF NOT EXISTS mentor_reflect_replies (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            entry_id INTEGER NOT NULL DEFAULT 0,
            mentor_id INTEGER NOT NULL DEFAULT 0,
            reply TEXT NOT NULL DEFAULT '',
            created_at VARCHAR(32) NOT NULL DEFAULT ''
        );
        CREATE TABLE IF NOT EXISTS diary_shares (
            entry_id INTEGER NOT NULL,
            user_id  INTEGER NOT NULL,
            created_at TEXT NOT NULL DEFAULT '',
            PRIMARY KEY (entry_id, user_id)
        );
        CREATE TABLE IF NOT EXISTS mentor_goals (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            mentorship_id INTEGER NOT NULL,
            title VARCHAR(200) NOT NULL DEFAULT '',
            measure TEXT NOT NULL DEFAULT '',
            due_on VARCHAR(10) NOT NULL DEFAULT '',
            status VARCHAR(12) NOT NULL DEFAULT 'open',
            sort_no INTEGER NOT NULL DEFAULT 0,
            created_at VARCHAR(32) NOT NULL DEFAULT '',
            updated_at VARCHAR(32) NOT NULL DEFAULT '',
            closed_at VARCHAR(32) NOT NULL DEFAULT ''
        );
        CREATE TABLE IF NOT EXISTS mentor_undo (
            token VARCHAR(40) PRIMARY KEY,
            mentor_id INTEGER NOT NULL DEFAULT 0,
            op VARCHAR(24) NOT NULL DEFAULT '',
            payload TEXT NOT NULL DEFAULT '',
            created_at VARCHAR(32) NOT NULL DEFAULT ''
        );";
        try { Database::execSchema($db, $ddl); }
        catch (Throwable $e) { error_log('[mentorportal] ensure: ' . $e->getMessage()); }

        self::addCol('mentorships', 'stage', 'INTEGER NOT NULL DEFAULT 1');
        self::addCol('mentorships', 'track', "VARCHAR(80) NOT NULL DEFAULT ''");
        self::addCol('mentorships', 'started_at', "VARCHAR(32) NOT NULL DEFAULT ''");
        self::addCol('mentorships', 'ends_at', "VARCHAR(32) NOT NULL DEFAULT ''");
        self::addCol('mentorships', 'close_steps', "VARCHAR(48) NOT NULL DEFAULT ''");
        self::addCol('mentor_sessions', 'topics', "VARCHAR(255) NOT NULL DEFAULT ''");
        self::addCol('mentor_sessions', 'mood', "VARCHAR(24) NOT NULL DEFAULT ''");

        // MP-03. The roster's two hot paths: every pairing of one mentor, and
        // every session of one pairing in time order.
        Database::ensureIndex($db, 'idx_mentorships_mentor_status', 'mentorships', 'mentor_id, status');
        Database::ensureIndex($db, 'idx_msessions_pair_when', 'mentor_sessions', 'mentorship_id, scheduled_at');
        Database::ensureIndex($db, 'idx_mvalues_pair', 'mentor_values', 'mentorship_id, month_key');
        Database::ensureIndex($db, 'idx_mcheckins_pair', 'mentor_checkins', 'mentorship_id, due_at');
        Database::ensureIndex($db, 'idx_mmessages_pair', 'mentor_messages', 'mentorship_id, created_at');
        Database::ensureIndex($db, 'idx_macademy_user', 'mentor_academy', 'user_id, module_key');
        Database::ensureIndex($db, 'idx_mgoals_pair', 'mentor_goals', 'mentorship_id, sort_no');

        // started_at is what "paired since" and "days since" fall back to. A
        // pairing created before this column existed has one — its created_at —
        // and leaving it empty would make every old pairing look brand new.
        try { $db->exec("UPDATE mentorships SET started_at = created_at WHERE started_at = '' AND created_at <> ''"); }
        catch (Throwable $e) { /* nothing to backfill */ }
    }

    private static function addCol(string $table, string $col, string $decl): void
    {
        try {
            if (Database::columnExists($table, $col)) return;
            Database::pdo()->exec('ALTER TABLE ' . $table . ' ADD COLUMN ' . $col . ' ' . $decl);
        } catch (Throwable $e) { /* already there, or the engine said so differently */ }
    }

    public static function now(): string { return gmdate('Y-m-d H:i:s'); }
    private static function ago(int $days): string { return gmdate('Y-m-d H:i:s', time() - $days * 86400); }
    private static function ahead(int $days): string { return gmdate('Y-m-d H:i:s', time() + $days * 86400); }

    /* ══ ACCESS ══════════════════════════════════════════════════════════════ */

    public function ownsPairing(int $pairingId): bool
    {
        if ($pairingId <= 0) return false;
        $s = $this->db->prepare('SELECT 1 FROM mentorships WHERE id = ? AND mentor_id = ?');
        $s->execute([$pairingId, $this->mentorId]);
        return (bool) $s->fetchColumn();
    }

    public function ownsSession(int $sessionId): bool
    {
        $s = $this->db->prepare('SELECT 1 FROM mentor_sessions s JOIN mentorships m ON m.id = s.mentorship_id
                                  WHERE s.id = ? AND m.mentor_id = ?');
        $s->execute([$sessionId, $this->mentorId]);
        return (bool) $s->fetchColumn();
    }

    /* ══ THE MENTOR ══════════════════════════════════════════════════════════ */

    public function me(): array
    {
        $st = $this->db->prepare('SELECT name, email FROM lms_users WHERE id = ?');
        $st->execute([$this->mentorId]);
        $u = $st->fetch(PDO::FETCH_ASSOC) ?: ['name' => 'Mentor', 'email' => ''];
        $a = $this->academy();
        return [
            'name'         => (string) $u['name'],
            'email'        => (string) $u['email'],
            'initials'     => self::initials((string) $u['name']),
            'academy_line' => 'Academy ' . $a['done'] . '/' . $a['required'] . ' · ' . ($a['safeguarding_ok'] ? 'cleared' : 'not cleared'),
            'cleared'      => (bool) $a['safeguarding_ok'],
        ];
    }

    public static function initials(string $name): string
    {
        $p = preg_split('/\s+/u', trim($name)) ?: [];
        if (!$p || $p[0] === '') return '?';
        return mb_strtoupper(mb_substr($p[0], 0, 1) . (count($p) > 1 ? mb_substr((string) end($p), 0, 1) : ''));
    }

    /* ══ NAV BADGES — ONE QUERY (MP-05) ══════════════════════════════════════
       Six scalar sub-selects in one SELECT rather than six round trips. The
       badges are on every page of the portal; six queries per page, on a shared
       host, is most of the budget spent before the page has drawn anything. */

    public function navBadges(): array
    {
        $now  = self::now();
        $d21  = self::ago(21);
        $month = gmdate('Y-m');
        $sql = "SELECT
          (SELECT COUNT(*) FROM mentorships r WHERE r.mentor_id = :m1 AND r.status = 'pending') AS requests,
          (SELECT COUNT(*) FROM mentor_sessions s JOIN mentorships p ON p.id = s.mentorship_id
            WHERE p.mentor_id = :m2 AND p.status = 'active' AND s.attendance = 'scheduled' AND s.scheduled_at <> '' AND s.scheduled_at < :now1) AS today,
          (SELECT COUNT(*) FROM mentorships p WHERE p.mentor_id = :m3 AND p.status = 'active'
             AND NOT EXISTS (SELECT 1 FROM mentor_values v WHERE v.mentorship_id = p.id AND v.month_key = :mon)) AS vals,
          (SELECT COUNT(*) FROM mentor_checkins c JOIN mentorships p ON p.id = c.mentorship_id
            WHERE p.mentor_id = :m4 AND c.sent_at = '' AND c.due_at <= :now2) AS checkins,
          (SELECT COUNT(*) FROM diary_shares sh
             JOIN diary_entries d ON d.id = sh.entry_id
             JOIN mentorships p ON p.mentee_id = d.author_id AND p.mentor_id = :m5 AND p.status = 'active'
            WHERE sh.user_id = :m6
              AND NOT EXISTS (SELECT 1 FROM mentor_reflect_replies rr WHERE rr.entry_id = d.id AND rr.mentor_id = :m8)) AS reflections,
          (SELECT COUNT(*) FROM mentorships p WHERE p.mentor_id = :m7 AND p.status = 'active'
             AND COALESCE(NULLIF(p.started_at,''), p.created_at) < :d21
             AND NOT EXISTS (SELECT 1 FROM mentor_sessions s2 WHERE s2.mentorship_id = p.id AND s2.attendance = 'attended' AND s2.scheduled_at > :d21b)) AS stale";
        try {
            $st = $this->db->prepare($sql);
            $st->execute([
                ':m1' => $this->mentorId, ':m2' => $this->mentorId, ':m3' => $this->mentorId,
                ':m4' => $this->mentorId, ':m5' => $this->mentorId, ':m6' => $this->mentorId,
                ':m7' => $this->mentorId, ':m8' => $this->mentorId, ':now1' => $now, ':now2' => $now, ':mon' => $month,
                ':d21' => $d21, ':d21b' => $d21,
            ]);
            $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) { error_log('[mentorportal] navBadges: ' . $e->getMessage()); $r = []; }

        $academy = $this->academy();
        return [
            'today'       => (int) ($r['today'] ?? 0) + (int) ($r['stale'] ?? 0),
            'values'      => (int) ($r['vals'] ?? 0),
            'checkins'    => (int) ($r['checkins'] ?? 0),
            'reflections' => (int) ($r['reflections'] ?? 0),
            'requests'    => (int) ($r['requests'] ?? 0),
            'academy'     => max(0, $academy['required'] - $academy['done']),
        ];
    }

    /* ══ ROSTER (MP-02, MP-04, MP-06, MP-07) ═════════════════════════════════
       Four queries, whatever the page: the rows, the per-filter counts, the
       filtered total, and the unfiltered total — the last three in ONE pass.
       Nothing is loaded and then thrown away. */

    private const FILTERS = ['all', 'attn', 'wait', 'goals', 'log', 'closing'];
    private const SORTS   = ['wait', 'name', 'kept', 'stage'];

    public function roster(string $q, string $filter, string $sort, int $page, bool $onlyPage = false): array
    {
        $filter = in_array($filter, self::FILTERS, true) ? $filter : 'all';
        $sort   = in_array($sort, self::SORTS, true) ? $sort : 'wait';
        $page   = max(1, $page);

        $now = self::now(); $d21 = self::ago(21); $soon = self::ahead(45);
        $p = [':mid' => $this->mentorId, ':now' => $now, ':d21' => $d21, ':soon' => $soon];

        // One pre-aggregated row per pairing. GROUP BY on the child table, not a
        // correlated sub-select per column — which is the same query run four
        // times for every mentee on the page.
        $agg = "LEFT JOIN (
                  SELECT mentorship_id,
                         MAX(CASE WHEN attendance = 'attended' THEN scheduled_at END) AS last_at,
                         SUM(CASE WHEN attendance = 'attended' THEN 1 ELSE 0 END)     AS attended,
                         SUM(CASE WHEN attendance IN ('attended','missed') THEN 1 ELSE 0 END) AS held,
                         MIN(CASE WHEN attendance = 'scheduled' AND scheduled_at > :now THEN scheduled_at END) AS next_at,
                         SUM(CASE WHEN attendance = 'scheduled' AND scheduled_at <> '' AND scheduled_at < :now THEN 1 ELSE 0 END) AS to_log
                    FROM mentor_sessions GROUP BY mentorship_id
                ) s ON s.mentorship_id = m.id";
        $base = "FROM mentorships m
                 JOIN lms_users u ON u.id = m.mentee_id
                 LEFT JOIN mentor_cohorts ch ON ch.id = m.cohort_id
                 {$agg}
                 WHERE m.mentor_id = :mid AND m.status = 'active'";

        $since = "COALESCE(NULLIF(s.last_at, ''), NULLIF(m.started_at, ''), m.created_at)";
        $F = [
            'all'     => '1=1',
            'attn'    => "({$since} < :d21 OR (COALESCE(s.held,0) > 0 AND (1.0 * s.attended / s.held) < 0.7) OR m.goals = '')",
            'wait'    => "{$since} < :d21",
            'goals'   => "m.goals = ''",
            'log'     => 'COALESCE(s.to_log, 0) > 0',
            'closing' => "(m.stage >= 6 OR (m.ends_at <> '' AND m.ends_at < :soon))",
        ];
        $S = [
            'wait'  => "{$since} ASC, u.name ASC",
            'name'  => 'u.name ASC, m.id ASC',
            'kept'  => '(1.0 * COALESCE(s.attended,0) / CASE WHEN COALESCE(s.held,0) = 0 THEN 1 ELSE s.held END) ASC, u.name ASC',
            'stage' => 'm.stage DESC, u.name ASC',
        ];

        $search = '';
        if ($q !== '') {
            // LIKE wildcards in the search box are the searcher's text, not
            // operators: a mentee called "100%" must be findable.
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
            $search = " AND (u.name LIKE :q ESCAPE '\\' OR m.track LIKE :q ESCAPE '\\' OR COALESCE(ch.name,'') LIKE :q ESCAPE '\\')";
            $p[':q'] = $like;
        }

        $limit  = $onlyPage ? self::PER_PAGE : $page * self::PER_PAGE;
        $offset = $onlyPage ? ($page - 1) * self::PER_PAGE : 0;

        $rows = [];
        try {
            $rows = $this->run(
                "SELECT m.id AS pairing_id, u.name, m.track, m.programme, COALESCE(ch.name, '') AS chapter, m.stage,
                        {$since} AS since_at, s.next_at, COALESCE(s.to_log,0) AS to_log, m.goals,
                        COALESCE(s.attended,0) AS attended, COALESCE(s.held,0) AS held
                 {$base}{$search} AND ({$F[$filter]})
                 ORDER BY {$S[$sort]} LIMIT {$limit} OFFSET {$offset}", $p
            )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) { error_log('[mentorportal] roster rows: ' . $e->getMessage()); }

        // The counts, the filtered total and the grand total, in one pass.
        $counts = array_fill_keys(self::FILTERS, 0); $total = 0; $all = 0;
        try {
            $sel = 'SELECT COUNT(*) AS all_n';
            foreach ($F as $k => $cond) $sel .= ", SUM(CASE WHEN {$cond} THEN 1 ELSE 0 END) AS c_{$k}";
            $sel .= ", SUM(CASE WHEN ({$F[$filter]})" . ($q !== '' ? " AND (u.name LIKE :q ESCAPE '\\' OR m.track LIKE :q ESCAPE '\\' OR COALESCE(ch.name,'') LIKE :q ESCAPE '\\')" : '') . " THEN 1 ELSE 0 END) AS filtered";
            $c = $this->run($sel . ' ' . $base, $p)->fetch(PDO::FETCH_ASSOC) ?: [];
            foreach ($F as $k => $_) $counts[$k] = (int) ($c['c_' . $k] ?? 0);
            $total = (int) ($c['filtered'] ?? 0);
            $all   = (int) ($c['all_n'] ?? 0);
        } catch (Throwable $e) { error_log('[mentorportal] roster counts: ' . $e->getMessage()); }

        return [
            'rows'   => array_map([$this, 'shapeRow'], $rows),
            'total'  => $total,
            'all'    => $all,
            'counts' => $counts,
            'page'   => $page,
            'q'      => $q,
            'filter' => $filter,
            'sort'   => $sort,
        ];
    }

    /**
     * Run a statement with only the named parameters it actually contains.
     *
     * The roster builds its SQL from a filter and a sort, and each filter names
     * a different subset of the cutoffs. PDO refuses a bound parameter the
     * statement does not use, so passing the whole set made "All" — the default
     * view — the one filter that failed.
     */
    private function run(string $sql, array $params): PDOStatement
    {
        $use = [];
        foreach ($params as $k => $v) if (preg_match('/' . preg_quote($k, '/') . '\\b/', $sql)) $use[$k] = $v;
        $st = $this->db->prepare($sql);
        $st->execute($use);
        return $st;
    }

    private function shapeRow(array $r): array
    {
        $since = (string) ($r['since_at'] ?? '');
        $days  = $since === '' ? null : max(0, (int) floor((time() - (strtotime($since . ' UTC') ?: time())) / 86400));
        $stage = max(1, min(6, (int) $r['stage']));
        $held  = (int) $r['held'];
        $kept  = $held > 0 ? (int) round(100 * (int) $r['attended'] / $held) : null;

        [$health, $tone] = match (true) {
            $stage <= 2                   => ['Just started', 'indigo'],
            $days !== null && $days > 21  => ['Needs attention', 'red'],
            $days !== null && $days > 14  => ['Check in soon', 'gold'],
            default                       => ['Going well', 'green'],
        };

        return [
            'pairing_id'  => (int) $r['pairing_id'],
            'name'        => (string) $r['name'],
            'initials'    => self::initials((string) $r['name']),
            'health'      => $health,
            'tone'        => $tone,
            'track'       => (string) ($r['track'] ?? ''),
            'programme'   => (string) ($r['programme'] ?? ''),
            'chapter'     => (string) ($r['chapter'] ?? ''),
            // "NextGen Vanguard · Leadership track · Ikotun chapter" — the three
            // things that tell one Ada Okonkwo from another at a glance.
            'where'       => implode(' · ', array_filter([
                                (string) ($r['programme'] ?? ''),
                                ($r['track'] ?? '') !== '' ? $r['track'] . ' track' : '',
                                ($r['chapter'] ?? '') !== '' ? $r['chapter'] . ' chapter' : '',
                             ])),
            'stage'       => $stage,
            'stage_label' => self::STAGES[$stage - 1],
            'days'        => $days,
            'kept'        => $kept,
            'to_log'      => (int) ($r['to_log'] ?? 0),
            'goals'       => trim((string) ($r['goals'] ?? '')),
            'next'        => !empty($r['next_at']) ? self::when((string) $r['next_at']) : null,
        ];
    }

    /** "Today", "Tomorrow", or the weekday — what a person would actually say. */
    public static function relativeDay(string $ts): string
    {
        $t = $ts === '' ? 0 : (strtotime($ts . ' UTC') ?: 0);
        if (!$t) return '';
        $days = (int) floor((strtotime(date('Y-m-d', $t)) - strtotime(date('Y-m-d'))) / 86400);
        if ($days <= 0) return 'Today';
        if ($days === 1) return 'Tomorrow';
        return $days < 7 ? date('l', $t) : date('j M', $t);
    }

    public static function when(string $ts, string $fmt = 'D j M · g:ia'): string
    {
        $t = $ts === '' ? 0 : (strtotime($ts . ' UTC') ?: 0);
        return $t ? date($fmt, $t) : '';
    }

    /**
     * Where this pairing sits inside the CURRENT filter and sort, and what ‹ ›
     * point at. The ids come back in one query; the position is found in PHP
     * because a window function is not available on every engine this runs on,
     * and the list is one mentor's pairings, not the whole table.
     */
    public function positionInRoster(int $pairingId, string $q, string $filter, string $sort): array
    {
        $ids = array_column($this->roster($q, $filter, $sort, 1000)['rows'], 'pairing_id');
        $i = array_search($pairingId, $ids, true);
        if ($i === false) return ['index' => 0, 'total' => count($ids), 'prev' => null, 'next' => null];
        return [
            'index' => $i + 1,
            'total' => count($ids),
            'prev'  => $i > 0 ? $ids[$i - 1] : null,
            'next'  => $i + 1 < count($ids) ? $ids[$i + 1] : null,
        ];
    }

    /* ══ TODAY ═══════════════════════════════════════════════════════════════
       The four tiles, the next session, and what actually needs the mentor.
       "Needs you" is a list of things with a place to go, not a feeling. */

    public function today(): array
    {
        $now = self::now(); $month = gmdate('Y-m'); $d21 = self::ago(21);
        $prof = Mentorship::profile($this->mentorId) ?: [];
        $capacity = max(1, (int) ($prof['capacity'] ?? 3));

        $kpi = ['hours' => 0.0, 'kept' => null, 'attended' => 0, 'held' => 0, 'values_done' => 0, 'values_total' => 0, 'mentees' => 0, 'capacity' => $capacity];
        try {
            $st = $this->db->prepare(
                "SELECT COALESCE(SUM(s.duration_min),0) AS mins,
                        SUM(CASE WHEN s.attendance = 'attended' THEN 1 ELSE 0 END) AS attended,
                        SUM(CASE WHEN s.attendance IN ('attended','missed') THEN 1 ELSE 0 END) AS held
                   FROM mentor_sessions s JOIN mentorships m ON m.id = s.mentorship_id
                  WHERE m.mentor_id = ? AND s.attendance = 'attended'");
            $st->execute([$this->mentorId]);
            $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
            $kpi['hours'] = round(((int) ($r['mins'] ?? 0)) / 60, 1);

            $st = $this->db->prepare(
                "SELECT SUM(CASE WHEN s.attendance = 'attended' THEN 1 ELSE 0 END) AS a,
                        SUM(CASE WHEN s.attendance IN ('attended','missed') THEN 1 ELSE 0 END) AS h
                   FROM mentor_sessions s JOIN mentorships m ON m.id = s.mentorship_id WHERE m.mentor_id = ?");
            $st->execute([$this->mentorId]);
            $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
            $kpi['attended'] = (int) ($r['a'] ?? 0);
            $kpi['held']     = (int) ($r['h'] ?? 0);
            $kpi['kept'] = $kpi['held'] > 0 ? (int) round(100 * $kpi['attended'] / $kpi['held']) : null;

            $st = $this->db->prepare(
                "SELECT COUNT(*) AS n,
                        SUM(CASE WHEN EXISTS (SELECT 1 FROM mentor_values v WHERE v.mentorship_id = m.id AND v.month_key = ?) THEN 1 ELSE 0 END) AS seen
                   FROM mentorships m WHERE m.mentor_id = ? AND m.status = 'active'");
            $st->execute([$month, $this->mentorId]);
            $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
            $kpi['mentees'] = (int) ($r['n'] ?? 0);
            $kpi['values_total'] = (int) ($r['n'] ?? 0);
            $kpi['values_done']  = (int) ($r['seen'] ?? 0);
        } catch (Throwable $e) { error_log('[mentorportal] today kpi: ' . $e->getMessage()); }

        $next = null;
        try {
            $st = $this->db->prepare(
                "SELECT s.id, s.title, s.scheduled_at, s.duration_min, s.session_type, s.notes, s.meet_url,
                        m.id AS pairing_id, u.name
                   FROM mentor_sessions s JOIN mentorships m ON m.id = s.mentorship_id
                   JOIN lms_users u ON u.id = m.mentee_id
                  WHERE m.mentor_id = ? AND s.attendance = 'scheduled' AND s.scheduled_at > ?
                  ORDER BY s.scheduled_at ASC LIMIT 1");
            $st->execute([$this->mentorId, $now]);
            $r = $st->fetch(PDO::FETCH_ASSOC);
            if ($r) $next = [
                'session_id' => (int) $r['id'], 'pairing_id' => (int) $r['pairing_id'],
                'name' => (string) $r['name'], 'initials' => self::initials((string) $r['name']),
                'type' => Mentorship::typeLabel((string) $r['session_type']),
                'when' => self::when((string) $r['scheduled_at']),
                'relative' => self::relativeDay((string) $r['scheduled_at']),
                'minutes' => (int) ($r['duration_min'] ?: 60),
                'agenda' => trim((string) $r['notes']),
                'meet_url' => (string) $r['meet_url'],
            ];
        } catch (Throwable $e) { error_log('[mentorportal] today next: ' . $e->getMessage()); }

        // Everything waiting, each with the exact place it is dealt with.
        $todo = [];
        $add = function (string $what, string $who, string $href, string $tone = 'gold', string $verb = 'Open') use (&$todo) {
            $todo[] = ['what' => $what, 'who' => $who, 'href' => $href, 'tone' => $tone, 'verb' => $verb];
        };
        try {
            $st = $this->db->prepare(
                "SELECT s.id, s.scheduled_at, s.title, m.id AS pairing_id, u.name
                   FROM mentor_sessions s JOIN mentorships m ON m.id = s.mentorship_id
                   JOIN lms_users u ON u.id = m.mentee_id
                  WHERE m.mentor_id = ? AND s.attendance = 'scheduled' AND s.scheduled_at <> '' AND s.scheduled_at < ?
                  ORDER BY s.scheduled_at ASC LIMIT 20");
            $st->execute([$this->mentorId, $now]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r)
                $add('Log ' . explode(' ', (string) $r['name'])[0] . '’s session',
                     trim((string) $r['title']) . ' · ' . self::when((string) $r['scheduled_at'], 'D j M · g:ia'),
                     '?v=case&id=' . (int) $r['pairing_id'] . '&tab=sessions#s' . (int) $r['id'], 'gold', 'Log it');

            $st = $this->db->prepare(
                "SELECT m.id, u.name FROM mentorships m JOIN lms_users u ON u.id = m.mentee_id
                  WHERE m.mentor_id = ? AND m.status = 'active' AND m.goals = '' ORDER BY u.name LIMIT 20");
            $st->execute([$this->mentorId]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r)
                $add('Agree goals with ' . (string) $r['name'], 'Set one to three goals at the kick-off', '?v=case&id=' . (int) $r['id'], 'indigo', 'Open');

            $st = $this->db->prepare(
                "SELECT c.id, c.week, m.id AS pairing_id, u.name FROM mentor_checkins c
                   JOIN mentorships m ON m.id = c.mentorship_id JOIN lms_users u ON u.id = m.mentee_id
                  WHERE m.mentor_id = ? AND c.sent_at = '' AND c.due_at <= ? ORDER BY c.due_at ASC LIMIT 20");
            $st->execute([$this->mentorId, $now]);
            $due = $st->fetchAll(PDO::FETCH_ASSOC);
            if ($due) $add(count($due) === 1 ? 'One check-in for your coordinator' : count($due) . ' check-ins for your coordinator',
                           'Takes a minute each', '?v=checkins', 'indigo', 'Start');

            $st = $this->db->prepare(
                "SELECT COUNT(*) FROM mentorships WHERE mentor_id = ? AND status = 'pending'");
            $st->execute([$this->mentorId]);
            $n = (int) $st->fetchColumn();
            if ($n) $add($n === 1 ? 'One mentorship request' : $n . ' mentorship requests', 'Reply within a week', '?v=requests', 'indigo', 'Review');

            $st = $this->db->prepare(
                "SELECT m.id, u.name FROM mentorships m JOIN lms_users u ON u.id = m.mentee_id
                  WHERE m.mentor_id = ? AND m.status = 'active' AND m.stage >= 6 ORDER BY u.name LIMIT 20");
            $st->execute([$this->mentorId]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r)
                $add('Plan the close with ' . (string) $r['name'], 'Look back at the goals together', '?v=case&id=' . (int) $r['id'], 'red', 'Plan');
        } catch (Throwable $e) { error_log('[mentorportal] today todo: ' . $e->getMessage()); }

        // "Longest wait first" — the same ordering the roster uses, so the six
        // here and the list behind "See all" cannot disagree.
        $wait = $this->roster('', 'all', 'wait', 1, true);

        $note = null;
        try {
            $st = $this->db->prepare("SELECT id, body, created_at, ack_at FROM mentor_notes WHERE mentor_id = ? ORDER BY id DESC LIMIT 1");
            $st->execute([$this->mentorId]);
            $r = $st->fetch(PDO::FETCH_ASSOC);
            if ($r) $note = ['id' => (int) $r['id'], 'body' => (string) $r['body'], 'acked' => (string) $r['ack_at'] !== '',
                             'when' => self::when((string) $r['created_at'], 'j M')];
        } catch (Throwable $e) { /* no note */ }

        // Round-robin by kind, so the first screenful shows every kind of work
        // waiting rather than the first eight of whichever kind is commonest.
        $byKind = [];
        foreach ($todo as $row) $byKind[$row['verb']][] = $row;
        $mixed = []; $n = $byKind ? max(array_map('count', $byKind)) : 0;
        for ($i = 0; $i < $n; $i++) foreach ($byKind as $kind => $rows) if (isset($rows[$i])) $mixed[] = $rows[$i];

        return ['kpi' => $kpi, 'next' => $next, 'todo' => $mixed, 'todo_total' => count($todo),
                'wait' => array_slice($wait['rows'], 0, 6), 'wait_total' => $wait['total'], 'note' => $note];
    }

    /* ══ CASE FILE ═══════════════════════════════════════════════════════════ */

    public function caseFile(int $pairingId): ?array
    {
        if (!$this->ownsPairing($pairingId)) return null;
        $st = $this->db->prepare(
            "SELECT m.*, u.name, u.email, COALESCE(ch.name,'') AS chapter
               FROM mentorships m JOIN lms_users u ON u.id = m.mentee_id
               LEFT JOIN mentor_cohorts ch ON ch.id = m.cohort_id WHERE m.id = ?");
        $st->execute([$pairingId]);
        $m = $st->fetch(PDO::FETCH_ASSOC);
        if (!$m) return null;

        $sessions = Mentorship::sessions($pairingId);
        $attended = array_values(array_filter($sessions, fn($s) => $s['attendance'] === 'attended'));
        $held     = array_values(array_filter($sessions, fn($s) => in_array($s['attendance'], ['attended', 'missed'], true)));
        $last     = $attended ? end($attended) : null;
        $minutes  = array_sum(array_map(fn($s) => (int) $s['duration_min'] ?: 60, $attended));
        $started  = (string) ($m['started_at'] ?: $m['created_at']);

        return [
            'pairing_id' => $pairingId,
            'name'       => (string) $m['name'],
            'first'      => explode(' ', trim((string) $m['name']))[0],
            'initials'   => self::initials((string) $m['name']),
            'track'      => (string) ($m['track'] ?? ''),
            'chapter'    => (string) $m['chapter'],
            'where'      => implode(' · ', array_filter([
                                (string) ($m['programme'] ?? ''),
                                ($m['track'] ?? '') !== '' ? $m['track'] . ' track' : '',
                                ((string) $m['chapter']) !== '' ? $m['chapter'] . ' chapter' : '',
                            ])),
            'since'      => self::when($started, 'j M Y'),
            'stage'      => max(1, min(6, (int) ($m['stage'] ?? 1))),
            'stages'     => self::STAGES,
            'goals'      => trim((string) ($m['goals'] ?? '')),
            'goals_list' => $this->goalsFor($pairingId, trim((string) ($m['goals'] ?? '')) !== ''),
            'close_steps'=> array_values(array_filter(explode(',', (string) ($m['close_steps'] ?? '')))),
            'sessions'   => $sessions,
            'stats'      => [
                'hours'    => round($minutes / 60, 1),
                'sessions' => count($attended),
                'held'     => count($held),
                'kept'     => $held ? (int) round(100 * count($attended) / count($held)) : null,
                'days'     => $last === null ? null
                    : max(0, (int) floor((time() - (strtotime(((string) $last['when']) . ' UTC') ?: time())) / 86400)),
            ],
            'last'       => $last ? ['when' => self::when((string) $last['when'], 'j M Y'), 'outcome' => trim((string) $last['outcome'])] : null,
            'values'     => $this->valuesFor($pairingId),
            'checkin'    => $this->latestCheckin($pairingId),
            'messages'   => $this->messages($pairingId),
        ];
    }

    /* ── Goals ──────────────────────────────────────────────────────────────
       One to three per pairing. The legacy paragraph is migrated the first
       time a case file that has one is opened, so nothing a mentor wrote
       before this existed is lost or left behind. */

    /**
     * Every goal of one pairing, oldest first.
     *
     * $migrate is the caller saying "this pairing has legacy text worth
     * turning into rows" — the case file knows, because it has just read the
     * column. Passing false keeps this to exactly one query.
     */
    public function goalsFor(int $pairingId, bool $migrate = true): array
    {
        self::ensure();
        $st = $this->db->prepare(
            "SELECT id, title, measure, due_on, status, created_at, closed_at
               FROM mentor_goals WHERE mentorship_id = ? ORDER BY sort_no ASC, id ASC");
        $st->execute([$pairingId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if (!$rows && $migrate) $rows = $this->migrateGoalText($pairingId);

        $today = gmdate('Y-m-d');
        $out = [];
        foreach ($rows as $r) {
            $status = (string) $r['status'];
            if (!isset(self::GOAL_STATUS[$status])) $status = 'open';
            $due = (string) $r['due_on'];
            $out[] = [
                'id'         => (int) $r['id'],
                'title'      => (string) $r['title'],
                'measure'    => (string) $r['measure'],
                'due_on'     => $due,
                'due_label'  => $due === '' ? '' : self::when($due . ' 00:00:00', 'j M Y'),
                'overdue'    => $status === 'open' && $due !== '' && $due < $today,
                'status'     => $status,
                'status_label' => self::GOAL_STATUS[$status],
                'created_at' => (string) $r['created_at'],
                'closed_at'  => (string) $r['closed_at'],
            ];
        }
        return $out;
    }

    /** met / open / set aside, and whether there is room for another. */
    public static function goalTally(array $items): array
    {
        $n = ['met' => 0, 'open' => 0, 'dropped' => 0];
        foreach ($items as $g) $n[$g['status']]++;
        $live = $n['met'] + $n['open'];
        return $n + ['live' => $live, 'total' => count($items), 'can_add' => $n['open'] < self::GOAL_MAX];
    }

    /**
     * Turn the pairing's free-text goals into rows, once.
     *
     * Conservative on purpose: it splits on line breaks and semicolons, never
     * on full stops, because "Lead one project and speak at the chapter
     * meeting by December." is one goal written in one sentence, and chopping
     * it would put words in the mentor's mouth. $at is when the goals are
     * recorded as agreed — the pairing's start for a migration, so the
     * timeline does not claim they were set today.
     */
    private function migrateGoalText(int $pairingId, ?string $at = null): array
    {
        $st = $this->db->prepare('SELECT goals, started_at, created_at FROM mentorships WHERE id = ?');
        $st->execute([$pairingId]);
        $m = $st->fetch(PDO::FETCH_ASSOC);
        if (!$m) return [];
        $text = trim(strip_tags((string) ($m['goals'] ?? '')));
        if ($text === '') return [];

        $when  = $at ?? ((string) ($m['started_at'] ?: $m['created_at']) ?: self::now());
        $parts = preg_split('/\s*(?:\r?\n|;)+\s*/u', $text) ?: [];
        $ins   = $this->db->prepare(
            "INSERT INTO mentor_goals (mentorship_id, title, measure, due_on, status, sort_no, created_at, updated_at, closed_at)
             VALUES (?, ?, '', '', 'open', ?, ?, ?, '')");
        $i = 0;
        foreach ($parts as $t) {
            $t = self::cleanLine(ltrim($t, "-*\u{2022} \t"), 200);
            if ($t === '') continue;
            $ins->execute([$pairingId, $t, $i, $when, $when]);
            if (++$i >= self::GOAL_MAX) break;
        }
        if ($i === 0) return [];
        $st = $this->db->prepare(
            "SELECT id, title, measure, due_on, status, created_at, closed_at
               FROM mentor_goals WHERE mentorship_id = ? ORDER BY sort_no ASC, id ASC");
        $st->execute([$pairingId]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private static function cleanLine(string $s, int $max): string
    {
        return mb_substr(trim(preg_replace('/\s+/u', ' ', strip_tags($s)) ?? ''), 0, $max);
    }

    /** A date the browser sent, or nothing. Never a half-parsed string. */
    private static function cleanDate(string $s): string
    {
        $s = trim($s);
        if ($s === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) return '';
        [$y, $m, $d] = array_map('intval', explode('-', $s));
        return checkdate($m, $d, $y) ? $s : '';
    }

    /** The seven values and when each was last seen, for one pairing. */
    public function valuesFor(int $pairingId): array
    {
        $out = [];
        foreach (self::VALUES as $k => $label) $out[$k] = [
            'key' => $k, 'label' => $label, 'motto' => self::VALUE_MOTTO[$k] ?? '',
            'chips' => self::VALUE_CHIPS[$k] ?? [], 'level' => 0, 'last' => '', 'evidence' => '',
        ];
        try {
            $st = $this->db->prepare(
                "SELECT value_key, level, evidence, observed_at FROM mentor_values
                  WHERE mentorship_id = ? ORDER BY id ASC");
            $st->execute([$pairingId]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $k = (string) $r['value_key'];
                if (!isset($out[$k])) continue;
                $out[$k]['level'] = (int) $r['level'];
                $out[$k]['evidence'] = (string) $r['evidence'];
                $out[$k]['last'] = self::when((string) $r['observed_at'], 'j M');
            }
        } catch (Throwable $e) { /* none recorded */ }
        return array_values($out);
    }

    /** Whether this pairing has been observed in the CURRENT month. */
    public function valuesDoneThisMonth(int $pairingId): bool
    {
        try {
            $st = $this->db->prepare('SELECT 1 FROM mentor_values WHERE mentorship_id = ? AND month_key = ? LIMIT 1');
            $st->execute([$pairingId, gmdate('Y-m')]);
            return (bool) $st->fetchColumn();
        } catch (Throwable $e) { return false; }
    }

    /** The next pairing with no observation this month, for "save and move on". */
    public function nextUnobserved(int $afterPairingId = 0): ?int
    {
        try {
            $st = $this->db->prepare(
                "SELECT m.id FROM mentorships m
                  WHERE m.mentor_id = ? AND m.status = 'active' AND m.id <> ?
                    AND NOT EXISTS (SELECT 1 FROM mentor_values v WHERE v.mentorship_id = m.id AND v.month_key = ?)
                  ORDER BY m.id ASC LIMIT 1");
            $st->execute([$this->mentorId, $afterPairingId, gmdate('Y-m')]);
            $v = $st->fetchColumn();
            return $v === false ? null : (int) $v;
        } catch (Throwable $e) { return null; }
    }

    private function latestCheckin(int $pairingId): ?array
    {
        try {
            $st = $this->db->prepare("SELECT q0, q1, q2, sent_at FROM mentor_checkins
                                       WHERE mentorship_id = ? AND sent_at <> '' ORDER BY id DESC LIMIT 1");
            $st->execute([$pairingId]);
            $r = $st->fetch(PDO::FETCH_ASSOC);
            if (!$r) return null;
            return ['q0' => (int) $r['q0'], 'q1' => (int) $r['q1'], 'q2' => (int) $r['q2'],
                    'when' => self::when((string) $r['sent_at'], 'j M')];
        } catch (Throwable $e) { return null; }
    }

    /** The thread, oldest first. Queued messages are shown to the mentor. */
    public function messages(int $pairingId, int $limit = 50): array
    {
        try {
            $st = $this->db->prepare(
                "SELECT id, sender_id, body, created_at, send_after FROM mentor_messages
                  WHERE mentorship_id = ? ORDER BY id DESC LIMIT {$limit}");
            $st->execute([$pairingId]);
            $rows = array_reverse($st->fetchAll(PDO::FETCH_ASSOC) ?: []);
        } catch (Throwable $e) { return []; }
        $now = self::now();
        return array_map(fn($r) => [
            'id' => (int) $r['id'],
            'mine' => (int) $r['sender_id'] === $this->mentorId,
            'body' => (string) $r['body'],
            'when' => self::when((string) $r['created_at'], 'j M · g:ia'),
            'queued' => (string) $r['send_after'] !== '' && (string) $r['send_after'] > $now,
        ], $rows);
    }

    /* ══ CHECK-INS ═══════════════════════════════════════════════════════════
       Week 1, 2 and 4 of a pairing, then monthly. Generated from the pairing's
       own start date so a mentor never has to remember the schedule. */

    public function checkins(bool $all = false): array
    {
        $this->generateDueCheckins();
        $limit = $all ? 200 : 12;
        try {
            $st = $this->db->prepare(
                "SELECT c.id, c.week, c.due_at, c.sent_at, m.id AS pairing_id, u.name
                   FROM mentor_checkins c JOIN mentorships m ON m.id = c.mentorship_id
                   JOIN lms_users u ON u.id = m.mentee_id
                  WHERE m.mentor_id = ? AND m.status = 'active'
                  ORDER BY CASE WHEN c.sent_at = '' THEN 0 ELSE 1 END, c.due_at ASC LIMIT {$limit}");
            $st->execute([$this->mentorId]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) { error_log('[mentorportal] checkins: ' . $e->getMessage()); return []; }
        return array_map(fn($r) => [
            'id' => (int) $r['id'], 'pairing_id' => (int) $r['pairing_id'],
            'name' => (string) $r['name'], 'initials' => self::initials((string) $r['name']),
            'week' => (int) $r['week'], 'due' => self::when((string) $r['due_at'], 'j M'),
            'sent' => (string) $r['sent_at'] !== '',
            'overdue' => (string) $r['sent_at'] === '' && (string) $r['due_at'] <= self::now(),
        ], $rows);
    }

    public function checkinsTotal(): int
    {
        try {
            $st = $this->db->prepare("SELECT COUNT(*) FROM mentor_checkins c JOIN mentorships m ON m.id = c.mentorship_id
                                       WHERE m.mentor_id = ? AND m.status = 'active'");
            $st->execute([$this->mentorId]);
            return (int) $st->fetchColumn();
        } catch (Throwable $e) { return 0; }
    }

    /** Create the rows that are due but do not exist yet. Idempotent. */
    private function generateDueCheckins(): void
    {
        try {
            $st = $this->db->prepare("SELECT id, COALESCE(NULLIF(started_at,''), created_at) AS began
                                        FROM mentorships WHERE mentor_id = ? AND status = 'active'");
            $st->execute([$this->mentorId]);
            $pairs = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) { return; }

        $ins = $this->db->prepare('INSERT INTO mentor_checkins (mentorship_id, week, due_at, sent_at) VALUES (?,?,?,\'\')');
        $has = $this->db->prepare('SELECT 1 FROM mentor_checkins WHERE mentorship_id = ? AND week = ? LIMIT 1');
        $now = time();
        foreach ($pairs as $p) {
            $began = strtotime(((string) $p['began']) . ' UTC') ?: $now;
            $weeks = [1, 2, 4];
            // …then monthly, for as long as the pairing has been running.
            for ($w = 8; $w <= 260; $w += 4) { if ($began + $w * 604800 > $now + 604800) break; $weeks[] = $w; }
            foreach ($weeks as $w) {
                $due = $began + $w * 604800;
                if ($due > $now + 604800) continue;          // not due, and not due within the week
                $has->execute([(int) $p['id'], $w]);
                if ($has->fetchColumn()) continue;
                try { $ins->execute([(int) $p['id'], $w, gmdate('Y-m-d H:i:s', $due)]); }
                catch (Throwable $e) { /* raced with another request */ }
            }
        }
    }

    /* ══ REFLECTIONS ═════════════════════════════════════════════════════════ */

    public function reflections(int $page = 1): array
    {
        $limit = 12; $offset = max(0, ($page - 1) * $limit);
        try {
            $st = $this->db->prepare(
                "SELECT d.id, d.title, d.body, d.entry_date, u.name, rr.reply
                   FROM diary_shares sh
                   JOIN diary_entries d ON d.id = sh.entry_id
                   JOIN mentorships p ON p.mentee_id = d.author_id AND p.mentor_id = ? AND p.status = 'active'
                   JOIN lms_users u ON u.id = d.author_id
                   LEFT JOIN mentor_reflect_replies rr ON rr.entry_id = d.id AND rr.mentor_id = ?
                  WHERE sh.user_id = ?
                  ORDER BY d.entry_date DESC, d.id DESC LIMIT {$limit} OFFSET {$offset}");
            $st->execute([$this->mentorId, $this->mentorId, $this->mentorId]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) { error_log('[mentorportal] reflections: ' . $e->getMessage()); return []; }
        return array_map(fn($r) => [
            'entry_id' => (int) $r['id'],
            'name' => (string) $r['name'], 'first' => explode(' ', trim((string) $r['name']))[0],
            'initials' => self::initials((string) $r['name']),
            'title' => (string) ($r['title'] ?: 'Untitled entry'),
            'excerpt' => DiaryJournal::excerpt((string) $r['body'], 320),
            'date' => self::when(((string) $r['entry_date']) . ' 00:00:00', 'j M Y'),
            'reply' => (string) ($r['reply'] ?? ''),
        ], $rows);
    }

    /* ══ REQUESTS ════════════════════════════════════════════════════════════ */

    public function requests(): array
    {
        try {
            $st = $this->db->prepare(
                "SELECT m.id, m.message, m.created_at, u.name, u.email
                   FROM mentorships m JOIN lms_users u ON u.id = m.mentee_id
                  WHERE m.mentor_id = ? AND m.status = 'pending' ORDER BY m.id ASC");
            $st->execute([$this->mentorId]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) { return []; }
        return array_map(fn($r) => [
            'id' => (int) $r['id'], 'name' => (string) $r['name'],
            'initials' => self::initials((string) $r['name']),
            'message' => trim((string) $r['message']),
            'when' => self::when((string) $r['created_at'], 'j M'),
        ], $rows);
    }

    /** At capacity, or safeguarding lapsed, and WHY — so the button can say so. */
    public function acceptBlock(): string
    {
        if (!$this->safeguardingCurrent())
            return 'Your safeguarding module has lapsed. Retake it before accepting anyone.';
        $prof = Mentorship::profile($this->mentorId) ?: [];
        $cap  = max(1, (int) ($prof['capacity'] ?? 3));
        $have = $this->activeCount();
        if ($have >= $cap) return 'You are at your capacity of ' . $cap . '. Raise it in your profile, or close a pairing first.';
        return '';
    }

    public function activeCount(): int
    {
        try {
            $st = $this->db->prepare("SELECT COUNT(*) FROM mentorships WHERE mentor_id = ? AND status = 'active'");
            $st->execute([$this->mentorId]);
            return (int) $st->fetchColumn();
        } catch (Throwable $e) { return 0; }
    }

    /* ══ ACADEMY ═════════════════════════════════════════════════════════════ */

    private ?array $academyCache = null;

    public function academy(): array
    {
        // Memoised: the nav badge, the Today tile and the Academy view all ask
        // on the same request, and a mentor's own training does not change
        // between two reads of one page.
        if ($this->academyCache !== null) return $this->academyCache;
        $passes = [];
        try {
            $st = $this->db->prepare("SELECT module_key, score, completed_at FROM mentor_academy
                                       WHERE user_id = ? AND passed = 1 ORDER BY id DESC");
            $st->execute([$this->mentorId]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $k = (string) $r['module_key'];
                if (!isset($passes[$k])) $passes[$k] = ['score' => (int) $r['score'], 'at' => (string) $r['completed_at']];
            }
        } catch (Throwable $e) { /* none yet */ }

        $modules = []; $required = 0; $done = 0; $next = null;
        foreach (self::MODULES as $key => $m) {
            $pass = $passes[$key] ?? null;
            $lapsed = false;
            if ($pass && $m['lapses_days'] > 0) {
                $t = strtotime(((string) $pass['at']) . ' UTC') ?: 0;
                $lapsed = $t > 0 && $t < time() - $m['lapses_days'] * 86400;
            }
            $ok = $pass !== null && !$lapsed;
            if ($m['required']) { $required++; if ($ok) $done++; }
            $row = ['key' => $key, 'title' => $m['title'], 'required' => $m['required'],
                    'passed' => $ok, 'lapsed' => $lapsed,
                    'score' => $pass['score'] ?? null,
                    'when' => $pass ? self::when((string) $pass['at'], 'j M Y') : ''];
            $modules[] = $row;
            if ($next === null && $m['required'] && !$ok) $next = $row;
        }
        $sg = $passes['safeguarding'] ?? null;
        $sgOk = $sg !== null && (strtotime(((string) $sg['at']) . ' UTC') ?: 0) > time() - self::MODULES['safeguarding']['lapses_days'] * 86400;

        return $this->academyCache = ['modules' => $modules, 'required' => $required, 'done' => $done,
                'pass_mark' => self::PASS_MARK, 'next' => $next, 'safeguarding_ok' => $sgOk];
    }

    public function safeguardingCurrent(): bool
    {
        if ($this->academyCache !== null) return (bool) $this->academyCache['safeguarding_ok'];
        try {
            $st = $this->db->prepare("SELECT completed_at FROM mentor_academy
                                       WHERE user_id = ? AND module_key = 'safeguarding' AND passed = 1
                                       ORDER BY id DESC LIMIT 1");
            $st->execute([$this->mentorId]);
            $at = (string) ($st->fetchColumn() ?: '');
        } catch (Throwable $e) { return false; }
        if ($at === '') return false;
        $t = strtotime($at . ' UTC') ?: 0;
        return $t > time() - self::MODULES['safeguarding']['lapses_days'] * 86400;
    }

    public function academyModule(string $key): ?array
    {
        $m = MentorAcademy::module($key);
        if (!$m) return null;
        $a = $this->academy();
        foreach ($a['modules'] as $row) if ($row['key'] === $key) $m['state'] = $row;
        return $m;
    }

    /** Record an attempt. A pass is recorded; a fail is recorded too, so a
     *  coordinator can see who is stuck rather than only who is done. */
    public function recordAttempt(string $key, array $answers): array
    {
        $r = MentorAcademy::mark($key, $answers);
        if (empty($r['ok'])) return $r;
        $this->db->prepare('INSERT INTO mentor_academy (user_id, module_key, score, passed, completed_at) VALUES (?,?,?,?,?)')
            ->execute([$this->mentorId, $key, (int) $r['score'], $r['passed'] ? 1 : 0, self::now()]);
        $this->academyCache = null;      // they have just changed it
        return $r;
    }

    /* ══ PROFILE ═════════════════════════════════════════════════════════════ */

    public function profile(): array
    {
        $p = Mentorship::profile($this->mentorId) ?: [];
        $me = $this->me();
        return [
            'name'      => $me['name'],
            'initials'  => $me['initials'],
            'headline'  => (string) ($p['headline'] ?? ''),
            'bio'       => (string) ($p['bio'] ?? ''),
            'focus'     => is_array($p['focus'] ?? null) ? implode(', ', $p['focus']) : (string) ($p['focus'] ?? ''),
            'capacity'  => max(1, min(50, (int) ($p['capacity'] ?? 3))),
            'accepting' => (int) ($p['accepting'] ?? 1) === 1,
            'active'    => $this->activeCount(),
        ];
    }

    /** Mentees for the Values picker and the schedule dialog. */
    public function menteeOptions(): array
    {
        try {
            $st = $this->db->prepare(
                "SELECT m.id, u.name,
                        (SELECT COUNT(*) FROM mentor_values v WHERE v.mentorship_id = m.id AND v.month_key = ?) AS seen
                   FROM mentorships m JOIN lms_users u ON u.id = m.mentee_id
                  WHERE m.mentor_id = ? AND m.status = 'active' ORDER BY u.name ASC");
            $st->execute([gmdate('Y-m'), $this->mentorId]);
            return array_map(fn($r) => ['id' => (int) $r['id'], 'name' => (string) $r['name'], 'observed' => (int) $r['seen'] > 0],
                $st->fetchAll(PDO::FETCH_ASSOC) ?: []);
        } catch (Throwable $e) { return []; }
    }

    /**
     * Who a mentor is told to go to. Read from configuration rather than
     * hard-coded: a name in the source is a person who has left by the time
     * somebody needs them.
     */
    public function coordinatorFirstName(): string
    {
        $n = defined('MENTOR_COORDINATOR') ? (string) MENTOR_COORDINATOR : (string) (getenv('MENTOR_COORDINATOR') ?: '');
        $n = trim($n);
        return $n === '' ? 'your coordinator' : explode(' ', $n)[0];
    }

    /* ══════════════════════════════════════════════════════════════════════════
       WRITES
       Every one of these re-checks ownership. The API calls them with ids that
       came off the wire, so "the caller already checked" is not a thing that is
       true here.
       ══════════════════════════════════════════════════════════════════════════ */

    /** Add one goal. The first one moves the pairing to "Goals agreed". */
    public function addGoal(int $pairingId, string $title, string $measure, string $due): array
    {
        if (!$this->ownsPairing($pairingId)) return ['ok' => false, 'error' => 'Not your mentee.'];
        $title = self::cleanLine($title, 200);
        if ($title === '') return ['ok' => false, 'error' => 'Write what you are both working towards.'];

        $have = $this->goalsFor($pairingId);
        if (self::goalTally($have)['can_add'] === false) {
            return ['ok' => false, 'error' => 'Three goals at a time is the limit. Mark one met, or set it aside.'];
        }
        $now = self::now();
        $this->db->prepare(
            "INSERT INTO mentor_goals (mentorship_id, title, measure, due_on, status, sort_no, created_at, updated_at, closed_at)
             VALUES (?, ?, ?, ?, 'open', ?, ?, ?, '')")
            ->execute([$pairingId, $title, self::cleanLine($measure, 300), self::cleanDate($due), count($have), $now, $now]);
        $this->syncGoalText($pairingId);
        return ['ok' => true];
    }

    /** Reword a goal, or change how you will know it happened. */
    public function editGoal(int $pairingId, int $goalId, string $title, string $measure, string $due): array
    {
        if (!$this->ownsPairing($pairingId)) return ['ok' => false, 'error' => 'Not your mentee.'];
        $title = self::cleanLine($title, 200);
        if ($title === '') return ['ok' => false, 'error' => 'Write what you are both working towards.'];
        $st = $this->db->prepare(
            'UPDATE mentor_goals SET title = ?, measure = ?, due_on = ?, updated_at = ? WHERE id = ? AND mentorship_id = ?');
        $st->execute([$title, self::cleanLine($measure, 300), self::cleanDate($due), self::now(), $goalId, $pairingId]);
        if ($st->rowCount() === 0 && !$this->goalExists($pairingId, $goalId)) {
            return ['ok' => false, 'error' => 'That goal is not on this pairing.'];
        }
        $this->syncGoalText($pairingId);
        return ['ok' => true];
    }

    /**
     * Met, set aside, or back to working on it.
     *
     * A goal is never deleted by this; "Set aside" keeps it in the record so
     * the closing conversation can still see what was agreed and what changed.
     */
    public function setGoalStatus(int $pairingId, int $goalId, string $status): array
    {
        if (!$this->ownsPairing($pairingId)) return ['ok' => false, 'error' => 'Not your mentee.'];
        if (!isset(self::GOAL_STATUS[$status])) return ['ok' => false, 'error' => 'Unknown status.'];
        if (!$this->goalExists($pairingId, $goalId)) return ['ok' => false, 'error' => 'That goal is not on this pairing.'];
        $now = self::now();
        $this->db->prepare('UPDATE mentor_goals SET status = ?, closed_at = ?, updated_at = ? WHERE id = ? AND mentorship_id = ?')
            ->execute([$status, $status === 'open' ? '' : $now, $now, $goalId, $pairingId]);
        $this->syncGoalText($pairingId);
        return ['ok' => true, 'status' => $status, 'label' => self::GOAL_STATUS[$status]];
    }

    /** Remove a goal written by mistake. Set aside is the one to use otherwise. */
    public function removeGoal(int $pairingId, int $goalId): array
    {
        if (!$this->ownsPairing($pairingId)) return ['ok' => false, 'error' => 'Not your mentee.'];
        if (!$this->goalExists($pairingId, $goalId)) return ['ok' => false, 'error' => 'That goal is not on this pairing.'];
        $this->db->prepare('DELETE FROM mentor_goals WHERE id = ? AND mentorship_id = ?')->execute([$goalId, $pairingId]);
        $this->syncGoalText($pairingId);
        return ['ok' => true];
    }

    private function goalExists(int $pairingId, int $goalId): bool
    {
        $st = $this->db->prepare('SELECT 1 FROM mentor_goals WHERE id = ? AND mentorship_id = ?');
        $st->execute([$goalId, $pairingId]);
        return (bool) $st->fetchColumn();
    }

    /**
     * Keep mentorships.goals — and the stage — telling the truth.
     *
     * Everything written before this feature reads that one column: the
     * mentee's portal, the roster filter, Today's "Agree goals with …", the
     * seed data. So every goal write rewrites it from the live goals, and the
     * stage follows: the first goal moves a pairing up to "Goals agreed", and
     * removing the last one moves it back down to "Kick-off" rather than
     * leaving a pairing standing at a step it is no longer on. A stage past
     * agreement is never pulled backwards.
     */
    private function syncGoalText(int $pairingId): void
    {
        $st = $this->db->prepare('SELECT title, status FROM mentor_goals WHERE mentorship_id = ? ORDER BY sort_no ASC, id ASC');
        $st->execute([$pairingId]);
        $live = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            if ((string) $r['status'] !== 'dropped') $live[] = (string) $r['title'];
        }
        $sql = $live
            ? 'UPDATE mentorships SET goals = ?, stage = CASE WHEN stage < 3 THEN 3 ELSE stage END, updated_at = ? WHERE id = ?'
            : 'UPDATE mentorships SET goals = ?, stage = CASE WHEN stage = 3 THEN 2 ELSE stage END, updated_at = ? WHERE id = ?';
        $this->db->prepare($sql)->execute([mb_substr(implode('; ', $live), 0, 2000), self::now(), $pairingId]);
    }

    /**
     * The whole set at once, as one block of text — what the older client and
     * the mentee-side import post. Rewritten as rows so there is one shape of
     * goal in the database, not two.
     */
    public function saveGoals(int $pairingId, string $goals): array
    {
        if (!$this->ownsPairing($pairingId)) return ['ok' => false, 'error' => 'Not your mentee.'];
        $goals = trim(strip_tags($goals));
        if ($goals === '') return ['ok' => false, 'error' => 'Write what you are working towards.'];
        $this->db->prepare('DELETE FROM mentor_goals WHERE mentorship_id = ?')->execute([$pairingId]);
        $this->db->prepare('UPDATE mentorships SET goals = ?, updated_at = ? WHERE id = ?')
            ->execute([mb_substr($goals, 0, 2000), self::now(), $pairingId]);
        $this->migrateGoalText($pairingId, self::now());
        $this->syncGoalText($pairingId);
        return ['ok' => true];
    }

    /** One of the three things that must happen before a pairing can close. */
    public function closeStep(int $pairingId, string $step, bool $on): array
    {
        if (!$this->ownsPairing($pairingId)) return ['ok' => false, 'error' => 'Not your mentee.'];
        $allowed = ['schedule', 'review', 'contact'];
        if (!in_array($step, $allowed, true)) return ['ok' => false, 'error' => 'Unknown step.'];
        $st = $this->db->prepare('SELECT close_steps FROM mentorships WHERE id = ?');
        $st->execute([$pairingId]);
        $have = array_values(array_filter(explode(',', (string) $st->fetchColumn())));
        $have = $on ? array_values(array_unique(array_merge($have, [$step]))) : array_values(array_diff($have, [$step]));
        $this->db->prepare('UPDATE mentorships SET close_steps = ?, updated_at = ? WHERE id = ?')
            ->execute([implode(',', $have), self::now(), $pairingId]);
        return ['ok' => true, 'steps' => $have];
    }

    /**
     * Close a pairing — only once all three steps are done.
     *
     * Checked HERE and not only in the browser: the button is disabled client
     * side, and a disabled button is a suggestion. A planned ending is the one
     * part of mentoring a young person remembers most clearly, and closing by
     * accident is not something an Undo repairs.
     */
    public function closePairing(int $pairingId): array
    {
        if (!$this->ownsPairing($pairingId)) return ['ok' => false, 'error' => 'Not your mentee.'];
        $st = $this->db->prepare('SELECT close_steps FROM mentorships WHERE id = ?');
        $st->execute([$pairingId]);
        $have = array_values(array_filter(explode(',', (string) $st->fetchColumn())));
        $missing = array_diff(['schedule', 'review', 'contact'], $have);
        if ($missing) return ['ok' => false, 'error' => 'Do the three closing steps first.'];
        $this->db->prepare("UPDATE mentorships SET status = 'ended', stage = 6, ends_at = ?, updated_at = ? WHERE id = ?")
            ->execute([self::now(), self::now(), $pairingId]);
        return ['ok' => true];
    }

    public const TOPICS = ['Goals', 'Values', 'School or work', 'Family', 'Money', 'Wellbeing'];
    public const MOODS  = ['Engaged', 'Quiet', 'Worried', 'Upset'];

    /**
     * Log a session that happened. This is what turns a scheduled row into
     * hours, so it is the one write a mentor does most and the one that must
     * never need a second screen.
     */
    public function logSession(int $sessionId, int $minutes, array $topics, string $mood, string $outcome): array
    {
        if (!$this->ownsSession($sessionId)) return ['ok' => false, 'error' => 'Not your session.'];
        $minutes = in_array($minutes, [30, 45, 60, 90], true) ? $minutes : 60;
        $topics  = array_values(array_intersect(self::TOPICS, $topics));
        $mood    = in_array($mood, self::MOODS, true) ? $mood : '';
        $outcome = mb_substr(trim(strip_tags($outcome)), 0, 2000);

        $this->db->prepare('UPDATE mentor_sessions SET topics = ?, mood = ?, outcome = ? WHERE id = ?')
            ->execute([implode(', ', $topics), $mood, $outcome, $sessionId]);
        $r = Mentorship::markAttendance($this->mentorId, $sessionId, 'attended', $minutes);
        if (empty($r['ok'])) return $r;

        // Meeting regularly is a fact about sessions, so it is set by logging
        // one rather than by a mentor ticking a box about themselves.
        $this->db->prepare("UPDATE mentorships SET stage = CASE WHEN stage < 4 THEN 4 ELSE stage END
                             WHERE id = (SELECT mentorship_id FROM mentor_sessions WHERE id = ?) AND goals <> ''")
            ->execute([$sessionId]);
        return ['ok' => true, 'minutes' => $minutes, 'concern' => in_array($mood, ['Worried', 'Upset'], true)];
    }

    public function markMissed(int $sessionId): array
    {
        if (!$this->ownsSession($sessionId)) return ['ok' => false, 'error' => 'Not your session.'];
        return Mentorship::markAttendance($this->mentorId, $sessionId, 'missed');
    }

    /** The Undo behind the toast: back to scheduled, and the hours come off. */
    public function unlogSession(int $sessionId): array
    {
        if (!$this->ownsSession($sessionId)) return ['ok' => false, 'error' => 'Not your session.'];
        $this->db->prepare("UPDATE mentor_sessions SET attendance = 'scheduled', status = 'scheduled',
                            attended_at = '', duration_min = 0, topics = '', mood = '' WHERE id = ?")
            ->execute([$sessionId]);
        return ['ok' => true];
    }

    public function createSession(int $pairingId, string $type, string $when, int $minutes, string $agenda, string $meetUrl = ''): array
    {
        if (!$this->ownsPairing($pairingId)) return ['ok' => false, 'error' => 'Not your mentee.'];
        $types = array_keys(Mentorship::sessionTypes());
        $type  = in_array($type, $types, true) ? $type : 'checkin';
        $title = Mentorship::typeLabel($type);
        return Mentorship::addSession($this->mentorId, $pairingId, $title, $when, trim($agenda), $meetUrl, $minutes, $type);
    }

    /**
     * Send a message, or queue it until morning.
     *
     * Nothing a mentor types at 1am reaches a fifteen-year-old at 1am. The
     * message is stored immediately so it is never lost and the safeguarding
     * lead can always read it; send_after is when the mentee's portal shows it.
     */
    public function sendMessage(int $pairingId, string $body): array
    {
        if (!$this->ownsPairing($pairingId)) return ['ok' => false, 'error' => 'Not your mentee.'];
        $body = mb_substr(trim(strip_tags($body)), 0, 4000);
        if ($body === '') return ['ok' => false, 'error' => 'Write something first.'];

        $now = time();
        $hour = (int) date('G', $now);
        $queued = $hour < self::SEND_FROM || $hour >= self::SEND_TO;
        $after = $queued ? date('Y-m-d H:i:s', mktime(self::SEND_FROM, 0, 0, (int) date('n', $now), (int) date('j', $now) + ($hour >= self::SEND_TO ? 1 : 0), (int) date('Y', $now))) : '';

        $this->db->prepare('INSERT INTO mentor_messages (mentorship_id, sender_id, body, created_at, send_after) VALUES (?,?,?,?,?)')
            ->execute([$pairingId, $this->mentorId, $body, self::now(), $after]);
        return ['ok' => true, 'queued' => $queued, 'when' => date('j M · g:ia', $now)];
    }

    /* ── Values ───────────────────────────────────────────────────────────────
       Evidence is required for Exemplary and for any rise on last time. The
       rule lives here, not only in the form: "Demonstrated" with nothing behind
       it is what a values record fills up with otherwise, and a record of
       unevidenced praise is worth nothing to the young person it describes. */

    private const WEAK_WORDS = ['good', 'great', 'excellent', 'very good', 'nice', 'well done', 'ok', 'okay'];
    public const EVIDENCE_MIN = 18;

    public static function evidenceWeak(string $t): bool
    {
        $t = trim($t);
        if (mb_strlen($t) < self::EVIDENCE_MIN) return true;
        return in_array(mb_strtolower(rtrim($t, '.!')), self::WEAK_WORDS, true);
    }

    public function saveValues(int $pairingId, array $levels, array $evidence): array
    {
        if (!$this->ownsPairing($pairingId)) return ['ok' => false, 'error' => 'Not your mentee.'];
        $last = [];
        foreach ($this->valuesFor($pairingId) as $v) $last[$v['key']] = (int) $v['level'];

        $clean = [];
        foreach (self::VALUES as $key => $label) {
            if (!array_key_exists($key, $levels)) return ['ok' => false, 'error' => 'Rate all seven values. Use Not seen if you didn’t see it.'];
            $lv = (int) $levels[$key];
            if ($lv < 0 || $lv > 3) return ['ok' => false, 'error' => 'That is not one of the four levels.'];
            $ev = trim((string) ($evidence[$key] ?? ''));
            $needs = $lv > 0 && ($lv === 3 || $lv > ($last[$key] ?? 0));
            if ($needs && self::evidenceWeak($ev))
                return ['ok' => false, 'error' => 'Say what they did, not how good it was.', 'value' => $key];
            $clean[$key] = ['level' => $lv, 'evidence' => mb_substr($ev, 0, 1000)];
        }

        $month = gmdate('Y-m'); $now = self::now();
        $ins = $this->db->prepare('INSERT INTO mentor_values (mentorship_id, value_key, level, evidence, month_key, observed_at) VALUES (?,?,?,?,?,?)');
        foreach ($clean as $key => $c) $ins->execute([$pairingId, $key, $c['level'], $c['evidence'], $month, $now]);

        return ['ok' => true, 'next_id' => $this->nextUnobserved($pairingId)];
    }

    /* ── Check-ins ─────────────────────────────────────────────────────────── */

    public function saveCheckin(int $checkinId, int $pairingId, int $q0, int $q1, int $q2): array
    {
        if (!$this->ownsPairing($pairingId)) return ['ok' => false, 'error' => 'Not your mentee.'];
        $st = $this->db->prepare('SELECT 1 FROM mentor_checkins WHERE id = ? AND mentorship_id = ?');
        $st->execute([$checkinId, $pairingId]);
        if (!$st->fetchColumn()) return ['ok' => false, 'error' => 'That check-in is not yours.'];

        // Any of "struggling", "no" or "yes, worried" raises a flag. The mentor
        // is not asked whether to raise it: a question that says "are you worried"
        // and then asks the worried person to decide is not a safeguard.
        $flag = $q0 === 2 || $q1 === 2 || $q2 >= 1;
        $this->db->prepare('UPDATE mentor_checkins SET q0 = ?, q1 = ?, q2 = ?, flagged = ?, sent_at = ? WHERE id = ?')
            ->execute([$q0, $q1, $q2, $flag ? 1 : 0, self::now(), $checkinId]);
        return ['ok' => true, 'flagged' => $flag, 'coordinator' => $this->coordinatorFirstName()];
    }

    /** The coordinator's queue: every flagged check-in nobody has cleared. */
    public static function coordinatorQueue(int $limit = 200): array
    {
        self::ensure();
        try {
            $st = Database::pdo()->prepare(
                "SELECT c.id, c.week, c.sent_at, m.id AS pairing_id, mu.name AS mentor, u.name AS mentee
                   FROM mentor_checkins c
                   JOIN mentorships m ON m.id = c.mentorship_id
                   JOIN lms_users u ON u.id = m.mentee_id
                   JOIN lms_users mu ON mu.id = m.mentor_id
                  WHERE c.flagged = 1 AND c.cleared_at = '' ORDER BY c.sent_at DESC LIMIT {$limit}");
            $st->execute();
            return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) { return []; }
    }

    /* ── Reflections ───────────────────────────────────────────────────────── */

    public function replyToReflection(int $entryId, string $reply): array
    {
        $st = $this->db->prepare(
            "SELECT u.name FROM diary_shares sh
               JOIN diary_entries d ON d.id = sh.entry_id
               JOIN mentorships p ON p.mentee_id = d.author_id AND p.mentor_id = ? AND p.status = 'active'
               JOIN lms_users u ON u.id = d.author_id
              WHERE sh.user_id = ? AND d.id = ? LIMIT 1");
        $st->execute([$this->mentorId, $this->mentorId, $entryId]);
        $name = (string) ($st->fetchColumn() ?: '');
        if ($name === '') return ['ok' => false, 'error' => 'That entry was not shared with you.'];

        $reply = mb_substr(trim(strip_tags($reply)), 0, 500);
        if ($reply === '') return ['ok' => false, 'error' => 'Pick a reply.'];
        $this->db->prepare('DELETE FROM mentor_reflect_replies WHERE entry_id = ? AND mentor_id = ?')->execute([$entryId, $this->mentorId]);
        $this->db->prepare('INSERT INTO mentor_reflect_replies (entry_id, mentor_id, reply, created_at) VALUES (?,?,?,?)')
            ->execute([$entryId, $this->mentorId, $reply, self::now()]);
        return ['ok' => true, 'first' => explode(' ', trim($name))[0]];
    }

    public function unreplyToReflection(int $entryId): array
    {
        $this->db->prepare('DELETE FROM mentor_reflect_replies WHERE entry_id = ? AND mentor_id = ?')->execute([$entryId, $this->mentorId]);
        return ['ok' => true];
    }

    /* ── Requests ──────────────────────────────────────────────────────────── */

    public function acceptRequest(int $pairingId): array
    {
        if (!$this->ownsPairing($pairingId)) return ['ok' => false, 'error' => 'Not your request.'];
        $block = $this->acceptBlock();
        if ($block !== '') return ['ok' => false, 'error' => $block];
        $r = Mentorship::respond($this->mentorId, $pairingId, true);
        if (empty($r['ok'])) return $r;
        $this->db->prepare('UPDATE mentorships SET stage = CASE WHEN stage < 1 THEN 1 ELSE stage END, started_at = ? WHERE id = ? AND started_at = \'\'')
            ->execute([self::now(), $pairingId]);
        return ['ok' => true, 'pairing_id' => $pairingId];
    }

    public function declineRequest(int $pairingId): array
    {
        if (!$this->ownsPairing($pairingId)) return ['ok' => false, 'error' => 'Not your request.'];
        $r = Mentorship::respond($this->mentorId, $pairingId, false);
        if (empty($r['ok'])) return $r;
        return ['ok' => true, 'undo' => $this->undoToken('decline', ['id' => $pairingId])];
    }

    public function undeclineRequest(int $pairingId, string $token): array
    {
        $p = $this->undoPayload($token, 'decline');
        if (!$p || (int) ($p['id'] ?? 0) !== $pairingId) return ['ok' => false, 'error' => 'That can no longer be undone.'];
        $this->db->prepare("UPDATE mentorships SET status = 'pending', updated_at = ? WHERE id = ? AND mentor_id = ?")
            ->execute([self::now(), $pairingId, $this->mentorId]);
        return ['ok' => true];
    }

    /* ── Profile ───────────────────────────────────────────────────────────── */

    public function saveProfile(array $in): array
    {
        $cap = max(1, min(50, (int) ($in['capacity'] ?? 3)));
        return Mentorship::becomeMentor($this->mentorId, [
            'headline'  => (string) ($in['headline'] ?? ''),
            'focus'     => (string) ($in['focus'] ?? ''),
            'bio'       => (string) ($in['bio'] ?? ''),
            'capacity'  => $cap,
            'accepting' => (int) ($in['accepting'] ?? 1) === 1,
        ]);
    }

    /* ── Report a concern ──────────────────────────────────────────────────── */

    public const CONCERN_CATEGORIES = [
        'harm'       => 'Someone is hurting them, or might',
        'self'       => 'They might hurt themselves',
        'home'       => 'Something at home',
        'money'      => 'Money, work or exploitation',
        'online'     => 'Something online',
        'conduct'    => 'Conduct of an adult in the programme',
        'other'      => 'Something else that worries me',
    ];

    public function reportConcern(string $category, string $facts, int $pairingId = 0): array
    {
        if (!isset(self::CONCERN_CATEGORIES[$category])) return ['ok' => false, 'error' => 'Choose what it is about.'];
        $facts = trim(strip_tags($facts));
        if (mb_strlen($facts) < 10) return ['ok' => false, 'error' => 'Write at least a sentence about what happened.'];
        if ($pairingId > 0 && !$this->ownsPairing($pairingId)) $pairingId = 0;

        $caseNo = 'AVS-' . gmdate('ym') . '-' . strtoupper(bin2hex(random_bytes(2)));
        $this->db->prepare('INSERT INTO mentor_concerns (case_no, mentor_id, mentorship_id, category, facts, status, created_at) VALUES (?,?,?,?,?,?,?)')
            ->execute([$caseNo, $this->mentorId, $pairingId, $category, mb_substr($facts, 0, 8000), 'open', self::now()]);
        return ['ok' => true, 'case_no' => $caseNo];
    }

    public function acknowledgeNote(int $noteId): array
    {
        $this->db->prepare('UPDATE mentor_notes SET ack_at = ? WHERE id = ? AND mentor_id = ?')
            ->execute([self::now(), $noteId, $this->mentorId]);
        return ['ok' => true];
    }

    /* ── Bulk (MP-08) ──────────────────────────────────────────────────────────
       One request, at most 100 ids, EVERY id checked. Partial failure is
       reported rather than swallowed: "sent to 23 of 24" is a fact the mentor
       can act on; a silent 23 is one they find out about from the mentee. */

    public function bulk(string $action, array $ids, string $body = ''): array
    {
        $ids = array_slice(array_values(array_unique(array_map('intval', $ids))), 0, 100);
        if (!$ids) return ['ok' => 0, 'failed' => []];
        if (!in_array($action, ['message', 'checkin'], true)) return ['ok' => 0, 'failed' => [], 'error' => 'Unknown action.'];

        $done = []; $failed = [];
        foreach ($ids as $id) {
            if (!$this->ownsPairing($id)) { $failed[] = ['id' => $id, 'name' => $this->nameFor($id)]; continue; }
            if ($action === 'message') {
                $text = trim($body) !== '' ? $body : 'Checking in — how is this week going?';
                $r = $this->sendMessage($id, $text);
            } else {
                $r = $this->requestCheckin($id);
            }
            if (!empty($r['ok'])) $done[] = $id; else $failed[] = ['id' => $id, 'name' => $this->nameFor($id)];
        }
        return ['ok' => count($done), 'failed' => $failed, 'undo' => $this->undoToken('bulk-' . $action, ['ids' => $done])];
    }

    private function requestCheckin(int $pairingId): array
    {
        $this->db->prepare('INSERT INTO mentor_checkins (mentorship_id, week, due_at, sent_at) VALUES (?,?,?,\'\')')
            ->execute([$pairingId, 0, self::now()]);
        return ['ok' => true, 'id' => (int) $this->db->lastInsertId()];
    }

    public function bulkUndo(string $token): array
    {
        $p = $this->undoPayload($token, null);
        if (!$p) return ['ok' => false, 'error' => 'That can no longer be undone.'];
        $ids = array_map('intval', (array) ($p['ids'] ?? []));
        if (!$ids) return ['ok' => true];
        $in = implode(',', array_fill(0, count($ids), '?'));
        $this->db->prepare("DELETE FROM mentor_checkins WHERE week = 0 AND sent_at = '' AND mentorship_id IN ($in)")->execute($ids);
        return ['ok' => true];
    }

    private function nameFor(int $pairingId): string
    {
        try {
            $st = $this->db->prepare('SELECT u.name FROM mentorships m JOIN lms_users u ON u.id = m.mentee_id WHERE m.id = ?');
            $st->execute([$pairingId]);
            return (string) ($st->fetchColumn() ?: 'that mentee');
        } catch (Throwable $e) { return 'that mentee'; }
    }

    /* Undo tokens are short-lived rows rather than a signed blob, so an undo
       cannot be replayed a week later against a pairing that has moved on. */
    public const UNDO_TTL = 900;

    private function undoToken(string $op, array $payload): string
    {
        $t = bin2hex(random_bytes(12));
        try {
            $this->db->prepare('INSERT INTO mentor_undo (token, mentor_id, op, payload, created_at) VALUES (?,?,?,?,?)')
                ->execute([$t, $this->mentorId, $op, json_encode($payload), self::now()]);
            $this->db->prepare('DELETE FROM mentor_undo WHERE created_at < ?')->execute([gmdate('Y-m-d H:i:s', time() - self::UNDO_TTL)]);
        } catch (Throwable $e) { return ''; }
        return $t;
    }

    private function undoPayload(string $token, ?string $op): ?array
    {
        if ($token === '') return null;
        try {
            $st = $this->db->prepare('SELECT op, payload, created_at FROM mentor_undo WHERE token = ? AND mentor_id = ?');
            $st->execute([$token, $this->mentorId]);
            $r = $st->fetch(PDO::FETCH_ASSOC);
            if (!$r) return null;
            if ($op !== null && (string) $r['op'] !== $op) return null;
            if ((strtotime(((string) $r['created_at']) . ' UTC') ?: 0) < time() - self::UNDO_TTL) return null;
            $this->db->prepare('DELETE FROM mentor_undo WHERE token = ?')->execute([$token]);
            return json_decode((string) $r['payload'], true) ?: [];
        } catch (Throwable $e) { return null; }
    }

    /* ── Plan the next session ────────────────────────────────────────────────
       Four lines built from THIS pairing's own record — the goals, the last
       outcome, the value least recently seen, the latest check-in. It drafts;
       the mentor decides. It never proposes contact outside the portal, which
       is a rule about what it may say, enforced where the lines are made. */

    public function planLines(int $pairingId): array
    {
        $c = $this->caseFile($pairingId);
        if (!$c) return [];
        $first = $c['first'];
        /* The goal it asks about is a goal still OPEN — one already met is not
           the thing to chase, and one set aside is the thing you both agreed
           to stop chasing. */
        $open = array_values(array_filter($c['goals_list'], fn($g) => $g['status'] === 'open'));
        $goal = $open ? self::firstSentence($open[0]['title']) : '';
        $lastOutcome = $c['last']['outcome'] ?? '';

        $weakest = null;
        foreach ($c['values'] as $v) if ($weakest === null || $v['level'] < $weakest['level']) $weakest = $v;

        $ck = $c['checkin'];
        $check = $ck === null
            ? 'how the last few weeks have actually been'
            : ($ck['q2'] >= 1 ? 'what has been worrying them, slowly and without pressing' : 'what has gone well since you last spoke');

        return [
            'Open'  => 'Ask ' . $first . ' how the week went before anything else.',
            'Check' => 'Check ' . $check . '.',
            'Ask'   => $goal !== ''
                ? 'Ask what moved on “' . $goal . '” and what got in the way.'
                : 'Agree what you are both working towards, and write it down together.',
            'Agree' => ($lastOutcome !== ''
                ? 'Agree the next step after “' . self::firstSentence($lastOutcome) . '”'
                : 'Agree one thing ' . $first . ' will do before you next meet')
                . ($weakest && $weakest['level'] === 0 ? ', and look for ' . mb_strtolower($weakest['label']) . ' while you talk.' : '.'),
        ];
    }

    private static function firstSentence(string $s): string
    {
        $s = trim(preg_replace('/\s+/u', ' ', strip_tags($s)) ?? '');
        $parts = preg_split('/(?<=[.!?;])\s+/u', $s) ?: [$s];
        return mb_substr($parts[0], 0, 120);
    }
}
