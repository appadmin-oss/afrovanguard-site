<?php
/** One module: lessons, one practice scenario, then the quiz. */
$key = preg_replace('/[^a-z]/', '', (string) ($_GET['key'] ?? ''));
$m   = $portal->academyModule($key);
if (!$m) { echo '<p class="avm-empty">That module does not exist. <a class="avm-link" href="?v=academy">Back to the Academy ›</a></p>'; return; }
$state = $m['state'] ?? ['passed' => false, 'score' => null, 'lapsed' => false];
?>
      <div class="avm-casebar"><a class="avm-link" href="?v=academy">‹ Academy</a></div>

<?php if (isset($_GET['score'])): $score = max(0, min(100, (int) $_GET['score'])); $passed = !empty($_GET['passed']); ?>
      <section class="avm-panel" aria-labelledby="avm-res-h">
        <div class="avm-h2row"><h2 id="avm-res-h" tabindex="-1"><?= $passed ? 'Passed' : 'Not this time' ?> · <span class="av-num"><?= $score ?>%</span></h2></div>
<?php if ($passed): ?>
        <p style="margin:0">Recorded against your certification. <a class="avm-link" href="?v=academy">Back to the Academy ›</a></p>
<?php else: ?>
        <p style="margin:0">The pass mark is <?= MentorPortal::PASS_MARK ?>%. Read the lessons again — the answers are all in them — and try the quiz once more.</p>
        <div><a class="avm-btn avm-btn--ink" href="?v=module&amp;key=<?= e($key) ?>">Try again</a></div>
<?php endif; ?>
      </section>
<?php endif; ?>

      <section class="avm-panel">
        <div class="avm-h2row">
          <h2><?= e($m['title']) ?></h2>
<?php if (!empty($state['passed'])): ?>          <span class="avm-pill avm-st--attended">Passed <?= (int) $state['score'] ?>%</span>
<?php endif; ?>
        </div>
        <p style="margin:0;color:var(--av-muted)"><?= e($m['lead']) ?></p>
      </section>

<?php foreach ($m['lessons'] as $i => $lesson): ?>
      <section class="avm-panel">
        <div class="avm-h2row"><h2><?= $i + 1 ?>. <?= e($lesson['h']) ?></h2></div>
        <p style="margin:0;font-size:15px;line-height:1.65;max-width:68ch;text-wrap:pretty"><?= e($lesson['p']) ?></p>
      </section>
<?php endforeach; ?>

      <section class="avm-panel" data-avm-scenario aria-labelledby="avm-sc-h">
        <div class="avm-h2row"><h2 id="avm-sc-h">Practice</h2></div>
        <p style="margin:0;font-size:15px;line-height:1.6;max-width:68ch"><?= e($m['scenario']['q']) ?></p>
        <div style="display:flex;flex-direction:column;gap:8px">
<?php foreach ($m['scenario']['options'] as $oi => $opt): ?>
          <label class="avm-cats" style="display:block">
            <input type="radio" name="scenario" value="<?= $oi ?>" data-best="<?= $opt['best'] ? '1' : '0' ?>" data-why="<?= e($opt['why']) ?>">
            <span><?= e($opt['t']) ?></span>
          </label>
<?php endforeach; ?>
        </div>
        <p class="avm-offline" data-avm-why hidden role="status"></p>
      </section>

      <form class="avm-panel" method="post" action="/mentorship/api.php?action=quiz" aria-labelledby="avm-q-h">
        <div class="avm-h2row"><h2 id="avm-q-h">Quiz</h2><span style="font-size:13px;color:var(--av-muted)">Pass mark <?= MentorPortal::PASS_MARK ?>%</span></div>
        <input type="hidden" name="key" value="<?= e($m['key']) ?>">
        <input type="hidden" name="csrf" value="<?= e(av_csrf_token()) ?>">
<?php foreach ($m['quiz'] as $qi => $qq): ?>
        <fieldset class="avm-val">
          <legend style="font-size:14px;font-weight:600;padding:0 0 8px"><?= $qi + 1 ?>. <?= e($qq['q']) ?></legend>
          <div style="display:flex;flex-direction:column;gap:8px">
<?php foreach ($qq['a'] as $ai => $ans): ?>
            <label class="avm-cats" style="display:block">
              <input type="radio" name="a[<?= $qi ?>]" value="<?= $ai ?>" required><span><?= e($ans) ?></span>
            </label>
<?php endforeach; ?>
          </div>
        </fieldset>
<?php endforeach; ?>
        <div><button class="avm-btn avm-btn--ink" type="submit">Submit the quiz</button></div>
      </form>
