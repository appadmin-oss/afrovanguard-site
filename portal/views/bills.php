<?php
/**
 * portal/views/bills.php — Bills & appeals: the fines and deductions proposed
 * against this member, put to them like a bill (owner, 2026-10-22).
 *
 *   waiting for your answer   accept it, or contest it with your side
 *   with the committee        answered (or self-reported); a person decides
 *   decided                   approved or not; an approved one may be appealed
 *                             once, in time, and the hearing is shown here
 *   report yourself           a fine or a deduction from the catalogue
 *
 * Every rule — whose case, how late, what is switched on — is Conduct's; this
 * page only draws what Conduct says is possible. Posts go to
 * /mentorship/conduct.php and come back here with ?done= or ?err=.
 */
declare(strict_types=1);

$bId     = (int) $u['id'];
$bRows   = $myBills ?? Conduct::billsFor($bId);   /* the shell reads them once */
$bCsrf   = av_csrf_token();
$bWait   = array_values(array_filter($bRows, static fn($c) => $c['status'] === 'proposed' && (string) $c['response'] === ''));
$bWith   = array_values(array_filter($bRows, static fn($c) => $c['status'] === 'proposed' && (string) $c['response'] !== ''));
$bDone   = array_values(array_filter($bRows, static fn($c) => $c['status'] !== 'proposed'));
$bSelfOn = AvRules::bool('quest.self_report');
$bFineOk = Conduct::fineable($bId);
$bDays   = AvRules::int('quest.appeal_days');
$bPrice  = static fn(array $c): string => $c['kind'] === 'fine' ? '₦' . number_format((int) $c['amount']) : '−' . (int) $c['points'] . ' points';
$bFrom   = static fn(array $c): string => $c['mentor_kind'] === 'self' ? 'You reported this' : 'Proposed by ' . ((string) ($c['mentor_name'] ?? '') !== '' ? $c['mentor_name'] : 'your mentor');
$bDone0  = (string) ($_GET['done'] ?? '');
$bErr    = (string) ($_GET['err'] ?? '');
$bSaid   = ['self_report' => 'Reported. The compliance committee decides it; you will be told.', 'respond' => 'Your answer is with the committee.',
            'appeal' => 'Your appeal is lodged. Somebody who had no part in the decision hears it, and you will be told.', 'self_withdraw' => 'Withdrawn.'][$bDone0] ?? '';

/* What a member may report against themselves: priced catalogue lines only. */
$bPick = [];
if ($bSelfOn) {
    foreach (QuestRules::items('deduction') as $did => $d) {
        if ($d['enabled']) $bPick['deduction'][$did] = '−' . (int) $d['value'] . ' points · ' . $d['label'];
    }
    if ($bFineOk) {
        foreach (QuestRules::items('fine') as $fid => $f) {
            if ($f['enabled'] && $f['value'] !== null) $bPick['fine'][$fid] = '₦' . number_format(QuestMoney::scaleFine($bId, (int) $f['value'])) . ' · ' . $f['label'];
        }
    }
}
?>
      <section class="pview avp-view avp-bills" id="view-bills" data-view="bills" hidden aria-labelledby="avpBillsH">
        <div class="avp-view-head"><h1 class="avp-h1" id="avpBillsH">Bills &amp; appeals</h1></div>
        <p class="avp-note avp-quest-intro">A fine or a deduction is put to you the day it is proposed, with what was seen. Accept it, or give your side; the compliance committee reads both and decides. If it is approved and you think it is wrong, you may appeal once<?= AvRules::bool('quest.appeals_enabled') ? ', within ' . $bDays . ' days' : '' ?>, and somebody new hears it.</p>
<?php if ($bSaid !== ''): ?>
        <p class="avp-bill-flash is-ok" role="status"><?= e($bSaid) ?></p>
<?php elseif ($bErr !== ''): ?>
        <p class="avp-bill-flash is-bad" role="alert"><?= e($bErr) ?></p>
<?php endif; ?>

        <div class="avp-stack">
          <section class="avp-card" aria-labelledby="avpBillsWaitH">
            <div class="avp-card-head"><h2 id="avpBillsWaitH">Waiting for your answer</h2><?php if ($bWait): ?><span class="avp-chip avp-chip--gold"><?= count($bWait) ?></span><?php endif; ?></div>
            <div class="avp-card-body">
