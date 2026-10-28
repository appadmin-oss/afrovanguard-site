<?php
/**
 * partials/av-cover.php — the cover graphic for anything without a photo
 * (designs: Afrovanguard Cover + Afrovanguard Default Graphics).
 *
 * ONE renderer for every surface that would otherwise show a blank box:
 * events, Diary entries, projects, appeals, Academy courses, news. A
 * typographic card: category ground and accent (inferred from the title when
 * none is given), a faint motif offset per title so neighbouring cards differ,
 * the date or a kicker, the title, one meta line; date-aware for events
 * (live now / took place, muted), a progress bar for appeals, a holiday stripe
 * on the day, a light tone for print and email, six ratios.
 *
 * Styles: assets/site/avcv.css (tokens only). Browser twin for cards built
 * from JSON: assets/site/avcv.js → window.avCover(o) returns the same element.
 * Keep the two in step; tests/avcover.test.php pins the PHP side.
 *
 * Usage (any PHP page):
 *
 *   require_once AV_ROOT . '/partials/av-cover.php';
 *   <link rel="stylesheet" href="/assets/site/avcv.css">
 *   <?= av_cover(['kind' => 'course', 'title' => $c['title'], 'badge' => '6 weeks',
 *                 'meta' => 'Beginner · Certificate', 'ratio' => '16:9']) ?>
 *
 * Options (all optional except title):
 *   kind      event | diary | project | appeal | course | news   (default event)
 *   title     string
 *   category  one of av_cover_cats() keys; inferred from the title otherwise
 *   date, end, today   Y-m-d (today defaults to the server date)
 *   time, location     events: the meta line
 *   readTime           diary: "4 min read" (meta line, with the date)
 *   progress, raised, goal   appeals: bar 0–100 and "₦x of ₦y"
 *   badge, meta        top-right badge; the meta line for project/course/news
 *   holiday            force a holiday stripe (av_cover_holidays() keys)
 *   ratio     16:9 | 1.91:1 | 3:2 | 4:5 | 1:1 | 9:16   (default 16:9)
 *   tone      dark | light   (default dark)
 *   source    override the top-left source line ("Afrovanguard · …")
 *   class     extra class names on the root
 * The root fills its container's width; its height follows the ratio. Round
 * the corners from the caller with `--avcv-radius` or `border-radius`.
 */
declare(strict_types=1);

/** Category → [label, motif]. Ground/accent come from .avcv--{key} in CSS. */
function av_cover_cats(): array
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

/** Holiday key → [label, month-day]. */
function av_cover_holidays(): array
{
    return [
        'independence' => ['Independence Day', '10-01'], 'democracyday' => ['Democracy Day', '06-12'],
        'christmas' => ['Christmas', '12-25'], 'newyear' => ['New Year', '01-01'], 'africaday' => ['Africa Day', '05-25'],
        'childrensday' => ['Children’s Day', '05-27'], 'founding' => ['Anniversary', '11-14'],
    ];
}

/** Source line per kind. */
function av_cover_sources(): array
{
    return ['event' => 'Afrovanguard · Events', 'diary' => 'Afrovanguard · The Diary', 'project' => 'Afrovanguard · Projects',
            'appeal' => 'Afrovanguard · Appeal', 'course' => 'Afrovanguard · Academy', 'news' => 'Afrovanguard'];
}

/** The category a title reads as, as the design infers it (first match wins). */
function av_cover_infer(string $t): string
{
    $t = mb_strtolower($t);
    $rules = [
        '/summit|conference|congress/' => 'summit', '/gala|dinner|award|banquet/' => 'gala',
        '/bootcamp|hackathon|coding|techome/' => 'bootcamp', '/workshop|masterclass|training|clinic|seminar|webinar/' => 'workshop',
        '/town ?hall|forum|assembly|agm/' => 'townhall', '/storm|school|students|tutor|waec|exam/' => 'school',
        '/showcase|concert|art|music|stardom|talent|film|media/' => 'arts',
        '/prayer|worship|devotion|faith|kingdom|church|mosque|carol/' => 'faith',
        '/football|sport|match|race|fitness|wellbeing|health/' => 'sports',
        '/meetup|get-together|community|volunteer|chapter|parade/' => 'meetup',
        '/leader|incorrupt|integrity|character|creed|genius/' => 'leadership', '/tech|digital|code|data|laptop/' => 'technology',
        '/culture|heritage|language|tradition/' => 'culture', '/nation|governance|democracy|citizen|corruption|africa/' => 'nation',
        '/education|learn|appraisal|book|read/' => 'education',
    ];
    foreach ($rules as $re => $k) if (preg_match($re, $t)) return $k;
    return 'general';
}

