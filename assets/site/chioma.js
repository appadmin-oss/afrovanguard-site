/* ============================================================
   Chioma — Afrovanguard's AI site guide (floating assistant).
   Self-contained: injects its own styles + DOM on every page,
   is context-aware (sends the current page), and lively.
   ============================================================ */
(function () {
  'use strict';
  if (window.__chioma) return; window.__chioma = true;
  if (document.documentElement.hasAttribute('data-no-chioma')) return;

  var ENDPOINT = '/chioma.php';
  var FACE = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/><circle cx="9" cy="11.5" r="1" fill="currentColor" stroke="none"/><circle cx="15" cy="11.5" r="1" fill="currentColor" stroke="none"/><path d="M9 14.5a4 4 0 0 0 6 0"/></svg>';
  var SEND = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 2 11 13M22 2l-7 20-4-9-9-4 20-7z"/></svg>';

  /* ---- ensure stylesheet ---- */
  if (!document.querySelector('link[href="/assets/site/chioma.css"]')) {
    var l = document.createElement('link'); l.rel = 'stylesheet'; l.href = '/assets/site/chioma.css'; document.head.appendChild(l);
  }

  /* ---- page context ---- */
  function context() {
    var sec = document.body.getAttribute('data-section') || '';
    var path = location.pathname;
    if (!sec) {
      if (/^\/academy/.test(path)) sec = 'Academy';
      else if (/^\/diary/.test(path)) sec = 'Diary';
      else if (/^\/projects/.test(path)) sec = 'Projects';
      else if (/donate/.test(path)) sec = 'Donate';
      else if (/contact/.test(path)) sec = 'Contact';
      else if (path === '/' || /index/.test(path)) sec = 'Home';
    }
    var title = (document.title || '').replace(/\s*[|—–]\s*Afrovanguard.*$/i, '').trim() || 'Afrovanguard';
    return { title: title, path: path, section: sec };
  }

  /* ---- state ---- */
  var history = [];
  try { history = JSON.parse(sessionStorage.getItem('chioma.history') || '[]') || []; } catch (e) {}
  var open = false, sending = false, greeted = false;
  try { greeted = sessionStorage.getItem('chioma.greeted') === '1'; } catch (e) {}

  /* ---- build DOM ---- */
  var root = document.createElement('div'); root.className = 'chioma-root'; root.setAttribute('data-no-export', '');
  if (document.documentElement.getAttribute('data-theme') === 'dark') root.setAttribute('data-dark', '1');
  root.innerHTML =
    '<div class="chioma-panel" role="dialog" aria-label="Chat with Chioma, the Afrovanguard guide" aria-modal="false">' +
      '<div class="ch-head"><span class="ch-ava">' + FACE + '</span>' +
        '<div><div class="ch-name">Chioma</div><div class="ch-status">Afrovanguard guide</div></div>' +
        '<button class="ch-x" aria-label="Close chat">&times;</button></div>' +
      '<div class="ch-body" id="chBody" aria-live="polite"></div>' +
      '<div class="ch-chips" id="chChips"></div>' +
      '<form class="ch-foot" id="chForm"><input id="chInput" type="text" autocomplete="off" placeholder="Ask Chioma anything…" aria-label="Message Chioma" maxlength="1500" />' +
        '<button class="ch-send" type="submit" aria-label="Send">' + SEND + '</button></form>' +
      '<div class="ch-disclaimer">Chioma is an AI guide — double-check anything important.</div>' +
    '</div>' +
    '<div class="chioma-greet" id="chGreet"><button class="ch-greet-x" aria-label="Dismiss">&times;</button>' +
      '<b>Hi, I’m Chioma</b> — your guide to Afrovanguard. Need a hand finding something? 👋</div>' +
    '<button class="chioma-fab" id="chFab" aria-label="Chat with Chioma" aria-expanded="false">' + FACE + '<span class="ch-dot"></span></button>';
  (document.body || document.documentElement).appendChild(root);

  var body = root.querySelector('#chBody'), input = root.querySelector('#chInput'),
      chips = root.querySelector('#chChips'), greet = root.querySelector('#chGreet'),
      fab = root.querySelector('#chFab');

  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
  // light markup: linkify /paths and bare urls, keep it safe (escaped first)
  function fmt(t) {
    t = esc(t);
    t = t.replace(/(https?:\/\/[^\s)]+)/g, '<a href="$1" target="_blank" rel="noopener">$1</a>');
    t = t.replace(/(^|[\s(])(\/[a-z0-9][a-z0-9\/\-]*\.?[a-z]*)/gi, function (m, pre, p) { return pre + '<a href="' + p + '">' + p + '</a>'; });
    return t;
  }
  function addMsg(role, text) {
    var d = document.createElement('div'); d.className = 'ch-msg ' + (role === 'user' ? 'user' : 'bot');
    d.innerHTML = role === 'user' ? esc(text) : fmt(text);
    body.appendChild(d); body.scrollTop = body.scrollHeight; return d;
  }
  function typing(on) {
    var ex = body.querySelector('.ch-typing');
    if (on && !ex) { var t = document.createElement('div'); t.className = 'ch-typing'; t.innerHTML = '<span></span><span></span><span></span>'; body.appendChild(t); body.scrollTop = body.scrollHeight; }
    else if (!on && ex) ex.remove();
  }
  function saveHistory() { try { sessionStorage.setItem('chioma.history', JSON.stringify(history.slice(-20))); } catch (e) {} }

  var CHIPS = [['Explore the Academy', 'What can I learn in the Academy?'], ['How can I donate?', 'How can I donate?'],
               ['Get involved', 'How can I get involved or volunteer?'], ['What is Afrovanguard?', 'What is Afrovanguard about?']];
  function renderChips(show) {
    chips.innerHTML = '';
    if (!show) return;
    CHIPS.forEach(function (c) { var b = document.createElement('button'); b.className = 'ch-chip'; b.type = 'button'; b.textContent = c[0]; b.onclick = function () { sendMessage(c[1]); }; chips.appendChild(b); });
  }

  function renderHistory() {
    body.innerHTML = '';
    if (!history.length) {
      addMsg('bot', "Hi, I'm Chioma — your Afrovanguard guide! 🌍 Ask me about our free Academy, our projects, how to donate, or how to get involved.");
      renderChips(true);
    } else {
      history.forEach(function (h) { addMsg(h.role, h.text); });
      renderChips(false);
    }
  }

  function setOpen(on) {
    open = on; root.classList.toggle('is-open', on); fab.setAttribute('aria-expanded', String(on));
    if (on) {
      hideGreet();
      requestAnimationFrame(function () { root.classList.add('is-anim'); });
      renderHistory();
      setTimeout(function () { input.focus(); }, 60);
    } else { root.classList.remove('is-anim'); }
  }
  function hideGreet() { greet.classList.remove('show'); }

  function sendMessage(text) {
    text = (text || '').trim(); if (!text || sending) return;
    renderChips(false);
    addMsg('user', text); history.push({ role: 'user', text: text }); saveHistory();
    input.value = ''; sending = true; typing(true);
    fetch(ENDPOINT, { method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'same-origin',
      body: JSON.stringify({ message: text, history: history.slice(-12), page: context() }) })
      .then(function (r) { return r.json().catch(function () { return { ok: false }; }); })
      .then(function (d) {
        typing(false); sending = false;
        var reply = (d && d.reply) || (d && d.error) || "Sorry, I had trouble just then. You can always reach the team via /contact/.";
        addMsg('bot', reply); history.push({ role: 'bot', text: reply }); saveHistory();
      })
      .catch(function () { typing(false); sending = false; addMsg('bot', "I couldn't reach the server. Please check your connection and try again — or visit /contact/."); });
  }

  /* ---- public API (lets existing "chat with us" buttons open Chioma) ---- */
  window.chioma = {
    open: function (msg) { setOpen(true); if (msg) setTimeout(function () { sendMessage(msg); }, 200); },
    close: function () { setOpen(false); },
    toggle: function () { setOpen(!open); },
    // Programmatic ask — no UI. Resolves to Chioma's reply text. Lets your own
    // AI agent / scripts converse with Chioma in the browser.
    //   chioma.ask('How do I donate?').then(function (reply) { ... });
    ask: function (text, opts) {
      opts = opts || {};
      return fetch(ENDPOINT, { method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'same-origin',
        body: JSON.stringify({ message: String(text || ''), history: opts.history || [], page: opts.page || context() }) })
        .then(function (r) { return r.json(); })
        .then(function (d) { if (!d || d.reply == null) throw new Error((d && d.error) || 'Chioma had trouble.'); return d.reply; });
    }
  };
  // Back-compat shim for the old Botpress hooks that pages may still call.
  if (!window.botpress) window.botpress = { open: function () { setOpen(true); }, close: function () { setOpen(false); }, sendEvent: function () {} };

  /* ---- events ---- */
  fab.addEventListener('click', function () { setOpen(!open); });
  root.querySelector('.ch-x').addEventListener('click', function () { setOpen(false); });
  root.querySelector('#chForm').addEventListener('submit', function (e) { e.preventDefault(); sendMessage(input.value); });
  greet.querySelector('.ch-greet-x').addEventListener('click', function (e) { e.stopPropagation(); hideGreet(); try { sessionStorage.setItem('chioma.greeted', '1'); } catch (e2) {} });
  greet.addEventListener('click', function () { setOpen(true); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && open) setOpen(false); });

  /* ---- lively greeting nudge (once per session, if not opened) ---- */
  if (!greeted) {
    setTimeout(function () { if (!open) { greet.classList.add('show'); try { sessionStorage.setItem('chioma.greeted', '1'); } catch (e) {} setTimeout(hideGreet, 9000); } }, 6500);
  }
})();
