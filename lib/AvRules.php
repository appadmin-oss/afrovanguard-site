<?php
/**
 * lib/AvRules.php — Afrovanguard's operating rules, as editable data.
 *
 * The concept report is explicit (§27): "AI should not invent Afrovanguard's
 * constitution. Afrovanguard defines the rules. AI enforces and monitors them."
 *
 * So the thresholds that drive mentorship cadence, relationship health, the
 * escalation ladder, level advancement and the AI's own guard-rails do not live
 * in PHP constants where only a developer can reach them. They live here: a
 * typed REGISTRY of every rule the system honours, each with a documented
 * default, and a database table that leadership can edit from Studio without a
 * deploy.
 *
 * Resolution order for every rule (first non-empty wins):
 *
 *   1. the `av_rules` table          — leadership's edit, made in Studio
 *   2. the rule's `env` key          — Config::get(), i.e. config.php or SetEnv
 *   3. the coded default             — so a fresh install is fully operational
 *
 * That order matters: a deployment can pin a value via env, but leadership can
 * always override it at runtime, and a database that has never been touched
 * behaves exactly like the documented defaults.
 *
 * Nothing here throws into a request. A missing table, an unreadable row or a
 * malformed value falls back to the coded default and logs.
 */
declare(strict_types=1);

final class AvRules
{
    /** Rule types the registry understands. */
    private const TYPES = ['int', 'bool', 'str', 'text', 'enum', 'list'];