/** FNV-1a over the title's UTF-16 code units, as the browser hashes it (32-bit, then absolute). */
function av_cover_hash(string $s): int
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

/** @param array<string,mixed> $o see the file header */
function av_cover(array $o): string
{
    $s = static fn(string $k): string => trim((string) ($o[$k] ?? ''));
    $cats  = av_cover_cats();
    $kind  = isset(av_cover_sources()[$s('kind')]) ? $s('kind') : 'event';
    $title = $s('title');
    $cat   = $s('category');
    $ck    = isset($cats[$cat]) ? $cat : av_cover_infer($title . ' ' . $cat);
    [$label, $motif] = $cats[$ck];
    $h     = av_cover_hash($title);

    $ratios = ['16:9' => [16, 9], '1.91:1' => [1.91, 1], '3:2' => [3, 2], '4:5' => [4, 5], '1:1' => [1, 1], '9:16' => [9, 16]];
    $rk    = isset($ratios[$s('ratio')]) ? $s('ratio') : '16:9';
    [$rw, $rh] = $ratios[$rk];
    $r     = $rh / $rw;
    $shape = $r >= 1 ? 'tall' : ($r <= .56 ? 'wide' : 'mid');
    $light = $s('tone') === 'light';

    $today = $s('today') !== '' ? $s('today') : date('Y-m-d');
    $date  = preg_match('/^\d{4}-\d{2}-\d{2}$/', $s('date')) ? $s('date') : '';
    $end   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $s('end')) ? $s('end') : '';
    $isEv  = $kind === 'event';
    $live  = $isEv && $date !== '' && $today >= $date && $today <= ($end ?: $date);
    $past  = $isEv && $date !== '' && $today > ($end ?: $date);

    $hols  = av_cover_holidays();
    $hk    = isset($hols[$s('holiday')]) ? $s('holiday') : '';
    if ($hk === '' && $date !== '') {
        foreach ($hols as $k => [, $md]) if ($md === substr($date, 5, 5)) { $hk = $k; break; }
    }

    $kick = ['event' => $label ?: 'Event', 'diary' => $label ?: 'Field note', 'project' => $label ?: 'Programme',
             'appeal' => 'Live appeal', 'course' => $label ?: 'Course', 'news' => $label ?: 'Update'][$kind];
    $ts   = $date !== '' ? strtotime($date) : false;
    $parts = match ($kind) {
        'event'  => [$s('time'), $s('location')],
        'diary'  => [$s('readTime'), $ts ? date('j M Y', $ts) : ''],
        'appeal' => [$s('raised') !== '' && $s('goal') !== '' ? $s('raised') . ' of ' . $s('goal') : ''],
        default  => [$s('meta')],
    };
    $meta = implode(' · ', array_filter($parts, fn($x) => $x !== ''));
    $pct  = max(0, min(100, (int) round((float) ($o['progress'] ?? 0))));
    $badge = $s('badge');
    $source = $s('source') !== '' ? $s('source') : av_cover_sources()[$kind] . ($hk ? ' · ' . $hols[$hk][0] : '');

    $ox = $oy = '0%'; $ang = '0deg';
    if ($motif === 'arcs')    { $ox = (88 + $h % 14) . '%'; $oy = (118 + $h % 20) . '%'; }
    if ($motif === 'contour') { $ox = (80 + $h % 20) . '%'; $oy = (-20 + $h % 25) . '%'; }
    if ($motif === 'hatch')   { $ang = (45 + ($h % 3) * 15) . 'deg'; }
    if ($motif === 'rays')    { $ang = ($h % 30) . 'deg'; }
    $lx = 70 + $h % 30;
    $style = sprintf('--cv-ar:%s/%s;--cv-lx:%d%%;--cv-ly:%d%%;--cv-gx:%d%%;--cv-gl:%d%%;--cv-mx:%dpx;--cv-my:%dpx;--cv-ox:%s;--cv-oy:%s;--cv-ang:%s',
        $rw, $rh, $lx, $h % 40, 100 - ($lx - 70), (int) (100 - $lx / 2), $h % 22, $h % 13, $ox, $oy, $ang);
    if ($kind === 'appeal') $style .= ';--cv-bar:' . $pct . '%';
    $len = mb_strlen($title);

    $cls = 'avcv avcv--' . $ck . ' avcv-m-' . $motif . ' avcv--' . $shape . ($light ? ' avcv--light' : '')
         . ($past ? ' is-past' : '') . ($len > 72 ? ' is-longer' : ($len > 46 ? ' is-long' : ''))
         . ($s('class') !== '' ? ' ' . $s('class') : '');
    $aria = $kick . ': ' . $title . ($meta !== '' ? ' — ' . $meta : '');
    $x = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    $out  = '<div class="' . $x($cls) . '" role="img" aria-label="' . $x($aria) . '" style="' . $x($style) . '">';
    $out .= '<span class="avcv-motif"></span><span class="avcv-light"></span><span class="avcv-grain"></span>';
    $out .= '<span class="avcv-in"><span class="avcv-top"><span class="avcv-src"><span class="avcv-seal"></span><span>'
          . $x($source) . '</span></span>';
    if ($live) $out .= '<span class="avcv-live">Live now</span>';
    elseif ($badge !== '') $out .= '<span class="avcv-badge">' . $x($badge) . '</span>';
    $out .= '</span><span class="avcv-bot">';
    if ($isEv && $ts) {
        $multi = $end !== '' && $end !== $date && ($te = strtotime($end));
        $sub = $past ? 'Took place' : ($multi ? '– ' . date('j M', $te) : $kick);
        $out .= '<span class="avcv-date"><span class="avcv-day">' . date('j', $ts) . '</span><span class="avcv-mon"><span>'
              . date('M Y', $ts) . '</span><span>' . $x($sub) . '</span></span></span>';
    } else {
        $out .= '<span class="avcv-kick">' . $x($kick) . '</span>';
    }
    $out .= '<span class="avcv-title">' . $x($title) . '</span>';
    if ($kind === 'appeal') $out .= '<span class="avcv-bar"><span></span></span>';
    if ($meta !== '') $out .= '<span class="avcv-meta"><span>' . $x($meta) . '</span></span>';
    $out .= '</span></span>';
    if ($hk) $out .= '<span class="avcv-stripe avcv-hol-' . $hk . '"></span>';
    return $out . '</div>';
}

/**
 * The cover for an appeal row with no photo (Give tiles, a project's appeal
 * cards). $st is Appeals::state($a): the bar and "₦raised of ₦goal" come from
 * the verified payment record, as everywhere else an appeal shows money.
 * With $fill it fills the caller's sized media frame (.avcv--fill), whatever
 * its ratio; without, it takes its own height from $ratio.
 */
function av_cover_appeal(array $a, array $st, string $ratio = '3:2', bool $fill = true): string
{
    $goal = (int) ($st['goal'] ?? 0);
    return av_cover([
        'kind'     => 'appeal',
        'title'    => (string) ($a['title'] ?? ''),
        'ratio'    => $ratio,
        'progress' => (int) ($st['percent'] ?? 0),
        'raised'   => $goal > 0 ? Appeals::naira((int) ($st['raised'] ?? 0)) : '',
        'goal'     => $goal > 0 ? Appeals::naira($goal) : '',
        'class'    => $fill ? 'avcv--fill' : '',
    ]);
}
