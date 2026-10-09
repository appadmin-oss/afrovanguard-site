<?php
/**
 * portal/views/membership.php — Membership / Account (design: Afrovanguard
 * Portal v2, view "membership").
 *
 * Membership dues (org members with a dues record), the membership or account
 * details, "Your card" (portal/_card.php: the member ID card and its photo),
 * and the Continental Scaling Plan. Dues fee amounts are deliberately not
 * shown here — only the member's own total and status; the offline form
 * names the price because the member is about to pay it.
 *
 * Hooks kept for portal/dues-pay.js: #membership[data-csrf], [data-dues-pay],
 * .dues-msg, #duesOffline form, .dues-off-msg, .dues-off-list.
 */
declare(strict_types=1);
?>
      <section class="pview avp-view avp-membership" id="view-membership" data-view="membership" hidden aria-labelledby="avpMemH">
        <div class="avp-view-head"><h1 class="avp-h1" id="avpMemH"><?= $isOrg ? 'Membership' : 'Account' ?></h1></div>
        <div class="avp-stack">
<?php if ($isOrg && $dues):
    $duesPT   = $dues['paid_through'] ? date('j M Y', (int) strtotime((string) $dues['paid_through'])) : null;
    $duesPill = ['active' => 'Current', 'due_soon' => 'Due soon', 'overdue' => 'Overdue', 'none' => 'Not paid'][$duesState] ?? 'Dues';
    if (!empty($dues['lifetime'])) $duesPill = 'Lifetime';
    $duesTone = (!empty($dues['lifetime']) || $duesState === 'active') ? 'green' : ($duesState === 'overdue' ? 'red' : 'gold');
    $duesCanPay = !empty($dues['payable']) && empty($dues['lifetime']);
    $duesRecurring = defined('AV_DUES_PLAN_CODE') && AV_DUES_PLAN_CODE;
    $duesN = (int) ($dues['payments_count'] ?? 0);
?>
          <section class="avp-card avp-dues dues-<?= e($duesState) ?>" id="membership" data-csrf="<?= e($duesCsrf) ?>" aria-labelledby="avpDuesH">
            <div class="avp-card-head"><h2 id="avpDuesH">Membership dues</h2><span class="avp-chip avp-chip--<?= e($duesTone) ?>"><?= e($duesPill) ?></span></div>
            <div class="avp-card-body avp-dues-body">
<?php if ($duesTotal > 0): ?>
              <div class="avp-dues-total"><span>Total dues paid</span><strong class="av-num">₦<?= number_format($duesTotal) ?></strong><span class="avp-dues-n av-num">· <?= $duesN ?> payment<?= $duesN === 1 ? '' : 's' ?></span></div>
<?php endif; ?>
<?php if (!empty($dues['lifetime'])): ?>
              <p class="avp-dues-line is-ok">✓ <strong>Lifetime membership</strong> — no dues due.</p>
<?php elseif ($duesState === 'active'): ?>
              <p class="avp-dues-line is-ok">✓ Paid<?= $duesPT ? ' through ' . e($duesPT) : '' ?>.</p>
<?php elseif ($duesState === 'overdue'): ?>
              <p class="avp-dues-line is-warn">Lapsed<?= $duesPT ? ' on ' . e($duesPT) : '' ?> — please renew.</p>
<?php elseif ($duesState === 'due_soon'): ?>
              <p class="avp-dues-line is-warn">Renew soon to stay current.</p>
<?php endif; ?>
<?php if ($duesCanPay): ?>
              <div class="avp-row-btns">
                <button type="button" class="avp-btn<?= $duesState === 'active' ? '' : ' avp-btn--ink' ?>" data-dues-pay data-period="year"><?= $duesState === 'active' ? 'Renew a year' : 'Pay a year' ?></button>
                <button type="button" class="avp-btn" data-dues-pay data-period="month"><?= $duesRecurring ? 'Monthly' : 'Pay a month' ?></button>
              </div>
              <p class="avp-msg dues-msg" role="status" hidden></p>
<?php else: ?>
              <p class="avp-box">Card payment isn’t available yet — pay by transfer, cash or POS and send the receipt below.</p>
