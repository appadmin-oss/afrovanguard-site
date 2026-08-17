<?php
/**
 * lib/AvTools.php — the tools the AI can actually use.
 *
 * A model with a good prompt still only knows what you paste into it. These are
 * the capabilities that let it go and find out: look a member up, read a
 * pairing's consistency, check the live rules, search the web, read a page.
 *
 * The design decision that matters is the split between READ and PROPOSE.
 *
 * Read tools return organisational fact. Propose tools do NOT change anything —
 * they file a proposal into `av_proposals` that an administrator approves or
 * rejects in the Studio. So the AI can genuinely improve itself (write a better
 * doctrine entry, suggest a threshold, rewrite one of its own prompts) without
 * ever being able to silently rewrite Afrovanguard's constitution. That is the
 * §23 division of labour applied to the AI's own configuration: it recommends,
 * a human decides.
 *
 * Two privacy rules are enforced here rather than left to the prompt, because a
 * prompt is a request and this is a boundary:
 *
 *   • No tool returns a member's stated reason for missing a commitment or a
 *     meeting. Those are confidential disclosures about illness, money and
 *     family (see the seeded doctrine).
 *   • No tool returns an email address or any other contact detail. The AI can
 *     identify a member and report on their mentorship; it cannot harvest a
 *     directory.
 *
 * Every tool is fail-safe: a broken query returns an error object the model can
 * read and reason about, never an exception into the request.
 */
declare(strict_types=1);

final class AvTools
{
    /** Tiers a caller can grant. 'propose' implies 'read'. */
    const TIERS = ['read', 'web', 'propose'];

    private static bool $ready = false;

