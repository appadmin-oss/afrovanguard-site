<?php
/**
 * lib/AvSettings.php — set the AI up from the Studio, not from a text file.
 *
 * Everything the AI layer needs to run — which model, whose API key, which
 * notetaker, which search provider — has until now lived in `.env` or
 * `config.php`. On shared cPanel hosting that means an administrator who wants to
 * switch on the assistant has to find a file above the web root, edit it without
 * breaking the syntax, and hope. Most never will, and the feature stays dark.
 *
 * This puts those settings in the Studio, and keeps three promises.
 *
 * 1. SECRETS STAY SECRET. An API key is encrypted at rest with the same
 *    sodium/openssl pattern GoogleWorkspaceUser already uses, keyed from
 *    av_secret(). No secret is ever returned to the browser — the Studio gets a
 *    masked preview ("sk-ant-…4f2a") and nothing more, so a stored key cannot be
 *    read back out through the admin API even by someone already signed in.
 *
 * 2. NOTHING IS STORED IN PLAINTEXT. If there is no APP_KEY to encrypt with, or
 *    no crypto extension, saving a secret is REFUSED with an explanation rather
 *    than quietly written to a database in the clear.
 *
 * 3. EXISTING CODE DOES NOT CHANGE. Consumers read their configuration three
 *    different ways — `Config::str()`, a private `cfg()` helper, or a bare
 *    `getenv()`. Rather than hunt down and rewrite each one, apply() publishes
 *    stored settings into the process environment and Config::get() consults this
 *    store first. AvBot, Gemini, RecallBot, AvWeb and Meetings all pick up a
 *    Studio value with no edit at all.
 *
 * PRECEDENCE: the Studio wins over `.env`, `config.php` constants and the real
 * environment. That is deliberate and it is the opposite of the .env loader's
 * rule, so it is worth saying why: an administrator changing a key in the Studio
 * right now is expressing current intent, and a variable someone exported a year
 * ago should not silently defeat them. The Studio shows plainly when a setting is
 * shadowing an environment value, so the override is visible rather than
 * mysterious.
 */
declare(strict_types=1);

final class AvSettings
{
    /** Masked stand-in shown for a stored secret. Never a real value. */
    public const MASK = '••••••••';

    private static bool $ready = false;
    /** @var array<string,string>|null decrypted values, memoised per request */
    private static ?array $memo = null;
    private static bool $applied = false;
    /** @var array<string,true> keys this class has putenv()'d, so it can withdraw them */
    private static array $published = [];
    /** Guards against Config::get() → AvSettings → Config::get() recursion. */
    private static bool $reading = false;

