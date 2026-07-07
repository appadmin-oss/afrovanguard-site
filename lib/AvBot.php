<?php
/**
 * lib/AvBot.php — the official Afrovanguard bot's brain (AI-powered).
 *
 * Turns the community bot from a scripted poster into an AI assistant: given a
 * member's question (and the surrounding thread), it asks the configured AI —
 * speaking in the Afrovanguard voice and bounded to the movement's mission —
 * and returns a concise reply the caller can post as @Afrovanguard.
 *
 * Two providers are supported, chosen at runtime:
 *   • anthropic — Claude, via the native Messages API (api.anthropic.com).
 *   • groq      — Llama/Mixtral/Gemma, via Groq's OpenAI-compatible Chat
 *                 Completions API (api.groq.com). Fast and inexpensive.
 * Both go out as raw HTTPS via cURL (this host has no Composer), and the bot is
 * graceful: if the selected provider has no API key, configured() is false and
 * reply() returns ok=false with a friendly fallback so nothing breaks.
 *
 * Agent-integrable: reply() is a pure text function, and the integration API
 * (integrations/api.php → bot.ask) exposes it to trusted external agents, which
 * can also act THROUGH the bot via community.post / community.reply.
 *
 * Config (env or config.php):
 *   AV_AI_PROVIDER     anthropic | groq. Unset ⇒ auto-detect: prefer Anthropic
 *                      if ANTHROPIC_API_KEY is set, else Groq if GROQ_API_KEY is.
 *   ANTHROPIC_API_KEY  enables Claude (console.anthropic.com)
 *   GROQ_API_KEY       enables Groq (console.groq.com)
 *   AV_AI_MODEL        override the model. Default per provider:
 *                        anthropic ⇒ claude-opus-4-8
 *                        groq      ⇒ llama-3.3-70b-versatile
 *   AV_AI_BASE_URL     override the endpoint (gateway / Bedrock / proxy / test mock)
 */
declare(strict_types=1);

final class AvBot
{
    // Anthropic (Claude) — native Messages API.
    const ANTHROPIC_ENDPOINT = 'https://api.anthropic.com/v1/messages';
    const ANTHROPIC_VERSION  = '2023-06-01';
    const ANTHROPIC_MODEL    = 'claude-opus-4-8';

    // Groq — OpenAI-compatible Chat Completions API.
    const GROQ_ENDPOINT = 'https://api.groq.com/openai/v1/chat/completions';
    const GROQ_MODEL    = 'llama-3.3-70b-versatile';

    /* ── Provider selection ── */

    /** anthropic | groq. AV_AI_PROVIDER wins; else auto-detect from which key is set. */
    public static function provider(): string
    {
        $p = strtolower(trim(self::cfg('AV_AI_PROVIDER')));
        if ($p === 'groq' || $p === 'anthropic') return $p;
        if (self::keyPresent('ANTHROPIC_API_KEY')) return 'anthropic';
        if (self::keyPresent('GROQ_API_KEY'))      return 'groq';
        return 'anthropic';   // label for an unconfigured bot
    }

    /** True when the selected provider has its API key. */
    public static function configured(): bool
    {
        return self::keyPresent(self::provider() === 'groq' ? 'GROQ_API_KEY' : 'ANTHROPIC_API_KEY');
    }

    /** Model in use — AV_AI_MODEL override, else the provider default. */
    public static function model(): string
    {
        $m = self::cfg('AV_AI_MODEL');
        if ($m !== '') return $m;
        return self::provider() === 'groq' ? self::GROQ_MODEL : self::ANTHROPIC_MODEL;
    }

    private static function endpoint(): string
    {
        $b = self::cfg('AV_AI_BASE_URL');
        if ($b !== '') return $b;
        return self::provider() === 'groq' ? self::GROQ_ENDPOINT : self::ANTHROPIC_ENDPOINT;
    }

    private static function apiKey(): string
    {
        return self::cfg(self::provider() === 'groq' ? 'GROQ_API_KEY' : 'ANTHROPIC_API_KEY');
    }

    /* ── Small config helpers (constant → env, via Config when available) ── */
    private static function cfg(string $key, string $default = ''): string
    {
        if (class_exists('Config')) return Config::str($key, $default);
        $v = getenv($key);
        return ($v !== false && $v !== '') ? $v : $default;
    }
    private static function keyPresent(string $key): bool
    {
        if (class_exists('Config')) return Config::has($key);
        $v = getenv($key);
        return $v !== false && $v !== '';
    }

