<?php
/**
 * tests/quotecard.test.php — the quote of the week card (/quote-card.php):
 * one quote list (avh.js), the week rotation, and the GD toolkit it draws with.
 */
declare(strict_types=1);

require_once AV_ROOT . '/lib/AvImage.php';
require_once AV_ROOT . '/lib/QuoteOfWeek.php';

$all = QuoteOfWeek::all();
ck('quote: the list is read from avh.js', count($all) >= 5 && $all[0][1] === 'Chinua Achebe'
    && $all[0][0] === 'The trouble with Nigeria is simply and squarely a failure of leadership.');
ck('quote: curly apostrophes survive the parse', str_contains($all[1][0], 'it’s done'));
ck('quote: escaped quotes in the JS are unescaped', QuoteOfWeek::all("const QUOTES = [\n  ['It\\'s a', 'B', 'C']\n];")[0][0] === "It's a");

$q = QuoteOfWeek::forDate(new DateTimeImmutable('2026-10-07', new DateTimeZone('Africa/Lagos')), $all);
ck('quote: ISO week 41 → the page’s pick (41 mod 5)', $q['n'] === 41 && $q['who'] === $all[41 % count($all)][1]);
ck('quote: the week label, one month', $q['week'] === 'Week 41 · 5 – 11 Oct');
$q = QuoteOfWeek::forDate(new DateTimeImmutable('2026-09-30'), $all);
ck('quote: the week label across months, en-GB “Sept”', $q['week'] === 'Week 40 · 28 Sept – 4 Oct');
ck('quote: initials', QuoteOfWeek::initials('Nelson Mandela') === 'NM' && QuoteOfWeek::initials('Afrovanguard') === 'A');

ck('image: colours come from the tokens by name', AvImage::token('--av-ink') === [17, 24, 39] && AvImage::token('--av-gold') === [243, 180, 22]);
ck('image: an unknown token is grey, not a fatal', AvImage::token('--av-nope') === [128, 128, 128]);

if (AvImage::available()) {
    $lines = AvImage::wrap('The trouble with Nigeria is simply and squarely a failure of leadership.', 'serif-500', 70, 920, true, -.01);
    ck('image: balanced wrap keeps the words and the line count', count($lines) === 3
        && implode(' ', $lines) === 'The trouble with Nigeria is simply and squarely a failure of leadership.');
    ck('image: every balanced line fits', max(array_map(fn($l) => AvImage::width($l, 'serif-500', 70, -.01), $lines)) <= 920);
    $im = AvImage::canvas(40, 20, AvImage::token('--av-ink'));
    ck('image: canvas is filled with the token', imagecolorat($im, 5, 5) === (17 << 16 | 24 << 8 | 39));
    imagedestroy($im);
} else {
    ck('image: GD + FreeType available for the share cards', false);
}

$src = (string) file_get_contents(AV_ROOT . '/quote-card.php');
ck('card: takes no text from the request', !preg_match('/\$_GET\[\'(quote|who|where|week|photo)\'\]/', $src));
$home = (string) file_get_contents(AV_ROOT . '/index.html');
ck('home: share menu offers the story card and the download',
    str_contains($home, 'href="/quote-card.php?format=story&amp;download=1"') && str_contains($home, 'href="/quote-card.php?format=feed&amp;download=1"'));
