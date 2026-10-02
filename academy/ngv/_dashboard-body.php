<?php
/**
 * academy/ngv/_dashboard-body.php — the NGV dashboard itself: track
 * and plan, the fee account, damage, certifications, reading, schedule.
 *
 * Rendered inside the member portal (/portal/#ngv) and the standalone page.
 * Saves go to /academy/ngv/dashboard.php, which stays the one place that
 * writes, whichever surface the member is on.
 */
/* Which cards to draw. The standalone page draws them all with its own
   header; the member portal draws them in three views (Programme, Fees &
   account, the cards it puts elsewhere), once each, and the script once. */
$ngvParts      = $ngvParts ?? null;
$ngvShow       = static fn(string $id): bool => $ngvParts === null || in_array($id, $ngvParts, true);
$ngvStandalone = $ngvStandalone ?? ($ngvParts === null);
$ngvBanner     = $ngvBanner ?? true;
$ngvScript     = $ngvScript ?? true;
?>
<?php if ($ngvScript): ?>      <span class="save-msg" id="saveMsg" aria-live="polite">Saved ✓</span><?php endif; ?>
<?php if ($ngvBanner): ?>
      <?php if (!$enabled): ?>
      <div class="ngv-banner">The next cohort is being prepared — your personal tools below still work, and your progress is saved.</div>
      <?php endif; ?>
<?php endif; ?>
<?php if ($ngvStandalone): ?>

      <?php
      /* ── The lead ──────────────────────────────────────────────────────
         One thing answers "am I on track", and for a participant it is money:
         it has a deadline and a consequence, and everything else on this page
         is progress they control at their own pace. So it leads, with the way
         to deal with it beside it rather than four screens away.

         The four equal tiles this replaced gave the fee, the reading count and
         an unset track the same weight — which is the same as giving none of
         them any. */
      $nextDue = null;
      if ($feesOn && is_array($account['training'] ?? null) && !empty($account['training']['instalments'])) {
          foreach ($account['training']['instalments'] as $tr) {
              /* The first one not yet on the account: that is what arrives next,
                 which is the figure somebody can plan around. */
              if ((string) ($tr['state'] ?? '') === 'to come' || (string) ($tr['state'] ?? '') === 'due') {
                  $nextDue = ['amount' => (int) ($tr['amount'] ?? 0),
                              'label'  => date('F', (int) strtotime(((string) $tr['period']) . '-01'))];
                  break;
              }
          }
      }
      /* Card payment is offered only when it can actually work. A Pay button
         that opens an error is worse than no button: it teaches somebody the
         site is broken at the moment they were trying to give it money. */
      $payOn = $feesOn && class_exists('Payments') && Payments::configured('paystack');
      ?>
      <header class="dash-lead<?= $owed > 0 ? ' is-due' : '' ?>">
        <div class="dash-lead-main">
          <p class="dash-eyebrow"><?= $e($first) ?><?php if (class_exists('Levels')): ?> · <?= $e(Levels::labelOf(Levels::of($uid))) ?><?php endif;
            if (!empty($p['cohort'])): ?> · <?= $e((string) $p['cohort']) ?><?php endif; ?></p>
          <?php if (!$feesOn): ?>
            <p class="dash-figure is-clear">You're all set</p>
            <p class="dash-figure-sub">Fees aren't switched on for your cohort yet. Everything below is yours to keep up to date.</p>
          <?php elseif ($owed > 0): ?>
            <p class="dash-figure">₦<?= number_format($owed) ?></p>
            <p class="dash-figure-sub">outstanding<?php if ($nextDue): ?> · next instalment
              ₦<?= number_format((int) $nextDue['amount']) ?> in <?= $e((string) $nextDue['label']) ?><?php endif; ?></p>
            <div class="dash-lead-actions">
              <?php if ($payOn): ?>
                <button type="button" class="pbtn pbtn-gold" data-pay="<?= (int) $owed ?>">Pay ₦<?= number_format($owed) ?> now</button>
              <?php endif; ?>
              <a class="pbtn pbtn-ghost" href="#account">See what it's made of</a>
            </div>
          <?php else: ?>
            <p class="dash-figure is-clear">Nothing outstanding</p>
            <p class="dash-figure-sub">Your fees are up to date<?php if ($nextDue): ?> — next instalment
              ₦<?= number_format((int) $nextDue['amount']) ?> in <?= $e((string) $nextDue['label']) ?><?php endif; ?>.</p>
            <div class="dash-lead-actions">
              <?php if ($payOn && $nextDue): ?>
                <button type="button" class="pbtn pbtn-ghost" data-pay="<?= (int) $nextDue['amount'] ?>">Pay next instalment early</button>
              <?php endif; ?>
              <a class="pbtn pbtn-ghost" href="#account">See my account</a>
            </div>
          <?php endif; ?>
        </div>

        <dl class="dash-lead-side">
          <div><dt>Reading</dt><dd><span id="tileBooks"><?= $booksRead ?></span><span class="of">/ <?= $BOOKS_TOTAL ?></span></dd></div>
          <div><dt>Track</dt><dd class="is-text" id="tileTrack"><?= $myTrack !== '' ? $e($myTrack) : 'Not picked' ?></dd></div>
          <div><dt>Certifications</dt><dd><?= count($myCerts) ?></dd></div>
        </dl>
      </header>

<?php endif; ?>
      <div class="ngv-grid">

        <!-- My track + plan -->
