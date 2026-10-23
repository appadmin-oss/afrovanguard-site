<?php
/** An award, a deduction or a fine for one mentee, from the Quest's priced catalogue: put forward here, decided by the compliance committee. */
$cp = Conduct::pairing($uid, $id);
$flash = (string) ($_GET['done'] ?? '');
$err = (string) ($_GET['err'] ?? '');
$statusWord = ['proposed' => 'Waiting for the committee', 'approved' => 'Approved', 'rejected' => 'Not approved', 'withdrawn' => 'Withdrawn'];
$statusTone = ['proposed' => 'gold', 'approved' => 'green', 'rejected' => 'red', 'withdrawn' => 'gray'];
if (!$cp):
?>
      <section class="avm-panel avcd">
        <h2>Choose a mentee</h2>
        <p class="avcd-p">Open a mentee’s case file and press <b>Fine or award</b>.</p>
        <ul class="avcd-list">
<?php foreach ($portal->menteeOptions() as $m): ?>
          <li><a href="?v=conduct&amp;id=<?= (int) $m['id'] ?>"><?= e($m['name']) ?></a></li>
<?php endforeach; ?>
        </ul>
      </section>
<?php return; endif;
$fineable = Conduct::fineable((int) $cp['mentee_id']);
$mine = Conduct::forPairing($uid, (int) $cp['id']);
$awardsOn = AvRules::bool('quest.awards_enabled');
$customOn = AvRules::bool('quest.awards_custom');
$kindTabs = ['award' => 'Award'];
if (AvRules::bool('quest.deductions_enabled')) $kindTabs['deduction'] = 'Deduction';
$kindTabs['fine'] = 'Fine';
$kindNow = (string) ($_GET['kind'] ?? 'award');
if (!isset($kindTabs[$kindNow])) $kindNow = 'award';
$kindIntro = [
    'award'     => 'Pick the deed from the catalogue. The committee confirms it; then the points are theirs.',
    'deduction' => 'Points taken away for the manual\'s breaches. The committee confirms it like an award; it never costs anybody their level by itself.',
    'fine'      => 'Money owed on their NGV account, at the manual\'s price.',
];
/* The picker: catalogue lines by group, price in the label, switched-off
   lines left out; the old reasons only where a switch allows them. */
$picker = [];
if ($kindNow === 'award') {
    $groups = Quest::groups('awardable-actions');
    foreach (QuestRules::items('award') as $aid => $a) {
        if (!$a['enabled'] || $aid === 'mentee_rises') continue;
        $picker[$groups[$a['group']] ?? 'Other'][$aid] = '+' . (int) $a['value'] . ' · ' . $a['label'];
    }
    if ($customOn) $picker['Not in the catalogue']['reason:other'] = 'Other — say what, and give the points';
} elseif ($kindNow === 'deduction') {
    foreach (QuestRules::items('deduction') as $did => $d) {
        if ($d['enabled']) $picker['Deductions'][$did] = '−' . (int) $d['value'] . ' · ' . $d['label'];
    }
} else {
    $groups = Quest::groups('fines');
    foreach (QuestRules::items('fine') as $fid => $f) {
        if (!$f['enabled']) continue;
        $picker[$groups[$f['group']] ?? 'Other'][$fid] = ($f['value'] === null ? 'priced each time' : '₦' . number_format((int) $f['value'])) . ' · ' . $f['label'];
    }
    foreach (Conduct::FINE_REASONS as $k => $l) $picker['Not in the catalogue']['reason:' . $k] = $l;
}
$today = date('Y-m-d');
?>
      <div class="avm-casebar">
        <a class="avm-btn" href="?v=case&amp;id=<?= (int) $cp['id'] ?>">‹ <?= e($cp['mentee_name']) ?></a>
        <span class="avcd-kind"><?= e(Conduct::MENTOR_KINDS[(string) ($cp['kind'] ?? 'academy')] ?? 'Academy mentor') ?></span>
      </div>

<?php if ($flash === 'proposed'): ?>
      <p class="avcd-note is-ok" role="status">Put forward. The compliance committee has been told; you will hear when they decide. Until then nothing is owed and nothing is granted. <?= in_array($kindNow, Conduct::ADVERSE, true) ? e($cp['mentee_name']) . ' is shown it now, like a bill, and may accept it or give their side; the committee reads both.' : e($cp['mentee_name']) . ' does not see it.' ?></p>
<?php elseif ($flash === 'withdrawn'): ?>
      <p class="avcd-note is-ok" role="status">Withdrawn.</p>
<?php elseif ($err !== ''): ?>
      <p class="avcd-note is-bad" role="alert"><?= e($err) ?></p>
<?php endif; ?>

      <section class="avm-panel avcd" aria-labelledby="avcd-h">
        <h2 id="avcd-h">Put something forward for <?= e($cp['mentee_name']) ?></h2>
        <div class="avcd-switch" role="group" aria-label="Award, deduction or fine">
<?php foreach ($kindTabs as $k => $word): ?>
          <a href="?v=conduct&amp;id=<?= (int) $cp['id'] ?>&amp;kind=<?= e($k) ?>" class="avcd-tab<?= $kindNow === $k ? ' is-on' : '' ?>"<?= $kindNow === $k ? ' aria-current="true"' : '' ?>><?= e($word) ?></a>
<?php endforeach; ?>
        </div>

<?php if ($kindNow === 'fine' && !$fineable): ?>
        <p class="avcd-p"><?= e($cp['mentee_name']) ?> has no NextGen Vanguard account, so there is nothing a fine could be owed on. Put an award forward instead, or raise the behaviour with your coordinator.</p>
<?php elseif ($kindNow === 'award' && !$awardsOn): ?>
        <p class="avcd-p">Awards are switched off for now.</p>
