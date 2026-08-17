<?php
/**
 * lib/AvRules.php — the Afrovanguard rules engine.
 *
 * The accountability system is governed by numbers that belong to Afrovanguard's
 * leadership, not to a developer: how often mentors must meet, how many misses
 * trigger an escalation, what "active mentee" means, what earns Level B. The
 * concept report is explicit about who owns them:
 *
 *   "AI should not invent Afrovanguard's constitution. Afrovanguard defines the
 *    rules. AI enforces and monitors them."
 *
 * So every such number lives here as DATA, editable in the Studio, and is read
 * at runtime — never frozen into a `const`. What stays in code is the *registry*:
 * each rule's type, bounds, group and documented default. That is a schema, not
 * a policy, and it is what lets the Studio render an editor and reject nonsense
 * (a cadence of -3 days, an escalation ladder of 0 steps) before it reaches the
 * engine.
 *
 * Resolution order, most specific first:
 *
 *   1. the DB override      (av_rules — what leadership set in the Studio)
 *   2. Config               (a config.php constant or AV_* env var, same key)
 *   3. the declared default (DEFS below)
 *
 * Step 2 is what makes this additive: a deployment already setting AV_* keeps
 * working untouched, and the Studio simply takes precedence once used.
 *
 * Reads are hot (the daily sweep asks for the same rules for every member), so
 * the whole set is memoised per request and cached in meta with a version stamp
 * bumped on write. Every read is fail-safe: a broken DB yields declared
 * defaults, never an exception into the request.
 */
declare(strict_types=1);

final class AvRules
{
    private const META_VER = 'av_rules_ver';
    private const EV_CHANGED = 'rules.changed';

    /** @var array<string,array<string,mixed>>|null memoised resolved values */
    private static ?array $memo = null;
    private static bool $ready = false;

