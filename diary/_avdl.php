<?php
/**
 * diary/_avdl.php — the Diary listing's card (design "Afrovanguard Diary",
 * All entries grid). assets/site/avdl.js builds the same markup for the
 * entries it loads after the first page; keep the two in step
 * (tests/avdl.test.php pins this side).
 */
declare(strict_types=1);
require_once AV_ROOT . '/partials/av-cover.php';

/** "3.4k" above a thousand, the plain number below it. */
function avdl_compact(int $n): string
{
    return $n >= 1000 ? rtrim(rtrim(number_format($n / 1000, 1), '0'), '.') . 'k' : (string) $n;
}

/** "8 min read | Sep 21, 2026" — either half may be absent. */
function avdl_meta(array $a): string
{
    $min = (int) ($a['read_minutes'] ?? 0);
    $parts = array_filter([$min ? $min . ' min read' : '', (string) ($a['published'] ?? '')], 'strlen');
    return implode(' | ', $parts);
}

/** The picture: the entry's cover photo, else the shared cover graphic. */
function avdl_img(array $a, string $ratio = '3:2'): string
{
    $url = trim((string) ($a['cover_url'] ?? ''));
    if ($url !== '') return '<img src="' . e($url) . '" alt="" loading="lazy" decoding="async">';
    return av_cover(['kind' => 'diary', 'title' => (string) $a['title'], 'date' => substr((string) ($a['published_at'] ?? ''), 0, 10),
        'readTime' => (int) ($a['read_minutes'] ?? 0) ? (int) $a['read_minutes'] . ' min read' : '', 'ratio' => $ratio, 'class' => 'avcv--fill']);
}

/** One card of the grid. Counts render only when the server has them. */
function avdl_card(array $a, ?array $c): string
{
    $counts = '';
    if ($c !== null) {
        $counts = '<span class="avdl-counts"><span>' . e(avdl_compact((int) $c['views'])) . ' views</span>'
            . '<span>' . (int) $c['comments'] . ' comments</span>'
            . '<span>' . (int) $c['claps'] . ' <span aria-hidden="true">👏</span><span class="av-sr">applause</span></span></span>';
    }
    return '<li><a class="avdl-card" href="/diary/' . e($a['slug']) . '/">'
        . '<div class="avdl-card-img">' . avdl_img($a, '3:2') . '</div>'
        . '<div class="avdl-card-body">'
        . '<span class="avdl-kick">Diary <span class="avdl-sep" aria-hidden="true">|</span> ' . e((string) $a['category']) . '</span>'
        . '<h3 class="avdl-card-t">' . e((string) $a['title']) . '</h3>'
        . '<span class="avdl-card-meta">' . e(avdl_meta($a)) . '</span>'
        . $counts
        . '</div></a></li>';
}