    /**
     * The tool registry. `schema` is JSON Schema for the arguments, shared by
     * both providers (Anthropic tool-use and Gemini function-calling take the
     * same shape with different wrappers).
     */
    private const DEFS = [

        /* ── Reading the organisation ─────────────────────────────────── */
        'member_lookup' => [
            'tier'  => 'read',
            'desc'  => 'Find Afrovanguard members by name. Returns their id, level and segment — never an email address or any contact detail.',
            'schema' => [
                'type' => 'object',
                'properties' => [
                    'query' => ['type' => 'string', 'description' => 'A name or part of one.'],
                ],
                'required' => ['query'],
            ],
        ],
        'mentorship_status' => [
            'tier'  => 'read',
            'desc'  => 'A member\'s mentorship picture: pairings, attendance rate, streak, how many mentees are genuinely active, and how many of those are themselves mentoring.',
            'schema' => [
                'type' => 'object',
                'properties' => ['user_id' => ['type' => 'integer', 'description' => 'The member id from member_lookup.']],
                'required' => ['user_id'],
            ],
        ],
        'level_check' => [
            'tier'  => 'read',
            'desc'  => 'Assess a member against the live level criteria. Returns the evidence, the gaps and a verdict — a recommendation for leadership, never a promotion.',
            'schema' => [
                'type' => 'object',
                'properties' => ['user_id' => ['type' => 'integer']],
                'required' => ['user_id'],
            ],
        ],
        'inactive_pairings' => [
            'tier'  => 'read',
            'desc'  => 'Mentorships that have gone quiet — no held session inside the configured inactivity window.',
            'schema' => [
                'type' => 'object',
                'properties' => ['days' => ['type' => 'integer', 'description' => 'Override the configured window. Omit to use the rule.']],
            ],
        ],
        'org_stats' => [
            'tier'  => 'read',
            'desc'  => 'Organisation-wide mentorship counts: mentors, pairings, sessions, inactive pairings.',
            'schema' => ['type' => 'object', 'properties' => []],
        ],

        /* ── Reading its own configuration ────────────────────────────── */
        'rules_read' => [
            'tier'  => 'read',
            'desc'  => 'The live operating rules with their values, defaults and whether each is enforced yet. Read this before suggesting any threshold change.',
            'schema' => [
                'type' => 'object',
                'properties' => ['group' => ['type' => 'string', 'description' => 'Optional group filter, e.g. Mentorship, Levels, Meetings.']],
            ],
        ],
        'knowledge_search' => [
            'tier'  => 'read',
            'desc'  => 'Search the doctrine the assistants are taught. Use this to check whether something is already recorded before proposing to add it.',
            'schema' => [
                'type' => 'object',
                'properties' => ['query' => ['type' => 'string'], 'scope' => ['type' => 'string']],
            ],
        ],
        'prompt_read' => [
            'tier'  => 'read',
            'desc'  => 'Read one of the AI\'s own system prompt templates, including which placeholders it declares.',
            'schema' => [
                'type' => 'object',
                'properties' => ['key' => ['type' => 'string', 'description' => 'e.g. meeting.minutes']],
                'required' => ['key'],
            ],
        ],

        /* ── The web ──────────────────────────────────────────────────── */
        'web_search' => [
            'tier'  => 'web',
            'desc'  => 'Search the web. Returns titles, URLs and snippets. Use it to find current, citable material — then web_fetch the page before relying on it, because a snippet is not a source.',
            'schema' => [
                'type' => 'object',
                'properties' => [
                    'query' => ['type' => 'string'],
                    'count' => ['type' => 'integer', 'description' => 'Results to return, 1-10.'],
                ],
                'required' => ['query'],
            ],
        ],
        'web_fetch' => [
            'tier'  => 'web',
            'desc'  => 'Fetch a web page and return its readable text. Always cite the URL when you use what it says.',
            'schema' => [
                'type' => 'object',
                'properties' => ['url' => ['type' => 'string']],
                'required' => ['url'],
            ],
        ],

        /* ── Proposing changes (never applied directly) ───────────────── */
        'propose_knowledge' => [
            'tier'  => 'propose',
            'desc'  => 'Propose a doctrine entry for the assistants to be taught. Filed for an administrator to approve — it does not take effect when you call this. Say plainly in the rationale where the content came from, and cite a URL if it came from the web.',
            'schema' => [
                'type' => 'object',
                'properties' => [
                    'title'     => ['type' => 'string'],
                    'body'      => ['type' => 'string', 'description' => 'What the AI should know. Write it as fact, not as advice to the reader.'],
                    'scope'     => ['type' => 'string', 'description' => 'all | mentorship | meetings | levels | commitments | assistant'],
                    'priority'  => ['type' => 'integer', 'description' => '0-100; higher is read first.'],
                    'rationale' => ['type' => 'string', 'description' => 'Why this belongs, and its source.'],
                    'id'        => ['type' => 'integer', 'description' => 'Omit to add. Supply to revise an existing entry.'],
                ],
                'required' => ['title', 'body', 'rationale'],
            ],
        ],
        'propose_rule' => [
            'tier'  => 'propose',
            'desc'  => 'Propose a change to an operating rule. Filed for an administrator to approve. Read the rule first with rules_read, and never propose a value you cannot justify from evidence.',
            'schema' => [
                'type' => 'object',
                'properties' => [
                    'key'       => ['type' => 'string'],
                    'value'     => ['type' => 'string'],
                    'rationale' => ['type' => 'string'],
                ],
                'required' => ['key', 'value', 'rationale'],
            ],
        ],
        'propose_prompt' => [
            'tier'  => 'propose',
            'desc'  => 'Propose a rewrite of one of your own system prompt templates. Filed for an administrator to approve. Keep every placeholder the template declares — dropping one silently breaks the feature that uses it.',
            'schema' => [
                'type' => 'object',
                'properties' => [
                    'key'       => ['type' => 'string'],
                    'text'      => ['type' => 'string'],
                    'rationale' => ['type' => 'string'],
                ],
                'required' => ['key', 'text', 'rationale'],
            ],
        ],
    ];

    /* ════════════════════════════════════════════════════════════════
       Storage — the proposal queue
       ════════════════════════════════════════════════════════════════ */

