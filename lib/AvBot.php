<?php
/**
 * lib/AvBot.php — the official Afrovanguard bot's brain (Claude-powered).
 *
 * Turns the community bot from a scripted poster into an AI assistant: given a
 * member's question (and the surrounding thread), it asks Claude — speaking in
 * the Afrovanguard voice and bounded to the movement's mission — and returns a
 * concise reply the caller can post as @Afrovanguard.
 *
 * Dependency-free (raw HTTPS via cURL — this host has no Composer), and
 * graceful: if ANTHROPIC_API_KEY isn't set, configured() is false and reply()
 * returns ok=false with a friendly fallback so nothing breaks.
 *
 * Agent-integrable: reply() is a pure text function, and the integration API
 * (integrations/api.php → bot.ask) exposes it to trusted external agents, which
 * can also act THROUGH the bot via community.post / community.reply. So an
 * external Claude agent can both drive this bot and be answered by it.
 *
 * Config (env or config.php):
 *   ANTHROPIC_API_KEY   required to enable the AI; absent ⇒ scripted fallback
 *   AV_AI_MODEL         default claude-opus-4-8 (set claude-haiku-4-5 / -sonnet-4-6 to cut cost)
 *   AV_AI_BASE_URL      override the Messages endpoint (gateway/Bedrock/test mock)
 */
declare(strict_types=1);

final class AvBot
{
    const DEFAULT_ENDPOINT = 'https://api.anthropic.com/v1/messages';
    const API_VERSION      = '2023-06-01';
    const DEFAULT_MODEL    = 'claude-opus-4-8';

    public static function configured(): bool
    {
        return class_exists('Config') ? Config::has('ANTHROPIC_API_KEY') : (getenv('ANTHROPIC_API_KEY') ? true : false);
    }

    public static function model(): string
    {
        return class_exists('Config') ? Config::str('AV_AI_MODEL', self::DEFAULT_MODEL) : (getenv('AV_AI_MODEL') ?: self::DEFAULT_MODEL);
    }

    private static function endpoint(): string
    {
        $b = class_exists('Config') ? Config::str('AV_AI_BASE_URL', '') : (string) getenv('AV_AI_BASE_URL');
        return $b !== '' ? $b : self::DEFAULT_ENDPOINT;
    }

    private static function apiKey(): string
    {
        return class_exists('Config') ? Config::str('ANTHROPIC_API_KEY', '') : (string) getenv('ANTHROPIC_API_KEY');
    }

    /** The Afrovanguard persona + guardrails. Stable (good for prompt caching). */
    public static function systemPrompt(): string
    {
        $org = class_exists('Config') ? Config::str('AV_ORG_DOMAIN', 'afrovanguard.org.ng') : 'afrovanguard.org.ng';
        // Derived site facts, then leadership's written doctrine. Both are live:
        // editing an entry in Studio changes the bot's next reply.
        $knowledge = class_exists('AiKnowledge') ? AiKnowledge::asPromptBlock() : '';
        if (class_exists('AvKnowledge')) $knowledge .= AvKnowledge::asPromptBlock();
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
- Don't claim to perform actions you can't (you reply with text; you don't process payments, change accounts, or send email).{$knowledge}
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
            return ['ok' => false, 'text' => '', 'error' => 'AI is not configured (set ANTHROPIC_API_KEY).'];
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

        $payload = [
            'model'      => self::model(),
            'max_tokens' => max(64, min(2048, (int) ($opts['max_tokens'] ?? 1024))),
            'system'     => trim((string) ($opts['system'] ?? '')) !== '' ? (string) $opts['system'] : self::systemPrompt(),
            'messages'   => [['role' => 'user', 'content' => mb_substr($prompt, 0, 12000)]],
        ];

        $res = self::http($payload);
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

    /** POST the Messages API request. Returns the decoded body or ['__error'=>string]. */
    private static function http(array $payload): array
    {
        if (!function_exists('curl_init')) return ['__error' => 'curl unavailable'];
        $ch = curl_init(self::endpoint());
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_TIMEOUT        => 45,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER     => [
                'content-type: application/json',
                'x-api-key: ' . self::apiKey(),
                'anthropic-version: ' . self::API_VERSION,
            ],
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
            error_log('[avbot] ' . $code . ': ' . substr($body, 0, 300));
            return ['__error' => $msg];
        }
        return $d;
    }
}