    /**
     * The rule registry: key => [type, default, label, group, help, min, max, options].
     *
     * `type` is one of int | bool | str | enum | csv. `min`/`max` bound ints.
     * `options` enumerates enum values. Keys are dotted and grouped by subsystem
     * so the Studio can render sections without extra configuration.
     */
    private const DEFS = [
        /* ── Mentorship cadence & reminders (report §13, §27) ── */
        'mentorship.cadence_days' => [
            'type' => 'int', 'default' => 7, 'min' => 1, 'max' => 90, 'group' => 'Mentorship',
            'label' => 'Required meeting cadence (days)',
            'help'  => 'How often an active mentor and mentee are expected to meet. The report assumes weekly; set what Afrovanguard actually requires.',
        ],
        'mentorship.reminder_lead_hours' => [
            'type' => 'int', 'default' => 24, 'min' => 1, 'max' => 336, 'group' => 'Mentorship',
            'label' => 'Reminder lead time (hours)',
            'help'  => 'How far ahead of a due meeting the reminder goes out.',
        ],
        'mentorship.inactive_days' => [
            'type' => 'int', 'default' => 21, 'min' => 2, 'max' => 365, 'group' => 'Mentorship',
            'label' => 'Inactivity threshold (days)',
            'help'  => 'A pairing with no session for this long counts as inactive.',
        ],
        'mentorship.active_mentee_requires_days' => [
            'type' => 'int', 'default' => 30, 'min' => 7, 'max' => 365, 'group' => 'Mentorship',
            'label' => 'An "active" mentee has met within (days)',
            'help'  => 'The report\'s §4A test: a mentee only counts as active if the relationship is really running. This is that window.',
        ],

        /* ── Relationship health (report §15) ── */
        'health.amber_attendance_pct' => [
            'pending' => 'relationship health',
            'type' => 'int', 'default' => 70, 'min' => 0, 'max' => 100, 'group' => 'Health',
            'label' => 'Amber below attendance (%)',
            'help'  => 'Attendance rate under this puts a relationship in Amber.',
        ],
        'health.red_attendance_pct' => [
            'pending' => 'relationship health',
            'type' => 'int', 'default' => 40, 'min' => 0, 'max' => 100, 'group' => 'Health',
            'label' => 'Red below attendance (%)',
            'help'  => 'Attendance rate under this puts a relationship in Red. Must be below the Amber threshold.',
        ],
        'health.red_missed_streak' => [
            'pending' => 'relationship health',
            'type' => 'int', 'default' => 3, 'min' => 1, 'max' => 20, 'group' => 'Health',
            'label' => 'Red after consecutive misses',
            'help'  => 'Consecutive missed meetings that force Red regardless of the overall rate.',
        ],

        /* ── Escalation ladder (report §14) ── */
        'escalation.steps' => [
            'type' => 'int', 'default' => 3, 'min' => 1, 'max' => 10, 'group' => 'Escalation',
            'label' => 'Steps before leadership is involved',
            'help'  => 'Misses handled between the pair before the chain above them is told. The report\'s ladder is 3: gentle, firm, escalate.',
        ],
        'escalation.notify_chain' => [
            'type' => 'bool', 'default' => true, 'group' => 'Escalation',
            'label' => 'Notify the mentor\'s own mentor',
            'help'  => 'On the final step, inform the next level up (Mentor → Mentor\'s leader).',
        ],
        'escalation.cooldown_days' => [
            'pending' => 'the escalation ladder',
            'type' => 'int', 'default' => 7, 'min' => 1, 'max' => 90, 'group' => 'Escalation',
            'label' => 'Cooldown between escalations (days)',
            'help'  => 'Minimum gap between two escalations on the same relationship, so a quiet month cannot produce a pile of notices.',
        ],
        'escalation.tone' => [
            'type' => 'enum', 'default' => 'supportive', 'group' => 'Escalation',
            'options' => ['supportive', 'neutral', 'firm'],
            'label' => 'Notification tone',
            'help'  => 'The report is emphatic that escalation is early intervention, not punishment (§14). This shapes the wording the AI uses.',
        ],

        /* ── The O–G leadership ladder (report §4, §17, §20) ── */
        'levels.ladder' => [
            // Ladder codes are concatenated into DDL defaults and rendered as
            // badges, so they are restricted to short alphanumerics rather than
            // arbitrary text.
            'type' => 'csv', 'default' => 'O,A,B,C,D,E,F,G', 'group' => 'Levels',
            'item_pattern' => '/^[A-Za-z0-9]{1,4}$/', 'item_hint' => 'short codes (letters or digits, up to 4 characters)',
            'label' => 'Level ladder (in order)',
            'help'  => 'The progression, lowest first. The report proposes O→G; a shorter ladder is valid if leadership prefers one.',
        ],
        'levels.active_mentees_for_a' => [
            'type' => 'int', 'default' => 2, 'min' => 1, 'max' => 20, 'group' => 'Levels',
            'label' => 'Active mentees required for Level A',
            'help'  => 'Counted as ACTIVE mentees, not names on a list (§4A, §17).',
        ],
        'levels.require_multiplication' => [
            'type' => 'bool', 'default' => true, 'group' => 'Levels',
            'label' => 'Levels above A require multiplication',
            'help'  => 'On: advancement past A needs mentees who are themselves mentoring — the report\'s core principle. Off: levels above A are pure leadership grants.',
        ],
        'levels.min_days_at_level' => [
            'type' => 'int', 'default' => 90, 'min' => 0, 'max' => 1095, 'group' => 'Levels',
            'label' => 'Minimum time at a level (days)',
            'help'  => 'Tenure floor before the next level can be recommended (§27 "define minimum duration").',
        ],
        'levels.min_attendance_pct' => [
            'type' => 'int', 'default' => 85, 'min' => 0, 'max' => 100, 'group' => 'Levels',
            'label' => 'Minimum meeting consistency (%)',
            'help'  => 'Meeting consistency required for a promotion recommendation (§20 uses 85%).',
        ],
        'levels.min_commitment_pct' => [
            'type' => 'int', 'default' => 90, 'min' => 0, 'max' => 100, 'group' => 'Levels',
            'pending' => 'commitment tracking',
            'label' => 'Minimum commitment completion (%)',
            'help'  => 'Commitment completion required for a promotion recommendation (§20 uses 90%). Stored now, enforced once commitments are tracked as first-class records — until then promotion recommendations ignore it.',
        ],
        'levels.auto_promote' => [
            'type' => 'bool', 'default' => false, 'group' => 'Levels',
            'label' => 'Promote automatically',
            'help'  => 'Leave OFF. The report is explicit (§20, §23): the AI recommends and leadership decides. On, the engine writes levels without human approval.',
        ],

        /* ── Commitments (report §11, §39.2) ── */
        'commitments.default_due_days' => [
            'pending' => 'commitment tracking',
            'type' => 'int', 'default' => 7, 'min' => 1, 'max' => 180, 'group' => 'Commitments',
            'label' => 'Default deadline (days)',
            'help'  => 'Deadline given to an action item the meeting did not date.',
        ],
        'commitments.overdue_grace_days' => [
            'pending' => 'commitment tracking',
            'type' => 'int', 'default' => 1, 'min' => 0, 'max' => 30, 'group' => 'Commitments',
            'label' => 'Grace before "overdue" (days)',
            'help'  => 'How long past its deadline a commitment waits before it is chased.',
        ],
        'commitments.require_miss_reason' => [
            'pending' => 'commitment tracking',
            'type' => 'bool', 'default' => true, 'group' => 'Commitments',
            'label' => 'Ask why, on a miss',
            'help'  => 'Implements the report\'s §13 step 5 — "What did you fail to accomplish? Why?"',
        ],
        'commitments.auto_assign_owner' => [
            'pending' => 'commitment tracking',
            'type' => 'bool', 'default' => false, 'group' => 'Commitments',
            'label' => 'Assign owners without confirmation',
            'help'  => 'Leave OFF. The AI matches an action item\'s owner NAME to a member; silently assigning work to the wrong person is worse than no automation. Off means the chair confirms.',
        ],

        /* ── Meetings (report §7–10) ── */
        'meetings.ai_agenda' => [
            'pending' => 'agenda drafting',
            'type' => 'bool', 'default' => true, 'group' => 'Meetings',
            'label' => 'Propose agendas with AI',
            'help'  => 'Draft an agenda for a meeting that has none, from prior minutes and open commitments. Always a draft for the chair to approve (§7).',
        ],
        'meetings.warn_minutes' => [
            'pending' => 'in-meeting timing',
            'type' => 'csv', 'default' => '20,10,5', 'group' => 'Meetings',
            'label' => 'Time warnings (minutes remaining)',
            'help'  => 'When to warn that a meeting is nearing its scheduled end (§10). The report calls these configurable.',
        ],
        'meetings.ai_notetaker' => [
            'type' => 'bool', 'default' => true, 'group' => 'Meetings',
            'label' => 'Allow the AI notetaker in meetings',
            'help'  => 'Master switch for the notetaker that joins a Google Meet to capture the transcript. Off, no bot can be sent — on demand or otherwise — and meetings fall back to Google\'s own transcript or a pasted one.',
        ],
        'meetings.bot_on_demand' => [
            'type' => 'bool', 'default' => true, 'group' => 'Meetings',
            'label' => 'Let participants add the AI mid-meeting',
            'help'  => 'When on, anyone in a meeting can send the notetaker in from the portal without having ticked auto-record when it was scheduled. Off, only meetings scheduled with recording get it.',
        ],
        'meetings.bot_join_lead_min' => [
            'type' => 'int', 'default' => 2, 'min' => 0, 'max' => 60, 'group' => 'Meetings',
            'label' => 'Notetaker joins this many minutes early',
            'help'  => 'How far ahead of the start time the bot is asked to join, so it is already present when the first person arrives.',
        ],
        'meetings.bot_announce' => [
            'type' => 'bool', 'default' => true, 'group' => 'Meetings',
            'label' => 'Announce the notetaker to participants',
            'help'  => 'Keep this ON. The bot appears in the participant list by name, but people deserve to be told in the invite that a meeting is being transcribed, not to discover it. Turning this off does not hide the bot — it only removes the notice.',
        ],

        /* ── The AI itself (report §23) ── */
        'ai.enabled' => [
            'type' => 'bool', 'default' => true, 'group' => 'AI',
            'label' => 'AI assistance enabled',
            'help'  => 'Master switch. Off, the engine still tracks and reminds deterministically — it just stops calling a model.',
        ],
        'ai.character_scores' => [
            'type' => 'bool', 'default' => false, 'group' => 'AI',
            'label' => 'Allow a numeric character score',
            'help'  => 'Leave OFF. The report\'s §3A is explicit that character must not be reduced to a number; the scorecard shows behavioural evidence instead.',
        ],
        'ai.tone' => [
            'pending' => 'assistant tone',
            'type' => 'enum', 'default' => 'warm', 'group' => 'AI',
            'options' => ['warm', 'neutral', 'formal'],
            'label' => 'Assistant tone',
            'help'  => 'How the assistants address members.',
        ],
    ];