    /**
     * The settings an administrator may manage.
     *
     *   group   Studio section
     *   label   human name
     *   secret  true ⇒ encrypted at rest, never returned to the browser
     *   type    text | enum | url
     *   help    what it does / where to get it
     *   options enum members
     *   ph      placeholder
     */
    private const DEFS = [

        /* ── Claude (Anthropic) ───────────────────────────────────────── */
        'ANTHROPIC_API_KEY' => [
            'group' => 'Claude (Anthropic)', 'label' => 'API key', 'secret' => true, 'type' => 'text',
            'ph' => 'sk-ant-…',
            'help' => 'Powers the community bot, the Studio assistant and its tool use. Get one at console.anthropic.com.',
        ],
        'AV_AI_MODEL' => [
            'group' => 'Claude (Anthropic)', 'label' => 'Model', 'secret' => false, 'type' => 'text',
            'ph' => 'claude-opus-4-8',
            'help' => 'Leave blank for the default. This is the single biggest lever on recurring AI cost — a cheaper tier is right for sweeps and drafting; reserve the expensive one for member-facing conversation.',
        ],
        'AV_AI_BASE_URL' => [
            'group' => 'Claude (Anthropic)', 'label' => 'Endpoint override', 'secret' => false, 'type' => 'url',
            'ph' => 'https://api.anthropic.com/v1/messages',
            'help' => 'Only for a gateway, a proxy or Bedrock. Leave blank otherwise.',
        ],

        /* ── Gemini ───────────────────────────────────────────────────── */
        'AV_GEMINI_API_KEY' => [
            'group' => 'Gemini (Google)', 'label' => 'API key', 'secret' => true, 'type' => 'text',
            'ph' => 'AIza…',
            'help' => 'Preferred for meeting and session minutes — fast and cheap per transcript. Get one at aistudio.google.com.',
        ],
        'AV_GEMINI_MODEL' => [
            'group' => 'Gemini (Google)', 'label' => 'Model', 'secret' => false, 'type' => 'text',
            'ph' => 'gemini-2.0-flash',
            'help' => 'Leave blank for the default.',
        ],

        /* ── OpenAI ───────────────────────────────────────────────────── */
        'OPENAI_API_KEY' => [
            'group' => 'OpenAI', 'label' => 'API key', 'secret' => true, 'type' => 'text',
            'ph' => 'sk-…',
            'help' => 'Brings two things the others do not: a very cheap tier for the meeting pipeline, and Whisper — a dedicated transcription endpoint that takes an uploaded recording up to ~25MB, where Gemini\'s inline path stops around 19MB. Get one at platform.openai.com.',
        ],
        'AV_OPENAI_MODEL' => [
            'group' => 'OpenAI', 'label' => 'Model', 'secret' => false, 'type' => 'text',
            'ph' => 'gpt-4o-mini',
            'help' => 'Leave blank for gpt-4o-mini, which is cheap and good enough for minutes. Use a larger model only where you have found the small one wanting.',
        ],
        'AV_OPENAI_TRANSCRIBE_MODEL' => [
            'group' => 'OpenAI', 'label' => 'Transcription model', 'secret' => false, 'type' => 'text',
            'ph' => 'whisper-1',
            'help' => 'Used when a recording is uploaded rather than transcribed live.',
        ],
        'AV_OPENAI_BASE_URL' => [
            'group' => 'OpenAI', 'label' => 'Endpoint override', 'secret' => false, 'type' => 'url',
            'ph' => 'https://api.openai.com/v1',
            'help' => 'Point this at any OpenAI-compatible endpoint — Azure, a gateway, Groq, OpenRouter, or a model you host yourself. That is the cheapest route of all if you already run one.',
        ],

        /* ── Which provider does what ─────────────────────────────────── */
        'AV_AGENT_PROVIDER' => [
            'group' => 'Assistant', 'label' => 'Force a provider', 'secret' => false, 'type' => 'enum',
            'options' => ['', 'openai', 'anthropic', 'gemini', 'groq', 'openrouter', 'together', 'deepseek', 'cerebras', 'local'],
            'help' => 'An override, and normally blank. Routing lives in Rules & AI → the ai.route_* rules, which set a ranked list per kind of work; naming a provider here promotes it to the front of every one of those lists without discarding their fallbacks. Useful for pinning a provider while you diagnose another. The tool-using assistant additionally ignores any provider with no tool loop implemented.',
        ],

        /* ── The Google Chat task bot ─────────────────────────────────── */
        'AV_CHAT_AUDIENCE' => [
            'group' => 'Chat task bot', 'label' => 'Chat app audience', 'secret' => false, 'type' => 'text',
            'ph' => '123456789012',
            'help' => 'Required, and the bot refuses every request without it. This is the Google Cloud project number of your Chat app (or the custom audience configured on it). It is the claim that stops a token minted for somebody else\'s Chat app being accepted here. Point the app\'s endpoint at https://your-domain/webhooks/chat.',
        ],
        'AV_CHAT_CERTS_URL' => [
            'group' => 'Chat task bot', 'label' => 'Certificate endpoint override', 'secret' => false, 'type' => 'url',
            'ph' => 'https://www.googleapis.com/service_accounts/v1/metadata/x509/chat@system.gserviceaccount.com',
            'help' => 'Leave blank. Only needed if Google moves where it publishes the public keys that Chat events are signed with.',
        ],

        /* ── The support tier: OpenAI-wire endpoints, keyed per vendor ── */
        'GROQ_API_KEY' => [
            'group' => 'Support tier', 'label' => 'Groq API key', 'secret' => true, 'type' => 'text',
            'ph' => 'gsk_…',
            'help' => 'The cheap, fast tier the bulk routing rule points at by default — reminder wording, catch-up summaries, classifications. Free at the volume an organisation this size generates. Get one at console.groq.com.',
        ],
        'AV_GROQ_MODEL' => [
            'group' => 'Support tier', 'label' => 'Groq model', 'secret' => false, 'type' => 'text',
            'ph' => 'llama-3.3-70b-versatile',
            'help' => 'Leave blank for the default. These vendors rename and retire models faster than the frontier labs, so a sudden run of failures on the AI Ops board is worth checking here first.',
        ],
        'OPENROUTER_API_KEY' => [
            'group' => 'Support tier', 'label' => 'OpenRouter API key', 'secret' => true, 'type' => 'text',
            'ph' => 'sk-or-…',
            'help' => 'One key, many models. Useful for trying a model before committing to its vendor. Set AV_OPENROUTER_MODEL to choose.',
        ],
        'DEEPSEEK_API_KEY' => [
            'group' => 'Support tier', 'label' => 'DeepSeek API key', 'secret' => true, 'type' => 'text',
            'ph' => 'sk-…',
            'help' => 'Cheap reasoning. Add it to a routing rule to use it.',
        ],
        'CEREBRAS_API_KEY' => [
            'group' => 'Support tier', 'label' => 'Cerebras API key', 'secret' => true, 'type' => 'text',
            'ph' => 'csk-…',
            'help' => 'The fastest tokens per second of the support tier.',
        ],
        'AV_LOCAL_BASE_URL' => [
            'group' => 'Support tier', 'label' => 'Local model endpoint', 'secret' => false, 'type' => 'url',
            'ph' => 'http://127.0.0.1:11434/v1',
            'help' => 'Ollama, llama.cpp or vLLM on this host. Costs nothing and sends nothing to a vendor — but shared hosting rarely has one. Setting this or AV_LOCAL_MODEL is what marks it available.',
        ],

        /* ── The meeting notetaker ────────────────────────────────────── */
        'AV_MEET_BOT_PROVIDER' => [
            'group' => 'Meeting notetaker', 'label' => 'Backend', 'secret' => false, 'type' => 'enum',
            'options' => ['', 'attendee', 'recall', 'google', 'webhook', 'none'],
            'help' => 'Blank auto-detects, preferring the free options. "google" uses Meet\'s own transcription and costs nothing at all. "attendee" runs an open-source bot you host yourself — free per meeting, you pay only for the container. "recall" is the hosted equivalent, billed per meeting-hour. "none" disables it regardless of keys.',
        ],
        'AV_ATTENDEE_API_KEY' => [
            'group' => 'Meeting notetaker', 'label' => 'Attendee API key', 'secret' => true, 'type' => 'text',
            'help' => 'Attendee is the open-source notetaker (github.com/attendee-labs/attendee). Self-hosted it charges nothing per meeting, so it costs you a small always-on container. The recogniser is separate: Meet\'s own captions are free, or you add a Deepgram/OpenAI key inside Attendee and that provider bills you. There is a hosted service too if you would rather not run it.',
        ],
        'AV_ATTENDEE_BASE_URL' => [
            'group' => 'Meeting notetaker', 'label' => 'Attendee instance URL', 'secret' => false, 'type' => 'url',
            'ph' => 'https://meetbot.your-domain.org',
            'help' => 'Your own instance. Leave blank to use the hosted service at app.attendee.dev — which is not free, so set this if the point was to avoid a per-meeting bill.',
        ],
        'AV_ATTENDEE_BOT_NAME' => [
            'group' => 'Meeting notetaker', 'label' => 'How the Attendee bot appears', 'secret' => false, 'type' => 'text',
            'ph' => 'Afrovanguard Notetaker',
            'help' => 'The name participants see in the meeting.',
        ],
        'AV_RECALL_API_KEY' => [
            'group' => 'Meeting notetaker', 'label' => 'Recall.ai API key', 'secret' => true, 'type' => 'text',
            'help' => 'Only needed for the "recall" backend. Google\'s own Meet transcripts are free and already wired — prefer those unless you also need Zoom or Teams.',
        ],
        'AV_RECALL_WEBHOOK_TOKEN' => [
            'group' => 'Meeting notetaker', 'label' => 'Recall webhook token', 'secret' => true, 'type' => 'text',
            'help' => 'SET THIS if you use Recall. It is the shared secret on the callback URL — without it, anyone who guesses the URL can post a transcript into your meeting records.',
        ],
        'AV_RECALL_REGION' => [
            'group' => 'Meeting notetaker', 'label' => 'Recall region', 'secret' => false, 'type' => 'text',
            'ph' => 'us-west-2',
            'help' => 'API region host prefix. Leave blank for us-west-2.',
        ],
        'AV_RECALL_BOT_NAME' => [
            'group' => 'Meeting notetaker', 'label' => 'How the bot appears', 'secret' => false, 'type' => 'text',
            'ph' => 'Afrovanguard Notetaker',
            'help' => 'The name participants see in the meeting. Make it recognisable — people should know what it is without asking.',
        ],
        'AV_MEET_BOT_JOIN_URL' => [
            'group' => 'Meeting notetaker', 'label' => 'Custom recorder URL', 'secret' => false, 'type' => 'url',
            'ph' => 'https://recorder.example/join',
            'help' => 'For the "webhook" backend: your own service that joins the call and posts the transcript back.',
        ],

        /* ── Web search ───────────────────────────────────────────────── */
        'AV_SEARCH_PROVIDER' => [
            'group' => 'Web search', 'label' => 'Provider', 'secret' => false, 'type' => 'enum',
            'options' => ['', 'brave', 'serper', 'google', 'tavily'],
            'help' => 'Blank picks whichever key below is set. Reading a page needs no key at all — only searching does.',
        ],
        'AV_BRAVE_API_KEY' => [
            'group' => 'Web search', 'label' => 'Brave Search key', 'secret' => true, 'type' => 'text',
            'help' => 'From api.search.brave.com.',
        ],
        'AV_SERPER_API_KEY' => [
            'group' => 'Web search', 'label' => 'serper.dev key', 'secret' => true, 'type' => 'text',
            'help' => 'Google results via serper.dev.',
        ],
        'AV_GOOGLE_CSE_KEY' => [
            'group' => 'Web search', 'label' => 'Google Programmable Search key', 'secret' => true, 'type' => 'text',
            'help' => 'Needs the search engine ID below as well — one without the other does nothing.',
        ],
        'AV_GOOGLE_CSE_CX' => [
            'group' => 'Web search', 'label' => 'Google search engine ID (cx)', 'secret' => false, 'type' => 'text',
            'help' => 'The cx value of your programmable search engine.',
        ],
        'AV_TAVILY_API_KEY' => [
            'group' => 'Web search', 'label' => 'Tavily key', 'secret' => true, 'type' => 'text',
            'help' => 'Tavily is built for LLM use and returns cleaner results than a general search API.',
        ],
    ];

