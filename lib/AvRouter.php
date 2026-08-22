<?php
/**
 * lib/AvRouter.php — which model answers which job, and what it cost.
 *
 * Before this, provider choice was one hardcoded order for everything: Claude,
 * then OpenAI, then Gemini, wherever a caller happened to remember to fall back
 * at all. That is the wrong shape twice over. A frontier model writing a
 * one-line reminder is money set on fire, and a cheap model writing a promotion
 * review is a decision made badly. The work is not uniform, so the routing
 * should not be either.
 *
 * So jobs are classified, and each class gets its own ranked list of providers
 * — declared as rules, which means leadership owns them the same way it owns
 * the escalation ladder (§27). The shipped defaults:
 *
 *   reason  openai → anthropic → gemini → groq     judgement: briefs, reviews,
 *                                                  agendas, minutes
 *   bulk    groq → gemini → openai → anthropic     volume: nudges, wording,
 *                                                  classification, summaries
 *   tools   openai → anthropic → gemini            the agent loop
 *
 * OpenAI is the primary because it is the one provider here that does all three
 * competently and has the deepest tool support. Groq (and the rest of AiCompat)
 * are the support tier — fast, free or near-free, and entirely good enough for
 * work whose output a human reads in three seconds. Anthropic is secondary: it
 * stays in the reason and tools lists, first fallback for the work where the
 * difference shows.
 *
 * Groq is deliberately absent from the `tools` default. Its tool-calling
 * support varies by model in a way the other three do not, and a tool loop that
 * half-works is worse than one that is not offered. Add it there if a specific
 * model proves itself.
 *
 * THE LEDGER. Every attempt is recorded — provider, model, job, latency, tokens
 * in and out, whether it was a fallback, and the error if it failed. That is
 * the token accounting AI-AUDIT A-9 said was missing, and it is what makes the
 * AI Ops board show real numbers instead of a green tick. Recording is
 * best-effort by construction: a ledger write that fails must never fail the
 * call it was measuring.
 */
declare(strict_types=1);

final class AvRouter
{
    /** The three classes of work. Anything unrecognised routes as 'reason'. */
    public const JOB_REASON = 'reason';
    public const JOB_BULK   = 'bulk';
    public const JOB_TOOLS  = 'tools';
    public const JOBS = [self::JOB_REASON, self::JOB_BULK, self::JOB_TOOLS];

    /**
     * Providers with a native client of their own, outside AiCompat's presets.
     *
     * This is a CAPABILITY list, not a ranking. Nothing in this file may treat
     * its order as a preference: preference is declared in the ai.route_* rules
     * and nowhere else, so that changing which provider leads is an edit in the
     * Studio rather than a deploy.
     */
    private const NATIVE = ['openai', 'anthropic', 'gemini'];

    /** Rows older than this are pruned on write. A ledger is a diagnostic, not an archive. */
    private const RETAIN_DAYS = 90;

    private static bool $ready = false;

    /* ════════════════════════════════════════════════════════════════
       Inventory
       ════════════════════════════════════════════════════════════════ */

    /** Every provider this build can speak to, native and compatible alike. */
    public static function known(): array
    {
        return array_merge(self::NATIVE, class_exists('AiCompat') ? AiCompat::handles() : []);
    }

    public static function configured(string $handle): bool
    {
        switch ($handle) {
            case 'openai':    return class_exists('OpenAi') && OpenAi::configured();
            case 'anthropic': return class_exists('AvBot')  && AvBot::configured();
            case 'gemini':    return class_exists('Gemini') && Gemini::configured();
            default:          return class_exists('AiCompat') && AiCompat::configured($handle);
        }
    }

    public static function label(string $handle): string
    {
        switch ($handle) {
            case 'openai':    return 'OpenAI';
            case 'anthropic': return 'Anthropic';
            case 'gemini':    return 'Gemini';
            default:          return class_exists('AiCompat') ? AiCompat::label($handle) : $handle;
        }
    }