    /* ════════════════════════════════════════════════════════════════
       Storage
       ════════════════════════════════════════════════════════════════ */

    /** Idempotently provision the overrides table. Portable across drivers. */
    public static function ensure(): void
    {
        if (self::$ready) return;
        self::$ready = true;
        try {
            $db = Database::pdo();
            Database::execSchema($db, "CREATE TABLE IF NOT EXISTS av_rules (
                rule_key VARCHAR(64) PRIMARY KEY,
                value TEXT NOT NULL DEFAULT '',
                updated_by VARCHAR(191) NOT NULL DEFAULT '',
                updated_at VARCHAR(32) NOT NULL DEFAULT ''
            );");
        } catch (Throwable $e) { error_log('[rules] ensure: ' . $e->getMessage()); }
    }

    /* ════════════════════════════════════════════════════════════════
       Reads
       ════════════════════════════════════════════════════════════════ */

    /** Every rule, resolved: key => value. Memoised per request. */
    public static function all(): array
    {
        if (self::$memo !== null) return self::$memo;

        $overrides = [];
        try {
            self::ensure();
            foreach (Database::pdo()->query('SELECT rule_key, value FROM av_rules') as $r) {
                $overrides[(string) $r['rule_key']] = (string) $r['value'];
            }
        } catch (Throwable $e) { error_log('[rules] load: ' . $e->getMessage()); }

        $out = [];
        foreach (self::DEFS as $key => $def) {
            if (array_key_exists($key, $overrides)) {
                $cast = self::cast($key, $overrides[$key]);
                if ($cast !== null) { $out[$key] = $cast; continue; }
                // A stored value that no longer validates (bounds tightened in a
                // later release) must not poison the engine — fall through.
            }
            $env = self::fromConfig($key);
            $out[$key] = $env ?? $def['default'];
        }
        return self::$memo = $out;
    }

