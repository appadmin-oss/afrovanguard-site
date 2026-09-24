<?php
/**
 * search.php — JSON site search for the accessible search modal.
 *
 *   GET ?q=<query>[&ai=1]
 *
 * Ranked keyword search across key pages, the Diary, the Academy and the People
 * directory, plus an optional AI answer (grounded on the live site knowledge).
 *
 * The index and its ranking live in lib/SiteSearch.php, so this endpoint and
 * Chioma's site_search tool return the same results from the same code.
 * Read-only, same-origin friendly, and fail-safe (never throws to the client).
 */
declare(strict_types=1);
require_once __DIR__ . '/lib/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

$q = trim((string) ($_GET['q'] ?? ''));
$qlen = function_exists('mb_strlen') ? mb_strlen($q) : strlen($q);
if ($qlen < 2) {
    echo json_encode(['ok' => true, 'q' => $q, 'results' => [], 'ai' => null]);
    exit;
}

$results = SiteSearch::query($q, 12, (string) ($_GET['type'] ?? ''));

/* ── Optional AI answer, grounded on the live site knowledge. ────────────── */
$ai = null;
if ((string) ($_GET['ai'] ?? '') === '1' && class_exists('AvBot')) {
    if (AvBot::configured()) {
        $sys = "You are the Afrovanguard website search assistant. Answer the visitor's query in 2-3 concise, warm sentences and, when relevant, point them to the right place using these paths: Academy /academy/, the Diary /diary/, Donate /donate.html, Projects /projects/, About /about.html, Contact /contact.html, the member Portal /portal/, Events https://afg.afrovanguard.org.ng/events. Don't invent pages or facts. If you don't know, say so and suggest Contact.";
        if (class_exists('AiKnowledge')) $sys .= AiKnowledge::asPromptBlock();
        try {
            $r = AvBot::reply($q, [], ['system' => $sys]);
            $ai = ['ok' => (bool) ($r['ok'] ?? false), 'text' => !empty($r['ok']) ? (string) $r['text'] : ''];
        } catch (\Throwable $e) { error_log('[search] ai: ' . $e->getMessage()); $ai = ['ok' => false, 'text' => '']; }
    } else {
        $ai = ['ok' => false, 'text' => '', 'configured' => false];
    }
}

echo json_encode(['ok' => true, 'q' => $q, 'results' => $results, 'ai' => $ai], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
