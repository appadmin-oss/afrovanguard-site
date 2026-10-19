<?php
/** A fine or an award for one mentee: put forward here, decided by the compliance committee. */
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
$kindNow = (string) ($_GET['kind'] ?? ($fineable ? 'fine' : 'award'));
if (!in_array($kindNow, Conduct::KINDS, true)) $kindNow = 'award';
$today = date('Y-m-d');
?>
      <div class="avm-casebar">
        <a class="avm-btn" href="?v=case&amp;id=<?= (int) $cp['id'] ?>">‹ <?= e($cp['mentee_name']) ?></a>
        <span class="avcd-kind"><?= e(Conduct::MENTOR_KINDS[(string) ($cp['kind'] ?? 'academy')] ?? 'Academy mentor') ?></span>
      </div>

<?php if ($flash === 'proposed'): ?>
      <p class="avcd-note is-ok" role="status">Put forward. The compliance committee has been told; you will hear when they decide. Until then nothing is owed and nothing is granted, and <?= e($cp['mentee_name']) ?> does not see it.</p>
<?php elseif ($flash === 'withdrawn'): ?>
      <p class="avcd-note is-ok" role="status">Withdrawn.</p>
<?php elseif ($err !== ''): ?>
      <p class="avcd-note is-bad" role="alert"><?= e($err) ?></p>
<?php endif; ?>

      <section class="avm-panel avcd" aria-labelledby="avcd-h">
        <h2 id="avcd-h">Put something forward for <?= e($cp['mentee_name']) ?></h2>
        <div class="avcd-switch" role="group" aria-label="Fine or award">
          <a href="?v=conduct&amp;id=<?= (int) $cp['id'] ?>&amp;kind=award" class="avcd-tab<?= $kindNow === 'award' ? ' is-on' : '' ?>"<?= $kindNow === 'award' ? ' aria-current="true"' : '' ?>>Award</a>
          <a href="?v=conduct&amp;id=<?= (int) $cp['id'] ?>&amp;kind=fine" class="avcd-tab<?= $kindNow === 'fine' ? ' is-on' : '' ?>"<?= $kindNow === 'fine' ? ' aria-current="true"' : '' ?>>Fine</a>
        </div>

<?php if ($kindNow === 'fine' && !$fineable): ?>
        <p class="avcd-p"><?= e($cp['mentee_name']) ?> has no NextGen Vanguard account, so there is nothing a fine could be owed on. Put an award forward instead, or raise the behaviour with your coordinator.</p>
<?php else: ?>
        <form class="avcd-form" method="post" action="/mentorship/conduct.php">
          <input type="hidden" name="csrf" value="<?= e(av_csrf_token()) ?>">
          <input type="hidden" name="op" value="propose">
          <input type="hidden" name="kind" value="<?= e($kindNow) ?>">
          <input type="hidden" name="pairing_id" value="<?= (int) $cp['id'] ?>">
          <label>What is it for?
            <select class="avm-select" name="reason" required>
              <option value="">Choose</option>
<?php foreach ($kindNow === 'fine' ? Conduct::FINE_REASONS : Conduct::AWARD_REASONS as $k => $l): ?>
              <option value="<?= e($k) ?>"><?= e($l) ?></option>
<?php endforeach; ?>
            </select>
          </label>
          <label>In a few words<input class="avm-input" type="text" name="title" maxlength="160" placeholder="<?= $kindNow === 'fine' ? 'e.g. Disrespected a facilitator' : 'e.g. Led the Saturday clean-up' ?>"></label>
<?php if ($kindNow === 'fine'): ?>
          <label>Amount (₦)<input class="avm-input" type="text" name="amount" inputmode="numeric" required placeholder="2000"><small>Up to ₦<?= number_format(Conduct::FINE_MAX) ?>. It is posted to their NGV account only once approved.</small></label>
<?php else: ?>
          <label>Points<input class="avm-input" type="number" name="points" min="1" max="<?= Conduct::POINTS_MAX ?>" required value="5"><small>Up to <?= Conduct::POINTS_MAX ?>, added to their points once approved.</small></label>
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
            <span class="avcd-what"><b><?= $r['kind'] === 'fine' ? 'Fine ₦' . number_format((int) $r['amount']) : 'Award · ' . (int) $r['points'] . ' points' ?></b>
              <span><?= e($r['title'] !== '' ? $r['title'] : (($r['kind'] === 'fine' ? Conduct::FINE_REASONS : Conduct::AWARD_REASONS)[$r['reason']] ?? $r['reason'])) ?> · <?= e($r['occurred_on']) ?></span>
<?php if ($r['status'] === 'rejected' && trim((string) $r['decision_note']) !== ''): ?>              <span>Why: <?= e((string) $r['decision_note']) ?></span>
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
