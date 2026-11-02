<?php
/**
 * academy/ngv/_reading-review.php — the reading challenge's review desk.
 *
 * The three things a person has to DO for the 24-book challenge to mean
 * anything: read the claims, reopen a rejection that was wrong, and have the
 * spoken conversations. They are in one partial because they are reachable
 * from two places now — the NGV console (academy/ngv/members.php) and a page
 * of their own (academy/ngv/reading-review.php) that a track lead can be
 * given without the roster, the fines desk and the ledger coming with it.
 *
 * Expects from its host: $e (an escaper) and a `#msg` status strip. Its
 * script is self-contained and posts `book_review` / `book_spot` back to
 * whichever page included it — both answer them. The caller has already
 * established that the viewer may review (NgvReading::canReview).
 */
declare(strict_types=1);
?>
  <?php /* ── How the challenge is going ─────────────────────────────────
       A reading challenge is a thing a programme MANAGES, and the only way
       to ask "how is it going" used to be to open the console and count
       tiles. Who has stalled, who has not begun, what has waited longest,
       and how wide each person's reading is across the seven values. */
  $rvCo = NgvReading::cohort();
  $rvPeople = $rvCo['people'];
  usort($rvPeople, static function ($a, $b) {
      if ($a['stalled'] !== $b['stalled']) return $a['stalled'] ? -1 : 1;
      return $b['verified'] <=> $a['verified'];
  });
  $rvNever = array_values(array_filter($rvPeople, static fn($p) => $p['sessions'] === 0 && $p['verified'] === 0));
  ?>
  <section class="card" id="readingstate" aria-labelledby="h-rstate">
    <header><h2 id="h-rstate">How the challenge is going</h2><span class="sp"></span>
      <span class="sub"><?= count($rvPeople) ?> active vanguard<?= count($rvPeople) === 1 ? '' : 's' ?>
        · <?= (int) $rvCo['verified'] ?> books verified
        · <?= number_format(((int) $rvCo['minutes']) / 60, 1) ?> hours logged</span></header>
    <div class="body">
      <p class="sub" style="margin-top:0">
        <?php if ((int) $rvCo['stalled']): ?><strong><?= (int) $rvCo['stalled'] ?></strong> started and <?= (int) $rvCo['stalled'] === 1 ? 'has' : 'have' ?> not logged a sitting in four weeks.<?php endif; ?>
        <?php if ($rvNever): ?> <strong><?= count($rvNever) ?></strong> <?= count($rvNever) === 1 ? 'has' : 'have' ?> not begun at all — a different conversation.<?php endif; ?>
        <?php if ((int) $rvCo['oldest_days'] > 7): ?> The claim that has waited longest has been waiting <strong><?= (int) $rvCo['oldest_days'] ?> days</strong>.<?php endif; ?>
        <?php if (!(int) $rvCo['stalled'] && !$rvNever): ?>Everybody who has started is still reading.<?php endif; ?>
      </p>
      <div class="scroll">
        <table>
          <thead><tr><th>Vanguard</th><th>Track</th><th class="num">Verified</th><th class="num">Waiting</th><th class="num">To put right</th><th class="num">Sittings</th><th class="num">Hours</th><th>Last read</th></tr></thead>
          <tbody>
          <?php foreach (array_slice($rvPeople, 0, 60) as $p): ?>
            <tr<?= $p['stalled'] ? ' class="bkq--flagged"' : '' ?>>
              <td><strong><?= $e((string) $p['name']) ?></strong></td>
              <td class="sub"><?= $e((string) $p['track']) ?></td>
              <td class="num"><?= (int) $p['verified'] ?> / <?= (int) NgvReading::TOTAL ?></td>
              <td class="num"><?= (int) $p['waiting'] ?: '—' ?></td>
              <td class="num"><?= (int) $p['needs_work'] ?: '—' ?></td>
              <td class="num"><?= (int) $p['sessions'] ?: '—' ?></td>
              <td class="num"><?= $p['minutes'] ? number_format($p['minutes'] / 60, 1) : '—' ?></td>
              <td class="sub"><?= $p['last_read'] === '' ? 'never' : $e((string) $p['last_read']) . ($p['stalled'] ? ' · ' . (int) $p['stalled_days'] . ' days ago' : '') ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <?php if (count($rvPeople) > 60): ?><p class="sub">Showing 60 of <?= count($rvPeople) ?>, stalled first.</p><?php endif; ?>
      </div>
      <p class="sub" style="margin-bottom:0">A vanguard with claims waiting has done their part; the delay is this desk's.
        <b>Last read</b> is the day of their most recent sitting, which is the earliest sign somebody has drifted.</p>
    </div>
  </section>

  <?php /* ── Book claims awaiting a human ──────────────────────────────────
       This queue is the whole verification story. Everything else — the
       substance floor, the duplicate check, the pacing flags — only narrows
       what lands here and puts the doubtful ones first. Nothing counts toward
       a participant's twenty-four until somebody on this page says so. */
  $bkQueue = NgvReading::queue(40); ?>
  <section class="card" id="bookclaims" aria-labelledby="h-books">
    <header><h2 id="h-books">Books to check</h2> <span class="sp"></span>
      <span class="sub"><?= count($bkQueue) ?> waiting<?php
        $bkFlagged = 0; foreach ($bkQueue as $bq) if ($bq['flags_list']) $bkFlagged++;
        if ($bkFlagged): ?> · <strong><?= $bkFlagged ?> flagged</strong><?php endif; ?></span></header>
    <div class="body">
      <?php if (!$bkQueue): ?>
        <p class="sub">Nothing waiting. Claims appear here the moment a participant sends one.</p>
      <?php else: ?>
        <p class="sub" style="margin-bottom:14px">
          A flag is a reason to look, never a verdict — approve a flagged claim if the writing is real.
          The browser signals (pasted, typed fast) can be faked by anyone who opens developer tools,
          so weigh the writing, not the badge. The one thing software cannot catch is somebody who read
          a summary and wrote well about it: that is what the takeaway is for, and why asking them about
          it out loud is worth more than anything on this page.
        </p>
        <?php foreach ($bkQueue as $bc): /* the name rides on the row (NgvReading::queue) */ ?>
          <article class="bkq<?= $bc['flags_list'] ? ' bkq--flagged' : '' ?>" data-claim="<?= (int) $bc['id'] ?>">
            <div class="bkq-head">
              <div>
                <div class="bkq-who"><?= $e((string) ($bc['member_name'] ?? '') !== '' ? (string) $bc['member_name'] : 'Member #' . (int) $bc['member_id']) ?>
                  <span class="sub">· book <?= (int) $bc['slot'] ?> of 24</span></div>
                <div class="bkq-book"><strong><?= $e((string) $bc['title']) ?></strong>
                  <span class="sub">by <?= $e((string) $bc['author']) ?> ·
                  read <?= $e((string) $bc['started_on']) ?> → <?= $e((string) $bc['finished_on']) ?> ·
                  <?= (int) $bc['reflection_len'] ?> characters</span></div>
              </div>
            </div>
            <?php if ($bc['flags_list']): ?>
              <ul class="bkq-flags">
                <?php foreach ($bc['flags_list'] as $fl): ?>
                  <li><?= $e(NgvReading::flagLabel((string) $fl)) ?></li>
                <?php endforeach; ?>
              </ul>
            <?php endif; ?>
            <?php /* THE SITTINGS, before the writing. A claim backed by eight
                     dated sittings is a different thing from one backed by
                     none, and the dates, the minutes and the values they
                     named are the part a reviewer can weigh fastest. */
              $bcSits = (array) ($bc['sessions'] ?? []); ?>
            <div class="bkq-sits">
              <?php if (!$bcSits): ?>
                <span class="bkq-sits-none">No reading logged against this book.</span>
              <?php else: ?>
                <strong><?= count($bcSits) ?> sitting<?= count($bcSits) === 1 ? '' : 's' ?></strong>
                · <?= (int) ($bc['session_minutes'] ?? 0) ?> minutes
                · <?= $e((string) $bcSits[0]['day']) ?> → <?= $e((string) $bcSits[count($bcSits) - 1]['day']) ?>
              <?php endif; ?>
            </div>
            <?php if ($bcSits): ?>
            <details class="bkq-read">
              <summary>Their reading, sitting by sitting</summary>
              <ol class="bkq-chapters">
                <?php foreach ($bcSits as $bs): ?>
                  <li>
                    <strong><?= $e((string) $bs['day']) ?></strong>
                    <span class="sub"><?= $e(trim((string) $bs['covered'] . ' · ' . (int) $bs['minutes'] . ' min · ' . (string) $bs['place'], ' ·')) ?><?= (string) $bs['mood_label'] !== '' ? ' · ' . $e((string) $bs['mood_label']) : '' ?></span>
                    <div class="bkq-text"><?= nl2br($e((string) $bs['notes'])) ?></div>
                    <p class="bkq-take"><strong>Where they stand:</strong> <?= nl2br($e((string) $bs['stance'])) ?></p>
                    <p class="bkq-take"><strong>What they will do:</strong> <?= nl2br($e((string) $bs['application'])) ?></p>
                  </li>
                <?php endforeach; ?>
              </ol>
            </details>
            <?php endif; ?>
            <details class="bkq-read">
              <summary>Read what they wrote<?= $bc['chapter_notes_list'] ? ' — ' . count($bc['chapter_notes_list']) . ' chapter' . (count($bc['chapter_notes_list']) === 1 ? '' : 's') . ' and the whole book' : '' ?></summary>
              <?php if ($bc['chapter_notes_list']): ?>
                <ol class="bkq-chapters">
                  <?php foreach ($bc['chapter_notes_list'] as $cn): ?>
                    <li><?= nl2br($e((string) $cn)) ?></li>
                  <?php endforeach; ?>
                </ol>
                <p class="bkq-take"><strong>The whole book:</strong></p>
              <?php endif; ?>
              <div class="bkq-text"><?= nl2br($e((string) $bc['reflection'])) ?></div>
              <p class="bkq-take"><strong>What they did with it:</strong>
                <?= nl2br($e((string) $bc['takeaway'])) ?></p>
            </details>
            <div class="bkq-act">
              <input class="bkq-note" maxlength="1000"
                     placeholder="Why — required to send back or reject">
              <button class="btn ok bkq-btn" data-verdict="verified">Verify book</button>
              <button class="btn bkq-btn" data-verdict="resubmit">Send back to rewrite</button>
              <button class="btn danger bkq-btn" data-verdict="rejected" data-confirm="Reject this claim?&#10;&#10;It closes: they cannot edit it or send it again. Use “Send back to rewrite” if you want them to fix it. A rejection can be reopened later from “Not accepted”.">Reject claim</button>
            </div>
          </article>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </section>

  <?php /* Not accepted. A rejection closes the claim to the member — that is
           what makes it a different decision from "send back to rewrite",
           which used to be the same thing in effect. So the door back is
           here, through a person, with a reason, on the record. */
  $bkNo = NgvReading::rejected(15);
  if ($bkNo): ?>
  <section class="card" id="bookrejected" aria-labelledby="h-booksno">
    <header><h2 id="h-booksno">Not accepted</h2> <span class="sp"></span>
      <span class="sub"><?= count($bkNo) ?> closed claim<?= count($bkNo) === 1 ? '' : 's' ?></span></header>
    <div class="body">
      <p class="sub" style="margin-bottom:14px">
        A rejected claim is closed: the participant cannot edit it or send it again. If it was rejected
        in error, or they have made their case, reopen it and say why — they can then rewrite it.
      </p>
      <?php foreach ($bkNo as $bc): ?>
        <article class="bkq" data-claim="<?= (int) $bc['id'] ?>">
          <div class="bkq-head"><div>
            <div class="bkq-who"><?= $e((string) ($bc['member_name'] ?? '') !== '' ? (string) $bc['member_name'] : 'Member #' . (int) $bc['member_id']) ?>
              <span class="sub">· book <?= (int) $bc['slot'] ?> of 24</span></div>
            <div class="bkq-book"><strong><?= $e((string) $bc['title']) ?></strong>
              <span class="sub">rejected <?= $e(substr((string) $bc['reviewed_at'], 0, 10)) ?></span></div>
          </div></div>
          <?php if (trim((string) $bc['review_note']) !== ''): ?>
            <p class="bkq-take"><strong>Why:</strong> <?= $e((string) $bc['review_note']) ?></p>
          <?php endif; ?>
          <details class="bkq-read"><summary>Read what they wrote</summary>
            <div class="bkq-text"><?= nl2br($e((string) $bc['reflection'])) ?></div>
          </details>
          <div class="bkq-act">
            <input class="bkq-note" maxlength="1000" placeholder="Why are you reopening it — required">
            <button class="btn bkq-btn" data-verdict="resubmit">Reopen for rewriting</button>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
  </section>
  <?php endif; ?>

  <?php /* The spoken check. Everything above this point can be gamed by
           somebody who reads well and did not read the book — this is the part
           that cannot, and it is the part that needs a human to actually do
           it. The book was chosen at random when the check opened and the
           participant has not been told which one. */
  $spQueue = NgvReading::spotQueue(40); ?>
  <section class="card" id="spotchecks" aria-labelledby="h-spot">
    <header><h2 id="h-spot">Books to ask about</h2> <span class="sp"></span>
      <span class="sub"><?= count($spQueue) ?> conversation<?= count($spQueue) === 1 ? '' : 's' ?> due</span></header>
    <div class="body">
      <?php if (!$spQueue): ?>
        <p class="sub">Nothing due. One of these opens each time a participant reaches another six verified books.</p>
      <?php else: ?>
        <p class="sub" style="margin-bottom:14px">
          Two minutes, in person or on a call. This is the only check that reaches somebody who read a
          summary and wrote well about it — no amount of software does. The book was picked at random
          and <strong>they have not been told which one</strong>, so please do not tell them in advance.
        </p>
        <?php foreach ($spQueue as $sp): ?>
          <article class="spq" data-spot="<?= (int) $sp['id'] ?>">
            <div class="spq-who"><?= $e((string) ($sp['member_name'] ?? ('Member #' . (int) $sp['member_id']))) ?>
              <span class="sub">· reached <?= (int) $sp['milestone'] ?> books</span></div>
            <p class="spq-ask">Ask them about <strong><?= $e((string) ($sp['title'] ?? 'their book')) ?></strong><?php
              if (trim((string) ($sp['author'] ?? '')) !== ''): ?> by <?= $e((string) $sp['author']) ?><?php endif; ?>.</p>
            <ul class="spq-prompts">
              <li>What was the argument, in their own words?</li>
              <li>They wrote that they changed something because of it — how has that gone since?</li>
              <li>What did they disagree with, or find weak?</li>
            </ul>
            <?php if (trim((string) ($sp['takeaway'] ?? '')) !== ''): ?>
              <details class="bkq-read">
                <summary>What they wrote they would change</summary>
                <p class="bkq-take"><?= nl2br($e((string) $sp['takeaway'])) ?></p>
              </details>
            <?php endif; ?>
            <div class="spq-act">
              <input class="spq-note" maxlength="1000"
                     placeholder="How it went — required if they could not answer">
              <button class="btn ok spq-btn" data-outcome="passed">They knew it</button>
              <button class="btn danger spq-btn" data-outcome="failed">They could not say</button>
            </div>
          </article>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </section>

