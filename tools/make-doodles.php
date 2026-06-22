<?php
/**
 * tools/make-doodles.php — generates Google-style holiday "doodle" versions of
 * the Afrovanguard wordmark into /assets/doodles/<key>.svg. The Celebrations
 * engine swaps the nav logo for these on the matching day (admins can also
 * upload their own art in the Studio, which overrides these).
 *
 *   php tools/make-doodles.php
 */
declare(strict_types=1);
$dir = dirname(__DIR__) . '/assets/doodles';
@mkdir($dir, 0775, true);

// Wordmark: AFRO (white) + VANGUARD (gold). System bold font (SVG <img> has no webfonts).
function wordmark(): string {
    return '<text x="6" y="37" font-family="Helvetica Neue,Arial,sans-serif" font-weight="800" '
         . 'font-size="29" letter-spacing="-1"><tspan fill="#ffffff">AFRO</tspan>'
         . '<tspan fill="#f3b416">VANGUARD</tspan></text>';
}
function star(float $x, float $y, float $r, string $fill): string {
    $pts = [];
    for ($i = 0; $i < 10; $i++) {
        $ang = M_PI / 5 * $i - M_PI / 2; $rad = ($i % 2 ? $r * 0.42 : $r);
        $pts[] = round($x + cos($ang) * $rad, 1) . ',' . round($y + sin($ang) * $rad, 1);
    }
    return '<polygon points="' . implode(' ', $pts) . '" fill="' . $fill . '"/>';
}

// Per-key decorative motif (drawn to the right of / above the wordmark; viewBox 232×52 art zone)
$motifs = [
    'newyear' =>
        '<g stroke="#f3b416" stroke-width="2.2" stroke-linecap="round">'
        . '<g transform="translate(244,15)"><line x1="0" y1="-10" x2="0" y2="-5"/><line x1="7" y1="-7" x2="4" y2="-4"/><line x1="10" y1="0" x2="5" y2="0"/><line x1="7" y1="7" x2="4" y2="4"/><line x1="0" y1="10" x2="0" y2="5"/><line x1="-7" y1="7" x2="-4" y2="4"/><line x1="-10" y1="0" x2="-5" y2="0"/><line x1="-7" y1="-7" x2="-4" y2="-4"/></g></g>'
        . star(276, 12, 5, '#16a34a') . star(292, 26, 4, '#0ea5e9')
        . '<circle cx="262" cy="30" r="2.4" fill="#ec4899"/>',
    'eid' =>
        '<g transform="translate(248,17)"><path d="M2 -10 A10 10 0 1 0 2 10 A7.5 7.5 0 1 1 2 -10 Z" fill="#f3b416"/></g>'
        . star(272, 9, 5, '#f3b416') . star(290, 22, 3.6, '#fff')
        . '<g transform="translate(300,8)"><rect x="-3" y="2" width="6" height="11" rx="1.5" fill="#16a34a"/><rect x="-2" y="0" width="4" height="2" fill="#f3b416"/><line x1="0" y1="-4" x2="0" y2="0" stroke="#f3b416" stroke-width="1.4"/></g>',
    'easter' =>
        '<g transform="translate(244,10)"><ellipse cx="9" cy="16" rx="8.5" ry="11.5" fill="#16a34a"/><path d="M1 13 q8 4 16 0 M1 18 q8 4 16 0" stroke="#fff" stroke-width="1.6" fill="none"/></g>'
        . '<g transform="translate(266,14)"><ellipse cx="8" cy="14" rx="7.5" ry="10" fill="#ec4899"/><circle cx="5" cy="11" r="1.6" fill="#fff"/><circle cx="11" cy="16" r="1.6" fill="#fff"/></g>'
        . '<path d="M292 8 q6 2 4 12" stroke="#16a34a" stroke-width="2" fill="none" stroke-linecap="round"/>',
    'christmas' =>
        '<g transform="translate(246,12)"><path d="M2 10 q7 -11 14 -1 q-7 5 -14 1z" fill="#16a34a"/><circle cx="8" cy="11" r="2.2" fill="#ef4444"/><circle cx="12" cy="13" r="2.2" fill="#ef4444"/><circle cx="6" cy="14" r="2.2" fill="#ef4444"/></g>'
        . star(286, 14, 6, '#f3b416')
        . '<g stroke="#fff" stroke-width="1.6" stroke-linecap="round"><line x1="300" y1="24" x2="306" y2="24"/><line x1="303" y1="21" x2="303" y2="27"/></g>',
    'africaday' =>
        '<path transform="translate(250,9)" d="M16 0 l7 2 0 6 5 5 -3 6 -4 2 -3 9 -5 -3 -2 -10 -6 -3 1 -8 6 -3 4 -3z" fill="#16a34a" opacity="0.92"/>'
        . '<g>' . implode('', array_map(function ($i) {
            $cols = ['#16a34a', '#f3b416', '#d4380d'];
            return '<rect x="' . (6 + $i * 17) . '" y="43" width="14" height="3.4" rx="1" fill="' . $cols[$i % 3] . '"/>';
        }, range(0, 11))) . '</g>',
    'independence' =>
        '<g transform="translate(250,11)"><rect x="0" y="0" width="8" height="29" fill="#16a34a"/><rect x="8" y="0" width="8" height="29" fill="#fff"/><rect x="16" y="0" width="8" height="29" fill="#16a34a"/></g>'
        . star(282, 16, 6, '#f3b416'),
    'founding' =>
        '<g transform="translate(252,8)"><rect x="3" y="10" width="5" height="20" rx="1.5" fill="#f3b416"/><path d="M5.5 10 q-3.5 -5 0 -9 q3.5 4 0 9z" fill="#ef4444"/></g>'
        . star(276, 12, 4.5, '#16a34a') . '<circle cx="294" cy="14" r="2.6" fill="#0ea5e9"/><circle cx="284" cy="30" r="2.2" fill="#ec4899"/><circle cx="300" cy="28" r="2.2" fill="#f3b416"/>',
    'womensday' =>
        '<g transform="translate(258,9)" stroke="#7c3aed" stroke-width="2.6" fill="none"><circle cx="10" cy="9" r="8"/><line x1="10" y1="17" x2="10" y2="30"/><line x1="4" y1="24" x2="16" y2="24"/></g>' . star(288, 14, 4.5, '#ec4899'),
    'childrensday' =>
        '<g transform="translate(250,8)"><circle cx="10" cy="11" r="9" fill="#f59e0b"/><line x1="10" y1="20" x2="10" y2="30" stroke="#16a34a" stroke-width="1.6"/></g>'
        . '<g transform="translate(280,10)"><path d="M8 0 l8 8 -8 8 -8 -8z" fill="#0ea5e9"/><path d="M8 16 q2 6 -2 10" stroke="#16a34a" stroke-width="1.4" fill="none"/></g>',
    'youthday' =>
        '<g transform="translate(262,8)"><path d="M10 0 C16 6 16 14 12 22 L8 22 C4 14 4 6 10 0Z" fill="#f3b416"/><circle cx="10" cy="9" r="2.6" fill="#0d1220"/><path d="M6 22 l-3 6 4 -2 M14 22 l3 6 -4 -2" stroke="#d4380d" stroke-width="2" fill="none" stroke-linecap="round"/></g>',
];

$count = 0;
foreach ($motifs as $key => $motif) {
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 320 52" role="img" aria-label="Afrovanguard">'
         . wordmark() . $motif . '</svg>';
    file_put_contents("$dir/$key.svg", $svg);
    $count++;
}
echo "Wrote $count doodles to assets/doodles/\n";
