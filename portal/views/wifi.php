<?php /* portal/views/wifi.php — the member's Wi-Fi sign-in, which CACENTRE runs
   (lib/CacWifi.php, portal/wifi.js). Rendered inside the shell, which defines
   the variables it reads. */ ?>
        <!-- ============================================================ -->
        <!-- WI-FI — the member's own sign-in, from CACENTRE              -->
        <!-- ============================================================ -->
<?php if (CacSso::ready()): ?>
        <section class="pview" id="view-wifi" data-view="wifi" hidden data-csrf="<?= e($collabCsrf) ?>">
          <div class="view-head"><h1>Wi-Fi</h1></div>
          <section class="pcard" aria-labelledby="wifiH">
            <div class="pcard-head"><div>
              <h2 id="wifiH">Your Wi-Fi sign-in</h2>
              <span class="task-head-sub">CACENTRE runs the Wi-Fi at every site. Your sign-in name is your ID; the password is one you choose. This page opens on the Wi-Fi before you sign in.</span>
            </div></div>
            <div class="pcard-body">
              <p class="wifi-msg" id="wifiMsg" role="status" aria-live="polite">Loading your Wi-Fi details…</p>
              <dl class="wifi-dl" id="wifiDl" hidden>
                <div><dt>Username</dt><dd class="wifi-v" id="wifiUser"></dd></div>
                <div><dt>Password</dt><dd id="wifiPwState"></dd></div>
                <div id="wifiSpeedRow"><dt>Speed</dt><dd id="wifiSpeed"></dd></div>
                <div><dt>Where</dt><dd id="wifiWhere"></dd></div>
                <div><dt>Used today</dt><dd id="wifiToday"></dd></div>
                <div><dt>This month</dt><dd id="wifiMonth"></dd></div>
              </dl>
              <form class="wifi-foot" id="wifiFoot" hidden novalidate>
                <h3 class="wifi-h" id="wifiFormH">Set your Wi-Fi password</h3>
                <label class="wifi-lbl" for="wifiPw1">Password
                  <input class="wifi-inp" type="password" id="wifiPw1" name="password" minlength="8" maxlength="64" autocomplete="new-password" required aria-describedby="wifiRules"></label>
                <label class="wifi-lbl" for="wifiPw2">Type it again
                  <input class="wifi-inp" type="password" id="wifiPw2" name="again" minlength="8" maxlength="64" autocomplete="new-password" required></label>
                <p class="wifi-note" id="wifiRules">At least 8 characters, no spaces or semicolons, and not your sign-in name. Use it only for the Wi-Fi and never tell anyone: anything done on the Wi-Fi with it counts as you. Only you and the Wi-Fi team see how much you use, never which sites.</p>
                <p class="wifi-msg is-bad" id="wifiPwErr" role="alert" hidden></p>
                <div class="wifi-acts">
                  <button type="submit" class="pbtn pbtn-gold" id="wifiSave" aria-describedby="wifiLeft">Set password</button>
                  <span class="wifi-note" id="wifiLeft"></span>
                </div>
              </form>
              <button type="button" class="pbtn pbtn-ghost" id="wifiRetry" hidden>Try again</button>
            </div>
          </section>
        </section>
<?php endif; ?>