    /**
     * Every rule the system honours.
     *
     *   type    int | bool | str | text | enum | list
     *   def     the coded default (a fresh install runs on these)
     *   group   Studio grouping
     *   label   human name
     *   help    why this exists / what moves if you change it
     *   env     optional Config/env key that can pin the value below the DB
     *   min/max int bounds (inclusive)
     *   options enum members
     */
    public const REGISTRY = [

        /* ── Mentorship cadence (report §12–13) ───────────────────────── */
        'mentorship.cadence_days' => [
            'type' => 'int', 'def' => 7, 'min' => 1, 'max' => 90,
            'group' => 'Mentorship', 'label' => 'Expected meeting cadence (days)',
            'help'  => 'How often a mentor and mentee are expected to meet. "Weekly" is the default, but the report is clear this must be configurable, not assumed.',
        ],
        'mentorship.reminder_lead_hours' => [
            'type' => 'int', 'def' => 24, 'min' => 1, 'max' => 336,
            'group' => 'Mentorship', 'label' => 'Reminder lead time (hours)',
            'help'  => 'How far ahead of a scheduled session the reminder goes out.',
        ],
        'mentorship.inactive_days' => [
            'type' => 'int', 'def' => 21, 'min' => 3, 'max' => 365,
            'group' => 'Mentorship', 'label' => 'Inactive after (days)',
            'help'  => 'A pairing with no held session for this long is reported as inactive.',
        ],
        'mentorship.min_session_minutes' => [
            'type' => 'int', 'def' => 15, 'min' => 1, 'max' => 480,
            'group' => 'Mentorship', 'label' => 'Minimum session length (minutes)',
            'help'  => 'Shorter than this and a session is not counted as held.',
        ],

        /* ── Relationship health (report §15) ─────────────────────────── */
        'health.green_min_rate' => [
            'type' => 'int', 'def' => 80, 'min' => 0, 'max' => 100,
            'group' => 'Health', 'label' => 'Green: minimum attendance rate (%)',
            'help'  => 'At or above this attendance rate, and meeting recently, a relationship is Green.',
        ],
        'health.amber_min_rate' => [
            'type' => 'int', 'def' => 50, 'min' => 0, 'max' => 100,
            'group' => 'Health', 'label' => 'Amber: minimum attendance rate (%)',
            'help'  => 'Below this rate a relationship is Red. Must be lower than the Green threshold.',
        ],
        'health.amber_after_days' => [
            'type' => 'int', 'def' => 14, 'min' => 1, 'max' => 365,
            'group' => 'Health', 'label' => 'Amber after silence (days)',
            'help'  => 'No held session for this long moves a relationship to Amber regardless of its historic rate.',
        ],
        'health.red_after_days' => [
            // Matches the inactive threshold by default: "Red" and "inactive"
            // describe the same silence, and should not disagree out of the box.
            'type' => 'int', 'def' => 21, 'min' => 1, 'max' => 365,
            'group' => 'Health', 'label' => 'Red after silence (days)',
            'help'  => 'No held session for this long moves a relationship to Red. Must exceed the Amber window.',
        ],

        /* ── Escalation ladder (report §14) ───────────────────────────── */
        'escalation.miss_1' => [
            'type' => 'enum', 'def' => 'nudge_both',
            'options' => ['none', 'nudge_mentee', 'nudge_mentor', 'nudge_both'],
            'group' => 'Escalation', 'label' => 'First miss',
            'help'  => 'What happens the first time a session is missed. The report is emphatic: early intervention, not punishment.',
        ],
        'escalation.miss_2' => [
            'type' => 'enum', 'def' => 'nudge_both',
            'options' => ['none', 'nudge_mentee', 'nudge_mentor', 'nudge_both', 'notify_grandmentor'],
            'group' => 'Escalation', 'label' => 'Second miss',
            'help'  => 'What happens on a second consecutive miss.',
        ],
        'escalation.miss_3' => [
            'type' => 'enum', 'def' => 'notify_grandmentor',
            'options' => ['none', 'nudge_both', 'notify_grandmentor', 'notify_admin', 'notify_all'],
            'group' => 'Escalation', 'label' => 'Third and further misses',
            'help'  => 'Who is told when a pattern has formed. "Grandmentor" is the mentor\'s own mentor.',
        ],
        'escalation.cooldown_days' => [
            'type' => 'int', 'def' => 3, 'min' => 0, 'max' => 90,
            'group' => 'Escalation', 'label' => 'Cooldown between escalations (days)',
            'help'  => 'The same relationship will not be escalated again inside this window, so a daily sweep cannot nag.',
        ],
        'escalation.tone' => [
            'type' => 'text',
            'def'  => 'Warm, direct and non-punitive. The purpose is early support, never discipline. Address the person by name, state plainly what was missed, ask whether something is in the way, and offer the next step. Never imply judgement of character.',
            'group' => 'Escalation', 'label' => 'Tone of escalation messages',
            'help'  => 'Fed verbatim to the AI whenever it drafts a nudge. Changing this changes how every reminder reads.',
        ],

        /* ── Level ladder (report §4, §17, §20) ───────────────────────── */
        'levels.order' => [
            'type' => 'list', 'def' => 'O,A,B,C,D,E,F,G',
            'group' => 'Levels', 'label' => 'Ladder',
            'help'  => 'The progression, lowest first. The report describes O through G, each level a doubling of the multiplication tree.',
        ],
        'levels.active_window_days' => [
            'type' => 'int', 'def' => 60, 'min' => 7, 'max' => 730,
            'group' => 'Levels', 'label' => 'Active-mentee window (days)',
            'help'  => 'The look-back period used to decide whether a mentee counts as active.',
        ],
        'levels.active_min_sessions' => [
            'type' => 'int', 'def' => 2, 'min' => 1, 'max' => 52,
            'group' => 'Levels', 'label' => 'Sessions needed to count as active',
            'help'  => 'Held sessions inside the window before a mentee counts. This is what stops a name on a list from earning advancement (§4A).',
        ],
        'levels.mentees_for_a' => [
            'type' => 'int', 'def' => 2, 'min' => 1, 'max' => 20,
            'group' => 'Levels', 'label' => 'Active mentees for Level A',
            'help'  => 'How many ACTIVE mentees Level A requires. Deliberately not a referral count — the report names rewarding headcount as an anti-pattern (§17).',
        ],
        'levels.require_multiplication' => [
            'type' => 'bool', 'def' => true,
            'group' => 'Levels', 'label' => 'Levels above A require multiplication',
            'help'  => 'When on, advancing past A needs mentees who are themselves mentoring — measuring multiplication, not recruitment.',
        ],
        'levels.min_tenure_days' => [
            'type' => 'int', 'def' => 90, 'min' => 0, 'max' => 3650,
            'group' => 'Levels', 'label' => 'Minimum days at a level',
            'help'  => 'How long a member must hold a level before the next one can be recommended.',
        ],
        'levels.auto_promote' => [
            'type' => 'bool', 'def' => false,
            'group' => 'Levels', 'label' => 'Promote automatically',
            'help'  => 'Leave off. The report is explicit (§20): promotion is automated as a RECOMMENDATION, never blindly automated. Turning this on removes the human decision.',
        ],

        /* ── Commitments (report §11, §39.2) ──────────────────────────── */
        'commitments.default_due_days' => [
            'type' => 'int', 'def' => 7, 'min' => 1, 'max' => 365,
            'group' => 'Commitments', 'label' => 'Default deadline (days)',
            'help'  => 'Used when a meeting action item carries no explicit date.',
        ],
        'commitments.reminder_days_before' => [
            'type' => 'int', 'def' => 1, 'min' => 0, 'max' => 30,
            'group' => 'Commitments', 'label' => 'Remind this many days before due',
            'help'  => 'The nudge before a commitment falls due.',
        ],
        'commitments.overdue_grace_days' => [
            'type' => 'int', 'def' => 2, 'min' => 0, 'max' => 60,
            'group' => 'Commitments', 'label' => 'Grace before counted missed (days)',
            'help'  => 'How long past its deadline a commitment stays merely overdue rather than missed.',
        ],
        'commitments.require_owner_confirm' => [
            'type' => 'bool', 'def' => true,
            'group' => 'Commitments', 'label' => 'Confirm owner before assigning',
            'help'  => 'When on, an AI-proposed owner is suggested for a human to approve rather than assigned silently. An AI quietly assigning work to the wrong person is worse than no automation.',
        ],
        'commitments.max_per_meeting' => [
            'type' => 'int', 'def' => 12, 'min' => 1, 'max' => 50,
            'group' => 'Commitments', 'label' => 'Maximum commitments per meeting',
            'help'  => 'A ceiling on how many action items one transcript can produce.',
        ],

        /* ── Meetings (report §7, §10) ────────────────────────────────── */
        'meetings.duration_warnings' => [
            'type' => 'list', 'def' => '20,10,5',
            'group' => 'Meetings', 'label' => 'Duration warnings (minutes remaining)',
            'help'  => 'When to warn that a meeting is running out of time.',
        ],
        'meetings.transcript_char_limit' => [
            'type' => 'int', 'def' => 20000, 'min' => 1000, 'max' => 200000,
            'group' => 'Meetings', 'label' => 'Transcript characters sent to AI',
            'help'  => 'How much of a raw transcript is passed for summarising. Higher costs more per meeting.',
        ],
        'meetings.agenda_needs_approval' => [
            'type' => 'bool', 'def' => true,
            'group' => 'Meetings', 'label' => 'AI agendas need chair approval',
            'help'  => 'A machine-drafted agenda is proposed to the chair rather than published directly.',
        ],

        /* ── AI behaviour (report §23, §3A) ───────────────────────────── */
        'ai.persona' => [
            'type' => 'text',
            'def'  => 'You are the Afrovanguard accountability assistant. Afrovanguard is a Pan-African movement raising an incorruptible generation through mentorship, service and leadership multiplication.',
            'group' => 'AI', 'label' => 'Assistant persona',
            'help'  => 'The opening description every accountability prompt is built on.',
        ],
        'ai.boundaries' => [
            'type' => 'text',
            'def'  => 'You observe, record, analyse, remind and recommend. You do NOT interpret motives, counsel, decide, discipline or promote — those belong to human leadership. Never invent a fact about a real person. When the evidence is thin, say so plainly rather than filling the gap.',
            'group' => 'AI', 'label' => 'Boundaries',
            'help'  => 'The division of labour from §23, fed into every accountability prompt. This is the single most important guard-rail here.',
        ],
        'ai.max_tokens' => [
            'type' => 'int', 'def' => 2048, 'min' => 256, 'max' => 8192,
            'group' => 'AI', 'label' => 'Maximum response tokens',
            'help'  => 'Upper bound on a single AI response.',
        ],
        'ai.temperature_pct' => [
            'type' => 'int', 'def' => 10, 'min' => 0, 'max' => 100,
            'group' => 'AI', 'label' => 'Creativity (%)',
            'help'  => 'Low values keep summaries faithful to the source. 10% suits minutes and analysis.',
        ],
        'ai.knowledge_char_limit' => [
            'type' => 'int', 'def' => 3200, 'min' => 500, 'max' => 20000,
            'group' => 'AI', 'label' => 'Site-facts block size (characters)',
            'help'  => 'Hard ceiling on the auto-compiled facts block (programmes, articles, events) appended to a system prompt, so it can never balloon.',
        ],
        'ai.doctrine_char_limit' => [
            'type' => 'int', 'def' => 6000, 'min' => 500, 'max' => 20000,
            'group' => 'AI', 'label' => 'Doctrine block size (characters)',
            'help'  => 'Hard ceiling on the written doctrine block. Raise this if you add entries and find the later ones being cut off.',
        ],
        'ai.allow_character_score' => [
            'type' => 'bool', 'def' => false,
            'group' => 'AI', 'label' => 'Allow a numeric character score',
            'help'  => 'Leave off. The report (§3A) warns that character must never be reduced to a number — collect behavioural evidence and show leaders patterns instead. Turning this on undermines the premise of the whole system.',
        ],
    ];

