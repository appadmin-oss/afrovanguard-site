<?php
/**
 * chioma.php — Chioma, Afrovanguard's friendly site guide (AI FAB backend).
 *
 * Public, same-origin, rate-limited. Takes the visitor's message + a short
 * history + the page they're on, and replies in Chioma's voice via Claude
 * (reusing AvBot's plumbing with a custom persona). Degrades gracefully to a
 * helpful scripted reply when ANTHROPIC_API_KEY isn't configured.
 *
 *   POST {message, history:[{role,text}], page:{title,path,section}}
 *     → {ok:true, reply:"…", configured:bool}
 */
declare(strict_types=1);
require_once __DIR__ . '/lib/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') { http_response_code(405); echo json_encode(['ok' => false, 'error' => 'POST required.']); exit; }
if (function_exists('require_same_origin')) require_same_origin();
if (function_exists('av_rate_ok') && !av_rate_ok('chioma', 30, 300)) { http_response_code(429); echo json_encode(['ok' => false, 'error' => 'You’re sending messages a bit fast — give me a moment. 🙂']); exit; }

$body = json_decode((string) file_get_contents('php://input'), true) ?: [];
$message = trim((string) ($body['message'] ?? ''));
if ($message === '') { echo json_encode(['ok' => false, 'error' => 'Say something and I’ll help.']); exit; }
$message = mb_substr($message, 0, 1500);

$page = is_array($body['page'] ?? null) ? $body['page'] : [];
$pTitle = mb_substr(trim((string) ($page['title'] ?? '')), 0, 160);
$pPath  = mb_substr(trim((string) ($page['path'] ?? '')), 0, 200);
$pSection = preg_replace('/[^a-z0-9 \-]/i', '', (string) ($page['section'] ?? ''));

// History → AvBot's [{role:member|bot,text}] shape (most recent kept).
$history = [];
foreach ((array) ($body['history'] ?? []) as $h) {
    $t = trim((string) ($h['text'] ?? ''));
    if ($t === '') continue;
    $role = (($h['role'] ?? '') === 'user') ? 'member' : 'bot';
    $history[] = ['role' => $role, 'name' => $role === 'bot' ? 'Chioma' : null, 'text' => mb_substr($t, 0, 1200)];
}
$history = array_slice($history, -12);

if (!class_exists('AvBot') || !AvBot::configured()) {
    echo json_encode(['ok' => true, 'configured' => false, 'reply' => chioma_fallback($message, $pPath)]);
    exit;
}

$ctx = '';
if ($pTitle !== '' || $pPath !== '') {
    $ctx = "\n\nContext — the visitor is currently on this page: \"{$pTitle}\" ({$pPath})"
        . ($pSection !== '' ? " in the \"{$pSection}\" section." : '.')
        . " Tailor your help to where they are when it's relevant.";
}

$org = defined('AV_ORG_DOMAIN') ? AV_ORG_DOMAIN : 'afrovanguard.org.ng';
$system = <<<SYS
You are Chioma — the warm, witty, whip-smart guide for the Afrovanguard website. Think of yourself as a knowledgeable Nigerian big-sister who helps every visitor feel at home and find exactly what they need. You are friendly and lively but never fake; you're proud of the movement and genuinely want to help.

Afrovanguard is a Nigerian-rooted nonprofit raising one million incorruptible African leaders by 2040 through community, technology and cultural advancement. Key places you can guide people to:
- The Academy (/academy/) — free, hands-on programmes: Techome, MediaPro, Africa GATES, Next Generation Genius.
- Projects (/projects/) — Street-To-Stardom, LCASP children's programme, and more.
- The Diary (/diary/) — stories and dispatches from the work.
- Donate (/donate.html) — material donations are especially welcome, and monetary too.
- Contact (/contact/) — to reach the team; Membership for members-only spaces (an @{$org} account).

How you talk:
- Short and conversational — usually 1–3 sentences. Warm, plain, a little playful. A tasteful emoji is fine, sparingly.
- Be genuinely useful: answer the question, then point to the right page or next step when it helps.
- When someone wants to act (enrol, donate, volunteer, contact), name the page and encourage them.

Hard rules:
- NEVER invent specifics you weren't given — dates, figures, names, prices, links beyond the ones above. If unsure, say so kindly and point them to Contact.
- No legal/medical/financial advice; don't make promises for staff.
- If something is off-mission, harmful or abusive, decline briefly and warmly and steer back to how you can help.
- You reply with words only — you don't process payments, change accounts, or send email yourself.{$ctx}
SYS;

$res = AvBot::reply($message, $history, ['system' => $system, 'max_tokens' => 500]);
if (!empty($res['ok'])) {
    echo json_encode(['ok' => true, 'configured' => true, 'reply' => $res['text']]);
} else {
    // AI declined or errored → still be helpful.
    echo json_encode(['ok' => true, 'configured' => true, 'reply' => chioma_fallback($message, $pPath)]);
}

/** A helpful, on-brand reply when the AI isn't available — keyword-routed to the right page. */
function chioma_fallback(string $msg, string $path): string
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