    /** Which model this provider would actually use right now. */
    public static function model(string $handle): string
    {
        try {
            switch ($handle) {
                case 'openai':    return class_exists('OpenAi') ? OpenAi::model() : '';
                case 'anthropic': return class_exists('AvBot')  ? AvBot::model()  : '';
                case 'gemini':    return class_exists('Gemini') ? Gemini::model() : '';
                default:          return class_exists('AiCompat') ? AiCompat::model($handle) : '';
            }
        } catch (Throwable $e) { return ''; }
    }

    /**
     * The full picture for the AI Ops board: every provider, whether it is
     * configured, its model, and which job lists it appears in.
     */
    public static function inventory(): array
    {
        $routes = [];
        foreach (self::JOBS as $job) $routes[$job] = self::order($job, false);

        $out = [];
        foreach (self::known() as $h) {
            $jobs = [];
            foreach (self::JOBS as $job) {
                $pos = array_search($h, $routes[$job], true);
                if ($pos !== false) $jobs[$job] = $pos + 1;      // 1-based rank
            }
            $out[] = [
                'handle'     => $h,
                'label'      => self::label($h),
                'native'     => in_array($h, self::NATIVE, true),
                'configured' => self::configured($h),
                'model'      => self::model($h),
                'note'       => (!in_array($h, self::NATIVE, true) && class_exists('AiCompat')) ? AiCompat::note($h) : '',
                'ranks'      => $jobs,
            ];
        }
        return $out;
    }

    /* ════════════════════════════════════════════════════════════════
       Routing
       ════════════════════════════════════════════════════════════════ */

    /**
     * Job class → rule key, written out in full.
     *
     * This was 'ai.route_' . $job, which is shorter and wrong in two ways. A
     * typo in a job name would have silently read a rule that does not exist —
     * AvRules::get() returns null for an unknown key, list() then returns [],
     * and the router would have fallen back to "everything configured" while
     * looking like it had honoured a rule. And the drift guard in
     * tests/rules.test.php greps lib/ for each declared key by name, so a
     * concatenated key reads as a rule nothing consumes. It caught this.
     */
    private const RULE_KEYS = [
        self::JOB_REASON => 'ai.route_reason',
        self::JOB_BULK   => 'ai.route_bulk',
        self::JOB_TOOLS  => 'ai.route_tools',
    ];

    private static function ruleKey(string $job): string
    {
        return self::RULE_KEYS[$job] ?? self::RULE_KEYS[self::JOB_REASON];
    }

    /**
     * The ranked provider list for a job.
     *
     * $onlyConfigured=false gives the declared order as written, for display.
     * True — the default — gives what would actually be tried right now.
     *
     * A rule naming providers that are all unconfigured must not mean "no AI":
     * that would take a deployment offline over a routing typo. So an empty
     * result falls back to any configured provider, preferring the primary.
     */
    public static function order(string $job, bool $onlyConfigured = true): array
    {
        $declared = [];
        try {
            if (class_exists('AvRules')) $declared = AvRules::list(self::ruleKey($job));
        } catch (Throwable $e) { $declared = []; }
        // No literal ordering here on purpose. If the rules engine cannot answer
        // there is no declared preference to honour, so every provider this build
        // knows is a candidate, in registry order — which is arbitrary, and is
        // meant to be. Baking a favourite in at this line is exactly how a
        // "leadership owns the routing" rule quietly stops being true.
        if (!$declared) $declared = self::known();

        // AV_AGENT_PROVIDER predates the routing rules and is documented config,
        // so it still wins — but as a promotion to the front of the list rather
        // than a replacement for it, which keeps the fallbacks the rule declared.
        $forced = class_exists('Config') ? strtolower(trim(Config::str('AV_AGENT_PROVIDER', ''))) : '';
        if ($forced !== '') array_unshift($declared, $forced);

        $known = self::known();
        $out = [];
        foreach ($declared as $h) {
            $h = strtolower(trim((string) $h));
            // Silently drop what this build cannot speak to. A rule is edited by
            // hand in a text box; a typo there should narrow the list, not throw.
            if ($h === '' || !in_array($h, $known, true) || in_array($h, $out, true)) continue;
            if ($onlyConfigured && !self::configured($h)) continue;
            $out[] = $h;
        }
        if ($out || !$onlyConfigured) return $out;

        // The declared list resolved to nothing usable — every provider in it is
        // unconfigured, or the rule is empty. Rather than report no AI at all,
        // take whatever this deployment actually has. Preference is borrowed
        // from the OTHER job routes before falling back to registry order, so
        // even this path follows what leadership declared where it can.
        foreach (self::preferenceUnion() as $h) {
            if (self::configured($h) && !in_array($h, $out, true)) $out[] = $h;
        }
        return $out;
    }