    /**
     * The RAW stored override for a key, or null when there is none.
     *
     * Distinct from get(), which resolves through config and defaults. Callers
     * that need to restore prior state — undo, in particular — must use this:
     * recording a resolved value as the "previous" one would turn a default or an
     * env-provided value into a permanent database override on undo.
     */
    public static function rawOverride(string $key): ?string
    {
        try {
            self::ensure();
            $st = Database::pdo()->prepare('SELECT value FROM av_rules WHERE rule_key = ?');
            $st->execute([$key]);
            $v = $st->fetchColumn();
            return $v === false ? null : (string) $v;
        } catch (Throwable $e) { return null; }
    }

    /** Raw stored overrides for many keys at once: key => value (absent = no override). */
    public static function rawOverrides(array $keys = []): array
    {
        $out = [];
        try {
            self::ensure();
            foreach (Database::pdo()->query('SELECT rule_key, value FROM av_rules') as $r) {
                $k = (string) $r['rule_key'];
                if (!$keys || in_array($k, $keys, true)) $out[$k] = (string) $r['value'];
            }
        } catch (Throwable $e) { /* best-effort */ }
        return $out;
    }

    /** One resolved rule value, or the declared default for an unknown key. */
    public static function get(string $key)
    {
        $all = self::all();
        if (array_key_exists($key, $all)) return $all[$key];
        return self::DEFS[$key]['default'] ?? null;
    }

    public static function int(string $key): int { return (int) self::get($key); }
    public static function bool(string $key): bool { return (bool) self::get($key); }
    public static function str(string $key): string { return (string) self::get($key); }

