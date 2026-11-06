<?php
/**
 * academy/_cert.php — the one certificate renderer (PNG, GD), for a course
 * certificate and a programme certificate alike, so the two cannot drift
 * into different designs or different verify lines.
 *
 * Build spec: HANDOFF-ACADEMY-CERTIFICATE.md (artboard "D · Pennant, refined").
 * Every measurement below is in DESIGN px on a 1600×1131 canvas; the output
 * is that canvas times AVAC_CERT_S. Text positions are the TOP of the text
 * box as a browser lays it out (line box from the font's hhea metrics), and
 * are converted to GD baselines in one place, avac_cert_baseline().
 */
declare(strict_types=1);

/** Output scale: 1600×1131 design px → 3200×2262 PNG (about 270 dpi on A4). */
const AVAC_CERT_S = 2;
const AVAC_CERT_W = 1600;
const AVAC_CERT_H = 1131;

/** Colours (RGB), mirroring assets/site/av-tokens.css. No literals elsewhere. */
const AVAC_CERT_RGB = [
    'paper'     => [255, 255, 255],   // --av-white
    'ink'       => [17, 24, 39],      // --av-ink
    'text2'     => [69, 74, 86],      // --av-text-2
    'muted'     => [95, 99, 109],     // --av-muted
    'gold'      => [243, 180, 22],    // --av-gold
    'goldDeep'  => [201, 162, 75],    // --av-quote-mark
    'goldLight' => [246, 215, 126],   // --av-gold-light
    'onInk'     => [231, 233, 238],   // --av-on-ink-soft
    'white'     => [255, 255, 255],
];

/** Brand faces (relative to AV_ROOT). */
const AVAC_CERT_FONTS = [
    'serif600'  => '/assets/site/fonts/CormorantGaramond-600.ttf',
    'serif500i' => '/assets/site/fonts/CormorantGaramond-500-italic.ttf',
    'sans400'   => '/assets/site/fonts/SourceSans3-400.ttf',
    'sans600'   => '/assets/site/fonts/SourceSans3-600.ttf',
    'sans700'   => '/assets/site/fonts/SourceSans3-700.ttf',
];

/**
 * Vertical metrics per em (hhea ascender / |descender|, line gap 0), read from
 * the TTFs above. A browser's line box is built from these, so they are what
 * turns "top of the text box" into a baseline. Re-read them if the fonts change.
 */
const AVAC_CERT_VMETRICS = [
    'serif' => [0.924, 0.287],   // Cormorant Garamond
    'sans'  => [1.024, 0.400],   // Source Sans 3
];

/** CERT-03: the Academy crest. Drawn only when this file exists; nothing is drawn in its place. */
const AVAC_CERT_CREST = '/assets/site/academy-crest.png';
/** CERT-17: optional signature image. Drawn only when this file exists. */
const AVAC_CERT_SIGNATURE = '/assets/site/cert-signature.png';

/** D-2 defaults; override with ACADEMY_CERT_SIGNATORY / ACADEMY_CERT_SIGNATORY_TITLE in config.php. */
const AVAC_CERT_SIGNATORY_DEFAULT = 'Van. Ujagbe Onofua';
const AVAC_CERT_SIGNATORY_TITLE_DEFAULT = 'COO, Afrovanguard';

/** Left column (CERT-10..14) and the collision guard (§4.5.3). */
const AVAC_CERT_COL_X = 140;
const AVAC_CERT_COL_W = 700;
const AVAC_CERT_NAME_TOP = 203;
const AVAC_CERT_SIG_BAND_TOP = 869;
const AVAC_CERT_GUARD = 48;

/**
 * The copy that differs between a course and a programme certificate (§5).
 * 'kind' is the plain-text fallback's label, unchanged from before.
 *
 * @return array{kind:string, subtitle:string, leadin:string}
 */
function avac_cert_copy(string $variant): array
{
    return $variant === 'programme'
        ? ['kind' => 'Programme Certificate', 'subtitle' => 'of Programme Completion',
           'leadin' => 'for successfully completing every course in the programme']
        : ['kind' => 'Certificate of Completion', 'subtitle' => 'of Completion',
           'leadin' => 'for successfully completing the course'];
}

/**
 * §4.5.4: trim and collapse whitespace; never change case. Also compose to NFC:
 * GD places no combining marks, so a decomposed "Ọlá" (O + U+0323, a + U+0301)
 * would print its tone marks beside the letters instead of on them.
 */
