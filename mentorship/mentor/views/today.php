<?php
/** Today — the four numbers, what is next, and what needs doing. */
$t = $portal->today();
$k = $t['kpi'];
?>
      <div class="avm-kpis">
        <a class="avm-kpi" href="?v=mentees&amp;s=kept">
          <span>Hours logged</span><b class="av-num"><?= e(rtrim(rtrim(number_format($k['hours'], 1), '0'), '.')) ?></b>
          <small>Across every pairing</small>
        </a>
        <a class="avm-kpi" href="?v=mentees&amp;s=kept">
          <span>Sessions kept</span><b class="av-num"><?= $k['kept'] === null ? '—' : (int) $k['kept'] . '%' ?></b>
          <small><?= $k['kept'] === null ? 'Once you have logged a session' : (int) $k['attended'] . ' of ' . (int) $k['held'] . ' attended' ?></small>
        </a>
        <a class="avm-kpi" href="?v=values">
          <span>Values observed</span><b class="av-num"><?= (int) $k['values_done'] ?> / <?= (int) $k['values_total'] ?></b>
          <small>This month</small>
        </a>
        <a class="avm-kpi" href="?v=mentees">
          <span>Mentees</span><b class="av-num"><?= (int) $k['mentees'] ?> / <?= (int) $k['capacity'] ?></b>
          <small><?= $k['mentees'] >= $k['capacity'] ? 'At your capacity' : 'Accepting requests' ?></small>
        </a>
      </div>

      <div class="avm-two">
<?php if ($t['next']): $n = $t['next']; ?>
        <section class="avm-lead" aria-labelledby="avm-next-h">
          <div class="avm-card-h"><h2 id="avm-next-h">Next session</h2><span><?= e($n['relative']) ?></span></div>
          <div class="avm-next">
            <div class="avm-next-who">
              <span class="avm-av avm-av--lg" aria-hidden="true"><?= e($n['initials']) ?></span>
              <span><b><?= e($n['name']) ?></b><small><?= e($n['type']) ?> · <?= e($n['when']) ?> · <?= (int) $n['minutes'] ?> min</small></span>
            </div>
<?php if ($n['agenda'] !== ''): ?>            <p><b>Agenda:</b> <?= e($n['agenda']) ?></p>
<?php endif; ?>
            <div style="display:flex;gap:8px;flex-wrap:wrap">
<?php if ($n['meet_url'] !== ''): ?>              <a class="avm-btn avm-btn--gold" href="<?= e($n['meet_url']) ?>" target="_blank" rel="noopener">Join Google Meet</a>
<?php endif; ?>              <a class="avm-btn avm-btn--ghost-ink" href="?v=case&amp;id=<?= (int) $n['pairing_id'] ?>">Open case file</a>
            </div>
          </div>
        </section>
<?php else: ?>
        <section class="avm-lead" aria-labelledby="avm-next-h">
          <div class="avm-card-h"><h2 id="avm-next-h">Next session</h2><span>Nothing booked</span></div>
          <div class="avm-next">
            <p>Nothing in the diary yet. Schedule one and your mentee sees it in their portal.</p>
            <div><button type="button" class="avm-btn avm-btn--gold" data-avm-open="sched">Schedule a session</button></div>
          </div>
        </section>
<?php endif; ?>

        <section class="avm-card" aria-labelledby="avm-todo-h">
          <div class="avm-card-h"><h2 id="avm-todo-h">Needs you</h2><span class="av-num"><?= (int) $t['todo_total'] ?> to do</span></div>
<?php if (!$t['todo']): ?>
          <p class="avm-empty">Nothing is waiting. Every session is logged, every pairing has goals, and no check-in is due.</p>
<?php else: foreach (array_slice($t['todo'], 0, 8) as $row): ?>
          <a class="avm-todo" href="<?= e($row['href']) ?>">
            <span class="avm-dot avm-dot--<?= e($row['tone']) ?>" aria-hidden="true"></span>
            <span><b><?= e($row['what']) ?></b><?= $row['who'] !== '' ? '<small>' . e($row['who']) . '</small>' : '' ?></span>
            <em><?= e($row['verb']) ?> ›</em>
          </a>
<?php endforeach; endif; ?>
        </section>
      </div>

      <div class="avm-h2row">
        <h2>One-to-ones, longest wait first</h2>
<?php if ($t['wait_total'] > count($t['wait'])): ?>        <a class="avm-link" href="?v=mentees&amp;s=wait">See all <?= (int) $t['wait_total'] ?> ›</a>
<?php endif; ?>
      </div>
<?php if (!$t['wait']): ?>
      <p class="avm-empty">No mentees yet. Members can request you once your profile is published. <a class="avm-link" href="?v=profile">Your profile ›</a></p>
<?php else: ?>
      <div class="avm-wait">
<?php foreach ($t['wait'] as $row): ?>
        <a href="?v=case&amp;id=<?= (int) $row['pairing_id'] ?>">
          <span class="avm-av" aria-hidden="true"><?= e($row['initials']) ?></span>
          <span><b><?= e($row['name']) ?></b>
            <small class="<?= $row['days'] !== null && $row['days'] > 21 ? 'av-late' : ($row['days'] !== null && $row['days'] > 14 ? 'av-due' : '') ?>">
              <?= $row['days'] === null ? 'No session yet' : ($row['days'] === 0 ? 'Met today' : $row['days'] . ' days since you met') ?>
            </small>
          </span>
        </a>
<?php endforeach; ?>
      </div>
<?php endif; ?>

<?php if ($t['note']): $note = $t['note']; ?>
      <div class="avm-note">
        <span class="avm-av" aria-hidden="true"><?= e(mb_substr($portal->coordinatorFirstName(), 0, 1)) ?></span>
        <div>
          <p class="avm-eyebrow">From your coordinator · <?= e($note['when']) ?></p>
          <p><?= e($note['body']) ?></p>
          <div style="display:flex;gap:8px;margin-top:10px">
            <button type="button" class="avm-btn" data-avm-ack="<?= (int) $note['id'] ?>" aria-pressed="<?= $note['acked'] ? 'true' : 'false' ?>"><?= $note['acked'] ? 'Acknowledged ✓' : 'Acknowledge' ?></button>
            <button type="button" class="avm-btn" data-avm-open="support" data-topic="Replying to your note">Reply</button>
          </div>
        </div>
      </div>
<?php endif; ?>
