<?php
/**
 * lib/AiCompat.php — every OpenAI-wire-compatible endpoint, as one client.
 *
 * Groq, OpenRouter, Together, DeepSeek, Cerebras and a local Ollama all speak
 * the same /chat/completions dialect OpenAi.php already implements. Writing a
 * class per vendor would be six copies of one HTTP call differing only in a
 * base URL, so instead they are a preset table: a new endpoint is a row here
 * plus an API key in config, not a new file.
 *
 * The point of them is the support tier. The primary provider answers the work
 * that needs judgement; these answer the high-volume, low-stakes work — a
 * reminder's wording, a classification, a one-line summary — at a fraction of
 * the cost, and several have a free tier that covers an organisation this size
 * outright. AvRouter decides which job goes where; this class only knows how to
 * talk to them.
 *
 * Deliberately NOT a general gateway: no tool calling, no transcription, no
 * streaming. Those live with the providers that do them well (OpenAi, AvBot,
 * Gemini). This is the cheap-completion path and nothing else, which is why it
 * stays under 200 lines.
 *
 * A NOTE ON THE MODEL DEFAULTS. Base URLs here are stable; model *names* are
 * not — these vendors deprecate and rename far faster than the frontier labs,
 * and a 404 on the model id is the first thing to check when one stops
 * answering. Every default below is overridable with AV_<HANDLE>_MODEL, and
 * the Studio's AI Ops board shows which model each provider is actually using
 * so a stale default is visible rather than mysterious.
 */
declare(strict_types=1);

final class AiCompat
{
    /**
     * handle => [label, base URL, config keys that hold the API key, default model]
     *
     * `keys` is a list because deployments name them differently; the first one
     * set wins. An endpoint with no key configured is simply not available —
     * except 'local', which is a loopback server and needs none.
     */
    private const PRESETS = [
        'groq' => [
            'label' => 'Groq',
            'base'  => 'https://api.groq.com/openai/v1',
            'keys'  => ['GROQ_API_KEY', 'AV_GROQ_API_KEY'],
            'model' => 'llama-3.3-70b-versatile',
            'note'  => 'Very fast, generous free tier. The support tier this deployment defaults to.',
        ],
        'openrouter' => [
            'label' => 'OpenRouter',
            'base'  => 'https://openrouter.ai/api/v1',
            'keys'  => ['OPENROUTER_API_KEY', 'AV_OPENROUTER_API_KEY'],
            'model' => 'meta-llama/llama-3.3-70b-instruct',
            'note'  => 'One key, many models. Useful for trying a model before committing to its vendor.',
        ],
        'together' => [
            'label' => 'Together',
            'base'  => 'https://api.together.xyz/v1',
            'keys'  => ['TOGETHER_API_KEY', 'AV_TOGETHER_API_KEY'],
            'model' => 'meta-llama/Llama-3.3-70B-Instruct-Turbo',
            'note'  => 'Open-weight hosting.',
        ],
        'deepseek' => [
            'label' => 'DeepSeek',
            'base'  => 'https://api.deepseek.com/v1',
            'keys'  => ['DEEPSEEK_API_KEY', 'AV_DEEPSEEK_API_KEY'],
            'model' => 'deepseek-chat',
            'note'  => 'Cheap reasoning.',
        ],
        'cerebras' => [
            'label' => 'Cerebras',
            'base'  => 'https://api.cerebras.ai/v1',
            'keys'  => ['CEREBRAS_API_KEY', 'AV_CEREBRAS_API_KEY'],
            'model' => 'llama-3.3-70b',
            'note'  => 'Fastest tokens per second of the lot.',
        ],
        'local' => [
            'label' => 'Local model',
            'base'  => 'http://127.0.0.1:11434/v1',
            'keys'  => [],   // Ollama and llama.cpp ignore the header entirely
            'model' => 'llama3.1',
            'note'  => 'Ollama, llama.cpp or vLLM on the same host. Costs nothing and leaves no data with a vendor — but shared hosting rarely has one.',
        ],
    ];

    /** Every handle this class knows, whether configured or not. */
    public static function handles(): array { return array_keys(self::PRESETS); }

    public static function knows(string $handle): bool { return isset(self::PRESETS[$handle]); }

    public static function label(string $handle): string
    {
        return (string) (self::PRESETS[$handle]['label'] ?? $handle);
    }

    public static function note(string $handle): string
    {
        return (string) (self::PRESETS[$handle]['note'] ?? '');
    }

    private static function cfg(string $k, string $def = ''): string
    {
        if (class_exists('Config') && Config::has($k)) return Config::str($k, $def);
        return (string) (getenv($k) ?: $def);
    }

    /** The env-var prefix a handle's overrides use: 'groq' → 'AV_GROQ_'. */
    private static function prefix(string $handle): string
    {
        return 'AV_' . strtoupper(preg_replace('/[^a-z0-9]+/i', '_', $handle) ?? '') . '_';
    }

    /** The first env var name that would enable this endpoint ('' if it needs none). */
    public static function primaryKeyName(string $handle): string
    {
        $p = self::PRESETS[$handle] ?? null;
        return $p && $p['keys'] !== [] ? (string) $p['keys'][0] : '';
    }