    public static function ensure(): void
    {
        if (self::$ready) return;
        self::$ready = true;
        try {
            Database::execSchema(Database::pdo(), "CREATE TABLE IF NOT EXISTS av_proposals (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                kind VARCHAR(16) NOT NULL DEFAULT '',
                target VARCHAR(191) NOT NULL DEFAULT '',
                payload TEXT NOT NULL DEFAULT '',
                rationale TEXT NOT NULL DEFAULT '',
                status VARCHAR(12) NOT NULL DEFAULT 'pending',
                created_by VARCHAR(191) NOT NULL DEFAULT '',
                created_at VARCHAR(32) NOT NULL DEFAULT '',
                decided_by VARCHAR(191) NOT NULL DEFAULT '',
                decided_at VARCHAR(32) NOT NULL DEFAULT '',
                note TEXT NOT NULL DEFAULT ''
            );
            CREATE INDEX IF NOT EXISTS idx_avprop ON av_proposals(status, id);");
        } catch (Throwable $e) { error_log('[tools] ensure: ' . $e->getMessage()); }
    }

    /* ════════════════════════════════════════════════════════════════
       Registry
       ════════════════════════════════════════════════════════════════ */

    /** Tool names available at the granted tiers. 'propose' implies 'read'. */
    public static function available(array $tiers): array
    {
        $tiers = array_values(array_intersect($tiers, self::TIERS));
        if (in_array('propose', $tiers, true) && !in_array('read', $tiers, true)) $tiers[] = 'read';
        $out = [];
        foreach (self::DEFS as $name => $d) {
            if (!in_array($d['tier'], $tiers, true)) continue;
            // A web tool the deployment cannot actually perform is not offered —
            // a model told it has a capability it lacks will keep retrying it.
            if ($d['tier'] === 'web' && (!class_exists('AvWeb') || !AvWeb::available($name))) continue;
            $out[] = $name;
        }
        return $out;
    }

    /** Provider-neutral specs for the named tools: [{name, description, input_schema}]. */
    public static function specs(array $names): array
    {
        $out = [];
        foreach ($names as $n) {
            if (!isset(self::DEFS[$n])) continue;
            $out[] = [
                'name'         => $n,
                'description'  => self::DEFS[$n]['desc'],
                'input_schema' => self::DEFS[$n]['schema'],
            ];
        }
        return $out;
    }

    public static function defined(string $name): bool { return isset(self::DEFS[$name]); }
    public static function tierOf(string $name): string { return (string) (self::DEFS[$name]['tier'] ?? ''); }

    /** Every tool with its tier and whether it is usable right now — for the Studio. */
    public static function describe(): array
    {
        $out = [];
        foreach (self::DEFS as $name => $d) {
            $usable = true; $why = '';
            if ($d['tier'] === 'web') {
                if (!class_exists('AvWeb')) { $usable = false; $why = 'web layer unavailable'; }
                elseif (!AvWeb::available($name)) { $usable = false; $why = AvWeb::whyUnavailable($name); }
            }
            $out[] = ['name' => $name, 'tier' => $d['tier'], 'desc' => $d['desc'], 'usable' => $usable, 'why' => $why];
        }
        return $out;
    }

    /* ════════════════════════════════════════════════════════════════
       Execution
       ════════════════════════════════════════════════════════════════ */

