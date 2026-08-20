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

    /** Total characters of thread + question sent as the user turn. */
    const MAX_PROMPT_CHARS   = 12000;
    /** Of that, the most the member's own question may take. Reserved first. */
    const MAX_QUESTION_CHARS = 4000;

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
        $knowledge = class_exists('AiKnowledge') ? AiKnowledge::asPromptBlock() : '';
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
        // The Studio master switch is enforced at the network call, so it covers
        // EVERY caller rather than only the ones that remember to ask. It is
        // deliberately not folded into configured(), which must keep reporting
        // truthfully on credentials for the System health page.
        if (class_exists('AvRules') && !AvRules::bool('ai.enabled')) {
            return ['ok' => false, 'text' => '', 'error' => 'AI assistance is switched off in the Studio rules.'];
        }
        if (!self::configured()) {
            return ['ok' => false, 'text' => '', 'error' => 'AI is not configured (set ANTHROPIC_API_KEY).'];
        }

        $payload = [
            'model'      => self::model(),
            'max_tokens' => max(64, min(2048, (int) ($opts['max_tokens'] ?? 1024))),
            'system'     => trim((string) ($opts['system'] ?? '')) !== '' ? (string) $opts['system'] : self::systemPrompt(),
            'messages'   => [['role' => 'user', 'content' => self::composePrompt($userText, $history)]],
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

    /**
     * Fold a thread and the member's question into one user turn.
     *
     * Public and pure so it can be tested without a provider — the bug it exists
     * to prevent is a string-assembly bug, and string assembly should not need
     * an API key to verify.
     *
     * It budgets the PARTS, not the composed whole. 40 turns × 1200 characters is
     * 48,000, and the question sits at the end of the string, so capping the
     * composed prompt cut from the end: a busy thread reached the model as a wall
     * of context with the member's actual question — and the instruction to
     * answer it — both gone. Nothing logged it; the model simply answered
     * whatever it could still see.
     *
     * So the question is reserved first, and the thread gets what is left, oldest
     * turns dropped whole until it fits. The result is bounded by construction to
     * MAX_PROMPT_CHARS; no caller should re-truncate it, because a truncating cap
     * is exactly what made the original fault invisible.
     */
    public static function composePrompt(string $userText, array $history = []): string
    {
        $userText = trim($userText);

        // Bound how many prior turns we fold in — a caller (especially the
        // integration API's bot.ask) could pass an arbitrarily long context, and
        // the budgeting below would otherwise rebuild a huge string per dropped
        // turn. Keep the most recent.
        if (count($history) > 40) $history = array_slice($history, -40);

        $lines = [];
        foreach ($history as $h) {
            if (!is_array($h)) continue;
            $who = (($h['role'] ?? '') === 'bot') ? 'Afrovanguard (you)' : ('Member' . (!empty($h['name']) ? ' ' . $h['name'] : ''));
            $txt = trim((string) ($h['text'] ?? ''));
            if ($txt !== '') $lines[] = $who . ': ' . mb_substr($txt, 0, 1200);
        }

        // With no thread, nothing is competing for the budget, so the text gets
        // all of it. This case is NOT hypothetical and the reserve must not be
        // applied to it: Meetings::structure(), Mentorship::structureSession()
        // and AvLab::complete() all hand an ~11,000-character transcript in here
        // with an empty history. Capping that at the question reserve would
        // truncate a meeting to its first few minutes — the same silent
        // shortening this method exists to stop.
        if (!$lines) return mb_substr($userText, 0, self::MAX_PROMPT_CHARS);

        // Only once a thread is competing does the question get a reserve.
        $question = mb_substr($userText, 0, self::MAX_QUESTION_CHARS);
        $head     = "Here is the community thread so far:\n\n";
        $tail     = "\nReply to the latest message:\n" . $question;
        $budget   = self::MAX_PROMPT_CHARS - mb_strlen($head) - mb_strlen($tail);

        $context = '';
        while ($lines) {
            $candidate = implode("\n", $lines) . "\n";
            if (mb_strlen($candidate) <= $budget) { $context = $candidate; break; }
            array_shift($lines);                  // drop the OLDEST turn, try again
        }
        // Every turn was dropped and it still would not fit: send the question
        // alone. A question with no thread is answerable; a thread with no
        // question is not.
        return $context !== '' ? $head . $context . $tail : $question;
    }

    /**
     * The raw Messages API, for callers that need more than one text turn —
     * principally AvAgent, which runs the tool-use loop and therefore has to
     * send assistant turns, tool_use blocks and tool_result blocks back.
     *
     * reply() stays the simple front door; this keeps authentication, the
     * endpoint and the master switch in one place instead of a second HTTP
     * client growing next to it.
     *
     * @return array decoded response, or ['__error'=>string]
     */
    public static function rawMessages(array $payload): array
    {
        if (class_exists('AvRules') && !AvRules::bool('ai.enabled')) {
            return ['__error' => 'AI assistance is switched off in the Studio rules.'];
        }
        if (!self::configured()) return ['__error' => 'AI is not configured (set ANTHROPIC_API_KEY).'];
        if (empty($payload['model'])) $payload['model'] = self::model();
        return self::http($payload);
    }

    /** POST the Messages API request. Returns the decoded body or ['__error'=>string]. */
    private static function http(array $payload): array
    {
        if (!function_exists('curl_init')) return ['__error' => 'curl unavailable'];
        // Encode before the handle exists, and check it. json_encode() returns
        // false on malformed UTF-8 anywhere in the payload; handed straight to
        // CURLOPT_POSTFIELDS that false becomes an empty body and the failure
        // surfaces as a baffling 400 from the provider instead of here.
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) return ['__error' => 'Could not encode the request: ' . json_last_error_msg()];
        $ch = curl_init(self::endpoint());
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_TIMEOUT        => 45,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_POSTFIELDS     => $json,
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