    /** A csv rule as a trimmed, non-empty list. */
    public static function list(string $key): array
    {
        $raw = self::get($key);
        if (is_array($raw)) return $raw;
        $parts = array_map('trim', explode(',', (string) $raw));
        return array_values(array_filter($parts, static fn($p) => $p !== ''));
    }

    /**
     * The Config/env fallback for a rule. Dotted keys map to the AV_ convention:
     * `mentorship.cadence_days` → `AV_MENTORSHIP_CADENCE_DAYS`.
     */
    private static function fromConfig(string $key)
    {
        if (!class_exists('Config')) return null;
        $envKey = 'AV_' . strtoupper(str_replace('.', '_', $key));
        $raw = Config::get($envKey);
        if ($raw === null || $raw === '') return null;
        return self::cast($key, (string) $raw);
    }

    /* ════════════════════════════════════════════════════════════════
       Validation
       ════════════════════════════════════════════════════════════════ */

    /**
     * Coerce a raw string to the rule's declared type, enforcing bounds and
     * options. Returns null when the value is not acceptable — callers treat
     * null as "reject", never as "false" or "zero".
     */
    public static function cast(string $key, string $raw)
    {
        $def = self::DEFS[$key] ?? null;
        if (!$def) return null;
        $raw = trim($raw);

        switch ($def['type']) {
            case 'int':
                if ($raw === '' || !preg_match('/^-?\d+$/', $raw)) return null;
                $n = (int) $raw;
                if (isset($def['min']) && $n < $def['min']) return null;
                if (isset($def['max']) && $n > $def['max']) return null;
                return $n;

            case 'bool':
                $t = strtolower($raw);
                if (in_array($t, ['1', 'true', 'yes', 'on'], true)) return true;
                if (in_array($t, ['0', 'false', 'no', 'off', ''], true)) return false;
                return null;

            case 'enum':
                return in_array($raw, (array) ($def['options'] ?? []), true) ? $raw : null;

            case 'csv':
                $parts = array_values(array_filter(array_map('trim', explode(',', $raw)), static fn($p) => $p !== ''));
                if (!$parts) return null;
                // Some csv rules feed places where arbitrary text is unsafe or
                // meaningless (ladder codes reach a DDL default and a UI badge),
                // so a rule may constrain its own items.
                $pattern = (string) ($def['item_pattern'] ?? '');
                if ($pattern !== '') {
                    foreach ($parts as $p) { if (!preg_match($pattern, $p)) return null; }
                }
                return implode(',', $parts);

            case 'str':
            default:
                return $raw;
        }
    }

    /**
     * Cross-rule sanity checks — the ones a per-field type cannot catch.
     * Returns a list of human-readable problems ([] when coherent).
     */
    public static function conflicts(?array $set = null): array
    {
        $r = $set ?? self::all();
        $out = [];

        $amber = (int) ($r['health.amber_attendance_pct'] ?? 0);
        $red   = (int) ($r['health.red_attendance_pct'] ?? 0);
        if ($red >= $amber) {
            $out[] = 'Health: the Red attendance threshold (' . $red . '%) must be below Amber (' . $amber . '%), or nothing is ever Amber.';
        }

        $ladder = is_array($r['levels.ladder'] ?? null)
            ? $r['levels.ladder']
            : array_values(array_filter(array_map('trim', explode(',', (string) ($r['levels.ladder'] ?? '')))));
        if (count($ladder) < 2) {
            $out[] = 'Levels: the ladder needs at least two levels.';
        } elseif (count($ladder) !== count(array_unique($ladder))) {
            $out[] = 'Levels: the ladder has duplicate entries.';
        }

        $cadence = (int) ($r['mentorship.cadence_days'] ?? 0);
        $active  = (int) ($r['mentorship.active_mentee_requires_days'] ?? 0);
        if ($cadence > 0 && $active > 0 && $active < $cadence) {
            $out[] = 'Mentorship: the "active mentee" window (' . $active . 'd) is shorter than the required cadence (' . $cadence
                   . 'd), so a pair meeting exactly on schedule would still read as inactive.';
        }

        $inactive = (int) ($r['mentorship.inactive_days'] ?? 0);
        if ($cadence > 0 && $inactive > 0 && $inactive < $cadence) {
            $out[] = 'Mentorship: the inactivity threshold (' . $inactive . 'd) is shorter than the cadence (' . $cadence . 'd), so every pairing is permanently inactive.';
        }

        return $out;
    }