    /**
     * Run a tool. $ctx carries ['actor'=>string, 'tiers'=>string[]].
     *
     * Always returns an array the model can read. A refusal or a failure is data,
     * not an exception — the model needs to be able to see what went wrong and
     * try something else.
     */
    public static function run(string $name, array $args, array $ctx = []): array
    {
        if (!isset(self::DEFS[$name])) return ['error' => 'No such tool: ' . $name];

        $tiers = (array) ($ctx['tiers'] ?? ['read']);
        if (in_array('propose', $tiers, true) && !in_array('read', $tiers, true)) $tiers[] = 'read';
        if (!in_array(self::DEFS[$name]['tier'], $tiers, true)) {
            return ['error' => 'That tool is not available in this context.'];
        }
        $actor = (string) ($ctx['actor'] ?? 'ai');

        try {
            switch ($name) {
                case 'member_lookup':      return self::memberLookup((string) ($args['query'] ?? ''));
                case 'mentorship_status':  return self::mentorshipStatus((int) ($args['user_id'] ?? 0));
                case 'level_check':        return self::levelCheck((int) ($args['user_id'] ?? 0));
                case 'inactive_pairings':  return self::inactivePairings((int) ($args['days'] ?? 0));
                case 'org_stats':          return self::orgStats();
                case 'rules_read':         return self::rulesRead((string) ($args['group'] ?? ''));
                case 'knowledge_search':   return self::knowledgeSearch((string) ($args['query'] ?? ''), (string) ($args['scope'] ?? ''));
                case 'prompt_read':        return self::promptRead((string) ($args['key'] ?? ''));

                case 'web_search':         return AvWeb::search((string) ($args['query'] ?? ''), (int) ($args['count'] ?? 5));
                case 'web_fetch':          return AvWeb::fetch((string) ($args['url'] ?? ''));

                case 'propose_knowledge':  return self::proposeKnowledge($args, $actor);
                case 'propose_rule':       return self::proposeRule($args, $actor);
                case 'propose_prompt':     return self::proposePrompt($args, $actor);
            }
        } catch (Throwable $e) {
            error_log('[tools] ' . $name . ': ' . $e->getMessage());
            return ['error' => 'The tool failed: ' . $e->getMessage()];
        }
        return ['error' => 'Unhandled tool: ' . $name];
    }

    /* ── read implementations ─────────────────────────────────────── */

    private static function memberLookup(string $q): array
    {
        $q = trim($q);
        if ($q === '') return ['error' => 'Give a name to search for.'];
        if (!class_exists('Mentorship')) return ['error' => 'Member data unavailable.'];
        $rows = Mentorship::findUsers($q, '', 10);
        $out = [];
        foreach ($rows as $r) {
            $uid = (int) ($r['id'] ?? 0);
            if ($uid <= 0) continue;
            // Name, id, level, segment — deliberately no email. The AI can
            // identify and report on a member; it cannot harvest a directory.
            $out[] = [
                'user_id' => $uid,
                'name'    => (string) ($r['name'] ?? ''),
                'level'   => class_exists('Levels') ? Levels::of($uid) : '',
                'segment' => (string) ($r['segment'] ?? ''),
            ];
        }
        return ['members' => $out, 'count' => count($out)];
    }

    private static function mentorshipStatus(int $uid): array
    {
        if ($uid <= 0) return ['error' => 'Give a user_id (use member_lookup first).'];
        if (!class_exists('Mentorship')) return ['error' => 'Mentorship data unavailable.'];
        $m = Mentorship::multiplicationSummary($uid);
        $c = Mentorship::memberConsistency($uid, false);
        return [
            'user_id'            => $uid,
            'active_mentees'     => (int) ($m['active'] ?? 0),
            'multiplying'        => (int) ($m['multiplying'] ?? 0),
            'descendants'        => (int) ($m['descendants'] ?? 0),
            'network_depth'      => (int) ($m['depth'] ?? 0),
            'sessions_held'      => (int) ($c['held'] ?? 0),
            'sessions_attended'  => (int) ($c['attended'] ?? 0),
            'attendance_rate_pct'=> (int) ($c['rate'] ?? 0),
            'streak'             => (int) ($c['streak'] ?? 0),
            'note'               => 'active_mentees counts only pairings that have really met, per the operating rules. Registered-but-dormant pairings are excluded by design.',
        ];
    }

