<?php
/**
 * Pieces shared by the VanQuest mails (vanquest_welcome, vanquest_onboard), which
 * sit on _frame_vq.php. VanQuest's tokens: site/css/vq-base.css in van-quest.
 * Every function returns markup; values are escaped here, never by the caller.
 */
declare(strict_types=1);

const VQ_SANS  = "font-family:Geist,Arial,Helvetica,sans-serif;";
const VQ_MONO  = "font-family:'Geist Mono','Courier New',Courier,monospace;";
const VQ_LABEL = VQ_MONO . "font-size:10.5px;line-height:16px;letter-spacing:.12em;color:#8a8073;";
const VQ_PANEL = "background:#1d1915;border:1px solid #2c2620;";

/** The defaults every VanQuest mail shares; adds the rank names from lib/quest/ranks.json. */
function vq_defaults(array &$v): void
{
    $v += [
        'placement' => '', 'xp' => 50, 'first_name' => '',
        'vq_url' => 'https://vquest.afrovanguard.org.ng',
        'unsubscribe_url' => 'mailto:cacentre@afrovanguard.org.ng?subject=Unsubscribe%20from%20VanQuest',
    ];
    $title = [];
    foreach (json_decode((string) @file_get_contents(__DIR__ . '/../quest/ranks.json'), true)['ranks'] ?? [] as $r) $title[$r['id']] = $r['title'];
    $v['_ranks'] = $title ?: array_combine(str_split('OABCDEFG'), str_split('OABCDEFG'));
    $v['_placed'] = in_array($v['placement'], ['O', 'A', 'B', 'C', 'D'], true) ? $v['placement'] : '';
    $v['_xp'] = max(50, (int) $v['xp']);
}

/** An illustration: the GIF, and its last frame as PNG for Outlook. */
function vq_art(array $v, string $n, int $w, int $h, string $style = ''): string
{
    $img = fn(string $ext) => '<img class="av-art" src="' . e($v['art_base']) . "/$n.$ext\" width=\"$w\" height=\"$h\" alt=\"\" style=\"display:block;width:{$w}px;height:{$h}px;border:0;$style\">";
    return '<!--[if !mso]><!-->' . $img('gif') . '<!--<![endif]--><!--[if mso]>' . $img('png') . '<![endif]-->';
}

/** A section label, mono caps. */
function vq_label(string $text, string $pad = '24px 32px 6px'): string
{
    return '<tr><td class="av-px" style="padding:' . $pad . ';"><div class="vq-mono" style="' . VQ_LABEL . 'text-align:left;">' . e($text) . '</div></td></tr>';
}

/** The orange pill button, with its Outlook VML twin. */
function vq_button(string $text, string $href): string
{
    return '<tr><td class="av-px" align="center" style="padding:24px 40px 0;text-align:center;"><table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center"><tr><td bgcolor="#ff9f1c" style="background:#ff9f1c;border-radius:999px;">'
        . '<!--[if mso]><v:roundrect xmlns:v="urn:schemas-microsoft-com:vml" xmlns:w="urn:schemas-microsoft-com:office:word" href="' . e($href) . '" style="height:50px;v-text-anchor:middle;width:280px;" arcsize="50%" stroke="f" fillcolor="#ff9f1c"><w:anchorlock/><center style="color:#0b0a09;font-family:Arial,sans-serif;font-size:16px;font-weight:bold;">' . e($text) . '</center></v:roundrect><![endif]-->'
        . '<!--[if !mso]><!--><a class="av-btn" href="' . e($href) . '" style="display:inline-block;padding:15px 32px;' . VQ_SANS . 'font-size:16px;line-height:20px;font-weight:700;color:#0b0a09;text-decoration:none;border-radius:999px;">' . e($text) . ' →</a><!--<![endif]-->'
        . '</td></tr></table></td></tr>';
}

