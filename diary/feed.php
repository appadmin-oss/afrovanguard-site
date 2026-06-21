<?php
/**
 * diary/feed.php — RSS 2.0 feed for the Diary, generated from the database.
 * Served at /diary/feed.xml (see .htaccess).
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/bootstrap.php';

$articles = (new DiaryRepository())->all();
header('Content-Type: application/rss+xml; charset=utf-8');

$self = diary_url('feed.xml');
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom"><channel>' . "\n";
echo '  <title>The Afrovanguard Diary</title>' . "\n";
echo '  <link>' . e(diary_url()) . "</link>\n";
echo '  <description>Field notes, methodology, and the mission behind raising one million incorruptible leaders for Africa by 2040.</description>' . "\n";
echo '  <language>en-NG</language>' . "\n";
echo '  <atom:link href="' . e($self) . '" rel="self" type="application/rss+xml" />' . "\n";
if (!empty($articles)) {
    echo '  <lastBuildDate>' . date(DATE_RSS, strtotime($articles[0]['published_at'])) . "</lastBuildDate>\n";
}
foreach ($articles as $a) {
    $url = diary_url($a['slug'] . '/');
    echo "  <item>\n";
    echo '    <title>' . e($a['title']) . "</title>\n";
    echo '    <link>' . e($url) . "</link>\n";
    echo '    <guid isPermaLink="true">' . e($url) . "</guid>\n";
    echo '    <category>' . e($a['category']) . "</category>\n";
    echo '    <pubDate>' . date(DATE_RSS, strtotime($a['published_at'])) . "</pubDate>\n";
    echo '    <description>' . e($a['dek']) . "</description>\n";
    echo "  </item>\n";
}
echo "</channel></rss>\n";