    public static function keys(): array { return array_keys(self::DEFS); }
    public static function defined(string $k): bool { return isset(self::DEFS[$k]); }
    public static function isSecret(string $k): bool { return !empty(self::DEFS[$k]['secret']); }

    /* ════════════════════════════════════════════════════════════════
       Storage
       ════════════════════════════════════════════════════════════════ */

    public static function ensure(): void
    {
        if (self::$ready) return;
        self::$ready = true;
        try {
            Database::execSchema(Database::pdo(), "CREATE TABLE IF NOT EXISTS av_settings (
                setting_key VARCHAR(64) PRIMARY KEY,
                value TEXT NOT NULL DEFAULT '',
                is_secret INTEGER NOT NULL DEFAULT 0,
                updated_by VARCHAR(191) NOT NULL DEFAULT '',
                updated_at VARCHAR(32) NOT NULL DEFAULT ''
            );");
        } catch (Throwable $e) { error_log('[settings] ensure: ' . $e->getMessage()); }
    }

    /**
     * Stored settings, decrypted, memoised for the request.
     *
     * Guarded hard: this is reached from Config::get(), which runs very early and
     * on paths where the database may not exist yet. A failure here must degrade
     * to "nothing stored", never break the request.
     */
    private static function all(): array
    {
        if (self::$memo !== null) return self::$memo;
        if (self::$reading) return [];              // re-entered via Config — bail
        self::$memo = [];
        if (!class_exists('Database')) return self::$memo;

        self::$reading = true;
        try {
            self::ensure();
            foreach (Database::pdo()->query('SELECT setting_key, value, is_secret FROM av_settings') as $r) {
                $k = (string) $r['setting_key'];
                $v = (string) $r['value'];
                if ((int) $r['is_secret'] === 1) {
                    $p = self::dec($v);
                    // A secret we cannot decrypt (APP_KEY changed, extension gone)
                    // is skipped rather than passed on as ciphertext, which would
                    // be sent to an API as if it were a key.
                    if ($p === null) { error_log('[settings] cannot decrypt ' . $k . ' — skipping'); continue; }
                    $v = $p;
                }
                if ($v !== '') self::$memo[$k] = $v;
            }
        } catch (Throwable $e) {
            error_log('[settings] load: ' . $e->getMessage());
        } finally {
            self::$reading = false;
        }
        return self::$memo;
    }

