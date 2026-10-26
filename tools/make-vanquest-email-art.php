<?php
/**
 * tools/make-vanquest-email-art.php — draws the pixel-art illustrations for the
 * VanQuest welcome email (lib/email/templates/vanquest_welcome.php) into
 * /assets/site/email/vq*.gif, each with a PNG of its last frame beside it for
 * Outlook (see docs/emails.md, "Illustrations and the Outlook fallback").
 *
 * The icons are VanQuest's own: the grids and palette below are copied from
 * van-quest site/js/vq-icons.js (16x17 canvas, 14x14 art, 1px outline, 1px drop
 * shadow). Change them there first, then here, then re-run:
 *
 *   php tools/make-vanquest-email-art.php
 *
 * GD writes single-frame GIFs only, so each frame is written on its own and the
 * frames are stitched into one looping GIF89a below.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }
$dir = dirname(__DIR__) . '/assets/site/email';

/* ── VanQuest palette (vq-icons.js P) plus the email's own surfaces ───────── */
const PAL = [
    'K' => '1a0f08', 'o' => 'ff9f1c', 'l' => 'ffc65c', 'w' => 'fff3d6', 'd' => 'd2600c', 'D' => '8f3a0c',
    't' => 'b49c82', 's' => '857060', 'S' => '5a4a3e', 'b' => 'b0703f', 'B' => '7d4a2a', 'h' => '3b2416',
    'c' => 'f6e8cc', 'k' => '1a0f08',
    // Surfaces, matching _frame_vq.php: night sky, card, panel, ridge layers, glow.
    'n' => '0b0a09', 'C' => '15120f', 'p' => '1d1915', 'r' => '3a2e24', 'R' => '241d17',
    'g' => '4a2a0e', 'G' => '2e1b0c', 'm' => '6b5236',
];

function mirror(array $rows): array { return array_map(fn($r) => $r . strrev($r), $rows); }
const ICONS_RAW = [
    'lion'   => ['m', ['...dddd', '.ddoooo', 'dooodoo', 'doollll', 'dollccc', 'dolckcc', 'dolcccc', 'dolcccc', 'doolccD', '.doolcc', '.ddoolc', '..ddooo', '....ddd']],
    'flame'  => ['m', ['......o', '.....oo', '....ooo', '...oool', '..ooool', '..oooll', '.dooolw', '.doolll', 'ddoollw', 'ddoolww', 'ddoolww', '.ddoolw', '..ddool', '....ddd']],
    'beacon' => ['m', ['......l', '.....lw', '....olw', '.....dl', '......d', '.......', '.....ts', '.....ts', '....tsS', '....tsS', '....tsS', '...tsSS', '.tttsss', 'SSSSSSS']],
    'people' => ['m', ['..hh...', '.hhhh..', '.hbbh..', '.bbbb..', '..bB...', '.oooo..', 'ollllo.', 'ollllo.', 'oolloo.', 'dooood.', 'dooood.', '.dddd..', '.D..D..', '.D..D..']],
    'check'  => ['', ['............ol', '...........oll', '..........oll.', '.........oll..', 'ol......oll...', 'oll....oll....', '.oll..oll.....', '..olloll......', '...ooll.......', '....dd........']],
];

/** vq-icons.js build(): centre the art on 16x17, add the outline (K) and the shadow (Z). */
function icon(string $name, array $recolour = []): array
{
    [$mode, $rows] = ICONS_RAW[$name];
    if ($mode === 'm') $rows = mirror($rows);
    $W = 16; $H = 17;
    $w = max(array_map('strlen', $rows)); $h = count($rows);
    $ox = 1 + intdiv(14 - $w, 2); $oy = 1 + intdiv(14 - $h, 2);
    $g = array_fill(0, $H, array_fill(0, $W, '.'));
    foreach ($rows as $y => $r) for ($x = 0; $x < strlen($r); $x++) if ($r[$x] !== '.') $g[$y + $oy][$x + $ox] = $recolour[$r[$x]] ?? $r[$x];
    $art = fn($x, $y) => isset($g[$y][$x]) && $g[$y][$x] !== '.' && $g[$y][$x] !== 'K';
    for ($y = 0; $y < $H; $y++) for ($x = 0; $x < $W; $x++)
        if ($g[$y][$x] === '.' && ($art($x - 1, $y) || $art($x + 1, $y) || $art($x, $y - 1) || $art($x, $y + 1))) $g[$y][$x] = 'K';
    for ($y = $H - 1; $y > 0; $y--) for ($x = 0; $x < $W; $x++)
        if ($g[$y][$x] === '.' && $g[$y - 1][$x] !== '.' && $g[$y - 1][$x] !== 'Z') $g[$y][$x] = 'Z';
    return $g;
}

