<?php
/**
 * lib/Chioma.php — the brain behind Chioma, Afrovanguard's site guide.
 *
 * One reply pipeline used by BOTH the public widget (chioma.php) and the tokened
 * integration API (integrations/api.php → chioma.ask), so a browser visitor and
 * an external AI agent get the same Chioma.
 *
 * Reply sources, in order:
 *   1. YOUR AI Agent — if AV_CHIOMA_AGENT_URL is set, Chioma forwards the message
 *      (+ history, page context and her persona) to your agent's webhook and
 *      relays its reply. This is how you make Chioma *be* your own agent.
 *   2. Claude (AvBot) with Chioma's persona — if ANTHROPIC_API_KEY is set.
 *   3. A keyword-routed scripted reply — so she's always useful.
 *
 * Agent webhook contract (AV_CHIOMA_AGENT_URL):
 *   POST {message, history:[{role,text}], context:{title,path,section}, persona, source}
 *     (Authorization: Bearer AV_CHIOMA_AGENT_KEY  — sent when configured)
 *   ← any of: {reply|text|message|output|response|answer} or OpenAI
 *     {choices:[{message:{content}}]} or a plain-text body.
 */
declare(strict_types=1);

final class Chioma
{
    public static function agentUrl(): string { return trim((string) (getenv('AV_CHIOMA_AGENT_URL') ?: '')); }
    public static function agentConfigured(): bool
    {
        $u = self::agentUrl();
        return $u !== '' && filter_var($u, FILTER_VALIDATE_URL) !== false;
    }
    /** True when Chioma can produce a real AI reply (your agent or Claude). */
    public static function aiAvailable(): bool
    {
        return self::agentConfigured() || (class_exists('AvBot') && AvBot::configured());
    }

    public static function systemPrompt(array $ctx = []): string
    {
        $org = defined('AV_ORG_DOMAIN') ? AV_ORG_DOMAIN : 'afrovanguard.org.ng';
        $t = trim((string) ($ctx['title'] ?? '')); $p = trim((string) ($ctx['path'] ?? '')); $s = trim((string) ($ctx['section'] ?? ''));
        $ctxLine = ($t !== '' || $p !== '')
            ? "\n\nContext — the visitor is currently on: \"{$t}\" ({$p})" . ($s !== '' ? " in the \"{$s}\" section." : '.') . " Tailor your help to where they are when it's relevant."
            : '';
        return <<<SYS
You are Chioma — Afrovanguard's friendly, capable operations assistant for the website. Think of yourself as the warm, knowledgeable Nigerian big-sister on the front desk: you make every visitor feel at home, anticipate what they need, and get them to the right place quickly. You are lively but never fake; you're proud of the movement and genuinely glad to help.

Your job is to help visitors navigate the site, get involved, donate, find the right programme, or reach a real person. Be proactive: when you sense what someone is trying to do, offer the next step before they have to ask.

Afrovanguard is a Nigerian-rooted nonprofit raising one million incorruptible African leaders by 2040 through community, technology and cultural advancement. Key places you can guide people to:
- The Academy (/academy/) — free, hands-on programmes: Techome, MediaPro, Africa GATES, Next Generation Genius.
- Projects (/projects/) — Street-To-Stardom, LCASP children's programme, and more.
- The Diary (/diary/) — stories and dispatches from the work.
- Donate (/donate.html) — material donations are especially welcome, and monetary too.
- Contact (/contact/) — to reach the team; Membership for members-only spaces (an @{$org} account).

How you talk:
- Short and conversational — usually 1–3 sentences. Warm, plain, professional, a little playful. A tasteful emoji is fine, sparingly.
- Be genuinely useful: answer the question, then point to the right page or next step when it helps.
- When someone wants to act (enrol, donate, volunteer, contact), name the page and encourage them — and if they seem stuck, offer to connect them with the team via Contact.

Hard rules:
- NEVER invent specifics you weren't given — dates, figures, names, prices, links beyond the ones above. If unsure, say so kindly and point them to Contact.
- No legal/medical/financial advice; don't make promises for staff.
- If something is off-mission, harmful or abusive, decline briefly and warmly and steer back to how you can help.
- You reply with words only — you don't process payments, change accounts, or send email yourself.{$ctxLine}
SYS;
    }

