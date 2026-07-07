<?php
/**
 * chioma.php — Chioma, Afrovanguard's friendly site guide (AI FAB backend).
 *
 * Public, same-origin, rate-limited. Takes the visitor's message + a short
 * history + the page they're on, and hands it to Chioma (lib/Chioma.php), who
 * replies via YOUR AI agent (if AV_CHIOMA_AGENT_URL is set), else Claude, else
 * a helpful scripted fallback — so she always responds.
 *
 *   POST {message, history:[{role,text}], page:{title,path,section}}
 *     → {ok:true, reply:"…", source:"agent|ai|fallback", configured:bool}
 */
declare(strict_types=1);
require_once __DIR__ . '/lib/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') { http_response_code(405); echo json_encode(['ok' => false, 'error' => 'POST required.']); exit; }
if (function_exists('require_same_origin')) require_same_origin();
if (function_exists('av_rate_ok') && !av_rate_ok('chioma', 30, 300)) { http_response_code(429); echo json_encode(['ok' => false, 'error' => 'You’re sending messages a bit fast — give me a moment. 🙂']); exit; }

$body = json_decode((string) file_get_contents('php://input'), true) ?: [];

// ── Reply feedback (👍/👎 from the widget) — logged for the team, never
// interrupts the conversation. POST {rate:"up"|"down", text, page:{path}}.
$rate = strtolower(trim((string) ($body['rate'] ?? '')));
if ($rate === 'up' || $rate === 'down') {
    try {
        $pdo = Database::pdo();
        switch ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)) {
            case 'mysql':
                $pdo->exec("CREATE TABLE IF NOT EXISTS chioma_feedback (id INTEGER PRIMARY KEY AUTO_INCREMENT, rating VARCHAR(8) NOT NULL, reply TEXT, path VARCHAR(200) NOT NULL DEFAULT '', created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
                break;
            case 'pgsql':
                $pdo->exec("CREATE TABLE IF NOT EXISTS chioma_feedback (id SERIAL PRIMARY KEY, rating VARCHAR(8) NOT NULL, reply TEXT, path VARCHAR(200) NOT NULL DEFAULT '', created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP)");
                break;
            default:
                $pdo->exec("CREATE TABLE IF NOT EXISTS chioma_feedback (id INTEGER PRIMARY KEY AUTOINCREMENT, rating TEXT NOT NULL, reply TEXT, path TEXT NOT NULL DEFAULT '', created_at TEXT NOT NULL DEFAULT (datetime('now')))");
        }
        $pdo->prepare('INSERT INTO chioma_feedback (rating, reply, path) VALUES (?, ?, ?)')->execute([
            $rate,
            mb_substr(trim((string) ($body['text'] ?? '')), 0, 600),
            mb_substr(trim((string) (($body['page']['path'] ?? '') ?: '')), 0, 200),
        ]);
    } catch (Throwable $e) { error_log('[chioma] feedback: ' . $e->getMessage()); }
    echo json_encode(['ok' => true]); exit;
}

$message = trim((string) ($body['message'] ?? ''));
if ($message === '') { echo json_encode(['ok' => false, 'error' => 'Say something and I’ll help.']); exit; }
$message = mb_substr($message, 0, 1500);

$page = is_array($body['page'] ?? null) ? $body['page'] : [];
$ctx = [
    'title'   => mb_substr(trim((string) ($page['title'] ?? '')), 0, 160),
    'path'    => mb_substr(trim((string) ($page['path'] ?? '')), 0, 200),
    'section' => preg_replace('/[^a-z0-9 \-]/i', '', (string) ($page['section'] ?? '')),
];

// History → [{role:user|bot, text}] (most recent kept). Chioma normalises further.
$history = [];
foreach ((array) ($body['history'] ?? []) as $h) {
    $t = trim((string) ($h['text'] ?? ''));
    if ($t === '') continue;
    $role = (($h['role'] ?? '') === 'user') ? 'user' : 'bot';
    $history[] = ['role' => $role, 'text' => mb_substr($t, 0, 1200)];
}
$history = array_slice($history, -12);

// Executive-assistant context: a stable per-visitor conversation id (minted by
// the widget) plus the signed-in account, so the agent knows WHO it's helping
// and can keep memory per conversation. Only sent to YOUR configured agent.
$meta = ['session' => preg_replace('/[^A-Za-z0-9\-_]/', '', mb_substr((string) ($body['session'] ?? ''), 0, 64))];
try {
    $au = class_exists('LmsAuth') ? LmsAuth::user() : null;
    if ($au) {
        $meta['user'] = [
            'name'   => (string) $au['name'],
            'email'  => (string) $au['email'],
            'member' => LmsAuth::isOrgMember($au),
            'role'   => (string) ($au['role'] ?? 'learner'),
        ];
    }
} catch (Throwable $e) {}

$res = Chioma::reply($message, $history, $ctx, $meta);
echo json_encode([
    'ok'         => true,
    'reply'      => $res['reply'] !== '' ? $res['reply'] : Chioma::fallback($message, $ctx['path']),
    'source'     => $res['source'],
    'actions'    => $res['actions'] ?? [],
    'configured' => Chioma::aiAvailable(),
]);
