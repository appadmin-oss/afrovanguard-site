<?php
/**
 * lib/AvAgent.php — the loop that lets the AI use its tools.
 *
 * `AvBot::reply()` and `Gemini::generate()` are single-shot: text in, text out.
 * That is the right shape for a community reply and the wrong one for a question
 * like "is Ada ready for Level A?", where the honest answer requires going and
 * reading her mentorship record first.
 *
 * This class runs the loop. It sends the tool specs from AvTools, and when the
 * model asks for a tool it executes it, feeds the result back, and asks again —
 * until the model answers in prose or the turn ceiling is reached.
 *
 * All three providers are supported because deployments have one or another:
 * Anthropic tool-use is preferred (it handles multi-step tool chains best), then
 * OpenAI tool-calling, then Gemini function-calling. The wire formats are
 * genuinely different — different envelopes, different names for the same idea,
 * arguments as a JSON string in one and an object in the others, and Gemini
 * refuses a schema with no properties — so each has its own translation and
 * AvTools stays provider-neutral above them.
 *
 * Everything the model did is returned in `steps`, so the Studio can show the
 * working rather than only the conclusion. An assistant that says "Ada isn't
 * ready" is much easier to trust — or to catch out — when you can see which
 * records it actually read.
 *
 * Caps: turns come from ai.max_tool_turns, and a tool result is truncated before
 * it goes back to the model, so one enormous web page cannot blow the context.
 */
declare(strict_types=1);

final class AvAgent
{
    /** How much of a single tool result is fed back to the model. */
    private const MAX_RESULT_CHARS = 16000;

    /** Is any provider able to run a tool loop right now? */
    public static function available(): bool
    {
        if (class_exists('AvRules') && !AvRules::bool('ai.enabled')) return false;
        return (class_exists('AvBot') && AvBot::configured())
            || (class_exists('OpenAi') && OpenAi::configured())
            || (class_exists('Gemini') && Gemini::configured());
    }

    /** Which provider the loop would use ('' when none). */
    public static function provider(): string
    {
        $forced = class_exists('Config') ? strtolower(Config::str('AV_AGENT_PROVIDER', '')) : '';
        if ($forced === 'anthropic' && class_exists('AvBot') && AvBot::configured()) return 'anthropic';
        if ($forced === 'openai' && class_exists('OpenAi') && OpenAi::configured()) return 'openai';
        if ($forced === 'gemini' && class_exists('Gemini') && Gemini::configured()) return 'gemini';
        // Claude first (best multi-step tool chains), then OpenAI, then Gemini.
        if (class_exists('AvBot') && AvBot::configured()) return 'anthropic';
        if (class_exists('OpenAi') && OpenAi::configured()) return 'openai';
        if (class_exists('Gemini') && Gemini::configured()) return 'gemini';
        return '';
    }

    /**
     * Which tool tiers a caller should get, given the rules. Centralised so the
     * chat console, the test console and any future caller agree — and so
     * switching a rule off actually removes the capability everywhere.
     */
    public static function tiersFor(string $context = 'admin'): array
    {
        if (class_exists('AvRules') && !AvRules::bool('ai.tools')) return [];
        $tiers = ['read'];
        if (!class_exists('AvRules') || AvRules::bool('ai.web_access')) $tiers[] = 'web';
        // Only an administrator's own session may file proposals — a member-facing
        // assistant has no business suggesting changes to the constitution.
        if ($context === 'admin' && (!class_exists('AvRules') || AvRules::bool('ai.self_improve'))) $tiers[] = 'propose';
        return $tiers;
    }

