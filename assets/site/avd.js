/* Diary engagement behaviour. Prefix avd-. No dependencies. Load with `defer` on diary pages.
   API: /diary/api.php?action=… (JSON). Writes send header X-CSRF from <meta name="csrf-token">. */
(function () {
  'use strict';
  var API = '/diary/api.php';
  var csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
  var $ = function (s, r) { return (r || document).querySelector(s); };
  var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };
  var typing = function (e) { var t = e.target; return t && (t.isContentEditable || /^(INPUT|TEXTAREA|SELECT)$/.test(t.tagName)); };
  function api(action, data, method) {
    var url = API + '?action=' + action;
    if (method !== 'POST' && data) url += '&' + new URLSearchParams(data);
    return fetch(url, { method: method || 'GET', credentials: 'same-origin',
      headers: method === 'POST' ? { 'Content-Type': 'application/json', 'X-CSRF': csrf } : {},
      body: method === 'POST' ? JSON.stringify(data || {}) : undefined
    }).then(function (r) { if (!r.ok) throw new Error(r.status); return r.json(); });
  }
  var toastT;
  function toast(msg) {
    var t = $('.avd-toast') || document.body.appendChild(Object.assign(document.createElement('div'), { className: 'avd-toast' }));
    t.setAttribute('role', 'status'); t.textContent = msg; t.hidden = false;
    clearTimeout(toastT); toastT = setTimeout(function () { t.hidden = true; }, 4000);
  }
  function fmt(s) { s = Math.max(0, Math.floor(s)); return Math.floor(s / 60) + ':' + String(s % 60).padStart(2, '0'); }
  function spoken(s) { s = Math.floor(s); var m = Math.floor(s / 60), r = s % 60; return m + ' minute' + (m === 1 ? '' : 's') + ' ' + r + ' second' + (r === 1 ? '' : 's'); }
  function shareUrl(kind, url, text) {
    var u = encodeURIComponent(url), t = encodeURIComponent(text || '');
    if (kind === 'x') return 'https://x.com/intent/post?url=' + u + '&text=' + t;
    if (kind === 'whatsapp') return 'https://wa.me/?text=' + encodeURIComponent((text ? '“' + text + '” ' : '') + url);
    if (kind === 'linkedin') return 'https://www.linkedin.com/sharing/share-offsite/?url=' + u;
  }

  /* view ping (server dedupes per 30 min, excludes bots/admins) */
  var slugEl = $('[data-avd-comments]') || $('[data-avd-engage]');
  var slug = slugEl && (slugEl.dataset.slug || (document.body.dataset.slug || ''));
  if (document.body.dataset.slug) api('view', { slug: document.body.dataset.slug }, 'POST').catch(function () {});

  /* engagement bar */
  var eng = $('[data-avd-engage]');
  if (eng) {
    var url = eng.dataset.url, title = eng.dataset.title, claps = 0;
    var clap = $('[data-avd-clap]', eng), n = $('[data-avd-clapn]', eng);
    clap.addEventListener('click', function () {
      if (claps >= 50) return;
      claps++; n.textContent = +n.textContent + 1; clap.classList.add('is-on'); clap.setAttribute('aria-pressed', 'true');
      api('clap', { slug: document.body.dataset.slug }, 'POST').catch(function () {});
    });
    var save = $('[data-avd-save]', eng);
    save.addEventListener('click', function () {
      var on = save.getAttribute('aria-pressed') !== 'true';
      save.setAttribute('aria-pressed', String(on)); save.classList.toggle('is-on', on); save.textContent = on ? 'Saved' : 'Save';
      api(on ? 'save' : 'unsave', { slug: document.body.dataset.slug }, 'POST').catch(function () {});
    });
    var sb = $('[data-avd-share]', eng), menu = $('[data-avd-sharemenu]', eng);
    $$('a[data-share]', menu).forEach(function (a) { a.href = shareUrl(a.dataset.share, url, title); });
    function closeMenu(focus) { menu.hidden = true; sb.setAttribute('aria-expanded', 'false'); if (focus) sb.focus(); }
    sb.addEventListener('click', function () { var open = menu.hidden; menu.hidden = !open; sb.setAttribute('aria-expanded', String(open)); if (open) $('[role=menuitem]', menu).focus(); });
    $('[data-share="copy"]', menu).addEventListener('click', function () { navigator.clipboard.writeText(url).then(function () { toast('Link copied'); }); closeMenu(true); });
    document.addEventListener('click', function (e) { if (!menu.hidden && !e.target.closest('.avd-share')) closeMenu(false); });
    menu.addEventListener('keydown', function (e) { if (e.key === 'Escape') { e.stopPropagation(); closeMenu(true); } });
  }
  /* Follow the author: one email whenever they publish something new
     (diary/api.php follow / unfollow / follow.state, lib/DiaryFollows.php).
     A member follows in one press; a reader without an account leaves an email. */
  var follow = $('[data-avd-follow]');
  if (follow) {
    var fForm = $('[data-avd-follow-form]'), fMsg = $('[data-avd-follow-msg]');
    var author = follow.getAttribute('data-author') || '', first = follow.getAttribute('data-first') || 'them';
    var signedIn = false;
    var setF = function (on) { follow.setAttribute('aria-pressed', String(on)); follow.textContent = on ? 'Following' : 'Follow'; };
    var say = function (t, bad) { if (fMsg) { fMsg.textContent = t || ''; fMsg.classList.toggle('is-bad', !!bad); } };
    var post = function (action, payload) {
      return fetch('/diary/api.php?action=' + action, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) })
        .then(function (r) { return r.json().catch(function () { return { ok: false, error: 'The server answered HTTP ' + r.status + '.' }; }); })
        .catch(function () { return { ok: false, error: 'The connection dropped. Try again.' }; });
    };
    fetch('/diary/api.php?action=follow.state&author=' + encodeURIComponent(author), { credentials: 'same-origin' })
      .then(function (r) { return r.json(); }).then(function (d) {
        if (!d || !d.ok) return;
        signedIn = !!d.signedIn;
        // A guest's follow lives on the server by email; this browser only remembers that it asked.
        var mem = false; try { mem = localStorage.getItem('av.follow.' + author) === '1'; } catch (e) {}
        setF(signedIn ? !!d.following : mem);
      }).catch(function () {});
    follow.addEventListener('click', function () {
      var on = follow.getAttribute('aria-pressed') === 'true';
      if (!signedIn) {
        if (on) { say('To stop following, use the link at the foot of any email we send you.'); return; }
        if (fForm) { fForm.hidden = false; var em = fForm.querySelector('input[type=email]'); if (em) em.focus(); }
        return;
      }
      follow.disabled = true;
      post(on ? 'unfollow' : 'follow', { author: author }).then(function (d) {
        follow.disabled = false;
        if (!d.ok) { say(d.error || 'That did not go through. Try again.', true); return; }
        setF(!!d.following);
        say(d.following ? 'You’ll get an email when ' + first + ' publishes something new.' : 'You’ve stopped following ' + first + '.');
      });
    });
    if (fForm) fForm.addEventListener('submit', function (e) {
      e.preventDefault();
      var em = fForm.querySelector('input[type=email]'), btn = fForm.querySelector('button[type=submit]'), email = (em.value || '').trim();
      if (!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(email)) { say('Enter a valid email address.', true); em.focus(); return; }
      btn.disabled = true;
      post('follow', { author: author, email: email, hp: (fForm.querySelector('[name=hp]') || {}).value || '' }).then(function (d) {
        btn.disabled = false;
        if (!d.ok) { say(d.error || 'That did not go through. Try again.', true); return; }
        fForm.hidden = true; setF(true); follow.focus();
        try { localStorage.setItem('av.follow.' + author, '1'); } catch (e) {}
        say('You’ll get an email when ' + first + ' publishes something new.');
      });
    });
  }

  /* highlight to share (12–280 chars inside the article) */
  var hl = $('[data-avd-hl]'), article = $('article.avd-article') || $('article');
  if (hl && article) {
    document.addEventListener('selectionchange', function () {
      var sel = getSelection(), txt = String(sel).trim();
      if (!sel.rangeCount || txt.length < 12 || txt.length > 280 || !article.contains(sel.anchorNode)) { hl.hidden = true; return; }
      var r = sel.getRangeAt(0).getBoundingClientRect();
      $$('a[data-share]', hl).forEach(function (a) { a.href = shareUrl(a.dataset.share, location.href.split('#')[0], txt); });
      hl.dataset.text = txt; hl.hidden = false;
      hl.style.top = (scrollY + r.top - hl.offsetHeight - 8) + 'px';
      hl.style.left = Math.max(8, scrollX + r.left + r.width / 2 - hl.offsetWidth / 2) + 'px';
    });
    $('[data-share="copy"]', hl).addEventListener('click', function () { navigator.clipboard.writeText('“' + hl.dataset.text + '” ' + location.href.split('#')[0]).then(function () { toast('Copied'); }); hl.hidden = true; });
  }

  /* comments */
  var cs = $('[data-avd-comments]');
  if (cs) {
    var list = $('[data-avd-list]', cs), form = $('[data-avd-compose]', cs), more = $('.avd-compose-more', form), ta = form.body, err = $('[data-avd-err]', form), sort = 'top';
    ta.addEventListener('focus', function () { ta.rows = 4; more.hidden = false; });
    function load(all) {
      list.innerHTML = '<li class="avd-skel"></li><li class="avd-skel"></li><li class="avd-skel"></li>';
      api('comments', { slug: cs.dataset.slug, sort: sort, page: 1, all: all ? 1 : 0 }).then(function (d) {
        list.innerHTML = d.html || '<li class="avd-c-empty">Be the first to add to the conversation.</li>';  // server renders avd_comment()
      }).catch(function () { list.innerHTML = '<li class="avd-c-empty">Couldn’t load comments. Try again.</li>'; });
    }
    $$('[data-sort]', cs).forEach(function (b) { b.addEventListener('click', function () {
      sort = b.dataset.sort; $$('[data-sort]', cs).forEach(function (x) { x.setAttribute('aria-checked', String(x === b)); }); load(false);
    }); });
    var all = $('[data-avd-all]', cs); if (all) all.addEventListener('click', function () { all.remove(); load(true); });
    form.addEventListener('submit', function (e) {
      e.preventDefault(); err.textContent = '';
      if (ta.value.trim().length < 3) { err.textContent = 'Write a little more before posting.'; ta.focus(); return; }
      if (!form.name.value.trim()) { err.textContent = 'Add your name to post.'; form.name.focus(); return; }
      var body = { slug: cs.dataset.slug, body: ta.value.trim(), name: form.name.value.trim(), email: form.email.value.trim(), parent_id: form.parent_id.value || null };
      api('comment', body, 'POST').then(function (d) {
        var tmp = document.createElement('ol'); tmp.innerHTML = d.html;   // pending comment, shown only to its author
        var li = tmp.firstElementChild, parent = body.parent_id && list.querySelector('[data-id="' + body.parent_id + '"] .avd-c-body');
        if (parent) { (parent.querySelector('.avd-c-replies') || parent.appendChild(Object.assign(document.createElement('ol'), { className: 'avd-c-replies' }))).appendChild(li); }
        else { var empty = $('.avd-c-empty', list); if (empty) empty.remove(); list.prepend(li); }
        ta.value = ''; form.parent_id.value = ''; ta.placeholder = 'Add to the conversation…';
      }).catch(function () { err.textContent = 'Couldn’t post. Try again.'; });   // text is kept
    });
    list.addEventListener('click', function (e) {
      var b = e.target.closest('button'); if (!b) return;
      var li = b.closest('.avd-c'), id = li.dataset.id;
      if (b.hasAttribute('data-avd-like')) {
        var on = b.getAttribute('aria-pressed') !== 'true', c = $('.av-num', b);
        b.setAttribute('aria-pressed', String(on)); c.textContent = +c.textContent + (on ? 1 : -1);
        api('comment-like', { id: id, on: on }, 'POST').catch(function () {});
      } else if (b.hasAttribute('data-avd-reply')) {
        form.parent_id.value = id; ta.placeholder = 'Reply to ' + $('.avd-c-meta strong', li).textContent + '…'; ta.focus();
      } else if (b.hasAttribute('data-avd-report')) {
        b.textContent = 'Reported'; b.disabled = true; api('comment-report', { id: id }, 'POST').catch(function () {});
      }
    });
  }

  /* audio */
  var L = $('.avd-listen'), bar = $('[data-avd-bar]');
  if (L && bar) {
    var a = new Audio(), key = 'avd-lp:' + L.dataset.slug, chapters = [], rates = [1, 1.25, 1.5, 2, 0.75], ri = 0, lastSave = 0;
    var playBtn = $('[data-avd-play]', L), resume = $('[data-avd-resume]', L), seek = $('[data-avd-seek]', bar), fill = $('[data-avd-fill]', bar),
        tog = $('[data-avd-toggle]', bar), icon = $('[data-avd-icon]', bar), chBtn = $('[data-avd-chapters]', bar), chList = $('[data-avd-chlist]', bar),
        chTitle = $('[data-avd-chtitle]', bar), time = $('[data-avd-time]', bar), speed = $('[data-avd-speed]', bar), live = $('[data-avd-live]', bar);
    var dur = +L.dataset.duration;
    fetch(L.dataset.avdAudioSrc + '&meta=1', { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (m) {
      a.src = m.src; a.preload = 'metadata'; chapters = m.chapters || []; dur = m.seconds || dur; seek.max = Math.floor(dur);
      chList.innerHTML = chapters.map(function (c, i) { return '<li><button type="button" data-t="' + c.t + '"><span>' + c.title.replace(/</g, '&lt;') + '</span><span class="av-num">' + fmt(c.t) + '</span></button></li>'; }).join('');
      var saved = +localStorage.getItem(key);
      if (saved > 5) { resume.hidden = false; $('.av-num', resume).textContent = fmt(saved); }
    }).catch(function () { L.hidden = true; });
    function chIdx(t) { var i = 0; chapters.forEach(function (c, k) { if (t >= c.t) i = k; }); return i; }
    function render() {
      var t = a.currentTime, i = chIdx(t), ch = chapters[i] ? chapters[i].title : L.dataset.title;
      fill.style.width = (100 * t / dur) + '%'; seek.value = Math.floor(t);
      seek.setAttribute('aria-valuetext', spoken(t) + ' of ' + spoken(dur) + ', ' + ch);
      chTitle.textContent = ch; time.textContent = fmt(t) + ' / ' + fmt(dur) + (chapters.length ? ' · ' + (i + 1) + ' of ' + chapters.length : '');
      playBtn.textContent = a.paused ? 'Listen · ' + Math.ceil(dur / 60) + ' min' : 'Pause · ' + fmt(t);
      playBtn.setAttribute('aria-pressed', String(!a.paused));
      tog.setAttribute('aria-label', a.paused ? 'Play' : 'Pause');
      icon.setAttribute('d', a.paused ? 'M8 5v14l11-7z' : 'M7 5h4v14H7zM13 5h4v14h-4z');
      $$('button', chList).forEach(function (b, k) { b.setAttribute('aria-current', String(k === i)); });
    }
    function persist() { localStorage.setItem(key, String(Math.floor(a.currentTime))); }
    function play() { bar.hidden = false; a.play(); }
    function toggle() { a.paused ? play() : a.pause(); }
    playBtn.addEventListener('click', toggle);
    resume.addEventListener('click', function () { a.currentTime = +localStorage.getItem(key); resume.hidden = true; play(); });
    tog.addEventListener('click', toggle);
    $$('[data-avd-skip]', bar).forEach(function (b) { b.addEventListener('click', function () { a.currentTime = Math.min(dur, Math.max(0, a.currentTime + +b.dataset.avdSkip)); }); });
    seek.addEventListener('input', function () { a.currentTime = +seek.value; });
    speed.addEventListener('click', function () { ri = (ri + 1) % rates.length; a.playbackRate = rates[ri]; speed.textContent = rates[ri] + '×'; speed.setAttribute('aria-label', 'Playback speed ' + rates[ri] + '×'); });
    chBtn.addEventListener('click', function () { var o = chList.hidden; chList.hidden = !o; chBtn.setAttribute('aria-expanded', String(o)); });
    chList.addEventListener('click', function (e) { var b = e.target.closest('button'); if (b) { a.currentTime = +b.dataset.t; chList.hidden = true; chBtn.setAttribute('aria-expanded', 'false'); chBtn.focus(); } });
    $('[data-avd-close]', bar).addEventListener('click', function () { a.pause(); persist(); bar.hidden = true; playBtn.focus(); });
    /* MODIFICATION to the drop-in, declared in the PR: the bar's duration starts
       as an estimate (audio.php cannot measure a file it has not built, and a
       human-recorded narration lives in a file this server may not hold), so
       the real one is taken from the element the moment it is known. Without
       this the scrubber's range stays at the estimate and a seek lands in the
       wrong minute. */
    a.addEventListener('loadedmetadata', function () {
      if (isFinite(a.duration) && a.duration > 0) { dur = a.duration; seek.max = Math.floor(dur); render(); }
    });
    a.addEventListener('timeupdate', function () { render(); if (Date.now() - lastSave > 5000) { lastSave = Date.now(); persist(); } });
    a.addEventListener('play', function () { render(); live.textContent = 'Playing: ' + chTitle.textContent; });
    a.addEventListener('pause', function () { render(); persist(); live.textContent = 'Paused at ' + fmt(a.currentTime); });
    a.addEventListener('error', function () { L.hidden = true; bar.hidden = true; });
    document.addEventListener('keydown', function (e) {
      if (bar.hidden || typing(e) || e.metaKey || e.ctrlKey || e.altKey) return;
      var k = e.key.toLowerCase();
      if (k === 'k') { e.preventDefault(); toggle(); }
      else if (k === 'j') a.currentTime = Math.max(0, a.currentTime - 15);
      else if (k === 'l') a.currentTime = Math.min(dur, a.currentTime + 15);
      else if (e.key === 'Escape' && !chList.hidden) { chList.hidden = true; chBtn.setAttribute('aria-expanded', 'false'); }
    });
  }
})();
