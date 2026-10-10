<?php
/**
 * lib/AvImage.php — the small GD toolkit the share images are drawn with
 * (quote card, Diary / Give / Academy Open Graph cards).
 *
 * The host has no headless browser, so a PNG that has to exist at a URL (an
 * og:image, a "Download card" link) is drawn here, in PHP, from the same
 * design numbers the HTML uses:
 *
 *  · colours come from assets/site/av-tokens.css by NAME (token()), so the
 *    images change with the tokens and this file holds no colour literals;
 *  · fonts are the self-hosted TTFs in assets/site/fonts/;
 *  · sizes are CSS pixels. GD's FreeType renders at 96 dpi, so a CSS px size
 *    is px × 0.75 in GD points; text is placed by its CSS line box (the
 *    metrics below are each family's ascent/descent, measured in Chromium),
 *    so `y` is always the TOP of the line box, as in the design.
 *
 * Every helper is pure GD; nothing here reads the request or the database.
 */
declare(strict_types=1);

final class AvImage
{
    /** family => [ascent, descent] as a fraction of the font size (CSS line-height: normal = sum). */
    private const METRICS = ['serif' => [0.924, 0.287], 'sans' => [1.024, 0.400]];

    private const FONTS = [
        'serif-500' => 'CormorantGaramond-500.ttf', 'serif-600' => 'CormorantGaramond-600.ttf',
        'serif-500i' => 'CormorantGaramond-500-italic.ttf', 'serif-400' => 'CormorantGaramond-400.ttf',
        'sans-400' => 'SourceSans3-400.ttf', 'sans-600' => 'SourceSans3-600.ttf', 'sans-700' => 'SourceSans3-700.ttf',
    ];

    /** @var array<string,array{0:int,1:int,2:int}>|null */
    private static ?array $tokens = null;

    /** True when GD can draw text with the site fonts. */
    public static function available(): bool
    {
        return extension_loaded('gd') && function_exists('imagettftext') && is_file(self::font('serif-500'));
    }

    public static function font(string $key): string
    {
        return AV_ROOT . '/assets/site/fonts/' . (self::FONTS[$key] ?? self::FONTS['sans-400']);
    }

