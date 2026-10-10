<?php /* portal/views/chat.php — moved verbatim from portal/index.php v1 (row 14 shell
   rebuild). Markup and ids unchanged; restyled through portal.css + avp.css tokens.
   Rendered inside the shell, which defines the variables it reads. */ ?>
        <!-- ============================================================ -->
        <!-- TEAM CHAT  (Slack-style native channels — @afrovanguard only)-->
        <!-- ============================================================ -->
        <section class="pview" id="view-chat" data-view="chat" hidden>
          <div class="view-head">
            <div><h1>Team Chat</h1><p class="view-sub">Talk to the team in real time. Channels, @mentions and reactions — always on, no app to open.</p></div>
          </div>
          <section class="pcard tc-card" id="teamChat" data-csrf="<?= e($collabCsrf) ?>" data-me="<?= e($pInitials) ?>">
            <div class="tc">
              <!-- Channel rail -->
              <aside class="tc-rail" aria-label="Channels">
                <div class="tc-search">
                  <svg class="tc-search-ico" width="15" height="15" viewBox="0 0 24 24" fill="none"><circle cx="11" cy="11" r="7" stroke="currentColor" stroke-width="1.9"/><path d="m20 20-3.5-3.5" stroke="currentColor" stroke-width="1.9" stroke-linecap="round"/></svg>
                  <input type="search" id="tcSearch" placeholder="Search messages…" aria-label="Search messages" autocomplete="off">
                  <div class="tc-search-results" id="tcSearchResults" hidden></div>
                </div>
                <div class="tc-rail-h">Channels<button type="button" class="tc-addch" id="tcAddChannel" title="New channel" aria-label="New channel" hidden>+</button></div>
                <ul class="tc-channels" id="tcChannels"><li class="pc-empty">Loading…</li></ul>
                <button type="button" class="tc-saved-btn" id="tcSavedBtn">
                  <svg width="14" height="14" viewBox="0 0 24 24" fill="none"><path d="M6 4h12v16l-6-4-6 4V4Z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/></svg>
                  <span>Saved items</span>
                </button>
                <button type="button" class="tc-catchup" id="tcCatchup" hidden>
                  <svg width="15" height="15" viewBox="0 0 24 24" fill="none"><path d="M12 2.5 14 8.5 20 10 14 11.5 12 17.5 10 11.5 4 10 10 8.5 12 2.5Z" fill="currentColor"/></svg>
                  <span>Catch me up</span>
                </button>
              </aside>
              <!-- Conversation -->
              <div class="tc-main">
                <div class="tc-topbar">
                  <span class="tc-ch-ico">#</span>
                  <div class="tc-ch-meta">
                    <div class="tc-ch-title"><b id="tcChannelName">general</b><span class="tc-ch-count" id="tcMemberCount"></span></div>
                    <div class="tc-ch-topic" id="tcTopic"></div>
                  </div>
                  <button type="button" class="tc-pinbtn" id="tcPinBtn" hidden aria-label="Pinned messages">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none"><path d="M9 4h6l-1 6 4 3v2H6v-2l4-3-1-6Z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/><path d="M12 17v3" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
                    <span id="tcPinCount">0</span>
                  </button>
                  <button type="button" class="tc-pinbtn tc-chset" id="tcChannelSettings" hidden aria-label="Channel settings" title="Channel settings">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="3" stroke="currentColor" stroke-width="1.8"/><path d="M12 2v3M12 19v3M4.2 4.2l2.1 2.1M17.7 17.7l2.1 2.1M2 12h3M19 12h3M4.2 19.8l2.1-2.1M17.7 6.3l2.1-2.1" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
                  </button>
                  <span class="tc-presence"><span class="dot-live"></span><span id="tcPresence">0</span> online</span>
                </div>
                <div class="tc-pinned" id="tcPinned" hidden></div>
                <div class="tc-stream" id="tcStream"><p class="pc-empty">Loading messages…</p></div>
                <div class="tc-typing" id="tcTyping" hidden></div>
                <form class="tc-compose tc-compose--float" id="tcCompose" autocomplete="off">
                  <div class="tc-mentions" id="tcMentions" hidden></div>
                  <div class="tc-editbar" id="tcEditBar" hidden><svg width="13" height="13" viewBox="0 0 24 24" fill="none"><path d="M4 20h4l10-10-4-4L4 16v4Z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/></svg> Editing message <button type="button" class="tc-editcancel" id="tcEditCancel">cancel</button></div>
                  <div class="tc-rte" id="tcInput" contenteditable="true" role="textbox" aria-multiline="true" aria-label="Message" data-ph="Message #general — use @ to mention"></div>
                  <div class="tc-compose-bar">
                    <div class="tc-fmtbar" role="toolbar" aria-label="Text formatting">
                      <button type="button" class="tc-fmt" data-cmd="bold" title="Bold — Ctrl+B" aria-label="Bold"><b>B</b></button>
                      <button type="button" class="tc-fmt" data-cmd="italic" title="Italic — Ctrl+I" aria-label="Italic"><i>I</i></button>
                      <button type="button" class="tc-fmt" data-cmd="strike" title="Strikethrough" aria-label="Strikethrough"><s>S</s></button>
                      <span class="tc-fmtsep" aria-hidden="true"></span>
                      <button type="button" class="tc-fmt" data-cmd="ul" title="Bulleted list" aria-label="Bulleted list"><svg width="17" height="17" viewBox="0 0 24 24" fill="none"><path d="M8 6h13M8 12h13M8 18h13" stroke="currentColor" stroke-width="1.9" stroke-linecap="round"/><circle cx="3.6" cy="6" r="1.4" fill="currentColor"/><circle cx="3.6" cy="12" r="1.4" fill="currentColor"/><circle cx="3.6" cy="18" r="1.4" fill="currentColor"/></svg></button>
                      <button type="button" class="tc-fmt" data-cmd="ol" title="Numbered list" aria-label="Numbered list"><svg width="17" height="17" viewBox="0 0 24 24" fill="none"><path d="M10 6h11M10 12h11M10 18h11" stroke="currentColor" stroke-width="1.9" stroke-linecap="round"/><text x="2" y="8.5" font-size="8" font-weight="700" fill="currentColor">1</text><text x="2" y="14.5" font-size="8" font-weight="700" fill="currentColor">2</text><text x="2" y="20.5" font-size="8" font-weight="700" fill="currentColor">3</text></svg></button>
                      <button type="button" class="tc-fmt" data-cmd="quote" title="Quote" aria-label="Quote"><svg width="17" height="17" viewBox="0 0 24 24" fill="none"><path d="M6 5v14M10 8h8M10 12h8M10 16h5" stroke="currentColor" stroke-width="1.9" stroke-linecap="round"/></svg></button>
                      <span class="tc-fmtsep" aria-hidden="true"></span>
                      <button type="button" class="tc-fmt" data-cmd="code" title="Inline code" aria-label="Inline code"><svg width="17" height="17" viewBox="0 0 24 24" fill="none"><path d="M9 8 5 12l4 4M15 8l4 4-4 4" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"/></svg></button>
                      <button type="button" class="tc-fmt" data-cmd="codeblock" title="Code block" aria-label="Code block"><svg width="17" height="17" viewBox="0 0 24 24" fill="none"><rect x="3" y="4" width="18" height="16" rx="2.5" stroke="currentColor" stroke-width="1.7"/><path d="M9 9 7 12l2 3M15 9l2 3-2 3" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg></button>
                      <button type="button" class="tc-fmt" data-cmd="link" title="Link" aria-label="Link"><svg width="17" height="17" viewBox="0 0 24 24" fill="none"><path d="M10 13a5 5 0 0 0 7 0l2-2a5 5 0 0 0-7-7l-1 1M14 11a5 5 0 0 0-7 0l-2 2a5 5 0 0 0 7 7l1-1" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg></button>
                    </div>
                    <button type="submit" class="pbtn pbtn-gold tc-send" aria-label="Send message"><span>Send</span><svg width="15" height="15" viewBox="0 0 24 24" fill="none"><path d="M4 12h15M13 6l6 6-6 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg></button>
                  </div>
                </form>
                <!-- Thread drawer -->
                <div class="tc-thread" id="tcThread" hidden aria-label="Thread">
                  <div class="tc-thread-h"><span>Thread</span><button type="button" class="pm-x" id="tcThreadClose" aria-label="Close thread">✕</button></div>
                  <div class="tc-thread-body" id="tcThreadBody"></div>
                  <form class="tc-compose tc-thread-compose" id="tcThreadForm" autocomplete="off">
                    <div class="tc-mentions" id="tcThreadMentions" hidden></div>
                    <div class="tc-rte tc-rte--sm" id="tcThreadInput" contenteditable="true" role="textbox" aria-multiline="true" aria-label="Reply" data-ph="Reply… use @ to mention"></div>
                    <div class="tc-compose-bar">
                      <div class="tc-fmtbar" role="toolbar" aria-label="Text formatting">
                        <button type="button" class="tc-fmt" data-cmd="bold" title="Bold — Ctrl+B" aria-label="Bold"><b>B</b></button>
                        <button type="button" class="tc-fmt" data-cmd="italic" title="Italic — Ctrl+I" aria-label="Italic"><i>I</i></button>
                        <button type="button" class="tc-fmt" data-cmd="code" title="Inline code" aria-label="Inline code"><svg width="16" height="16" viewBox="0 0 24 24" fill="none"><path d="M9 8 5 12l4 4M15 8l4 4-4 4" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"/></svg></button>
                        <button type="button" class="tc-fmt" data-cmd="link" title="Link" aria-label="Link"><svg width="16" height="16" viewBox="0 0 24 24" fill="none"><path d="M10 13a5 5 0 0 0 7 0l2-2a5 5 0 0 0-7-7l-1 1M14 11a5 5 0 0 0-7 0l-2 2a5 5 0 0 0 7 7l1-1" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg></button>
                      </div>
                      <button type="submit" class="pbtn pbtn-gold tc-send">Reply</button>
                    </div>
                  </form>
                </div>
              </div>
              <!-- Members rail -->
              <aside class="tc-members" id="tcMembers" aria-label="Members"><p class="pc-empty">Loading…</p></aside>
            </div>
          </section>

          <!-- Catch-me-up recap modal -->
          <div class="pm-scrim" id="recapScrim" hidden>
            <div class="pm-modal" role="dialog" aria-modal="true" aria-labelledby="recapTitle">
              <div class="pm-head"><h2 id="recapTitle">✨ Catch me up</h2><button type="button" class="pm-x" id="recapClose" aria-label="Close">✕</button></div>
              <div class="pm-body"><div class="tc-recap" id="recapBody"><p class="pc-empty">Summarising the channel…</p></div></div>
            </div>
          </div>

          <!-- Saved items modal -->
          <div class="pm-scrim" id="savedScrim" hidden>
            <div class="pm-modal" role="dialog" aria-modal="true" aria-labelledby="savedTitle">
              <div class="pm-head"><h2 id="savedTitle">🔖 Saved items</h2><button type="button" class="pm-x" id="savedClose" aria-label="Close">✕</button></div>
              <div class="pm-body"><div class="tc-saved" id="savedBody"><p class="pc-empty">Loading…</p></div></div>
            </div>
          </div>

          <!-- Channel editor modal (admins) -->
          <div class="pm-scrim" id="chanScrim" hidden>
            <div class="pm-modal" role="dialog" aria-modal="true" aria-labelledby="chanTitle">
              <div class="pm-head"><h2 id="chanTitle">New channel</h2><button type="button" class="pm-x" id="chanClose" aria-label="Close">✕</button></div>
              <div class="pm-body">
                <form id="chanForm" autocomplete="off">
                  <input type="hidden" id="chanKey" value="">
                  <label class="pm-label">Name<input type="text" id="chanLabel" maxlength="60" placeholder="e.g. Coding practice" required></label>
                  <label class="pm-label">Topic<input type="text" id="chanTopic" maxlength="200" placeholder="What this channel is for"></label>
                  <label class="pm-check"><input type="checkbox" id="chanPrivate"> <span>Private — only chosen members can see it</span></label>
                  <div class="tc-memberpick" id="chanMemberPick" hidden>
                    <div class="pm-label" style="margin:0 0 4px">Members</div>
                    <div class="tc-memberpick-list" id="chanMemberList"></div>
                  </div>
                  <label class="pm-check"><input type="checkbox" id="chanGchatOn"> <span>Mirror to Google Chat (posts as the author)</span></label>
                  <label class="pm-label tc-gchat-space" id="chanGchatSpaceWrap" hidden>Google Chat space id<input type="text" id="chanGchatSpace" maxlength="120" placeholder="spaces/AAAA… or AAAA…"></label>
                  <div class="pm-actions"><button type="submit" class="pbtn pbtn-gold" id="chanSave">Create channel</button></div>
                  <p class="tc-chan-msg" id="chanMsg" hidden></p>
                </form>
              </div>
            </div>
          </div>
        </section>
