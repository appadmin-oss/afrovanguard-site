<?php
/**
 * lib/AvOg.php — the per-item Open Graph cards (1200 × 630), design
 * "Afrovanguard OG Images":
 *
 *   diary()   1b · Diary entry   — sand, gold top rule, eyebrow + reference
 *   appeal()  1c · Project/programme layout, used for a Give appeal: ink,
 *             photo on the right half, and the figure + bar that make
 *             somebody tap (kept from the old card)
 *   course()  1d · Academy course — paper, gold frame, price pill
 *
 * The static ones (1a default, 1e default image, 1f square) are exported
 * PNGs in assets/og/. Drawn with lib/AvImage.php; colours are tokens.
 * The diary/, give/ and academy/ og.php routes call these and cache.
 */
declare(strict_types=1);

require_once __DIR__ . '/AvImage.php';

final class AvOg
{
    public const W = 1200;
    public const H = 630;

    /** Diamond + wordmark, vertically centred on a row starting at $top. Returns the row height. */
    private static function brand($im, float $x, float $top, float $px, float $dia, array $ink, array $mark, float $gap): float
    {
        $h = AvImage::lineH('serif', $px);
        AvImage::diamond($im, $x + $dia / 2, $top + $h / 2, $dia, $mark);
        AvImage::text($im, 'Afrovanguard', 'serif-600', $px, $x + $dia + $gap, $top, $ink);
        return $h;
    }

    /** Title lines at the largest step that fits $maxLines. @return array{0:float,1:string[]} */
    private static function fit(string $text, string $font, array $sizes, float $maxW, int $maxLines, float $track = 0.0): array
    {
        foreach ($sizes as $px) {
            $lines = AvImage::wrap($text, $font, $px, $maxW, false, $track); // the design wraps these greedily (no text-wrap: balance)
            if (count($lines) <= $maxLines) return [$px, $lines];
        }
        $px = end($sizes);
        $lines = AvImage::wrap($text, $font, $px, $maxW, false, $track);
        if (count($lines) > $maxLines) {
            $lines = array_slice($lines, 0, $maxLines);
            $lines[$maxLines - 1] = rtrim($lines[$maxLines - 1], ' .,;:') . '…';
        }
        return [$px, $lines];
    }

    /** A pill: rounded ends drawn as discs. */
    private static function pill($im, float $x, float $y, float $w, float $h, array $rgb): void
    {
        $d = (int) round($h);
        AvImage::disc($im, (int) round($x), (int) round($y), $d, fn() => [$rgb, 1.0]);
        AvImage::disc($im, (int) round($x + $w - $h), (int) round($y), $d, fn() => [$rgb, 1.0]);
        imagefilledrectangle($im, (int) round($x + $h / 2), (int) round($y), (int) round($x + $w - $h / 2), (int) round($y + $h) - 1, AvImage::col($im, $rgb));
    }

    /**
     * 1b · Diary entry.
     * @param array{title:string,category?:string,ref?:string,date?:string,minutes?:int} $a
     */
    public static function diary(array $a)
    {
        $T = [AvImage::class, 'token'];
        $W = self::W; $H = self::H; $L = 80; $R = $W - 80;
        $im = AvImage::canvas($W, $H, $T('--av-sand'));
        imagefilledrectangle($im, 0, 0, $W, 11, AvImage::col($im, $T('--av-gold')));

        $top = 12 + 72; $bot = $H - 72;
        $eyebrow = mb_strtoupper('The Diary' . (!empty($a['category']) ? ' · ' . $a['category'] : ''));
        $rowA = AvImage::lineH('sans', 22);
        AvImage::text($im, $eyebrow, 'sans-700', 22, $L, $top, $T('--av-gold-text'), 1, .16);
        if (!empty($a['ref'])) AvImage::text($im, (string) $a['ref'], 'sans-400', 20, $R, $top + ($rowA - AvImage::lineH('sans', 20)) / 2, $T('--av-muted-2'), 1, .04, 'right');

        $meta = implode(' · ', array_filter([(string) ($a['date'] ?? ''), !empty($a['minutes']) ? (int) $a['minutes'] . ' min read' : '']));
        $rowC = max(AvImage::lineH('serif', 34), AvImage::lineH('sans', 24));
        $cTop = $bot - $rowC;
        self::brand($im, $L, $cTop + ($rowC - AvImage::lineH('serif', 34)) / 2, 34, 14, $T('--av-ink'), $T('--av-ink'), 14);
        if ($meta !== '') AvImage::text($im, $meta, 'sans-400', 24, $R, $cTop + ($rowC - AvImage::lineH('sans', 24)) / 2, $T('--av-nav-sub'), 1, 0, 'right');

        [$px, $lines] = self::fit((string) $a['title'], 'serif-400', [92, 80, 68, 58], 1000, 3, -.015);
        $tH = count($lines) * .98 * $px;
        $y = $top + $rowA + (($cTop - $top - $rowA) - $tH) / 2;
        foreach ($lines as $i => $ln) AvImage::text($im, $ln, 'serif-400', $px, $L, $y + $i * .98 * $px, $T('--av-ink'), 1, -.015, 'left', .98);
        return $im;
    }