    /** The Afrovanguard persona + guardrails. Stable (good for prompt caching). */
    public static function systemPrompt(): string
    {
        $org = self::cfg('AV_ORG_DOMAIN', 'afrovanguard.org.ng');
        return <<<SYS
You are the official Afrovanguard community bot — a warm, encouraging Pan-African voice for Afrovanguard, a Nigerian-rooted nonprofit whose mission is to raise one million incorruptible African leaders by 2040 through community, technology and cultural advancement.

What Afrovanguard runs: the LCASP children's programme across Lagos community centres; the Academy (free, hands-on programmes — Techome, MediaPro, Africa GATES, Next Generation Genius); Street-To-Stardom; and a public Diary of the work. Members reach mentorship and members-only spaces with an @{$org} account.

How to respond:
- Speak in the first person as Afrovanguard. Be warm, direct, and genuinely helpful.
- Keep replies SHORT — 2–4 sentences for a community feed, unless asked for more.
- Stay on the movement: programmes, learning, leadership, civics, volunteering, events, the community.
- Encourage integrity, service and initiative — the heart of the movement.

Hard rules:
- NEVER invent specific facts you weren't given — dates, figures, names, links, prices. If you don't know, say so and point them to the team (contact via the site) or the relevant space.
- No legal, medical or financial advice. Don't make promises on behalf of staff.
- If a request is off-mission, harmful, or abusive, decline briefly and kindly and steer back to how you can help.
- Don't claim to perform actions you can't (you reply with text; you don't process payments, change accounts, or send email).
SYS;
    }

    /**
     * Generate a reply. $history is prior turns as ['role'=>'member'|'bot','name'=>?,'text'=>...]
     * (oldest first); they're folded into a single grounded user message so role
     * alternation can't break. Returns ['ok'=>bool,'text'=>string,'error'=>?string].
     */
    public static function reply(string $userText, array $history = [], array $opts = []): array
    {
        $userText = trim($userText);
        if ($userText === '') return ['ok' => false, 'text' => '', 'error' => 'Empty prompt.'];
        if (!self::configured()) {
            return ['ok' => false, 'text' => '', 'error' => 'AI is not configured (set ANTHROPIC_API_KEY or GROQ_API_KEY).'];
        }

        // Bound how many prior turns we fold in — a caller (esp. the integration
        // API's bot.ask) could pass an arbitrarily long context; keep the most
        // recent turns so we don't build a huge string before the prompt cap.
        if (count($history) > 40) $history = array_slice($history, -40);

        $context = '';
        foreach ($history as $h) {
            $who = (($h['role'] ?? '') === 'bot') ? 'Afrovanguard (you)' : ('Member' . (!empty($h['name']) ? ' ' . $h['name'] : ''));
            $txt = trim((string) ($h['text'] ?? ''));
            if ($txt !== '') $context .= $who . ': ' . mb_substr($txt, 0, 1200) . "\n";
        }
        $prompt = $context !== ''
            ? "Here is the community thread so far:\n\n" . $context . "\nReply to the latest message:\n" . $userText
            : $userText;

        $system    = trim((string) ($opts['system'] ?? '')) !== '' ? (string) $opts['system'] : self::systemPrompt();
        $maxTokens = max(64, min(2048, (int) ($opts['max_tokens'] ?? 1024)));

        return self::provider() === 'groq'
            ? self::completeGroq($system, mb_substr($prompt, 0, 12000), $maxTokens)
            : self::completeAnthropic($system, mb_substr($prompt, 0, 12000), $maxTokens);
    }

    /** Claude via the Anthropic Messages API. */
    private static function completeAnthropic(string $system, string $userPrompt, int $maxTokens): array
    {
        $payload = [
            'model'      => self::model(),
            'max_tokens' => $maxTokens,
            'system'     => $system,
            'messages'   => [['role' => 'user', 'content' => $userPrompt]],
        ];
        $res = self::http($payload, [
            'content-type: application/json',
            'x-api-key: ' . self::apiKey(),
            'anthropic-version: ' . self::ANTHROPIC_VERSION,
        ]);
        if (isset($res['__error'])) return ['ok' => false, 'text' => '', 'error' => $res['__error']];

        // Opus 4.8 can decline via stop_reason "refusal" (content empty/partial).
        if (($res['stop_reason'] ?? '') === 'refusal') {
            return ['ok' => false, 'text' => '', 'error' => 'declined', 'refusal' => true];
        }
        $text = '';
        foreach (($res['content'] ?? []) as $block) {
            if (($block['type'] ?? '') === 'text') $text .= (string) ($block['text'] ?? '');
        }
        $text = trim($text);
        if ($text === '') return ['ok' => false, 'text' => '', 'error' => 'Empty AI response.'];
        return ['ok' => true, 'text' => $text, 'error' => null];
    }

    /** Groq via its OpenAI-compatible Chat Completions API (system as a message). */
    private static function completeGroq(string $system, string $userPrompt, int $maxTokens): array
    {
        $payload = [
            'model'      => self::model(),
            'max_tokens' => $maxTokens,
            'messages'   => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user',   'content' => $userPrompt],
            ],
        ];
        $res = self::http($payload, [
            'content-type: application/json',
            'authorization: Bearer ' . self::apiKey(),
        ]);
        if (isset($res['__error'])) return ['ok' => false, 'text' => '', 'error' => $res['__error']];

        $text = trim((string) ($res['choices'][0]['message']['content'] ?? ''));
        if ($text === '') return ['ok' => false, 'text' => '', 'error' => 'Empty AI response.'];
        return ['ok' => true, 'text' => $text, 'error' => null];
    }

    /** POST a JSON request with the given headers. Returns the decoded body or ['__error'=>string]. */
    private static function http(array $payload, array $headers): array
    {
        if (!function_exists('curl_init')) return ['__error' => 'curl unavailable'];
        $ch = curl_init(self::endpoint());
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_TIMEOUT        => 45,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
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
            $msg = $d['error']['message'] ?? ('HTTP ' . $code);
            error_log('[avbot] ' . self::provider() . ' ' . $code . ': ' . substr($body, 0, 300));
            return ['__error' => $msg];
        }
        return $d;
    }
}
