<?php
/**
 * Appeals: a member's appeal against an approved fine or deduction, heard by
 * somebody who had no part in the case — not the member, not the proposer,
 * not the person who approved it. Uphold, overturn (the fine is voided, the
 * deduction taken off) or reduce. Every outcome needs a reason; the member
 * reads it.
 */
declare(strict_types=1);
$open = Conduct::appeals('open');
$heard = Conduct::appeals('heard', 80);
$price = static fn(array $c): string => $c['kind'] === 'fine' ? QuestDesk::naira((int) $c['amount']) : '−' . (int) $c['points'] . ' points';
?>
      <section class="pview avp-view qd-view" id="view-appeals" data-view="appeals" hidden aria-labelledby="qdAppealsH">
        <div class="avp-view-head qd-head">
          <div><h1 class="avp-h1" id="qdAppealsH"><?= e($title) ?></h1>
            <p class="qd-sub">A member may appeal an approved fine or deduction once, within <?= AvRules::int('quest.appeal_days') ?> days. Read the case, their answer at the time and their grounds now, then decide. Nobody hears an appeal against a case they proposed, approved, or are the subject of. While an appeal is open, an overdue balance raises no suspension.</p></div>
        </div>
<?php if (!$open): ?>
        <div class="avp-card"><div class="avp-card-body"><p class="qd-empty"><?= AvRules::bool('quest.appeals_enabled') ? 'No appeal is waiting.' : 'Appeals are switched off in Rules.' ?></p></div></div>
<?php else: ?>
        <div class="qd-stack">
<?php foreach ($open as $a):
    $why = (int) $a['mentee_id'] === (int) $u['id'] ? 'This is your own appeal' : ((int) $a['mentor_id'] === (int) $u['id'] ? 'You proposed this case' : ((int) $a['decided_by'] === (int) $u['id'] ? 'You approved this case' : ''));
    $isFine = $a['kind'] === 'fine'; ?>
          <article class="avp-card qd-sug" aria-labelledby="qdApp<?= (int) $a['id'] ?>">
            <div class="avp-card-head">
              <h2 id="qdApp<?= (int) $a['id'] ?>"><?= e((string) $a['mentee_name']) ?> <span class="qd-cell-sub"><?= e(Conduct::caseLabel($a)) ?></span></h2>
              <span class="qd-kindtag" data-kind="<?= e((string) $a['kind']) ?>"><?= e($price($a)) ?></span>
            </div>
            <div class="avp-card-body">
              <dl class="qd-facts">
                <dt>What was seen</dt><dd><?= e((string) $a['evidence']) ?> <span class="qd-cell-sub"><?= e(Conduct::MENTOR_KINDS[$a['mentor_kind']] ?? 'Mentor') ?>: <?= e((string) $a['mentor_name']) ?>, <?= e((string) $a['occurred_on']) ?></span></dd>
<?php if ((string) $a['response'] !== ''): ?>
                <dt>Their answer then</dt><dd><?= e(Conduct::RESPONSES[(string) $a['response']] ?? '') ?><?= trim((string) $a['response_note']) !== '' ? ' — ' . e((string) $a['response_note']) : '' ?></dd>
<?php else: ?>
                <dt>Their answer then</dt><dd>None given.</dd>
<?php endif; ?>
                <dt>Approved</dt><dd><?= e((string) $a['decider_name']) ?>, <?= e(substr((string) $a['decided_at'], 0, 10)) ?><?= trim((string) $a['decision_note']) !== '' ? ' — ' . e((string) $a['decision_note']) : '' ?></dd>
                <dt>Grounds of appeal</dt><dd><?= e((string) $a['grounds']) ?> <span class="qd-cell-sub">lodged <?= e(substr((string) $a['created_at'], 0, 10)) ?></span></dd>
              </dl>
<?php if ($why !== ''): ?>
              <p class="qd-hint"><?= e($why) ?>, so somebody else hears the appeal.</p>
<?php else: ?>
              <form class="qd-inline qd-appeal" method="post" action="/mentorship/conduct.php" data-qd-ajax data-qd-remove="article">
                <input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="op" value="appeal_hear"><input type="hidden" name="appeal_id" value="<?= (int) $a['id'] ?>">
                <label class="qd-grow"><span class="av-sr">Reason for the decision</span><input type="text" name="note" maxlength="2000" required placeholder="Reason — the member reads it"></label>
                <label class="qd-appeal-to"><span class="av-sr">Reduce to</span><input type="text" name="new_value" inputmode="numeric" placeholder="<?= $isFine ? 'Reduce to ₦' : 'Reduce to points' ?>"></label>
                <button class="avp-btn avp-btn--sq" type="submit" name="outcome" value="upheld">Uphold</button>
                <button class="avp-btn avp-btn--sq" type="submit" name="outcome" value="reduced">Reduce</button>
                <button class="avp-btn avp-btn--ink avp-btn--sq" type="submit" name="outcome" value="overturned">Overturn</button>
              </form>
<?php endif; ?>
            </div>
          </article>
<?php endforeach; ?>
        </div>
<?php endif; ?>

<?php if ($heard): ?>
        <section class="avp-card qd-gap" aria-labelledby="qdAppDoneH">
          <div class="avp-card-head"><h2 id="qdAppDoneH">Heard</h2></div>
          <div class="qd-table-wrap">
            <table class="qd-table qd-table--quiet">
              <caption class="av-sr">Appeals already heard</caption>
              <thead><tr><th scope="col">Member</th><th scope="col">Case</th><th scope="col">Outcome</th><th scope="col">Heard by</th><th scope="col" class="qd-c-num">On</th></tr></thead>
              <tbody>
<?php foreach ($heard as $a): ?>
                <tr><td><?= e((string) $a['mentee_name']) ?></td><td><?= e(Conduct::caseLabel($a)) ?> · <?= e($price($a)) ?></td>
                  <td><span class="avp-chip avp-chip--sm avp-chip--<?= ['upheld' => 'indigo', 'overturned' => 'green', 'reduced' => 'gold'][$a['status']] ?? 'indigo' ?>"><?= e(Conduct::APPEAL_OUTCOMES[$a['status']] ?? $a['status']) ?></span><?= $a['status'] === 'reduced' ? ' to ' . e($a['kind'] === 'fine' ? QuestDesk::naira((int) $a['new_value']) : (int) $a['new_value'] . ' points') : '' ?><?php if (trim((string) $a['hearing_note']) !== ''): ?><span class="qd-cell-sub"><?= e((string) $a['hearing_note']) ?></span><?php endif; ?></td>
                  <td><?= e((string) ($a['hearer_name'] ?? '')) ?></td><td class="qd-c-num av-num"><?= e(substr((string) $a['heard_at'], 0, 10)) ?></td></tr>
<?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </section>
<?php endif; ?>
      </section>
