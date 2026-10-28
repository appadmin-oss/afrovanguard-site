<?php
/**
 * Cases: mentors' awards, deductions, fines and challenge sign-offs waiting
 * for a decision — one at a time or many at once — and the record of what
 * was decided. Nobody decides a case they proposed or one about themselves.
 */
declare(strict_types=1);
$waitingAll = Conduct::queue('proposed', 300);
$decided = array_merge(Conduct::queue('approved', 80), Conduct::queue('rejected', 80));
usort($decided, static fn($a, $b) => strcmp((string) $b['decided_at'], (string) $a['decided_at']));
$what = static function (array $c): string {
    return match ((string) $c['kind']) {
        'fine'      => 'Fine ' . QuestDesk::naira((int) $c['amount']),
        'deduction' => '−' . (int) $c['points'] . ' points',
        'challenge' => 'Challenge · ' . (int) $c['points'] . ' pt',
        default     => '+' . (int) $c['points'] . ' points',
    };
};
?>
      <section class="pview avp-view qd-view" id="view-cases" data-view="cases" hidden aria-labelledby="qdCasesH">
        <div class="avp-view-head qd-head">
          <div><h1 class="avp-h1" id="qdCasesH"><?= e($title) ?></h1>
            <p class="qd-sub">Read what the mentor saw and, for a fine or deduction, the member's answer — then decide. Approving makes it real — the points are granted or taken, the fine is posted — and both people are told. Rejecting needs a reason, which the mentor reads.</p></div>
        </div>

        <form class="qd-filters" role="search" data-qd-filter="qdCaseTable">
          <label class="qd-search"><span class="av-sr">Search cases</span><input type="search" placeholder="Search a name, a deed, a mentor…" data-qd-q></label>
          <label class="qd-select-l"><span class="av-sr">Kind</span>
            <select data-qd-col="kind"><option value="">Every kind</option><option value="award">Awards</option><option value="deduction">Deductions</option><option value="fine">Fines</option><option value="challenge">Challenges</option></select>
          </label>
          <label class="qd-select-l"><span class="av-sr">The member's answer</span>
            <select data-qd-col="answer"><option value="">Any answer</option><option value="ready">Ready to decide</option><option value="contested">Contested</option><option value="none">Not answered</option><option value="self">Self-reported</option></select>
          </label>
          <p class="qd-count" role="status" data-qd-count><?= count($waitingAll) ?> waiting</p>
        </form>

<?php if (!$waitingAll): ?>
        <div class="avp-card"><div class="avp-card-body"><p class="qd-empty">Nothing is waiting. Mentors' proposals arrive here, and the committee is notified.</p></div></div>
<?php else: ?>
        <form class="qd-bulk" method="post" action="/mentorship/conduct.php" data-qd-bulk="qdCaseTable" data-noun="case">
          <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
          <div class="qd-bulkbar" role="region" aria-label="Actions for the selected cases" data-qd-bulkbar hidden>
            <p class="qd-bulk-count" aria-live="polite"><b data-qd-n>0</b> selected</p>
            <label class="qd-bulk-note"><span class="av-sr">Note for the selected cases</span><input type="text" name="note" maxlength="1000" placeholder="Note — required to reject"></label>
            <button class="avp-btn avp-btn--ink avp-btn--sq" type="submit" name="op" value="bulk_approve">Approve</button>
            <button class="avp-btn avp-btn--sq qd-btn-danger" type="submit" name="op" value="bulk_reject">Reject</button>
          </div>
          <div class="qd-table-wrap">
            <label class="qd-pickall"><input type="checkbox" data-qd-all> Select every case shown</label>
            <table class="qd-table qd-table--stack" id="qdCaseTable">
              <caption class="av-sr">Cases waiting for a decision</caption>
              <thead><tr>
                <th scope="col" class="qd-c-check"><label><span class="av-sr">Select every case shown</span><input type="checkbox" data-qd-all></label></th>
                <th scope="col">Member</th><th scope="col">What</th><th scope="col">For</th><th scope="col">From</th><th scope="col" class="qd-c-num">On</th><th scope="col" class="qd-c-act"><span class="av-sr">Decide</span></th>
              </tr></thead>
              <tbody>
<?php foreach ($waitingAll as $c): $self = (int) $c['mentor_id'] === (int) $u['id'] || (int) $c['mentee_id'] === (int) $u['id']; ?>
<?php
    /* Words the answer filter matches: the answer itself, "self", and "ready"
       when nothing — no notice — stands between the committee and approving. */
    $ans = in_array((string) $c['kind'], Conduct::ADVERSE, true) ? ((string) $c['response'] ?: 'none') : '';
    $tags = trim($ans . ($c['mentor_kind'] === 'self' ? ' self' : '') . (Conduct::noticeLeft($c) === '' ? ' ready' : ''));
