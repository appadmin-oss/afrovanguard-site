<?php
/**
 * Promotions: who the engine rates ready for their next level — on the
 * Pyramid Manual's floors when that rule is on — and the gaps for everybody
 * else close to it. The engine recommends; a person promotes, one level, on
 * what they can see here.
 */
declare(strict_types=1);
$queue = class_exists('Promotion') ? Promotion::queue() : [];
$ready = array_values(array_filter($queue, static fn($r) => !empty($r['ready'])));
$near = array_values(array_filter($queue, static fn($r) => empty($r['ready'])));
?>
      <section class="pview avp-view qd-view" id="view-promotions" data-view="promotions" hidden aria-labelledby="qdPromH">
        <div class="avp-view-head qd-head">
          <div><h1 class="avp-h1" id="qdPromH"><?= e($title) ?></h1>
            <p class="qd-sub"><?= AvRules::bool('levels.quest_pyramid')
              ? 'On the Pyramid Manual\'s floors: the next level\'s Quest points and its direct mentees actually met, plus the level rules leadership has switched on. What no record can show — the value assessment, the behaviour score, three major projects — is yours to confirm before you promote.'
              : 'On the level rules alone — the Pyramid Manual\'s floors are switched off in Rules.' ?></p></div>
        </div>
<?php if (!$queue): ?>
        <div class="avp-card"><div class="avp-card-body"><p class="qd-empty">Nobody is close to their next level yet.</p></div></div>
<?php endif; ?>
<?php foreach (['Ready' => $ready, 'Close, not yet' => $near] as $head => $rows): if (!$rows) continue; ?>
        <h2 class="qd-h2"><?= e($head) ?> <span class="qd-cell-sub av-num"><?= count($rows) ?></span></h2>
        <div class="qd-grid">
<?php foreach ($rows as $r): $self = (int) $r['user_id'] === (int) $u['id']; $rev = $r['review'] ?? null; ?>
          <article class="avp-card qd-prom" aria-labelledby="qdProm<?= (int) $r['user_id'] ?>">
            <div class="avp-card-head">
              <h3 id="qdProm<?= (int) $r['user_id'] ?>"><?= e((string) $r['name']) ?></h3>
              <span class="qd-ladder"><span class="qd-lvl"><?= e((string) $r['level']) ?></span><span aria-hidden="true">→</span><span class="qd-lvl qd-lvl--next"><?= e((string) $r['next']) ?></span><span class="av-sr">from <?= e(Levels::labelOf((string) $r['level'])) ?> to <?= e(Levels::labelOf((string) $r['next'])) ?></span></span>
            </div>
            <div class="avp-card-body">
<?php if ($r['reasons']): ?>              <ul class="qd-checks"><?php foreach ($r['reasons'] as $x): ?><li class="is-ok"><?= e((string) $x) ?></li><?php endforeach; ?></ul>
<?php endif; ?>
<?php if ($r['gaps']): ?>              <ul class="qd-checks"><?php foreach ($r['gaps'] as $x): ?><li class="is-gap"><?= e((string) $x) ?></li><?php endforeach; ?></ul>
<?php endif; ?>
<?php if ($rev && $rev['status'] === 'deferred'): ?>
              <p class="qd-hint">Set aside by <?= e((string) $rev['decided_by']) ?>: <?= e((string) ($rev['note'] ?? '')) ?></p>
<?php endif; ?>
<?php if (!empty($r['ready']) && !$self): ?>
              <form class="qd-inline" method="post" action="/mentorship/conduct.php" data-qd-ajax data-qd-remove="article">
                <input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="user_id" value="<?= (int) $r['user_id'] ?>">
                <input type="hidden" name="to" value="<?= e((string) $r['next']) ?>">
                <label class="qd-grow"><span class="av-sr">Note</span><input type="text" name="note" maxlength="600" placeholder="Note — required to set aside"></label>
                <button class="avp-btn avp-btn--ink avp-btn--sq" type="submit" name="op" value="promote">Promote to <?= e((string) $r['next']) ?></button>
                <button class="avp-btn avp-btn--sq" type="submit" name="op" value="defer"<?= $rev ? '' : ' disabled title="Set aside once the engine has written its review"' ?>>Set aside</button>
              </form>
<?php elseif ($self): ?>
              <p class="qd-hint">This is about you, so somebody else decides it.</p>
<?php endif; ?>
            </div>
          </article>
<?php endforeach; ?>
        </div>
<?php endforeach; ?>
      </section>