    private static function levelCheck(int $uid): array
    {
        if ($uid <= 0) return ['error' => 'Give a user_id (use member_lookup first).'];
        if (!class_exists('Levels')) return ['error' => 'Level data unavailable.'];
        $r = Levels::recommend($uid);
        $r['reminder'] = 'This is a recommendation for leadership. Never state that a member has been promoted.';
        return $r;
    }

    private static function inactivePairings(int $days): array
    {
        if (!class_exists('Mentorship')) return ['error' => 'Mentorship data unavailable.'];
        $rows = Mentorship::inactivePairs($days > 0 ? $days : 0);
        $out = [];
        foreach (array_slice($rows, 0, 40) as $r) {
            $out[] = [
                'pairing_id'   => (int) ($r['id'] ?? 0),
                'mentor'       => (string) ($r['mentor'] ?? ''),
                'mentee'       => (string) ($r['mentee'] ?? ''),
                'last_session' => (string) ($r['last_session'] ?? ''),
            ];
        }
        return ['pairings' => $out, 'count' => count($rows), 'shown' => count($out)];
    }

    private static function orgStats(): array
    {
        if (!class_exists('Mentorship')) return ['error' => 'Mentorship data unavailable.'];
        return ['stats' => Mentorship::adminStats()];
    }

    private static function rulesRead(string $group): array
    {
        if (!class_exists('AvRules')) return ['error' => 'Rules unavailable.'];
        $d = AvRules::describe();
        $out = [];
        foreach (($d['groups'] ?? []) as $g => $rules) {
            if ($group !== '' && strcasecmp($g, $group) !== 0) continue;
            foreach ($rules as $r) {
                $out[] = [
                    'key'     => $r['key'],
                    'group'   => $g,
                    'label'   => $r['label'],
                    'value'   => is_array($r['value']) ? implode(',', $r['value']) : (string) $r['value'],
                    'default' => is_array($r['default']) ? implode(',', $r['default']) : (string) $r['default'],
                    'source'  => $r['source'],
                    // A rule nothing reads yet: say so, or the model will reason
                    // about a threshold that changes nothing.
                    'enforced'=> $r['pending'] === '',
                    'awaiting'=> $r['pending'],
                ];
            }
        }
        return ['rules' => $out, 'conflicts' => $d['conflicts'] ?? []];
    }

    private static function knowledgeSearch(string $q, string $scope): array
    {
        if (!class_exists('AvKnowledge')) return ['error' => 'Knowledge base unavailable.'];
        $q = mb_strtolower(trim($q));
        $rows = AvKnowledge::listAll($scope);
        $out = [];
        foreach ($rows as $r) {
            if ($q !== '' && strpos(mb_strtolower($r['title'] . ' ' . $r['body']), $q) === false) continue;
            $out[] = [
                'id' => $r['id'], 'title' => $r['title'], 'scope' => $r['scope'],
                'priority' => $r['priority'], 'active' => $r['active'],
                'body' => mb_substr($r['body'], 0, 600),
            ];
            if (count($out) >= 15) break;
        }
        return ['entries' => $out, 'count' => count($out)];
    }

    private static function promptRead(string $key): array
    {
        if (!class_exists('AvPrompts')) return ['error' => 'Prompts unavailable.'];
        if (!AvPrompts::isKey($key)) {
            return ['error' => 'No such prompt.', 'available' => AvPrompts::keys()];
        }
        return AvPrompts::describeOne($key);
    }

    /* ── propose implementations ──────────────────────────────────── */

