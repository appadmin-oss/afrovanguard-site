<?php
/**
 * Template: vanquest_welcome
 * Vars: placement (optional: the O–D letter the visitor's placement test gave, or '')
 *       xp        (optional: the visitor's XP on the site after joining; at least 50)
 * Sent by process-contact.php when someone joins from vquest.afrovanguard.org.ng.
 * Copy follows the VanQuest site (van-quest site/index.html, placement/): XP is
 * the website's tally, not Quest points; a placement is a potential level that
 * an interview confirms. Art: tools/make-vanquest-email-art.php.
 */
$frame = 'vq';
$v += [
    'placement'       => '',
    'xp'              => 50,
    'vq_url'          => 'https://vquest.afrovanguard.org.ng',
    'unsubscribe_url' => 'mailto:cacentre@afrovanguard.org.ng?subject=Unsubscribe%20from%20VanQuest',
];

/* Rank names from the Quest's own ladder; the letters alone if it is missing. */
$title = [];
foreach (json_decode((string) @file_get_contents(__DIR__ . '/../../quest/ranks.json'), true)['ranks'] ?? [] as $r) $title[$r['id']] = $r['title'];
if (!$title) $title = array_combine(str_split('OABCDEFG'), str_split('OABCDEFG'));
$ids    = array_keys($title);
$placed = in_array($v['placement'], ['O', 'A', 'B', 'C', 'D'], true) ? $v['placement'] : '';
$at     = $placed ?: 'O';
$pos    = array_search($at, $ids, true);
$xp     = max(50, (int) $v['xp']);
$cta    = $placed ? ['Continue the quest', $v['vq_url'] . '/'] : ['Find your starting level', $v['vq_url'] . '/placement/'];

$subject   = 'Quest accepted: welcome to VanQuest';
$preheader = $placed
    ? 'You placed at ' . $placed . '. Next: the interview that confirms it.'
    : '+50 XP. Your first quest: find your starting level.';

/* VanQuest's type and surfaces (van-quest site/css/vq-base.css). */
$sans  = "font-family:Geist,Arial,Helvetica,sans-serif;";
$mono  = "font-family:'Geist Mono','Courier New',Courier,monospace;";
$label = $mono . "font-size:10.5px;line-height:16px;letter-spacing:.12em;color:#8a8073;";
$panel = "background:#1d1915;border:1px solid #2c2620;";

/* An illustration: the GIF, and its last frame as PNG for Outlook. */
$art = function (string $n, int $w, int $h, string $style = '') use ($v): string {
    $img = fn(string $ext) => '<img class="av-art" src="' . e($v['art_base']) . "/$n.$ext\" width=\"$w\" height=\"$h\" alt=\"\" style=\"display:block;width:{$w}px;height:{$h}px;border:0;$style\">";
    return '<!--[if !mso]><!-->' . $img('gif') . '<!--<![endif]--><!--[if mso]>' . $img('png') . '<![endif]-->';
};

/* One quest-log entry: icon, what to do, what it gives. */
$quest = function (string $icon, string $name, string $sub, string $reward, bool $done, string $href = '') use ($art, $sans, $mono, $panel): string {
    $name = $href === '' ? e($name)
        : '<a href="' . e($href) . '" style="color:#f4efe6;text-decoration:none;">' . e($name) . '&nbsp;<span style="color:#ff9f1c;">→</span></a>';
    return '<tr><td style="padding:5px 0;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#1d1915" style="' . $panel . 'border-radius:12px;' . ($done ? 'border-color:#5a3a14;' : '') . '"><tr>'
        . '<td width="40" valign="middle" style="padding:10px 0 10px 12px;">' . $art($icon, 40, 40, 'border-radius:8px;') . '</td>'
        . '<td valign="middle" style="padding:10px 12px;' . $sans . 'text-align:left;">'
        .   '<div style="font-size:15px;line-height:20px;font-weight:600;color:' . ($done ? '#ffc65c' : '#f4efe6') . ';">' . $name . '</div>'
        .   '<div style="font-size:13px;line-height:19px;color:#a99f92;">' . e($sub) . '</div></td>'
        . '<td valign="middle" align="right" class="vq-mono" style="padding:10px 14px 10px 0;' . $mono . 'font-size:11px;line-height:16px;letter-spacing:.08em;white-space:nowrap;text-align:right;color:' . ($done ? '#ff9f1c' : '#8a8073') . ';">' . e($reward) . '</td>'
        . '</tr></table></td></tr>';
};

/* The ladder, one cell per rank: reached, current, still ahead. */
$rung = function (string $id, int $i) use ($title, $pos, $mono): string {
    $look = $i === $pos ? 'background:#4a2c0c;border:1px solid #ff9f1c;color:#ff9f1c;font-weight:600;'
        : ($i < $pos ? 'background:#2a1f12;border:1px solid #4a3418;color:#c9c0b2;' : 'background:#191512;border:1px solid #2a241e;color:#5f574d;');
    return '<td align="center" style="padding:0 2px;"><span class="vq-mono vq-rung" title="' . e($title[$id]) . '" style="display:block;' . $mono . 'font-size:12px;line-height:28px;height:28px;border-radius:7px;text-align:center;' . $look . '">' . e($id) . '</span></td>';
};
?>
<tr><td align="center" style="padding:28px 28px 0;text-align:center;"><?= $art('vqhero', 192, 120, 'margin:0 auto;border-radius:12px;') ?></td></tr>
<tr><td class="av-px" align="center" style="padding:22px 40px 0;text-align:center;"><div class="vq-mono av-rise" style="<?= $mono ?>font-size:11.5px;line-height:16px;letter-spacing:.14em;color:#ff9f1c;">QUEST ACCEPTED</div></td></tr>
<tr><td class="av-px" align="center" style="padding:10px 40px 0;text-align:center;"><h1 class="av-h1 av-rise" style="margin:0;<?= $sans ?>font-size:36px;line-height:38px;font-weight:600;letter-spacing:-0.035em;color:#f4efe6;mso-line-height-rule:exactly;">You’re in the Quest.</h1></td></tr>
<tr><td class="av-px" align="center" style="padding:14px 48px 0;text-align:center;"><div class="av-rise2" style="<?= $sans ?>font-size:16px;line-height:25px;color:#c9c0b2;mso-line-height-rule:exactly;">Welcome to VanQuest, an Afrovanguard initiative. Build an incorruptible character, strengthen your community, help build a better Africa. The first step is behind you.</div></td></tr>