function avac_cert_clean(string $s): string
{
    $s = trim((string) preg_replace('/\s+/u', ' ', $s));
    if (class_exists('Normalizer')) {
        $n = Normalizer::normalize($s, Normalizer::FORM_C);
        if (is_string($n)) $s = $n;
    }
    return $s;
}

/** @return array{0:string,1:string} signatory name and title, from config or the D-2 defaults */
function avac_cert_signatory(): array
{
    $n = defined('ACADEMY_CERT_SIGNATORY') ? trim((string) constant('ACADEMY_CERT_SIGNATORY')) : '';
    $t = defined('ACADEMY_CERT_SIGNATORY_TITLE') ? trim((string) constant('ACADEMY_CERT_SIGNATORY_TITLE')) : '';
    return [$n !== '' ? $n : AVAC_CERT_SIGNATORY_DEFAULT, $t !== '' ? $t : AVAC_CERT_SIGNATORY_TITLE_DEFAULT];
}

/** Greedy wrap at the last space that fits. A word wider than $max sits on a line of its own. */
function avac_cert_wrap(string $text, float $px, float $max, callable $measure): array
{
    $lines = []; $cur = '';
    foreach (explode(' ', $text) as $w) {
        $try = $cur === '' ? $w : $cur . ' ' . $w;
        if ($cur === '' || $measure($try, $px) <= $max) { $cur = $try; continue; }
        $lines[] = $cur; $cur = $w;
    }
    $lines[] = $cur;
    return $lines;
}

/**
 * §4.5 fitting rule, pure: no image is drawn, $measure(string $text, float $px): float
 * returns a width in design px. Tries $start, then steps down by $step to $floor
 * using at most $maxLines; at the floor allows $floorLines. Beyond that it shrinks
 * the whole block (a single over-wide word is scaled to fit) until it fits in
 * $floorLines. It never truncates: implode(' ', lines) is always the input.
 *
 * @return array{size:int, lines:list<string>, width:float}
 */
function avac_cert_fit(string $text, callable $measure, float $max, int $start, int $floor, int $step, int $maxLines, int $floorLines): array
{
    $text = avac_cert_clean($text);
    $try = static function (int $px, int $lines) use ($text, $measure, $max): ?array {
        $ls = avac_cert_wrap($text, $px, $max, $measure);
        $wid = 0.0;
        foreach ($ls as $l) $wid = max($wid, (float) $measure($l, $px));
        return (count($ls) <= $lines && $wid <= $max) ? ['size' => $px, 'lines' => $ls, 'width' => $wid] : null;
    };
    for ($px = $start; $px >= $floor; $px -= $step) {
        if ($r = $try($px, $maxLines)) return $r;
    }
    if ($floorLines > $maxLines && ($r = $try($floor, $floorLines))) return $r;
    /* Below the floor: scale to the widest line, or step by one when the
       trouble is the line count. 8 px is a backstop, never reached by a name. */
    $px = $floor;
    while ($px > 8) {
        $ls = avac_cert_wrap($text, $px, $max, $measure);
        $wid = 0.0;
        foreach ($ls as $l) $wid = max($wid, (float) $measure($l, $px));
        $next = $wid > $max ? (int) floor($px * $max / $wid) : $px - 1;
        $px = max(8, min($px - 1, $next));
        if ($r = $try($px, $floorLines)) return $r;
    }
    $ls = avac_cert_wrap($text, 8, $max, $measure);
    return ['size' => 8, 'lines' => $ls, 'width' => $max];
}

/** Line-box height in design px. $lh null = CSS "normal" (ascender + descender). */
function avac_cert_line_h(string $family, float $px, ?float $lh): float
{
    [$a, $d] = AVAC_CERT_VMETRICS[$family];
    return $lh === null ? ($a + $d) * $px : $lh * $px;
}

/** Baseline (design px) of a line whose box top is $top: half-leading plus ascender. */
function avac_cert_baseline(string $family, float $top, float $px, ?float $lh): float
{
    [$a, $d] = AVAC_CERT_VMETRICS[$family];
    return $top + (avac_cert_line_h($family, $px, $lh) - ($a + $d) * $px) / 2 + $a * $px;
}

/**
 * The left column's flow (CERT-11..14) and the collision guard (§4.5.3), pure.
 * $measure(string $text, float $px) measures in Cormorant Garamond 600.
 * $limit is the lowest the title block may reach (869 − 48 = 821).
 */