    /**
     * Every provider named anywhere in the routing rules, in the order they are
     * first mentioned, then everything else this build knows.
     *
     * Used only as a last resort, and only so that a deployment with a broken
     * rule still prefers what its own other rules prefer.
     */
    private static function preferenceUnion(): array
    {
        $out = [];
        foreach (self::JOBS as $job) {
            try {
                $declared = class_exists('AvRules') ? AvRules::list(self::ruleKey($job)) : [];
            } catch (Throwable $e) { $declared = []; }
            foreach ($declared as $h) {
                $h = strtolower(trim((string) $h));
                if ($h !== '' && !in_array($h, $out, true)) $out[] = $h;
            }
        }
        foreach (self::known() as $h) { if (!in_array($h, $out, true)) $out[] = $h; }
        return $out;
    }

    /**
     * The environment variables that would switch a provider on, for an error
     * message that stays true as providers are added. Generated, never typed:
     * a hand-written "set OPENAI_API_KEY" is out of date the moment the
     * registry changes, and reads as a recommendation besides.
     */
    public static function keyHint(): string
    {
        $native = ['openai' => 'OPENAI_API_KEY', 'anthropic' => 'ANTHROPIC_API_KEY', 'gemini' => 'AV_GEMINI_API_KEY'];
        $keys = [];
        foreach (self::NATIVE as $h) { if (isset($native[$h])) $keys[] = $native[$h]; }
        if (class_exists('AiCompat')) {
            foreach (AiCompat::handles() as $h) {
                $k = AiCompat::primaryKeyName($h);
                if ($k !== '') $keys[] = $k;
            }
        }
        // Alphabetical, so the sentence cannot be read as a recommendation.
        $keys = array_values(array_unique($keys));
        sort($keys, SORT_STRING);
        return implode(', ', array_slice($keys, 0, 6));
    }

    /** Is any provider able to answer this job class? */
    public static function available(string $job = self::JOB_REASON): bool
    {
        if (class_exists('AvRules') && !AvRules::bool('ai.enabled')) return false;
        return self::order($job) !== [];
    }

    /** Whether a failed provider should hand on to the next one. */
    private static function fallbackEnabled(): bool
    {
        try { return !class_exists('AvRules') || AvRules::bool('ai.fallback'); }
        catch (Throwable $e) { return true; }
    }

    /* ════════════════════════════════════════════════════════════════
       The call
       ════════════════════════════════════════════════════════════════ */

