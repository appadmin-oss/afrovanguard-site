<?php
/**
 * portal/views/requests.php — Finance requests: a member asks CACENTRE finance for money.
 *
 * The request goes to CFIS (lib/CfisRequests.php) and joins the same approval
 * ladder as one typed at a finance desk; this site only says who asked and
 * what for. It used to be a card near the bottom of Today that showed no form
 * at all while finance was not connected; it is its own page now, in the
 * member's menu, and says plainly when finance is not switched on.
 *
 * A change to the member's own record is asked for in Membership
 * (views/membership.php, lib/MemberRequests.php), decided by an admin.
 *
 * Hook: #moneyRequests (portal/money-requests.js).
 */
declare(strict_types=1);
?>
      <section class="pview avp-view avp-requests" id="view-requests" data-view="requests" hidden aria-labelledby="avpReqH">
        <div class="avp-view-head"><h1 class="avp-h1" id="avpReqH">Finance requests</h1></div>
        <p class="avp-req-intro">Ask CACENTRE finance for money, under your own name: a claim for something you paid for, or something that needs buying. Finance decides, and you see where each request stands below. A change to your own record is asked for in Membership.</p>
        <div class="avp-stack">


        <!-- Asking finance for money. Not inside the dues block above:
             this is for every member, and a facilitator who paid a bus
             fare out of their own pocket is exactly who it is for. Until
             now they had to find somebody with a finance console login
             and ask them to type it in. -->
        <section class="avp-card" id="moneyRequests" data-csrf="<?= e(av_csrf_token()) ?>" aria-labelledby="avpMoneyReqH">
          <div class="pcard-head"><h2 id="avpMoneyReqH">Ask finance for money</h2></div>
          <div class="pcard-body">
            <p class="pcard-note" data-mr-max>A claim for something you paid for, or something that needs buying.</p>
            <p class="pcard-note" data-mr-down hidden>Finance cannot be reached just now, so requests cannot be sent. Nothing you have already sent is affected.</p>
            <p class="pcard-note" data-mr-off hidden>Asking finance for money is not switched on for this site yet. An administrator connects it to CACENTRE finance (CFIS) once; until then, ask your coordinator.</p>

            <form class="mr-form" data-mr-form novalidate hidden>
              <label class="mr-wide">What for <input name="title" maxlength="160" required placeholder="Bus fare to the Saturday session"></label>
              <label class="mr-wide">Why it is needed <textarea name="purpose" rows="2" required placeholder="Four facilitators travelling to the Ikeja centre"></textarea></label>
              <label>Kind of spending <select name="category" required></select></label>
              <label>Amount (₦) <input name="amount" type="number" min="100" step="1" required></label>

              <details data-mr-bank>
                <summary>Where to pay it back</summary>
                <p class="pcard-note">Asked once. Finance keeps it against your name for next time — a request that is approved and then cannot be paid helps nobody.</p>
                <label>Account number <input name="account_number" inputmode="numeric" maxlength="20"></label>
                <label>Bank <input name="bank_name" maxlength="60" placeholder="GTBank"></label>
                <label>Bank code <input name="bank_code" maxlength="10" placeholder="058"></label>
              </details>

              <button type="submit" class="pbtn pbtn-gold">Send to finance</button>
            </form>

            <p class="enroll-msg" data-mr-msg role="status" hidden></p>
            <div data-mr-list aria-live="polite"><p class="pc-empty">Loading…</p></div>
          </div>
        </section>

        </div>
      </section>