<?php if (!$bWait): ?>
              <p class="pc-empty">Nothing is waiting for you.</p>
<?php else: ?>
              <ul class="avp-bills-list">
<?php foreach ($bWait as $c): $left = Conduct::noticeLeft($c); ?>
                <li class="avp-bill">
                  <div class="avp-bill-top"><b><?= e(Conduct::caseLabel($c)) ?></b><span class="avp-bill-price av-num"><?= e($bPrice($c)) ?></span></div>
                  <p class="avp-bill-meta"><?= e($bFrom($c)) ?> · happened <time><?= e((string) $c['occurred_on']) ?></time><?= $left !== '' ? ' · answer by <time>' . e($left) . '</time>' : '' ?></p>
                  <details class="avp-bill-ev"><summary>What was seen</summary><p><?= e((string) $c['evidence']) ?></p></details>
                  <div class="avp-bill-acts">
                    <form method="post" action="/mentorship/conduct.php">
                      <input type="hidden" name="csrf" value="<?= e($bCsrf) ?>"><input type="hidden" name="op" value="respond"><input type="hidden" name="case_id" value="<?= (int) $c['id'] ?>"><input type="hidden" name="answer" value="accepted">
                      <button class="avp-btn" type="submit">I accept it</button>
                    </form>
                    <form class="avp-form avp-bill-contest" method="post" action="/mentorship/conduct.php">
                      <input type="hidden" name="csrf" value="<?= e($bCsrf) ?>"><input type="hidden" name="op" value="respond"><input type="hidden" name="case_id" value="<?= (int) $c['id'] ?>"><input type="hidden" name="answer" value="contested">
                      <label class="avp-form-wide">Your side <textarea name="note" rows="3" minlength="<?= Conduct::EVIDENCE_MIN ?>" required placeholder="What happened, as you saw it"></textarea></label>
                      <button class="avp-btn avp-btn--ink avp-form-wide" type="submit">Contest it</button>
                    </form>
                  </div>
                </li>
<?php endforeach; ?>
              </ul>
<?php endif; ?>
            </div>
          </section>

<?php if ($bWith): ?>
          <section class="avp-card" aria-labelledby="avpBillsWithH">
            <div class="avp-card-head"><h2 id="avpBillsWithH">With the committee</h2></div>
            <div class="avp-card-body">
              <ul class="avp-bills-list">
<?php foreach ($bWith as $c): ?>
                <li class="avp-bill">
                  <div class="avp-bill-top"><b><?= e(Conduct::caseLabel($c)) ?></b><span class="avp-bill-price av-num"><?= e($bPrice($c)) ?></span></div>
                  <p class="avp-bill-meta"><?= e($bFrom($c)) ?> · <?= e(Conduct::RESPONSES[(string) $c['response']] ?? '') ?> <time><?= e(substr((string) $c['responded_at'], 0, 10)) ?></time></p>
<?php if ($c['mentor_kind'] === 'self'): ?>
                  <form method="post" action="/mentorship/conduct.php">
                    <input type="hidden" name="csrf" value="<?= e($bCsrf) ?>"><input type="hidden" name="op" value="self_withdraw"><input type="hidden" name="case_id" value="<?= (int) $c['id'] ?>">
                    <button class="avp-btn avp-btn--sq" type="submit">Withdraw it</button>
                  </form>
<?php endif; ?>
                </li>
<?php endforeach; ?>
              </ul>
            </div>
          </section>
<?php endif; ?>

          <section class="avp-card" aria-labelledby="avpBillsDoneH">
            <div class="avp-card-head"><h2 id="avpBillsDoneH">Decided</h2></div>
            <div class="avp-card-body">
<?php if (!$bDone): ?>
              <p class="pc-empty">Nothing decided yet.</p>
<?php else: ?>
              <ul class="avp-bills-list">
