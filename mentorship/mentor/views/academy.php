<?php
/** Certification: what you must pass, what helps, and where you are. */
$a = $portal->academy();
$pct = $a['required'] > 0 ? (int) round(100 * $a['done'] / $a['required']) : 0;
?>
      <section class="avm-panel" aria-labelledby="avm-ac-h">
        <div class="avm-h2row">
          <h2 id="avm-ac-h">Certification</h2>
          <span style="font-size:13px;color:var(--av-muted)"><span class="av-num"><?= (int) $a['done'] ?></span> of <span class="av-num"><?= (int) $a['required'] ?></span> required · pass mark <?= (int) $a['pass_mark'] ?>%</span>
        </div>
        <span class="avm-progress"><span style="width:<?= $pct ?>%"></span></span>
<?php if (!$a['safeguarding_ok']): ?>
        <p class="avm-danger">Your safeguarding module has lapsed, so you cannot accept new mentees until you retake it. The people you already mentor are unaffected.</p>
<?php endif; ?>
      </section>

<?php if ($a['next']): $n = $a['next']; ?>
      <section class="avm-card avm-next" aria-labelledby="avm-up-h">
        <h2 id="avm-up-h" class="avm-eyebrow" style="color:var(--av-gold-light)">Up next</h2>
        <div class="avm-next-who"><span><b><?= e($n['title']) ?></b><small><?= $n['lapsed'] ? 'Lapsed — retake it' : 'Not taken yet' ?></small></span></div>
        <div><a class="avm-btn avm-btn--gold" href="?v=module&amp;key=<?= e($n['key']) ?>">Start</a></div>
      </section>
<?php endif; ?>

<?php foreach ([true => 'Required', false => 'Optional'] as $req => $heading): ?>
      <div class="avm-h2row"><h2><?= $heading ?></h2></div>
      <section class="avm-card">
<?php foreach ($a['modules'] as $m): if ($m['required'] !== (bool) $req) continue; ?>
        <a class="avm-todo" href="?v=module&amp;key=<?= e($m['key']) ?>">
          <span class="avm-dot avm-dot--<?= $m['passed'] ? 'green' : ($m['lapsed'] ? 'red' : 'gold') ?>" aria-hidden="true"></span>
          <span><b><?= e($m['title']) ?></b>
            <small><?= $m['passed'] ? 'Passed ' . (int) $m['score'] . '% · ' . e($m['when']) : ($m['lapsed'] ? 'Lapsed · retake it' : 'Not taken yet') ?></small></span>
          <em><?= $m['passed'] ? 'Review' : 'Start' ?></em>
        </a>
<?php endforeach; ?>
      </section>
<?php endforeach; ?>

      <div class="avm-h2row"><h2>Screening</h2></div>
      <section class="avm-card">
<?php foreach ([
    ['Identity checked', 'Done when your account was approved'],
    ['References', 'Held by the coordinator'],
    ['Safeguarding declaration', 'Renewed with the safeguarding module'],
] as $row): ?>
        <div class="avm-todo" style="cursor:default">
          <span class="avm-dot avm-dot--green" aria-hidden="true"></span>
          <span><b><?= e($row[0]) ?></b><small><?= e($row[1]) ?></small></span>
          <em></em>
        </div>
<?php endforeach; ?>
      </section>
