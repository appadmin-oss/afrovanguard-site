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
              <span class="task-head-sub">CACENTRE runs the Wi-Fi at every site. Your sign-in name and PIN are yours alone.</span>
            </div></div>
            <div class="pcard-body">
              <p class="wifi-msg" id="wifiMsg" role="status" aria-live="polite">Loading your Wi-Fi details…</p>
              <dl class="wifi-dl" id="wifiDl" hidden>
                <div><dt>Username</dt><dd class="wifi-v" id="wifiUser"></dd></div>
                <div><dt>PIN</dt><dd class="wifi-v"><span id="wifiPin">••••••</span>
                  <button type="button" class="pbtn pbtn-ghost" id="wifiShow" aria-pressed="false">Show</button></dd></div>
                <div id="wifiSpeedRow"><dt>Speed</dt><dd id="wifiSpeed"></dd></div>
                <div><dt>Where</dt><dd id="wifiWhere"></dd></div>
                <div><dt>Used today</dt><dd id="wifiToday"></dd></div>
                <div><dt>This month</dt><dd id="wifiMonth"></dd></div>
              </dl>
              <div class="wifi-foot" id="wifiFoot" hidden>
                <p class="wifi-note">Your PIN is only for the Wi-Fi. Never tell anyone else your PIN — anything done on the Wi-Fi with it counts as you. Only you and the Wi-Fi team see what you use, never which sites.</p>
                <button type="button" class="pbtn pbtn-ghost" id="wifiNew" aria-describedby="wifiLeft">Get a new PIN</button>
                <span class="wifi-note" id="wifiLeft"></span>
              </div>
              <button type="button" class="pbtn pbtn-ghost" id="wifiRetry" hidden>Try again</button>
            </div>
          </section>
        </section>
<?php endif; ?>