<?php foreach ($bDone as $c): $until = $c['appeal_id'] ? '' : Conduct::appealOpenUntil($c); ?>
                <li class="avp-bill">
                  <div class="avp-bill-top"><b><?= e(Conduct::caseLabel($c)) ?></b><span class="avp-bill-price av-num"><?= e($bPrice($c)) ?></span></div>
                  <p class="avp-bill-meta"><?= e($bFrom($c)) ?> ·
                    <span class="avp-chip avp-chip--sm avp-chip--<?= $c['status'] === 'approved' ? 'red' : 'green' ?>"><?= $c['status'] === 'approved' ? 'Approved' : 'Not approved' ?></span>
                    <time><?= e(substr((string) $c['decided_at'], 0, 10)) ?></time></p>
<?php if (trim((string) $c['decision_note']) !== ''): ?>
                  <p class="avp-bill-note"><?= e((string) $c['decision_note']) ?></p>
<?php endif; ?>
<?php if ($c['appeal_id']): ?>
                  <p class="avp-bill-meta">Appeal: <span class="avp-chip avp-chip--sm avp-chip--<?= ['open' => 'gold', 'upheld' => 'red', 'overturned' => 'green', 'reduced' => 'indigo'][$c['appeal_status']] ?? 'gold' ?>"><?= e($c['appeal_status'] === 'open' ? 'Being heard' : (Conduct::APPEAL_OUTCOMES[$c['appeal_status']] ?? '')) ?></span>
                    <?= $c['appeal_status'] === 'reduced' ? ' to ' . e($c['kind'] === 'fine' ? '₦' . number_format((int) $c['appeal_value']) : (int) $c['appeal_value'] . ' points') : '' ?></p>
<?php if (trim((string) $c['appeal_note']) !== ''): ?>
                  <p class="avp-bill-note"><?= e((string) $c['appeal_note']) ?></p>
<?php endif; ?>
<?php elseif ($until !== ''): ?>
                  <details class="avp-bill-ev">
                    <summary>Appeal — until <?= e($until) ?></summary>
                    <form class="avp-form" method="post" action="/mentorship/conduct.php">
                      <input type="hidden" name="csrf" value="<?= e($bCsrf) ?>"><input type="hidden" name="op" value="appeal"><input type="hidden" name="case_id" value="<?= (int) $c['id'] ?>">
                      <label class="avp-form-wide">Your grounds <textarea name="grounds" rows="3" minlength="<?= Conduct::EVIDENCE_MIN ?>" required placeholder="Why the decision is wrong, or the price too high"></textarea></label>
                      <button class="avp-btn avp-btn--ink avp-form-wide" type="submit">Lodge the appeal</button>
                    </form>
                  </details>
<?php endif; ?>
                </li>
<?php endforeach; ?>
              </ul>
<?php endif; ?>
            </div>
          </section>

<?php if ($bSelfOn && $bPick): ?>
          <section class="avp-card" aria-labelledby="avpBillsSelfH">
            <div class="avp-card-head"><h2 id="avpBillsSelfH">Report yourself</h2></div>
            <div class="avp-card-body">
              <p class="avp-note">Owning a lapse before anybody else raises it is part of accountability. It is decided like any other case, by somebody else, and it cannot be appealed — you said it happened.</p>
              <form class="avp-form" method="post" action="/mentorship/conduct.php">
                <input type="hidden" name="csrf" value="<?= e($bCsrf) ?>"><input type="hidden" name="op" value="self_report">
                <label class="avp-form-wide">From the catalogue
                  <select name="action" required>
<?php foreach ($bPick as $k => $lines): ?>
                    <optgroup label="<?= $k === 'fine' ? 'Fines' : 'Deductions' ?>">
<?php foreach ($lines as $id => $label): ?>                      <option value="<?= e($k . ':' . $id) ?>"><?= e($label) ?></option>
<?php endforeach; ?>
                    </optgroup>
<?php endforeach; ?>
                  </select></label>
                <label>The day <input type="date" name="occurred_on" required max="<?= e(gmdate('Y-m-d')) ?>" value="<?= e(gmdate('Y-m-d')) ?>"></label>
                <label class="avp-form-wide">What happened <textarea name="evidence" rows="3" minlength="<?= Conduct::EVIDENCE_MIN ?>" required placeholder="When, where, and what you did"></textarea></label>
                <button class="avp-btn avp-btn--ink avp-form-wide" type="submit">Report it</button>
              </form>
            </div>
          </section>
<?php endif; ?>
        </div>
      </section>
