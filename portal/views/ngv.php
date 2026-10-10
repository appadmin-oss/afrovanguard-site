<?php /* portal/views/ngv.php — moved verbatim from portal/index.php v1 (row 14 shell
   rebuild). Markup and ids unchanged; restyled through portal.css + avp.css tokens.
   Rendered inside the shell, which defines the variables it reads. */ ?>
<?php
        /* The programme dashboard's cards, drawn into portal views — once each,
           the script once, with the portal's own headings in place of the
           standalone page's greeting and tiles (those are on Today). */
        $ngvRender = static function (array $v, array $parts, bool $script): void {
            (static function (array $__v): void { extract($__v); require AV_ROOT . '/academy/ngv/_dashboard-body.php'; })(
                $v + ['ngvParts' => $parts, 'ngvStandalone' => false, 'ngvBanner' => $parts[0] === 'track', 'ngvScript' => $script, 'ngvAccountHref' => '#ngv-account']);
        };
?>
        <section class="pview" id="view-ngv" data-view="ngv" hidden>
          <div class="view-head">
            <div><h1>Programme</h1><p class="view-sub">Your NextGen Vanguard track and plan, the 24-book challenge, certifications and the schedule. Everything saves as you go.</p></div>
            <a class="pbtn pbtn-ghost" href="/academy/ngv/" target="_blank" rel="noopener">Programme page ↗</a>
          </div>
<?php $ngvRender($ngvVars, ['track', 'focus', 'reading', 'certs', 'schedule', 'support'], false); ?>
        </section>
        <section class="pview" id="view-ngv-account" data-view="ngv-account" hidden>
          <div class="view-head">
            <div><h1>Fees &amp; account</h1><p class="view-sub">What the programme has charged and what you have paid, your receipts, and anything reported as damaged. Nothing here can be changed from your side — ask, and a person answers.</p></div>
          </div>
<?php $ngvRender($ngvVars, ['account', 'damage'], true); ?>
        </section>
        <section class="pview" id="view-ngv-fines" data-view="ngv-fines" hidden>
          <div class="view-head">
            <div><h1>Fines</h1><p class="view-sub">Every fine the NGV office has recorded for you — what for, the day, and where it stands. A fine is paid from Fees &amp; account, like any other fee.</p></div>
          </div>
<?php $ngvRender($ngvVars, ['fines'], false); ?>
        </section>