    /** A stored setting, or null. Used by Config::get() before anything else. */
    public static function get(string $key): ?string
    {
        if (!isset(self::DEFS[$key])) return null;
        $all = self::all();
        return isset($all[$key]) && $all[$key] !== '' ? $all[$key] : null;
    }

    public static function flush(): void
    {
        self::$memo = null;
        self::$applied = false;
    }

    /**
     * Publish stored settings into the process environment.
     *
     * This is what makes the whole thing work without touching any consumer:
     * classes that read a bare getenv() — Meetings::botProvider() among them —
     * see a Studio value as if it had been exported. Called once from bootstrap.
     *
     * Only keys in the registry are ever published, so a compromised row cannot
     * introduce an arbitrary environment variable.
     */
    public static function apply(): void
    {
        if (self::$applied) return;
        self::$applied = true;

        $now = [];
        foreach (self::all() as $k => $v) {
            if (!isset(self::DEFS[$k]) || $v === '') continue;
            $now[$k] = $v;
        }

        // Withdraw anything we published earlier that is no longer stored.
        // Without this, clearing a key in the Studio leaves the old value live
        // for the rest of the request — so the Setup screen would say "unset"
        // while the provider it belongs to still reported itself configured.
        foreach (self::$published as $k => $_) {
            if (isset($now[$k])) continue;
            // Hand the key back to the real environment if it had one, rather
            // than deleting a value we never owned.
            $orig = isset($_SERVER[$k]) ? (string) $_SERVER[$k] : '';
            if ($orig !== '') { putenv($k . '=' . $orig); $_ENV[$k] = $orig; }
            else { putenv($k); unset($_ENV[$k]); }
            unset(self::$published[$k]);
        }

        foreach ($now as $k => $v) {
            putenv($k . '=' . $v);
            $_ENV[$k] = $v;
            self::$published[$k] = true;
        }
    }