<?php if ($ngvShow('track')): ?>
        <section class="pcard" id="track">
          <div class="pcard-head"><h2>My track &amp; plan</h2></div>
          <div class="pcard-body">
            <div class="ngv-sub-h">My track</div>
            <div class="ngv-picks">
              <?php foreach ($tracks as $t): $nm = (string)($t['name'] ?? ''); if ($nm === '') continue; ?>
              <div class="trk <?= $myTrack === $nm ? 'on' : '' ?>" data-track="<?= $e($nm) ?>">
                <div class="i"><?= $e((string)($t['icon'] ?? '🎯')) ?></div>
                <div class="n"><?= $e($nm) ?></div>
                <div class="d"><?= $e((string)($t['desc'] ?? '')) ?></div>
              </div>
              <?php endforeach; ?>
            </div>
            <?php if ($myTrack !== ''): ?><div class="ngv-box" id="trackNote">You're on <b><?= $e($myTrack) ?></b>. <?= $e($myTrackDesc) ?></div><?php endif; ?>

            <?php if (!empty($planOpts)): ?>
            <div class="ngv-sub-h">My plan</div>
            <div class="ngv-picks">
              <?php foreach ($planOpts as $pl): $pn = (string)$pl['name']; ?>
              <?php /* No icon. The same card symbol on every plan told nobody
                       which plan was which — it was four identical pictures
                       competing with the four different prices beside them. */ ?>
              <div class="trk <?= $myPlan === $pn ? 'on' : '' ?>" data-plan="<?= $e($pn) ?>">
                <div class="n"><?= $e($pn) ?> · <?= $e((string)$pl['priceLabel']) ?></div>
                <div class="d"><?= $e((string)$pl['desc']) ?></div>
              </div>
              <?php endforeach; ?>
            </div>
            <div class="ngv-box">Your plan sets your <b>training fee</b> in <a href="<?= isset($ngvAccountHref) ? e($ngvAccountHref) . '" data-goto="ngv-account' : '#account' ?>">Your account</a>. Free tracks stay free — no one is turned away for lack.</div>
            <?php endif; ?>
          </div>
        </section>
<?php endif; ?>

        <!-- Focus note -->
<?php if ($ngvShow('focus')): ?>
        <section class="pcard" id="focus">
          <div class="pcard-head"><h2>My focus this month</h2><span class="pcard-sub">private to you</span></div>
          <div class="pcard-body">
            <textarea id="note" maxlength="200" placeholder="What are you committed to this month? e.g. finish 2 books, ship my track project, show up on time every day."><?= $e($myNote) ?></textarea>
            <div style="display:flex;align-items:center;gap:10px;margin-top:10px">
              <button class="pbtn pbtn-gold" id="noteSave">Save note</button>
              <span style="font-size:12px;color:var(--muted-2)"><span id="noteCount"><?= mb_strlen($myNote) ?></span>/200</span>
            </div>
          </div>
        </section>
<?php endif; ?>

        <!-- Your account — training fee, membership, monthly commitment, any
             fines, and every entry behind them. One plain figure, never in red,
             no deadline, and the money conversation pointed at a person. -->
