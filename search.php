<?php
/**
 * search.php — JSON site search for the accessible search modal.
 *
 *   GET ?q=<query>[&ai=1]
 *
 * Ranked keyword search across key pages, the Diary, the Academy and the People
 * directory, plus an optional AI answer (grounded on the live site knowledge).
 *
 * Ranking: the query is tokenised; a result must match ALL terms (AND) to rank,
 * falling back to ANY-term matches only if nothing matches everything. Title
 * hits and whole-query title matches score highest, then a per-type weight, so
 * the most relevant, most authoritative results come first. Read-only,
 * same-origin friendly, and fail-safe (never throws to the client).
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

/* ── Tokenise the query into search terms (>=2 chars, delimiter-split) ────── */
$terms = array_values(array_filter(
    preg_split('/[\s,]+/', mb_strtolower($q)) ?: [],
    fn($t) => mb_strlen($t) >= 2
));
if (!$terms) $terms = [mb_strtolower($q)];
$qlc = mb_strtolower($q);

$clip = function (string $s, int $n = 140): string {
    $s = trim(preg_replace('/\s+/', ' ', strip_tags($s)) ?? '');
    return (function_exists('mb_strlen') ? mb_strlen($s) : strlen($s)) > $n ? mb_substr($s, 0, $n - 1) . '…' : $s;
};

/**
 * Score a candidate. $title is weighted heaviest, then $body, then $meta.
 * Returns [matchedAll, score]. matchedAll = every term appears somewhere.
 */
$scoreOf = function (string $title, string $body, string $meta, int $typeWeight) use ($terms, $qlc): array {
    $tl = mb_strtolower($title); $bl = mb_strtolower($body); $ml = mb_strtolower($meta);
    $score = 0; $matchedAll = true; $anyHit = false;
    foreach ($terms as $t) {
        $inTitle = mb_strpos($tl, $t) !== false;
        $inBody  = mb_strpos($bl, $t) !== false;
        $inMeta  = mb_strpos($ml, $t) !== false;
        if ($inTitle) { $score += 12; $anyHit = true; }
        elseif ($inBody) { $score += 4; $anyHit = true; }
        elseif ($inMeta) { $score += 3; $anyHit = true; }
        else { $matchedAll = false; }
    }
    if ($tl !== '' && mb_strpos($tl, $qlc) !== false) $score += 25;     // whole query in title
    elseif ($bl !== '' && mb_strpos($bl, $qlc) !== false) $score += 8;  // whole query in body
    if ($score > 0) $score += $typeWeight;
    return [$matchedAll, $anyHit ? $score : 0];
};

$all = [];   // each: [matchedAll, score, result]
$add = function (bool $matchedAll, int $score, array $r) use (&$all) {
    if ($score > 0) $all[] = [$matchedAll, $score, $r];
};

/* 1) Key pages (always available). */
foreach ([
    ['Home', '/', 'The Afrovanguard movement'],
    ['About', '/about.html', 'Our story, ethos and people'],
    ['Academy', '/academy/', 'Free, hands-on programmes'],
    ['Projects', '/projects/', 'Our programmes across Lagos and beyond'],
    ['The Diary', '/diary/', 'Field notes and methodology'],
    ['Donate', '/donate.html', 'Give funds, materials, skills or time'],
    ['Events', 'https://afg.afrovanguard.org.ng/events', 'Latest and upcoming events'],
    ['Contact', '/contact.html', 'Reach the team'],
    ['Member portal', '/portal/', 'Your learning, mentorship and membership dues'],
] as [$t, $h, $d]) {
    [$ok, $sc] = $scoreOf($t, $d, '', 5);
    $add($ok, $sc, ['type' => 'Page', 'title' => $t, 'url' => $h, 'excerpt' => $d]);
}

/* 2) Diary entries. Matched by title, dek, category — and by reference code. */
try {
    // A code is an exact identifier, not a keyword, so it does not compete on
    // relevance: if the query IS a code, that entry is the answer and goes first.
    $wantCode = DiaryRepository::normaliseRefCode($q);
    foreach ((new DiaryRepository())->all() as $a) {
        $title = (string) ($a['title'] ?? '');
        $dek = (string) ($a['dek'] ?? '');
        $code = (string) ($a['ref_code'] ?? '');
        [$ok, $sc] = $scoreOf($title, $dek, trim((string) ($a['category'] ?? '') . ' ' . $code), 3);
        if ($wantCode !== '' && $code === $wantCode) { $ok = true; $sc = 10000; }
        $add($ok, $sc, ['type' => 'Diary', 'title' => $title, 'url' => '/diary/' . (string) ($a['slug'] ?? '') . '/',
                        'excerpt' => $clip($dek), 'code' => $code]);
    }
} catch (\Throwable $e) { error_log('[search] diary: ' . $e->getMessage()); }

/* 3) Academy courses. */
try {
    foreach ((new AcademyRepository())->all() as $c) {
        $title = (string) ($c['title'] ?? '');
        $blurb = (string) ($c['blurb'] ?? $c['dek'] ?? $c['summary'] ?? '');
        [$ok, $sc] = $scoreOf($title, $blurb, (string) ($c['category'] ?? ''), 4);
        $add($ok, $sc, ['type' => 'Academy', 'title' => $title, 'url' => '/academy/' . (string) ($c['slug'] ?? '') . '/', 'excerpt' => $clip($blurb)]);
    }
} catch (\Throwable $e) { error_log('[search] academy: ' . $e->getMessage()); }

/* 4) People directory (public profiles). */
try {
    if (function_exists('av_team_rows') && function_exists('av_team_member_dict')) {
        foreach (av_team_rows(Database::pdo(), true) as $row) {
            $m = av_team_member_dict($row);
            $name = (string) ($m['name'] ?? '');
            if ($name === '') continue;
            $role = (string) ($m['role'] ?? '');
            $bio = (string) ($m['bio'] ?? '');
            [$ok, $sc] = $scoreOf($name, $role . ' ' . $bio, 'people team', 3);
            $url = function_exists('av_person_url') ? av_person_url($m) : ('/people/' . (int) ($m['id'] ?? 0));
            $add($ok, $sc, ['type' => 'People', 'title' => $name, 'url' => $url, 'excerpt' => $clip($role !== '' ? $role : $bio, 90)]);
        }
    }
} catch (\Throwable $e) { error_log('[search] people: ' . $e->getMessage()); }

/* ── Rank: prefer results that matched every term, then by score. ────────── */
$matchedAll = array_filter($all, fn($x) => $x[0]);
$pool = $matchedAll ?: $all;                         // relax to any-term only if needed
usort($pool, fn($a, $b) => $b[1] <=> $a[1]);
$results = array_map(fn($x) => $x[2], array_slice($pool, 0, 12));

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