    /**
     * Run a conversation with tools.
     *
     * $opts:
     *   system   string  system prompt (defaults to the assistant persona)
     *   history  array   prior turns [['role'=>'user'|'assistant','text'=>...]]
     *   tiers    array   tool tiers to grant (see tiersFor())
     *   actor    string  who is asking, recorded on any proposal filed
     *   max_turns int    override ai.max_tool_turns
     *
     * @return array{ok:bool,text:string,steps:array,turns:int,provider:string,error:?string}
     */
    public static function run(string $userText, array $opts = []): array
    {
        $userText = trim($userText);
        $out = ['ok' => false, 'text' => '', 'steps' => [], 'turns' => 0, 'provider' => '', 'error' => null];
        if ($userText === '') { $out['error'] = 'Ask something.'; return $out; }

        $provider = self::provider();
        $out['provider'] = $provider;
        if ($provider === '') {
            $out['error'] = 'No AI provider is configured (set ANTHROPIC_API_KEY, OPENAI_API_KEY or AV_GEMINI_API_KEY).';
            return $out;
        }

        $tiers = (array) ($opts['tiers'] ?? self::tiersFor('admin'));
        $names = class_exists('AvTools') ? AvTools::available($tiers) : [];
        $specs = $names ? AvTools::specs($names) : [];

        $maxTurns = (int) ($opts['max_turns'] ?? 0);
        if ($maxTurns <= 0) $maxTurns = class_exists('AvRules') ? AvRules::int('ai.max_tool_turns') : 6;
        $maxTurns = max(1, min(20, $maxTurns));

        $ctx = ['actor' => (string) ($opts['actor'] ?? 'ai'), 'tiers' => $tiers];
        $system = trim((string) ($opts['system'] ?? '')) !== '' ? (string) $opts['system'] : self::defaultSystem();

        $history = (array) ($opts['history'] ?? []);
        if ($provider === 'anthropic') return self::runAnthropic($userText, $system, $specs, $ctx, $maxTurns, $history, $out);
        if ($provider === 'openai')    return self::runOpenAi($userText, $system, $specs, $ctx, $maxTurns, $history, $out);
        return self::runGemini($userText, $system, $specs, $ctx, $maxTurns, $history, $out);
    }

    /** The persona used when a caller does not supply one. */
    public static function defaultSystem(): string
    {
        $s = class_exists('AvPrompts') && AvPrompts::isKey('assistant.console')
            ? AvPrompts::render('assistant.console', [])
            : 'You are the Afrovanguard accountability assistant. Answer from the tools, never from assumption.';
        return $s;
    }

    /**
     * One-shot completion on whichever provider this deployment has — no tools,
     * no loop, just system + user in and text out.
     *
     * This exists because the fallback chain had been copied into six places
     * (Meetings, Mentorship, Community, AvLab, Collab and Accountability), each
     * with its own provider order, and none of them honouring AV_AGENT_PROVIDER —
     * so the Studio's "provider" setting silently governed only the tool loop.
     * AI-AUDIT.md A-13. Routing through provider() fixes that for every caller
     * that adopts this; the older five can migrate one at a time.
     *
     * @return array{ok:bool,text:string,provider:string,error:?string}
     */
    public static function complete(string $system, string $user, array $opts = []): array
    {
        $out = ['ok' => false, 'text' => '', 'provider' => '', 'error' => null];
        if (trim($user) === '') { $out['error'] = 'Nothing to send.'; return $out; }

        $maxTok = (int) ($opts['max_tokens'] ?? (class_exists('AvRules') ? AvRules::int('ai.max_tokens') : 2048));
        $temp   = (float) ($opts['temperature'] ?? 0.2);

        // provider() order, then the other two as fallbacks — a brief that fails
        // because the preferred provider is down is a brief nobody reads.
        $order = array_values(array_unique(array_filter([self::provider(), 'anthropic', 'openai', 'gemini'])));
        $err   = '';
        foreach ($order as $p) {
            try {
                if ($p === 'gemini' && class_exists('Gemini') && Gemini::configured()) {
                    $r = Gemini::generate($user, ['system' => $system, 'max_tokens' => $maxTok, 'temperature' => $temp]);
                } elseif ($p === 'openai' && class_exists('OpenAi') && OpenAi::configured()) {
                    $r = OpenAi::generate($user, ['system' => $system, 'max_tokens' => $maxTok, 'temperature' => $temp]);
                } elseif ($p === 'anthropic' && class_exists('AvBot') && AvBot::configured()) {
                    // AvBot caps its own user turn; hand it a bounded prompt.
                    $r = AvBot::reply(mb_substr($user, 0, 11000), [], ['system' => $system, 'max_tokens' => min($maxTok, 2048)]);
                } else {
                    continue;
                }
            } catch (Throwable $e) { $err = $e->getMessage(); continue; }

            if (!empty($r['ok']) && trim((string) $r['text']) !== '') {
                return ['ok' => true, 'text' => trim((string) $r['text']), 'provider' => $p, 'error' => null];
            }
            $err = (string) ($r['error'] ?? $err);
        }
        $out['error'] = $err !== '' ? $err : 'No AI provider is configured.';
        return $out;
    }

