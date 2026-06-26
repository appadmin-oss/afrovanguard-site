<?php
/**
 * diary/export.php — download/print the Diary.
 *   ?format=book|html|md|json   (default: book — the print-ready Journal)
 *   ?scope=mine                 (a signed-in member's OWN entries; requires login)
 * Public scope exports published articles only. Light per-IP rate limit.
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_once AV_ROOT . '/lib/DiaryExport.php';

$format = strtolower((string) ($_GET['format'] ?? 'book'));
if (!in_array($format, ['json', 'md', 'html', 'book'], true)) $format = 'book';
$scope = (($_GET['scope'] ?? '') === 'mine') ? 'mine' : 'diary';

if (!av_rate_ok('diary_export', 30, 600)) {
    http_response_code(429);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Too many exports — please wait a few minutes.';
    exit;
}

$order = $format === 'book' ? 'ASC' : 'DESC';   // book reads chronologically, like a journal
$items = [];
$meta  = ['site' => defined('SITE_URL') ? SITE_URL : '', 'exported' => date('F j, Y')];

if ($scope === 'mine') {
    $u = LmsAuth::user();
    if (!$u) { header('Location: ' . av_login_url('/diary/export.php?scope=mine&format=' . $format)); exit; }
    $entries = (new DiaryJournal())->mine((int) $u['id']);
    if ($format === 'book') $entries = array_reverse($entries);
    foreach ($entries as $e) {
        $items[] = [
            'title'    => ($e['title'] ?? '') !== '' ? (string) $e['title'] : 'Entry',
            'subtitle' => ucfirst((string) $e['kind']) . ' · ' . ucfirst((string) $e['status']),
            'date'     => (string) $e['entry_date'],
            'html'     => nl2br(htmlspecialchars((string) $e['body'], ENT_QUOTES, 'UTF-8')),
            'slug'     => (string) ($e['published_slug'] ?? ''),
        ];
    }
    $first = explode(' ', trim((string) $u['name']))[0] ?: 'My';
    $meta['title']    = $first . '’s Journal';
    $meta['subtitle'] = 'My Afrovanguard Diary';
    $base = 'my-afrovanguard-journal';
} else {
    foreach ((new DiaryRepository())->allForExport($order) as $a) {
        $by = trim(strip_tags((string) $a['authors_html']));
        $items[] = [
            'title'    => (string) $a['title'],
            'subtitle' => (string) $a['category'] . ($by !== '' ? ' · ' . $by : ''),
            'date'     => (string) $a['published'],
            'html'     => (string) $a['body_html'],
            'slug'     => (string) $a['slug'],
        ];
    }
    $meta['title']    = 'The Afrovanguard Diary';
    $meta['subtitle'] = 'Dispatches from the movement';
    $base = 'afrovanguard-diary';
}
$meta['count'] = count($items);
$stamp = date('Y-m-d');

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');

switch ($format) {
    case 'json':
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $base . '-' . $stamp . '.json"');
        echo DiaryExport::json($items, $meta);
        break;
    case 'md':
        header('Content-Type: text/markdown; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $base . '-' . $stamp . '.md"');
        echo DiaryExport::markdown($items, $meta);
        break;
    case 'html':
        header('Content-Type: text/html; charset=utf-8');
        echo DiaryExport::html($items, $meta);
        break;
    case 'book':
    default:
        header('Content-Type: text/html; charset=utf-8');
        echo DiaryExport::book($items, $meta);
        break;
}