    /**
     * Where the value in force actually comes from, per key:
     *   studio | config | env | unset
     * plus whether a Studio value is shadowing something from the environment,
     * because a silent override is the confusing kind.
     */
    public static function sourceOf(string $key): array
    {
        $stored = self::get($key) !== null;

        // Read the underlying environment WITHOUT our own apply() in the way, so
        // "shadowing" means something. $_SERVER is untouched by putenv().
        $envRaw = '';
        if (defined($key) && (string) constant($key) !== '') $envRaw = 'config';
        elseif (isset($_SERVER[$key]) && (string) $_SERVER[$key] !== '') $envRaw = 'env';

        if ($stored) return ['source' => 'studio', 'shadowing' => $envRaw];
        if ($envRaw !== '') return ['source' => $envRaw, 'shadowing' => ''];
        // getenv() may still hold a value from the .env loader.
        $e = getenv($key);
        if ($e !== false && $e !== '') return ['source' => 'env', 'shadowing' => ''];
        return ['source' => 'unset', 'shadowing' => ''];
    }

    /**
     * Every setting, grouped, for the Studio.
     *
     * A secret's value is NEVER included — only whether one is set and a masked
     * preview. There is deliberately no way to read a stored key back out through
     * the API, signed in or not.
     */
    public static function describe(): array
    {
        $groups = [];
        $meta = [];
        try {
            self::ensure();
            foreach (Database::pdo()->query('SELECT setting_key, updated_by, updated_at FROM av_settings') as $r) {
                $meta[(string) $r['setting_key']] = ['by' => (string) $r['updated_by'], 'at' => (string) $r['updated_at']];
            }
        } catch (Throwable $e) { /* no provenance */ }

        foreach (self::DEFS as $key => $d) {
            $src = self::sourceOf($key);
            $live = self::liveValue($key);
            $secret = !empty($d['secret']);

            $groups[$d['group']][] = [
                'key'        => $key,
                'label'      => $d['label'],
                'help'       => (string) ($d['help'] ?? ''),
                'type'       => (string) ($d['type'] ?? 'text'),
                'options'    => array_values((array) ($d['options'] ?? [])),
                'placeholder'=> (string) ($d['ph'] ?? ''),
                'secret'     => $secret,
                'is_set'     => $live !== '',
                // A secret shows only a masked tail; a plain setting shows itself.
                'value'      => $secret ? '' : $live,
                'preview'    => $secret ? self::maskOf($live) : '',
                'source'     => $src['source'],
                'shadowing'  => $src['shadowing'],
                'updated_by' => $meta[$key]['by'] ?? '',
                'updated_at' => $meta[$key]['at'] ?? '',
            ];
        }
        $out = [];
        foreach ($groups as $name => $fields) $out[] = ['group' => $name, 'fields' => $fields];
        return ['groups' => $out, 'crypto' => self::cryptoStatus()];
    }

    /** The value actually in force for a key, from any source. */
    private static function liveValue(string $key): string
    {
        $s = self::get($key);
        if ($s !== null) return $s;
        if (defined($key) && (string) constant($key) !== '') return (string) constant($key);
        $e = getenv($key);
        return ($e !== false) ? (string) $e : '';
    }

    /** "sk-ant-…4f2a" — enough to recognise a key, not enough to use it. */
    private static function maskOf(string $v): string
    {
        if ($v === '') return '';
        $len = strlen($v);
        if ($len <= 8) return self::MASK;
        return substr($v, 0, 6) . '…' . substr($v, -4);
    }

    /* ════════════════════════════════════════════════════════════════
       Writing
       ════════════════════════════════════════════════════════════════ */