function avac_cert_layout(string $name, string $title, string $variant, callable $measure, float $limit = AVAC_CERT_SIG_BAND_TOP - AVAC_CERT_GUARD): array
{
    $nameStart = 108; $titleStart = 46;
    while (true) {
        $n = avac_cert_fit($name, $measure, AVAC_CERT_COL_W, $nameStart, 72, 4, 2, 2);
        $t = avac_cert_fit($title, $measure, AVAC_CERT_COL_W, $titleStart, 34, 2, 2, 3);
        $ruleTop = AVAC_CERT_NAME_TOP + count($n['lines']) * avac_cert_line_h('serif', $n['size'], 0.98) + 40;
        $leadTop = $ruleTop + 3 + 40;
        $titleTop = $leadTop + avac_cert_line_h('sans', 22, null) + 6;
        $titleBottom = $titleTop + count($t['lines']) * avac_cert_line_h('serif', $t['size'], 1.1);
        if ($titleBottom <= $limit) break;
        if ($nameStart > 72) { $nameStart -= 4; continue; }
        if ($titleStart > 34) { $titleStart -= 2; continue; }
        break;   // both at their floors: nothing left to give
    }
    return ['name' => $n, 'title' => $t, 'copy' => avac_cert_copy($variant), 'nameTop' => (float) AVAC_CERT_NAME_TOP,
        'ruleTop' => $ruleTop, 'leadTop' => $leadTop, 'titleTop' => $titleTop, 'titleBottom' => $titleBottom];
}

/** Absolute font paths, or null when GD/FreeType or any brand face is missing. */
function avac_cert_font_paths(): ?array
{
    if (!extension_loaded('gd') || !function_exists('imagettftext')) return null;
    $out = [];
    foreach (AVAC_CERT_FONTS as $k => $rel) {
        if (!is_file(AV_ROOT . $rel)) return null;
        $out[$k] = AV_ROOT . $rel;
    }
    return $out;
}

/** Advance width in design px of $text in $font at $px design px (GD sizes are points at 96 dpi). */
function avac_cert_measure(string $font, string $text, float $px): float
{
    if ($text === '') return 0.0;
    $bb = imagettfbbox($px * AVAC_CERT_S * 0.75, 0, $font, $text);
    return (max($bb[2], $bb[4]) - min(0, $bb[0], $bb[6])) / AVAC_CERT_S;
}

/** Ink extents [left, top, right] of one glyph relative to its pen origin, in output px (cached). */
function avac_cert_ink(string $font, float $pt, string $g): array
{
    static $cache = [];
    $key = $font . '|' . $pt . '|' . $g;
    if (isset($cache[$key])) return $cache[$key];
    $n = (int) ceil($pt * 4); $o = (int) ceil($pt * 2);
    $im = imagecreatetruecolor($n, $n);
    imagefilledrectangle($im, 0, 0, $n - 1, $n - 1, imagecolorallocate($im, 255, 255, 255));
    imagettftext($im, $pt, 0, $o, $o, imagecolorallocate($im, 0, 0, 0), $font, $g);
    $l = $n; $r = -1; $t = $n;
    for ($y = 0; $y < $n; $y++) for ($x = 0; $x < $n; $x++) {
        if ((imagecolorat($im, $x, $y) & 0xFF) < 128) { $l = min($l, $x); $r = max($r, $x); $t = min($t, $y); }
    }
    imagedestroy($im);
    return $cache[$key] = $r < 0 ? [0, 0, 0] : [$l - $o, $t - $o, $r - $o];
}

/**
 * imagettftext, plus mark placement. GD does no shaping, so a combining mark
 * that has no precomposed form after NFC (Yoruba ọ̀, ẹ́: dot below AND a tone)
 * would print beside its letter. Each such mark is centred on its base letter's
 * ink here, and an above-mark is lifted over a letter taller than "o".
 */
