/* ============================================================
   community.js — the Afrovanguard Community feed.
   Progressive enhancement over the SSR'd first page: like, reply
   (lazy-loaded threads), post, and load-older. Writes hit
   /community/api.php; reads are public. Card markup mirrors
   comm_card() in index.php so appended posts match the SSR ones.
   ============================================================ */
(function () {
  'use strict';
  var API = '/community/api.php';
  var main = document.querySelector('.cm');
  if (!main) return;
  var feed = document.getElementById('cmFeed');
  var signedIn = main.getAttribute('data-signed-in') === '1';

  /* When embedded in the portal the community lives in a tab that may be
     hidden; pause live polling until it's on screen. On the standalone page
     there's no such wrapper, so it's always "visible". */
  var portalView = document.getElementById('view-community');
  function communityVisible() { return !portalView || !portalView.hidden; }
  var kickers = []; // functions to run when the tab is (re)opened
  function kick() { if (communityVisible()) kickers.forEach(function (f) { try { f(); } catch (e) {} }); }

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
    });
  }
  function nl2br(s) { return esc(s).replace(/\n/g, '<br>'); }
  function loginRedirect() { location.href = '/login?next=' + encodeURIComponent(location.pathname + location.search); }

  function api(action, opts) {
    opts = opts || {};
    var url = API + '?action=' + action + (opts.query || '');
    var init = { credentials: 'same-origin', headers: {} };
    if (opts.body) { init.method = 'POST'; init.headers['Content-Type'] = 'application/json'; init.body = JSON.stringify(opts.body); }
    return fetch(url, init).then(function (r) {
      return r.json().then(function (d) { d.__status = r.status; return d; });
    });
  }

  var CHECK = '<svg class="cm-check" viewBox="0 0 24 24" width="15" height="15" aria-label="Verified"><path fill="currentColor" d="m12 1 2.6 1.9 3.2-.3 1 3 2.7 1.8-1 3 1 3-2.7 1.8-1 3-3.2-.3L12 23l-2.6-1.9-3.2.3-1-3L2.5 16.6l1-3-1-3 2.7-1.8 1-3 3.2.3z"/><path d="m8.5 12 2.4 2.4 4.6-4.8" stroke="#fff" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round"/></svg>';
  var HEART = '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.9"><path d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.6l-1-1a5.5 5.5 0 0 0-7.8 7.8l1 1L12 21l7.8-7.6 1-1a5.5 5.5 0 0 0 0-7.8Z"/></svg>';
  var BUBBLE = '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M21 11.5a8.4 8.4 0 0 1-9 8.4 9 9 0 0 1-3.9-.9L3 20l1-3.1A8.4 8.4 0 1 1 21 11.5Z"/></svg>';

  function ident(p) {
    return '<span class="cm-author">' + esc(p.author) + '</span>'
      + (p.verified ? CHECK : '')
      + '<span class="cm-tier cm-tier--' + esc(String(p.tier).toLowerCase()) + '">' + esc(p.tier) + '</span>'
      + (p.classification ? '<span class="cm-class cm-class--' + esc(p.classification) + '" title="' + esc(p.class_label || '') + '">' + esc(p.class_label || '') + '</span>' : '')
      + '<span class="cm-dot">·</span><span class="cm-ago">' + esc(p.ago) + '</span>';
  }

  /** Moderator controls (admins/coordinators only) appended to a post's bar. */
  var ADMIN = main.getAttribute('data-admin') === '1';
  function modBar(p) {
    if (!ADMIN) return '';
    return '<span class="cm-mod">'
      + '<button type="button" class="cm-act cm-mod-btn" data-mod-pin="' + p.id + '" title="Pin / unpin">📌</button>'
      + '<button type="button" class="cm-act cm-mod-btn" data-mod-cls="' + p.id + '" title="Change who can see this">🏷</button>'
      + '<button type="button" class="cm-act cm-mod-btn" data-mod-del="' + p.id + '" title="Remove post">🗑</button>'
      + '</span>';
  }

  /** Full post card — mirrors comm_card($p, false) in index.php. */
  function renderPost(p) {
    var art = document.createElement('article');
    art.className = 'cm-post' + (p.is_bot ? ' is-bot' : '');
    art.setAttribute('data-id', p.id);
    art.innerHTML =
      (p.pinned ? '<div class="cm-pin">📌 Pinned by the team</div>' : '')
      + '<div class="cm-post-in">'
      + '<div class="cm-head">'
      + '<span class="cm-av" style="--c:' + esc(p.space.color) + '">' + esc(p.initial) + '</span>'
      + '<div class="cm-meta"><div class="cm-line1">' + ident(p) + '</div>'
      + '<div class="cm-line2"><span class="cm-space" style="--c:' + esc(p.space.color) + '">' + esc(p.space.name) + '</span></div></div></div>'
      + '<div class="cm-body">' + nl2br(p.body) + '</div>'
      + '<div class="cm-bar">'
      + '<button class="cm-act cm-like' + (p.liked ? ' is-liked' : '') + '" data-like="' + p.id + '" aria-pressed="' + (p.liked ? 'true' : 'false') + '">'
      + HEART + '<span class="cm-likes">' + (p.likes | 0) + '</span></button>'
      + '<button class="cm-act cm-reply-toggle" data-reply="' + p.id + '">'
      + BUBBLE + '<span class="cm-replies">' + (p.reply_count | 0) + '</span></button>' + modBar(p) + '</div>'
      + '<div class="cm-thread" data-thread="' + p.id + '" hidden></div>'
      + '</div>';
    return art;
  }

  /** A light reply row (matches the .cm-reply CSS). */
  function renderReply(p) {
    var row = document.createElement('div');
    row.className = 'cm-reply';
    row.setAttribute('data-id', p.id);
    row.innerHTML =
      '<span class="cm-av" style="--c:' + esc(p.space.color) + '">' + esc(p.initial) + '</span>'
      + '<div class="cm-reply-bd"><div class="cm-reply-l1">' + ident(p) + '</div>'
      + '<div class="cm-body">' + nl2br(p.body) + '</div></div>';
    return row;
  }

  /* ── compose ── */
  var form = document.getElementById('cmCompose');
  if (form) {
    var body = document.getElementById('cmBody');
    var space = document.getElementById('cmSpace');
    var msg = form.querySelector('.cm-msg');
    var btn = form.querySelector('.cm-post-btn');

    /* Deep-link from a birthday celebration ("Send warm wishes"): prefill the
     * composer with a birthday message for the honoree and bring it into view. */
    (function () {
      var m = /[?&]wish=([^&]+)/.exec(location.search);
      if (!m || !body) return;
      var who = '';
      try { who = decodeURIComponent(m[1].replace(/\+/g, ' ')).trim(); } catch (e) { who = ''; }
      who = who.replace(/[<>]/g, '').slice(0, 60);
      if (!body.value) body.value = 'Happy birthday' + (who ? ', ' + who : '') + '! 🎉 ';
      var comp = document.querySelector('.cm-composer');
      if (comp && comp.scrollIntoView) comp.scrollIntoView({ behavior: 'smooth', block: 'center' });
      try { body.focus(); body.setSelectionRange(body.value.length, body.value.length); } catch (e) {}
    })();
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var text = (body.value || '').trim();
      if (text.length < 2) { setMsg(msg, 'Write a little more.', 'err'); return; }
      btn.disabled = true; setMsg(msg, '', '');
      var clsSel = document.getElementById('cmClass');
      api('post', { body: { space: space.value, body: text, classification: clsSel ? clsSel.value : 'members' } }).then(function (d) {
        btn.disabled = false;
        if (d.__status === 401) { loginRedirect(); return; }
        if (!d.ok) { setMsg(msg, d.error || 'Could not post.', 'err'); return; }
        var node = renderPost(d.post);
        var empty = feed.querySelector('.cm-empty');
        if (empty) empty.remove();
        feed.insertBefore(node, feed.firstChild);
        body.value = ''; setMsg(msg, 'Posted.', 'ok');
        setTimeout(function () { setMsg(msg, '', ''); }, 2500);
      }).catch(function () { btn.disabled = false; setMsg(msg, 'Network error — try again.', 'err'); });
    });

    /* ── Ask the AI bot ── posts the question, then the bot's reply in-thread ── */
    var askBtn = document.getElementById('cmAsk');
    if (askBtn) {
      askBtn.addEventListener('click', function () {
        var text = (body.value || '').trim();
        if (text.length < 3) { setMsg(msg, 'Ask the bot a fuller question.', 'err'); return; }
        askBtn.disabled = true; btn.disabled = true; setMsg(msg, 'Asking the Afrovanguard bot…', '');
        api('ask', { body: { space: space.value, body: text } }).then(function (d) {
          askBtn.disabled = false; btn.disabled = false;
          if (d.__status === 401) { loginRedirect(); return; }
          if (!d.ok) { setMsg(msg, d.error || 'Could not ask.', 'err'); return; }
          var empty = feed.querySelector('.cm-empty'); if (empty) empty.remove();
          var node = renderPost(d.question);
          feed.insertBefore(node, feed.firstChild);
          body.value = '';
          if (d.bot) {
            var thread = node.querySelector('.cm-thread[data-thread="' + d.question.id + '"]');
            if (thread) { thread.removeAttribute('hidden'); thread.setAttribute('data-loaded', '1'); thread.appendChild(renderReply(d.bot)); }
            var rc = node.querySelector('[data-reply="' + d.question.id + '"] .cm-replies'); if (rc) rc.textContent = '1';
            setMsg(msg, 'Afrovanguard replied below.', 'ok');
          } else {
            setMsg(msg, d.note || 'Posted — the team will follow up.', 'ok');
          }
          setTimeout(function () { setMsg(msg, '', ''); }, 4500);
        }).catch(function () { askBtn.disabled = false; btn.disabled = false; setMsg(msg, 'Network error — try again.', 'err'); });
      });
    }
  }
  function setMsg(el, t, kind) { if (!el) return; el.textContent = t; el.className = 'cm-msg' + (kind ? ' is-' + kind : ''); }

  /* ── delegated actions: like, reply-toggle ── */
  document.addEventListener('click', function (e) {
    var likeBtn = e.target.closest('[data-like]');
    if (likeBtn) {
      if (!signedIn) { loginRedirect(); return; }
      var id = likeBtn.getAttribute('data-like');
      likeBtn.disabled = true;
      api('like', { body: { id: +id } }).then(function (d) {
        likeBtn.disabled = false;
        if (d.__status === 401) { loginRedirect(); return; }
        if (!d.ok) return;
        likeBtn.classList.toggle('is-liked', !!d.liked);
        likeBtn.setAttribute('aria-pressed', d.liked ? 'true' : 'false');
        var n = likeBtn.querySelector('.cm-likes'); if (n) n.textContent = d.likes | 0;
      }).catch(function () { likeBtn.disabled = false; });
      return;
    }
    var replyBtn = e.target.closest('[data-reply]');
    if (replyBtn) {
      var pid = replyBtn.getAttribute('data-reply');
      var thread = main.querySelector('.cm-thread[data-thread="' + pid + '"]');
      if (!thread) return;
      var willOpen = thread.hasAttribute('hidden');
      if (willOpen) { thread.removeAttribute('hidden'); openThread(thread, pid); }
      else { thread.setAttribute('hidden', ''); }
    }
  });

  function openThread(thread, pid) {
    if (thread.getAttribute('data-loaded') === '1') { focusReply(thread); return; }
    thread.setAttribute('data-loaded', '1');
    thread.innerHTML = '<div class="cm-thread-loading cm-ago">Loading replies…</div>';
    api('replies', { query: '&id=' + encodeURIComponent(pid) }).then(function (d) {
      thread.innerHTML = '';
      (d.replies || []).forEach(function (r) { thread.appendChild(renderReply(r)); });
      if (signedIn) thread.appendChild(replyForm(pid));
      else {
        var prompt = document.createElement('div');
        prompt.className = 'cm-ago';
        prompt.innerHTML = '<a data-login-link href="/login">Sign in</a> to reply.';
        thread.appendChild(prompt);
      }
      focusReply(thread);
    }).catch(function () { thread.innerHTML = '<div class="cm-ago">Could not load replies.</div>'; thread.removeAttribute('data-loaded'); });
  }
  function focusReply(thread) { var t = thread.querySelector('.cm-reply-form textarea'); if (t) t.focus(); }

  function replyForm(pid) {
    var f = document.createElement('form');
    f.className = 'cm-reply-form';
    f.setAttribute('data-reply-form', pid);
    f.innerHTML = '<textarea rows="1" maxlength="5000" placeholder="Write a reply…" aria-label="Reply"></textarea>'
      + '<button type="submit" class="cm-post-btn cm-reply-send">Reply</button>';
    return f;
  }

  /* ── reply submit (delegated) ── */
  document.addEventListener('submit', function (e) {
    var f = e.target.closest('[data-reply-form]');
    if (!f) return;
    e.preventDefault();
    var pid = f.getAttribute('data-reply-form');
    var ta = f.querySelector('textarea');
    var sendBtn = f.querySelector('button');
    var text = (ta.value || '').trim();
    if (!text) return;
    sendBtn.disabled = true;
    api('reply', { body: { id: +pid, body: text } }).then(function (d) {
      sendBtn.disabled = false;
      if (d.__status === 401) { loginRedirect(); return; }
      if (!d.ok) { ta.setAttribute('placeholder', d.error || 'Could not reply.'); return; }
      f.parentNode.insertBefore(renderReply(d.reply), f);
      ta.value = '';
      var counter = main.querySelector('[data-reply="' + pid + '"] .cm-replies');
      if (counter) counter.textContent = (parseInt(counter.textContent, 10) || 0) + 1;
    }).catch(function () { sendBtn.disabled = false; });
  });

  /* ── load older ── */
  var more = document.getElementById('cmMore');
  if (more) {
    more.addEventListener('click', function () {
      var offset = +more.getAttribute('data-offset') || 0;
      var sort = main.getAttribute('data-sort') || 'latest';
      var sp = main.getAttribute('data-space') || '';
      var q = '&sort=' + encodeURIComponent(sort) + '&offset=' + offset + (sp ? '&space=' + encodeURIComponent(sp) : '');
      more.disabled = true; var label = more.textContent; more.textContent = 'Loading…';
      api('feed', { query: q }).then(function (d) {
        more.disabled = false; more.textContent = label;
        if (!d.ok) return;
        (d.posts || []).forEach(function (p) { feed.appendChild(renderPost(p)); });
        more.setAttribute('data-offset', d.offset);
        if (!d.has_more) more.setAttribute('hidden', '');
      }).catch(function () { more.disabled = false; more.textContent = label; });
    });
  }

  /* ============================================================
     LIVE FEED · switch spaces/sort without a reload, and surface new
     posts as they arrive (a "N new posts" pill). Works on the page and
     inside the portal tab. Polling pauses when the tab isn't visible.
     ============================================================ */
  var moreBtn = document.getElementById('cmMore');
  var feedLabel = main.querySelector('.cm-feed-label');
  var pending = [], pill = null;

  function inFeed(id) { return !!feed.querySelector('.cm-post[data-id="' + id + '"]'); }
  function feedTopId() { var a = feed.querySelector('.cm-post[data-id]'); return a ? +a.getAttribute('data-id') : 0; }

  function renderPill() {
    if (!pending.length) { if (pill) { pill.remove(); pill = null; } return; }
    if (!pill) {
      pill = document.createElement('button');
      pill.type = 'button';
      pill.className = 'cm-live-pill';
      pill.addEventListener('click', flushPending);
      feed.parentNode.insertBefore(pill, feed);
    }
    pill.textContent = '▲ ' + pending.length + ' new post' + (pending.length > 1 ? 's' : '');
  }
  function flushPending() {
    pending.sort(function (a, b) { return a.id - b.id; }); // oldest first → newest ends on top
    var empty = feed.querySelector('.cm-empty'); if (empty) empty.remove();
    pending.forEach(function (p) { if (!inFeed(p.id)) feed.insertBefore(renderPost(p), feed.firstChild); });
    pending = []; renderPill();
    if (feed.scrollIntoView) feed.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }

  var feedPolling = false;
  function feedPoll() {
    if (feedPolling || document.hidden || !communityVisible()) return;
    if ((main.getAttribute('data-sort') || 'latest') !== 'latest') return; // only "Latest" streams
    var sp = main.getAttribute('data-space') || '';
    var q = '&sort=latest&offset=0' + (sp ? '&space=' + encodeURIComponent(sp) : '');
    feedPolling = true;
    api('feed', { query: q }).then(function (d) {
      feedPolling = false;
      if (!d || !d.ok) return;
      var top = feedTopId();
      (d.posts || []).forEach(function (p) {
        if (p.id > top && !inFeed(p.id) && !pending.some(function (x) { return x.id === p.id; })) pending.push(p);
      });
      renderPill();
    }).catch(function () { feedPolling = false; });
  }

  /* switch space / sort in place */
  function loadFeed(space, sort) {
    main.setAttribute('data-space', space || '');
    main.setAttribute('data-sort', sort || 'latest');
    pending = []; renderPill();
    // reflect active states
    [].forEach.call(main.querySelectorAll('.cm-space-link'), function (a) {
      a.classList.toggle('is-active', (a.getAttribute('data-space') || '') === (space || ''));
    });
    [].forEach.call(main.querySelectorAll('.cm-sort-tab'), function (a) {
      a.classList.toggle('is-active', (a.getAttribute('data-sort') || 'latest') === (sort || 'latest'));
    });
    if (feedLabel) feedLabel.textContent = space ? space.replace(/-/g, ' ').replace(/\b\w/g, function (c) { return c.toUpperCase(); }) : 'All activity';
    var compSpace = document.getElementById('cmSpace');
    if (compSpace && space) { try { compSpace.value = space; } catch (e) {} }
    feed.innerHTML = '<div class="cm-empty">Loading…</div>';
    var q = '&sort=' + encodeURIComponent(sort || 'latest') + '&offset=0' + (space ? '&space=' + encodeURIComponent(space) : '');
    api('feed', { query: q }).then(function (d) {
      feed.innerHTML = '';
      if (!d || !d.ok || !(d.posts || []).length) {
        feed.innerHTML = '<div class="cm-empty">No posts here yet. Be the first to share something.</div>';
      } else {
        d.posts.forEach(function (p) { feed.appendChild(renderPost(p)); });
      }
      if (moreBtn) {
        if (d && d.has_more) { moreBtn.removeAttribute('hidden'); moreBtn.setAttribute('data-offset', d.offset); }
        else moreBtn.setAttribute('hidden', '');
      }
    }).catch(function () { feed.innerHTML = '<div class="cm-empty">Could not load posts.</div>'; });
  }

  main.addEventListener('click', function (e) {
    var sl = e.target.closest('.cm-space-link');
    if (sl && sl.hasAttribute('data-space')) { e.preventDefault(); loadFeed(sl.getAttribute('data-space') || '', main.getAttribute('data-sort') || 'latest'); return; }
    var st = e.target.closest('.cm-sort-tab');
    if (st && st.hasAttribute('data-sort')) { e.preventDefault(); loadFeed(main.getAttribute('data-space') || '', st.getAttribute('data-sort') || 'latest'); return; }
  });

  kickers.push(feedPoll);
  setInterval(feedPoll, 15000);
  document.addEventListener('visibilitychange', function () { if (!document.hidden) feedPoll(); });

  /* ============================================================
     ORG-ONLY · members chat + directory + @mention autocomplete.
     Org members "see each other" (the directory, SSR'd) and chat
     live; external members never get here (data-org="0"). Polling
     is incremental (since=lastId) and pauses when the tab is hidden.
     ============================================================ */
  var isOrg = main.getAttribute('data-org') === '1';
  var chatEl = document.getElementById('cmChat');
  if (isOrg && chatEl) {
    var meUid = parseInt(main.getAttribute('data-uid'), 10) || 0;
    var log = document.getElementById('cmChatLog');
    var cForm = document.getElementById('cmChatForm');
    var cInput = document.getElementById('cmChatInput');
    var cSend = cForm.querySelector('.cm-chat-send');
    var cMsg = chatEl.querySelector('.cm-chat-msg');
    var lastId = 0;
    var polling = false;
    var channel = chatEl.getAttribute('data-channel') || 'general';

    /* Highlight resolved @mentions inside an (escaped) body. The server
       returns the resolved member list, so we only chip real org members —
       never arbitrary text. A mention of *you* is styled distinctly. */
    function withMentions(bodyText, mentions) {
      var html = nl2br(bodyText);
      (mentions || []).forEach(function (m) {
        var tok = String(m.token || '').replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
        if (!tok) return;
        var re = new RegExp('@' + tok + '(?![A-Za-z0-9._@\\-])', 'g');
        var you = (m.id | 0) === meUid ? ' cm-mention--you' : '';
        html = html.replace(re, '<span class="cm-mention' + you + '">@' + esc(m.name) + '</span>');
      });
      return html;
    }

    function renderChat(m) {
      var row = document.createElement('div');
      row.className = 'cm-chat-row' + (m.is_me ? ' is-me' : '');
      row.setAttribute('data-id', m.id);
      row.innerHTML =
        '<span class="cm-chat-av">' + esc(m.initial) + '</span>'
        + '<div class="cm-chat-bd">'
        + '<div class="cm-chat-l1"><span class="cm-chat-who">' + esc(m.author) + '</span>'
        + (m.verified ? CHECK : '')
        + '<span class="cm-dot">·</span><span class="cm-ago">' + esc(m.ago) + '</span>'
        + '<button type="button" class="cm-to-task" title="Turn into a task" data-task="' + esc(m.body) + '">+ Task</button></div>'
        + '<div class="cm-chat-text">' + withMentions(m.body, m.mentions) + '</div></div>';
      return row;
    }

    function nearBottom() { return log.scrollHeight - log.scrollTop - log.clientHeight < 80; }
    function toBottom() { log.scrollTop = log.scrollHeight; }

    function paint(list, opts) {
      opts = opts || {};
      if (!list || !list.length) return;
      var stick = opts.force || nearBottom();
      var empty = log.querySelector('.cm-chat-empty');
      if (empty) empty.remove();
      list.forEach(function (m) {
        if (m.id > lastId) lastId = m.id;
        if (log.querySelector('.cm-chat-row[data-id="' + m.id + '"]')) return; // de-dupe
        log.appendChild(renderChat(m));
      });
      if (stick) toBottom();
    }

    function poll() {
      if (polling || document.hidden || !communityVisible()) return;
      polling = true;
      api('chat_list', { query: '&channel=' + encodeURIComponent(channel) + '&since=' + lastId }).then(function (d) {
        polling = false;
        if (d && d.ok) paint(d.messages, { force: lastId === 0 });
      }).catch(function () { polling = false; });
    }

    /* ── channel switching (# General / Announcements / …) ── */
    var chanBar = document.getElementById('cmChan');
    if (chanBar) chanBar.addEventListener('click', function (e) {
      var btn = e.target.closest('.cm-chan-btn'); if (!btn) return;
      var ch = btn.getAttribute('data-chan') || 'general';
      if (ch === channel) return;
      channel = ch; chatEl.setAttribute('data-channel', ch); lastId = 0;
      [].forEach.call(chanBar.querySelectorAll('.cm-chan-btn'), function (b) { b.classList.toggle('is-on', b === btn); });
      log.innerHTML = '<div class="cm-chat-empty">Loading #' + esc(ch) + '…</div>';
      poll();
    });

    /* ── message → task (chat ⇄ work bridge) ── */
    log.addEventListener('click', function (e) {
      var b = e.target.closest('.cm-to-task'); if (!b) return;
      var body = b.getAttribute('data-task') || ''; if (!body) return;
      b.disabled = true;
      api('to_task', { body: { body: body } }).then(function (d) {
        b.disabled = false;
        if (d && d.ok) { b.textContent = '✓ Task'; b.classList.add('is-done'); setMsg(cMsg, 'Added to your tasks.', 'ok'); setTimeout(function(){ setMsg(cMsg,'',''); }, 2500); }
        else { setMsg(cMsg, (d && d.error) || 'Could not create task.', 'err'); }
      }).catch(function () { b.disabled = false; setMsg(cMsg, 'Network error — try again.', 'err'); });
    });

    /* ── send ── */
    cForm.addEventListener('submit', function (e) {
      e.preventDefault();
      hideMentions();
      var text = (cInput.value || '').trim();
      if (!text) return;
      cSend.disabled = true; setMsg(cMsg, '', '');
      api('chat_send', { body: { body: text, channel: channel } }).then(function (d) {
        cSend.disabled = false;
        if (d.__status === 401) { loginRedirect(); return; }
        if (!d.ok) { setMsg(cMsg, d.error || 'Could not send.', 'err'); return; }
        paint([d.message], { force: true });
        cInput.value = ''; grow();
        cInput.focus();
      }).catch(function () { cSend.disabled = false; setMsg(cMsg, 'Network error — try again.', 'err'); });
    });
    // Enter sends; Shift+Enter is a newline. (Skipped while the @menu is open.)
    cInput.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' && !e.shiftKey && !menuOpen()) { e.preventDefault(); cForm.requestSubmit ? cForm.requestSubmit() : cForm.dispatchEvent(new Event('submit', { cancelable: true })); }
    });

    /* ── textarea auto-grow ── */
    function grow() { cInput.style.height = 'auto'; cInput.style.height = Math.min(cInput.scrollHeight, 140) + 'px'; }
    cInput.addEventListener('input', grow);

    /* ── @mention autocomplete ── */
    var pop = document.getElementById('cmMentionPop');
    var mActive = -1, mItems = [], mStart = -1, mTimer = null;
    function menuOpen() { return !pop.hasAttribute('hidden'); }
    function hideMentions() { pop.setAttribute('hidden', ''); pop.innerHTML = ''; mActive = -1; mItems = []; mStart = -1; }
    function tokenBeforeCaret() {
      var pos = cInput.selectionStart || 0;
      var pre = cInput.value.slice(0, pos);
      var m = /(^|\s)@([A-Za-z0-9._\-]{0,30})$/.exec(pre);
      if (!m) return null;
      return { q: m[2], start: pos - m[2].length - 1 }; // index of the '@'
    }
    function drawMenu() {
      if (!mItems.length) { hideMentions(); return; }
      pop.innerHTML = mItems.map(function (it, i) {
        return '<button type="button" class="cm-mention-opt' + (i === mActive ? ' is-active' : '')
          + '" data-i="' + i + '" role="option" aria-selected="' + (i === mActive ? 'true' : 'false') + '">'
          + '<span class="cm-mention-av">' + esc(it.initial) + '</span>'
          + '<span class="cm-mention-nm">' + esc(it.name) + (it.is_me ? ' <em>(you)</em>' : '')
          + '<small>@' + esc(it.handle) + '</small></span></button>';
      }).join('');
      pop.removeAttribute('hidden');
    }
    function accept(i) {
      var it = mItems[i]; if (!it) return;
      var pos = cInput.selectionStart || 0;
      var before = cInput.value.slice(0, mStart);
      var after = cInput.value.slice(pos);
      var ins = '@' + it.handle + ' ';
      cInput.value = before + ins + after;
      var caret = before.length + ins.length;
      cInput.setSelectionRange(caret, caret);
      hideMentions(); grow(); cInput.focus();
    }
    cInput.addEventListener('input', function () {
      var tok = tokenBeforeCaret();
      if (!tok) { hideMentions(); return; }
      mStart = tok.start;
      clearTimeout(mTimer);
      mTimer = setTimeout(function () {
        api('mention_search', { query: '&q=' + encodeURIComponent(tok.q) }).then(function (d) {
          if (!d || !d.ok) { hideMentions(); return; }
          mItems = d.matches || []; mActive = mItems.length ? 0 : -1;
          drawMenu();
        }).catch(hideMentions);
      }, 120);
    });
    cInput.addEventListener('keydown', function (e) {
      if (!menuOpen()) return;
      if (e.key === 'ArrowDown') { e.preventDefault(); mActive = (mActive + 1) % mItems.length; drawMenu(); }
      else if (e.key === 'ArrowUp') { e.preventDefault(); mActive = (mActive - 1 + mItems.length) % mItems.length; drawMenu(); }
      else if (e.key === 'Enter' || e.key === 'Tab') { e.preventDefault(); accept(mActive < 0 ? 0 : mActive); }
      else if (e.key === 'Escape') { e.preventDefault(); hideMentions(); }
    });
    pop.addEventListener('mousedown', function (e) {
      var opt = e.target.closest('[data-i]'); if (!opt) return;
      e.preventDefault(); accept(+opt.getAttribute('data-i'));
    });
    document.addEventListener('click', function (e) { if (!chatEl.contains(e.target)) hideMentions(); });

    /* ── boot: initial load, then poll; pause when hidden ── */
    poll();
    kickers.push(poll);
    var pollTimer = setInterval(poll, 5000);
    document.addEventListener('visibilitychange', function () { if (!document.hidden) poll(); });
    if (location.hash === '#chat') { try { chatEl.scrollIntoView({ behavior: 'smooth', block: 'center' }); } catch (e) {} }
  }

  /* When the portal switches to the Community tab, resume live polling at once
     (hashchange fires for #community; also cover in-portal nav clicks). */
  if (portalView) {
    window.addEventListener('hashchange', function () { setTimeout(kick, 60); });
    document.addEventListener('click', function (e) {
      if (e.target.closest('[data-view="community"],[data-goto="community"]')) setTimeout(kick, 80);
    });
  }

  /* ── Moderator controls + official announcements (admins/coordinators) ── */
  if (ADMIN) {
    var CLABEL = { public: 'Public', members: 'Members-only', confidential: 'Confidential' };
    // Server-rendered posts on the page don't have the mod bar yet — add it.
    [].forEach.call(main.querySelectorAll('.cm-post'), function (a) {
      var bar = a.querySelector('.cm-bar');
      if (bar && !bar.querySelector('.cm-mod')) bar.insertAdjacentHTML('beforeend', modBar({ id: a.getAttribute('data-id') }));
    });
    main.addEventListener('click', function (e) {
      var pin = e.target.closest('[data-mod-pin]');
      if (pin) {
        var art = pin.closest('.cm-post'), isPinned = !!(art && art.querySelector('.cm-pin'));
        api('mod_pin', { body: { id: +pin.getAttribute('data-mod-pin'), pin: !isPinned } }).then(function (d) {
          if (!d || !d.ok || !art) return;
          if (isPinned) { var pn = art.querySelector('.cm-pin'); if (pn) pn.remove(); }
          else art.insertAdjacentHTML('afterbegin', '<div class="cm-pin">📌 Pinned by the team</div>');
        });
        return;
      }
      var del = e.target.closest('[data-mod-del]');
      if (del) {
        if (!confirm('Remove this post from the community?')) return;
        var da = del.closest('.cm-post');
        api('mod_delete', { body: { id: +del.getAttribute('data-mod-del') } }).then(function (d) {
          if (d && d.ok && da) da.remove(); else if (d && !d.ok) alert(d.error || 'Not allowed.');
        });
        return;
      }
      var cls = e.target.closest('[data-mod-cls]');
      if (cls) {
        var v = (prompt('Who can see this post? public, members, or confidential', 'members') || '').trim().toLowerCase();
        if (['public', 'members', 'confidential'].indexOf(v) < 0) return;
        api('mod_classify', { body: { id: +cls.getAttribute('data-mod-cls'), classification: v } }).then(function (d) {
          if (!d || !d.ok) return;
          var b = cls.closest('.cm-post').querySelector('.cm-class');
          if (b) { b.className = 'cm-class cm-class--' + v; b.textContent = CLABEL[v]; b.title = CLABEL[v]; }
        });
        return;
      }
    });
    var annBtn = document.getElementById('cmAnnounce');
    if (annBtn) annBtn.addEventListener('click', function () {
      var bodyEl = document.getElementById('cmBody'), spaceEl = document.getElementById('cmSpace');
      var text = ((bodyEl && bodyEl.value) || '').trim();
      if (text.length < 3) { if (bodyEl) bodyEl.focus(); alert('Type the announcement in the box first.'); return; }
      var pin = confirm('Pin this announcement to the top of the space?');
      annBtn.disabled = true;
      api('announce', { body: { space: spaceEl ? spaceEl.value : 'announcements', body: text, pin: pin } }).then(function (d) {
        annBtn.disabled = false;
        if (d && d.ok && d.post) {
          if (bodyEl) bodyEl.value = '';
          var empty = feed.querySelector('.cm-empty'); if (empty) empty.remove();
          feed.insertBefore(renderPost(d.post), feed.firstChild);
        } else alert((d && d.error) || 'Could not announce.');
      }).catch(function () { annBtn.disabled = false; });
    });
  }
})();