/* ── A frame is a grid of palette keys; Z is the 38% black shadow over its ground ── */
final class Frame
{
    public array $px;
    public function __construct(public int $w, public int $h, public string $ground)
    { $this->px = array_fill(0, $h, array_fill(0, $w, $ground)); }
    public function set(int $x, int $y, string $c): void
    { if ($x >= 0 && $y >= 0 && $x < $this->w && $y < $this->h) $this->px[$y][$x] = $c; }
    public function get(int $x, int $y): ?string { return $this->px[$y][$x] ?? null; }
    public function blit(array $g, int $ox, int $oy, int $k = 1): void
    {
        foreach ($g as $y => $row) foreach ($row as $x => $c) {
            if ($c === '.') continue;
            for ($i = 0; $i < $k; $i++) for ($j = 0; $j < $k; $j++) $this->set($ox + $x * $k + $j, $oy + $y * $k + $i, $c);
        }
    }
    /** A four-point pixel sparkle; size 0 is a single dot. */
    public function sparkle(int $x, int $y, int $size, string $core = 'w', string $arm = 'l'): void
    {
        $this->set($x, $y, $core);
        for ($i = 1; $i <= $size; $i++) foreach ([[1, 0], [-1, 0], [0, 1], [0, -1]] as [$dx, $dy]) $this->set($x + $dx * $i, $y + $dy * $i, $arm);
    }
}

function shade(string $hex, float $a): array
{
    $v = array_map('hexdec', str_split($hex, 2));
    return array_map(fn($c) => (int) round($c * (1 - $a)), $v);
}

/** Paint one frame at $scale into a palette image; one fixed palette for every frame. */
function render(Frame $f, int $scale): string
{
    $im = imagecreate($f->w * $scale, $f->h * $scale);
    $idx = [];
    foreach (PAL as $k => $hex) $idx[$k] = imagecolorallocate($im, ...array_map('hexdec', str_split($hex, 2)));
    foreach (['n', 'C', 'p'] as $ground) $idx['Z' . $ground] = imagecolorallocate($im, ...shade(PAL[$ground], 0.38));
    imagefill($im, 0, 0, $idx[$f->ground]);
    foreach ($f->px as $y => $row) foreach ($row as $x => $c) {
        $col = $c === 'Z' ? $idx['Z' . $f->ground] : $idx[$c];
        imagefilledrectangle($im, $x * $scale, $y * $scale, ($x + 1) * $scale - 1, ($y + 1) * $scale - 1, $col);
    }
    ob_start(); imagegif($im); $gif = ob_get_clean();
    return $gif;
}

/** Stitch single-frame GIFs into one looping GIF89a, each frame keeping its own colour table. */
function animate(array $gifs, int $delayCs): string
{
    $out = '';
    foreach ($gifs as $n => $g) {
        $packed = ord($g[10]);
        $gctLen = ($packed & 0x80) ? 3 * (2 << ($packed & 7)) : 0;
        $gct = substr($g, 13, $gctLen);
        $p = 13 + $gctLen;
        while ($g[$p] === "\x21") {            // skip any extension GD wrote
            $p += 2;
            while (($len = ord($g[$p])) > 0) $p += $len + 1;
            $p++;
        }
        if ($g[$p] !== "\x2C") throw new RuntimeException('frame ' . $n . ': no image descriptor');
        $desc = substr($g, $p, 10);
        $desc[9] = chr(0x80 | ($packed & 7));   // local colour table, same size as the global one was
        $data = substr($g, $p + 10, strlen($g) - $p - 11);
        if ($n === 0) {
            $out = 'GIF89a' . substr($g, 6, 4) . chr(0x70) . "\x00\x00"
                 . "\x21\xFF\x0BNETSCAPE2.0\x03\x01\x00\x00\x00";
        }
        $out .= "\x21\xF9\x04\x04" . pack('v', $delayCs) . "\x00\x00" . $desc . $gct . $data;
    }
    return $out . "\x3B";
}

