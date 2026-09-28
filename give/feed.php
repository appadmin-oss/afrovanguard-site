<?php
/**
 * give/feed.php — live appeals as JSON or RSS.
 *   /give/feed.json   — for the home page band and any partner widget
 *   /give/feed.xml    — for readers, aggregators and syndication
 *
 * The home page is a static index.html served by DirectoryIndex, so it cannot
 * read the database. Rather than convert six thousand lines of hand-built page
 * into PHP to show four appeals, the band there is progressive: it ships with a
 * static fallback in the markup and fills itself from this endpoint. If the
 * fetch fails, the visitor sees a real section pointing at /give/ rather than a
 * spinner that never resolves.
 *
 * Public, cacheable, and read-only. It exposes exactly what the public appeal
 * page already shows — no donor names, no emails, nothing about who gave.
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/bootstrap.php';

$format = (string) ($_GET['format'] ?? 'json');
$limit  = max(1, min(50, (int) ($_GET['limit'] ?? 12)));

/* Only appeals that are actually asking. A funded or closed one on somebody
   else's site is a dead link with a full progress bar. */
$rows = [];
foreach (Appeals::published(80) as $a) {
    if ((string) $a['status'] !== 'live') continue;
    $st = Appeals::state($a);
    $rows[] = [
        'title'     => (string) $a['title'],
        'tagline'   => (string) $a['tagline'],
        'slug'      => (string) $a['slug'],
        'url'       => Appeals::url($a, $format === 'rss' ? 'rss' : 'feed'),
        'image'     => (string) $a['cover_url'] !== '' ? (string) $a['cover_url'] : Appeals::ogUrl($a),
        'kind'      => (string) $a['kind'],
        'location'  => (string) $a['location'],
        'urgent'    => (bool) $a['urgent'],
        'raised'    => $st['raised'],
        'goal'      => $st['goal'],
        'percent'   => $st['percent'],
        'donors'    => $st['donors'],
        'days_left' => $st['days_left'],
        'match_live' => $st['match_live'] && $st['match_left'] > 0,
        'raised_label' => Appeals::naira($st['raised']),
        'goal_label'   => $st['goal'] > 0 ? Appeals::naira($st['goal']) : '',
        'published' => (string) $a['published_at'],
    ];
    if (count($rows) >= $limit) break;
}

/* Five minutes. Long enough that a busy home page is not re-querying per
   visitor, short enough that a donor who has just given sees the needle move. */
header('Cache-Control: public, max-age=300');
header('X-Robots-Tag: noindex');

if ($format === 'rss') {
    header('Content-Type: application/rss+xml; charset=utf-8');
    $S = rtrim(SITE_URL, '/');
    $esc = static fn($s) => htmlspecialchars((string) $s, ENT_QUOTES | ENT_XML1, 'UTF-8');
    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    echo '<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom"><channel>' . "\n";
    echo '  <title>Afrovanguard — live appeals</title>' . "\n";
    echo '  <link>' . $esc($S . '/give/') . '</link>' . "\n";
    echo '  <description>What Afrovanguard is raising for right now, and what each gift pays for.</description>' . "\n";
    echo '  <language>en-NG</language>' . "\n";
    echo '  <atom:link href="' . $esc($S . '/give/feed.xml') . '" rel="self" type="application/rss+xml"/>' . "\n";
    foreach ($rows as $r) {
        $desc = $r['tagline'];
        if ($r['goal'] > 0 && $r['percent'] !== null) {
            $desc = rtrim($desc, ' .') . ' — ' . $r['percent'] . '% of ' . $r['goal_label'] . ' raised.';
        }
        echo "  <item>\n";
        echo '    <title>' . $esc($r['title']) . "</title>\n";
        echo '    <link>' . $esc($r['url']) . "</link>\n";
        echo '    <guid isPermaLink="true">' . $esc($r['url']) . "</guid>\n";
        echo '    <description>' . $esc($desc) . "</description>\n";
        if ($r['published'] !== '') {
            $t = strtotime($r['published']);
            if ($t) echo '    <pubDate>' . gmdate('D, d M Y H:i:s', $t) . " GMT</pubDate>\n";
        }
        if ($r['image'] !== '') echo '    <enclosure url="' . $esc($r['image']) . '" type="image/png"/>' . "\n";
        echo "  </item>\n";
    }
    echo "</channel></rss>\n";
    exit;
}

header('Content-Type: application/json; charset=utf-8');
/* Same-origin by default. The embed widget is the supported way to put an
   appeal on somebody else's site — it renders, it is styled, and it cannot be
   scraped into a misleading figure the way a raw feed can. */
if (($_SERVER['HTTP_ORIGIN'] ?? '') !== '') {
    $host = parse_url((string) $_SERVER['HTTP_ORIGIN'], PHP_URL_HOST) ?: '';
    if ($host !== '' && stripos((string) ($_SERVER['HTTP_HOST'] ?? ''), $host) === false) {
        http_response_code(403);
        echo json_encode(['error' => 'Use /give/<slug>/embed to show an appeal on another site.']);
        exit;
    }
}
/* The needs ride along. The home-page band leads with them, and a second
   round trip for four short lines would be a request nobody needs made. */
$needs = [];
foreach (Appeals::currentNeedsAll(6) as $n) {
    $needs[] = [
        'when'   => $n['cadence'] === 'daily' ? 'Today' : ($n['cadence'] === 'weekly' ? 'This week' : 'Still needed'),
        'cadence' => (string) $n['cadence'],
        'title'  => (string) $n['title'],
        'figure' => Appeals::naira((int) $n['target_ngn']),
        'target' => (int) $n['target_ngn'],
        'unit'   => ($n['units_target'] > 0 && $n['unit_label'] !== '')
            ? number_format((int) $n['units_target']) . ' ' . (string) $n['unit_label']
              . ' at ' . Appeals::naira((int) $n['unit_cost']) . ' each'
            : '',
        'for'    => (string) $n['appeal']['title'],
        'url'    => (string) $n['appeal']['url'],
    ];
}

echo json_encode([
    'updated' => gmdate('c'),
    'count'   => count($rows),
    'appeals' => $rows,
    'needs'   => $needs,
    'totals'  => Appeals::needsTotal(),
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