<?php if ($ngvShow('account')): ?>
        <section class="pcard wide" id="account">
          <div class="pcard-head"><h2>Your account</h2><span class="pcard-sub">recorded by your team</span></div>
          <div class="pcard-body">
            <?php if (!$feesOn): ?>
              <div class="ngv-figure clear">Nothing to pay</div>
              <div class="ngv-figure-sub">fees aren't switched on yet</div>
              <div class="ngv-box">When your team starts recording membership and commitment, it will appear here — with
                every entry, so you can always see what a figure is made of.</div>
            <?php else: ?>
              <?php if ($owed > 0): ?>
                <div class="ngv-figure">₦<?= number_format($owed) ?></div>
                <div class="ngv-figure-sub">outstanding</div>
              <?php else: ?>
                <div class="ngv-figure clear">All clear</div>
                <div class="ngv-figure-sub">nothing outstanding</div>
              <?php endif; ?>

              <?php if ($payOn && $owed > 0): ?>
                <?php /* Amount first, one button. The instalment is offered
                         beside the full balance because "this month's ₦20,000"
                         is what most people actually mean to pay, and making
                         them retype it is how a payment becomes a maybe. */ ?>
                <div class="npay" id="npay">
                  <p class="npay-legend">Pay online</p>
                  <div class="npay-opts" role="radiogroup" aria-label="How much to pay">
                    <label class="npay-opt">
                      <input type="radio" name="npay_amt" value="<?= (int) $owed ?>" checked>
                      <span class="npay-amt">₦<?= number_format($owed) ?></span>
                      <span class="npay-what">everything outstanding</span>
                    </label>
                    <?php if ($nextDue && (int) $nextDue['amount'] > 0 && (int) $nextDue['amount'] < $owed): ?>
                      <label class="npay-opt">
                        <input type="radio" name="npay_amt" value="<?= (int) $nextDue['amount'] ?>">
                        <span class="npay-amt">₦<?= number_format((int) $nextDue['amount']) ?></span>
                        <span class="npay-what">one instalment</span>
                      </label>
                    <?php endif; ?>
                    <label class="npay-opt npay-opt--other">
                      <input type="radio" name="npay_amt" value="other">
                      <span class="npay-amt">Another amount</span>
                      <span class="npay-what">whatever you can manage</span>
                    </label>
                  </div>
                  <label class="npay-custom" hidden>
                    <span class="sr-only">Amount in naira</span>
                    <span class="npay-pre" aria-hidden="true">₦</span>
                    <input type="number" id="npayOther" min="100" step="500" inputmode="numeric" placeholder="Amount">
                  </label>
                  <button type="button" class="pbtn pbtn-gold npay-go" id="npayGo">Pay <span id="npayGoAmt"></span></button>
                  <p class="npay-err" id="npayErr" role="alert" hidden></p>
                  <p class="npay-fine">Card, transfer or USSD, secured by Paystack. Your receipt is emailed the moment it clears.
                    Paying part of it is fine — anything you send comes off the oldest thing you owe first.</p>
                </div>
              <?php elseif ($owed > 0): ?>
                <div class="ngv-box">Card payment isn't switched on yet. You can still pay by transfer using the
                  details below, and your team will record it.</div>
              <?php endif; ?>

              <?php if ((int)$account['paidAhead'] > 0): ?>
                <div class="ngv-box">You're ₦<?= number_format((int)$account['paidAhead']) ?> ahead on a fee that's already
                  settled. It stays on your record as paid ahead rather than being moved onto something else.</div>
              <?php endif; ?>

              <?php $T = is_array($account['training'] ?? null) && !empty($account['training']) ? $account['training'] : null; ?>
              <?php if ($account['planLabel'] !== ''): ?>
              <div class="ngv-box">
                You're on the <b><?= $e((string)$account['planLabel']) ?></b> plan.
                <?php if ($account['planFree']): ?>Your training is <b>free</b> — only membership and the monthly commitment apply.
                <?php elseif ($T): ?>Its training fee is shown below, month by month.
                <?php elseif ((int)$account['planFee'] > 0): ?>
                  Its training fee is ₦<?= number_format((int)$account['planFee']) ?>; nothing has been agreed on your account yet.
                <?php else: ?>Its training fee is shown below.<?php endif; ?>
              </div>
              <?php endif; ?>

              <!-- Per line, not one netted total: "square on membership, two
                   months behind on commitment" is something you can act on. -->
              <div class="ngv-rows" style="margin-top:12px">
                <?php foreach ($account['lines'] as $ln): ?>
                <div class="ngv-row">
                  <div><span class="k"><?= $e((string)$ln['label']) ?></span><span class="d"><?= $e((string)$ln['detail']) ?></span></div>
                  <span class="amt">
                    <?php if (!empty($ln['free'])): ?><span class="pchip pchip--green">Free</span>
                    <?php elseif ((int)$ln['due'] > 0): ?><span class="pchip pchip--red">₦<?= number_format((int)$ln['due']) ?></span>
                    <?php elseif ((int)$ln['charged'] > 0): ?><span class="pchip pchip--green">Paid</span>
                    <?php else: ?><span class="pchip pchip--gold">Not yet charged</span><?php endif; ?>
                  </span>
                </div>
                <?php endforeach; ?>
                <div class="ngv-row"><div><span class="k">Received from you</span><span class="d">across every fee</span></div><span class="amt">₦<?= number_format((int)$account['received']) ?></span></div>
                <?php if ((int)$account['waived'] > 0): ?>
                <div class="ngv-row"><div><span class="k">Set aside for you</span><span class="d">waived by your team — not money you paid, and not money you owe</span></div><span class="amt">₦<?= number_format((int)$account['waived']) ?></span></div>
                <?php endif; ?>
              </div>

              <?php if ($T && (int)$T['months'] > 1): ?>
              <!-- Month by month. A ₦240,000 total says nothing you can plan
                   around; "instalment 4 of 12, ₦20,000, this month" does. -->
              <div class="ngv-sched">
                <div class="ngv-sched-head">
                  <b>Your training fee</b>
                  <span><?= (int)$T['settled'] ?> of <?= (int)$T['months'] ?> charged ·
                    ₦<?= number_format((int)$T['paid']) ?> of ₦<?= number_format((int)$T['total']) ?> paid</span>
                </div>
                <div class="ngv-bar"><span style="width:<?= (int)$T['total'] > 0 ? min(100, round(100 * (int)$T['paid'] / (int)$T['total'])) : 0 ?>%"></span></div>
                <div class="ngv-insts">
                  <?php foreach ($T['instalments'] as $i): ?>
                    <div class="ngv-inst ngv-inst--<?= $e((string)$i['state']) ?>">
                      <span class="mo"><?= $e(date('M y', (int) strtotime($i['period'] . '-01'))) ?></span>
                      <span class="amt">₦<?= number_format((int)$i['amount']) ?></span>
                      <span class="st"><?= $i['state'] === 'charged' ? 'on your account' : ($i['state'] === 'due' ? 'due' : 'to come') ?></span>
                    </div>
                  <?php endforeach; ?>
                </div>
                <p class="ngv-fine">Only the months already on your account are being asked for. The rest arrive one at a
                  time, and the total was fixed when this was agreed — it does not change if the programme's prices do.</p>
              </div>
              <?php endif; ?>

              <?php $rcByPay = []; foreach ($myReceipts as $rc) $rcByPay[(int)$rc['id']] = $rc; ?>
              <?php if ($myReceipts): ?>
              <!-- Somebody who handed over cash has no other proof it arrived.
                   These are the same payment rows as the ledger below, addressed
                   so they can be opened, printed, or shown to a third party. -->
              <details class="ngv-details">
                <summary>My receipts (<?= count($myReceipts) ?>)</summary>
                <div class="ngv-rows" style="margin-top:6px">
                  <?php foreach ($myReceipts as $rc): ?>
                  <div class="ngv-row"<?= $rc['void'] ? ' style="opacity:.55"' : '' ?>>
                    <div>
                      <span class="k"><?= $e((string)$rc['no']) ?>
                        <?php if ($rc['void']): ?><span class="pchip pchip--red">cancelled</span><?php endif; ?></span>
                      <span class="d"><?= $e((string)$rc['lineLabel']) ?><?= $rc['period'] !== '' ? ' · ' . $e((string)$rc['period']) : '' ?>
                        · paid <?= $e((string)$rc['paidOn']) ?><?= $rc['method'] !== '' ? ' · ' . $e((string)$rc['method']) : '' ?></span>
                    </div>
                    <span class="amt">₦<?= number_format((int)$rc['amount']) ?>
                      <a class="ngv-mini" href="/academy/ngv/receipt.php?id=<?= (int)$rc['id'] ?>&amp;c=<?= urlencode($rc['code']) ?>"
                         target="_blank" rel="noopener">open</a></span>
                  </div>
                  <?php endforeach; ?>
                </div>
                <p class="ngv-fine">Each opens a printable receipt anyone can check without an account — useful as proof of
                  payment. It shows that one payment and nothing else about your account.</p>
              </details>
              <?php endif; ?>

              <?php if (!empty($myEntries)): ?>
              <details class="ngv-details" style="margin-top:10px">
                <summary>See every entry (<?= count($myEntries) ?>)</summary>
                <div class="ngv-rows" style="margin-top:6px">
                  <?php foreach ($myEntries as $en): $isCharge = $en['side'] === 'charge'; ?>
                  <div class="ngv-row"<?= $en['void'] ? ' style="opacity:.55"' : '' ?>>
                    <div>
                      <span class="k"><?= $e($isCharge
                            ? ($kindLabel[$en['kind']] ?? ucfirst((string)$en['kind']))
                            : (($creditWord[$en['creditKind']] ?? 'Payment received') . ' · ' . ($kindLabel[$en['kind']] ?? $en['kind']))) ?></span>
                      <span class="d">
                        <?= $e(substr((string)$en['created_at'], 0, 10)) ?>
                        <?= $en['period'] !== '' ? ' · ' . $e((string)$en['period']) : '' ?>
                        <?= $en['note'] !== '' ? ' · ' . $e((string)$en['note']) : '' ?>
                        <?= $en['void'] ? ' · cancelled (' . $e((string)$en['voidReason']) . ')' : '' ?>
                        <?php if (!$isCharge && $en['creditKind'] === 'payment'): $rc = $rcByPay[(int)$en['id']] ?? null; ?>
                          <?php if ($rc): ?> · <a href="/academy/ngv/receipt.php?id=<?= (int)$rc['id'] ?>&amp;c=<?= urlencode($rc['code']) ?>"
                            target="_blank" rel="noopener">receipt <?= $e($rc['no']) ?></a><?php endif; ?>
                        <?php endif; ?>
                      </span>
                    </div>
                    <span class="amt"><?= $isCharge ? '' : '− ' ?>₦<?= number_format((int)$en['amount']) ?></span>
                  </div>
                  <?php endforeach; ?>
                </div>
                <p class="ngv-fine">Nothing is ever deleted here. A correction stays visible with its reason, so this list
                  always adds up to the figure at the top.</p>
              </details>
              <?php else: ?>
              <div class="ngv-box">Nothing on your account yet. When your team records a charge or a payment it shows here.</div>
              <?php endif; ?>
            <?php endif; ?>

            <?php if ($feesOn && !empty($account['payTo'])): ?><div class="ngv-box"><?= $e((string)$account['payTo']) ?></div><?php endif; ?>

            <?php if ($feesOn): ?>
            <!-- Somebody who wants their position in writing should be able to
                 get it without asking a person for it. -->
            <div class="ngv-box">
              Want this in writing? <button class="pbtn pbtn-ghost" id="stmtBtn" type="button">Email me my statement</button>
              <span id="stmtOut" class="ngv-fine"></span>
            </div>
            <?php endif; ?>

            <!-- The page promises "no one is turned away for lack — speak to your
                 track lead or send a letter requesting consideration". Repeating
                 that and stopping there makes it a dead end: the person who most
                 needs it is the one least likely to walk up and start the
                 conversation. So here is the conversation, in one box. -->
            <?php
              $reqs = is_array($account['requests'] ?? null) ? $account['requests'] : [];
              $openReq = null;
              foreach ($reqs as $rq) { if ($rq['status'] === 'open') { $openReq = $rq; break; } }
            ?>
            <?php if ($openReq): ?>
              <div class="ngv-box">
                <b>Your track lead has your message.</b><br>
                <span class="ngv-quote"><?= $e((string)$openReq['message']) ?></span><br>
                Sent <?= $e(substr((string)$openReq['created_at'], 0, 10)) ?>. They'll come back to you here.
              </div>
            <?php elseif ($feesOn): ?>
              <!-- Offered only while fees are actually running. "I need
                   consideration this month" against an account with nothing on
                   it is a form that invites a message nobody can answer. An
                   existing request still shows above either way, so a reply is
                   never lost because a switch was flipped. -->
              <details class="ngv-details ngv-ask">
                <summary>Something look wrong, or is this a difficult month?</summary>
                <p class="ngv-fine" style="margin-top:6px">Tell your track lead here rather than letting it sit. Nothing you
                  write changes your account on its own — a person reads it and replies.</p>
                <select id="reqKind" class="ngv-input">
                  <option value="consideration">I need consideration this month</option>
                  <option value="query">A figure here looks wrong</option>
                </select>
                <textarea id="reqMsg" class="ngv-input" rows="3" maxlength="1200"
                  placeholder="A sentence or two is plenty."></textarea>
                <button class="pbtn pbtn-gold" id="reqSend" type="button">Send it</button>
                <span id="reqMsgOut" class="ngv-fine"></span>
              </details>
            <?php endif; ?>

            <?php foreach (array_slice(array_filter($reqs, static fn($r) => $r['status'] !== 'open'), 0, 3) as $rq): ?>
              <div class="ngv-box">
                <b><?= $rq['status'] === 'declined' ? 'Answered' : 'Sorted' ?>
                  — <?= $e(substr((string)$rq['handled_at'], 0, 10)) ?></b><br>
                <span class="ngv-quote"><?= $e((string)$rq['message']) ?></span><br>
                <?= $e((string)$rq['outcome']) ?>
              </div>
            <?php endforeach; ?>

            <?php if (!empty($account['note'])): ?><div class="ngv-box"><?= $e((string)$account['note']) ?></div><?php endif; ?>
          </div>
        </section>