function write(string $dir, string $name, array $frames, int $scale, int $delayCs): void
{
    $gifs = array_map(fn($f) => render($f, $scale), $frames);
    file_put_contents("$dir/$name.gif", animate($gifs, $delayCs));
    // Outlook freezes a GIF on frame 1; it is served the last frame instead.
    $im = imagecreatefromstring(end($gifs));
    imagepng($im, "$dir/$name.png", 9);
    printf("%-10s %3d frames  %6.1f KB\n", $name, count($frames), filesize("$dir/$name.gif") / 1024);
}

/* ── Pixel digits for "+50 XP" (3x5) ──────────────────────────────────────── */
const GLYPH = [
    '+' => ['...', '.x.', 'xxx', '.x.', '...'], '5' => ['xxx', 'x..', 'xx.', '..x', 'xx.'],
    '0' => ['.x.', 'x.x', 'x.x', 'x.x', '.x.'], 'X' => ['x.x', 'x.x', '.x.', 'x.x', 'x.x'],
    'P' => ['xx.', 'x.x', 'xx.', 'x..', 'x..'], ' ' => ['..', '..', '..', '..', '..'],
];
function text(Frame $f, string $s, int $x, int $y, string $c): void
{
    // Outline first so the text reads over the sky and the glow alike.
    foreach ([[1, 0], [-1, 0], [0, 1], [0, -1], [1, 1]] as [$dx, $dy]) text1($f, $s, $x + $dx, $y + $dy, 'K');
    text1($f, $s, $x, $y, $c);
}
function text1(Frame $f, string $s, int $x, int $y, string $c): void
{
    foreach (str_split($s) as $ch) {
        foreach (GLYPH[$ch] as $gy => $row) for ($gx = 0; $gx < strlen($row); $gx++) if ($row[$gx] === 'x') $f->set($x + $gx, $y + $gy, $c);
        $x += strlen(GLYPH[$ch][0]) + 1;
    }
}

/* ══ vqhero — the lion on the summit, embers rising, +50 XP ════════════════
   80x50 logical pixels at 4x: 320x200, shown at 160x100. */
$N = 24; $frames = [];
$lion = icon('lion');
$stars = [[6, 5], [14, 12], [22, 3], [9, 21], [70, 18], [64, 4], [74, 9], [3, 14], [31, 8], [52, 11], [44, 3], [77, 24], [58, 21], [18, 26]];
$embers = [[30, 0], [49, 7], [36, 13], [55, 4], [25, 17], [44, 20]];
for ($t = 0; $t < $N; $t++) {
    $f = new Frame(80, 50, 'n');
    foreach ($stars as $i => [$sx, $sy]) {
        $ph = ($t + $i * 5) % 12;
        if ($ph < 2) $f->sparkle($sx, $sy, 1, 'w', 'm');
        elseif ($ph < 7) $f->set($sx, $sy, 'm');
        else $f->set($sx, $sy, 'r');
    }
    // Glow behind the lion: a dithered disc that breathes by one pixel.
    $rad = 20 + (int) round(1.5 + 1.5 * sin($t / $N * 2 * M_PI));
    for ($y = 0; $y < 50; $y++) for ($x = 0; $x < 80; $x++) {
        $d = hypot($x - 39.5, ($y - 22) * 1.1);
        if ($d < $rad - 6) $f->set($x, $y, 'G');
        elseif ($d < $rad && (($x + $y) % 2 === 0)) $f->set($x, $y, 'g');
    }
    // Two ridges: the far one, then the near one with the summit under the lion.
    for ($x = 0; $x < 80; $x++) {
        $far = 37 + (int) round(3 * sin($x / 7.0) + 2 * sin($x / 3.1 + 1));
        for ($y = $far; $y < 50; $y++) $f->set($x, $y, 'R');
        $near = 41 + (int) round(min(abs($x - 40) / 3.2, 8) - 3 + sin($x / 2.3));
        for ($y = $near; $y < 50; $y++) $f->set($x, $y, 'r');
        if ($f->get($x, $near) === 'r' && ($x + $t) % 9 === 0) $f->set($x, $near, 'm');
    }
    $f->blit($lion, 24, 3 + ($t % 12 < 6 ? 0 : 1), 2);
    foreach ($embers as $i => [$ex, $ph]) {
        $life = ($t + $ph) % $N;
        $ey = 44 - $life * 1.6;
        if ($ey < 2) continue;
        $x = $ex + (int) round(2 * sin(($life + $i) / 3));
        $f->set($x, (int) $ey, $life < 8 ? 'w' : ($life < 16 ? 'l' : 'o'));
        if ($life < 12) $f->set($x, (int) $ey + 1, 'd');
    }
    // +50 XP bobs at the top right with a sparkle that blinks beside it.
    text($f, '+50 XP', 55, 4 + ($t % 8 < 4 ? 0 : 1), 'l');
    if ($t % 6 < 3) $f->sparkle(52, 3, 1, 'w', 'o');
    $frames[] = $f;
}
write($dir, 'vqhero', $frames, 4, 9);

