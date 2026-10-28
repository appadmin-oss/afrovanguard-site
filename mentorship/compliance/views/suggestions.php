<?php
/**
 * Suggestions: what the Pyramid Manual calls for and the machine will not do
 * by itself — a level lost to inactivity, a week's suspension and a fine for
 * an unsettled balance. A person accepts or dismisses each, with a reason.
 */
declare(strict_types=1);
$open = QuestLadder::queue('open');
$closed = array_slice(QuestLadder::queue('all', 120), 0, 120);
$closed = array_values(array_filter($closed, static fn($s) => $s['status'] !== 'open'));
$kindTone = ['demotion' => 'red', 'suspension' => 'gold', 'fine' => 'gold'];
?>
      <section class="pview avp-view qd-view" id="view-suggestions" data-view="suggestions" hidden aria-labelledby="qdSugH">
        <div class="avp-view-head qd-head">
          <div><h1 class="avp-h1" id="qdSugH"><?= e($title) ?></h1>
            <p class="qd-sub">The manual's "loss of current level" for 30 days' inactivity, and its suspension and ₦<?= number_format(AvRules::int('quest.money_overdue_fine')) ?> fine for a balance left unpaid, arrive here as suggestions. Accepting acts — one level down, a fine posted, a suspension recorded for its month. Dismissing needs a reason and stays on the record.</p></div>
        </div>
<?php if (!$open): ?>
        <div class="avp-card"><div class="avp-card-body"><p class="qd-empty">Nothing suggested. The inactivity check runs once a day<?= AvRules::bool('quest.demotion_suggestions') ? '' : ' — and is switched off' ?>.</p></div></div>
<?php else: ?>
        <div class="qd-stack">
<?php foreach ($open as $s): $self = (int) $s['member_id'] === (int) $u['id']; ?>
          <article class="avp-card qd-sug" aria-labelledby="qdSug<?= (int) $s['id'] ?>">
            <div class="avp-card-head">
              <h2 id="qdSug<?= (int) $s['id'] ?>"><?= e((string) $s['member_name']) ?> <span class="qd-cell-sub"><?= e(Levels::labelOf((string) ($s['member_level'] ?: Levels::base()))) ?></span></h2>
              <span class="avp-chip avp-chip--sm avp-chip--<?= e($kindTone[$s['kind']] ?? 'indigo') ?>"><?= e(QuestLadder::KINDS[$s['kind']] ?? $s['kind']) ?><?= $s['kind'] === 'fine' ? ' · ' . QuestDesk::naira((int) $s['amount']) : '' ?></span>
            </div>
            <div class="avp-card-body">
              <p class="avp-note"><?= e((string) $s['reason']) ?></p>
<?php if ($self): ?>
              <p class="qd-hint">This is about you, so somebody else decides it.</p>
<?php else: ?>
              <form class="qd-inline" method="post" action="/mentorship/conduct.php" data-qd-ajax data-qd-remove="article">
                <input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
                <label class="qd-grow"><span class="av-sr">Note</span><input type="text" name="note" maxlength="2000" placeholder="Note — required to dismiss"></label>
                <button class="avp-btn avp-btn--ink avp-btn--sq" type="submit" name="op" value="suggest_accept"><?= $s['kind'] === 'demotion' ? 'Move down one level' : ($s['kind'] === 'fine' ? 'Post the fine' : 'Suspend for a week') ?></button>
                <button class="avp-btn avp-btn--sq" type="submit" name="op" value="suggest_dismiss">Dismiss</button>
              </form>
<?php endif; ?>
            </div>
          </article>
<?php endforeach; ?>
        </div>
<?php endif; ?>

<?php if ($closed): ?>
        <section class="avp-card qd-gap" aria-labelledby="qdSugDoneH">
          <div class="avp-card-head"><h2 id="qdSugDoneH">Decided</h2></div>
          <div class="qd-table-wrap">
            <table class="qd-table qd-table--quiet">
              <caption class="av-sr">Suggestions already decided</caption>
              <thead><tr><th scope="col">Member</th><th scope="col">Suggested</th><th scope="col">Decision</th><th scope="col">By</th><th scope="col" class="qd-c-num">On</th></tr></thead>
              <tbody>
<?php foreach ($closed as $s): ?>
                <tr><td><?= e((string) $s['member_name']) ?></td><td><?= e(QuestLadder::KINDS[$s['kind']] ?? $s['kind']) ?></td>
                  <td><span class="avp-chip avp-chip--sm avp-chip--<?= $s['status'] === 'accepted' ? 'green' : 'indigo' ?>"><?= e(ucfirst((string) $s['status'])) ?></span><?php if (trim((string) $s['note']) !== ''): ?><span class="qd-cell-sub"><?= e((string) $s['note']) ?></span><?php endif; ?></td>
                  <td><?= e((string) ($s['decided_by_name'] ?? '')) ?></td><td class="qd-c-num av-num"><?= e(substr((string) $s['decided_at'], 0, 10)) ?></td></tr>
<?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </section>
<?php endif; ?>
      </section>