    /**
     * File a proposal. Validated NOW so the model learns immediately that a value
     * is out of range or a placeholder is unknown, rather than an administrator
     * discovering it at approval time.
     */
    private static function file(string $kind, string $target, array $payload, string $rationale, string $actor): array
    {
        self::ensure();
        $rationale = trim($rationale);
        if ($rationale === '') return ['error' => 'Give a rationale — a proposal without one cannot be judged.'];
        try {
            Database::pdo()->prepare(
                'INSERT INTO av_proposals (kind, target, payload, rationale, status, created_by, created_at) VALUES (?,?,?,?,\'pending\',?,?)'
            )->execute([$kind, mb_substr($target, 0, 191), json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        mb_substr($rationale, 0, 2000), mb_substr($actor, 0, 191), gmdate('c')]);
            $id = (int) Database::pdo()->lastInsertId();
            if (class_exists('Events')) { try { Events::emit('ai.proposal_filed', ['id' => $id, 'kind' => $kind]); } catch (Throwable $e) {} }
            return [
                'filed' => true, 'proposal_id' => $id, 'status' => 'pending',
                'note'  => 'Filed for an administrator to approve. This has NOT taken effect — do not tell anyone the change is live.',
            ];
        } catch (Throwable $e) {
            error_log('[tools] file: ' . $e->getMessage());
            return ['error' => 'Could not file the proposal.'];
        }
    }

    private static function proposeKnowledge(array $a, string $actor): array
    {
        $title = trim((string) ($a['title'] ?? ''));
        $body  = trim((string) ($a['body'] ?? ''));
        if ($title === '' || $body === '') return ['error' => 'A doctrine entry needs both a title and a body.'];
        if (mb_strlen($body) > 6000) return ['error' => 'Body too long (6000 characters maximum).'];
        $scope = (string) ($a['scope'] ?? 'all');
        if (class_exists('AvKnowledge') && !in_array($scope, AvKnowledge::SCOPES, true)) {
            return ['error' => 'Unknown scope. Use one of: ' . implode(', ', AvKnowledge::SCOPES) . '.'];
        }
        return self::file('knowledge', (string) (int) ($a['id'] ?? 0), [
            'id' => (int) ($a['id'] ?? 0), 'title' => $title, 'body' => $body,
            'scope' => $scope, 'priority' => max(0, min(100, (int) ($a['priority'] ?? 50))),
        ], (string) ($a['rationale'] ?? ''), $actor);
    }

    private static function proposeRule(array $a, string $actor): array
    {
        $key = trim((string) ($a['key'] ?? ''));
        $val = (string) ($a['value'] ?? '');
        if (!class_exists('AvRules')) return ['error' => 'Rules unavailable.'];
        if (AvRules::cast($key, $val) === null) {
            // Tell the model exactly what would be acceptable, so its next attempt
            // is informed rather than another guess.
            return ['error' => 'That value is not valid for ' . $key . '. Expected: ' . AvRules::expected($key)];
        }
        return self::file('rule', $key, ['key' => $key, 'value' => $val], (string) ($a['rationale'] ?? ''), $actor);
    }

    private static function proposePrompt(array $a, string $actor): array
    {
        $key = trim((string) ($a['key'] ?? ''));
        $txt = (string) ($a['text'] ?? '');
        if (!class_exists('AvPrompts')) return ['error' => 'Prompts unavailable.'];
        if (!AvPrompts::isKey($key)) return ['error' => 'No such prompt.', 'available' => AvPrompts::keys()];
        $chk = AvPrompts::validate($key, $txt);
        if (empty($chk['ok'])) return ['error' => (string) ($chk['error'] ?? 'That template is not valid.')];
        return self::file('prompt', $key, ['key' => $key, 'text' => $txt], (string) ($a['rationale'] ?? ''), $actor);
    }

    /* ════════════════════════════════════════════════════════════════
       The approval gate
       ════════════════════════════════════════════════════════════════ */