<script>
(function(){
  /* SELF-CONTAINED. The desk is included by two pages whose own scripts are
     IIFEs further down the document, so it can borrow neither their post()
     nor their load order. It posts to its own page's path — both hosts
     answer book_review and book_spot there — and writes to the #msg strip
     both hosts have, falling back to an alert if a third host ever has not. */
  var CSRF = <?= json_encode(av_csrf_token()) ?>;
  var msg = document.getElementById('msg'), mt;
  function toast(t, good){
    if (!msg) { if (good === false) window.alert(t); return; }
    msg.textContent = t; msg.style.color = good === false ? '#ffb4ab' : '#9be7b4';
    clearTimeout(mt); mt = setTimeout(function(){ msg.textContent = ''; }, 4000);
  }
  function post(body){
    return fetch(location.pathname + location.search, { method:'POST', credentials:'same-origin',
      headers:{'Content-Type':'application/json','X-CSRF-Token':CSRF}, body: JSON.stringify(body) })
      .then(function(r){ return r.json().catch(function(){ return {ok:false, error:'Unreadable reply.'}; }); })
      .catch(function(){ return {ok:false, error:'No connection.'}; });
  }
  /* ── Book claims ───────────────────────────────────────────────────── */
  document.querySelectorAll('.bkq').forEach(function(card){
    var note = card.querySelector('.bkq-note');
    card.querySelectorAll('.bkq-btn').forEach(function(btn){
      btn.addEventListener('click', function(){
        var verdict = btn.getAttribute('data-verdict');
        var why = (note && note.value || '').trim();
        /* Refuse in the browser too, so the reason is asked for before the
           round trip rather than after it. The server refuses either way. */
        if (verdict !== 'verified' && !why) {
          toast(verdict === 'rejected' ? 'Say why it was rejected' : 'Say what needs changing', false);
          if (note) note.focus();
          return;
        }
        if (verdict === 'verified' && !window.confirm('Verify this book?\n\nIt counts toward their twenty-four and they cannot change it afterwards.')) return;
        var ask = btn.getAttribute('data-confirm');
        if (ask && !window.confirm(ask)) return;
        var all = card.querySelectorAll('.bkq-btn');
        all.forEach(function(b){ b.disabled = true; });
        post({action:'book_review', claim_id: +card.getAttribute('data-claim'), verdict: verdict, note: why})
          .then(function(j){
            if (j.ok) { toast(verdict === 'verified' ? 'Verified — it counts toward their 24 ✓' : verdict === 'rejected' ? 'Rejected — they have been told why' : (card.closest('#bookrejected') ? 'Reopened — they can rewrite it' : 'Sent back — they have been told what to change')); card.remove(); }
            else { toast(j.error || 'Nothing changed — the server gave no reason', false); all.forEach(function(b){ b.disabled = false; }); }
          });
      });
    });
  });

  /* ── Spoken checks ─────────────────────────────────────────────────── */
  document.querySelectorAll('.spq').forEach(function(card){
    var note = card.querySelector('.spq-note');
    card.querySelectorAll('.spq-btn').forEach(function(btn){
      btn.addEventListener('click', function(){
        var outcome = btn.getAttribute('data-outcome');
        var why = (note && note.value || '').trim();
        if (outcome === 'failed' && !why) {
          toast('Write what they could not answer', false);
          if (note) note.focus();
          return;
        }
        if (outcome === 'failed' && !window.confirm('Record that they could not speak to this book?\n\nIt goes back to them to resubmit. It is one conversation, not a verdict on the rest.')) return;
        var all = card.querySelectorAll('.spq-btn');
        all.forEach(function(b){ b.disabled = true; });
        post({action:'book_spot', spot_id: +card.getAttribute('data-spot'), outcome: outcome, note: why})
          .then(function(j){
            if (j.ok) { toast(outcome === 'passed' ? 'Recorded — they passed the check ✓' : 'Recorded — the book goes back to them to resubmit'); card.remove(); }
            else { toast(j.error || 'Nothing changed — the server gave no reason', false); all.forEach(function(b){ b.disabled = false; }); }
          });
      });
    });
  });
})();
</script>