<tr><td class="av-px" style="padding:26px 32px 0;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#1d1915" style="<?= $panel ?>border-radius:14px;"><tr><td style="padding:16px 18px 18px;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>
<td class="vq-mono" style="<?= $label ?>text-align:left;">PLAYER CARD</td>
<td class="vq-mono" align="right" style="<?= $label ?>text-align:right;">RANK <?= sprintf('%02d / %02d', $pos + 1, count($ids)) ?></td>
</tr></table>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-top:10px;"><tr>
<td width="54" valign="middle"><table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr><td class="vq-now" width="52" height="58" align="center" valign="middle" bgcolor="#120f0c" style="width:52px;height:58px;background:#120f0c;border:1px solid #a8661a;border-radius:12px;<?= $sans ?>font-size:34px;line-height:58px;font-weight:700;color:#ff9f1c;text-align:center;"><?= e($at) ?></td></tr></table></td>
<td valign="middle" style="padding-left:14px;<?= $sans ?>text-align:left;">
<div style="font-size:20px;line-height:24px;font-weight:600;letter-spacing:-0.02em;color:#f4efe6;"><?= e(($placed ? 'Potential ' : '') . $title[$at]) ?></div>
<div style="font-size:13px;line-height:19px;color:#a99f92;"><?= $placed ? 'From your placement test. An interview confirms it.' : 'Where everybody begins. Come as you are.' ?></div></td>
<td valign="middle" align="right" style="<?= $sans ?>text-align:right;white-space:nowrap;"><span style="font-size:26px;line-height:30px;font-weight:700;color:#ff9f1c;"><?= $xp ?></span><span class="vq-mono" style="<?= $mono ?>font-size:11px;letter-spacing:.08em;color:#8a8073;">&nbsp;XP</span></td>
</tr></table>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-top:14px;table-layout:fixed;"><tr><?= implode(' ', array_map($rung, $ids, array_keys($ids))) ?></tr></table>
<div style="padding-top:8px;<?= $sans ?>font-size:12px;line-height:17px;color:#8a8073;text-align:left;"><?= e(count($ids) . ' levels, ' . $title[$ids[0]] . ' to ' . end($title)) ?>. Each asks for Quest points, earned on evidence, and from Becoming upward, people you mentor.</div>
</td></tr></table>
</td></tr>

<tr><td class="av-px" style="padding:24px 32px 4px;"><div class="vq-mono" style="<?= $label ?>text-align:left;">QUEST LOG</div></td></tr>
<tr><td class="av-px" style="padding:0 32px;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
<?= $quest('vqcheck', 'Join the Quest', 'Done. Welcome in.', '+50 XP', true) ?>
<?= $placed
    ? $quest('vqflame', 'Find your starting level', 'You placed at ' . $placed . ': potential ' . $title[$placed] . '.', '+25 XP', true)
    : $quest('vqflame', 'Find your starting level', 'Twelve situations place you from O to D. About 3 minutes.', '+25 XP', false, $v['vq_url'] . '/placement/') ?>
<?= $quest('vqbeacon', 'Explore the world', 'Walk the five places of the Quest in 3D.', 'EXPLORE', false, $v['vq_url'] . '/world/?play') ?>
<?= $quest('vqpeople', 'The interview', 'An interview with Afrovanguard confirms your starting level.', $placed ? 'NEXT' : 'LOCKED', false) ?>
</table></td></tr>

<tr><td class="av-px" align="center" style="padding:26px 40px 0;text-align:center;"><table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center"><tr><td bgcolor="#ff9f1c" style="background:#ff9f1c;border-radius:999px;">
<!--[if mso]><v:roundrect xmlns:v="urn:schemas-microsoft-com:vml" xmlns:w="urn:schemas-microsoft-com:office:word" href="<?= e($cta[1]) ?>" style="height:48px;v-text-anchor:middle;width:260px;" arcsize="50%" stroke="f" fillcolor="#ff9f1c"><w:anchorlock/><center style="color:#0b0a09;font-family:Arial,sans-serif;font-size:15px;font-weight:bold;"><?= e($cta[0]) ?></center></v:roundrect><![endif]-->
<!--[if !mso]><!--><a class="av-btn" href="<?= e($cta[1]) ?>" style="display:inline-block;padding:14px 28px;<?= $sans ?>font-size:15px;line-height:20px;font-weight:700;color:#0b0a09;text-decoration:none;border-radius:999px;"><?= e($cta[0]) ?> →</a><!--<![endif]-->
</td></tr></table></td></tr>
<tr><td class="av-px" align="center" style="padding:22px 48px 28px;text-align:center;"><div style="<?= $sans ?>font-size:12.5px;line-height:19px;color:#8a8073;">XP is the website’s tally of your journey. It is not Quest points: those are earned on evidence once you are in.</div></td></tr>