    /* ════════════════════════════════════════════════════════════════
       Writes
       ════════════════════════════════════════════════════════════════ */

    /**
     * Apply a batch of overrides. Validates every value BEFORE writing any, so a
     * partly-invalid submission changes nothing.
     *
     * @param array<string,string> $values raw key => value
     * @return array{ok:bool, saved:int, errors:array<string,string>, conflicts:array}
     */
    public static function save(array $values, string $actor = ''): array
    {
        $errors = [];
        $clean  = [];

        foreach ($values as $key => $raw) {
            $key = (string) $key;
            if (!isset(self::DEFS[$key])) { $errors[$key] = 'Unknown rule.'; continue; }
            $cast = self::cast($key, is_bool($raw) ? ($raw ? '1' : '0') : (string) $raw);
            if ($cast === null) { $errors[$key] = self::expected($key); continue; }
            $clean[$key] = $cast;
        }
        if ($errors) return ['ok' => false, 'saved' => 0, 'errors' => $errors, 'conflicts' => []];

        // Check coherence against what the set would BECOME, not what it is.
        $conflicts = self::conflicts(array_merge(self::all(), $clean));
        if ($conflicts) return ['ok' => false, 'saved' => 0, 'errors' => [], 'conflicts' => $conflicts];

        $saved = 0;
        try {
            self::ensure();
            $db = Database::pdo();
            $now = gmdate('c');
            foreach ($clean as $key => $val) {
                $str = is_bool($val) ? ($val ? '1' : '0') : (string) $val;
                // UPSERT without driver-specific syntax: update, insert if absent.
                $up = $db->prepare('UPDATE av_rules SET value = ?, updated_by = ?, updated_at = ? WHERE rule_key = ?');
                $up->execute([$str, $actor, $now, $key]);
                if ($up->rowCount() === 0) {
                    $ins = $db->prepare('INSERT INTO av_rules (rule_key, value, updated_by, updated_at) VALUES (?,?,?,?)');
                    try { $ins->execute([$key, $str, $actor, $now]); }
                    catch (Throwable $e) {
                        // Lost a race to a concurrent insert — the row exists now.
                        $up->execute([$str, $actor, $now, $key]);
                    }
                }
                $saved++;
            }
            self::invalidate();
        } catch (Throwable $e) {
            error_log('[rules] save: ' . $e->getMessage());
            return ['ok' => false, 'saved' => $saved, 'errors' => ['_' => 'Could not save.'], 'conflicts' => []];
        }
        return ['ok' => true, 'saved' => $saved, 'errors' => [], 'conflicts' => []];
    }

    /**
     * Drop an override so the rule falls back to Config/default.
     *
     * A reset changes the effective policy just as much as a save, so it goes
     * through the same coherence gate: dropping one override can leave the set
     * in a combination save() would have refused (resetting Amber back to a
     * default that sits below the pinned Red, say). Returns a reason on refusal.
     *
     * @return array{ok:bool, conflicts:array}
     */
    public static function resetChecked(string $key): array
    {
        if (!isset(self::DEFS[$key])) return ['ok' => false, 'conflicts' => ['Unknown rule.']];

        // What the set would become without this override.
        $would = self::all();
        $env = self::fromConfig($key);
        $would[$key] = $env ?? self::DEFS[$key]['default'];
        $conflicts = self::conflicts($would);
        if ($conflicts) return ['ok' => false, 'conflicts' => $conflicts];

        return ['ok' => self::reset($key), 'conflicts' => []];
    }

    /** Drop an override unconditionally. Prefer resetChecked() for admin actions. */
    public static function reset(string $key, string $actor = ''): bool
    {
        if (!isset(self::DEFS[$key])) return false;
        try {
            self::ensure();
            Database::pdo()->prepare('DELETE FROM av_rules WHERE rule_key = ?')->execute([$key]);
            self::invalidate();
            return true;
        } catch (Throwable $e) { error_log('[rules] reset: ' . $e->getMessage()); return false; }
    }

