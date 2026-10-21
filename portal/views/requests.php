<?php
/**
 * portal/views/requests.php — Requests: everything a member asks for, in one place.
 *
 * Two kinds, decided by different people:
 *   · a change to their own record — another centre, corrected details,
 *     pausing or leaving, anything else (lib/MemberRequests.php). An admin
 *     decides in the Studio → Members → Approvals.
 *   · money from CACENTRE finance (lib/CfisRequests.php). Finance decides,
 *     in CFIS; this site only says who asked and what for.
 *
 * Asking for these used to be either impossible (record changes) or a card
 * near the bottom of Today that showed no form at all while finance was not
 * connected. Both live here, under the member's own name.
 *
 * Hooks: #memberRequests (portal/member-requests.js), #moneyRequests
 * (portal/money-requests.js).
 */
declare(strict_types=1);
$mreqCentres = class_exists('Centres') ? Centres::names() : [];
$mreqMe = class_exists('MemberRoster') ? MemberRoster::get((int) $u['id']) : null;
?>
      <section class="pview avp-view avp-requests" id="view-requests" data-view="requests" hidden aria-labelledby="avpReqH">
        <div class="avp-view-head"><h1 class="avp-h1" id="avpReqH">Requests</h1></div>
        <p class="avp-req-intro">Ask from here, under your own name. A change to your record is decided by an admin; money is decided by CACENTRE finance. You see each answer below, and by email.</p>
        <div class="avp-stack">

        <section class="avp-card" id="memberRequests" data-csrf="<?= e(av_csrf_token()) ?>" aria-labelledby="avpMreqH">
          <div class="pcard-head"><h2 id="avpMreqH">Ask for a change to your record</h2></div>
          <div class="pcard-body">
            <form class="mr-form" data-mq-form novalidate>
              <label class="mr-wide">What are you asking for
                <select name="kind" data-mq-kind required>
<?php foreach (MemberRequests::KINDS as $k => $label): ?>
                  <option value="<?= e($k) ?>"><?= e($label) ?></option>
<?php endforeach; ?>
                </select>
              </label>
              <label class="mr-wide" data-mq-for="centre">Which centre
                <select name="centre">
                  <option value="">Choose a centre</option>
<?php foreach ($mreqCentres as $c): if ($mreqMe && mb_strtolower($c) === mb_strtolower((string) $mreqMe['centre'])) continue; ?>
                  <option value="<?= e($c) ?>"><?= e($c) ?></option>
<?php endforeach; ?>
                </select>
              </label>
              <p class="pcard-note" data-mq-for="centre"><?= $mreqMe && (string) $mreqMe['centre'] !== '' ? 'You are at ' . e((string) $mreqMe['centre']) . ' now.' : 'You are not at a centre yet.' ?></p>
              <label data-mq-for="details" hidden>Your name, as it should be <input name="name" maxlength="120" value="<?= e((string) ($mreqMe['name'] ?? '')) ?>" autocomplete="name"></label>
              <label data-mq-for="details" hidden>Your phone <input name="phone" inputmode="tel" maxlength="24" value="<?= e((string) ($mreqMe['phone'] ?? '')) ?>" autocomplete="tel"></label>
              <label class="mr-wide">Anything an admin should know <textarea name="message" rows="2" maxlength="1000" data-mq-message placeholder="I moved house and the Ayobo centre is nearer."></textarea></label>
              <button type="submit" class="pbtn pbtn-gold">Send to an admin</button>
            </form>
            <p class="enroll-msg" data-mq-msg role="status" hidden></p>
            <h3 class="avp-req-h3">Your requests</h3>
            <div data-mq-list aria-live="polite"><p class="pc-empty">Loading…</p></div>
          </div>
        </section>

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
