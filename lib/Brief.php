<?php
/**
 * lib/Brief.php — the leadership brief (report §21, §31, §38).
 *
 * The report's payoff, and the thing it builds toward for thirty pages:
 *
 *   "Instead of asking 'What happened last week?' the AI says: 87% of scheduled
 *    mentorship meetings were completed. 8 relationships are at risk. 4 members
 *    are ready for Level A assessment. Three leaders require intervention."
 *
 *   "This changes leadership from chasing people to managing exceptions." (§21)
 *
 * THE DESIGN DECISION THAT MATTERS: the numbers are computed here, in code, and
 * the model only writes the prose around them. Never the other way round.
 *
 * A brief is read by people who will act on it — reassign a mentor, open a
 * promotion review, intervene in a relationship. A figure a language model
 * produced by inference is worse than no figure at all, because it is
 * indistinguishable from one that was counted. So metrics() does arithmetic
 * against the database and nothing else, narrate() receives those figures as
 * fixed text, and the prompt (AvPrompts 'leadership.brief') tells the model in
 * as many words that every line must trace to a supplied figure.
 *
 * If no provider answers, the brief still ships — assembled deterministically
 * from the same metrics. §21's value is the exception list, and the exception
 * list is arithmetic. The model makes it readable, not true.
 *
 * FOUR CATEGORIES, from §31:
 *   critical     — someone must act this week
 *   attention    — worth a leader's eye, not yet urgent
 *   opportunity  — someone has earned a review (promotions, §20)
 *   growth       — what improved, measured against the previous brief
 *
 * Growth is a genuine delta, not a vibe: each brief stores its own counters, and
 * the next one of the same period compares against them. The first brief of any
 * period reports no growth, because there is nothing honest to compare against.
 *
 * §23 governs the whole thing. The brief observes, records, analyses and
 * recommends. It decides nothing, and it never scores a person.
 */
declare(strict_types=1);

final class Brief
{
    private static bool $ready = false;

    /** Periods the brief understands, and how far back each looks. */
    private const WINDOWS = ['day' => 1, 'week' => 7, 'month' => 30];

    public static function ensure(): void
    {
        if (self::$ready) return;
        self::$ready = true;
        try {
            Database::execSchema(Database::pdo(), "CREATE TABLE IF NOT EXISTS av_briefs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                period VARCHAR(8) NOT NULL DEFAULT 'week',
                from_at VARCHAR(32) NOT NULL DEFAULT '',
                to_at VARCHAR(32) NOT NULL DEFAULT '',
                metrics TEXT NOT NULL DEFAULT '',
                narrative TEXT NOT NULL DEFAULT '',
                source VARCHAR(16) NOT NULL DEFAULT '',
                created_at VARCHAR(32) NOT NULL DEFAULT ''
            );
            CREATE INDEX IF NOT EXISTS idx_avbrief ON av_briefs(period, id);");
        } catch (Throwable $e) { error_log('[brief] ensure: ' . $e->getMessage()); }
    }

    public static function periods(): array { return array_keys(self::WINDOWS); }

    /* ════════════════════════════════════════════════════════════════
       The figures — arithmetic only, no model anywhere near this
       ════════════════════════════════════════════════════════════════ */