/** The player card: rank letter, name, XP, the ladder. $seat: '' to leave the seat line out. */
function vq_player_card(array $v, string $seat = ''): string
{
    $title = $v['_ranks']; $ids = array_keys($title);
    $at = $v['_placed'] ?: 'O'; $pos = (int) array_search($at, $ids, true);
    $name = ($v['_placed'] ? 'Potential ' : '') . $title[$at];
    $sub = $v['_placed'] ? 'From your placement test. An interview confirms it.' : 'Where everybody begins. Come as you are.';
    $rungs = '';
    foreach ($ids as $i => $id) {
        $look = $i === $pos ? 'background:#4a2c0c;border:1px solid #ff9f1c;color:#ff9f1c;font-weight:600;'
            : ($i < $pos ? 'background:#2a1f12;border:1px solid #4a3418;color:#c9c0b2;' : 'background:#191512;border:1px solid #2a241e;color:#5f574d;');
        $rungs .= '<td align="center" style="padding:0 2px;"><span class="vq-mono vq-rung" title="' . e($title[$id]) . '" style="display:block;' . VQ_MONO . 'font-size:12px;line-height:28px;height:28px;border-radius:7px;text-align:center;' . $look . '">' . e($id) . '</span></td> ';
    }
    return '<tr><td class="av-px" style="padding:0 32px;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#1d1915" style="' . VQ_PANEL . 'border-radius:14px;"><tr><td style="padding:16px 18px 18px;">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>'
        .   '<td class="vq-mono" style="' . VQ_LABEL . 'text-align:left;">' . ($v['first_name'] !== '' ? e(mb_strtoupper($v['first_name'], 'UTF-8')) : 'PLAYER CARD') . '</td> '
        .   '<td class="vq-mono" align="right" style="' . VQ_LABEL . 'text-align:right;">RANK ' . sprintf('%02d / %02d', $pos + 1, count($ids)) . '</td></tr></table>'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-top:10px;"><tr>'
        .   '<td width="54" valign="middle"><table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr><td class="vq-now" width="52" height="58" align="center" valign="middle" bgcolor="#120f0c" style="width:52px;height:58px;background:#120f0c;border:1px solid #a8661a;border-radius:12px;' . VQ_SANS . 'font-size:34px;line-height:58px;font-weight:700;color:#ff9f1c;text-align:center;">' . e($at) . '</td></tr></table></td>'
        .   '<td valign="middle" style="padding-left:14px;' . VQ_SANS . 'text-align:left;"><div style="font-size:20px;line-height:24px;font-weight:600;letter-spacing:-0.02em;color:#f4efe6;">' . e($name) . '</div><div style="font-size:13px;line-height:19px;color:#a99f92;">' . e($sub) . '</div></td>'
        .   '<td valign="middle" align="right" style="' . VQ_SANS . 'text-align:right;white-space:nowrap;"><span style="font-size:26px;line-height:30px;font-weight:700;color:#ff9f1c;">' . $v['_xp'] . '</span><span class="vq-mono" style="' . VQ_MONO . 'font-size:11px;letter-spacing:.08em;color:#8a8073;">&nbsp;XP</span></td>'
        . '</tr></table>'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-top:14px;table-layout:fixed;"><tr>' . $rungs . '</tr></table>'
        . ($seat !== '' ? '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-top:12px;border-top:1px solid #2c2620;"><tr><td class="vq-mono" style="padding-top:10px;' . VQ_LABEL . 'text-align:left;">SEAT</td> <td class="vq-mono" align="right" style="padding-top:10px;' . VQ_MONO . 'font-size:11px;letter-spacing:.1em;color:' . ($seat === 'CONFIRMED' ? '#ff9f1c' : '#ffc65c') . ';text-align:right;">' . e($seat) . '</td></tr></table>' : '')
        . '</td></tr></table></td></tr>';
}