    /**
     * Drop every override at once.
     *
     * Always safe to allow: the declared defaults are coherent by construction,
     * so clearing everything cannot land in a contradictory state the way a
     * single-key reset can. Config/env values still apply underneath, so the
     * result is re-checked and reported rather than assumed.
     *
     * @return array{ok:bool, conflicts:array}
     */
    public static function resetAll(string $actor = ''): array
    {
        try {
            self::ensure();
            Database::pdo()->exec('DELETE FROM av_rules');
            self::invalidate();
            return ['ok' => true, 'conflicts' => self::conflicts()];
        } catch (Throwable $e) {
            error_log('[rules] resetAll: ' . $e->getMessage());
            return ['ok' => false, 'conflicts' => []];
        }
    }

    /** Forget caches and tell the rest of the app the constitution moved. */
    public static function invalidate(): void
    {
        self::$memo = null;
        try {
            $cur = (int) (Database::metaGet(self::META_VER) ?? '0');
            Database::metaSet(self::META_VER, (string) ($cur + 1));
        } catch (Throwable $e) { /* best-effort */ }
        // The AI brief embeds the rules, so it must be rebuilt.
        try { if (class_exists('AiKnowledge')) AiKnowledge::invalidate(); } catch (Throwable $e) {}
        try { if (class_exists('Events')) Events::emit(self::EV_CHANGED, []); } catch (Throwable $e) {}
    }

    /**
     * Undo primitive for the audit layer (AdminAudit::undo).
     *
     * `save` restores the previous values. A key whose previous value was null had
     * no override, so undoing means removing the override rather than writing
     * "null" over it — otherwise an undo would convert a default into a pin.
     */
    public static function applyUndo(string $op, array $args): bool
    {
        if ($op !== 'save') return false;
        $values = (array) ($args['values'] ?? []);
        $restore = [];
        $ok = true;
        foreach ($values as $key => $prev) {
            $key = (string) $key;
            if (!isset(self::DEFS[$key])) continue;
            if ($prev === null || $prev === '') { $ok = self::reset($key) && $ok; continue; }
            $restore[$key] = (string) $prev;
        }
        if ($restore) {
            $res = self::save($restore, 'undo');
            $ok = !empty($res['ok']) && $ok;
        }
        return $ok;
    }

    /** Monotonic stamp for cache keys elsewhere. */
    public static function version(): string
    {
        try { return (string) (Database::metaGet(self::META_VER) ?? '0'); }
        catch (Throwable $e) { return '0'; }
    }

    /* ════════════════════════════════════════════════════════════════
       Introspection — powers the Studio editor and the AI prompt block
       ════════════════════════════════════════════════════════════════ */

    /** A short "what this field accepts" string, for validation errors and help. */
    public static function expected(string $key): string
    {
        $def = self::DEFS[$key] ?? null;
        if (!$def) return 'Unknown rule.';
        switch ($def['type']) {
            case 'int':
                $lo = $def['min'] ?? null; $hi = $def['max'] ?? null;
                if ($lo !== null && $hi !== null) return 'A whole number between ' . $lo . ' and ' . $hi . '.';
                return 'A whole number.';
            case 'bool': return 'On or off.';
            case 'enum': return 'One of: ' . implode(', ', (array) ($def['options'] ?? [])) . '.';
            case 'csv':
                $hint = (string) ($def['item_hint'] ?? '');
                return 'A comma-separated list' . ($hint !== '' ? ' of ' . $hint . '.' : '.');
            default:     return 'Text.';
        }
    }