    /**
     * One completion, routed by job class, recorded in the ledger.
     *
     * $opts: system, max_tokens, temperature, actor (who/what asked — recorded).
     *
     * @return array{ok:bool,text:string,provider:string,model:string,error:?string,tried:list<string>}
     */
    public static function complete(string $job, string $prompt, array $opts = []): array
    {
        $job    = in_array($job, self::JOBS, true) ? $job : self::JOB_REASON;
        $actor  = mb_substr(trim((string) ($opts['actor'] ?? $job)), 0, 64);
        $out    = ['ok' => false, 'text' => '', 'provider' => '', 'model' => '', 'error' => null, 'tried' => []];

        if (class_exists('AvRules') && !AvRules::bool('ai.enabled')) {
            $out['error'] = 'AI assistance is switched off in the Studio rules.';
            return $out;
        }
        if (trim($prompt) === '') { $out['error'] = 'Nothing to send.'; return $out; }

        $order = self::order($job);
        if (!$order) { $out['error'] = 'No AI provider is configured.'; return $out; }
        if (!self::fallbackEnabled()) $order = array_slice($order, 0, 1);

        $errors = [];
        foreach ($order as $depth => $handle) {
            $started = microtime(true);
            $r = self::callOne($handle, $prompt, $opts);
            $ms = (int) round((microtime(true) - $started) * 1000);

            self::record([
                'job'      => $job,
                'actor'    => $actor,
                'provider' => $handle,
                'model'    => (string) ($r['model'] ?? self::model($handle)),
                'ok'       => !empty($r['ok']),
                'ms'       => $ms,
                'in'       => (int) ($r['usage']['in'] ?? 0),
                'out'      => (int) ($r['usage']['out'] ?? 0),
                'depth'    => (int) $depth,
                'error'    => (string) ($r['error'] ?? ''),
            ]);

            $out['tried'][] = $handle;
            if (!empty($r['ok'])) {
                return ['ok' => true, 'text' => trim((string) $r['text']), 'provider' => $handle,
                        'model' => (string) ($r['model'] ?? self::model($handle)), 'error' => null,
                        'tried' => $out['tried']];
            }
            $errors[] = self::label($handle) . ': ' . (string) ($r['error'] ?? 'failed');
        }

        $out['error'] = implode(' | ', $errors) ?: 'No AI provider answered.';
        return $out;
    }

    /**
     * Dispatch to one provider's own client, normalising the return.
     *
     * Public so a test can exercise the normalisation without a network — and so
     * a caller that genuinely needs a named provider (a comparison, a probe)
     * does not have to reach past the router to get one.
     *
     * @return array{ok:bool,text:string,error:?string,usage:array{in:int,out:int},model:string}
     */
    public static function callOne(string $handle, string $prompt, array $opts = []): array
    {
        $norm = static function (array $r, string $model): array {
            return [
                'ok'    => !empty($r['ok']),
                'text'  => (string) ($r['text'] ?? ''),
                'error' => isset($r['error']) ? (string) $r['error'] : null,
                'usage' => ['in'  => (int) ($r['usage']['in'] ?? 0),
                            'out' => (int) ($r['usage']['out'] ?? 0)],
                'model' => (string) ($r['model'] ?? $model),
            ];
        };
        try {
            switch ($handle) {
                case 'openai':
                    if (!class_exists('OpenAi')) break;
                    return $norm(OpenAi::generate($prompt, $opts), OpenAi::model());
                case 'anthropic':
                    if (!class_exists('AvBot')) break;
                    // AvBot takes (text, history, opts) and defaults its own system
                    // prompt; the other two take the system in $opts. Absorbing that
                    // difference here is the whole reason this switch exists.
                    return $norm(AvBot::reply($prompt, self::anthropicHistory($opts['history'] ?? []), $opts), AvBot::model());
                case 'gemini':
                    if (!class_exists('Gemini')) break;
                    return $norm(Gemini::generate($prompt, $opts), Gemini::model());
                default:
                    if (!class_exists('AiCompat') || !AiCompat::knows($handle)) break;
                    return $norm(AiCompat::generate($handle, $prompt, $opts), AiCompat::model($handle));
            }
        } catch (Throwable $e) {
            return ['ok' => false, 'text' => '', 'error' => 'threw: ' . $e->getMessage(),
                    'usage' => ['in' => 0, 'out' => 0], 'model' => ''];
        }
        return ['ok' => false, 'text' => '', 'error' => 'Unknown provider: ' . $handle,
                'usage' => ['in' => 0, 'out' => 0], 'model' => ''];
    }

