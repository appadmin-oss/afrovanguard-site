<?php /* portal/views/diary.php — My Diary (design "Afrovanguard Portal v3", Diary).

   Three panes, like a notes app: the library (buckets, notebooks, notebooks
   shared with you, tags, export), the entry list (search, kind filter, pinned
   then the rest), and the editor (notebook, save state, status, submit, pin,
   share, more; tabs; title, date, visibility, tags; templates; the body; the
   review track). The library is a drawer when the diary is narrower than
   1030px; below 712px the list and the editor are one pane at a time.

   Rendered here as the empty frame; portal/avdy.js fills it from
   /portal/notebooks.php (notebooks, tabs, tags, search) and /diary/api.php
   (the entries themselves). Styles portal/avdy.css — classes only, no inline
   styles. Rendered inside the shell, which defines $u and $collabCsrf. */
$dyName  = (string) ($u['name'] ?? '');
$dyEmail = (string) ($u['email'] ?? '');
?>
        <section class="pview" id="view-diary" data-view="diary" hidden>
          <div class="view-head">
            <div><h1>My Diary</h1><p class="view-sub">Private by default. Public entries and events are reviewed before they join the Diary.</p></div>
          </div>
          <div class="avdy" id="avdy" data-csrf="<?= e($collabCsrf) ?>" data-today="<?= e(date('Y-m-d')) ?>"
               data-me="<?= e($dyName) ?>" data-email="<?= e($dyEmail) ?>">
            <div class="avnb-scrim" data-dy-libscrim hidden></div>
            <aside class="avnb-lib" data-dy-lib aria-label="Notebooks and tags">
              <div class="avnb-lib-top">
                <button type="button" class="avnb-new" data-dy-new><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>New entry</button>
              </div>
              <div class="avnb-lib-scroll">
                <div class="avnb-grp" data-dy-buckets role="group" aria-label="Your diary"></div>
                <div class="avnb-grp">
                  <div class="avnb-grp-h"><span>Notebooks</span><button type="button" class="avnb-ico avnb-ico--sm" data-dy-nbnew aria-label="New notebook" title="New notebook"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg></button></div>
                  <div data-dy-nbs></div>
                </div>
                <div class="avnb-grp" data-dy-sharedgrp hidden>
                  <div class="avnb-grp-h"><span>Shared with me</span></div>
                  <div data-dy-shared></div>
                </div>
                <div class="avnb-grp" data-dy-tagsgrp hidden>
                  <div class="avnb-grp-h"><span>Tags</span></div>
                  <div data-dy-tags></div>
                </div>
              </div>
              <div class="avnb-export"><span>Export</span>
                <a href="<?= e(diary_url('export.php?scope=mine&format=book')) ?>">PDF</a>
                <a href="<?= e(diary_url('export.php?scope=mine&format=md')) ?>">Markdown</a>
                <a href="<?= e(diary_url('export.php?scope=mine&format=json')) ?>">JSON</a>
              </div>
            </aside>

            <div class="avnb-list" data-dy-listpane>
              <div class="avnb-list-top">
                <div class="avnb-list-h">
                  <button type="button" class="avnb-ico avnb-libbtn" data-dy-libopen aria-label="Notebooks and tags" title="Notebooks and tags" aria-expanded="false"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 4h18v16H3zM9 4v16"/></svg></button>
                  <span class="avnb-spine avnb-spine--h" data-dy-hspine hidden></span>
                  <h2 class="avnb-list-t" data-dy-htitle>All entries</h2>
                  <span class="avnb-list-acts" data-dy-hacts hidden>
                    <button type="button" class="avnb-ico" data-dy-nbshare aria-label="Share notebook" title="Share notebook"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M16 20v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2M9 10a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7zM22 20v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></svg></button>
                    <button type="button" class="avnb-ico" data-dy-nbedit aria-label="Notebook settings" title="Notebook settings"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h.01M12 12h.01M19 12h.01"/></svg></button>
                  </span>
                </div>
                <p class="avnb-list-sub" data-dy-hsub hidden></p>
                <div class="avnb-search" role="search">
                  <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M11 4a7 7 0 1 0 0 14 7 7 0 0 0 0-14zM20 20l-3.5-3.5"/></svg>
                  <input type="search" data-dy-q placeholder="Search titles, tabs and tags" aria-label="Search entries" maxlength="80">
                </div>
                <div class="avnb-kinds" role="group" aria-label="Show">
                  <button type="button" data-dy-kind="all" aria-pressed="true">All</button>
                  <button type="button" data-dy-kind="private" aria-pressed="false">Private</button>
                  <button type="button" data-dy-kind="public" aria-pressed="false">Public</button>
                  <button type="button" data-dy-kind="event" aria-pressed="false">Events</button>
                </div>
              </div>
              <div class="avnb-list-scroll" data-dy-list aria-busy="true">
                <div class="avnb-skel" aria-hidden="true"><span></span><span></span><span></span><span></span></div>
              </div>
              <p class="av-sr" role="status" aria-live="polite" data-dy-count></p>
            </div>

            <div class="avnb-ed" data-dy-ed>
              <div class="avnb-pick" data-dy-placeholder hidden>
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 4h12a3 3 0 0 1 3 3v13H7a3 3 0 0 1-3-3zM4 17a3 3 0 0 1 3-3h12"/></svg>
                <span>Pick an entry, or start a new one.</span>
                <button type="button" class="avnb-btn avnb-btn--ink" data-dy-new>New entry</button>
              </div>
              <div class="avnb-doc" data-dy-doc hidden></div>
            </div>
            <div class="avnb-modal" data-dy-modal hidden></div>
            <noscript><p class="avnb-nojs">Your diary needs JavaScript in the portal. You can still <a href="/diary/me/">write in your diary here</a>.</p></noscript>
          </div>
        </section>