    /**
     * The full registry with resolved values and provenance, grouped for the UI.
     * `source` tells leadership WHERE a value came from — the single most useful
     * thing when a rule is not behaving as someone expects.
     */
    public static function describe(): array
    {
        $values = self::all();
        $overrides = [];
        try {
            self::ensure();
            foreach (Database::pdo()->query('SELECT rule_key, updated_by, updated_at FROM av_rules') as $r) {
                $overrides[(string) $r['rule_key']] = ['by' => (string) $r['updated_by'], 'at' => (string) $r['updated_at']];
            }
        } catch (Throwable $e) { /* fall back to no provenance */ }

        $raw = self::rawOverrides();

        $groups = [];
        foreach (self::DEFS as $key => $def) {
            // A stored override that no longer validates is NOT in force — all()
            // skipped it — so reporting it as "set here" would show the Studio a
            // provenance that does not match the value beside it.
            $stored  = $raw[$key] ?? null;
            $inForce = $stored !== null && self::cast($key, $stored) !== null;

            $source = 'default';
            if ($inForce)                            $source = 'studio';
            elseif (self::fromConfig($key) !== null) $source = 'config';

            $groups[$def['group']][] = [
                'key'        => $key,
                'label'      => $def['label'],
                'help'       => $def['help'] ?? '',
                'type'       => $def['type'],
                'value'      => $values[$key] ?? $def['default'],
                'default'    => $def['default'],
                'min'        => $def['min'] ?? null,
                'max'        => $def['max'] ?? null,
                'options'    => $def['options'] ?? null,
                'expected'   => self::expected($key),
                'source'     => $source,
                // Named subsystem that will enforce this rule, when none does yet.
                // The Studio says so plainly rather than implying it is live.
                'pending'    => (string) ($def['pending'] ?? ''),
                'stale'      => $stored !== null && !$inForce ? $stored : '',
                'updated_by' => $inForce ? ($overrides[$key]['by'] ?? '') : '',
                'updated_at' => $inForce ? ($overrides[$key]['at'] ?? '') : '',
            ];
        }
        return ['groups' => $groups, 'conflicts' => self::conflicts($values), 'version' => self::version()];
    }

    /**
     * The active rules as a prompt block, so the assistants enforce Afrovanguard's
     * ACTUAL constitution rather than numbers a developer once typed into a
     * system prompt. Bounded, and it states the AI's limits as plainly as its
     * permissions (report §23).
     */
    public static function asPromptBlock(): string
    {
        try {
            $r = self::all();
            $lines = [
                'Meeting cadence: mentors and mentees are expected to meet every ' . (int) $r['mentorship.cadence_days']
                    . ' day(s); reminders go out ' . (int) $r['mentorship.reminder_lead_hours'] . 'h ahead.',
                'A mentee counts as ACTIVE only if the pairing has met within ' . (int) $r['mentorship.active_mentee_requires_days']
                    . ' days. Never treat a name on a list as an active mentee.',
                'Escalation ladder: ' . (int) $r['escalation.steps'] . ' step(s) before leadership is informed'
                    . (!empty($r['escalation.notify_chain']) ? ', then the mentor\'s own leader is told' : '')
                    . '. Tone: ' . (string) $r['escalation.tone'] . '. Escalation is early intervention, never punishment.',
                'Leadership ladder: ' . implode(' → ', self::list('levels.ladder'))
                    . '. Level A needs ' . (int) $r['levels.active_mentees_for_a'] . ' active mentee(s).'
                    . (!empty($r['levels.require_multiplication']) ? ' Levels above A require mentees who are themselves mentoring.' : ''),
                // Only thresholds the engine actually enforces are stated. Telling
                // the model about a rule nothing checks invites it to report
                // compliance that was never measured.
                'Promotion thresholds: at least ' . (int) $r['levels.min_days_at_level'] . ' days at the current level and '
                    . (int) $r['levels.min_attendance_pct'] . '% meeting consistency.',
            ];
            if (empty($r['levels.auto_promote'])) {
                $lines[] = 'You may RECOMMEND a promotion with its evidence. You may never decide one — leadership approves every advancement.';
            }
            if (empty($r['ai.character_scores'])) {
                $lines[] = 'Never reduce a person\'s character to a score or a percentage. Report observable behaviour and let a human interpret it.';
            }

            $block = "\n\nAFROVANGUARD RULES (set by leadership — these govern your reasoning; do not substitute your own thresholds):\n- "
                   . implode("\n- ", $lines);
            return mb_strlen($block) > 2000 ? mb_substr($block, 0, 2000) . '…' : $block;
        } catch (Throwable $e) {
            error_log('[rules] promptBlock: ' . $e->getMessage());
            return '';
        }
    }
}