    /** Pending proposals (newest first), or all when $status is ''. */
    public static function proposals(string $status = 'pending', int $limit = 60): array
    {
        self::ensure();
        try {
            $sql = 'SELECT * FROM av_proposals';
            $args = [];
            if ($status !== '' && $status !== 'all') { $sql .= ' WHERE status = ?'; $args[] = $status; }
            $sql .= ' ORDER BY id DESC LIMIT ' . max(1, min(200, $limit));
            $st = Database::pdo()->prepare($sql);
            $st->execute($args);
            return array_map(static fn(array $r) => [
                'id'         => (int) $r['id'],
                'kind'       => (string) $r['kind'],
                'target'     => (string) $r['target'],
                'payload'    => json_decode((string) $r['payload'], true) ?: [],
                'rationale'  => (string) $r['rationale'],
                'status'     => (string) $r['status'],
                'created_by' => (string) $r['created_by'],
                'created_at' => (string) $r['created_at'],
                'decided_by' => (string) $r['decided_by'],
                'decided_at' => (string) $r['decided_at'],
                'note'       => (string) $r['note'],
            ], $st->fetchAll(PDO::FETCH_ASSOC) ?: []);
        } catch (Throwable $e) { error_log('[tools] proposals: ' . $e->getMessage()); return []; }
    }

    public static function pendingCount(): int
    {
        self::ensure();
        try { return (int) Database::pdo()->query("SELECT COUNT(*) FROM av_proposals WHERE status='pending'")->fetchColumn(); }
        catch (Throwable $e) { return 0; }
    }

    /**
     * Apply a pending proposal. This is the ONLY path by which the AI's own
     * suggestions reach the live configuration, and it is always driven by an
     * administrator — never by the model.
     */
    public static function approve(int $id, string $actor): array
    {
        self::ensure();
        $p = null;
        foreach (self::proposals('all', 200) as $row) if ($row['id'] === $id) { $p = $row; break; }
        if (!$p) return ['ok' => false, 'error' => 'Proposal not found.'];
        if ($p['status'] !== 'pending') return ['ok' => false, 'error' => 'Already ' . $p['status'] . '.'];

        $pl = (array) $p['payload'];
        $res = ['ok' => false, 'error' => 'Unknown proposal kind.'];

        switch ($p['kind']) {
            case 'rule':
                $r = AvRules::save([(string) ($pl['key'] ?? '') => (string) ($pl['value'] ?? '')], $actor);
                $res = !empty($r['ok'])
                    ? ['ok' => true]
                    : ['ok' => false, 'error' => $r['conflicts']
                        ? ('Would create a conflict: ' . implode(' ', $r['conflicts']))
                        : implode(' ', array_map(fn($k, $v) => $k . ': ' . $v, array_keys($r['errors'] ?? []), $r['errors'] ?? []))];
                break;
            case 'knowledge':
                $r = AvKnowledge::save((int) ($pl['id'] ?? 0), $pl, $actor);
                $res = !empty($r['ok']) ? ['ok' => true] : ['ok' => false, 'error' => (string) ($r['error'] ?? 'Could not save.')];
                break;
            case 'prompt':
                $r = AvPrompts::save((string) ($pl['key'] ?? ''), (string) ($pl['text'] ?? ''), $actor);
                $res = !empty($r['ok']) ? ['ok' => true] : ['ok' => false, 'error' => (string) ($r['error'] ?? 'Could not save.')];
                break;
        }

        if (empty($res['ok'])) return $res;
        try {
            Database::pdo()->prepare("UPDATE av_proposals SET status='applied', decided_by=?, decided_at=? WHERE id=?")
                ->execute([mb_substr($actor, 0, 191), gmdate('c'), $id]);
        } catch (Throwable $e) { error_log('[tools] approve: ' . $e->getMessage()); }
        return ['ok' => true, 'kind' => $p['kind'], 'target' => $p['target']];
    }

    public static function reject(int $id, string $actor, string $note = ''): bool
    {
        self::ensure();
        try {
            Database::pdo()->prepare("UPDATE av_proposals SET status='rejected', decided_by=?, decided_at=?, note=? WHERE id=? AND status='pending'")
                ->execute([mb_substr($actor, 0, 191), gmdate('c'), mb_substr($note, 0, 2000), $id]);
            return true;
        } catch (Throwable $e) { error_log('[tools] reject: ' . $e->getMessage()); return false; }
    }
}
