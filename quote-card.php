<?php
/**
 * quote-card.php — the quote of the week as a share image (PNG).
 * Design: Afrovanguard Quote Card (feed 4:5, square 1:1, story 9:16).
 *
 *   /quote-card.php                      feed, 1080 × 1350
 *   /quote-card.php?format=square        1080 × 1080
 *   /quote-card.php?format=story         1080 × 1920 (Instagram story)
 *   …&download=1                         served as an attachment
 *
 * Always THIS week's quote, from the one list the Home page rotates
 * (assets/site/avh.js, read by lib/QuoteOfWeek.php). It takes no text from
 * the request: a card that drew any quote it was given would let anybody mint
 * an Afrovanguard-branded image saying anything, at our URL.
 *
 * Drawn with GD (the host has no headless browser), cached per week and
 * format under db/cache/. Without GD or the fonts it redirects to the site's
 * default share image.
 */
declare(strict_types=1);
require_once __DIR__ . '/lib/bootstrap.php';
require_once AV_ROOT . '/lib/AvImage.php';
require_once AV_ROOT . '/lib/QuoteOfWeek.php';

$format = (string) ($_GET['format'] ?? 'feed');
$spec = quote_card_spec($format) ?? quote_card_spec($format = 'feed');

if (!AvImage::available()) {
    header('Location: /assets/og/og-default.png', true, 302);
    exit;
}

$q = QuoteOfWeek::forDate();
$key = md5(implode('|', [$format, $q['text'], $q['who'], $q['where'], $q['week'], (string) @filemtime(__FILE__), (string) @filemtime(AV_ROOT . '/lib/AvImage.php')]));
$download = !empty($_GET['download']) ? 'afrovanguard-quote-week-' . $q['n'] . '-' . $format . '.png' : null;

AvImage::serveCached('quote-' . $format, $key, fn() => quote_card_draw($spec, $q), 3600, $download);

/** The design's numbers per format (CSS px). */
function quote_card_spec(string $f): ?array
{
    return [
        'feed'   => ['w' => 1080, 'h' => 1350, 'pt' => 88,  'px' => 88, 'pb' => 88,  'mark' => 300, 'markH' => 126, 'q' => 82, 'ring' => 176, 'name' => 38, 'where' => 26, 'gap' => 44],
        'square' => ['w' => 1080, 'h' => 1080, 'pt' => 80,  'px' => 80, 'pb' => 80,  'mark' => 240, 'markH' => 101, 'q' => 70, 'ring' => 144, 'name' => 34, 'where' => 24, 'gap' => 44],
        'story'  => ['w' => 1080, 'h' => 1920, 'pt' => 316, 'px' => 96, 'pb' => 228, 'mark' => 360, 'markH' => 151, 'q' => 96, 'ring' => 232, 'name' => 38, 'where' => 26, 'gap' => 64],
    ][$f] ?? null;
}

/** Quote size steps down with length, as the design's size() does. */
function quote_card_qsize(int $base, string $quote): int
{
    $n = mb_strlen($quote);
    return (int) round($base * ($n < 50 ? 1.12 : ($n < 80 ? 1 : ($n < 120 ? .86 : ($n < 170 ? .74 : .64)))));
}