    /* ════════════════════════════════════════════════════════════════
       Anthropic — tool_use / tool_result blocks
       ════════════════════════════════════════════════════════════════ */

    private static function runAnthropic(string $userText, string $system, array $specs, array $ctx, int $maxTurns, array $history, array $out): array
    {
        $messages = [];
        foreach (self::trimHistory($history) as $h) {
            $messages[] = ['role' => $h['role'], 'content' => $h['text']];
        }
        $messages[] = ['role' => 'user', 'content' => $userText];

        $maxTok = class_exists('AvRules') ? AvRules::int('ai.max_tokens') : 2048;

        for ($turn = 0; $turn < $maxTurns; $turn++) {
            $out['turns'] = $turn + 1;
            $payload = [
                'max_tokens' => max(256, min(8192, $maxTok)),
                'system'     => $system,
                'messages'   => $messages,
            ];
            if ($specs) $payload['tools'] = $specs;

            $res = AvBot::rawMessages($payload);
            if (isset($res['__error'])) { $out['error'] = (string) $res['__error']; return $out; }
            if (($res['stop_reason'] ?? '') === 'refusal') {
                $out['error'] = 'The model declined to answer that.';
                return $out;
            }

            $content = (array) ($res['content'] ?? []);
            $text = '';
            $calls = [];
            foreach ($content as $b) {
                if (($b['type'] ?? '') === 'text') $text .= (string) ($b['text'] ?? '');
                elseif (($b['type'] ?? '') === 'tool_use') {
                    $calls[] = ['id' => (string) ($b['id'] ?? ''), 'name' => (string) ($b['name'] ?? ''), 'input' => (array) ($b['input'] ?? [])];
                }
            }

            if (!$calls) {
                $out['ok'] = true;
                $out['text'] = trim($text);
                if ($out['text'] === '') { $out['ok'] = false; $out['error'] = 'The model returned nothing.'; }
                return $out;
            }

            // Echo the assistant turn back verbatim — the API requires the
            // tool_use blocks it sent to be present before their results.
            $messages[] = ['role' => 'assistant', 'content' => $content];

            $results = [];
            foreach ($calls as $c) {
                $r = self::execute($c['name'], $c['input'], $ctx, $out);
                $results[] = [
                    'type'        => 'tool_result',
                    'tool_use_id' => $c['id'],
                    'content'     => $r,
                ];
            }
            $messages[] = ['role' => 'user', 'content' => $results];
        }

        // Out of turns. Say so honestly rather than presenting partial work as
        // a finished answer.
        $out['error'] = 'Reached the tool limit (' . $maxTurns . ' round-trips) without a final answer. '
                      . 'Raise ai.max_tool_turns, or ask something narrower.';
        return $out;
    }

    /* ════════════════════════════════════════════════════════════════
       OpenAI — tool_calls / role:tool messages
       ════════════════════════════════════════════════════════════════ */

