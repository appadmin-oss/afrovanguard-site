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
  // Chioma's mark — a warm "operations assistant" persona, not a robot.
  // A friendly figure (head + shoulders) inside a soft ring, with a small
  // gold "thinking/idea" spark — she's the helpful person who has the answer.
  // Inline SVG only (CSP forbids external images). Crisp at 56–64px.
  var FACE = '<svg class="ch-mark" viewBox="0 0 48 48" fill="none" aria-hidden="true" focusable="false">' +
      '<circle cx="24" cy="24" r="22" fill="none" stroke="currentColor" stroke-width="2" stroke-opacity=".35"/>' +
      '<path d="M24 25.5a6.6 6.6 0 1 0 0-13.2 6.6 6.6 0 0 0 0 13.2Z" fill="currentColor"/>' +
      '<path d="M11.6 38.4a12.6 12.6 0 0 1 24.8 0 21.7 21.7 0 0 1-24.8 0Z" fill="currentColor"/>' +
      '<path class="ch-spark" d="M36.4 9.2l1.15 2.95L40.5 13.3l-2.95 1.15L36.4 17.4l-1.15-2.95L32.3 13.3l2.95-1.15L36.4 9.2Z" fill="var(--ch-spark, #fff)"/>' +
    '</svg>';
  var SEND = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M22 2 11 13M22 2l-7 20-4-9-9-4 20-7z"/></svg>';

  /* ---- ensure stylesheet ---- */
  if (!document.querySelector('link[href="/assets/site/chioma.css"]')) {
    var l = document.createElement('link'); l.rel = 'stylesheet'; l.href = '/assets/site/chioma.css'; document.head.appendChild(l);
  }

  /* ---- page context ---- */
  // Normalise the current location into a short route key used for both the
  // server context.section and the contextual "thought" bubble below.
  function routeKey() {
    var path = location.pathname.toLowerCase();
    if (/^\/academy/.test(path)) return 'academy';
    if (/^\/diary/.test(path) || /^\/blog/.test(path)) return 'diary';
    if (/^\/projects/.test(path)) return 'projects';
    if (/donate/.test(path)) return 'donate';
    if (/contact/.test(path)) return 'contact';
    if (/^\/about/.test(path)) return 'about';
    if (/^\/login/.test(path) || /^\/auth/.test(path)) return 'login';
    if (/^\/portal/.test(path) || /^\/member/.test(path) || /^\/donor-dashboard/.test(path)) return 'portal';
    if (/^\/events/.test(path)) return 'events';
    if (path === '/' || /^\/index/.test(path) || path === '') return 'home';
    return 'default';
  }
  var SECTION_LABEL = { academy: 'Academy', diary: 'Diary', projects: 'Projects', donate: 'Donate',
    contact: 'Contact', about: 'About', login: 'Login', portal: 'Portal', events: 'Events', home: 'Home', default: '' };
  function context() {
    var sec = document.body.getAttribute('data-section') || SECTION_LABEL[routeKey()] || '';
    var title = (document.title || '').replace(/\s*[|—–]\s*Afrovanguard.*$/i, '').trim() || 'Afrovanguard';
    return { title: title, path: location.pathname, section: sec };
  }

  /* ---- contextual "thought" bubble phrases, by route ---- */
  var BUBBLES = {
    home:     ['New here? I can point you to the right programme.', "Want to see what's happening this week?", 'Looking for something? Just ask me.'],
    academy:  ['Looking for a course? I can help you choose.', 'Ask me how enrolment works.', 'Our programmes are free — want the details?'],
    diary:    ['Want a quick summary of an entry?', 'Looking for a topic? Ask me.', 'Curious about the work behind a story?'],
    donate:   ["Not sure how to give? I'll walk you through it.", 'We welcome materials too — ask me how.', 'Questions about donating? I’m right here.'],
    projects: ['Curious which programme fits you? Ask away.', 'Want the story behind a project?', 'I can help you find a way to get involved.'],
    contact:  ['Not sure who to reach? I can point you.', 'Tell me what you need and I’ll help you ask.'],
    about:    ['Want the short version of our mission? Ask me.', 'Curious how we got started? I can share.'],
    login:    ['Trouble signing in? I can help.', 'New here? Ask me what membership offers.'],
    portal:   ['Need a hand finding something here? Just ask.', 'Looking for your next step? I can point you.'],
    events:   ['Want to know what’s coming up? Ask me.', 'Looking for an event near you? I can help.'],
    default:  ['Need a hand finding something? Just ask me.', 'I can help you get where you’re going. 🙂']
  };

  /* ---- state ---- */
  var history = [];
  try { history = JSON.parse(sessionStorage.getItem('chioma.history') || '[]') || []; } catch (e) {}
  var open = false, sending = false, greeted = false;
  // Honour the greeting throttle AND the site's onboarding-suppression flags
  // (the test harness sets these to keep nudges out of the way).
  try { greeted = sessionStorage.getItem('chioma.greeted') === '1' || sessionStorage.getItem('av_onboarded_v2') != null; } catch (e) {}

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
    '<div class="chioma-greet" id="chGreet" role="status" aria-live="polite">' +
      '<button class="ch-greet-x" type="button" aria-label="Dismiss message">&times;</button>' +
      '<span class="ch-greet-text"><b>Hi, I’m Chioma</b> — your guide to Afrovanguard. Need a hand finding something? 👋</span></div>' +
    '<button class="chioma-fab" id="chFab" type="button" aria-label="Chat with Chioma" aria-expanded="false">' + FACE + '<span class="ch-dot"></span></button>';
  (document.body || document.documentElement).appendChild(root);

  var body = root.querySelector('#chBody'), input = root.querySelector('#chInput'),
      chips = root.querySelector('#chChips'), greet = root.querySelector('#chGreet'),
      greetText = greet.querySelector('.ch-greet-text'), fab = root.querySelector('#chFab');

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
      muteBubbles();          // opening chat retires the nudges for this session
      hideGreet();
      requestAnimationFrame(function () { root.classList.add('is-anim'); });
      renderHistory();
      setTimeout(function () { input.focus(); }, 60);
    } else { root.classList.remove('is-anim'); }
  }
  function hideGreet() { greet.classList.remove('show'); fab.classList.remove('ch-nudge'); }

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

  /* ---- contextual "thought" bubble engine ----
     Surfaces a short, page-aware nudge near the FAB. Tasteful, not naggy:
     shows at most a couple of times per session, never after the chat is
     opened or a bubble is dismissed, and auto-hides after ~8s. */
  var BUBBLE_CAP = 2, bubbleTimer = null, hideTimer = null;
  function ss(k) { try { return sessionStorage.getItem(k); } catch (e) { return null; } }
  function ssSet(k, v) { try { sessionStorage.setItem(k, v); } catch (e) {} }
  // Honour the site's onboarding/greeting suppression flags (used in tests too).
  function muted() { return ss('chioma.muted') === '1'; }
  function muteBubbles() {
    ssSet('chioma.muted', '1'); ssSet('chioma.greeted', '1');
    if (bubbleTimer) { clearTimeout(bubbleTimer); bubbleTimer = null; }
    if (hideTimer) { clearTimeout(hideTimer); hideTimer = null; }
  }
  function bubbleCount() { return parseInt(ss('chioma.bubbles') || '0', 10) || 0; }

  var rk = routeKey();
  function nextPhrase() {
    var list = BUBBLES[rk] || BUBBLES.default;
    var i = (parseInt(ss('chioma.phrase') || '0', 10) || 0) % list.length;
    ssSet('chioma.phrase', String(i + 1));
    return list[i];
  }
  function showBubble(html) {
    if (open || muted() || bubbleCount() >= BUBBLE_CAP) return;
    greetText.innerHTML = html;
    greet.classList.add('show'); fab.classList.add('ch-nudge');
    setTimeout(function () { fab.classList.remove('ch-nudge'); }, 1400);
    ssSet('chioma.bubbles', String(bubbleCount() + 1));
    if (hideTimer) clearTimeout(hideTimer);
    hideTimer = setTimeout(hideGreet, 8000);
    scheduleBubble(38000);  // maybe one more later, if still allowed
  }
  function scheduleBubble(delay) {
    if (bubbleTimer) clearTimeout(bubbleTimer);
    if (muted() || bubbleCount() >= BUBBLE_CAP) return;
    bubbleTimer = setTimeout(function () { if (!open && !muted()) showBubble(esc(nextPhrase())); }, delay);
  }

  /* ---- events ---- */
  fab.addEventListener('click', function () { setOpen(!open); });
  root.querySelector('.ch-x').addEventListener('click', function () { setOpen(false); });
  root.querySelector('#chForm').addEventListener('submit', function (e) { e.preventDefault(); sendMessage(input.value); });
  greet.querySelector('.ch-greet-x').addEventListener('click', function (e) { e.stopPropagation(); hideGreet(); muteBubbles(); });
  greet.addEventListener('click', function () { setOpen(true); });
  // Click-away dismiss (don't nag): a tap anywhere else retires the bubble.
  document.addEventListener('click', function (e) {
    if (greet.classList.contains('show') && !root.contains(e.target)) { hideGreet(); }
  });
  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape') return;
    if (open) setOpen(false);
    else if (greet.classList.contains('show')) { hideGreet(); muteBubbles(); }
  });

  /* ---- first nudge shortly after load (once per session, if not opened) ----
     The opening "Hi, I'm Chioma…" greeting is already in the bubble; the first
     timer reveals it, then schedules at most one later, page-aware follow-up. */
  if (!greeted && !muted()) {
    bubbleTimer = setTimeout(function () {
      bubbleTimer = null;
      if (open || muted() || bubbleCount() >= BUBBLE_CAP) return;
      greet.classList.add('show'); fab.classList.add('ch-nudge');
      setTimeout(function () { fab.classList.remove('ch-nudge'); }, 1400);
      ssSet('chioma.bubbles', String(bubbleCount() + 1)); ssSet('chioma.greeted', '1');
      if (hideTimer) clearTimeout(hideTimer);
      hideTimer = setTimeout(hideGreet, 8000);
      scheduleBubble(40000);  // a later, page-aware follow-up (if still allowed)
    }, 5500);
  }
})();