    /**
     * Generate Chioma's reply.
     * @return array{ok:bool,reply:string,source:string}
     */
    public static function reply(string $message, array $history = [], array $ctx = []): array
    {
        $message = trim($message);
        if ($message === '') return ['ok' => false, 'reply' => '', 'source' => 'none'];
        $history = array_slice($history, -12);

        // 1) Your own AI Agent.
        if (self::agentConfigured()) {
            $r = self::delegate($message, $history, $ctx);
            if ($r !== null && trim($r) !== '') return ['ok' => true, 'reply' => trim($r), 'source' => 'agent'];
        }
        // 2) Claude via AvBot, in Chioma's voice.
        if (class_exists('AvBot') && AvBot::configured()) {
            $hist = [];
            foreach ($history as $h) {
                $t = trim((string) ($h['text'] ?? '')); if ($t === '') continue;
                $isUser = (($h['role'] ?? '') === 'user' || ($h['role'] ?? '') === 'member');
                $hist[] = ['role' => $isUser ? 'member' : 'bot', 'name' => $isUser ? null : 'Chioma', 'text' => mb_substr($t, 0, 1200)];
            }
            $res = AvBot::reply($message, $hist, ['system' => self::systemPrompt($ctx), 'max_tokens' => 500]);
            if (!empty($res['ok'])) return ['ok' => true, 'reply' => $res['text'], 'source' => 'ai'];
        }
        // 3) Scripted fallback.
        return ['ok' => true, 'reply' => self::fallback($message, (string) ($ctx['path'] ?? '')), 'source' => 'fallback'];
    }

    /** Forward to the site owner's configured AI agent; returns its reply text or null. */
    private static function delegate(string $message, array $history, array $ctx): ?string
    {
        if (!function_exists('curl_init')) return null;
        $payload = json_encode([
            'message' => $message,
            'history' => $history,
            'context' => $ctx,
            'persona' => self::systemPrompt($ctx),
            'source'  => 'afrovanguard-chioma',
        ]);
        $headers = ['Content-Type: application/json', 'Accept: application/json'];
        $key = trim((string) (getenv('AV_CHIOMA_AGENT_KEY') ?: ''));
        if ($key !== '') $headers[] = 'Authorization: Bearer ' . $key;

        $ch = curl_init(self::agentUrl());
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => $payload, CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 25, CURLOPT_CONNECTTIMEOUT => 10,
        ]);
        $resp = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($resp === false) { error_log('[chioma-agent] curl: ' . curl_error($ch)); curl_close($ch); return null; }
        curl_close($ch);
        if ($code < 200 || $code >= 300) { error_log('[chioma-agent] HTTP ' . $code . ': ' . substr((string) $resp, 0, 200)); return null; }

        $d = json_decode((string) $resp, true);
        if (is_array($d)) {
            foreach (['reply', 'text', 'message', 'output', 'response', 'answer'] as $k) {
                if (isset($d[$k]) && is_string($d[$k]) && trim($d[$k]) !== '') return $d[$k];
            }
            if (isset($d['choices'][0]['message']['content'])) return (string) $d['choices'][0]['message']['content'];
        }
        $trim = trim((string) $resp);   // accept a plain-text body too
        if ($trim !== '' && $trim[0] !== '{' && $trim[0] !== '[') return mb_substr($trim, 0, 2000);
        return null;
    }

    /** Helpful, on-brand reply when no AI is available — keyword-routed. */
    public static function fallback(string $msg, string $path = ''): string
    {
        $m = mb_strtolower($msg);
        $hit = function (array $words) use ($m): bool { foreach ($words as $w) if (mb_strpos($m, $w) !== false) return true; return false; };
        if ($hit(['donat', 'give', 'support', 'fund'])) return "Lovely — you can give at /donate.html. We especially welcome material/in-kind donations, and monetary gifts help too. 💛";
        if ($hit(['academy', 'course', 'learn', 'techome', 'mediapro', 'class', 'study', 'enrol', 'enroll'])) return "Our Academy has free, hands-on programmes — take a look at /academy/ and you can enrol right there. Want me to point you to a specific one?";
        if ($hit(['volunteer', 'join', 'help out', 'get involved'])) return "Wonderful! Head to /contact/ to get involved, or explore the programmes at /projects/. We'd love to have you.";
        if ($hit(['contact', 'reach', 'email', 'talk to', 'speak'])) return "You can reach the team any time via /contact/ — tell them what you need and they'll get back to you.";
        if ($hit(['member', 'sign in', 'login', 'account'])) return "Members get mentorship and members-only spaces. You can sign in or create an account from the top of any page.";
        if ($hit(['project', 'street', 'stardom', 'lcasp', 'children', 'programme', 'program'])) return "See all our work at /projects/ — from Street-To-Stardom to the LCASP children's programme across Lagos.";
        if ($hit(['hello', 'hi ', 'hey', 'who are you', 'your name'])) return "Hi, I'm Chioma — your guide to Afrovanguard! 🌍 Ask me about our Academy, projects, how to donate, or how to get involved.";
        return "I'm Chioma, your Afrovanguard guide! I can point you to our free Academy (/academy/), our projects (/projects/), how to donate (/donate.html), or how to get involved (/contact/). What are you looking for?";
    }
}