<?php endif; ?>
<?php if (empty($dues['lifetime'])): ?>
              <details class="avp-disclose" id="duesOffline">
                <summary>Paid by transfer, cash or POS? Send the receipt<span class="avp-disclose-i" aria-hidden="true">+</span></summary>
                <div class="avp-disclose-body">
                  <p class="avp-note">Your dues are credited once the receipt is checked — the amount, the date, and that it went to Afrovanguard. A receipt can be used once.</p>
                  <form class="avp-form dues-off-form" novalidate>
                    <label>For<select name="months"><option value="12" data-amount="<?= (int) OfflinePayments::duesPrice(12) ?>">A year — ₦<?= number_format(OfflinePayments::duesPrice(12)) ?></option><option value="1" data-amount="<?= (int) OfflinePayments::duesPrice(1) ?>">A month — ₦<?= number_format(OfflinePayments::duesPrice(1)) ?></option></select></label>
                    <label>Amount paid (₦)<input name="amount" type="number" min="1" required value="<?= (int) OfflinePayments::duesPrice(12) ?>"></label>
                    <label>How<select name="method"><option value="transfer">Bank transfer</option><option value="cash">Cash</option><option value="pos">POS</option><option value="deposit">Bank deposit</option></select></label>
                    <label>Day paid<input name="paid_on" type="date" max="<?= gmdate('Y-m-d') ?>"></label>
                    <label>Reference on the receipt (optional)<input name="reference" maxlength="80"></label>
                    <label>Receipt, slip or bank alert<input name="evidence" type="file" accept="image/*,application/pdf" required></label>
                    <button type="submit" class="avp-btn avp-btn--ink avp-form-wide">Send for checking</button>
                  </form>
                  <p class="avp-msg dues-off-msg" role="status" hidden></p>
                  <div class="avp-off-list dues-off-list" aria-live="polite"></div>
                </div>
              </details>
<?php endif; ?>
            </div>
          </section>
<?php endif; ?>

          <section class="avp-card"<?= ($isOrg && $dues) ? '' : ' id="membership"' ?> aria-labelledby="avpDetH">
            <div class="avp-card-head"><h2 id="avpDetH"><?= $isOrg ? 'Membership' : 'Account' ?></h2><span class="avp-chip avp-chip--<?= $isOrg ? 'green' : 'indigo' ?>"><?= $isOrg ? 'Active' : 'Learner' ?></span></div>
            <dl class="avp-dl">
              <div><dt>Name</dt><dd><?= e($u['name']) ?></dd></div>
              <div><dt>Email</dt><dd><?= e($u['email']) ?></dd></div>
<?php /* Recorded by the office (Studio → Members, or the NGV console), not here. */
      $bday = Birthdays::of((int) $u['id']); if ($bday): ?>
              <div><dt>Birthday</dt><dd><?= e(Birthdays::label($bday)) ?></dd></div>
<?php endif; ?>
<?php if ($isNgv): ?>
              <div><dt>Programme</dt><dd>NextGen Vanguard<?= $ngvVars['myTrack'] !== '' ? ' · ' . e((string) $ngvVars['myTrack']) : '' ?></dd></div>
<?php if (($ngvCard = GateAttendance::cardFor((int) $u['id'])) !== null): ?>
              <div><dt>NGV ID</dt><dd class="av-num"><?= e($ngvCard) ?></dd></div>
<?php endif; ?>
              <div><dt>NGV account</dt><dd><a href="#ngv-account" data-goto="ngv-account"><?= $ngvOwed > 0 ? '₦' . number_format($ngvOwed) . ' outstanding →' : 'All clear →' ?></a></dd></div>
<?php endif; ?>
<?php if ($hasGate): ?>
              <div><dt>CACENTRE gate</dt><dd><a href="#attendance" data-goto="attendance"><?= $gateWhy === '' ? 'Pass ready →' : 'Not allowed in — see why →' ?></a></dd></div>
<?php endif; ?>
              <div><dt><?= $isOrg ? 'Access' : 'Account' ?></dt><dd><?= $isOrg ? e($accessLevel) : 'Learner' ?></dd></div>
            </dl>
          </section>

<?php require AV_ROOT . '/portal/_card.php'; ?>

<?php if ($isOrg): ?>
          <section class="avp-plan" aria-labelledby="avpPlanH">
            <div class="avp-plan-head"><h2 id="avpPlanH">Continental Scaling Plan</h2><span class="avp-chip avp-chip--solid">Members only</span></div>
            <p>The 2026–2040 strategic plan to one million incorruptible C-Level leaders — the phase-by-phase CACENTRE growth path, output targets and org structure.</p>
            <a class="avp-btn avp-btn--paper" href="/blueprint">Read the plan →</a>
          </section>
<?php endif; ?>
        </div>
      </section>
