<?php
/**
 * Members: everybody in the Quest — everybody — with their level, their
 * journey, their points, the direct mentees they actually meet, any finding
 * standing against them, and their income bracket. One panel to move the
 * journey, place a bracket, and record or resolve a finding.
 *
 * Without script, "Manage" reloads with ?member=<id> and the panel is drawn
 * open for that member; with script, desk.js fills the same panel in place.
 */
declare(strict_types=1);
$roster = QuestDesk::roster();
$brackets = QuestMoney::brackets();
$journeyLevels = Quest::journey();
$focusId = (int) ($_GET['member'] ?? 0);
$focus = null;
foreach ($roster as $m) if ($m['id'] === $focusId) $focus = $m;
$focusFindings = $focus ? QuestRecord::findings($focus['id']) : [];
/* Open findings for the panel — one query for everybody, not one per member. */
$openFindings = [];
try {
    foreach (Database::pdo()->query("SELECT id, member_id, kind, raised_at FROM quest_findings WHERE status = 'open' ORDER BY id DESC") ?: [] as $f) {
        $openFindings[(int) $f['member_id']][] = ['id' => (int) $f['id'], 'kind' => QuestRecord::FINDING_KINDS[(string) $f['kind']] ?? 'Finding', 'at' => substr((string) $f['raised_at'], 0, 10)];
    }
} catch (Throwable $e) { /* none */ }
$levels = Levels::order();
?>
      <section class="pview avp-view qd-view" id="view-members" data-view="members" hidden aria-labelledby="qdMembersH">
        <div class="avp-view-head qd-head">
          <div><h1 class="avp-h1" id="qdMembersH"><?= e($title) ?></h1>
            <p class="qd-sub">Everybody is a part of the Quest from the first day. "Qualifies" is arithmetic on points and mentees met; the level they hold is a decision, made in Promotions.</p></div>
        </div>

        <form class="qd-filters" role="search" data-qd-filter="qdMemberTable">
          <label class="qd-search"><span class="av-sr">Search members</span><input type="search" placeholder="Search a name or email…" data-qd-q></label>
          <label class="qd-select-l"><span class="av-sr">Level</span>
            <select data-qd-col="level"><option value="">Every level</option><?php foreach ($levels as $l): ?><option value="<?= e($l) ?>"><?= e($l . ' · ' . Levels::labelOf($l)) ?></option><?php endforeach; ?></select></label>
          <label class="qd-select-l"><span class="av-sr">Show</span>
            <select data-qd-col="flag"><option value="">Everybody</option><option value="finding">A finding stands</option><option value="behind">Qualifies above their level</option><option value="nobracket">No income bracket</option></select></label>
          <p class="qd-count" role="status" data-qd-count><?= count($roster) ?> members</p>
        </form>

<?php if (!$roster): ?>
        <div class="avp-card"><div class="avp-card-body"><p class="qd-empty">No members yet.</p></div></div>
<?php else: ?>
        <div class="qd-table-wrap">
          <table class="qd-table qd-table--stack" id="qdMemberTable">
            <caption class="av-sr">Members of the Quest</caption>
            <thead><tr><th scope="col">Member</th><th scope="col">Level</th><th scope="col">Journey</th><th scope="col" class="qd-c-num">Points</th><th scope="col" class="qd-c-num">This month</th><th scope="col" class="qd-c-num">Mentees</th><th scope="col">Bracket</th><th scope="col" class="qd-c-act"><span class="av-sr">Manage</span></th></tr></thead>
            <tbody>
<?php foreach ($roster as $m):
    $behind = array_search($m['qualifies'], $levels, true) > array_search($m['level'], $levels, true);
    $flags = trim(($m['findings'] ? 'finding ' : '') . ($behind ? 'behind ' : '') . ($m['bracket'] === '' ? 'nobracket' : '')); ?>
              <tr data-level="<?= e($m['level']) ?>" data-flag="<?= e($flags) ?>"
                  data-member='<?= e((string) json_encode(['id' => $m['id'], 'name' => $m['name'], 'journey' => $m['journey_id'], 'bracket' => $m['bracket'], 'findings' => $openFindings[$m['id']] ?? []], JSON_HEX_APOS | JSON_HEX_QUOT)) ?>'>
                <td><b><?= e($m['name']) ?></b><span class="qd-cell-sub"><?= e($m['email']) ?></span></td>
                <td><span class="qd-lvl" title="<?= e($m['level_label']) ?>"><?= e($m['level']) ?></span><?php if ($behind): ?> <span class="avp-chip avp-chip--sm avp-chip--indigo" title="Points and mentees met qualify for <?= e($m['qualifies']) ?>">→ <?= e($m['qualifies']) ?></span><?php endif; ?></td>
                <td><?= e($m['journey']) ?></td>
                <td class="qd-c-num av-num"><?= number_format($m['points']) ?></td>
                <td class="qd-c-num av-num"><?= number_format($m['month']) ?></td>
                <td class="qd-c-num av-num"><?= (int) $m['mentees'] ?></td>
                <td><?= $m['bracket'] !== '' ? e($m['bracket']) : '<span class="qd-cell-sub">—</span>' ?><?php if ($m['findings']): ?> <span class="avp-chip avp-chip--sm avp-chip--red"><?= (int) $m['findings'] ?> finding<?= $m['findings'] > 1 ? 's' : '' ?></span><?php endif; ?></td>
                <td class="qd-c-act"><a class="avp-btn avp-btn--sq" href="?member=<?= (int) $m['id'] ?>#members" data-qd-manage>Manage</a></td>
              </tr>