<?php endif; ?>

        <!-- ══ Damage ═══════════════════════════════════════════════════════
             Recording damage costs nothing, and this section says so before it
             says anything else. The natural fear on being told "damage has been
             recorded" is a bill, and the status line is what replaces guessing
             with knowing. -->
<?php if ($ngvShow('damage')): ?>
        <section class="pcard" id="damage">
          <div class="pcard-head"><h2>Damage &amp; equipment</h2>
            <span class="pcard-sub"><?= $myDamage ? count($myDamage) . ' on record' : 'nothing on record' ?></span></div>
          <div class="pcard-body">
            <?php if ($myDamage): ?>
              <div class="ngv-rows">
                <?php foreach ($myDamage as $d): ?>
                <div class="ngv-row">
                  <div>
                    <span class="k"><?= $e((string)$d['item']) ?>
                      <?php if ($d['selfReport']): ?><span class="pchip pchip--green">you told us</span><?php endif; ?></span>
                    <span class="d">
                      <?= $e((string)$d['occurred_on']) ?><?= $d['place'] !== '' ? ' · ' . $e((string)$d['place']) : '' ?>
                      · <?= $e((string)$d['severityLabel']) ?>
                      <?php if ($d['outcome'] !== ''): ?><br><?= $e((string)$d['outcome']) ?><?php endif; ?>
                    </span>
                    <?php if (!empty($d['photos'])): ?>
                      <span class="ngv-shots">
                        <?php foreach ($d['photos'] as $ph): ?>
                          <a href="<?= $e((string)$ph) ?>" target="_blank" rel="noopener">
                            <img src="<?= $e((string)$ph) ?>" alt="Photo you attached of the <?= $e((string)$d['item']) ?>" loading="lazy"></a>
                        <?php endforeach; ?>
                      </span>
                    <?php endif; ?>
                    <?php if ($d['open'] && count($d['photos']) < NgvDamage::PHOTOS_MAX): ?>
                      <span class="ngv-addshot">
                        <label class="ngv-fine">Add a photo
                          <input type="file" class="dmAdd" data-for="<?= (int)$d['id'] ?>" accept="image/*"></label>
                      </span>
                    <?php endif; ?>
                  </div>
                  <span class="amt">
                    <?php if ($d['status'] === 'charged'): ?>
                      <span class="pchip pchip--red">₦<?= number_format((int)$d['charged']) ?></span>
                    <?php elseif ($d['open']): ?>
                      <span class="pchip pchip--gold"><?= $e((string)$d['statusLabel']) ?></span>
                    <?php else: ?>
                      <span class="pchip pchip--green"><?= $e((string)$d['statusLabel']) ?></span>
                    <?php endif; ?>
                  </span>
                </div>
                <?php endforeach; ?>
              </div>
              <p class="ngv-fine">Nothing is charged until you are told a figure. Where something has been assessed and
                you are being asked for less than it cost, the programme is carrying the rest.</p>
            <?php else: ?>
              <div class="ngv-box">Nothing on record. If you break or lose something, say so here — it costs you nothing
                to report, and telling us yourself is the right instinct.</div>
            <?php endif; ?>

            <details class="ngv-details ngv-ask">
              <summary>Report damage or something lost</summary>
              <p class="ngv-fine" style="margin-top:6px">This charges you nothing. It records what happened, and your track
                lead will tell you if there is anything to pay before it reaches your account.</p>
              <input id="dmItem" class="ngv-input" maxlength="120" placeholder="What was it? (e.g. laptop screen, chair)">
              <div class="ngv-two">
                <input id="dmWhen" class="ngv-input" type="date" value="<?= $e(function_exists('av_today_tz') ? av_today_tz() : gmdate('Y-m-d')) ?>">
                <select id="dmSev" class="ngv-input">
                  <?php foreach (NgvDamage::SEVERITIES as $k => $lbl): ?><option value="<?= $e($k) ?>"><?= $e($lbl) ?></option><?php endforeach; ?>
                </select>
              </div>
              <textarea id="dmDesc" class="ngv-input" rows="3" maxlength="1200" placeholder="What happened?"></textarea>
              <label class="ngv-fine" style="display:block;margin-top:8px">A photo, if you have one (optional)
                <input id="dmPhoto" class="ngv-input" type="file" accept="image/*" multiple style="margin-top:4px"></label>
              <button class="pbtn pbtn-gold" id="dmSend" type="button">Report it</button>
              <span id="dmOut" class="ngv-fine"></span>
            </details>
          </div>
        </section>