function avac_cert_text($im, float $pt, int $x, int $y, int $col, string $font, string $t): void
{
    if (!preg_match('/\p{Mn}/u', $t)) { imagettftext($im, $pt, 0, $x, $y, $col, $font, $t); return; }
    preg_match_all('/\P{M}\p{M}*/u', $t, $m);
    $prefix = '';
    foreach ($m[0] as $cluster) {
        $base = (string) preg_replace('/\p{M}+/u', '', $cluster);
        $marks = preg_split('//u', substr($cluster, strlen($base)), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        /* Pen position from the measured prefix keeps kerning as a plain run would. */
        $bb = $prefix === '' ? [0, 0, 0] : imagettfbbox($pt, 0, $font, $prefix);
        $bx = $x + ($prefix === '' ? 0 : $bb[2]);
        imagettftext($im, $pt, 0, (int) round($bx), $y, $col, $font, $base);
        $bi = avac_cert_ink($font, $pt, $base);
        $oTop = avac_cert_ink($font, $pt, 'o')[1];
        foreach ($marks as $mk) {
            $mi = avac_cert_ink($font, $pt, $mk);
            $dx = ($bi[0] + $bi[2]) / 2 - ($mi[0] + $mi[2]) / 2;
            $above = $mi[1] < $oTop;   // the mark's ink starts above the x-height
            $dy = ($above && $bi[1] < $oTop) ? $bi[1] - $oTop : 0;
            imagettftext($im, $pt, 0, (int) round($bx + $dx), $y + (int) round($dy), $col, $font, $mk);
        }
        $prefix .= $base;
    }
}

/**
 * Draw shapes antialiased: $draw($img, $map) paints on a transparent image at
 * 4× the output resolution, which is then resampled down onto $im. $map(x, y)
 * turns design px into that image's pixels. Box = design px.
 */
function avac_cert_aa($im, float $x, float $y, float $w, float $h, array $rgb, callable $draw): void
{
    $k = 4; $S = AVAC_CERT_S;
    $ox = (int) floor($x * $S); $oy = (int) floor($y * $S);
    $dw = (int) ceil(($x + $w) * $S) - $ox; $dh = (int) ceil(($y + $h) * $S) - $oy;
    $src = imagecreatetruecolor($dw * $k, $dh * $k);
    imagealphablending($src, false);
    /* Transparent pixels carry the shape's own colour, so the edge blend has no dark fringe. */
    imagefilledrectangle($src, 0, 0, $dw * $k - 1, $dh * $k - 1, imagecolorallocatealpha($src, $rgb[0], $rgb[1], $rgb[2], 127));
    imagesavealpha($src, true);
    $col = imagecolorallocatealpha($src, $rgb[0], $rgb[1], $rgb[2], 0);
    $map = static fn(float $px, float $py): array => [
        (int) round($px * $S * $k - $ox * $k), (int) round($py * $S * $k - $oy * $k)];
    $draw($src, $map, $col);
    imagealphablending($im, true);
    imagecopyresampled($im, $src, $ox, $oy, 0, 0, $dw, $dh, $dw * $k, $dh * $k);
    imagedestroy($src);
}

/** Copy a PNG with alpha into a box (design px), scaled to $h tall, no wider than $maxW. */
function avac_cert_image($im, string $file, float $x, float $top, float $h, float $maxW, bool $centre): void
{
    $src = @imagecreatefrompng($file);
    if (!$src) return;
    $sw = imagesx($src); $sh = imagesy($src);
    $scale = min($h / $sh, $maxW / $sw);
    $w = $sw * $scale; $hh = $sh * $scale;
    $left = $centre ? $x - $w / 2 : $x;
    $S = AVAC_CERT_S;
    imagealphablending($src, true); imagesavealpha($src, true);
    imagealphablending($im, true);
    imagecopyresampled($im, $src, (int) round($left * $S), (int) round($top * $S), 0, 0,
        (int) round($w * $S), (int) round($hh * $S), $sw, $sh);
    imagedestroy($src);
}

/**
 * Render the certificate as PNG bytes, or null when GD or a brand font is not
 * available. $trace, when given, receives every string drawn (for the tests).
 *
 * @param string $variant 'course' | 'programme'
 */
function avac_cert_render(string $learner, string $variant, string $title, string $issuedAt, string $serial, ?array &$trace = null): ?string
{
    $F = avac_cert_font_paths();
    if ($F === null) return null;
    $trace = [];
    $S = AVAC_CERT_S;
    $learner = avac_cert_clean($learner); $title = avac_cert_clean($title);
    $im = imagecreatetruecolor(AVAC_CERT_W * $S, AVAC_CERT_H * $S);
    imagealphablending($im, true);
    $C = [];
    foreach (AVAC_CERT_RGB as $k => $rgb) $C[$k] = imagecolorallocate($im, $rgb[0], $rgb[1], $rgb[2]);
    $px = static fn(float $v): int => (int) round($v * $S);
    /* A filled rectangle in design px: [x, y) to [x+w, y+h). */
    $rect = static function (float $x, float $y, float $w, float $h, string $c) use ($im, $C, $px): void {
        imagefilledrectangle($im, $px($x), $px($y), $px($x + $w) - 1, $px($y + $h) - 1, $C[$c]);
    };
    $measure = static fn(string $face, string $t, float $size): float => avac_cert_measure($F[$face], $t, $size);
    /* Draw one line: $x is the left of the box (or its centre when $centre), $top its top. */
    $text = static function (string $face, float $size, string $c, float $x, float $top, string $t, ?float $lh = null, bool $centre = false)
        use ($im, $C, $F, $px, $measure, &$trace): float {
        $w = $measure($face, $t, $size);
        $left = $centre ? $x - $w / 2 : $x;
        $base = avac_cert_baseline(str_starts_with($face, 'serif') ? 'serif' : 'sans', $top, $size, $lh);
        avac_cert_text($im, $size * AVAC_CERT_S * 0.75, $px($left), $px($base), $C[$c], $F[$face], $t);
        $trace[] = $t;
        return $w;
    };

    /* Paper, then CERT-01 frame (1 px, inset 40), drawn before the pennant covers its top. */
    $rect(0, 0, AVAC_CERT_W, AVAC_CERT_H, 'paper');
    $rect(40, 40, 1520, 1, 'goldDeep'); $rect(40, 1090, 1520, 1, 'goldDeep');
    $rect(40, 40, 1, 1051, 'goldDeep'); $rect(1559, 40, 1, 1051, 'goldDeep');

    /* CERT-02 pennant: straight sides drawn crisp, the pointed foot supersampled. */
    $rect(920, 0, 560, 808, 'ink');
    avac_cert_aa($im, 920, 800, 560, 140, AVAC_CERT_RGB['ink'], static function ($img, callable $m, int $col): void {
        $pts = [];
        foreach ([[920, 800], [1480, 800], [1480, 808], [1200, 940], [920, 808]] as [$x, $y]) array_push($pts, ...$m($x, $y));
        imagefilledpolygon($img, $pts, $col);
    });
    $cx = 1200.0;

    /* CERT-03 crest: 184 tall, top 104, centred. Nothing at all when the file is absent. */
    if (is_file(AV_ROOT . AVAC_CERT_CREST)) avac_cert_image($im, AV_ROOT . AVAC_CERT_CREST, $cx, 104, 184, 560, true);

    /* CERT-04 tracked label, drawn glyph by glyph and centred on its tracked width. */
    $label = 'AFROVANGUARD ACADEMY'; $track = 0.42 * 14;
    $glyphs = preg_split('//u', $label, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $adv = [];
    foreach ($glyphs as $g) $adv[] = $measure('sans700', $g . 'H', 14) - $measure('sans700', 'H', 14);
    $tw = array_sum($adv) + $track * (count($glyphs) - 1);
    $gx = $cx - $tw / 2;
    $base = avac_cert_baseline('sans', 314, 14, null);
    foreach ($glyphs as $i => $g) {
        if ($g !== ' ') imagettftext($im, 14 * $S * 0.75, 0, $px($gx), $px($base), $C['goldLight'], $F['sans700'], $g);
        $gx += $adv[$i] + $track;
    }
    $trace[] = $label;

    /* CERT-05 divider: rule, 12, diamond (7×7 square turned 45°), 12, rule; centre y 382. */
    $dy = 382.0; $rowW = 48 + 12 + 7 + 12 + 48; $rx = $cx - $rowW / 2;
    $rect($rx, $dy - 0.5, 48, 1, 'gold');
    $rect($rx + $rowW - 48, $dy - 0.5, 48, 1, 'gold');
    $half = 7 * M_SQRT2 / 2;
    avac_cert_aa($im, $cx - 6, $dy - 6, 12, 12, AVAC_CERT_RGB['gold'], static function ($img, callable $m, int $col) use ($cx, $dy, $half): void {
        imagefilledpolygon($img, [...$m($cx, $dy - $half), ...$m($cx + $half, $dy), ...$m($cx, $dy + $half), ...$m($cx - $half, $dy)], $col);
    });

    /* CERT-06, CERT-07: title and subtitle (line-height 1). */
    $copy = avac_cert_copy($variant);
    $text('serif600', 104, 'white', $cx, 425, 'Certificate', 1.0, true);
    $text('serif500i', 42, 'onInk', $cx, 533, $copy['subtitle'], 1.0, true);

    /* CERT-08: five 6 px dots, 18 px gaps, first at x 1149, centre y 651. */
    avac_cert_aa($im, 1146, 645, 108, 12, AVAC_CERT_RGB['goldDeep'], static function ($img, callable $m, int $col): void {
        $d = $m(6, 0)[0] - $m(0, 0)[0];   // 6 design px, in this image's pixels
        for ($i = 0; $i < 5; $i++) {
            [$x, $y] = $m(1152 + 24 * $i, 651);
            imagefilledellipse($img, $x, $y, $d, $d, $col);
        }
    });

    /* Left column: CERT-10 .. CERT-14, flowing below the name. */
    $L = avac_cert_layout($learner, $title, $variant, static fn(string $t, float $s): float => $measure('serif600', $t, $s));
    $text('sans400', 24, 'text2', AVAC_CERT_COL_X, 150, 'This is to proudly certify that');
    $n = $L['name'];
    foreach ($n['lines'] as $i => $line) {
        $text('serif600', $n['size'], 'ink', AVAC_CERT_COL_X, $L['nameTop'] + $i * 0.98 * $n['size'], $line, 0.98);
    }
    $rect(AVAC_CERT_COL_X, $L['ruleTop'], 96, 3, 'gold');
    $text('sans400', 22, 'text2', AVAC_CERT_COL_X, $L['leadTop'], $copy['leadin']);
    $t = $L['title'];
    foreach ($t['lines'] as $i => $line) {
        $text('serif600', $t['size'], 'ink', AVAC_CERT_COL_X, $L['titleTop'] + $i * 1.1 * $t['size'], $line, 1.1);
    }

    /* CERT-17: optional signature, in the band above the signatory rule, bottom-aligned to it. */
    $sig = AV_ROOT . AVAC_CERT_SIGNATURE;
    if (is_file($sig) && ($info = @getimagesize($sig)) && $info[0] > 0 && $info[1] > 0) {
        $sc = min(72 / $info[1], 300 / $info[0]);
        avac_cert_image($im, $sig, 152, 941 - $info[1] * $sc, 72, 300, false);
    }

    /* CERT-15, CERT-16: signatory and date, fixed. */
    [$sigName, $sigTitle] = avac_cert_signatory();
    $ts = strtotime($issuedAt);
    $date = date('j F Y', $ts !== false ? $ts : time());
    foreach ([[140, $sigName, $sigTitle], [518, $date, 'Date awarded']] as [$x, $top, $sub]) {
        $rect($x, 941, 322, 1, 'ink');
        $text('serif600', 27, 'ink', $x, 954, $top);
        $text('sans400', 16, 'muted', $x, 989, $sub);
    }

    /* CERT-18: serial line, the serial itself in SemiBold ink, printed in full. */
    $x = (float) AVAC_CERT_COL_X;
    foreach ([['sans400', 'muted', 'Certificate '], ['sans600', 'ink', $serial],
              ['sans400', 'muted', ' · Verify at afrovanguard.org.ng/academy/verify']] as [$face, $c, $run]) {
        $x += $text($face, 13, $c, $x, 1050, $run);
    }

    ob_start(); imagepng($im); $png = (string) ob_get_clean();
    imagedestroy($im);
    return $png;
}

/**
 * Send the certificate. Headers unchanged from before; without GD or the brand
 * fonts it falls back to the plain-text line, as it always has.
 *
 * @param string $variant 'course' | 'programme'
 */
function avac_cert_png(string $learner, string $variant, string $title, string $issuedAt, string $serial, string $file): void
{
    $png = avac_cert_render($learner, $variant, $title, $issuedAt, $serial);
    if ($png === null) {
        header('Content-Type: text/plain; charset=utf-8');
        echo avac_cert_copy($variant)['kind'] . ' ' . $serial . ' — ' . $learner . ' — ' . $title;
        return;
    }
    header('Content-Type: image/png');
    header('Content-Disposition: inline; filename="' . preg_replace('/[^a-z0-9.-]/', '', strtolower($file)) . '"');
    echo $png;
}