    private static function runOpenAi(string $userText, string $system, array $specs, array $ctx, int $maxTurns, array $history, array $out): array
    {
        $messages = [['role' => 'system', 'content' => $system]];
        foreach (self::trimHistory($history) as $h) {
            $messages[] = ['role' => $h['role'], 'content' => $h['text']];
        }
        $messages[] = ['role' => 'user', 'content' => $userText];

        $tools = [];
        foreach ($specs as $s) {
            $tools[] = ['type' => 'function', 'function' => [
                'name'        => $s['name'],
                'description' => $s['description'],
                'parameters'  => $s['input_schema'],
            ]];
        }
        $maxTok = class_exists('AvRules') ? AvRules::int('ai.max_tokens') : 2048;

        for ($turn = 0; $turn < $maxTurns; $turn++) {
            $out['turns'] = $turn + 1;
            $payload = [
                'messages'   => $messages,
                'max_tokens' => max(256, min(16384, $maxTok)),
            ];
            if ($tools) $payload['tools'] = $tools;

            $res = OpenAi::rawChat($payload);
            if (isset($res['__error'])) { $out['error'] = (string) $res['__error']; return $out; }

            $msg = (array) ($res['choices'][0]['message'] ?? []);
            $calls = (array) ($msg['tool_calls'] ?? []);

            if (!$calls) {
                $refusal = (string) ($msg['refusal'] ?? '');
                if ($refusal !== '') { $out['error'] = 'The model declined: ' . $refusal; return $out; }
                $out['ok'] = true;
                $out['text'] = trim((string) ($msg['content'] ?? ''));
                if ($out['text'] === '') { $out['ok'] = false; $out['error'] = 'The model returned nothing.'; }
                return $out;
            }

            // The assistant turn carrying the tool_calls must be echoed back
            // before their results, or the API rejects the follow-up.
            $messages[] = ['role' => 'assistant', 'content' => $msg['content'] ?? null, 'tool_calls' => $calls];

            foreach ($calls as $c) {
                $name = (string) ($c['function']['name'] ?? '');
                // Arguments arrive as a JSON *string*, unlike the other two providers.
                $args = json_decode((string) ($c['function']['arguments'] ?? '{}'), true);
                if (!is_array($args)) $args = [];
                $messages[] = [
                    'role'         => 'tool',
                    'tool_call_id' => (string) ($c['id'] ?? ''),
                    'content'      => self::execute($name, $args, $ctx, $out),
                ];
            }
        }

        $out['error'] = 'Reached the tool limit (' . $maxTurns . ' round-trips) without a final answer. '
                      . 'Raise ai.max_tool_turns, or ask something narrower.';
        return $out;
    }

    /* ════════════════════════════════════════════════════════════════
       Gemini — functionCall / functionResponse parts
       ════════════════════════════════════════════════════════════════ */

    private static function runGemini(string $userText, string $system, array $specs, array $ctx, int $maxTurns, array $history, array $out): array
    {
        $contents = [];
        foreach (self::trimHistory($history) as $h) {
            $contents[] = ['role' => $h['role'] === 'assistant' ? 'model' : 'user', 'parts' => [['text' => $h['text']]]];
        }
        $contents[] = ['role' => 'user', 'parts' => [['text' => $userText]]];

        $decls = [];
        foreach ($specs as $s) {
            $decls[] = [
                'name'        => $s['name'],
                'description' => $s['description'],
                'parameters'  => self::geminiSchema($s['input_schema']),
            ];
        }

        for ($turn = 0; $turn < $maxTurns; $turn++) {
            $out['turns'] = $turn + 1;
            $payload = [
                'contents' => $contents,
                'generationConfig' => [
                    'maxOutputTokens' => max(256, min(8192, class_exists('AvRules') ? AvRules::int('ai.max_tokens') : 2048)),
                    'temperature'     => 0.2,
                ],
                'systemInstruction' => ['parts' => [['text' => $system]]],
            ];
            if ($decls) $payload['tools'] = [['functionDeclarations' => $decls]];

            $res = Gemini::rawGenerate($payload);
            if (isset($res['__error'])) { $out['error'] = (string) $res['__error']; return $out; }
            if (!empty($res['promptFeedback']['blockReason'])) {
                $out['error'] = 'The model blocked that request (' . $res['promptFeedback']['blockReason'] . ').';
                return $out;
            }

            $parts = (array) ($res['candidates'][0]['content']['parts'] ?? []);
            $text = '';
            $calls = [];
            foreach ($parts as $p) {
                if (isset($p['text'])) $text .= (string) $p['text'];
                if (isset($p['functionCall'])) {
                    $calls[] = [
                        'name'  => (string) ($p['functionCall']['name'] ?? ''),
                        'input' => (array) ($p['functionCall']['args'] ?? []),
                    ];
                }
            }

            if (!$calls) {
                $out['ok'] = true;
                $out['text'] = trim($text);
                if ($out['text'] === '') { $out['ok'] = false; $out['error'] = 'The model returned nothing.'; }
                return $out;
            }

            $contents[] = ['role' => 'model', 'parts' => $parts];
            $responseParts = [];
            foreach ($calls as $c) {
                $r = self::execute($c['name'], $c['input'], $ctx, $out);
                $responseParts[] = [
                    'functionResponse' => [
                        'name'     => $c['name'],
                        // Gemini wants an object here; a bare string is rejected.
                        'response' => ['result' => $r],
                    ],
                ];
            }
            $contents[] = ['role' => 'user', 'parts' => $responseParts];
        }

        $out['error'] = 'Reached the tool limit (' . $maxTurns . ' round-trips) without a final answer. '
                      . 'Raise ai.max_tool_turns, or ask something narrower.';
        return $out;
    }

