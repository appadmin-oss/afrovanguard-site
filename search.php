<?php
/**
 * search.php — JSON site search for the accessible search modal.
 *
 *   GET ?q=<query>[&ai=1]
 *
 * Returns keyword matches across pages, the Diary and the Academy, plus an
 * optional AI answer (the Afrovanguard assistant) when ai=1 and configured.
 * Read-only, same-origin friendly, fail-safe (never throws to the client).
 */
declare(strict_types=1);
require_once __DIR__ . '/lib/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

$q = trim((string) ($_GET['q'] ?? ''));
if (function_exists('mb_strlen') ? mb_strlen($q) < 2 : strlen($q) < 2) {
    echo json_encode(['ok' => true, 'q' => $q, 'results' => [], 'ai' => null]);
    exit;
}
$needle = function (string $hay) use ($q): bool { return $hay !== '' && stripos($hay, $q) !== false; };
$clip   = function (string $s, int $n = 130): string { $s = trim(preg_replace('/\s+/', ' ', strip_tags($s))); return mb_strlen($s) > $n ? mb_substr($s, 0, $n - 1) . '…' : $s; };

$results = [];

// 1) Key pages (always available).
foreach ([
    ['Home', '/', 'The Afrovanguard movement'],
    ['About', '/about.html', 'Our story, ethos and people'],
    ['Academy', '/academy/', 'Free, hands-on programmes'],
    ['Projects', '/projects/', 'Our programmes across Lagos and beyond'],
    ['The Diary', '/diary/', 'Field notes and methodology'],
    ['Donate', '/donate.html', 'Give funds, materials, skills or time'],
    ['Events', 'https://afg.afrovanguard.org.ng/events', 'Latest and upcoming events'],
    ['Contact', '/contact.html', 'Reach the team'],
] as [$t, $h, $d]) {
    if ($needle($t) || $needle($d)) $results[] = ['type' => 'Page', 'title' => $t, 'url' => $h, 'excerpt' => $d];
}

// 2) Diary entries.
try {
    foreach ((new DiaryRepository())->all() as $a) {
        $hay = (string) ($a['title'] ?? '') . ' ' . (string) ($a['dek'] ?? '') . ' ' . (string) ($a['category'] ?? '');
        if ($needle($hay)) {
            $results[] = ['type' => 'Diary', 'title' => (string) $a['title'], 'url' => '/diary/' . (string) $a['slug'] . '/', 'excerpt' => $clip((string) ($a['dek'] ?? ''))];
            if (count($results) > 24) break;
        }
    }
} catch (\Throwable $e) { error_log('[search] diary: ' . $e->getMessage()); }

// 3) Academy courses.
try {
    foreach ((new AcademyRepository())->all() as $c) {
        $hay = (string) ($c['title'] ?? '') . ' ' . (string) ($c['blurb'] ?? $c['dek'] ?? $c['summary'] ?? '') . ' ' . (string) ($c['category'] ?? '');
        if ($needle($hay)) {
            $results[] = ['type' => 'Academy', 'title' => (string) $c['title'], 'url' => '/academy/' . (string) $c['slug'] . '/', 'excerpt' => $clip((string) ($c['blurb'] ?? $c['dek'] ?? $c['summary'] ?? ''))];
        }
    }
} catch (\Throwable $e) { error_log('[search] academy: ' . $e->getMessage()); }

$results = array_slice($results, 0, 12);

// 4) Optional AI answer (the integrated assistant).
$ai = null;
if ((string) ($_GET['ai'] ?? '') === '1' && class_exists('AvBot')) {
    if (AvBot::configured()) {
        $sys = "You are the Afrovanguard website search assistant. Answer the visitor's query in 2–3 concise, warm sentences and, when relevant, point them to the right place using these paths: Academy /academy/, the Diary /diary/, Donate /donate.html, Projects /projects/, About /about.html, Contact /contact.html, Events https://afg.afrovanguard.org.ng/events. Don't invent pages. If you don't know, say so and suggest Contact.";
        try {
            $r = AvBot::reply($q, [], ['system' => $sys]);
            $ai = ['ok' => (bool) ($r['ok'] ?? false), 'text' => !empty($r['ok']) ? (string) $r['text'] : ''];
        } catch (\Throwable $e) { error_log('[search] ai: ' . $e->getMessage()); $ai = ['ok' => false, 'text' => '']; }
    } else {
        $ai = ['ok' => false, 'text' => '', 'configured' => false];
    }
}

echo json_encode(['ok' => true, 'q' => $q, 'results' => $results, 'ai' => $ai], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