    /**
     * Everything §21 and §38 ask for, counted.
     *
     * @return array the metric block, with `notes` explaining anything a reader
     *               could otherwise misread (an empty org, a first-ever brief)
     */
    public static function metrics(string $period = 'week'): array
    {
        self::ensure();
        $days = self::WINDOWS[$period] ?? 7;
        $from = gmdate('Y-m-d H:i:s', time() - $days * 86400);
        $to   = gmdate('Y-m-d H:i:s');

        $m = [
            'period' => $period,
            'from'   => $from,
            'to'     => $to,
            'meetings'       => ['due' => 0, 'completed' => 0, 'missed' => 0, 'cancelled' => 0, 'upcoming' => 0, 'rate' => null],
            'relationships'  => ['active' => 0, 'red' => 0, 'amber' => 0, 'green' => 0, 'at_risk' => []],
            'commitments'    => ['overdue' => 0],
            'promotions'     => ['ready' => 0, 'members' => [], 'near_count' => 0, 'near' => []],
            'multiplication' => ['stalled' => 0, 'leaders' => []],
            'escalations'    => ['raised' => 0, 'to_leadership' => 0],
            'growth'         => [],
            'notes'          => [],
        ];

        try {
            $pairs = class_exists('Accountability') ? Accountability::activePairs() : [];
            $m['relationships']['active'] = count($pairs);
            if (!$pairs) { $m['notes'][] = 'There are no active mentorship pairings yet.'; }

            foreach ($pairs as $p) {
                $h = Accountability::health($p['id']);
                $m['relationships'][$h['status']]++;
                if ($h['status'] === 'red') {
                    $m['relationships']['at_risk'][] = [
                        'pair'    => $p['id'],
                        'mentor'  => $p['mentor'],
                        'mentee'  => $p['mentee'],
                        'reasons' => $h['reasons'],
                    ];
                }

                // Meetings inside the window, from the sessions we already need.
                foreach (Mentorship::sessions($p['id']) as $s) {
                    $when = (string) ($s['when'] ?? '');
                    if ($when === '') continue;
                    if (!empty($s['past'])) {
                        if ($when < $from) continue;
                        $a = (string) ($s['attendance'] ?? '');
                        if ($a === 'cancelled') { $m['meetings']['cancelled']++; continue; }
                        $m['meetings']['due']++;
                        if ($a === 'attended') $m['meetings']['completed']++;
                        else                   $m['meetings']['missed']++;   // recorded missed, or never recorded
                    } elseif (($s['attendance'] ?? '') !== 'cancelled') {
                        $m['meetings']['upcoming']++;
                    }
                }
            }
            if ($m['meetings']['due'] > 0) {
                $m['meetings']['rate'] = (int) round(100 * $m['meetings']['completed'] / $m['meetings']['due']);
            } else {
                $m['notes'][] = 'No mentorship meetings were scheduled to happen in this period.';
            }

            // §20 — who has EARNED a review. A recommendation, never a promotion.
            // Only mentors are assessed: under the O–G model advancement requires
            // active mentees, so nobody else can qualify. That is the model's rule,
            // not a shortcut to save queries.
            if (class_exists('Levels')) {
                $needA  = class_exists('AvRules') ? max(1, AvRules::int('levels.active_mentees_for_a')) : 2;
                $minPct = class_exists('AvRules') ? AvRules::int('levels.min_attendance_pct') : 85;
                $seen = [];
                foreach ($pairs as $p) {
                    $uid = $p['mentor_id'];
                    if (isset($seen[$uid])) continue;
                    $seen[$uid] = true;
                    $r  = Levels::recommend($uid);
                    $rm = (array) ($r['metrics'] ?? []);
                    if (!empty($r['recommend'])) {
                        $m['promotions']['members'][] = [
                            'user_id' => $uid, 'name' => $p['mentor'],
                            'level' => (string) $r['level'], 'next' => (string) ($r['next'] ?? ''),
                            'reasons' => array_slice((array) ($r['reasons'] ?? []), 0, 4),
                        ];
                    } elseif (($r['next'] ?? null) !== null
                              && (int) ($rm['active_mentees'] ?? 0) >= $needA
                              && ($rm['attendance_pct'] ?? null) !== null
                              && (int) $rm['attendance_pct'] >= $minPct) {
                        // §20 asks for who is APPROACHING the criteria, not only who
                        // has cleared them. Someone can meet every measurable test
                        // and still be held back by something the engine cannot
                        // verify — most often a tenure date that predates tracking.
                        // Left out, that person is invisible to leadership forever,
                        // which is the exact failure §21 exists to fix. Judged on
                        // the structured metrics, never on the wording of a gap.
                        $m['promotions']['near'][] = [
                            'user_id' => $uid, 'name' => $p['mentor'],
                            'level' => (string) $r['level'], 'next' => (string) ($r['next'] ?? ''),
                            'blocked_by' => array_slice((array) ($r['gaps'] ?? []), 0, 3),
                        ];
                    }
                    // §17 — a chain that has stopped reproducing. Active mentees but
                    // none of them mentoring anyone is the "looks fine on paper"
                    // case the report singles out.
                    $active = Mentorship::activeMenteeCount($uid);
                    if ($active > 0 && Mentorship::multiplyingMenteeCount($uid) === 0 && Levels::of($uid) !== Levels::base()) {
                        $m['multiplication']['leaders'][] = [
                            'user_id' => $uid, 'name' => $p['mentor'],
                            'level' => Levels::of($uid), 'active_mentees' => $active,
                        ];
                    }
                }
                $m['promotions']['ready']      = count($m['promotions']['members']);
                $m['promotions']['near_count'] = count($m['promotions']['near']);
                $m['multiplication']['stalled'] = count($m['multiplication']['leaders']);
            }

            if (class_exists('Commitments')) $m['commitments']['overdue'] = count(Commitments::overdue(500));

            // Escalations raised inside the window.
            $st = Database::pdo()->prepare('SELECT step, notified FROM av_escalations WHERE created_at >= ?');
            $st->execute([$from]);
            $steps = class_exists('AvRules') ? max(1, AvRules::int('escalation.steps')) : 3;
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
                $m['escalations']['raised']++;
                if ((int) $r['step'] >= $steps) $m['escalations']['to_leadership']++;
            }

            $m['growth'] = self::growthAgainstPrevious($period, $m);
        } catch (Throwable $e) {
            error_log('[brief] metrics: ' . $e->getMessage());
            $m['notes'][] = 'Some figures could not be gathered; treat this brief as partial.';
        }
        return $m;
    }

    /**
     * Deltas against the previous brief of the same period.
     *
     * Returns an empty list when there is no prior brief. The report's §31 wants
     * "increased by 18%", and the only honest source for that is a figure we
     * actually recorded last time — not an estimate, and not a comparison against
     * a window we never measured.
     */
    private static function growthAgainstPrevious(string $period, array $now): array
    {
        $prev = self::latest($period);
        if (!$prev || empty($prev['metrics'])) return [];
        $old = $prev['metrics'];
        $out = [];

        $delta = static function (?int $a, ?int $b): ?int {
            return ($a === null || $b === null) ? null : $a - $b;
        };
        $pairs = $delta($now['relationships']['active'] ?? null, $old['relationships']['active'] ?? null);
        if ($pairs !== null && $pairs !== 0) {
            $out[] = ($pairs > 0 ? '+' : '') . $pairs . ' active pairing' . (abs($pairs) === 1 ? '' : 's') . ' since the last brief';
        }
        $green = $delta($now['relationships']['green'] ?? null, $old['relationships']['green'] ?? null);
        if ($green !== null && $green !== 0) {
            $out[] = ($green > 0 ? '+' : '') . $green . ' relationship' . (abs($green) === 1 ? '' : 's') . ' now Green';
        }
        $rate = $delta($now['meetings']['rate'] ?? null, $old['meetings']['rate'] ?? null);
        if ($rate !== null && $rate !== 0) {
            $out[] = 'meeting completion ' . ($rate > 0 ? 'up ' : 'down ') . abs($rate) . ' points, to ' . (int) $now['meetings']['rate'] . '%';
        }
        $od = $delta($now['commitments']['overdue'] ?? null, $old['commitments']['overdue'] ?? null);
        if ($od !== null && $od !== 0) {
            $out[] = ($od < 0 ? abs($od) . ' fewer' : $od . ' more') . ' overdue commitment' . (abs($od) === 1 ? '' : 's');
        }
        return $out;
    }

    /* ════════════════════════════════════════════════════════════════
       The prose
       ════════════════════════════════════════════════════════════════ */

    /**
     * Turn the figures into §31's four categories.
     *
     * The model gets the metrics as text and is told it may not invent a number.
     * When it declines, fails, or is switched off, assemble() produces the same
     * four categories from the same figures — plainer, and just as true.
     *
     * @return array{headline:string,critical:string[],attention:string[],opportunity:string[],growth:string[],source:string}
     */
    public static function narrate(array $metrics): array
    {
        $fallback = self::assemble($metrics);

        if (class_exists('AvRules') && !AvRules::bool('ai.enabled')) return $fallback;
        if (!class_exists('AvPrompts') || !AvPrompts::isKey('leadership.brief')) return $fallback;

        $sys = AvPrompts::render('leadership.brief', [
            'period'  => self::periodLabel((string) ($metrics['period'] ?? 'week')),
            'metrics' => self::figuresAsText($metrics),
        ]);
        if (trim($sys) === '') return $fallback;

        $r = class_exists('AvAgent')
            ? AvAgent::complete($sys, 'Write the brief now.', ['max_tokens' => 1200, 'temperature' => 0.2])
            : ['ok' => false];
        if (empty($r['ok'])) return $fallback;

        $j = self::json((string) $r['text']);
        if (!is_array($j) || trim((string) ($j['headline'] ?? '')) === '') return $fallback;

        $list = static function ($v): array {
            $out = [];
            foreach ((array) $v as $x) { $x = trim((string) $x); if ($x !== '') $out[] = mb_substr($x, 0, 400); }
            return array_slice($out, 0, 12);
        };
        return [
            'headline'    => mb_substr(trim((string) $j['headline']), 0, 300),
            'critical'    => $list($j['critical'] ?? []),
            'attention'   => $list($j['attention'] ?? []),
            'opportunity' => $list($j['opportunity'] ?? []),
            // Growth is measured, not written. The model may rephrase the other
            // three from the figures; it does not get to author a trend.
            'growth'      => (array) ($metrics['growth'] ?? []),
            'source'      => (string) ($r['provider'] ?? 'ai'),
        ];
    }

    /**
     * The brief with no model involved.
     *
     * Not a placeholder — this is what ships whenever no provider answers, and it
     * has to stand on its own. Every line is a counted figure.
     */
    public static function assemble(array $m): array
    {
        $rel  = $m['relationships'] ?? [];
        $mt   = $m['meetings'] ?? [];
        $crit = []; $att = []; $opp = [];

        foreach (($rel['at_risk'] ?? []) as $r) {
            $crit[] = $r['mentor'] . ' → ' . $r['mentee'] . ': ' . implode('; ', array_slice($r['reasons'], 0, 2)) . '.';
        }
        if (($m['escalations']['to_leadership'] ?? 0) > 0) {
            $crit[] = (int) $m['escalations']['to_leadership'] . ' escalation(s) reached the leadership chain this period.';
        }

        if (($mt['missed'] ?? 0) > 0) {
            $att[] = (int) $mt['missed'] . ' of ' . (int) $mt['due'] . ' meetings due this period have no attendance recorded.';
        }
        if (($rel['amber'] ?? 0) > 0) $att[] = (int) $rel['amber'] . ' relationship(s) are Amber and worth a look before they slip.';
        if (($m['commitments']['overdue'] ?? 0) > 0) $att[] = (int) $m['commitments']['overdue'] . ' organisational commitment(s) are overdue.';
        foreach (($m['multiplication']['leaders'] ?? []) as $l) {
            $att[] = $l['name'] . ' (' . $l['level'] . ') has ' . (int) $l['active_mentees'] . ' active mentee(s), none of whom are yet mentoring.';
        }

        foreach (($m['promotions']['members'] ?? []) as $p) {
            $opp[] = $p['name'] . ' meets the criteria for ' . ($p['next'] !== '' ? $p['next'] : 'the next level') . ' — ready for review.';
        }
        foreach (($m['promotions']['near'] ?? []) as $p) {
            $opp[] = $p['name'] . ' meets every measurable criterion for ' . ($p['next'] !== '' ? $p['next'] : 'the next level')
                   . ($p['blocked_by'] ? ' — outstanding: ' . implode('; ', $p['blocked_by']) : ' — needs a manual check.');
        }

        $rate = $mt['rate'] ?? null;
        $headline = $rate !== null
            ? $rate . '% of mentorship meetings were completed this period; ' . (int) ($rel['red'] ?? 0) . ' relationship(s) need intervention.'
            : 'No mentorship meetings fell due this period; ' . (int) ($rel['active'] ?? 0) . ' pairing(s) are active.';

        return [
            'headline'    => $headline,
            'critical'    => $crit,
            'attention'   => $att,
            'opportunity' => $opp,
            'growth'      => (array) ($m['growth'] ?? []),
            'source'      => 'computed',
        ];
    }

    /** The metrics as the flat, unambiguous text the prompt is fed. */
    public static function figuresAsText(array $m): string
    {
        $rel = $m['relationships'] ?? []; $mt = $m['meetings'] ?? [];
        $L = [];
        $L[] = 'Period: ' . substr((string) ($m['from'] ?? ''), 0, 10) . ' to ' . substr((string) ($m['to'] ?? ''), 0, 10);
        $L[] = 'Mentorship meetings due in period: ' . (int) ($mt['due'] ?? 0)
             . ' (completed ' . (int) ($mt['completed'] ?? 0)
             . ', no attendance recorded ' . (int) ($mt['missed'] ?? 0)
             . ', cancelled ' . (int) ($mt['cancelled'] ?? 0) . ')';
        $L[] = 'Completion rate: ' . (($mt['rate'] ?? null) === null ? 'not applicable (nothing was due)' : (int) $mt['rate'] . '%');
        $L[] = 'Meetings scheduled ahead: ' . (int) ($mt['upcoming'] ?? 0);
        $L[] = 'Active pairings: ' . (int) ($rel['active'] ?? 0)
             . ' (Red ' . (int) ($rel['red'] ?? 0) . ', Amber ' . (int) ($rel['amber'] ?? 0) . ', Green ' . (int) ($rel['green'] ?? 0) . ')';
        foreach (($rel['at_risk'] ?? []) as $r) {
            $L[] = '  RED — ' . $r['mentor'] . ' mentoring ' . $r['mentee'] . ': ' . implode('; ', $r['reasons']);
        }
        $L[] = 'Escalations raised in period: ' . (int) ($m['escalations']['raised'] ?? 0)
             . ' (reached the leadership chain: ' . (int) ($m['escalations']['to_leadership'] ?? 0) . ')';
        $L[] = 'Overdue organisational commitments: ' . (int) ($m['commitments']['overdue'] ?? 0);
        $L[] = 'Members meeting the criteria for their next level: ' . (int) ($m['promotions']['ready'] ?? 0);
        foreach (($m['promotions']['members'] ?? []) as $p) {
            $L[] = '  READY — ' . $p['name'] . ' at ' . $p['level'] . ' → ' . ($p['next'] ?: 'next')
                 . ($p['reasons'] ? ' (' . implode('; ', $p['reasons']) . ')' : '');
        }
        $L[] = 'Members meeting every MEASURABLE criterion but blocked on a manual check: ' . (int) ($m['promotions']['near_count'] ?? 0);
        foreach (($m['promotions']['near'] ?? []) as $p) {
            $L[] = '  NEEDS A HUMAN CHECK — ' . $p['name'] . ' at ' . $p['level'] . ' → ' . ($p['next'] ?: 'next')
                 . ($p['blocked_by'] ? ' (outstanding: ' . implode('; ', $p['blocked_by']) . ')' : '');
        }
        $L[] = 'Leaders whose multiplication has stalled: ' . (int) ($m['multiplication']['stalled'] ?? 0);
        foreach (($m['multiplication']['leaders'] ?? []) as $l) {
            $L[] = '  STALLED — ' . $l['name'] . ' (' . $l['level'] . '), ' . (int) $l['active_mentees'] . ' active mentee(s), 0 multiplying';
        }
        foreach (($m['growth'] ?? []) as $g) $L[] = 'Change since the last brief: ' . $g;
        foreach (($m['notes'] ?? []) as $n)  $L[] = 'Note: ' . $n;
        return implode("\n", $L);
    }

    /* ════════════════════════════════════════════════════════════════
       Generating, storing, reading
       ════════════════════════════════════════════════════════════════ */

    /**
     * Compute, narrate, store, and tell leadership it is there.
     *
     * Runs at most once per period unless forced: one brief a day for 'day', one
     * a week for 'week', one a month for 'month'.
     */
    public static function generate(string $period = 'week', bool $force = false): array
    {
        self::ensure();
        if (!isset(self::WINDOWS[$period])) return ['ok' => false, 'error' => 'Unknown period.'];

        if (!$force) {
            $last = self::latest($period);
            if ($last && !self::isDue($period, (string) $last['created_at'])) {
                return ['ok' => true, 'skipped' => true, 'why' => 'the current ' . $period . ' already has a brief', 'brief' => $last];
            }
        }

        // The heaviest job on the tick: two session reads and a level assessment
        // per pairing. Shared hosting defaults to a 30s limit (AI-AUDIT.md A-12),
        // and a brief cut off half way is worse than a brief that took a minute.
        @set_time_limit(300);

        $m = self::metrics($period);
        $n = self::narrate($m);

        $id = 0;
        try {
            Database::pdo()->prepare(
                'INSERT INTO av_briefs (period, from_at, to_at, metrics, narrative, source, created_at) VALUES (?,?,?,?,?,?,?)'
            )->execute([$period, (string) $m['from'], (string) $m['to'],
                        (string) json_encode($m, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        (string) json_encode($n, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        (string) $n['source'], gmdate('Y-m-d H:i:s')]);
            $id = (int) Database::pdo()->lastInsertId();
        } catch (Throwable $e) { error_log('[brief] store: ' . $e->getMessage()); }

        self::notifyLeadership($period, $n, $id);

        return ['ok' => true, 'skipped' => false, 'id' => $id, 'period' => $period, 'metrics' => $m, 'narrative' => $n];
    }

    /** Is a new brief due for this period, given when the last one was written? */
    public static function isDue(string $period, string $lastAt): bool
    {
        $t = $lastAt !== '' ? strtotime($lastAt) : 0;
        if (!$t) return true;
        switch ($period) {
            case 'day':   return gmdate('Y-m-d', $t) !== gmdate('Y-m-d');
            case 'month': return gmdate('Y-m', $t)   !== gmdate('Y-m');
            default:      return gmdate('o-W', $t)   !== gmdate('o-W');   // ISO week
        }
    }

    /** The most recent brief for a period, decoded. */
    public static function latest(string $period = 'week'): ?array
    {
        self::ensure();
        try {
            $st = Database::pdo()->prepare('SELECT * FROM av_briefs WHERE period = ? ORDER BY id DESC LIMIT 1');
            $st->execute([$period]);
            $r = $st->fetch(PDO::FETCH_ASSOC);
            return $r ? self::decode($r) : null;
        } catch (Throwable $e) { error_log('[brief] latest: ' . $e->getMessage()); return null; }
    }

    /** Recent briefs, newest first. */
    public static function history(string $period = 'week', int $limit = 12): array
    {
        self::ensure();
        try {
            $st = Database::pdo()->prepare('SELECT * FROM av_briefs WHERE period = ? ORDER BY id DESC LIMIT ' . max(1, min(50, $limit)));
            $st->execute([$period]);
            return array_map([self::class, 'decode'], $st->fetchAll(PDO::FETCH_ASSOC) ?: []);
        } catch (Throwable $e) { return []; }
    }

    private static function decode(array $r): array
    {
        return [
            'id'         => (int) $r['id'],
            'period'     => (string) $r['period'],
            'from'       => (string) $r['from_at'],
            'to'         => (string) $r['to_at'],
            'metrics'    => json_decode((string) $r['metrics'], true) ?: [],
            'narrative'  => json_decode((string) $r['narrative'], true) ?: [],
            'source'     => (string) $r['source'],
            'created_at' => (string) $r['created_at'],
        ];
    }

    /**
     * §21: "Leaders should not have to search dashboards every day. The AI should
     * proactively bring important matters to them."
     *
     * One notification per brief, carrying the headline and the count that decides
     * whether it is worth opening now.
     */
    private static function notifyLeadership(string $period, array $n, int $id): void
    {
        if (!class_exists('AdminRoles') || !class_exists('Notifications')) return;
        try {
            $crit = count((array) ($n['critical'] ?? []));
            $body = (string) $n['headline'];
            if ($crit > 0) $body .= ' ' . $crit . ' item(s) need a decision.';
            // The Studio roster is keyed by email, and admin_users stores it
            // lowercased while lms_users may not — so match case-insensitively or
            // the brief silently reaches nobody.
            $find = Database::pdo()->prepare('SELECT id FROM lms_users WHERE LOWER(email) = ? LIMIT 1');
            foreach (AdminRoles::list() as $a) {
                $email = strtolower(trim((string) ($a['email'] ?? '')));
                if ($email === '') continue;
                $find->execute([$email]);
                $uid = (int) ($find->fetchColumn() ?: 0);
                if ($uid <= 0) continue;      // on the roster but has never signed in
                Notifications::push($uid, 'brief', self::periodLabel($period) . ' leadership brief', $body,
                    '/admin/#overview', 'brief:' . $period . ':' . $id);
            }
        } catch (Throwable $e) { error_log('[brief] notify: ' . $e->getMessage()); }
    }

    public static function periodLabel(string $p): string
    {
        return $p === 'day' ? 'Daily' : ($p === 'month' ? 'Monthly' : 'Weekly');
    }

    /** Pull the JSON object out of a model reply that may be fenced or padded. */
    private static function json(string $s)
    {
        $s = trim(preg_replace('/^```(?:json)?|```$/m', '', $s) ?? $s);
        $a = strpos($s, '{'); $b = strrpos($s, '}');
        if ($a === false || $b === false || $b <= $a) return null;
        return json_decode(substr($s, $a, $b - $a + 1), true);
    }
}
