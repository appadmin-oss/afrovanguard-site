<?php
/**
 * MentorPortal — read model for /mentorship/mentor/. Wraps Mentorship (contract) and the DB.
 * Every method is scoped to $this->mentorId. Every write endpoint in mentorship/api.php MUST call ownsPairing() per id.
 *
 * THIS FILE IS A SKELETON: method signatures + return shapes the views rely on, and the roster SQL pattern.
 * Verify table/column names against db/schema.sql before use. If a column does not exist, STOP AND ASK.
 */
final class MentorPortal
{
    public const PER_PAGE = 25;
    private PDO $db;

    public function __construct(private int $mentorId) { $this->db = Database::pdo(); }

    public function ownsPairing(int $pairingId): bool {
        $s = $this->db->prepare('SELECT 1 FROM mentorships WHERE id = ? AND mentor_id = ?');
        $s->execute([$pairingId, $this->mentorId]);
        return (bool)$s->fetchColumn();
    }

    /** ['name','initials','academy_line'] */
    public function me(): array { /* … */ return []; }

    /** ONE query. ['today'=>n,'values'=>n,'checkins'=>n,'reflections'=>n,'requests'=>n,'academy'=>n] */
    public function navBadges(): array { /* single SELECT with scalar sub-selects */ return []; }