    /* ════════════════════════════════════════════════════════════════
       Shared
       ════════════════════════════════════════════════════════════════ */

    /**
     * Run one tool, record the step, and return the result as text for the model.
     *
     * The step log keeps the arguments and a bounded preview of the result, so
     * the Studio can show what the assistant actually consulted rather than
     * asking anyone to take its word for it.
     */
    private static function execute(string $name, array $input, array $ctx, array &$out): string
    {
        $result = class_exists('AvTools')
            ? AvTools::run($name, $input, $ctx)
            : ['error' => 'Tools are unavailable.'];

        $json = self::boundResult($result);

        $out['steps'][] = [
            'tool'    => $name,
            'args'    => $input,
            'ok'      => !isset($result['error']),
            'error'   => (string) ($result['error'] ?? ''),
            'preview' => mb_substr(preg_replace('/\s+/', ' ', $json) ?? '', 0, 400),
        ];
        return $json;
    }

    /**
     * A tool result as bounded text for the model.
     *
     * Public and pure so the encoding invariant can be tested without a provider.
     *
     * The cap is a BYTE budget — the point is to bound the request payload — but
     * it must be cut with mb_strcut, never substr. JSON_UNESCAPED_UNICODE emits
     * raw multi-byte UTF-8, and a byte-wise cut lands mid-character whenever the
     * boundary falls inside one; the damaged string then makes the ENTIRE request
     * unencodable, because json_encode() returns false on malformed UTF-8. The
     * provider received an empty body and answered with a baffling 400. One curly
     * quote in the wrong place on a fetched page was enough to trigger it.
     *
     * The truncated result is deliberately no longer valid JSON — the model reads
     * it as text. It must still be valid UTF-8.
     */
    public static function boundResult(array $result): string
    {
        $json = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) return '{"error":"Unserialisable tool result."}';
        if (strlen($json) > self::MAX_RESULT_CHARS) {
            $json = mb_strcut($json, 0, self::MAX_RESULT_CHARS, 'UTF-8') . '… [truncated]';
        }
        return $json;
    }

    /** Keep the last few turns, normalised and bounded. */
    private static function trimHistory(array $history): array
    {
        $out = [];
        foreach (array_slice($history, -20) as $h) {
            $role = ($h['role'] ?? '') === 'assistant' ? 'assistant' : 'user';
            $txt  = trim((string) ($h['text'] ?? ''));
            if ($txt === '') continue;
            $out[] = ['role' => $role, 'text' => mb_substr($txt, 0, 6000)];
        }
        return $out;
    }

    /**
     * JSON Schema → the subset Gemini accepts.
     *
     * Gemini rejects an object schema with no properties, and ignores several
     * JSON Schema keywords rather than erroring on them. Sending only type,
     * properties, description and required keeps the two providers fed from one
     * registry without a second set of schemas to maintain.
     */
    private static function geminiSchema(array $schema): array
    {
        $props = (array) ($schema['properties'] ?? []);
        if (!$props) {
            // A no-argument tool: give it one ignorable optional field, because a
            // properties-less object is refused outright.
            return [
                'type' => 'object',
                'properties' => ['_' => ['type' => 'string', 'description' => 'Unused. Send an empty string.']],
            ];
        }
        $clean = [];
        foreach ($props as $k => $v) {
            $clean[$k] = array_filter([
                'type'        => (string) ($v['type'] ?? 'string'),
                'description' => (string) ($v['description'] ?? ''),
            ], static fn($x) => $x !== '');
        }
        $o = ['type' => 'object', 'properties' => $clean];
        if (!empty($schema['required'])) $o['required'] = array_values((array) $schema['required']);
        return $o;
    }
}
