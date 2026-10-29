<?php
/**
 * portal/views/wallet.php — the member's wallet: one statement of their money.
 *
 * Draws lib/MemberStatement.php and nothing else. It decides nothing and
 * writes nothing: every line is a row Membership, NgvLedger or OfflinePayments
 * already holds, and those stay the places money is paid and recorded. This
 * page only puts them on one page, so "what do I owe, and what have I paid?"
 * has an answer.
 *
 *   counted       charges and the money that arrived for them
 *   not counted   money the member reported sending that the office has not
 *                 confirmed: shown, so they can see it is known about, and
 *                 left out of the balance until it is confirmed
 *   not all of it a ledger that could not be read is named, and the balance
 *                 says it may be incomplete rather than look smaller than the
 *                 truth
 *
 * Expects $myStatement (MemberStatement::for) from portal/index.php.
 */
declare(strict_types=1);

$wS       = $myStatement;
$wMoney   = static fn(int $k): string => MemberStatement::naira($k);
$wWhere   = ['dues' => 'Membership dues', 'ngv' => 'NextGen Vanguard', 'offline' => 'Reported payment'];
$wMissing = ['dues' => 'your membership dues', 'ngv' => 'your NextGen Vanguard fees', 'offline' => 'payments you reported'];
$wDay     = static function (string $at): string {
    $t = strtotime($at);
    return $t ? date('j M Y', $t) : '';
};
$wBal     = (int) $wS['balance_kobo'];
$wRows    = array_reverse($wS['entries']);   /* newest first: the line just paid is the one looked for */
?>
      <section class="pview avp-view avp-wallet" id="view-wallet" data-view="wallet" hidden aria-labelledby="avpWalletH">
        <div class="avp-view-head"><h1 class="avp-h1" id="avpWalletH">Wallet</h1></div>
        <p class="avp-wallet-intro">Everything charged to you and everything you have paid, from every part of Afrovanguard, on one page.</p>

<?php if (!$wS['complete']): ?>
        <p class="avp-wallet-warn" role="alert">We could not read <?= e(implode(' or ', array_map(static fn($s) => $wMissing[$s] ?? $s, $wS['problems']))) ?> just now, so this is not all of it, and the balance below may be wrong. <?php /* A fresh query each render, so the link always reloads the page rather than only moving the hash. */ ?><a href="/portal/?r=<?= time() ?>#wallet">Try again</a></p>
<?php endif; ?>

        <div class="avp-kpis" aria-label="Your money at a glance">
          <div class="avp-kpi">
            <div class="avp-kpi-top"><span class="avp-kpi-l">Balance</span><?php if ($wBal > 0): ?><span class="avp-chip avp-chip--sm avp-chip--gold">To pay</span><?php elseif ($wBal < 0): ?><span class="avp-chip avp-chip--sm avp-chip--green">In credit</span><?php endif; ?></div>
            <div class="avp-kpi-v av-num"><?= e($wMoney(abs($wBal))) ?></div>
            <div class="avp-kpi-s"><?= $wBal > 0 ? 'You owe this.' : ($wBal < 0 ? 'You have paid this much more than has been charged.' : 'You owe nothing.') ?><?= $wS['complete'] ? '' : ' Possibly incomplete.' ?></div>
          </div>
          <div class="avp-kpi">
            <div class="avp-kpi-top"><span class="avp-kpi-l">Paid</span></div>
            <div class="avp-kpi-v av-num"><?= e($wMoney((int) $wS['paid_kobo'])) ?></div>
            <div class="avp-kpi-s">Of <?= e($wMoney((int) $wS['charged_kobo'])) ?> charged.</div>
          </div>
<?php if ((int) $wS['pending_kobo'] > 0): ?>
          <div class="avp-kpi">
            <div class="avp-kpi-top"><span class="avp-kpi-l">Waiting to be confirmed</span><span class="avp-chip avp-chip--sm avp-chip--indigo">Not counted yet</span></div>
            <div class="avp-kpi-v av-num"><?= e($wMoney((int) $wS['pending_kobo'])) ?></div>
            <div class="avp-kpi-s">The office checks the receipt, then it counts.</div>
          </div>
<?php endif; ?>
        </div>

        <section class="avp-card" aria-labelledby="avpWalletListH">
          <div class="avp-card-head"><h2 id="avpWalletListH">Statement</h2><span class="avp-wallet-count"><?= count($wRows) ?> <?= count($wRows) === 1 ? 'entry' : 'entries' ?></span></div>
          <div class="avp-card-body">
<?php if (!$wRows): ?>
            <p class="pc-empty"><?= $wS['complete'] ? 'Nothing has been charged to you or paid by you yet.' : 'Nothing could be shown. Try again in a minute.' ?></p>
<?php else: ?>
            <ul class="avp-wallet-list">
<?php foreach ($wRows as $w):
        $charge = (int) $w['charge_kobo']; $credit = (int) $w['credit_kobo'];
        /* Dues are a standing, not an invoice: charged and paid at once, so
           they are shown as paid and move no balance. */
        $both   = $charge > 0 && $credit > 0;
        if (!empty($w['pending']))  { $amt = $wMoney($credit); $tone = 'is-indigo'; $sr = 'reported, not counted yet'; }
        elseif ($both)              { $amt = 'Paid ' . $wMoney($credit); $tone = 'is-green'; $sr = ''; }
        elseif ($credit > 0)        { $amt = '−' . $wMoney($credit); $tone = 'is-green'; $sr = 'paid'; }
        else                        { $amt = '+' . $wMoney($charge); $tone = 'is-gold'; $sr = 'charged'; }
        $meta = array_filter([$wWhere[(string) $w['source']] ?? '', $wDay((string) $w['at']), (string) $w['method'] !== '' && !str_contains((string) $w['label'], (string) $w['method']) ? (string) $w['method'] : '']); ?>
              <li class="avp-wallet-row<?= !empty($w['pending']) ? ' is-pending' : '' ?>">
                <div class="avp-wallet-what"><b><?= e((string) $w['label']) ?></b><span><?= e(implode(' · ', $meta)) ?></span></div>
                <div class="avp-wallet-amts">
                  <span class="avp-wallet-amt <?= e($tone) ?> av-num"><?= e($amt) ?><?php if ($sr !== ''): ?><span class="avp-wallet-sr"> (<?= e($sr) ?>)</span><?php endif; ?></span>
                  <span class="avp-wallet-run av-num"><?= !empty($w['pending']) ? 'Not counted yet' : 'Balance ' . e($wMoney((int) $w['balance_kobo'])) ?></span>
                </div>
              </li>
<?php endforeach; ?>
            </ul>
<?php endif; ?>
<?php if ($wBal > 0): ?>
            <p class="avp-wallet-how"><?php if (in_array('ngv', $wS['sources'], true)): ?>Pay NextGen Vanguard fees from <a href="#ngv-account">Fees &amp; account</a>. <?php endif; ?>You can also pay at the office or by transfer, then send the office the receipt or bank alert: it is checked, recorded, and appears here.</p>
<?php endif; ?>
          </div>
        </section>
      </section>
