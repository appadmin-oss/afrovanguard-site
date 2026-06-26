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
})();