<?php endif; ?>

        <!-- Certifications -->
<?php if ($ngvShow('certs')): ?>
        <section class="pcard" id="certs">
          <div class="pcard-head"><h2>My certifications</h2><span class="pcard-sub"><?= count($myCerts) ?> earned</span></div>
          <div class="pcard-body">
            <?php if (!empty($myCerts)): ?>
            <div class="ngv-rows">
              <?php foreach ($myCerts as $cert): $certUrl = '/academy/ngv/certificate.php?id=' . (int)$cert['id'] . '&c=' . rawurlencode((string)($cert['code'] ?? '')); ?>
              <div class="ngv-row">
                <div><span class="k">🏅 <?= $e((string)($cert['title'] ?? '')) ?></span><span class="d"><?= $e(trim(((string)($cert['issued_by'] ?? '')) . (!empty($cert['issued_on']) ? ' · ' . substr((string)$cert['issued_on'], 0, 10) : ''), ' ·')) ?></span></div>
                <a class="amt pcard-link" href="<?= $e($certUrl) ?>" target="_blank" rel="noopener">View / print ↗</a>
              </div>
              <?php endforeach; ?>
            </div>
            <?php else: ?>
            <div class="ngv-box">No certifications yet — earn them by completing your track milestones. The programme offers up to <?= $e((string)($stats[1]['num'] ?? '6')) ?> per year.</div>
            <?php endif; ?>
          </div>
        </section>
<?php endif; ?>

        <!-- 24-book reading challenge -->
