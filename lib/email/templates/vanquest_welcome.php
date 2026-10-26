<?php
/**
 * Template: vanquest_welcome — the first VanQuest mail, sent the moment someone joins.
 * Vars: seat_url   the "Confirm your seat" link (VanQuest::seatUrl)
 *       people     optional: VanQuest::people(), players already in the community
 *       players    optional: how many players have a confirmed seat
 *       placement  optional: the O–D letter of the visitor's placement test, or ''
 *       xp         optional: the visitor's site XP after joining; at least 50
 *       seated     optional: true when the seat is already confirmed (a new link)
 *       first_name optional: known once the seat is confirmed
 * No community numbers here: those are on the community page. Copy follows the
 * VanQuest site (van-quest site/index.html). Parts: ../_vq_parts.php.
 */
require_once __DIR__ . '/../_vq_parts.php';
$frame = 'vq';
vq_defaults($v);
$v += ['seat_url' => $v['vq_url'] . '/seat/', 'people' => [], 'players' => 0, 'seated' => false];
$seated = (bool) $v['seated'];
$who = vq_names($v['people'], (int) $v['players']);

$subject   = $seated ? av_t('{first_name}, here is your way back into the community', $v + ['first_name' => $v['first_name'] ?: 'Player']) : 'Confirm your seat in the VanQuest community';
$preheader = $seated ? 'A fresh link to the community. Your old one no longer works.'
    : ($who !== '' ? $who . ' are already in. Confirm your seat to join them.' : 'One click and your seat in the community is yours.');
$tile = fn(string $icon, string $tag, string $name, string $line) => '<tr><td style="padding:5px 0;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#1d1915" style="' . VQ_PANEL . 'border-radius:12px;"><tr>'
    . '<td width="40" valign="top" style="padding:12px 0 12px 12px;">' . vq_art($v, $icon, 40, 40, 'border-radius:8px;') . '</td>'
    . '<td valign="top" style="padding:11px 14px 12px 12px;' . VQ_SANS . 'text-align:left;"><div class="vq-mono" style="' . VQ_MONO . 'font-size:10px;line-height:14px;letter-spacing:.12em;color:#ff9f1c;">' . e($tag) . '</div>'
    . '<div style="font-size:15px;line-height:20px;font-weight:600;color:#f4efe6;">' . e($name) . '</div><div style="font-size:13px;line-height:19px;color:#a99f92;">' . e($line) . '</div></td></tr></table></td></tr>';
?>
<tr><td align="center" style="padding:28px 28px 0;text-align:center;"><?= vq_art($v, 'vqhero', 192, 120, 'margin:0 auto;border-radius:12px;') ?></td></tr>
<?php if ($seated): ?>
<?= vq_head('WELCOME BACK', 'Your seat is still yours' . ($v['first_name'] !== '' ? ', ' . $v['first_name'] : '') . '.') ?>
<?= vq_para('Here is a fresh link into the VanQuest community. Your old link no longer works.') ?>
<?= vq_button('Enter the community', $v['seat_url']) ?>
<?php else: ?>
<?= vq_head('YOUR SEAT IS WAITING', 'Confirm your seat.') ?>
<?= vq_para('You joined VanQuest, an Afrovanguard initiative: a game in which you build an incorruptible character, strengthen your community and help build a better Africa. Confirm your seat to enter the community.') ?>
<?= vq_button('Confirm your seat', $v['seat_url']) ?>
<?php endif; ?>

<?php if ($v['people']): ?>
<?= vq_label('ALREADY IN THE COMMUNITY', '30px 32px 2px') ?>
<?= vq_people($v['people']) ?>
<?= vq_para(e($who) . ($seated ? ' are in the community with you.' : ' are already in. Your seat is next to theirs.'), '8px 40px 0', '13px', '#a99f92') ?>
<?php endif; ?>

<?php if (!$seated): ?>
<?= vq_label('HOW THE GAME WORKS', '30px 32px 6px') ?>
<tr><td class="av-px" style="padding:0 32px;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
<?= $tile('vqflame', 'VANQUEST IN', 'Build your character', 'Conquer within first. Seven values, ten practical challenges each, every one proved by what you do.') ?>
<?= $tile('vqpeople', 'VANQUEST OUT', 'Serve your community', 'Transform beyond. Mentor others, build a community and put your character to work where it counts.') ?>
<?= $tile('vqbeacon', 'THE MISSION', 'Light up the network', 'Every member and every centre is a light on the map, across Africa.') ?>
</table></td></tr>
<?php endif; ?>

<?= vq_label('YOUR STATUS') ?>
<?= vq_player_card($v, $seated ? 'CONFIRMED' : 'NOT CONFIRMED YET') ?>
<?= vq_para('Eight levels, ' . e(reset($v['_ranks'])) . ' to ' . e(end($v['_ranks'])) . '. Each asks for Quest points, earned on evidence, and from Becoming upward, people you mentor.', '8px 40px 0', '12.5px', '#8a8073') ?>

<?php if (!$seated): ?><?= vq_button('Get your seat', $v['seat_url']) ?><?php endif; ?>
<?= vq_para('XP is the website’s tally of your journey, not Quest points. If you did not join VanQuest, ignore this email and nothing happens.', '22px 48px 28px', '12.5px', '#8a8073') ?>
