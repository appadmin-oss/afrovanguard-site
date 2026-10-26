<?php
/**
 * tests/diarydropcap.test.php — diary entries have no drop cap.
 *
 * The big first letter was removed by request: on an entry opening with an
 * italic byline it hung into the paragraph below, and even placed on the
 * first paragraph of prose it did not suit the page. Nothing draws one now —
 * not the entry page, not the exported book.
 *
 * Run via tests/run.php (provides ck()).
 */
declare(strict_types=1);

foreach (['diary/diary.css', 'lib/DiaryExport.php', 'diary/article.php', 'diary/reader.php'] as $f) {
    $src = (string) file_get_contents(AV_ROOT . '/' . $f);
    ck('no drop cap: ' . $f, !str_contains($src, '::first-letter') && !str_contains($src, 'dropcap'));
}
