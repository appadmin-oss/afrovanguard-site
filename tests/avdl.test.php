<?php
/**
 * tests/avdl.test.php — the redesigned Diary listing's card (diary/_avdl.php).
 * Run via tests/run.php (provides ck()).
 */
declare(strict_types=1);
require_once AV_ROOT . '/diary/_avdl.php';

$dlA = ['slug' => 'a-b', 'title' => 'Fish & <Chips>', 'category' => 'Dispatch', 'read_minutes' => 8,
        'published' => 'Sep 21, 2026', 'published_at' => '2026-09-21 10:00:00', 'cover_url' => '/x.jpg'];
ck('avdl: compact counts', avdl_compact(999) === '999' && avdl_compact(3400) === '3.4k' && avdl_compact(2000) === '2k');
ck('avdl: meta reads "8 min read | date"', avdl_meta($dlA) === '8 min read | Sep 21, 2026');
ck('avdl: meta drops an unknown read time', avdl_meta(['read_minutes' => 0, 'published' => 'Sep 1, 2026']) === 'Sep 1, 2026');
$dlH = avdl_card($dlA, null);
ck('avdl: card links to the entry', str_contains($dlH, 'href="/diary/a-b/"'));
ck('avdl: card escapes the title', str_contains($dlH, 'Fish &amp; &lt;Chips&gt;') && !str_contains($dlH, '<Chips>'));
ck('avdl: card shows no counts when the server has none', !str_contains($dlH, 'avdl-counts'));
ck('avdl: card uses the cover photo', str_contains($dlH, 'src="/x.jpg"'));
$dlC = avdl_card($dlA, ['views' => 3400, 'comments' => 2, 'claps' => 9]);
ck('avdl: card counts', str_contains($dlC, '3.4k views') && str_contains($dlC, '2 comments') && str_contains($dlC, 'applause'));
$dlN = avdl_card(array_merge($dlA, ['cover_url' => '']), null);
ck('avdl: no photo falls back to the shared cover graphic', str_contains($dlN, 'avcv') && !str_contains($dlN, '<img'));
