<?php
/**
 * Template: vanquest_onboard — the second VanQuest mail, fifteen minutes after the seat is confirmed.
 * Vars: first_name, community_url (VanQuest::communityUrl)
 *       placement, xp      optional, as in vanquest_welcome
 *       avatar_url, sex    optional: the player's own pixel avatar
 *       people             optional: VanQuest::people(), others in the community
 *       joined_since       optional: how many confirmed a seat after this player did
 * The one next step is chosen for the player: the placement test if they have
 * not taken it, else the interview. No community numbers: those are on the page.
 * Sent by VanQuest::tick(). Parts: ../_vq_parts.php.
 */
require_once __DIR__ . '/../_vq_parts.php';
$frame = 'vq';
vq_defaults($v);
$v += ['community_url' => $v['vq_url'] . '/community/', 'avatar_url' => '', 'people' => [], 'joined_since' => 0];
$placed = $v['_placed'];
$title = $v['_ranks'];
$since = (int) $v['joined_since'];
$next = $placed
    ? ['Ask for your interview', $v['community_url'] . '#interview', 'You placed at ' . $placed . ', potential ' . $title[$placed] . '. An interview with Afrovanguard confirms it: ask for it from the community page.']
    : ['Find your starting level', $v['vq_url'] . '/placement/', 'Twelve everyday situations place you from O to D, in about three minutes. It is the first thing to do.'];

$subject   = av_t('{first_name}, you’re in the community. ', $v) . ($placed ? 'Next: your interview.' : 'Next: find your starting level.');
$preheader = ($since > 0 ? $since . ' ' . ($since === 1 ? 'player has' : 'players have') . ' joined since you. ' : '') . 'Your next step, chosen for you.';
?>
<tr><td align="center" style="padding:28px 28px 0;text-align:center;">
<?php if ($v['avatar_url'] !== ''): ?>
<img src="<?= e($v['avatar_url']) ?>" width="88" height="88" alt="" style="display:block;margin:0 auto;width:88px;height:88px;border:1px solid #5a3a14;border-radius:18px;image-rendering:pixelated;">
<div class="vq-mono" style="padding-top:8px;<?= VQ_MONO ?>font-size:10.5px;letter-spacing:.12em;color:#8a8073;">THIS IS YOU</div>
<?php else: ?><?= vq_art($v, 'vqhero', 192, 120, 'margin:0 auto;border-radius:12px;') ?><?php endif; ?>
</td></tr>
<?= vq_head('SEAT CONFIRMED', 'Welcome to the community, ' . $v['first_name'] . '.') ?>
<?= vq_para(e($next[2])) ?>
<?= vq_button($next[0], $next[1]) ?>

<?php if ($v['people']): ?>
<?= vq_label($since > 0 ? 'JOINED SINCE YOU' : 'IN THE COMMUNITY WITH YOU', '30px 32px 2px') ?>
<?= vq_people($v['people']) ?>
<?= vq_para(e(vq_names($v['people'], $since)) . ($since > 0 ? ' joined after you. Say hello in the community.' : ' are in the community with you.'), '8px 40px 0', '13px', '#a99f92') ?>
<?php endif; ?>

<?= vq_label('YOUR STATUS', '30px 32px 6px') ?>
<?= vq_player_card($v, 'CONFIRMED') ?>

<?= vq_label('YOUR QUESTS') ?>
<tr><td class="av-px" style="padding:0 32px;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
<?= vq_quest($v, 'vqcheck', 'Confirm your seat', 'Done. You are in the community.', '+50 XP', true) ?>
<?= $placed
    ? vq_quest($v, 'vqflame', 'Find your starting level', 'You placed at ' . $placed . ': potential ' . $title[$placed] . '.', '+25 XP', true)
    : vq_quest($v, 'vqflame', 'Find your starting level', 'Twelve situations place you from O to D. About 3 minutes.', '+25 XP', false, $v['vq_url'] . '/placement/') ?>
<?= vq_quest($v, 'vqbeacon', 'Explore the world', 'Walk the five places of the Quest in 3D.', 'EXPLORE', false, $v['vq_url'] . '/world/?play') ?>
<?= vq_quest($v, 'vqpeople', 'Ask for your interview', 'An interview with Afrovanguard confirms your starting level.', $placed ? 'NEXT' : 'AFTER THE TEST', false, $placed ? $v['community_url'] . '#interview' : '') ?>
</table></td></tr>

<?= vq_button('Enter the community', $v['community_url']) ?>
<?= vq_para('This link is your key to the community: keep this email. XP is the website’s tally of your journey, not Quest points; those are earned on evidence once you are in.', '22px 48px 28px', '12.5px', '#8a8073') ?>