?>
                <tr data-kind="<?= e((string) $c['kind']) ?>" data-answer="<?= e($tags) ?>" data-id="<?= (int) $c['id'] ?>">
                  <td class="qd-c-check"><?php if (!$self): ?><label><span class="av-sr">Select the case for <?= e((string) $c['mentee_name']) ?></span><input type="checkbox" name="case_ids[]" value="<?= (int) $c['id'] ?>" data-qd-row></label><?php endif; ?></td>
                  <td><b><?= e((string) $c['mentee_name']) ?></b></td>
                  <td><span class="qd-kindtag" data-kind="<?= e((string) $c['kind']) ?>"><?= e($what($c)) ?></span></td>
                  <td><?= e($c['title'] !== '' ? (string) $c['title'] : Conduct::caseLabel($c)) ?>
                    <details class="qd-ev"><summary><?= $c['mentor_kind'] === 'self' ? 'What they reported' : 'What the mentor saw' ?></summary><p><?= e((string) $c['evidence']) ?></p></details>
<?php if (in_array((string) $c['kind'], Conduct::ADVERSE, true)): $left = Conduct::noticeLeft($c); ?>
                    <span class="qd-answer" data-answer="<?= e((string) $c['response'] ?: 'none') ?>"><?= (string) $c['response'] !== ''
                        ? e(Conduct::RESPONSES[(string) $c['response']] ?? '') . ($c['mentor_kind'] === 'self' ? ' — self-reported' : '')
                        : 'No answer yet' . ($left !== '' ? ' — may be approved after ' . e($left) : '') ?></span>
<?php if ((string) $c['response'] === 'contested' && trim((string) $c['response_note']) !== ''): ?>
                    <details class="qd-ev"><summary>Their side</summary><p><?= e((string) $c['response_note']) ?></p></details>
<?php endif; ?>
<?php endif; ?></td>
                  <td><?= e((string) $c['mentor_name']) ?><span class="qd-cell-sub"><?= e(Conduct::MENTOR_KINDS[$c['mentor_kind']] ?? 'mentor') ?></span></td>
                  <td class="qd-c-num av-num"><?= e((string) $c['occurred_on']) ?></td>
                  <td class="qd-c-act">
<?php if ($self): ?>
                    <span class="avp-chip avp-chip--sm">Somebody else decides this</span>
<?php else: ?>
                    <div class="qd-row-actions">
                      <label class="qd-row-note"><span class="av-sr">Reason for case <?= (int) $c['id'] ?></span><input type="text" name="note" form="qdCase<?= (int) $c['id'] ?>" maxlength="1000" placeholder="Reason, to reject"></label>
                      <button class="avp-btn avp-btn--sq" type="submit" form="qdCase<?= (int) $c['id'] ?>" name="op" value="approve" data-qd-one>Approve</button>
                      <button class="avp-btn avp-btn--sq qd-btn-quiet" type="submit" form="qdCase<?= (int) $c['id'] ?>" name="op" value="reject" data-qd-one data-qd-needs-note>Reject</button>
                    </div>
<?php endif; ?>
                  </td>
                </tr>
<?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </form>
<?php foreach ($waitingAll as $c): ?>
        <form id="qdCase<?= (int) $c['id'] ?>" method="post" action="/mentorship/conduct.php" class="qd-hidden-form" data-qd-case>
          <input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="case_id" value="<?= (int) $c['id'] ?>">
        </form>
<?php endforeach; ?>
<?php endif; ?>

        <section class="avp-card qd-gap" aria-labelledby="qdDecidedH">
          <div class="avp-card-head"><h2 id="qdDecidedH">Decided</h2><span class="avp-head-sub">The latest 160</span></div>
<?php if (!$decided): ?>
          <div class="avp-card-body"><p class="qd-empty">Nothing decided yet.</p></div>
<?php else: ?>
          <div class="qd-table-wrap">
            <table class="qd-table qd-table--quiet">
              <caption class="av-sr">Cases already decided</caption>
              <thead><tr><th scope="col">Member</th><th scope="col">What</th><th scope="col">For</th><th scope="col">Decision</th><th scope="col">By</th><th scope="col" class="qd-c-num">On</th></tr></thead>
              <tbody>
<?php foreach ($decided as $c): ?>
                <tr><td><?= e((string) $c['mentee_name']) ?></td><td><?= e($what($c)) ?></td><td><?= e(Conduct::caseLabel($c)) ?><?= (string) ($c['outcome'] ?? '') !== '' && str_contains((string) $c['outcome'], 'ceiling') ? ' <span class="avp-chip avp-chip--sm avp-chip--gold">ceiling</span>' : '' ?></td>
                  <td><span class="avp-chip avp-chip--sm avp-chip--<?= $c['status'] === 'approved' ? 'green' : 'red' ?>"><?= e(ucfirst((string) $c['status'])) ?></span><?php if (trim((string) $c['decision_note']) !== ''): ?><span class="qd-cell-sub"><?= e((string) $c['decision_note']) ?></span><?php endif; ?></td>
                  <td><?= e((string) ($c['decider_name'] ?? '')) ?></td><td class="qd-c-num av-num"><?= e(substr((string) $c['decided_at'], 0, 10)) ?></td></tr>
<?php endforeach; ?>
              </tbody>
            </table>
          </div>
<?php endif; ?>
        </section>
      </section>