    private static bool $ensured = false;
    /** Per-request memo of the DB overrides: key => raw string. */
    private static ?array $overrides = null;

    private static function db(): PDO { return Database::pdo(); }

    /** Idempotently provision the rules table. Portable across SQLite/MySQL/Postgres. */
    public static function ensure(): void
    {
        if (self::$ensured) return;
        self::$ensured = true;
        try {
            Database::execSchema(self::db(), "
                CREATE TABLE IF NOT EXISTS av_rules (
                    rule_key TEXT PRIMARY KEY,
                    value TEXT NOT NULL DEFAULT '',
                    updated_by TEXT NOT NULL DEFAULT '',
                    updated_at TEXT NOT NULL DEFAULT (datetime('now'))
                );
            ");
        } catch (\Throwable $e) { error_log('[rules] ensure: ' . $e->getMessage()); }
    }

    /** Drop the per-request memo so the next read sees fresh values. */
    public static function flush(): void
    {
        self::$overrides = null;
        if (class_exists('AiKnowledge')) { try { AiKnowledge::invalidate(); } catch (\Throwable $e) {} }
    }

    /** All DB overrides, loaded once per request. */
    private static function overrides(): array
    {
        if (self::$overrides !== null) return self::$overrides;
        self::$overrides = [];
        self::ensure();
        try {
            foreach (self::db()->query('SELECT rule_key, value FROM av_rules') as $r) {
                self::$overrides[(string) $r['rule_key']] = (string) $r['value'];
            }
        } catch (\Throwable $e) { error_log('[rules] load: ' . $e->getMessage()); }
        return self::$overrides;
    }

    public static function defined(string $key): bool { return isset(self::REGISTRY[$key]); }

    /** The coded default for a rule, already cast to its type. */
    public static function defaultOf(string $key)
    {
        $spec = self::REGISTRY[$key] ?? null;
        return $spec ? self::cast((string) $spec['def'], $spec) : null;
    }

    /**
     * Resolve a rule: DB override → env/config pin → coded default.
     * Unknown keys return $fallback so a caller can never be surprised by null.
     */
    public static function get(string $key, $fallback = null)
    {
        $spec = self::REGISTRY[$key] ?? null;
        if (!$spec) return $fallback;

        $ov = self::overrides();
        if (isset($ov[$key]) && $ov[$key] !== '') {
            $v = self::validate($key, $ov[$key]);
            if ($v['ok']) return $v['value'];
            error_log('[rules] stored value for ' . $key . ' is invalid; using default');
        }
        if (!empty($spec['env']) && class_exists('Config')) {
            $env = Config::get((string) $spec['env'], null);
            if ($env !== null && $env !== '') {
                $v = self::validate($key, (string) $env);
                if ($v['ok']) return $v['value'];
            }
        }
        return self::cast((string) $spec['def'], $spec);
    }

    public static function int(string $key, int $d = 0): int { $v = self::get($key, $d); return is_int($v) ? $v : (int) $v; }
    public static function bool(string $key, bool $d = false): bool { $v = self::get($key, $d); return is_bool($v) ? $v : (bool) $v; }
    public static function str(string $key, string $d = ''): string { $v = self::get($key, $d); return is_array($v) ? implode(',', $v) : (string) $v; }
    /** A list rule as an array of trimmed, non-empty strings. */
    public static function arr(string $key): array { $v = self::get($key, []); return is_array($v) ? $v : array_values(array_filter(array_map('trim', explode(',', (string) $v)), 'strlen')); }

    /** Cast a raw string to the rule's declared type. */
    private static function cast(string $raw, array $spec)
    {
        switch ($spec['type']) {
            case 'int':  return (int) $raw;
            case 'bool': return in_array(strtolower(trim($raw)), ['1', 'true', 'yes', 'on'], true);
            case 'list': return array_values(array_filter(array_map('trim', explode(',', $raw)), 'strlen'));
            default:     return $raw;
        }
    }

    /**
     * Type-check and bound-check a proposed value.
     * Returns ['ok'=>true,'value'=>mixed] or ['ok'=>false,'error'=>string].
     */
    public static function validate(string $key, string $raw): array
    {
        $spec = self::REGISTRY[$key] ?? null;
        if (!$spec) return ['ok' => false, 'error' => 'Unknown rule.'];
        $raw = trim($raw);

        switch ($spec['type']) {
            case 'int':
                if ($raw === '' || !preg_match('/^-?\d+$/', $raw)) return ['ok' => false, 'error' => 'Must be a whole number.'];
                $n = (int) $raw;
                if (isset($spec['min']) && $n < $spec['min']) return ['ok' => false, 'error' => 'Must be at least ' . $spec['min'] . '.'];
                if (isset($spec['max']) && $n > $spec['max']) return ['ok' => false, 'error' => 'Must be at most ' . $spec['max'] . '.'];
                return ['ok' => true, 'value' => $n];

            case 'bool':
                if (!in_array(strtolower($raw), ['1', '0', 'true', 'false', 'yes', 'no', 'on', 'off'], true)) {
                    return ['ok' => false, 'error' => 'Must be true or false.'];
                }
                return ['ok' => true, 'value' => in_array(strtolower($raw), ['1', 'true', 'yes', 'on'], true)];

            case 'enum':
                $opts = (array) ($spec['options'] ?? []);
                if (!in_array($raw, $opts, true)) return ['ok' => false, 'error' => 'Must be one of: ' . implode(', ', $opts) . '.'];
                return ['ok' => true, 'value' => $raw];

            case 'list':
                $items = array_values(array_filter(array_map('trim', explode(',', $raw)), 'strlen'));
                if (!$items) return ['ok' => false, 'error' => 'Give at least one value, comma-separated.'];
                if (count($items) !== count(array_unique($items))) return ['ok' => false, 'error' => 'Values must be unique.'];
                return ['ok' => true, 'value' => $items];

            case 'text':
                if (mb_strlen($raw) > 4000) return ['ok' => false, 'error' => 'Too long (4000 characters maximum).'];
                return ['ok' => true, 'value' => $raw];

            default: // str
                if (mb_strlen($raw) > 300) return ['ok' => false, 'error' => 'Too long (300 characters maximum).'];
                return ['ok' => true, 'value' => $raw];
        }
    }

    /**
     * Cross-rule sanity checks. A rule can be individually valid and still make
     * no sense next to its neighbour (Amber above Green, Red inside the Amber
     * window). Returns a list of human-readable warnings — never blocks a save,
     * because leadership may be mid-way through a deliberate change.
     */
    public static function conflicts(): array
    {
        $out = [];
        $green = self::int('health.green_min_rate');
        $amber = self::int('health.amber_min_rate');
        if ($amber >= $green) {
            $out[] = 'Health: the Amber rate (' . $amber . '%) is not below the Green rate (' . $green . '%), so no relationship can ever be Amber.';
        }
        $aDays = self::int('health.amber_after_days');
        $rDays = self::int('health.red_after_days');
        if ($rDays <= $aDays) {
            $out[] = 'Health: Red after ' . $rDays . ' days is not later than Amber after ' . $aDays . ' days, so Amber is unreachable.';
        }
        $cad = self::int('mentorship.cadence_days');
        if ($aDays < $cad) {
            $out[] = 'Health: a relationship turns Amber after ' . $aDays . ' days but is only expected to meet every ' . $cad . ' days — pairings will go Amber while still on schedule.';
        }
        if (self::int('mentorship.inactive_days') < $rDays) {
            $out[] = 'Mentorship: the inactive threshold is shorter than the Red window, so pairings are reported inactive before they are ever marked Red.';
        }
        $order = self::arr('levels.order');
        if (count($order) < 2) {
            $out[] = 'Levels: the ladder needs at least two levels.';
        } elseif ($order[0] !== 'O') {
            $out[] = 'Levels: the ladder should start at O (the entry level); it currently starts at ' . $order[0] . '.';
        }
        if (self::bool('levels.auto_promote')) {
            $out[] = 'Levels: automatic promotion is ON. The concept report asks for promotion to be recommended to leadership, not applied automatically (§20).';
        }
        if (self::bool('ai.allow_character_score')) {
            $out[] = 'AI: numeric character scoring is ON. The report warns character must never be reduced to a single number (§3A).';
        }
        if (self::int('commitments.reminder_days_before') > self::int('commitments.default_due_days')) {
            $out[] = 'Commitments: the reminder fires further ahead than the default deadline, so it would be due before it is set.';
        }
        return $out;
    }

    /**
     * Set (or clear) a rule. An empty string deletes the override, returning the
     * rule to its env/coded default. Returns ['ok'=>bool, 'value'|'error'].
     */
    public static function set(string $key, string $raw, string $actor = 'admin'): array
    {
        if (!self::defined($key)) return ['ok' => false, 'error' => 'Unknown rule.'];
        self::ensure();

        if (trim($raw) === '') {
            try {
                self::db()->prepare('DELETE FROM av_rules WHERE rule_key = ?')->execute([$key]);
                self::flush();
                return ['ok' => true, 'value' => self::get($key), 'cleared' => true];
            } catch (\Throwable $e) {
                error_log('[rules] clear: ' . $e->getMessage());
                return ['ok' => false, 'error' => 'Could not reset that rule.'];
            }
        }

        $v = self::validate($key, $raw);
        if (!$v['ok']) return $v;

        $store = is_array($v['value']) ? implode(',', $v['value'])
               : (is_bool($v['value']) ? ($v['value'] ? '1' : '0') : (string) $v['value']);
        try {
            $db = self::db();
            $db->prepare('DELETE FROM av_rules WHERE rule_key = ?')->execute([$key]);
            $db->prepare('INSERT INTO av_rules (rule_key, value, updated_by, updated_at) VALUES (?,?,?,' . Database::nowExpr() . ')')
               ->execute([$key, $store, mb_substr($actor, 0, 120)]);
            self::flush();
            if (class_exists('Events')) Events::emit('rules.changed', ['key' => $key, 'value' => $store]);
            return ['ok' => true, 'value' => self::get($key)];
        } catch (\Throwable $e) {
            error_log('[rules] set: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'Could not save that rule.'];
        }
    }

    /** The raw stored override for a key ('' when none), for undo bookkeeping. */
    public static function rawOverride(string $key): string
    {
        return (string) (self::overrides()[$key] ?? '');
    }

    /**
     * Every rule, grouped for Studio: current value, default, whether it is
     * overridden and where the live value came from.
     */
    public static function all(): array
    {
        $ov = self::overrides();
        $groups = [];
        foreach (self::REGISTRY as $key => $spec) {
            $value    = self::get($key);
            $default  = self::cast((string) $spec['def'], $spec);
            $isOv     = isset($ov[$key]) && $ov[$key] !== '';
            $envPinned = !$isOv && !empty($spec['env']) && class_exists('Config')
                         && ($e = Config::get((string) $spec['env'], null)) !== null && $e !== '';

            $groups[$spec['group']][] = [
                'key'        => $key,
                'type'       => $spec['type'],
                'label'      => $spec['label'],
                'help'       => $spec['help'],
                'value'      => self::display($value),
                'default'    => self::display($default),
                'options'    => array_values((array) ($spec['options'] ?? [])),
                'min'        => $spec['min'] ?? null,
                'max'        => $spec['max'] ?? null,
                'overridden' => $isOv,
                'source'     => $isOv ? 'studio' : ($envPinned ? 'config' : 'default'),
            ];
        }
        $out = [];
        foreach ($groups as $name => $rules) $out[] = ['group' => $name, 'rules' => $rules];
        return $out;
    }

    /** Render a resolved value for transport/display. */
    private static function display($v): string
    {
        if (is_bool($v))  return $v ? '1' : '0';
        if (is_array($v)) return implode(',', $v);
        return (string) $v;
    }

    /** A flat key => resolved-value map, for tests and read-models. */
    public static function snapshot(): array
    {
        $out = [];
        foreach (array_keys(self::REGISTRY) as $k) $out[$k] = self::get($k);
        return $out;
    }

    /**
     * The rules block appended to accountability prompts. This is what makes the
     * AI dynamic: leadership edits a threshold in Studio and the model's next
     * answer honours it, with no deploy and no code change.
     */
    public static function asPromptBlock(): string
    {
        try {
            $lines = [];
            $lines[] = 'Meetings between a mentor and mentee are expected every ' . self::int('mentorship.cadence_days') . ' days; a session under ' . self::int('mentorship.min_session_minutes') . ' minutes does not count as held.';
            $lines[] = 'A pairing is reported inactive after ' . self::int('mentorship.inactive_days') . ' days without a held session.';
            $lines[] = 'Relationship health: Green at ' . self::int('health.green_min_rate') . '% attendance or better, Amber from ' . self::int('health.amber_min_rate') . '%, Red below that — or Amber after ' . self::int('health.amber_after_days') . ' days of silence and Red after ' . self::int('health.red_after_days') . '.';
            $lines[] = 'Missed sessions escalate as: first — ' . str_replace('_', ' ', self::str('escalation.miss_1')) . '; second — ' . str_replace('_', ' ', self::str('escalation.miss_2')) . '; third or more — ' . str_replace('_', ' ', self::str('escalation.miss_3')) . '. Never escalate the same pairing twice inside ' . self::int('escalation.cooldown_days') . ' days.';
            $lines[] = 'Tone for any nudge or escalation: ' . self::str('escalation.tone');
            $lines[] = 'The leadership ladder is ' . implode(' → ', self::arr('levels.order')) . '. A mentee counts as ACTIVE only with at least ' . self::int('levels.active_min_sessions') . ' held sessions in the last ' . self::int('levels.active_window_days') . ' days. Level A needs ' . self::int('levels.mentees_for_a') . ' active mentees.'
                . (self::bool('levels.require_multiplication') ? ' Levels above A additionally require mentees who are themselves mentoring — measure multiplication quality, never headcount.' : '');
            $lines[] = self::bool('levels.auto_promote')
                ? 'Promotions may be applied automatically.'
                : 'You may RECOMMEND a promotion with its evidence. You must never state that a member has been promoted — only leadership promotes.';
            $lines[] = 'Commitments default to a ' . self::int('commitments.default_due_days') . '-day deadline, are reminded ' . self::int('commitments.reminder_days_before') . ' day(s) ahead, and count as missed ' . self::int('commitments.overdue_grace_days') . ' day(s) after their deadline. At most ' . self::int('commitments.max_per_meeting') . ' per meeting.'
                . (self::bool('commitments.require_owner_confirm') ? ' Propose an owner for a human to confirm; never assign work silently.' : '');
            if (!self::bool('ai.allow_character_score')) {
                $lines[] = 'Never reduce a person\'s character to a score or a number. Report behavioural evidence — what was attended, kept, missed, and voluntarily disclosed — and let a human interpret it.';
            }

            $block = "\n\nAFROVANGUARD OPERATING RULES (set by leadership — these are authoritative; follow them exactly and never substitute your own thresholds):\n- "
                   . implode("\n- ", $lines);
            $warn = self::conflicts();
            if ($warn) $block .= "\n(Note: leadership's current settings contain unresolved conflicts. Where a rule is contradictory, say so rather than guessing.)";
            return $block;
        } catch (\Throwable $e) {
            error_log('[rules] promptBlock: ' . $e->getMessage());
            return '';
        }
    }

    /** Undo dispatch for the Studio audit trail (AdminAudit::UNDOABLE). */
    public static function applyUndo(string $op, array $args): bool
    {
        if ($op !== 'rule_set') return false;
        $key = (string) ($args['key'] ?? '');
        $to  = (string) ($args['to'] ?? '');
        if (!self::defined($key)) return false;
        $r = self::set($key, $to, 'undo');
        return !empty($r['ok']);
    }
}