/** One quest-log entry: icon, what to do, what it gives. */
function vq_quest(array $v, string $icon, string $name, string $sub, string $reward, bool $done, string $href = ''): string
{
    $name = $href === '' ? e($name)
        : '<a href="' . e($href) . '" style="color:#f4efe6;text-decoration:none;">' . e($name) . '&nbsp;<span style="color:#ff9f1c;">→</span></a>';
    return '<tr><td style="padding:5px 0;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#1d1915" style="' . VQ_PANEL . 'border-radius:12px;' . ($done ? 'border-color:#5a3a14;' : '') . '"><tr>'
        . '<td width="40" valign="middle" style="padding:10px 0 10px 12px;">' . vq_art($v, $icon, 40, 40, 'border-radius:8px;') . '</td>'
        . '<td valign="middle" style="padding:10px 12px;' . VQ_SANS . 'text-align:left;">'
        .   '<div style="font-size:15px;line-height:20px;font-weight:600;color:' . ($done ? '#ffc65c' : '#f4efe6') . ';">' . $name . '</div>'
        .   '<div style="font-size:13px;line-height:19px;color:#a99f92;">' . e($sub) . '</div></td>'
        . '<td valign="middle" align="right" class="vq-mono" style="padding:10px 14px 10px 0;' . VQ_MONO . 'font-size:11px;line-height:16px;letter-spacing:.08em;white-space:nowrap;text-align:right;color:' . ($done ? '#ff9f1c' : '#8a8073') . ';">' . e($reward) . '</td>'
        . '</tr></table></td></tr>';
}

/** "Chidi, Amara and 5 others": who is already in, in words. $total: everyone counted, or 0 for just these. */
function vq_names(array $people, int $total = 0): string
{
    $names = array_map(fn($p) => $p['first_name'], array_slice($people, 0, 3));
    $rest = max(0, max($total, count($people)) - count($names));
    if (!$names) return '';
    if ($rest > 0) return implode(', ', $names) . ' and ' . $rest . ' ' . ($rest === 1 ? 'other' : 'others');
    return count($names) > 1 ? implode(', ', array_slice($names, 0, -1)) . ' and ' . end($names) : $names[0];
}

/** Players, as avatars with first name and rank letter. $people: VanQuest::people(). */
function vq_people(array $people, int $max = 6): string
{
    $people = array_slice($people, 0, $max);
    if (!$people) return '';
    $cells = '';
    foreach ($people as $p) {
        $cells .= '<td align="center" valign="top" style="padding:4px 2px;' . VQ_SANS . 'text-align:center;">'
            . '<img src="' . e($p['avatar']) . '" width="44" height="44" alt="" style="display:block;margin:0 auto;width:44px;height:44px;border:1px solid #2c2620;border-radius:10px;image-rendering:pixelated;">'
            . '<div style="padding-top:5px;font-size:12px;line-height:15px;font-weight:600;color:#f4efe6;">' . e($p['first_name']) . '</div>'
            . '<div class="vq-mono" style="' . VQ_MONO . 'font-size:10px;line-height:14px;letter-spacing:.08em;color:#ff9f1c;">' . e($p['letter']) . '</div></td>';
    }
    return '<tr><td class="av-px" style="padding:10px 28px 0;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="table-layout:fixed;"><tr>' . $cells . '</tr></table></td></tr>';
}

/** A centred paragraph in the body voice. */
function vq_para(string $html, string $pad = '14px 48px 0', string $size = '16px', string $colour = '#c9c0b2'): string
{
    return '<tr><td class="av-px" align="center" style="padding:' . $pad . ';text-align:center;"><div style="' . VQ_SANS . 'font-size:' . $size . ';line-height:1.55;color:' . $colour . ';mso-line-height-rule:exactly;">' . $html . '</div></td></tr>';
}

/** Kicker and headline, under the hero. */
function vq_head(string $kicker, string $h1): string
{
    return '<tr><td class="av-px" align="center" style="padding:22px 40px 0;text-align:center;"><div class="vq-mono av-rise" style="' . VQ_MONO . 'font-size:11.5px;line-height:16px;letter-spacing:.14em;color:#ff9f1c;">' . e($kicker) . '</div></td></tr>'
        . '<tr><td class="av-px" align="center" style="padding:10px 40px 0;text-align:center;"><h1 class="av-h1 av-rise" style="margin:0;' . VQ_SANS . 'font-size:36px;line-height:38px;font-weight:600;letter-spacing:-0.035em;color:#f4efe6;mso-line-height-rule:exactly;">' . e($h1) . '</h1></td></tr>';
}