    /**
     * Roster: filter, sort, paginate IN SQL. ≤ 4 queries per call:
     *   1) page rows  2) filtered total  3) filter counts (one query, SUM(CASE…))  4) unfiltered total (or fold into 3)
     * Returns ['rows'=>[], 'total'=>int, 'all'=>int, 'counts'=>['all'=>n,'attn'=>n,'wait'=>n,'goals'=>n,'log'=>n,'closing'=>n], 'page'=>int]
     * Row: pairing_id, name, initials, health, tone(green|indigo|gold|red), track, chapter, stage_label, days|null, kept|null, next|null
     */
    public function roster(string $q, string $filter, string $sort, int $page): array {
        $base = "
          FROM mentorships m
          JOIN users u ON u.id = m.mentee_id
          LEFT JOIN (
            SELECT mentorship_id,
                   MAX(CASE WHEN status = 'attended' THEN scheduled_at END)             AS last_at,
                   SUM(status = 'attended')                                             AS attended,
                   SUM(status IN ('attended','missed'))                                 AS held,
                   MIN(CASE WHEN status = 'scheduled' AND scheduled_at > NOW() THEN scheduled_at END) AS next_at,
                   SUM(status = 'scheduled' AND scheduled_at < NOW())                   AS to_log
            FROM mentor_sessions GROUP BY mentorship_id
          ) s ON s.mentorship_id = m.id
          WHERE m.mentor_id = :mid AND m.status = 'active'";
        $days = "DATEDIFF(NOW(), COALESCE(s.last_at, m.started_at))";   // SQLite: CAST(julianday('now') - julianday(...) AS INT)
        $F = [
            'all'     => '1=1',
            'attn'    => "($days > 21 OR (s.held > 0 AND s.attended / s.held < 0.7) OR m.goals IS NULL OR m.goals = '')",
            'wait'    => "$days > 21",
            'goals'   => "(m.goals IS NULL OR m.goals = '')",
            'log'     => 's.to_log > 0',
            'closing' => "(m.stage = 6 OR m.ends_at < DATE_ADD(NOW(), INTERVAL 45 DAY))",
        ];
        $S = [
            'wait'  => "$days DESC, u.name ASC",
            'name'  => 'u.name ASC',
            'kept'  => '(s.attended / NULLIF(s.held,0)) ASC, u.name ASC',
            'stage' => 'm.stage DESC, u.name ASC',
        ];
        $where = $F[$filter] ?? $F['all'];
        $order = $S[$sort] ?? $S['wait'];
        $params = [':mid' => $this->mentorId];
        $search = '';
        if ($q !== '') { $search = " AND (u.name LIKE :q OR m.track LIKE :q OR u.chapter LIKE :q)"; $params[':q'] = '%' . $q . '%'; }
        $offset = 0; $limit = $page * self::PER_PAGE;           // full page set for SSR; partial=rows uses ($page-1)*25, 25
        if (($_GET['partial'] ?? '') === 'rows') { $offset = ($page - 1) * self::PER_PAGE; $limit = self::PER_PAGE; }

        $rows = $this->db->prepare("SELECT m.id AS pairing_id, u.name, m.track, u.chapter, m.stage, $days AS days,
               ROUND(100 * s.attended / NULLIF(s.held,0)) AS kept, s.next_at $base $search AND $where ORDER BY $order LIMIT $limit OFFSET $offset");
        $rows->execute($params);

        $countSql = "SELECT COUNT(*) AS all_n";
        foreach ($F as $k => $cond) $countSql .= ", SUM(CASE WHEN $cond THEN 1 ELSE 0 END) AS c_$k";
        $countSql .= ", SUM(CASE WHEN $where" . ($q !== '' ? " AND (u.name LIKE :q OR m.track LIKE :q OR u.chapter LIKE :q)" : '') . " THEN 1 ELSE 0 END) AS filtered $base";
        $c = $this->db->prepare($countSql); $c->execute($params); $c = $c->fetch(PDO::FETCH_ASSOC);

        $counts = []; foreach ($F as $k => $_) $counts[$k] = (int)$c["c_$k"];
        return ['rows' => array_map([$this, 'shapeRow'], $rows->fetchAll(PDO::FETCH_ASSOC)), 'total' => (int)$c['filtered'], 'all' => (int)$c['all_n'], 'counts' => $counts, 'page' => $page];
    }

    /** [index (1-based), total, prevId, nextId] within the same filter/sort — window function or two keyset queries. */
    public function positionInRoster(int $pairingId, string $q, string $filter, string $sort): array { return [1, 1, $pairingId, $pairingId]; }

    public function today(): array { return []; }
    public function caseFile(int $pairingId): ?array { return $this->ownsPairing($pairingId) ? [] : null; }
    public function valuesFor(int $pairingId): array { return []; }
    public function checkins(bool $all): array { return []; }
    public function reflections(int $page): array { return []; }
    public function requests(): array { return []; }      // includes safeguarding_ok (Academy: safeguarding module not lapsed)
    public function academy(): array { return []; }
    public function academyModule(string $key): ?array { return null; }
    public function profile(): array { return []; }
    public function menteeOptions(): array { return []; }
    public function coordinatorFirstName(): string { return 'Ifeoluwa'; }   // from config/coordinator assignment

    private function shapeRow(array $r): array {
        $stages = ['Matched', 'Kick-off', 'Goals agreed', 'Meeting regularly', 'Mid-point review', 'Planned close'];
        $days = $r['days'] === null ? null : (int)$r['days'];
        [$health, $tone] = match (true) {
            (int)$r['stage'] <= 2          => ['Just started', 'indigo'],
            $days !== null && $days > 21   => ['Needs attention', 'red'],
            $days !== null && $days > 14   => ['Check in soon', 'gold'],
            default                        => ['Going well', 'green'],
        };
        $parts = preg_split('/\s+/', trim($r['name']));
        return [
            'pairing_id' => (int)$r['pairing_id'], 'name' => $r['name'],
            'initials' => mb_strtoupper(mb_substr($parts[0], 0, 1) . mb_substr(end($parts), 0, 1)),
            'health' => $health, 'tone' => $tone, 'track' => $r['track'], 'chapter' => $r['chapter'],
            'stage_label' => $stages[max(0, (int)$r['stage'] - 1)], 'days' => $days,
            'kept' => $r['kept'] === null ? null : (int)$r['kept'],
            'next' => $r['next_at'] ? date('D M j · g:ia', strtotime($r['next_at'])) : null,
        ];
    }
}
