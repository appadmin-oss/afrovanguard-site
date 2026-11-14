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
            <p class="pcard-note" data-mr-down hidden>Finance cannot be reached just now. You can still ask: your request is kept and goes by itself as soon as finance answers.</p>
            <p class="pcard-note" data-mr-off hidden>Finance is not connected to this site yet. You can still ask: your request is kept, the office is told, and it goes to finance by itself once it is connected.</p>

            <form class="mr-form" data-mr-form novalidate hidden enctype="multipart/form-data">
              <fieldset class="mr-wide mr-kind">
                <legend>Have you already paid?</legend>
                <label><input type="radio" name="claim_kind" value="reimbursement" checked> Yes — I paid and want it back</label>
                <label><input type="radio" name="claim_kind" value="advance"> No — I need money for something</label>
              </fieldset>
              <label class="mr-wide">What for <input name="title" maxlength="160" required placeholder="Bus fare to the Saturday session"></label>
              <label class="mr-wide">Why it is needed <textarea name="purpose" rows="2" required placeholder="Four facilitators travelling to the Ikeja centre"></textarea></label>
              <label data-mr-single>Kind of spending <select name="category" required></select>
                <span class="pcard-note" data-mr-hint></span></label>
              <label data-mr-single>Amount (₦) <input name="amount" type="number" min="100" step="1" required></label>

              <!-- Several things in one request: drawn by the script, which
                   turns the one line above into a list. Without script the
                   one line posts as it always has. -->
              <fieldset class="mr-wide mr-lines" data-mr-lines hidden>
                <legend>What it is made up of</legend>
                <div data-mr-line-rows></div>
                <p class="mr-total" data-mr-total aria-live="polite"></p>
              </fieldset>
              <p class="mr-wide" data-mr-addline-wrap hidden><button type="button" class="pbtn pbtn-ghost" data-mr-addline>+ Add another line</button></p>

              <label>Needed by <span class="pcard-note">optional</span>
                <input name="needed_by" type="date" min="<?= e(gmdate('Y-m-d')) ?>"></label>

              <label class="mr-wide">Receipt <span class="pcard-note">a photo or PDF, up to three</span>
                <input type="file" name="receipts[]" multiple accept="image/jpeg,image/png,image/webp,image/gif,application/pdf"></label>
              <label class="mr-wide" data-mr-noreceipt>No receipt? Say why <span class="pcard-note" data-mr-receipt-rule>Asked for money already spent over ₦5,000.</span>
                <input name="no_receipt_reason" maxlength="500" placeholder="Prepaid units bought by USSD; no paper receipt"></label>

              <details data-mr-bank>
                <summary>Where to pay it back</summary>
                <p class="pcard-note">Asked once. Finance keeps it against your name for next time — a request that is approved and then cannot be paid helps nobody.</p>
                <label>Account number <input name="account_number" inputmode="numeric" maxlength="20" autocomplete="off"></label>
                <!-- Finance's list of banks, when it has one; the two boxes
                     below are then filled from it and hidden. -->
                <label data-mr-bankpick-wrap hidden>Bank <select data-mr-bankpick><option value="">Choose your bank</option></select></label>
                <label data-mr-bankfree>Bank <input name="bank_name" maxlength="60" placeholder="GTBank"></label>
                <label data-mr-bankfree>Bank code <input name="bank_code" maxlength="10" placeholder="058"></label>
                <p class="pcard-note mr-acct" data-mr-acct role="status" aria-live="polite" hidden></p>
              </details>

              <button type="submit" class="pbtn pbtn-gold">Send to finance</button>
            </form>

            <p class="enroll-msg" data-mr-msg role="status" hidden></p>
            <div data-mr-list aria-live="polite"><p class="pc-empty">Loading…</p></div>
            <!-- What this member asked finance for from another device or
                 account: finance's list, by their email (CFIS ADR 58). -->
            <section class="mr-earlier" data-mr-earlier aria-labelledby="avpMrEarlierH" hidden>
              <h3 id="avpMrEarlierH">Earlier requests</h3>
              <p class="pcard-note">Asked from another device or account. Finance's record, by your email.</p>
              <div data-mr-earlier-list></div>
            </section>
          </div>
        </section>

        </div>
      </section>