<?php if ($ngvShow('reading')): ?>
        <?php
        /* The reading challenge, as claims rather than checkboxes.
           Tapping a box used to be the whole of it — no record of which book,
           nothing written, nobody asked. A slot now holds a claim a track lead
           has to verify before it counts, and the figure on this page is the
           VERIFIED one. */
        $shelf = NgvReading::shelf($uid);
        $rp    = NgvReading::progress($uid);
        $legacyBits = substr_count($myBooks, '1');
        ?>
        <section class="pcard wide" id="reading">
          <div class="pcard-head"><h2>24-book reading challenge</h2>
            <span class="pcard-sub"><?= (int) $rp['verified'] ?> verified<?php
              if ($rp['waiting'] > 0): ?> · <?= (int) $rp['waiting'] ?> with your track lead<?php endif; ?><?php
              if ($rp['needs_work'] > 0): ?> · <?= (int) $rp['needs_work'] ?> to put right<?php endif; ?></span></div>
          <div class="pcard-body">
            <div class="ngv-books" id="books">
              <?php foreach ($shelf as $slot => $bc):
                $st = (string) $bc['status'];
                $cls = ['verified' => 'on', 'submitted' => 'pend', 'resubmit' => 'back',
                        'rejected' => 'back', 'draft' => 'draft'][$st] ?? '';
                $tip = ['verified' => 'Verified', 'submitted' => 'With your track lead',
                        'resubmit' => 'Needs another look', 'rejected' => 'Not accepted',
                        'draft' => 'Draft — not sent yet'][$st] ?? 'Not started';
              ?>
              <button type="button" class="book <?= $cls ?>" data-slot="<?= (int) $slot ?>"
                      title="Book <?= (int) $slot ?> — <?= $e($tip) ?><?= $bc['title'] !== '' ? ': ' . $e((string) $bc['title']) : '' ?>"
                      aria-label="Book <?= (int) $slot ?>, <?= $e($tip) ?>"><?= (int) $slot ?></button>
              <?php endforeach; ?>
            </div>
            <div class="ngv-bar"><i id="booksBar" style="width:<?= (int) round($rp['verified'] / max(1, (int) $rp['total']) * 100) ?>%"></i></div>
            <div style="font-size:12.5px;color:var(--muted)">
              <b><?= (int) $rp['verified'] ?></b> of <?= (int) $rp['total'] ?> verified.
              Tap a number to record a book: the title, when you read it, what it argued and one thing you
              have done because of it. Your track lead checks it before it counts.
            </div>
            <?php if (!empty($rp['spot_pending'])): ?>
              <?php /* Says that a conversation is due. Deliberately does NOT
                       say which book — the whole value of the check is that it
                       cannot be prepared for. `progress()` returns a boolean
                       for exactly this reason: the title is not available on
                       this page to leak by accident. */ ?>
              <div class="ngv-box" style="margin-top:12px;border-left:3px solid #1d4ed8">
                <strong>Your track lead will ask you about one of your books.</strong>
                It happens every six books and the book is picked at random, so it could be any of
                them. Nothing to prepare — if you read them, you can talk about them.
              </div>
            <?php endif; ?>
            <?php if ($legacyBits > (int) $rp['verified']): ?>
              <?php /* Honest about the migration: ticks from the old checkbox
                       era were never checked by anybody, so they are named as
                       what they are rather than quietly counted or deleted. */ ?>
              <div class="ngv-box" style="margin-top:12px"><?= (int) $legacyBits ?> book<?= $legacyBits === 1 ? '' : 's' ?>
                <?= $legacyBits === 1 ? 'was' : 'were' ?> ticked before we started checking them. Those are not counted
                above — record them properly when you have a moment and they will be.</div>
            <?php endif; ?>
          </div>
        </section>
<?php endif; ?>

        <!-- The claim sheet. One at a time, opened from a slot. -->
        <div class="bkmodal" id="bkModal" hidden role="dialog" aria-modal="true" aria-labelledby="bkTitle">
          <div class="bkmodal-card" role="document">
            <div class="bkmodal-head">
              <h3 id="bkTitle">Book <span id="bkSlot">1</span></h3>
              <button type="button" class="bkmodal-x" id="bkClose" aria-label="Close">&times;</button>
            </div>
            <div class="bkmodal-body">
              <p class="bk-status" id="bkStatus" hidden></p>
              <div class="bk-grid">
                <label class="bk-f bk-f--wide"><span>Title</span>
                  <input id="bkBookTitle" maxlength="200" autocomplete="off"></label>
                <label class="bk-f"><span>Author</span>
                  <input id="bkAuthor" maxlength="120" autocomplete="off"></label>
                <label class="bk-f"><span>Started</span>
                  <input id="bkStarted" type="date"></label>
                <label class="bk-f"><span>Finished</span>
                  <input id="bkFinished" type="date"></label>
              </div>
              <label class="bk-f bk-f--wide"><span>What did it argue, and did you agree?
                <em>at least <?= (int) NgvReading::MIN_REFLECTION ?> characters</em></span>
                <textarea id="bkReflection" rows="8" maxlength="6000"></textarea>
                <span class="bk-count" id="bkReflCount">0</span></label>
              <label class="bk-f bk-f--wide"><span>One thing you have done, or will do, because of it
                <em>your track lead may ask you about this</em></span>
                <textarea id="bkTakeaway" rows="3" maxlength="600"></textarea>
                <span class="bk-count" id="bkTakeCount">0</span></label>
              <p class="bk-err" id="bkErr" hidden role="alert"></p>
            </div>
            <div class="bkmodal-foot">
              <button type="button" class="pbtn pbtn-ghost" id="bkSaveDraft">Save draft</button>
              <button type="button" class="pbtn pbtn-gold" id="bkSubmit">Send for checking</button>
            </div>
          </div>
        </div>

        <!-- Schedule & where -->
<?php if ($ngvShow('schedule')): ?>
        <section class="pcard" id="schedule">
          <details>
            <summary class="pcard-head pcard-summary"><h2>Schedule &amp; where</h2><span class="pcard-sub">times, dress code and the centres</span></summary>
          <div class="pcard-body">
            <div class="ngv-rows">
              <?php foreach (['days' => 'Attendance', 'time' => 'Daily schedule', 'uniform' => 'Dress code'] as $k => $lbl): if (!empty($sched[$k])): ?>
              <div class="ngv-row"><div><span class="k"><?= $e($lbl) ?></span><span class="d"><?= $e((string)$sched[$k]) ?></span></div></div>
              <?php endif; endforeach; ?>
              <?php foreach ($offices as $o): if (empty($o['name'])) continue; ?>
              <div class="ngv-row"><div><span class="k"><?= $e((string)$o['name']) ?></span><span class="d"><?= $e((string)($o['address'] ?? '')) ?></span></div></div>
              <?php endforeach; ?>
            </div>
          </div>
          </details>
        </section>