    /**
     * The neutral history shape as AvBot wants it. AvBot folds a thread into one
     * user turn and labels each line by speaker, so it names the roles
     * 'member'/'bot' rather than 'user'/'assistant'.
     */
    private static function anthropicHistory($history): array
    {
        if (!is_array($history)) return [];
        $out = [];
        foreach ($history as $h) {
            if (!is_array($h)) continue;
            $t = trim((string) ($h['text'] ?? ''));
            if ($t === '') continue;
            $bot = in_array(($h['role'] ?? ''), ['assistant', 'bot', 'model'], true);
            $out[] = ['role' => $bot ? 'bot' : 'member',
                      'name' => $bot ? (string) ($h['name'] ?? 'Assistant') : null,
                      'text' => $t];
        }
        return $out;
    }

    /* ════════════════════════════════════════════════════════════════
       The ledger (AI-AUDIT A-9)
       ════════════════════════════════════════════════════════════════ */

    public static function ensure(): void
    {
        if (self::$ready) return;
        self::$ready = true;
        try {
            $db = Database::pdo();
            Database::execSchema($db, "CREATE TABLE IF NOT EXISTS av_ai_calls (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                job        VARCHAR(16)  NOT NULL DEFAULT '',
                actor      VARCHAR(64)  NOT NULL DEFAULT '',
                provider   VARCHAR(32)  NOT NULL DEFAULT '',
                model      VARCHAR(96)  NOT NULL DEFAULT '',
                ok         INTEGER      NOT NULL DEFAULT 0,
                ms         INTEGER      NOT NULL DEFAULT 0,
                tokens_in  INTEGER      NOT NULL DEFAULT 0,
                tokens_out INTEGER      NOT NULL DEFAULT 0,
                depth      INTEGER      NOT NULL DEFAULT 0,
                error      VARCHAR(300) NOT NULL DEFAULT '',
                created_at VARCHAR(32)  NOT NULL DEFAULT ''
            );");
            foreach (['idx_ai_calls_at ON av_ai_calls(created_at)',
                      'idx_ai_calls_provider ON av_ai_calls(provider)'] as $ix) {
                try { $db->exec('CREATE INDEX IF NOT EXISTS ' . $ix); } catch (Throwable $e) {}
            }
        } catch (Throwable $e) { error_log('[router] ensure: ' . $e->getMessage()); }
    }

    /**
     * Write one attempt. Never throws: a diagnostic that can break the thing it
     * diagnoses is worse than no diagnostic.
     */
    public static function record(array $row): void
    {
        try {
            self::ensure();
            Database::pdo()->prepare(
                'INSERT INTO av_ai_calls (job, actor, provider, model, ok, ms, tokens_in, tokens_out, depth, error, created_at)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?)'
            )->execute([
                mb_substr((string) ($row['job'] ?? ''), 0, 16),
                mb_substr((string) ($row['actor'] ?? ''), 0, 64),
                mb_substr((string) ($row['provider'] ?? ''), 0, 32),
                mb_substr((string) ($row['model'] ?? ''), 0, 96),
                !empty($row['ok']) ? 1 : 0,
                max(0, (int) ($row['ms'] ?? 0)),
                max(0, (int) ($row['in'] ?? 0)),
                max(0, (int) ($row['out'] ?? 0)),
                max(0, (int) ($row['depth'] ?? 0)),
                mb_substr((string) ($row['error'] ?? ''), 0, 300),
                gmdate('Y-m-d H:i:s'),
            ]);
            self::prune();
        } catch (Throwable $e) { error_log('[router] record: ' . $e->getMessage()); }
    }

    /**
     * Drop rows past the retention window — but only about one write in fifty,
     * because a DELETE on every model call is a needless write on a shared host
     * and the window does not have to be exact to the minute.
     */
    private static function prune(): void
    {
        if (random_int(1, 50) !== 1) return;
        try {
            $cut = gmdate('Y-m-d H:i:s', time() - self::RETAIN_DAYS * 86400);
            Database::pdo()->prepare('DELETE FROM av_ai_calls WHERE created_at < ?')->execute([$cut]);
        } catch (Throwable $e) { /* the window is advisory */ }
    }
}
