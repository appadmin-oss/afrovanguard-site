/* avdl.js — The Afrovanguard Diary, listing (design "Afrovanguard Diary").
 *
 * The page works without this: chips, sort and search are one GET form and
 * "Load more entries" is a link to the next page. With it, the same controls
 * filter in place from /diary/api.php?action=list, further pages append, the
 * URL keeps the filter (shareable, survives back/forward), "Saved" shows the
 * reader's saved entries, and Subscribe posts without leaving the page.
 * Cards are built as in diary/_avdl.php — keep the two in step.
 */
(function () {
  'use strict';
  var form = document.getElementById('avdlForm');
  var grid = document.querySelector('[data-avdl-grid]');
  if (!form || !grid) return;

  var PER = parseInt(form.getAttribute('data-per'), 10) || 12;
  var SKIP = form.getAttribute('data-skip') || '';
  var $ = function (s) { return document.querySelector(s); };
  var catIn = form.querySelector('[data-avdl-cat]');
  var sortIn = form.querySelector('[data-avdl-sort]');
  var qIn = form.querySelector('[data-avdl-q]');
  var savedChip = form.querySelector('[data-avdl-saved]');
  var chips = [].slice.call(form.querySelectorAll('.avdl-chip'));
  var empty = $('[data-avdl-empty]'), err = $('[data-avdl-err]'), skel = $('[data-avdl-skel]');
  var more = $('[data-avdl-more]'), moreBtn = $('[data-avdl-more-btn]'), status = $('[data-avdl-status]');
  var yearIn = form.querySelector('input[name=year]'), monthIn = form.querySelector('input[name=month]');

  var offset = parseInt(form.getAttribute('data-offset'), 10) || 0;
  var total = parseInt(form.getAttribute('data-total'), 10) || 0;
  var saved = false, busy = false, seq = 0, lastFailed = null;

  function savedList() { try { return JSON.parse(localStorage.getItem('av.saved') || '[]') || []; } catch (e) { return []; } }
  if (savedChip && savedList().length) savedChip.hidden = false;

  function st() {
    return { cat: catIn ? catIn.value : '', sort: sortIn ? sortIn.value : 'latest', q: qIn ? qIn.value.trim() : '',
      year: yearIn ? yearIn.value : '', month: monthIn ? monthIn.value : '' };
  }
  function filtered(s) { return !!(s.cat || s.q || s.year || s.month); }

  function compact(n) { n = +n || 0; return n >= 1000 ? String(+(n / 1000).toFixed(1)).replace(/\.0$/, '') + 'k' : String(n); }
  function meta(a) {
    var m = parseInt(a.read_minutes, 10) || 0;
    return [m ? m + ' min read' : '', a.published || ''].filter(Boolean).join(' | ');
  }
  function el(tag, cls, text) { var n = document.createElement(tag); if (cls) n.className = cls; if (text != null) n.textContent = text; return n; }
  function card(a, c) {
    var li = el('li'), link = el('a', 'avdl-card'); link.href = '/diary/' + encodeURIComponent(a.slug) + '/';
    var pic = el('div', 'avdl-card-img');
    if (a.cover_url) { var im = el('img'); im.src = a.cover_url; im.alt = ''; im.loading = 'lazy'; im.decoding = 'async'; pic.appendChild(im); }
    else if (window.avCover) pic.appendChild(window.avCover({ kind: 'diary', title: a.title, date: String(a.published_at || '').slice(0, 10),
      readTime: (parseInt(a.read_minutes, 10) || 0) ? a.read_minutes + ' min read' : '', ratio: '3:2', 'class': 'avcv--fill' }));
    var body = el('div', 'avdl-card-body'), kick = el('span', 'avdl-kick', 'Diary ');
    var sep = el('span', 'avdl-sep', '|'); sep.setAttribute('aria-hidden', 'true');
    kick.appendChild(sep); kick.appendChild(document.createTextNode(' ' + (a.category || '')));
    body.appendChild(kick); body.appendChild(el('h3', 'avdl-card-t', a.title)); body.appendChild(el('span', 'avdl-card-meta', meta(a)));
    if (c) {
      var ct = el('span', 'avdl-counts');
      ct.appendChild(el('span', '', compact(c.views) + ' views'));
      ct.appendChild(el('span', '', (+c.comments || 0) + ' comments'));
      var cl = el('span', '', (+c.claps || 0) + ' '), e1 = el('span', '', '👏'); e1.setAttribute('aria-hidden', 'true');
      cl.appendChild(e1); cl.appendChild(el('span', 'av-sr', 'applause')); ct.appendChild(cl);
      body.appendChild(ct);
    }
    link.appendChild(pic); link.appendChild(body); li.appendChild(link); return li;
  }

  function url(s, off, limit) {
    var p = new URLSearchParams({ action: 'list', limit: String(limit), offset: String(off) });
    ['cat', 'q', 'year', 'month'].forEach(function (k) { if (s[k]) p.set(k, s[k]); });
    if (s.sort && s.sort !== 'latest') p.set('sort', s.sort);
    return '/diary/api.php?' + p.toString();
  }
  function pageUrl(s, page) {
    var p = new URLSearchParams();
    ['cat', 'q', 'year', 'month'].forEach(function (k) { if (s[k]) p.set(k, s[k]); });
    if (s.sort && s.sort !== 'latest') p.set('sort', s.sort);
    if (page) p.set('page', String(page));
    var qs = p.toString(); return '/diary/' + (qs ? '?' + qs : '');
  }
  function say(n) { if (status) status.textContent = n + (n === 1 ? ' entry' : ' entries'); }
  function show(n) {
    var has = grid.children.length > 0;
    grid.hidden = !has; if (empty) empty.hidden = has;
    say(n);
  }
  function setBusy(b) {
    busy = b; if (skel) skel.hidden = !b;
    if (moreBtn) moreBtn.setAttribute('aria-busy', b ? 'true' : 'false');
  }
  function foot() { if (more) more.hidden = saved || offset >= total; if (moreBtn) moreBtn.href = pageUrl(st(), Math.ceil(grid.children.length / PER) + 1) + '#entries'; }

  /* Read a page. replace = a new filter (clears the grid); otherwise append. */
  function fetchPage(replace) {
    var s = st(), my = ++seq, skip = filtered(s) ? '' : SKIP;
    if (replace) offset = 0;
    var limit = PER + (skip && offset === 0 ? 1 : 0);
    setBusy(true); if (err) err.hidden = true;
    if (replace) { grid.innerHTML = ''; grid.hidden = true; if (empty) empty.hidden = true; }
    return fetch(url(s, offset, limit), { headers: { Accept: 'application/json' } })
      .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
      .then(function (d) {
        if (my !== seq) return;                          // a newer filter won
        if (!d || !d.ok) throw new Error('bad');
        var items = d.articles || [], frag = document.createDocumentFragment(), counts = d.counts || {};
        items.forEach(function (a) { if (a.slug !== skip) frag.appendChild(card(a, counts[a.slug] || null)); });
        grid.appendChild(frag);
        offset += items.length;
        total = d.total || 0;
        lastFailed = null;
        show(total - (skip && total ? 1 : 0)); foot();
      })
      .catch(function () {
        if (my !== seq) return;
        lastFailed = replace; if (err) err.hidden = false; if (more) more.hidden = true;
        grid.hidden = grid.children.length === 0;
      })
      .then(function () { if (my === seq) setBusy(false); });
  }

  function writeUrl(push) {
    try { history[push ? 'pushState' : 'replaceState'](null, '', pageUrl(st(), 0) + '#entries'); } catch (e) {}
  }
  function pressChip(btn) { chips.forEach(function (c) { c.setAttribute('aria-pressed', c === btn ? 'true' : 'false'); }); }
  function apply(push) { saved = false; writeUrl(push); fetchPage(true); }

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    var b = e.submitter;
    if (b && b.classList.contains('avdl-chip') && b.name === 'cat') { if (catIn) catIn.value = b.value; pressChip(b); }
    apply(true);
  });
  if (savedChip) savedChip.addEventListener('click', function () {
    pressChip(savedChip); saved = true; seq++;
    var mine = savedList(); grid.innerHTML = ''; setBusy(true); if (err) err.hidden = true; if (more) more.hidden = true;
    var my = seq;
    fetch('/diary/api.php?action=list&limit=48&offset=0', { headers: { Accept: 'application/json' } })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (my !== seq) return;
        (d && d.articles || []).forEach(function (a) { if (mine.indexOf(a.slug) !== -1) grid.appendChild(card(a, (d.counts || {})[a.slug] || null)); });
        show(grid.children.length);
      })
      .catch(function () { if (my === seq && err) err.hidden = false; })
      .then(function () { if (my === seq) setBusy(false); });
  });
  if (sortIn) sortIn.addEventListener('change', function () { apply(true); });
  var qT;
  if (qIn) qIn.addEventListener('input', function () { clearTimeout(qT); qT = setTimeout(function () { apply(false); }, 280); });
  var clear = $('[data-avdl-clear]');
  if (clear) clear.addEventListener('click', function (e) {
    e.preventDefault();
    if (catIn) catIn.value = ''; if (qIn) qIn.value = ''; if (sortIn) sortIn.value = 'latest';
    if (yearIn) yearIn.value = ''; if (monthIn) monthIn.value = '';
    pressChip(chips[0]); apply(true); if (qIn) qIn.focus();
  });
  if (moreBtn) moreBtn.addEventListener('click', function (e) { e.preventDefault(); if (!busy) fetchPage(false); });
  var retry = $('[data-avdl-retry]');
  if (retry) retry.addEventListener('click', function () { fetchPage(!!lastFailed); });
  window.addEventListener('popstate', function () { location.reload(); });

  /* Subscribe — the same endpoint the Diary has always used. */
  var sub = $('[data-avdl-sub]'), msg = $('[data-avdl-sub-msg]');
  if (sub) sub.addEventListener('submit', function (e) {
    e.preventDefault();
    var input = sub.querySelector('input[type=email]'), btn = sub.querySelector('button[type=submit]');
    var email = (input.value || '').trim();
    function note(t, bad) { msg.textContent = t; msg.classList.toggle('is-bad', !!bad); }
    if (!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(email)) { note('Please enter a valid email address.', true); input.focus(); return; }
    btn.disabled = true; btn.textContent = 'Subscribing…';
    fetch('/diary/api.php?action=subscribe', { method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ email: email, hp: (sub.querySelector('[name=hp]') || {}).value || '' }) })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (d && d.ok) { sub.hidden = true; note('✓ You’re subscribed — the next dispatch is on its way.'); }
        else note((d && d.error) || 'We could not subscribe you. Please try again.', true);
      })
      .catch(function () { note('The connection dropped. Please try again.', true); })
      .then(function () { btn.disabled = false; btn.textContent = 'Subscribe'; });
  });
})();