    public static function apiKey(string $handle): string
    {
        $p = self::PRESETS[$handle] ?? null;
        if (!$p) return '';
        foreach ((array) $p['keys'] as $k) {
            $v = self::cfg($k);
            if ($v !== '') return $v;
        }
        return '';
    }

    /**
     * A local server needs no key, so "configured" for it means "someone set a
     * base URL or a model on purpose". Otherwise every deployment would report
     * a loopback provider it does not have.
     */
    public static function configured(string $handle): bool
    {
        $p = self::PRESETS[$handle] ?? null;
        if (!$p) return false;
        if ($p['keys'] === []) {
            return self::cfg(self::prefix($handle) . 'BASE_URL') !== ''
                || self::cfg(self::prefix($handle) . 'MODEL') !== '';
        }
        return self::apiKey($handle) !== '';
    }

    public static function model(string $handle): string
    {
        $p = self::PRESETS[$handle] ?? null;
        if (!$p) return '';
        return self::cfg(self::prefix($handle) . 'MODEL', (string) $p['model']);
    }

    public static function base(string $handle): string
    {
        $p = self::PRESETS[$handle] ?? null;
        if (!$p) return '';
        return rtrim(self::cfg(self::prefix($handle) . 'BASE_URL', (string) $p['base']), '/');
    }

    /**
     * One completion. Same shape as OpenAi::generate() / Gemini::generate() /
     * AvBot::reply(), including the `usage` block AvRouter records — so the
     * router treats every provider identically and nothing here needs a
     * special case downstream.
     *
     * @return array{ok:bool,text:string,error:?string,usage:array{in:int,out:int},model:string}
     */
    public static function generate(string $handle, string $prompt, array $opts = []): array
    {
        $model = self::model($handle);
        $fail  = static fn(string $e): array =>
            ['ok' => false, 'text' => '', 'error' => $e, 'usage' => ['in' => 0, 'out' => 0], 'model' => $model];

        if (class_exists('AvRules') && !AvRules::bool('ai.enabled')) {
            return $fail('AI assistance is switched off in the Studio rules.');
        }
        if (!self::knows($handle))       return $fail('Unknown provider: ' . $handle);
        if (!self::configured($handle))  return $fail(self::label($handle) . ' is not configured.');
        if (trim($prompt) === '')        return $fail('Nothing to send.');

        $messages = [];
        $sys = trim((string) ($opts['system'] ?? ''));
        if ($sys !== '') $messages[] = ['role' => 'system', 'content' => $sys];
        if (class_exists('OpenAi')) {
            // Same wire, same conversion — borrowed rather than copied.
            foreach (OpenAi::historyMessages($opts['history'] ?? []) as $m) $messages[] = $m;
        }
        $messages[] = ['role' => 'user', 'content' => mb_substr($prompt, 0, 200000)];

        $res = self::http($handle, [
            'model'       => $model,
            'messages'    => $messages,
            'max_tokens'  => max(64, min(16384, (int) ($opts['max_tokens'] ?? 2048))),
            'temperature' => (float) ($opts['temperature'] ?? 0.2),
        ]);
        if (isset($res['__error'])) return $fail((string) $res['__error']);

        $usage = [
            'in'  => (int) ($res['usage']['prompt_tokens'] ?? 0),
            'out' => (int) ($res['usage']['completion_tokens'] ?? 0),
        ];
        $text = trim((string) ($res['choices'][0]['message']['content'] ?? ''));
        if ($text === '') {
            $refusal = (string) ($res['choices'][0]['message']['refusal'] ?? '');
            return ['ok' => false, 'text' => '', 'usage' => $usage, 'model' => $model,
                    'error' => $refusal !== '' ? 'declined: ' . $refusal : 'Empty ' . self::label($handle) . ' response.'];
        }
        return ['ok' => true, 'text' => $text, 'error' => null, 'usage' => $usage, 'model' => $model];
    }

    /** POST JSON. Returns the decoded body or ['__error'=>string]. Mirrors OpenAi::http(). */
    private static function http(string $handle, array $payload): array
    {
        if (!function_exists('curl_init')) return ['__error' => 'curl unavailable'];
        // Guarded encode: malformed UTF-8 anywhere in the payload otherwise
        // becomes an empty body and a baffling 400 from the far end. AI-AUDIT A-4.
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) return ['__error' => 'Could not encode the request: ' . json_last_error_msg()];

        $headers = ['content-type: application/json'];
        $key = self::apiKey($handle);
        if ($key !== '') $headers[] = 'authorization: Bearer ' . $key;

        $ch = curl_init(self::base($handle) . '/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_TIMEOUT        => 90,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_POSTFIELDS     => $json,
            CURLOPT_HTTPHEADER     => $headers,
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if (!is_string($body)) return ['__error' => 'transport: ' . $err];
        $d = json_decode($body, true);
        if (!is_array($d)) return ['__error' => 'bad response (HTTP ' . $code . ')'];
        if ($code >= 400) {
            error_log('[' . $handle . '] ' . $code . ': ' . substr($body, 0, 300));
            return ['__error' => (string) ($d['error']['message'] ?? ('HTTP ' . $code))];
        }
        return $d;
    }
}