<?php endif; ?>

        <!-- Skills + support -->
<?php if ($ngvShow('support')): ?>
        <?php /* The ways to reach a human stay in the open — they are what
                 somebody comes to this card FOR, and hiding a phone number
                 behind a disclosure on a page a struggling participant is
                 reading is the wrong thing to fold. The skills list is
                 reference, so that is what folds. */ ?>
        <section class="pcard" id="support">
          <div class="pcard-head"><h2>Getting help</h2><span class="pcard-sub">your team, any day</span></div>
          <div class="pcard-body">
            <div class="ngv-contact">
              <?php if (!empty($ct['phone'])): $tel = preg_replace('/[^0-9+]/', '', (string)$ct['phone']); ?>
              <a href="tel:<?= $e($tel) ?>">Call your team</a>
              <a href="https://wa.me/<?= $e(ltrim($tel, '+')) ?>" target="_blank" rel="noopener">WhatsApp</a>
              <?php endif; ?>
              <?php if (!empty($ct['email'])): ?><a href="mailto:<?= $e((string)$ct['email']) ?>"><?= $e((string)$ct['email']) ?></a><?php endif; ?>
              <a href="/academy/ngv/#faq" target="_blank" rel="noopener">Programme FAQ</a>
            </div>
            <?php if ($marquee): ?>
            <details style="margin-top:14px">
              <summary class="pcard-summary" style="padding:0;font-weight:700;font-size:13.5px;color:var(--muted)">
                Skills you're building <span class="pcard-sub"><?= count($marquee) ?></span></summary>
              <div class="ngv-pills" style="margin-top:10px">
                <?php foreach ($marquee as $m): ?><span class="ngv-pill"><?= $e((string)$m) ?></span><?php endforeach; ?>
              </div>
            </details>
            <?php endif; ?>
          </div>
        </section>
<?php endif; ?>

      </div>