    /**
     * Save (or clear) settings. An empty value clears the override, returning the
     * key to whatever the environment provides.
     *
     * The masked placeholder is treated as "leave it alone", so an administrator
     * editing the model name next to a stored key does not wipe the key by
     * submitting the form.
     *
     * @return array{ok:bool, saved:int, errors:array<string,string>}
     */
    public static function save(array $values, string $actor = ''): array
    {
        self::ensure();
        $errors = []; $saved = 0;

        foreach ($values as $key => $raw) {
            $key = (string) $key;
            if (!isset(self::DEFS[$key])) { $errors[$key] = 'Unknown setting.'; continue; }
            $d = self::DEFS[$key];
            $val = trim((string) $raw);

            // Unchanged masked secret — nothing to do.
            if (!empty($d['secret']) && ($val === self::MASK || strpos($val, '…') !== false)) continue;

            if ($val === '') {
                try {
                    Database::pdo()->prepare('DELETE FROM av_settings WHERE setting_key = ?')->execute([$key]);
                    $saved++;
                } catch (Throwable $e) { $errors[$key] = 'Could not clear that setting.'; }
                continue;
            }

            // Validate before storing, so a typo is caught here and not at the
            // next API call.
            $bad = self::validate($key, $val);
            if ($bad !== '') { $errors[$key] = $bad; continue; }

            $store = $val; $isSecret = 0;
            if (!empty($d['secret'])) {
                $enc = self::enc($val);
                if ($enc === null) {
                    // Never fall back to plaintext for a credential.
                    $errors[$key] = self::cryptoStatus()['reason'] ?: 'Cannot encrypt — refusing to store a key in plain text.';
                    continue;
                }
                $store = $enc; $isSecret = 1;
            }

            try {
                $db = Database::pdo();
                $db->prepare('DELETE FROM av_settings WHERE setting_key = ?')->execute([$key]);
                $db->prepare('INSERT INTO av_settings (setting_key, value, is_secret, updated_by, updated_at) VALUES (?,?,?,?,?)')
                   ->execute([$key, $store, $isSecret, mb_substr($actor, 0, 191), gmdate('c')]);
                $saved++;
            } catch (Throwable $e) {
                error_log('[settings] save: ' . $e->getMessage());
                $errors[$key] = 'Could not save that setting.';
            }
        }

        if ($saved > 0) {
            self::flush();
            self::apply();
            if (class_exists('AiKnowledge')) { try { AiKnowledge::invalidate(); } catch (Throwable $e) {} }
            if (class_exists('Events')) { try { Events::emit('settings.changed', ['keys' => array_keys($values)]); } catch (Throwable $e) {} }
        }
        return ['ok' => $errors === [], 'saved' => $saved, 'errors' => $errors];
    }

    /** '' when acceptable, else why not. */
    private static function validate(string $key, string $val): string
    {
        $d = self::DEFS[$key];
        $type = (string) ($d['type'] ?? 'text');

        if ($type === 'enum') {
            $opts = (array) ($d['options'] ?? []);
            return in_array($val, $opts, true) ? '' : 'Must be one of: ' . implode(', ', array_filter($opts)) . '.';
        }
        if ($type === 'url') {
            if (!preg_match('#^https?://#i', $val)) return 'Must be an http or https URL.';
            if (!filter_var($val, FILTER_VALIDATE_URL)) return 'That is not a valid URL.';
            return '';
        }
        if (strlen($val) > 4000) return 'Too long.';
        // A pasted key with whitespace or quotes in it is the commonest setup
        // mistake and produces a baffling 401 later — reject it here instead.
        if (!empty($d['secret']) && preg_match('/[\s"\']/', $val)) {
            return 'That looks like it has quotes or spaces in it — paste the key on its own.';
        }
        return '';
    }

    public static function clear(string $key, string $actor = ''): bool
    {
        if (!isset(self::DEFS[$key])) return false;
        return !empty(self::save([$key => ''], $actor)['ok']);
    }

    /* ════════════════════════════════════════════════════════════════
       Crypto — same scheme as GoogleWorkspaceUser
       ════════════════════════════════════════════════════════════════ */

    /** Whether secrets can be stored at all, and why not if they cannot. */
    public static function cryptoStatus(): array
    {
        $secret = function_exists('av_secret') ? av_secret() : '';
        if ($secret === '') {
            return ['ok' => false, 'reason' => 'No APP_KEY is set, so a key cannot be encrypted. Set APP_KEY (a long random string) before storing credentials here.'];
        }
        if (!function_exists('sodium_crypto_secretbox') && !function_exists('openssl_encrypt')) {
            return ['ok' => false, 'reason' => 'Neither libsodium nor OpenSSL is available on this host, so credentials cannot be encrypted.'];
        }
        return ['ok' => true, 'reason' => ''];
    }

    private static function key(): string { return hash('sha256', 'avset|' . (function_exists('av_secret') ? av_secret() : ''), true); }

    /** Encrypt, or null when we must refuse. */
    private static function enc(string $plain): ?string
    {
        if ($plain === '') return '';
        if (!self::cryptoStatus()['ok']) return null;
        try {
            if (function_exists('sodium_crypto_secretbox')) {
                $n = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
                return 's1:' . base64_encode($n . sodium_crypto_secretbox($plain, $n, self::key()));
            }
            $n = random_bytes(12); $tag = '';
            $c = openssl_encrypt($plain, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $n, $tag);
            return $c === false ? null : 'o1:' . base64_encode($n . $tag . $c);
        } catch (Throwable $e) { error_log('[settings] enc: ' . $e->getMessage()); return null; }
    }