    /**
     * 1c · the project/programme layout, for an appeal. With a photo it takes
     * the right half; without, the type takes the width and the 1a diamonds
     * sit top-right.
     * @param array{title:string,kicker:string,figure:string,percent:?int,met:bool,photo:?object,url:string} $o
     */
    public static function appeal(array $o)
    {
        $T = [AvImage::class, 'token'];
        $W = self::W; $H = self::H;
        $im = AvImage::canvas($W, $H, $T('--av-ink'));
        $photo = $o['photo'] ?? null;
        $L = 80;
        if ($photo) {
            AvImage::cover($im, $photo, $W / 2, 0, $W / 2, $H);
            $colR = $W / 2 - 64;
        } else {
            AvImage::diamond($im, $W - 120, 120, 520, $T('--av-og-line'), false);
            AvImage::diamond($im, $W - 120, 120, 320, $T('--av-og-line-2'), false);
            $colR = $W - 80;
        }
        $maxW = $colR - $L;
        $gl = $T('--av-gold-light');

        self::brand($im, $L, 72, 32, 14, $T('--av-white'), $T('--av-gold'), 12);
        $urlH = AvImage::lineH('sans', 22);
        AvImage::text($im, (string) $o['url'], 'sans-400', 22, $L, $H - 72 - $urlH, $gl);

        // middle block, bottom-aligned above the url as space-between puts it
        [$px, $lines] = self::fit((string) $o['title'], 'serif-400', $photo ? [112, 84, 68, 56, 48] : [112, 92, 76, 64], $maxW, 3);
        $kH = AvImage::lineH('sans', 22);
        $tH = count($lines) * .92 * $px;
        $figH = AvImage::lineH('sans', 26);
        $barH = $o['percent'] !== null ? 8 + 16 : 0;
        $blockH = $kH + 18 + $tH + 20 + $figH + $barH;
        $topRow = 72 + AvImage::lineH('serif', 32);
        $bottomRow = $H - 72 - $urlH;
        $y = $topRow + (($bottomRow - $topRow) - $blockH) / 2;

        AvImage::text($im, mb_strtoupper((string) $o['kicker']), 'sans-700', 22, $L, $y, $gl, 1, .16);
        $y += $kH + 18;
        foreach ($lines as $i => $ln) AvImage::text($im, $ln, 'serif-400', $px, $L, $y + $i * .92 * $px, $T('--av-white'), 1, 0, 'left', .92);
        $y += $tH + 20;
        $fpx = 26; while ($fpx > 18 && AvImage::width((string) $o['figure'], 'sans-400', $fpx) > $maxW) $fpx -= 2;
        AvImage::text($im, (string) $o['figure'], 'sans-400', $fpx, $L, $y + ($figH - AvImage::lineH('sans', $fpx)) / 2, $T('--av-on-ink-muted'));
        if ($o['percent'] !== null) {
            $by = (int) round($y + $figH + 16);
            imagefilledrectangle($im, $L, $by, (int) $colR, $by + 7, AvImage::col($im, $T('--av-og-line')));
            $fill = (int) round(($colR - $L) * max(0, min(100, (int) $o['percent'])) / 100);
            if ($fill > 0) imagefilledrectangle($im, $L, $by, $L + max(6, $fill), $by + 7, AvImage::col($im, $o['met'] ? $T('--av-live') : $T('--av-gold')));
        }
        return $im;
    }

    /**
     * 1d · Academy course.
     * @param array{title:string,pill?:string,foot?:string} $c
     */
    public static function course(array $c)
    {
        $T = [AvImage::class, 'token'];
        $W = self::W; $H = self::H; $L = 80; $R = $W - 80;
        $im = AvImage::canvas($W, $H, $T('--av-paper'));
        $fr = AvImage::col($im, $T('--av-og-frame'));
        imagesetthickness($im, 2);
        imagerectangle($im, 28, 28, $W - 29, $H - 29, $fr);
        imagesetthickness($im, 1);

        $top = 72; $bot = $H - 72;
        $pill = trim((string) ($c['pill'] ?? ''));
        $pillH = AvImage::lineH('sans', 22) + 16;
        $rowA = max(AvImage::lineH('sans', 22), $pill !== '' ? $pillH : 0);
        AvImage::text($im, 'AFROVANGUARD ACADEMY · COURSE', 'sans-700', 22, $L, $top + ($rowA - AvImage::lineH('sans', 22)) / 2, $T('--av-gold-text'), 1, .16);
        if ($pill !== '') {
            $pw = AvImage::width($pill, 'sans-700', 22) + 36;
            self::pill($im, $R - $pw, $top + ($rowA - $pillH) / 2, $pw, $pillH, $T('--av-ink'));
            AvImage::text($im, $pill, 'sans-700', 22, $R - $pw + 18, $top + ($rowA - $pillH) / 2 + 8, $T('--av-white'));
        }

        $rowC = max(AvImage::lineH('serif', 32), AvImage::lineH('sans', 24));
        $cTop = $bot - $rowC;
        AvImage::text($im, (string) ($c['foot'] ?? 'Certificate on completion'), 'sans-400', 24, $L, $cTop + ($rowC - AvImage::lineH('sans', 24)) / 2, $T('--av-nav-sub'));
        $bw = 14 + 12 + AvImage::width('Afrovanguard', 'serif-600', 32);
        self::brand($im, $R - $bw, $cTop + ($rowC - AvImage::lineH('serif', 32)) / 2, 32, 14, $T('--av-ink'), $T('--av-gold'), 12);

        [$px, $lines] = self::fit((string) $c['title'], 'serif-400', [100, 86, 72, 60], 980, 3);
        $tH = count($lines) * .95 * $px;
        $y = $top + $rowA + (($cTop - $top - $rowA) - $tH) / 2;
        foreach ($lines as $i => $ln) AvImage::text($im, $ln, 'serif-400', $px, $L, $y + $i * .95 * $px, $T('--av-ink'), 1, 0, 'left', .95);
        return $im;
    }
}
