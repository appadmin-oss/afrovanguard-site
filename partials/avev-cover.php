<?php
/**
 * partials/avev-cover.php — the event cover graphic (design: Afrovanguard Cover,
 * kind "event"), server-rendered for the Events page.
 *
 * A typographic card standing in for a photograph: category ground and accent,
 * a faint motif, the date or the category, the title and time · place. Colours
 * and motifs are CSS (assets/site/avev.css, tokens only); this file decides
 * which category, motif and layout a cover gets. assets/site/avev.js builds the
 * same markup for events loaded from the feed.
 */
declare(strict_types=1);

/** Category → [label, motif]. Ground/accent come from .avev-cov--{key} in CSS. */
function avev_cover_cats(): array
{
    return [
        'summit' => ['Summit', 'arcs'], 'gala' => ['Gala', 'hatch'], 'workshop' => ['Workshop', 'dots'],
        'bootcamp' => ['Bootcamp', 'grid'], 'townhall' => ['Town hall', 'contour'], 'meetup' => ['Community', 'contour'],
        'arts' => ['Arts & showcase', 'chevron'], 'faith' => ['Faith', 'rays'], 'school' => ['Schools', 'grid'],
        'sports' => ['Sports & wellbeing', 'chevron'], 'leadership' => ['Leadership', 'arcs'], 'technology' => ['Technology', 'grid'],
        'culture' => ['Culture', 'chevron'], 'education' => ['Education', 'dots'], 'nation' => ['Nation-building', 'contour'],
        'general' => ['', 'hatch'],
    ];
}

/** Holidays by month-day → [key, label]. */
function avev_cover_holidays(): array
{
    return ['10-01' => ['independence', 'Independence Day'], '06-12' => ['democracyday', 'Democracy Day'], '12-25' => ['christmas', 'Christmas'],
            '01-01' => ['newyear', 'New Year'], '05-25' => ['africaday', 'Africa Day'], '05-27' => ['childrensday', 'Children’s Day'],
            '11-14' => ['founding', 'Anniversary']];
}

/** FNV-1a over the title, as the design hashes it (32-bit, then absolute). */
function avev_cover_hash(string $s): int
{
    $h = 2166136261;
    $units = mb_convert_encoding($s, 'UTF-16BE', 'UTF-8');
    for ($i = 0, $n = strlen($units); $i + 1 < $n; $i += 2) {
        $h = ($h ^ ((ord($units[$i]) << 8) | ord($units[$i + 1]))) & 0xFFFFFFFF;
        $h = ($h * 16777619) & 0xFFFFFFFF;
    }
    if ($h >= 0x80000000) $h -= 0x100000000;
    return abs($h);
}

/**
 * @param array{title:string,category?:string,date?:string,end?:string,time?:string,location?:string,ratio?:string,today?:string} $o
 *   date / end / today are Y-m-d; ratio is '3:2' or '1.91:1'.
 */
function avev_cover(array $o): string
{
    $cats  = avev_cover_cats();
    $title = trim((string) ($o['title'] ?? ''));
    $ck    = isset($cats[$o['category'] ?? '']) ? (string) $o['category'] : 'general';
    [$label, $motif] = $cats[$ck];
    $h     = avev_cover_hash($title);
    $wide  = ($o['ratio'] ?? '3:2') === '1.91:1';

    $today = (string) ($o['today'] ?? date('Y-m-d'));
    $date  = (string) ($o['date'] ?? '');
    $end   = (string) ($o['end'] ?? '');
    $live  = $date !== '' && $today >= $date && $today <= ($end ?: $date);
    $past  = $date !== '' && $today > ($end ?: $date);
    $hol   = $date !== '' ? (avev_cover_holidays()[substr($date, 5, 5)] ?? null) : null;
    $kick  = $label !== '' ? $label : 'Event';
    $meta  = implode(' · ', array_filter([(string) ($o['time'] ?? ''), (string) ($o['location'] ?? '')], fn($x) => $x !== ''));

    $ox = $oy = '0%'; $ang = '0deg';
    if ($motif === 'arcs')    { $ox = (88 + $h % 14) . '%'; $oy = (118 + $h % 20) . '%'; }
    if ($motif === 'contour') { $ox = (80 + $h % 20) . '%'; $oy = (-20 + $h % 25) . '%'; }
    if ($motif === 'hatch')   { $ang = (45 + ($h % 3) * 15) . 'deg'; }
    if ($motif === 'rays')    { $ang = ($h % 30) . 'deg'; }
    $lx = 70 + $h % 30;
    $style = sprintf('--lx:%d%%;--ly:%d%%;--gx:%d%%;--mx:%dpx;--my:%dpx;--ox:%s;--oy:%s;--ang:%s',
        $lx, $h % 40, 100 - ($lx - 70), $h % 22, $h % 13, $ox, $oy, $ang);
    $len = mb_strlen($title);

    $cls = 'avev-cov avev-cov--' . $ck . ' avev-m-' . $motif . ($wide ? ' avev-cov--wide' : '')
         . ($past ? ' is-past' : '') . ($len > 72 ? ' is-longer' : ($len > 46 ? ' is-long' : ''));
    $aria = $kick . ': ' . $title . ($meta !== '' ? ' — ' . $meta : '');

    $out  = '<div class="' . e($cls) . '" role="img" aria-label="' . e($aria) . '" style="' . e($style) . '">';
    $out .= '<span class="avev-cov-motif"></span><span class="avev-cov-light"></span><span class="avev-cov-grain"></span>';
    $out .= '<span class="avev-cov-in"><span class="avev-cov-top"><span class="avev-cov-src"><img src="/assets/site/av-seal.png" alt="" /><span>Afrovanguard · Events'
          . ($hol ? ' · ' . e($hol[1]) : '') . '</span></span>';
    if ($live) $out .= '<span class="avev-cov-live">Live now</span>';
    $out .= '</span><span class="avev-cov-bot">';
    if ($date !== '' && ($ts = strtotime($date))) {
        $multi = $end !== '' && $end !== $date && ($te = strtotime($end));
        $sub = $past ? 'Took place' : ($multi ? '– ' . date('j M', $te) : $kick);
        $out .= '<span class="avev-cov-date"><span class="avev-cov-day">' . date('j', $ts) . '</span><span class="avev-cov-mon"><span>'
              . date('M Y', $ts) . '</span><span>' . e($sub) . '</span></span></span>';
    } else {
        $out .= '<span class="avev-cov-kick">' . e($kick) . '</span>';
    }
    $out .= '<span class="avev-cov-title">' . e($title) . '</span>';
    if ($meta !== '') $out .= '<span class="avev-cov-meta"><span>' . e($meta) . '</span></span>';
    $out .= '</span></span>';
    if ($hol) $out .= '<span class="avev-cov-stripe avev-hol-' . e($hol[0]) . '"></span>';
    return $out . '</div>';
}