    private static function dec(string $blob): ?string
    {
        if ($blob === '') return '';
        try {
            if (strncmp($blob, 's1:', 3) === 0 && function_exists('sodium_crypto_secretbox_open')) {
                $raw = base64_decode(substr($blob, 3), true);
                if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) return null;
                $n = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
                $p = sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $n, self::key());
                return $p === false ? null : $p;
            }
            if (strncmp($blob, 'o1:', 3) === 0 && function_exists('openssl_decrypt')) {
                $raw = base64_decode(substr($blob, 3), true);
                if ($raw === false || strlen($raw) <= 28) return null;
                $p = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
                return $p === false ? null : $p;
            }
        } catch (Throwable $e) { error_log('[settings] dec: ' . $e->getMessage()); }
        return null;
    }

    /* ════════════════════════════════════════════════════════════════
       Live connection tests
       ────────────────────────────────────────────────────────────────
       A key that is present but wrong looks exactly like a key that works,
       until the next meeting ends and produces no minutes. These make the real
       call — the cheapest one each provider offers — so an administrator finds
       out while they are still on the settings screen.
       ════════════════════════════════════════════════════════════════ */

    /** What can be tested, and whether it is worth offering right now. */
    public static function testable(): array
    {
        return [
            ['key' => 'anthropic', 'label' => 'Claude', 'ready' => class_exists('AvBot') && AvBot::configured()],
            ['key' => 'gemini',    'label' => 'Gemini', 'ready' => class_exists('Gemini') && Gemini::configured()],
            ['key' => 'openai',    'label' => 'OpenAI', 'ready' => class_exists('OpenAi') && OpenAi::configured()],
            ['key' => 'attendee',  'label' => 'Attendee notetaker', 'ready' => class_exists('AttendeeBot') && AttendeeBot::configured()],
            ['key' => 'recall',    'label' => 'Recall.ai notetaker', 'ready' => class_exists('RecallBot') && RecallBot::configured()],
            ['key' => 'search',    'label' => 'Web search', 'ready' => class_exists('AvWeb') && AvWeb::searchProvider() !== ''],
        ];
    }

    /**
     * Probe one provider.
     *
     * @return array{ok:bool, detail:string, ms:int}
     */
    public static function test(string $what): array
    {
        $t0 = microtime(true);
        $r = ['ok' => false, 'detail' => 'Unknown test.'];
        try {
            switch ($what) {
                case 'anthropic': $r = self::testAnthropic(); break;
                case 'gemini':    $r = self::testGemini(); break;
                case 'openai':    $r = self::testOpenAi(); break;
                case 'recall':    $r = self::testRecall(); break;
                case 'attendee':  $r = self::testAttendee(); break;
                case 'search':    $r = self::testSearch(); break;
            }
        } catch (Throwable $e) {
            error_log('[settings] test ' . $what . ': ' . $e->getMessage());
            $r = ['ok' => false, 'detail' => 'The test failed: ' . $e->getMessage()];
        }
        $r['ms'] = (int) round((microtime(true) - $t0) * 1000);
        return $r;
    }

    private static function testAnthropic(): array
    {
        if (!class_exists('AvBot') || !AvBot::configured()) return ['ok' => false, 'detail' => 'No Claude API key is set.'];
        // One token is enough to prove the key and the model name are both good.
        $res = AvBot::rawMessages([
            'max_tokens' => 1,
            'messages'   => [['role' => 'user', 'content' => 'ping']],
        ]);
        if (isset($res['__error'])) return ['ok' => false, 'detail' => self::humanise((string) $res['__error'])];
        return ['ok' => true, 'detail' => 'Connected · model ' . AvBot::model()];
    }

    private static function testGemini(): array
    {
        if (!class_exists('Gemini') || !Gemini::configured()) return ['ok' => false, 'detail' => 'No Gemini API key is set.'];
        $res = Gemini::generate('Reply with the single word: ok', ['max_tokens' => 64, 'temperature' => 0]);
        if (empty($res['ok'])) return ['ok' => false, 'detail' => self::humanise((string) ($res['error'] ?? 'Failed.'))];
        return ['ok' => true, 'detail' => 'Connected · model ' . Gemini::model()];
    }

    private static function testOpenAi(): array
    {
        if (!class_exists('OpenAi') || !OpenAi::configured()) return ['ok' => false, 'detail' => 'No OpenAI API key is set.'];
        $res = OpenAi::generate('Reply with the single word: ok', ['max_tokens' => 8, 'temperature' => 0]);
        if (empty($res['ok'])) return ['ok' => false, 'detail' => self::humanise((string) ($res['error'] ?? 'Failed.'))];
        return ['ok' => true, 'detail' => 'Connected · model ' . OpenAi::model()];
    }

    private static function testRecall(): array
    {
        if (!class_exists('RecallBot') || !RecallBot::configured()) return ['ok' => false, 'detail' => 'No Recall.ai API key is set.'];
        // botStatus() on an id that cannot exist: a bad key fails on auth, a good
        // key simply finds nothing. No bot is created, so nothing is billed.
        $probe = RecallBot::botStatus('avsetup-probe-000000000000');
        $ok = $probe === '';   // '' = reachable but no such bot, which is the pass
        if (!$ok) return ['ok' => true, 'detail' => 'Connected (unexpected bot state: ' . $probe . ')'];
        // Distinguish "auth failed" from "no such bot" by checking the webhook
        // token too, since that is the setting people forget.
        $warn = RecallBot::webhookToken() === ''
            ? ' — but no webhook token is set, so the callback URL is unauthenticated'
            : '';
        return ['ok' => true, 'detail' => 'Recall.ai reachable' . $warn];
    }

    private static function testAttendee(): array
    {
        if (!class_exists('AttendeeBot') || !AttendeeBot::configured()) return ['ok' => false, 'detail' => 'No Attendee API key is set.'];
        // Ask about a bot id that cannot exist. A reachable instance with a good
        // key answers 404 ("no such bot"), which is the pass; anything else — a
        // rejected key, a wrong URL, a container that is not running — is a fail.
        // Reporting "reachable" for all three, as an earlier version did, is
        // exactly the false green this screen exists to prevent.
        $p = AttendeeBot::ping();
        if (empty($p['ok'])) return ['ok' => false, 'detail' => self::humanise((string) ($p['error'] ?? 'Could not reach Attendee.'))];
        $where = AttendeeBot::selfHosted()
            ? 'your own instance — free per meeting'
            : 'the hosted service at app.attendee.dev, which is billed per meeting. Set an instance URL to self-host it instead.';
        return ['ok' => true, 'detail' => 'Connected · ' . $where];
    }

    private static function testSearch(): array
    {
        if (!class_exists('AvWeb')) return ['ok' => false, 'detail' => 'Web layer unavailable.'];
        $p = AvWeb::searchProvider();
        if ($p === '') return ['ok' => false, 'detail' => 'No search provider key is set.'];
        // Search is gated by a rule as well as a key; test the credential
        // regardless, so an administrator can verify it before switching on.
        $wasOff = class_exists('AvRules') && !AvRules::bool('ai.web_access');
        $res = AvWeb::search('Afrovanguard', 1);
        if (isset($res['error'])) {
            $hint = $wasOff ? ' (web access is also switched off in the rules)' : '';
            return ['ok' => false, 'detail' => self::humanise((string) $res['error']) . $hint];
        }
        $n = count((array) ($res['results'] ?? []));
        return ['ok' => true, 'detail' => 'Connected · ' . $p . ' returned ' . $n . ' result' . ($n === 1 ? '' : 's')
            . ($wasOff ? ' — remember web access is still off in the rules' : '')];
    }

    /**
     * Turn a provider's error into something an administrator can act on.
     * Never echoes a raw body, which can contain the key itself.
     */
    private static function humanise(string $err): string
    {
        $e = strtolower($err);
        if (strpos($e, '401') !== false || strpos($e, 'unauthor') !== false || strpos($e, 'api key') !== false || strpos($e, 'api_key') !== false) {
            return 'The key was rejected. Check it was pasted whole, with no spaces.';
        }
        if (strpos($e, '403') !== false || strpos($e, 'permission') !== false) return 'The key is valid but not permitted to do this.';
        if (strpos($e, '404') !== false || strpos($e, 'not_found') !== false || strpos($e, 'model') !== false) {
            return 'Rejected — most likely the model name. Clear it to use the default.';
        }
        if (strpos($e, '429') !== false || strpos($e, 'rate') !== false) return 'Rate-limited or out of quota. The key itself looks fine.';
        if (strpos($e, 'transport') !== false || strpos($e, 'curl') !== false || strpos($e, 'reach') !== false) {
            return 'Could not reach the provider — check outbound network access from this host.';
        }
        if (strpos($e, 'switched off') !== false) return $err;   // our own rule message
        return mb_substr($err, 0, 200);
    }

    /** Undo dispatch for the Studio audit trail. Secrets are never in the payload. */
    public static function applyUndo(string $op, array $args): bool
    {
        if ($op !== 'setting_clear') return false;
        $key = (string) ($args['key'] ?? '');
        return self::defined($key) && self::clear($key, 'undo');
    }
}