function quote_card_draw(array $s, array $q)
{
    $T = [AvImage::class, 'token'];
    $ink = $T('--av-ink'); $paper = $T('--av-qc-paper'); $gold = $T('--av-gold'); $goldDk = $T('--av-gold-text');
    $muted = $T('--av-qc-muted'); $motto = $T('--av-qc-motto');
    $W = $s['w']; $H = $s['h']; $L = $s['px']; $R = $W - $s['px'];

    $im = AvImage::canvas($W, $H, $ink);
    // radial-gradient(120% 70% at 100% 0%, rgba(gold,.13), transparent 60%)
    AvImage::radial($im, $W, 0, 1.2 * $W, 0.7 * $H, $gold, .13, .6);
    // the 10px gold rule along the top
    AvImage::hGradient($im, 0, 0, $W, 10, [[0, $goldDk], [.5, $gold], [1, $goldDk]]);

    /* header: seal + wordmark | QUOTE OF THE WEEK / week */
    $top = $s['pt'];
    if ($seal = AvImage::load('/assets/site/av-seal.png')) { AvImage::circleImage($im, $seal, $L, $top, 64); imagedestroy($seal); }
    AvImage::text($im, 'Afrovanguard', 'serif-600', 38, $L + 64 + 16, $top + (64 - 38) / 2, $paper, 1, 0, 'left', 1.0);
    AvImage::text($im, 'QUOTE OF THE WEEK', 'sans-700', 20, $R, $top, $gold, 1, .18, 'right');
    AvImage::text($im, $q['week'], 'sans-400', 22, $R, $top + AvImage::lineH('sans', 20) + 4, $muted, 1, 0, 'right');
    $headBottom = $top + 64;

    /* footer: hairline, site, motto */
    $footH = 1 + 30 + max(AvImage::lineH('sans', 24), AvImage::lineH('serif', 28));
    $footTop = $H - $s['pb'] - $footH;
    imageline($im, $L, (int) $footTop, $R, (int) $footTop, AvImage::col($im, $paper, .16));
    $rowTop = $footTop + 31;
    $rowH = $footH - 31;
    AvImage::text($im, 'afrovanguard.org.ng', 'sans-600', 24, $L, $rowTop + ($rowH - AvImage::lineH('sans', 24)) / 2, $paper);
    AvImage::text($im, 'Raising incorruptible leaders', 'serif-500i', 28, $R, $rowTop + ($rowH - AvImage::lineH('serif', 28)) / 2, $motto, 1, 0, 'right');

    /* the middle, centred between them: mark, quote, person */
    $qpx = quote_card_qsize($s['q'], $q['text']);
    $lines = AvImage::wrap($q['text'], 'serif-500', $qpx, $R - $L, true, -.01);
    $qH = count($lines) * 1.06 * $qpx;
    $D = $s['ring'];
    $textH = 1.1 * $s['name'] + 6 + 1.3 * $s['where'];
    $personH = max($D, $textH);
    $contentH = $s['markH'] + $s['gap'] + $qH + $s['gap'] + $personH;
    $y = $headBottom + (($footTop - $headBottom) - $contentH) / 2;

    AvImage::text($im, '“', 'serif-500', $s['mark'], $L, $y, $gold, 1, 0, 'left', .62);
    $y += $s['markH'] + $s['gap'];
    foreach ($lines as $i => $line) {
        AvImage::text($im, $line, 'serif-500', $qpx, $L, $y + $i * 1.06 * $qpx, $paper, 1, -.01, 'left', 1.06);
    }
    $y += $qH + $s['gap'];

    // the portrait ring: 140° gold gradient, 4px ink border, the face (initials until a photo exists)
    $py = (int) round($y + ($personH - $D) / 2);
    $hi = $T('--av-gold-light'); $lo = $T('--av-link-hover');
    AvImage::disc($im, $L, $py, $D, function ($u, $v) use ($hi, $lo) {
        $t = (($u - .5) * 0.6428 + ($v - .5) * 0.7660) / 1.4088 + .5;
        return [AvImage::mix($hi, $lo, max(0, min(1, $t))), 1.0];
    });
    AvImage::disc($im, $L + 5, $py + 5, $D - 10, fn() => [$ink, 1.0]);
    $face = $T('--av-qc-face');
    AvImage::disc($im, $L + 9, $py + 9, $D - 18, fn() => [$face, 1.0]);
    $ini = QuoteOfWeek::initials($q['who']);
    $ipx = round(($D - 18) * .36);
    AvImage::text($im, $ini, 'serif-600', $ipx, $L + $D / 2, $py + ($D - AvImage::lineH('serif', $ipx, 1.0)) / 2, $hi, 1, 0, 'center', 1.0);

    $tx = $L + $D + 30;
    $ty = $y + ($personH - $textH) / 2;
    AvImage::text($im, $q['who'], 'sans-700', $s['name'], $tx, $ty, $paper, 1, 0, 'left', 1.1);
    AvImage::text($im, $q['where'], 'sans-400', $s['where'], $tx, $ty + 1.1 * $s['name'] + 6, $muted, 1, 0, 'left', 1.3);
    return $im;
}