/* ══ The quest-log icons: 20x20 logical at 4x, shown at 40x40, on the panel ══ */
function tile(): Frame { return new Frame(20, 20, 'p'); }

// vqcheck: the check, and three sparkles that light in turn.
$frames = []; $check = icon('check');
for ($t = 0; $t < 12; $t++) {
    $f = tile(); $f->blit($check, 2, 2);
    foreach ([[2, 3, 0], [17, 4, 4], [16, 16, 8]] as [$x, $y, $at]) {
        $p = ($t - $at + 12) % 12;
        if ($p < 2) $f->sparkle($x, $y, 1); elseif ($p < 4) $f->set($x, $y, 'l');
    }
    $frames[] = $f;
}
write($dir, 'vqcheck', $frames, 4, 10);

// vqflame: the flame flickers through its ramp; an ember lifts off the tip.
$frames = [];
$ramps = [[], ['l' => 'w', 'o' => 'l'], ['w' => 'l', 'l' => 'o'], ['d' => 'o'], ['l' => 'w'], []];
for ($t = 0; $t < 6; $t++) {
    $f = tile(); $f->blit(icon('flame', $ramps[$t]), 2, 2);
    $ey = 2 - $t;
    if ($ey >= 0) $f->set(10 + ($t % 2), $ey, $t < 3 ? 'l' : 'o');
    $frames[] = $f;
}
write($dir, 'vqflame', $frames, 4, 11);

// vqbeacon: the light at the top throws rays that grow and fall back.
$frames = [];
for ($t = 0; $t < 8; $t++) {
    $f = tile(); $f->blit(icon('beacon'), 2, 2);
    $len = [0, 1, 2, 3, 3, 2, 1, 0][$t];
    for ($i = 1; $i <= $len; $i++) {
        $c = $i === $len ? 'o' : 'l';
        $f->set(9 - $i - 1, 3 - (int) floor($i / 2), $c); $f->set(10 + $i + 1, 3 - (int) floor($i / 2), $c);
        $f->set(9 - $i - 1, 5 + (int) floor($i / 2), $c); $f->set(10 + $i + 1, 5 + (int) floor($i / 2), $c);
    }
    $frames[] = $f;
}
write($dir, 'vqbeacon', $frames, 4, 12);

// vqpeople: the figure, and a sparkle that rises above its head.
$frames = [];
for ($t = 0; $t < 8; $t++) {
    $f = tile(); $f->blit(icon('people'), 2, 2);
    if ($t < 4) $f->sparkle(15, 4 - $t % 4, $t < 2 ? 1 : 0, 'w', 'l');
    $frames[] = $f;
}
write($dir, 'vqpeople', $frames, 4, 12);

// vqmark: the lion alone, for the header. One frame; served as PNG only.
$f = new Frame(16, 17, 'n'); $f->blit($lion, 0, 0);
$im = imagecreatefromstring(render($f, 4));
imagepng($im, "$dir/vqmark.png", 9);
echo "vqmark      PNG\n";
