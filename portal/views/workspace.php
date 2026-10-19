<?php /* portal/views/workspace.php — moved verbatim from portal/index.php v1 (row 14 shell
   rebuild). Markup and ids unchanged; restyled through portal.css + avp.css tokens.
   Rendered inside the shell, which defines the variables it reads. */
        require_once AV_ROOT . '/lib/workspace.php';
        $wsAdmin    = LmsAuth::rank((string) $u['role']) >= LmsAuth::ROLE_RANK['admin'];
        $wsSurfaces = av_workspace_surfaces($wsAdmin);
        $wsOauth    = class_exists('GoogleWorkspaceUser') && GoogleWorkspaceUser::configured();
        $wsConn     = $wsOauth && GoogleWorkspaceUser::connected((int) $u['id']);
?>
        <!-- ============================================================ -->
        <!-- WORKSPACE                                                    -->
        <!-- ============================================================ -->
        <section class="pview" id="view-workspace" data-view="workspace" hidden>
          <div class="view-head"><h1>Workspace</h1><a class="pcard-link" href="/workspace">Open all →</a></div>

<?php if ($wsOauth && !$wsConn): ?>
          <!-- Not connected → clear call to action -->
          <section class="pcard ws-connect-card">
            <div class="pcard-body">
              <h2>Connect your Google Workspace</h2>
              <p class="pcard-note">Bring your Gmail, Calendar and Drive into the portal. You’ll sign in with Google once and can disconnect anytime.</p>
              <a class="pbtn pbtn-gold" href="/auth/google/connect?next=<?= rawurlencode('/portal/#workspace') ?>">Connect Google →</a>
            </div>
          </section>
<?php elseif ($wsConn): ?>
          <!-- Connected → live snapshot (fetched from /portal/workspace.php?action=me) -->
          <section class="pcard" id="wsLive" data-live>
            <div class="pcard-head"><h2>Your Google Workspace</h2><span class="pchip pchip--green"><span class="dot-live"></span>Connected</span></div>
            <div class="pcard-body">
              <div class="pkpis ws-live-kpis">
                <div class="pkpi"><div class="pkpi-top"><span class="pkpi-label">Unread mail</span></div><div class="pkpi-value" id="wsUnread">—</div><div class="pkpi-sub">in your inbox</div></div>
                <div class="pkpi"><div class="pkpi-top"><span class="pkpi-label">Next event</span></div><div class="pkpi-value" id="wsNextC" style="font-size:16px;line-height:1.3">—</div><div class="pkpi-sub" id="wsNextW"></div></div>
                <div class="pkpi"><div class="pkpi-top"><span class="pkpi-label">Recent files</span></div><div class="pkpi-value" id="wsFiles">—</div><div class="pkpi-sub">in your Drive</div></div>
              </div>
              <div class="ws-live-cols">
                <div><h3 class="ws-live-h">Recent mail</h3><ul class="ws-live-list" id="wsMail"><li class="pc-empty">Loading…</li></ul></div>
                <div><h3 class="ws-live-h">Upcoming</h3><ul class="ws-live-list" id="wsEvents"><li class="pc-empty">Loading…</li></ul></div>
              </div>
            </div>
          </section>

          <!-- Team Chat — the native, in-portal chat (replaces the old Google Chat tile) -->
          <section class="pcard ws-teamchat-card">
            <div class="pcard-head"><h2>Team Chat</h2><span class="pchip pchip--green"><span class="dot-live"></span>Live · in-portal</span></div>
            <div class="pcard-body">
              <p class="pcard-note">Your team's real-time chat lives right here in the portal — channels, threads, @mentions, reactions, saved items and AI catch-up. Admins can mirror any channel into a Google Chat space, posted as the author.</p>
              <div class="pws-inline-actions">
                <a class="pbtn pbtn-gold" href="#chat" data-goto="chat">Open Team Chat →</a>
                <a class="pbtn pbtn-ghost" href="https://chat.google.com/" target="_blank" rel="noopener noreferrer">Google Chat ↗</a>
              </div>
            </div>
          </section>
<?php else: /* Google OAuth not configured on this deployment — be honest about why nothing fetches */ ?>
          <section class="pcard ws-connect-card">
            <div class="pcard-body">
              <h2>Live Google Workspace isn’t enabled yet</h2>
              <p class="pcard-note">Your Gmail, Calendar and Drive can appear here live — but this site first needs its Google Workspace connection switched on by an administrator (the Google OAuth credentials). Until then, use the app launchpad below to jump straight into each tool.</p>
              <a class="pbtn pbtn-soft" href="/workspace">Open the Workspace hub →</a>
            </div>
          </section>
<?php endif; ?>

          <section class="pcard">
            <div class="pcard-head">
              <div><h2>Your Workspace apps</h2><p class="pcard-sub">Signed in via Google · @<?= e(av_workspace_domain()) ?></p></div>
            </div>
            <div class="pcard-body pws-grid">
<?php foreach ($wsSurfaces as $s): $wsInt = !empty($s['internal']); ?>              <a class="pws-app" href="<?= e($s['url']) ?>"<?= $wsInt ? ' data-goto="chat"' : ' target="_blank" rel="noopener noreferrer"' ?>>
                <span class="pws-ico pws-ico--<?= e($s['key']) ?>"><?= av_workspace_icon($s['icon']) ?></span>
                <span class="pws-text"><span class="pws-name"><?= e($s['label']) ?></span><span class="pws-desc"><?= e($s['desc']) ?></span><?= $wsInt ? '' : '' ?></span>
              </a>
<?php endforeach; ?>            </div>
          </section>
        </section>