<?php endforeach; ?>
            </tbody>
          </table>
        </div>
<?php endif; ?>

        <aside class="qd-drawer" id="qdMemberPanel" aria-labelledby="qdPanelH" <?= $focus ? '' : 'hidden' ?> data-qd-drawer>
          <div class="qd-drawer-head">
            <h2 id="qdPanelH" data-qd-panel-name><?= e($focus['name'] ?? 'Member') ?></h2>
            <a class="avp-icon-btn" href="#members" data-qd-close aria-label="Close">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18"/></svg></a>
          </div>
          <div class="qd-drawer-body">
            <form class="qd-form" method="post" action="/mentorship/conduct.php" data-qd-ajax>
              <h3>Journey level</h3>
              <input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="op" value="journey"><input type="hidden" name="member_id" value="<?= (int) ($focus['id'] ?? 0) ?>" data-qd-mid>
              <label>Where they stand
                <select name="level" data-qd-journey><?php foreach ($journeyLevels as $j): ?><option value="<?= e((string) $j['id']) ?>"<?= ($focus['journey_id'] ?? '') === $j['id'] ? ' selected' : '' ?>><?= (int) $j['order'] ?> · <?= e((string) $j['name']) ?></option><?php endforeach; ?></select></label>
              <label>Why <input type="text" name="note" maxlength="200" placeholder="What you have seen"></label>
              <button class="avp-btn avp-btn--sq" type="submit">Save the journey</button>
            </form>

            <form class="qd-form" method="post" action="/mentorship/conduct.php" data-qd-ajax>
              <h3>Income bracket</h3>
              <input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="op" value="member_bracket"><input type="hidden" name="member_id" value="<?= (int) ($focus['id'] ?? 0) ?>" data-qd-mid>
<?php if (!$brackets): ?>
              <p class="qd-hint">No brackets yet — set them in Money.</p>
<?php else: ?>
              <label>Bracket
                <select name="bracket_id" data-qd-bracket><option value="0">None</option><?php foreach ($brackets as $b): ?><option value="<?= (int) $b['id'] ?>" data-label="<?= e((string) $b['label']) ?>"<?= ($focus['bracket'] ?? '') === $b['label'] ? ' selected' : '' ?>><?= e((string) $b['label']) ?></option><?php endforeach; ?></select></label>
              <button class="avp-btn avp-btn--sq" type="submit">Save the bracket</button>
<?php endif; ?>
            </form>

            <div class="qd-form">
              <h3>Integrity findings</h3>
              <p class="qd-hint">A finding is a gate, not a deduction: while it stands no quantity of points promotes. It stays on the record once resolved.</p>
              <ul class="qd-list qd-list--plain" data-qd-findings>
<?php foreach ($focusFindings as $f): if ($f['status'] !== 'open') continue; ?>
                <li><span class="qd-list-main"><?= e(QuestRecord::FINDING_KINDS[(string) $f['kind']] ?? 'Finding') ?> · <?= e(substr((string) $f['raised_at'], 0, 10)) ?></span>
                  <form class="qd-inline" method="post" action="/mentorship/conduct.php" data-qd-ajax data-qd-remove="li">
                    <input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="op" value="finding_resolve"><input type="hidden" name="id" value="<?= (int) $f['id'] ?>">
                    <label class="qd-grow"><span class="av-sr">What changed</span><input type="text" name="note" required placeholder="What changed"></label>
                    <button class="avp-btn avp-btn--sq" type="submit">Resolve</button>
                  </form></li>
<?php endforeach; ?>
              </ul>
              <form method="post" action="/mentorship/conduct.php" data-qd-ajax class="qd-form qd-form--flat">
                <input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="op" value="finding_raise"><input type="hidden" name="member_id" value="<?= (int) ($focus['id'] ?? 0) ?>" data-qd-mid>
                <label>Kind <select name="finding_kind"><?php foreach (QuestRecord::FINDING_KINDS as $k => $l): ?><option value="<?= e($k) ?>"><?= e($l) ?></option><?php endforeach; ?></select></label>
                <label>What happened <textarea name="detail" rows="3" minlength="30" required placeholder="What was done, when, and how it is known"></textarea></label>
                <button class="avp-btn avp-btn--sq qd-btn-danger" type="submit">Record a finding</button>
              </form>
            </div>
          </div>
        </aside>
        <template id="qdFindingRow">
          <li><span class="qd-list-main" data-t></span>
            <form class="qd-inline" method="post" action="/mentorship/conduct.php" data-qd-ajax data-qd-remove="li">
              <input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="op" value="finding_resolve"><input type="hidden" name="id" value="" data-id>
              <label class="qd-grow"><span class="av-sr">What changed</span><input type="text" name="note" required placeholder="What changed"></label>
              <button class="avp-btn avp-btn--sq" type="submit">Resolve</button>
            </form></li>
        </template>
      </section>