    /** A colour token from av-tokens.css as [r,g,b]. Unknown → mid grey, never a fatal. */
    public static function token(string $name): array
    {
        if (self::$tokens === null) {
            self::$tokens = [];
            $css = (string) @file_get_contents(AV_ROOT . '/assets/site/av-tokens.css');
            if (preg_match_all('/(--av-[\w-]+)\s*:\s*#([0-9a-fA-F]{6}|[0-9a-fA-F]{3})\b/', $css, $m, PREG_SET_ORDER)) {
                foreach ($m as [, $k, $hex]) {
                    if (strlen($hex) === 3) $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
                    self::$tokens[$k] ??= [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
                }
            }
        }
        return self::$tokens[$name] ?? [128, 128, 128];
    }

    /** A truecolor canvas filled with $rgb. */
    public static function canvas(int $w, int $h, array $rgb)
    {
        $im = imagecreatetruecolor($w, $h);
        imagealphablending($im, true);
        imagesavealpha($im, true);
        imagefilledrectangle($im, 0, 0, $w, $h, self::col($im, $rgb));
        return $im;
    }

    /** Allocate $rgb at opacity $a (0–1). */
    public static function col($im, array $rgb, float $a = 1.0): int
    {
        return imagecolorallocatealpha($im, $rgb[0], $rgb[1], $rgb[2], (int) round(127 * (1 - max(0.0, min(1.0, $a)))));
    }

    /** Mix two colours: $t = 0 → $a, 1 → $b. */
    public static function mix(array $a, array $b, float $t): array
    {
        return [(int) round($a[0] + ($b[0] - $a[0]) * $t), (int) round($a[1] + ($b[1] - $a[1]) * $t), (int) round($a[2] + ($b[2] - $a[2]) * $t)];
    }

    private static function pt(float $px): float
    {
        return $px * 0.75;
    }

    private static function family(string $font): string
    {
        return str_starts_with($font, 'serif') ? 'serif' : 'sans';
    }

    /** Width of $text in CSS px, with letter-spacing $track (em). */
    public static function width(string $text, string $font, float $px, float $track = 0.0): float
    {
        if ($text === '') return 0.0;
        $f = self::font($font);
        if ($track == 0.0) {
            $b = imagettfbbox(self::pt($px), 0, $f, $text);
            return (float) ($b[2] - $b[0]);
        }
        $b = imagettfbbox(self::pt($px), 0, $f, $text);
        return (float) ($b[2] - $b[0]) + mb_strlen($text) * $track * $px;
    }

    /** Pen offset of each character: the kerned width of the text before it. */
    private static function offsets(string $text, string $f, float $px): array
    {
        $chars = mb_str_split($text); $out = []; $pre = '';
        foreach ($chars as $ch) {
            $b = $pre === '' ? [0, 0, 0] : imagettfbbox(self::pt($px), 0, $f, $pre . 'l');
            $l = imagettfbbox(self::pt($px), 0, $f, 'l');
            $out[] = [$ch, $pre === '' ? 0.0 : (float) (($b[2] - $b[0]) - ($l[2] - $l[0]))];
            $pre .= $ch;
        }
        return $out;
    }

    /** Line-box height for a size and CSS line-height (null = normal). */
    public static function lineH(string $font, float $px, ?float $lh = null): float
    {
        [$asc, $desc] = self::METRICS[self::family($font)];
        return $lh === null ? ($asc + $desc) * $px : $lh * $px;
    }

    /** Baseline offset from the top of a line box. */
    public static function baseline(string $font, float $px, ?float $lh = null): float
    {
        [$asc, $desc] = self::METRICS[self::family($font)];
        $L = self::lineH($font, $px, $lh);
        return ($L - ($asc + $desc) * $px) / 2 + $asc * $px;
    }

    /**
     * Draw one line whose line box starts at $top. $align: left (x = left edge),
     * right (x = right edge), center. Returns the drawn width.
     */
    public static function text($im, string $text, string $font, float $px, float $x, float $top, array $rgb,
                                float $a = 1.0, float $track = 0.0, string $align = 'left', ?float $lh = null): float
    {
        $w = self::width($text, $font, $px, $track);
        if ($align === 'right') $x -= $w; elseif ($align === 'center') $x -= $w / 2;
        $y = (int) round($top + self::baseline($font, $px, $lh));
        $c = self::col($im, $rgb, $a);
        $f = self::font($font);
        if ($track == 0.0) {
            imagettftext($im, self::pt($px), 0, (int) round($x), $y, $c, $f, $text);
        } else {
            // CSS letter-spacing: every glyph keeps its kerned pen position, plus i × spacing.
            foreach (self::offsets($text, $f, $px) as $i => [$ch, $off]) {
                if (trim($ch) === '') continue;
                imagettftext($im, self::pt($px), 0, (int) round($x + $off + $i * $track * $px), $y, $c, $f, $ch);
            }
        }
        return $w;
    }

    /**
     * Wrap to $maxW. With $balance the lines are evened out the way CSS
     * `text-wrap: balance` does: the narrowest width that keeps the line count.
     * @return string[]
     */
    public static function wrap(string $text, string $font, float $px, float $maxW, bool $balance = false, float $track = 0.0): array
    {
        $greedy = function (float $w) use ($text, $font, $px, $track): array {
            $lines = []; $cur = '';
            foreach (preg_split('/\s+/u', trim($text)) as $word) {
                $try = $cur === '' ? $word : $cur . ' ' . $word;
                if ($cur !== '' && self::width($try, $font, $px, $track) > $w) { $lines[] = $cur; $cur = $word; } else { $cur = $try; }
            }
            if ($cur !== '') $lines[] = $cur;
            return $lines;
        };
        $lines = $greedy($maxW);
        $n = count($lines);
        if (!$balance || $n < 2) return $lines;
        // Balance: same line count, line widths as even as possible (least sum
        // of squares), every line within $maxW. Small DP over the word breaks.
        $words = preg_split('/\s+/u', trim($text)); $m = count($words);
        $wd = [];
        for ($i = 0; $i < $m; $i++) for ($j = $i; $j < $m; $j++) $wd[$i][$j] = self::width(implode(' ', array_slice($words, $i, $j - $i + 1)), $font, $px, $track);
        $best = [[0 => [0.0, []]]];           // $best[k][i] = [cost, breaks] for first i words in k lines
        for ($k = 1; $k <= $n; $k++) {
            foreach ($best[$k - 1] as $i => [$c, $br]) {
                for ($j = $i; $j < $m; $j++) {
                    $w = $wd[$i][$j];
                    if ($w > $maxW) break;
                    $cost = $c + $w * $w;
                    if (!isset($best[$k][$j + 1]) || $cost < $best[$k][$j + 1][0]) $best[$k][$j + 1] = [$cost, array_merge($br, [$j + 1])];
                }
            }
        }
        if (!isset($best[$n][$m])) return $lines;
        $out = []; $from = 0;
        foreach ($best[$n][$m][1] as $to) { $out[] = implode(' ', array_slice($words, $from, $to - $from)); $from = $to; }
        return $out;
    }

    /** Horizontal multi-stop gradient. $stops = [[0.0, rgb], [0.5, rgb], [1.0, rgb]]. */
    public static function hGradient($im, int $x, int $y, int $w, int $h, array $stops): void
    {
        for ($i = 0; $i < $w; $i++) {
            $t = $w > 1 ? $i / ($w - 1) : 0;
            for ($k = 0; $k < count($stops) - 1 && $t > $stops[$k + 1][0]; $k++);
            [$p0, $c0] = $stops[$k]; [$p1, $c1] = $stops[min($k + 1, count($stops) - 1)];
            $rgb = self::mix($c0, $c1, $p1 > $p0 ? ($t - $p0) / ($p1 - $p0) : 0);
            imageline($im, $x + $i, $y, $x + $i, $y + $h - 1, self::col($im, $rgb));
        }
    }

    /**
     * CSS radial-gradient(RX% RY% at CX CY, rgba(c, a0), transparent STOP) over
     * an opaque canvas. Mixed straight into the pixels (GD alpha has only 128
     * steps, which bands a faint glow) with a little dither, on a half-size
     * layer scaled up so it stays smooth and cheap.
     */
    public static function radial($im, float $cx, float $cy, float $rx, float $ry, array $rgb, float $a0, float $stop): void
    {
        $W = imagesx($im); $H = imagesy($im); $s = 2;
        $w = (int) ceil($W / $s); $h = (int) ceil($H / $s);
        $l = imagecreatetruecolor($w, $h);
        imagecopyresampled($l, $im, 0, 0, 0, 0, $w, $h, $W, $H);
        mt_srand(7);
        for ($j = 0; $j < $h; $j++) {
            for ($i = 0; $i < $w; $i++) {
                $d = sqrt(((($i + .5) * $s - $cx) / $rx) ** 2 + ((($j + .5) * $s - $cy) / $ry) ** 2) / $stop;
                if ($d >= 1) continue;
                $a = $a0 * (1 - $d);
                $c = imagecolorat($l, $i, $j);
                $n = mt_rand(-50, 50) / 100;
                $px = [(($c >> 16) & 255), (($c >> 8) & 255), ($c & 255)];
                $o = [];
                for ($k = 0; $k < 3; $k++) $o[$k] = max(0, min(255, (int) round($px[$k] + ($rgb[$k] - $px[$k]) * $a + $n)));
                imagesetpixel($l, $i, $j, ($o[0] << 16) | ($o[1] << 8) | $o[2]);
            }
        }
        imagecopyresampled($im, $l, 0, 0, 0, 0, $W, $H, $w, $h);
        imagedestroy($l);
    }

    /**
     * An anti-aliased disc of diameter $d at ($x,$y) (top-left), filled by
     * $fill($u,$v) → [rgb, alpha] with u,v in 0–1. Drawn 3× and scaled down.
     */
    public static function disc($im, int $x, int $y, int $d, callable $fill): void
    {
        $k = 3; $D = $d * $k; $r = $D / 2;
        $l = imagecreatetruecolor($D, $D);
        imagealphablending($l, false); imagesavealpha($l, true);
        imagefill($l, 0, 0, imagecolorallocatealpha($l, 0, 0, 0, 127));
        for ($j = 0; $j < $D; $j++) {
            for ($i = 0; $i < $D; $i++) {
                if (($i + .5 - $r) ** 2 + ($j + .5 - $r) ** 2 > $r * $r) continue;
                [$rgb, $a] = $fill(($i + .5) / $D, ($j + .5) / $D);
                imagesetpixel($l, $i, $j, self::col($l, $rgb, $a));
            }
        }
        imagecopyresampled($im, $l, $x, $y, 0, 0, $d, $d, $D, $D);
        imagedestroy($l);
    }

    /** Paste $src cropped to a circle of diameter $d (cover-fit). */
    public static function circleImage($im, $src, int $x, int $y, int $d): void
    {
        $sw = imagesx($src); $sh = imagesy($src); $side = min($sw, $sh);
        $k = 3; $D = $d * $k;
        $sq = imagecreatetruecolor($D, $D);
        imagecopyresampled($sq, $src, 0, 0, (int) (($sw - $side) / 2), (int) (($sh - $side) / 2), $D, $D, $side, $side);
        self::disc($im, $x, $y, $d, function ($u, $v) use ($sq, $D) {
            $c = imagecolorat($sq, min($D - 1, (int) ($u * $D)), min($D - 1, (int) ($v * $D)));
            return [[($c >> 16) & 255, ($c >> 8) & 255, $c & 255], 1.0];
        });
        imagedestroy($sq);
    }

    /** A filled square rotated 45° (the brand's diamond), centred at ($cx,$cy), side $s. */
    public static function diamond($im, float $cx, float $cy, float $s, array $rgb, bool $filled = true, float $a = 1.0): void
    {
        $h = $s * M_SQRT1_2;
        $pts = [(int) round($cx), (int) round($cy - $h), (int) round($cx + $h), (int) round($cy), (int) round($cx), (int) round($cy + $h), (int) round($cx - $h), (int) round($cy)];
        imageantialias($im, true);
        $filled ? imagefilledpolygon($im, $pts, self::col($im, $rgb, $a)) : imagepolygon($im, $pts, self::col($im, $rgb, $a));
    }

    /** Load an image from a site path (/uploads/…, /assets/…) or an http(s) URL. Null on any failure. */
    public static function load(string $src)
    {
        try {
            if ($src === '') return null;
            if ($src[0] === '/') {
                $path = realpath(AV_ROOT . $src);
                return $path && str_starts_with($path, realpath(AV_ROOT)) && is_file($path) ? @imagecreatefromstring((string) file_get_contents($path)) : null;
            }
            if (preg_match('~^https?://~', $src)) {
                $ctx = stream_context_create(['http' => ['timeout' => 6], 'ssl' => ['verify_peer' => true]]);
                $data = @file_get_contents($src, false, $ctx);
                return $data ? (@imagecreatefromstring($data) ?: null) : null;
            }
        } catch (Throwable $e) {}
        return null;
    }

    /** Paste $src to fill the box (object-fit: cover). */
    public static function cover($im, $src, int $x, int $y, int $w, int $h): void
    {
        $sw = imagesx($src); $sh = imagesy($src);
        $s = max($w / $sw, $h / $sh);
        $cw = (int) round($w / $s); $ch = (int) round($h / $s);
        imagecopyresampled($im, $src, $x, $y, (int) (($sw - $cw) / 2), (int) (($sh - $ch) / 2), $w, $h, $cw, $ch);
    }

    /**
     * Serve a cached PNG: draw with $draw() only when the key changed.
     * $name is a file-safe stem under db/cache/.
     */
    public static function serveCached(string $name, string $key, callable $draw, int $maxAge = 86400, ?string $download = null): void
    {
        $dir = AV_ROOT . '/db/cache';
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        $file = $dir . '/' . preg_replace('/[^a-z0-9_.-]/i', '-', $name) . '.png';
        $stamp = $file . '.key';
        if (!(is_file($file) && is_file($stamp) && trim((string) @file_get_contents($stamp)) === $key)) {
            $im = $draw();
            imagepng($im, $file, 9);
            imagedestroy($im);
            @file_put_contents($stamp, $key);
        }
        header('Content-Type: image/png');
        header('Cache-Control: public, max-age=' . $maxAge);
        header('X-Content-Type-Options: nosniff');
        if ($download !== null) header('Content-Disposition: attachment; filename="' . preg_replace('/[^a-z0-9_.-]/i', '-', $download) . '"');
        header('Content-Length: ' . filesize($file));
        readfile($file);
    }
}
