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
      + '<span class="cm-dot">·</span><span class="cm-ago">' + esc(p.ago) + '</span>';
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
      + BUBBLE + '<span class="cm-replies">' + (p.reply_count | 0) + '</span></button></div>'
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
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var text = (body.value || '').trim();
      if (text.length < 2) { setMsg(msg, 'Write a little more.', 'err'); return; }
      btn.disabled = true; setMsg(msg, '', '');
      api('post', { body: { space: space.value, body: text } }).then(function (d) {
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
        + '<span class="cm-dot">·</span><span class="cm-ago">' + esc(m.ago) + '</span></div>'
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
      if (polling || document.hidden) return;
      polling = true;
      api('chat_list', { query: '&since=' + lastId }).then(function (d) {
        polling = false;
        if (d && d.ok) paint(d.messages, { force: lastId === 0 });
      }).catch(function () { polling = false; });
    }

    /* ── send ── */
    cForm.addEventListener('submit', function (e) {
      e.preventDefault();
      hideMentions();
      var text = (cInput.value || '').trim();
      if (!text) return;
      cSend.disabled = true; setMsg(cMsg, '', '');
      api('chat_send', { body: { body: text } }).then(function (d) {
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
    var pollTimer = setInterval(poll, 5000);
    document.addEventListener('visibilitychange', function () { if (!document.hidden) poll(); });
    if (location.hash === '#chat') { try { chatEl.scrollIntoView({ behavior: 'smooth', block: 'center' }); } catch (e) {} }
  }
})();