<?php if ($ngvScript): ?>
<script>window.NGV_CSRF = <?= json_encode($csrf) ?>;</script>
<script src="/academy/ngv/pay.js" defer></script>
<script src="/academy/ngv/reading.js" defer></script>
<script>
(function(){
  var NGV_URL = '/academy/ngv/dashboard.php';
  var BOOKS_TOTAL = <?= $BOOKS_TOTAL ?>;
  var CSRF = <?= json_encode($csrf) ?>;
  var msg = document.getElementById('saveMsg');
  var msgT;
  function toast(txt){ if(!msg) return; msg.textContent = txt || 'Saved ✓'; msg.classList.add('on'); clearTimeout(msgT); msgT = setTimeout(function(){ msg.classList.remove('on'); }, 1600); }
  function save(patch, ok){
    fetch(NGV_URL, {method:'POST', headers:{'Content-Type':'application/json','X-CSRF-Token':CSRF}, credentials:'same-origin', body:JSON.stringify(patch)})
      .then(function(r){ return r.json().catch(function(){ return {ok:false, status:r.status}; }).then(function(j){ j.status = r.status; return j; }); })
      .then(function(j){ if(j && j.ok){ toast(); if(ok) ok(); } else if(j && j.status === 403){ toast('Session timed out — reload the page'); } else { toast('Couldn’t save — try again'); } })
      .catch(function(){ toast('Offline — not saved'); });
  }

  /* Asking for consideration. Deliberately NOT routed through save(): that one
     toasts "Saved" and swallows the server's reason, and the two answers this
     can give — "tell us a little about it" and "you already have one open" —
     are the whole conversation. */
  var reqSend = document.getElementById('reqSend');
  if(reqSend) reqSend.addEventListener('click', function(){
    var kindEl = document.getElementById('reqKind'), msgEl = document.getElementById('reqMsg');
    var out = document.getElementById('reqMsgOut');
    var body = {action:'fee_request', kind: kindEl ? kindEl.value : 'consideration', message: msgEl ? msgEl.value : ''};
    reqSend.disabled = true;
    fetch(NGV_URL, {method:'POST', headers:{'Content-Type':'application/json','X-CSRF-Token':CSRF}, credentials:'same-origin', body:JSON.stringify(body)})
      .then(function(r){ return r.json().catch(function(){ return {ok:false}; }); })
      .then(function(j){
        reqSend.disabled = false;
        if(j && j.ok){ if(out) out.textContent = 'Sent — your track lead will come back to you here.';
                       setTimeout(function(){ location.reload(); }, 1200); }
        else if(out){ out.textContent = (j && j.error) || 'Could not send that — try again.'; }
      })
      .catch(function(){ reqSend.disabled = false; if(out) out.textContent = 'Offline — not sent.'; });
  });

  /* Ask for the statement by email. Same reasoning as the request box: save()
     toasts "Saved" and swallows the server's reason, and "we sent you one today"
     is the answer worth reading. */
  var stmtBtn = document.getElementById('stmtBtn');
  if(stmtBtn) stmtBtn.addEventListener('click', function(){
    var out = document.getElementById('stmtOut');
    stmtBtn.disabled = true; if(out) out.textContent = 'Sending…';
    fetch(NGV_URL, {method:'POST', headers:{'Content-Type':'application/json','X-CSRF-Token':CSRF}, credentials:'same-origin', body:JSON.stringify({action:'statement'})})
      .then(function(r){ return r.json().catch(function(){ return {ok:false}; }); })
      .then(function(j){
        stmtBtn.disabled = false;
        if(out) out.textContent = (j && j.ok)
          ? (j.delivered ? 'Sent to ' + (j.to || 'your email') + '.' : 'We could not get that email out — tell your track lead.')
          : ((j && j.error) || 'Could not send that.');
      })
      .catch(function(){ stmtBtn.disabled = false; if(out) out.textContent = 'Offline — not sent.'; });
  });

  // Reporting your own damage. Costs nothing; staff decide if money follows.
  var dmSend = document.getElementById('dmSend');
  if(dmSend) dmSend.addEventListener('click', function(){
    var out = document.getElementById('dmOut'), g = function(id){ var el=document.getElementById(id); return el?el.value:''; };
    if(!g('dmItem').trim()){ if(out) out.textContent = 'What was it?'; return; }
    if(g('dmDesc').trim().length < 10){ if(out) out.textContent = 'A sentence or two about what happened, please.'; return; }
    // Multipart, so the optional photo rides along and this stays one step.
    var fd = new FormData();
    fd.append('action','damage'); fd.append('item', g('dmItem')); fd.append('occurred_on', g('dmWhen'));
    fd.append('severity', g('dmSev')); fd.append('description', g('dmDesc'));
    var pf = document.getElementById('dmPhoto');
    if(pf && pf.files) for(var i=0;i<pf.files.length;i++) fd.append('photos[]', pf.files[i]);
    dmSend.disabled = true; if(out) out.textContent = 'Sending…';
    fetch(NGV_URL, {method:'POST', headers:{'X-CSRF-Token':CSRF}, credentials:'same-origin', body:fd})
      .then(function(r){ return r.json().catch(function(){ return {ok:false, error:'That did not go through — the photo may be too large.'}; }); })
      .then(function(j){
        dmSend.disabled = false;
        if(j && j.ok){
          if(out) out.textContent = 'Recorded. Nothing has been charged.'
            + (j.photoNote ? ' (The photo did not attach: ' + j.photoNote + ')' : '');
          setTimeout(function(){ location.reload(); }, j.photoNote ? 2600 : 1200);
        } else if(out){ out.textContent = (j && j.error) || 'Could not record that.'; }
      })
      .catch(function(){ dmSend.disabled = false; if(out) out.textContent = 'Offline — not recorded.'; });
  });

  // Attaching a photo to a record already made — the picture often comes later.
  document.querySelectorAll('.dmAdd').forEach(function(inp){
    inp.addEventListener('change', function(){
      if(!inp.files || !inp.files.length) return;
      var fd = new FormData();
      fd.append('action','damage_photos'); fd.append('damage_id', inp.getAttribute('data-for')||'0');
      for(var i=0;i<inp.files.length;i++) fd.append('photos[]', inp.files[i]);
      inp.disabled = true;
      fetch(NGV_URL, {method:'POST', headers:{'X-CSRF-Token':CSRF}, credentials:'same-origin', body:fd})
        .then(function(r){ return r.json().catch(function(){ return {ok:false, error:'Upload rejected.'}; }); })
        .then(function(j){ inp.disabled = false; toast(j && j.ok ? 'Photo added ✓' : ((j && j.error) || 'Could not attach that')); if(j && j.ok) setTimeout(function(){ location.reload(); }, 700); })
        .catch(function(){ inp.disabled = false; toast('Offline — not attached'); });
    });
  });

  // Track picker (scoped to track tiles — the plan tiles below reuse .trk)
  document.querySelectorAll('.trk[data-track]').forEach(function(el){
    el.addEventListener('click', function(){
      var name = el.getAttribute('data-track');
      var wasOn = el.classList.contains('on');
      document.querySelectorAll('.trk[data-track]').forEach(function(x){ x.classList.remove('on'); });
      var val = wasOn ? '' : name;
      if(!wasOn) el.classList.add('on');
      save({track: val}, function(){
        var tile = document.getElementById('tileTrack');
        if(tile){ tile.textContent = val || '—'; }
        var tsub = tile && tile.nextElementSibling; if(tsub) tsub.textContent = val ? 'Locked in' : 'Pick one below';
      });
    });
  });

  // Plan picker — sets the training fee shown in "Your account". A reload
  // follows a change so the account figure and the fee line recompute server-
  // side (the money is computed there, never in the browser).
  document.querySelectorAll('.trk[data-plan]').forEach(function(el){
    el.addEventListener('click', function(){
      var name = el.getAttribute('data-plan');
      var wasOn = el.classList.contains('on');
      document.querySelectorAll('.trk[data-plan]').forEach(function(x){ x.classList.remove('on'); });
      var val = wasOn ? '' : name;
      if(!wasOn) el.classList.add('on');
      save({plan: val}, function(){ setTimeout(function(){ location.reload(); }, 500); });
    });
  });

  // 24-book challenge
  var books = document.getElementById('books');
  function bits(){ var s=''; document.querySelectorAll('.book').forEach(function(b){ s += b.classList.contains('on')?'1':'0'; }); return s; }
  function refreshBooks(){
    var n = bits().split('1').length - 1;
    var bar = document.getElementById('booksBar'); if(bar) bar.style.width = Math.round(n/BOOKS_TOTAL*100)+'%';
    ['booksLabel','tileBooks'].forEach(function(id){ var el=document.getElementById(id); if(el) el.textContent = n; });
  }
  if(books){
    var bt;
    books.addEventListener('click', function(ev){
      var b = ev.target.closest('.book'); if(!b) return;
      b.classList.toggle('on'); refreshBooks();
      clearTimeout(bt); bt = setTimeout(function(){ save({books: bits()}); }, 400); // debounce rapid taps
    });
  }

  // Focus note
  var note = document.getElementById('note');
  var noteCount = document.getElementById('noteCount');
  if(note && noteCount){ note.addEventListener('input', function(){ noteCount.textContent = note.value.length; }); }
  var noteSave = document.getElementById('noteSave');
  if(noteSave && note){ noteSave.addEventListener('click', function(){ save({note: note.value}); }); }

})();
</script>
<?php endif; ?>
