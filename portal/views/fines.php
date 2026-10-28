<?php
/**
 * portal/views/fines.php — a member's own fines, for a member outside NGV.
 *
 * Any Afrovanguard member can be fined (lib/NgvFines.php). A vanguard sees
 * theirs under NextGen Vanguard → Fines and pays from their NGV account; a
 * member outside NGV sees theirs here, and pays at the office or by
 * transfer — the office records it against the fine, with the receipt, which
 * is read and checked before anything is credited (lib/OfflinePayments).
 * Shown only to a member who has had a fine.
 *
 * Expects $myFines (NgvFines::forMember) from portal/index.php.
 */
declare(strict_types=1);
$fineState = ['owing' => ['Owing', 'is-red'], 'part' => ['Part paid', 'is-gold'], 'settled' => ['Paid', 'is-green'], 'waived' => ['Waived', 'is-indigo'], 'voided' => ['Cancelled', '']];
?>
      <section class="pview avp-view avp-fines" id="view-fines" data-view="fines" hidden aria-labelledby="avpFinesH">
        <div class="avp-view-head"><h1 class="avp-h1" id="avpFinesH">Fines</h1></div>
        <p class="avp-fines-intro">Every fine the office has recorded for you — what for, the day, and where it stands.</p>
        <section class="avp-card">
          <div class="avp-card-head"><h2>Your fines</h2>
            <span class="avp-fines-total"><?= (int) $myFines['owing'] > 0 ? '₦' . number_format((int) $myFines['owing']) . ' still to pay' : 'Nothing owing' ?></span></div>
          <div class="avp-card-body">
            <ul class="avp-fines-list">
              <?php foreach ($myFines['fines'] as $fn): $st = $fineState[(string) $fn['status']] ?? ['', ''];
                /* The ledger's note leads with the reason; it is shown once. */
                $fnNote = (string) $fn['note']; $lead = (string) $fn['label'];
                if ($fnNote === $lead) $fnNote = ''; elseif (str_starts_with($fnNote, $lead . ' — ')) $fnNote = mb_substr($fnNote, mb_strlen($lead . ' — '));
                $fnNote = trim((string) preg_replace('/\s*\(\d{1,2} \w{3} \d{4}\)$/u', '', $fnNote)); ?>
              <li class="avp-fine">
                <div class="avp-fine-what"><b><?= e((string) $fn['label']) ?></b>
                  <span><?= e(date('D j M Y', (int) strtotime((string) $fn['day'] . 'T12:00:00'))) ?><?= $fnNote !== '' ? ' · ' . e($fnNote) : '' ?><?= (string) $fn['status'] === 'voided' && (string) $fn['void_reason'] !== '' ? ' · cancelled: ' . e((string) $fn['void_reason']) : '' ?></span></div>
                <span class="avp-fine-amt <?= e($st[1]) ?>"><?php
                  if ($fn['status'] === 'part') echo '₦' . number_format((int) $fn['owing']) . ' of ₦' . number_format((int) $fn['amount']) . ' left';
                  elseif (in_array($fn['status'], ['owing', 'settled'], true)) echo e($st[0]) . ' · ₦' . number_format((int) $fn['amount']);
                  else echo e($st[0]); ?></span>
              </li>
              <?php endforeach; ?>
            </ul>
            <?php if ((int) $myFines['owing'] > 0): ?>
            <p class="avp-fines-how">Pay at the office, or by transfer — then send the office the receipt, transfer slip or bank alert. It is checked, recorded against the fine, and you get a receipt by email. Think a fine is wrong? Tell the office: it can be waived or cancelled, and the reason stays on record.</p>
            <?php endif; ?>
          </div>
        </section>
      </section>