<?php else: ?>
        <p class="avcd-p"><?= e($kindIntro[$kindNow] ?? '') ?></p>
        <form class="avcd-form" method="post" action="/mentorship/conduct.php">
          <input type="hidden" name="csrf" value="<?= e(av_csrf_token()) ?>">
          <input type="hidden" name="op" value="propose">
          <input type="hidden" name="kind" value="<?= e($kindNow) ?>">
          <input type="hidden" name="pairing_id" value="<?= (int) $cp['id'] ?>">
          <label class="avcd-span">What is it for?
            <select class="avm-select" name="action" required>
              <option value="">Choose</option>
<?php foreach ($picker as $groupLabel => $opts): ?>
              <optgroup label="<?= e($groupLabel) ?>">
<?php foreach ($opts as $val => $word): ?>
                <option value="<?= e($val) ?>"><?= e($word) ?></option>
<?php endforeach; ?>
              </optgroup>
<?php endforeach; ?>
            </select>
            <small><?= $kindNow === 'fine' ? 'The amount is the catalogue\'s; type one only for a fine it prices each time, or for Other.' : 'The points are the catalogue\'s, not yours — so the same deed is worth the same for everyone.' ?></small>
          </label>
          <label>In a few words<input class="avm-input" type="text" name="title" maxlength="160" placeholder="<?= $kindNow === 'award' ? 'e.g. Led the Saturday clean-up' : 'e.g. Missed Tuesday\'s meeting without word' ?>"></label>
<?php if ($kindNow === 'fine'): ?>
          <label>Amount (₦), only if asked<input class="avm-input" type="text" name="amount" inputmode="numeric" placeholder="2000"><small>Up to ₦<?= number_format(Conduct::FINE_MAX) ?>. Posted to their NGV account only once approved.</small></label>
<?php elseif ($kindNow === 'award' && $customOn): ?>
          <label>Points, only for Other<input class="avm-input" type="number" name="points" min="1" max="<?= Conduct::POINTS_MAX ?>"><small>Up to <?= Conduct::POINTS_MAX ?>. A catalogue action ignores this.</small></label>
<?php endif; ?>
          <label>The day it happened<input class="avm-input" type="date" name="occurred_on" required max="<?= e($today) ?>" value="<?= e($today) ?>"></label>
          <label class="avcd-span">What you saw
            <textarea class="avm-textarea" name="evidence" rows="5" required minlength="<?= Conduct::EVIDENCE_MIN ?>" placeholder="When, where, and what was said or done. The committee decides on this — and <?= e($cp['mentee_name']) ?> may read it if they query it."></textarea>
          </label>
          <div class="avcd-span"><button class="avm-btn avm-btn--ink" type="submit">Put it forward</button></div>
        </form>
<?php endif; ?>
      </section>

      <section class="avm-panel avcd" aria-labelledby="avcd-mine">
        <h2 id="avcd-mine">What you have put forward</h2>
<?php if (!$mine): ?>
        <p class="avcd-p">Nothing yet.</p>
<?php else: ?>
        <ul class="avcd-cases">
<?php foreach ($mine as $r): ?>
          <li>
            <span class="avcd-what"><b><?= $r['kind'] === 'fine' ? 'Fine ₦' . number_format((int) $r['amount']) : ($r['kind'] === 'deduction' ? 'Deduction · −' . (int) $r['points'] . ' points' : 'Award · ' . (int) $r['points'] . ' points') ?></b>
              <span><?= e($r['title'] !== '' ? $r['title'] : Conduct::caseLabel($r)) ?> · <?= e($r['occurred_on']) ?></span>
<?php if ($r['status'] === 'rejected' && trim((string) $r['decision_note']) !== ''): ?>              <span>Why: <?= e((string) $r['decision_note']) ?></span>
<?php endif; ?>
<?php if (in_array((string) $r['kind'], Conduct::ADVERSE, true)): ?>
              <span><?= (string) $r['response'] === '' ? ($r['status'] === 'proposed' ? 'Not answered yet' : 'Not answered')
                  : 'The member ' . e(strtolower(Conduct::RESPONSES[(string) $r['response']] ?? '')) . ' it' . ((string) $r['response'] === 'contested' && trim((string) $r['response_note']) !== '' ? ': ' . e((string) $r['response_note']) : '') ?></span>
<?php if (!empty($r['appeal_status'])): ?>
              <span>Appeal: <?= $r['appeal_status'] === 'open' ? 'being heard' : e(strtolower(Conduct::APPEAL_OUTCOMES[$r['appeal_status']] ?? '')) ?><?= $r['appeal_status'] === 'reduced' ? ' to ' . e($r['kind'] === 'fine' ? '₦' . number_format((int) $r['appeal_value']) : (int) $r['appeal_value'] . ' points') : '' ?></span>
<?php endif; ?>
<?php endif; ?>
            </span>
            <span class="avcd-chip" data-tone="<?= e($statusTone[$r['status']] ?? 'gray') ?>"><?= e($statusWord[$r['status']] ?? $r['status']) ?></span>
<?php if ($r['status'] === 'proposed'): ?>
            <form method="post" action="/mentorship/conduct.php">
              <input type="hidden" name="csrf" value="<?= e(av_csrf_token()) ?>">
              <input type="hidden" name="op" value="withdraw"><input type="hidden" name="case_id" value="<?= (int) $r['id'] ?>">
              <input type="hidden" name="pairing_id" value="<?= (int) $cp['id'] ?>">
              <button class="avm-btn" type="submit">Withdraw</button>
            </form>
<?php endif; ?>
          </li>
<?php endforeach; ?>
        </ul>
<?php endif; ?>
      </section>
