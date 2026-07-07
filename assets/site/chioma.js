/* ============================================================
   Chioma — Afrovanguard's AI site guide (floating assistant).
   Self-contained: injects its own styles + DOM on every page,
   is context-aware (sends the current page), and lively.

   2026 redesign — one launcher for the whole site:
   • Quick-actions row (WhatsApp / call / email — the old "Quick
     Contact" dock's channels; the dock itself is retired in CSS).
   • Voice input via the browser's free Web Speech API (feature-
     detected; the mic simply hides where unsupported).
   • 👍/👎 feedback + copy on replies, timestamps, maximize mode,
     Alt+C shortcut, cross-page chat continuity, retry on failure.
   ============================================================ */
(function () {
  'use strict';
  if (window.__chioma) return; window.__chioma = true;
  if (document.documentElement.hasAttribute('data-no-chioma')) return;

  var ENDPOINT = '/chioma.php';
  // Chioma's mark — SHE is the icon: a woman in a gele (Nigerian head-wrap),
  // inside an open arc whose gap cradles her idea-spark. The wrap's fold flicks
  // out on the left, balancing the spark on the right. Inline SVG only (CSP
  // forbids external images). Crisp from 15px (message avatar) to 60px (FAB).
  var FACE = '<svg class="ch-mark" viewBox="0 0 48 48" fill="none" aria-hidden="true" focusable="false">' +
      '<path d="M32.9 5A21 21 0 1 0 43 15.1" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-opacity=".4"/>' +
      '<path d="M16.2 13.4c-1.9-.7-3.1-2.3-3-4 1.8-.3 3.6.5 4.6 2 .5.8-.7 2.3-1.6 2Z" fill="currentColor"/>' +
      '<path d="M15.2 18.8c-.7-6.4 3.6-10.6 8.8-10.6s9.5 4.2 8.8 10.6c-1.1-2.2-2.9-3.4-5.1-3.7-1.6-.2-2.7-.7-3.7-1.8-1 1.1-2.1 1.6-3.7 1.8-2.2.3-4 1.5-5.1 3.7Z" fill="currentColor"/>' +
      '<circle cx="24" cy="22" r="6" fill="currentColor"/>' +
      '<path d="M24 30c-6.7 0-12 4.3-12.8 10a21 21 0 0 0 25.6 0C36 34.3 30.7 30 24 30Z" fill="currentColor"/>' +
      '<path class="ch-spark" d="M38.5 4.5l1.4 3.6 3.6 1.4-3.6 1.4-1.4 3.6-1.4-3.6-3.6-1.4 3.6-1.4 1.4-3.6Z" fill="var(--ch-spark, #fff)"/>' +
    '</svg>';
  var SEND = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M22 2 11 13M22 2l-7 20-4-9-9-4 20-7z"/></svg>';
  var MIC = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M12 2a3 3 0 0 0-3 3v7a3 3 0 0 0 6 0V5a3 3 0 0 0-3-3Z"/><path d="M19 10v2a7 7 0 0 1-14 0v-2"/><line x1="12" y1="19" x2="12" y2="22"/></svg>';
  var MAX = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true" focusable="false"><path d="M15 3h6v6M9 21H3v-6M21 3l-7 7M3 21l7-7"/></svg>';
  var THUMB = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M7 10v12M15 5.88 14 10h5.83a2 2 0 0 1 1.92 2.56l-2.33 8A2 2 0 0 1 17.5 22H4a2 2 0 0 1-2-2v-8a2 2 0 0 1 2-2h2.76a2 2 0 0 0 1.79-1.11L12 2a3.13 3.13 0 0 1 3 3.88Z"/></svg>';
  var COPY = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>';
  var ACTION_ICONS = {
    whatsapp: '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" focusable="false"><path d="M17.47 14.38c-.3-.15-1.76-.87-2.03-.97-.27-.1-.47-.15-.67.15-.2.3-.77.97-.94 1.16-.17.2-.35.22-.64.08-.3-.15-1.26-.46-2.39-1.48-.88-.79-1.48-1.76-1.65-2.06-.17-.3-.02-.46.13-.6.13-.13.3-.35.45-.52.15-.17.2-.3.3-.5.1-.2.05-.37-.03-.52-.07-.15-.67-1.61-.91-2.2-.24-.58-.49-.5-.67-.51h-.57c-.2 0-.52.07-.8.37-.27.3-1.04 1.02-1.04 2.48 0 1.46 1.07 2.87 1.22 3.07.15.2 2.1 3.2 5.08 4.49.7.3 1.26.49 1.7.62.71.23 1.36.2 1.87.12.57-.08 1.76-.72 2-1.41.25-.7.25-1.29.18-1.41-.08-.13-.27-.2-.57-.35m-5.42 7.4h-.01a9.87 9.87 0 0 1-5.03-1.37l-.36-.21-3.74.98 1-3.65-.24-.37a9.86 9.86 0 0 1-1.51-5.26c0-5.45 4.44-9.88 9.89-9.88a9.82 9.82 0 0 1 9.88 9.89c0 5.45-4.43 9.88-9.88 9.88m8.42-18.3A11.82 11.82 0 0 0 12.05 0C5.5 0 .16 5.34.16 11.9c0 2.1.55 4.14 1.59 5.94L.06 24l6.3-1.65a11.88 11.88 0 0 0 5.69 1.45c6.55 0 11.89-5.34 11.89-11.9 0-3.18-1.24-6.16-3.48-8.41z"/></svg>',
    call: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 3.07 9.81a19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 2 1h3a2 2 0 0 1 2 1.72c.13.96.36 1.9.7 2.81a2 2 0 0 1-.45 2.11L6.09 8.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.91.34 1.85.57 2.81.7A2 2 0 0 1 22 16.92z"/></svg>',
    email: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>'
  };
  // Contact channels shown in the quick-actions row (the old dock's items).
  // Pages can override before this script loads: window.CHIOMA_ACTIONS = [...]
  var ACTIONS = (Array.isArray(window.CHIOMA_ACTIONS) && window.CHIOMA_ACTIONS.length) ? window.CHIOMA_ACTIONS : [
    { key: 'whatsapp', label: 'WhatsApp', href: 'https://wa.me/2349037776318', blank: true },
    { key: 'call',     label: 'Call us',  href: 'tel:+2349037776318' },
    { key: 'email',    label: 'Email',    href: 'mailto:cacentre@afrovanguard.org.ng' }
  ];

  /* ---- ensure stylesheet ---- */
  if (!document.querySelector('link[href="/assets/site/chioma.css"]')) {
    var l = document.createElement('link'); l.rel = 'stylesheet'; l.href = '/assets/site/chioma.css'; document.head.appendChild(l);
  }

  /* ---- page context ---- */
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
  // Stable per-visitor conversation id — a connected AI agent (Make etc.)
  // keys its memory on this, turning Chioma into a true executive assistant.
  var sessionId = '';
  try {
    sessionId = sessionStorage.getItem('chioma.session') || '';
    if (!sessionId) {
      sessionId = 'ch-' + Date.now().toString(36) + '-' + Math.random().toString(36).slice(2, 10);
      sessionStorage.setItem('chioma.session', sessionId);
    }
  } catch (e) { sessionId = 'ch-' + Date.now().toString(36); }
  var open = false, sending = false, greeted = false;
  try { greeted = sessionStorage.getItem('chioma.greeted') === '1' || sessionStorage.getItem('av_onboarded_v2') != null; } catch (e) {}

  /* ---- build DOM ---- */
  var root = document.createElement('div'); root.className = 'chioma-root'; root.setAttribute('data-no-export', '');
  if (document.documentElement.getAttribute('data-theme') === 'dark') root.setAttribute('data-dark', '1');
  var actionsHtml = ACTIONS.map(function (a) {
    var ico = ACTION_ICONS[a.key] || ACTION_ICONS.email;
    return '<a class="ch-act ch-act--' + a.key + '" href="' + a.href + '"' + (a.blank ? ' target="_blank" rel="noopener"' : '') + '>' + ico + '<span>' + a.label + '</span></a>';
  }).join('');
  root.innerHTML =
    '<div class="chioma-panel" role="dialog" aria-label="Chat with Chioma, the Afrovanguard guide" aria-modal="false">' +
      '<div class="ch-head"><span class="ch-ava">' + FACE + '</span>' +
        '<div><div class="ch-name">Chioma</div><div class="ch-status">Online · replies instantly</div></div>' +
        '<div class="ch-hbtns">' +
          '<button class="ch-x ch-max" type="button" aria-label="Maximize chat" aria-pressed="false" title="Maximize">' + MAX + '</button>' +
          '<button class="ch-x ch-restart" type="button" aria-label="Restart conversation" title="Restart conversation">↺</button>' +
          '<button class="ch-x ch-close" type="button" aria-label="Close chat">&times;</button>' +
        '</div></div>' +
      '<div class="ch-actions" role="group" aria-label="Contact the team directly">' + actionsHtml + '</div>' +
      '<div class="ch-body" id="chBody" aria-live="polite"></div>' +
      '<div class="ch-chips" id="chChips"></div>' +
      '<form class="ch-foot" id="chForm">' +
        '<span class="ch-inwrap">' +
          '<input id="chInput" type="text" autocomplete="off" placeholder="Ask Chioma anything…" aria-label="Message Chioma" maxlength="1500" />' +
          '<button class="ch-mic" type="button" aria-label="Speak your message" aria-pressed="false" title="Speak instead of typing">' + MIC + '</button>' +
        '</span>' +
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
      greetText = greet.querySelector('.ch-greet-text'), fab = root.querySelector('#chFab'),
      sendBtn = root.querySelector('.ch-send'), mic = root.querySelector('.ch-mic'),
      maxBtn = root.querySelector('.ch-max');

  // The pulsing dot is an attention affordance — once the visitor has opened
  // the chat this session, it has done its job and retires.
  try { if (sessionStorage.getItem('chioma.opened') === '1') root.classList.add('ch-seen'); } catch (e) {}

  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
  // light markup: linkify /paths and bare urls, keep it safe (escaped first)
  function fmt(t) {
    t = esc(t);
    t = t.replace(/(https?:\/\/[^\s)]+)/g, '<a href="$1" target="_blank" rel="noopener">$1</a>');
    t = t.replace(/(^|[\s(])(\/[a-z0-9][a-z0-9\/\-]*\.?[a-z]*)/gi, function (m, pre, p) { return pre + '<a href="' + p + '">' + p + '</a>'; });
    return t;
  }
  function fmtTime(ts) {
    var d = ts ? new Date(ts) : new Date();
    var h = d.getHours(), m = d.getMinutes(), ap = h >= 12 ? 'PM' : 'AM';
    h = h % 12 || 12;
    return h + ':' + (m < 10 ? '0' : '') + m + ' ' + ap;
  }
  function dayLabel(ts) {
    if (!ts) return 'Today';
    var d = new Date(ts), n = new Date();
    var dd = new Date(d.getFullYear(), d.getMonth(), d.getDate());
    var nn = new Date(n.getFullYear(), n.getMonth(), n.getDate());
    var diff = Math.round((nn - dd) / 86400000);
    if (diff <= 0) return 'Today';
    if (diff === 1) return 'Yesterday';
    try { return d.toLocaleDateString(undefined, { month: 'short', day: 'numeric' }); } catch (e) { return 'Earlier'; }
  }

  /* ---- reply feedback + copy ---- */
  function toolBtn(svg, label, flip) {
    var b = document.createElement('button');
    b.type = 'button'; b.className = 'ch-tool'; b.setAttribute('aria-label', label); b.title = label;
    b.innerHTML = svg;
    if (flip) b.firstChild.style.transform = 'rotate(180deg)';
    return b;
  }
  function sendRating(dir, text) {
    // Best-effort — feedback must never interrupt the conversation.
    try {
      fetch(ENDPOINT, { method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'same-origin',
        body: JSON.stringify({ rate: dir, text: String(text || '').slice(0, 600), page: context() }) }).catch(function () {});
    } catch (e) {}
  }
  function copyText(text, btn) {
    var done = function () { btn.classList.add('is-on'); btn.title = 'Copied!'; setTimeout(function () { btn.classList.remove('is-on'); btn.title = 'Copy reply'; }, 1400); };
    if (navigator.clipboard && navigator.clipboard.writeText) { navigator.clipboard.writeText(text).then(done, function () {}); return; }
    try {
      var ta = document.createElement('textarea'); ta.value = text; ta.style.position = 'fixed'; ta.style.opacity = '0';
      document.body.appendChild(ta); ta.select(); document.execCommand('copy'); ta.remove(); done();
    } catch (e) {}
  }
  function buildTools(text, ts) {
    var tools = document.createElement('div'); tools.className = 'ch-tools';
    var up = toolBtn(THUMB, 'Helpful'), down = toolBtn(THUMB, 'Not helpful', true), cp = toolBtn(COPY, 'Copy reply');
    up.addEventListener('click', function () { up.classList.add('is-on'); down.classList.remove('is-on'); sendRating('up', text); });
    down.addEventListener('click', function () { down.classList.add('is-on'); up.classList.remove('is-on'); sendRating('down', text); });
    cp.addEventListener('click', function () { copyText(text, cp); });
    var t = document.createElement('span'); t.className = 'ch-time'; t.textContent = fmtTime(ts);
    tools.appendChild(up); tools.appendChild(down); tools.appendChild(cp); tools.appendChild(t);
    return tools;
  }

  /* ---- message rendering (grouped, with avatar + metadata) ---- */
  function addMsg(role, text, ts) {
    var msg = document.createElement('div');
    msg.className = 'ch-msg ' + (role === 'user' ? 'user' : 'bot');
    msg.innerHTML = role === 'user' ? esc(text) : fmt(text);
    if (role === 'user') {
      var g = document.createElement('div'); g.className = 'ch-group user';
      var t = document.createElement('span'); t.className = 'ch-time'; t.textContent = fmtTime(ts);
      g.appendChild(msg); g.appendChild(t);
      body.appendChild(g);
    } else {
      var row = document.createElement('div'); row.className = 'ch-row';
      row.innerHTML = '<span class="ch-bava">' + FACE + '</span>';
      var grp = document.createElement('div'); grp.className = 'ch-group';
      grp.appendChild(msg); grp.appendChild(buildTools(text, ts));
      row.appendChild(grp);
      body.appendChild(row);
    }
    body.scrollTop = body.scrollHeight;
    return msg;
  }
  function typing(on) {
    var ex = body.querySelector('.ch-typing-row');
    if (on && !ex) {
      var r = document.createElement('div'); r.className = 'ch-row ch-typing-row';
      r.innerHTML = '<span class="ch-bava">' + FACE + '</span><div class="ch-typing" role="status" aria-label="Chioma is typing"><span></span><span></span><span></span></div>';
      body.appendChild(r); body.scrollTop = body.scrollHeight;
    } else if (!on && ex) ex.remove();
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
    var day = document.createElement('div'); day.className = 'ch-day';
    day.textContent = dayLabel(history.length ? history[0].t : 0);
    body.appendChild(day);
    if (!history.length) {
      addMsg('bot', "Hi, I'm Chioma — your Afrovanguard guide! 🌍 Ask me about our free Academy, our projects, how to donate, or how to get involved.");
      renderChips(true);
    } else {
      history.forEach(function (h) { addMsg(h.role, h.text, h.t); });
      renderChips(false);
    }
  }

  // On phones the panel is a FULL-SCREEN overlay — lock the page scroll
  // behind it (and restore whatever was there before, e.g. a nav drawer's).
  var MOBILE_MQ = window.matchMedia ? window.matchMedia('(max-width: 640px)') : null;
  var scrollWasLocked = false, prevOverflow = '';
  function lockScroll(on) {
    if (on && MOBILE_MQ && MOBILE_MQ.matches && !scrollWasLocked) {
      prevOverflow = document.body.style.overflow; document.body.style.overflow = 'hidden'; scrollWasLocked = true;
    } else if (!on && scrollWasLocked) {
      document.body.style.overflow = prevOverflow; scrollWasLocked = false;
    }
  }
  function setOpen(on, opts) {
    opts = opts || {};
    var hadFocus = root.contains(document.activeElement);
    open = on; root.classList.toggle('is-open', on); fab.setAttribute('aria-expanded', String(on));
    try { sessionStorage.setItem('chioma.open', on ? '1' : '0'); } catch (e) {}
    lockScroll(on);
    if (on) {
      try { sessionStorage.setItem('chioma.opened', '1'); } catch (e) {}
      root.classList.add('ch-seen');
      muteBubbles();          // opening chat retires the nudges for this session
      hideGreet();
      requestAnimationFrame(function () { root.classList.add('is-anim'); });
      renderHistory();
      if (opts.focus !== false) setTimeout(function () { input.focus(); }, 60);
    } else {
      root.classList.remove('is-anim');
      stopListen();
      // Keyboard/AT flow: closing the dialog hands focus back to its trigger.
      if (hadFocus) fab.focus();
    }
  }
  function hideGreet() { greet.classList.remove('show'); fab.classList.remove('ch-nudge'); }

  var lastSent = '';
  function setSending(on) {
    sending = on;
    if (sendBtn) sendBtn.disabled = on;
    typing(on);
  }
  function sendMessage(text, opts) {
    opts = opts || {};
    text = (text || '').trim(); if (!text || sending) return;
    stopListen();
    renderChips(false);
    // A retry resends the SAME message — don't repeat it in the transcript.
    if (!opts.resend) { addMsg('user', text); history.push({ role: 'user', text: text, t: Date.now() }); saveHistory(); }
    lastSent = text;
    input.value = ''; setSending(true);
    fetch(ENDPOINT, { method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'same-origin',
      body: JSON.stringify({ message: text, history: history.slice(-12), page: context(), session: sessionId }) })
      .then(function (r) { return r.json().catch(function () { return { ok: false }; }); })
      .then(function (d) {
        setSending(false);
        var reply = (d && d.reply) || (d && d.error) || "Sorry, I had trouble just then. You can always reach the team via /contact/.";
        var msg = addMsg('bot', reply); history.push({ role: 'bot', text: reply, t: Date.now() }); saveHistory();
        // Agent-supplied next steps → tappable buttons under the reply.
        if (d && Array.isArray(d.actions) && d.actions.length) {
          var wrap = document.createElement('div'); wrap.className = 'ch-msg-actions';
          d.actions.slice(0, 6).forEach(function (a) {
            if (!a || !a.label || !a.url) return;
            var link = document.createElement('a');
            link.className = 'ch-action'; link.textContent = a.label; link.href = a.url;
            if (/^https?:/i.test(a.url)) { link.target = '_blank'; link.rel = 'noopener'; }
            wrap.appendChild(link);
          });
          if (wrap.children.length) { msg.parentElement.insertBefore(wrap, msg.nextSibling); body.scrollTop = body.scrollHeight; }
        }
      })
      .catch(function () {
        setSending(false);
        var d = addMsg('bot', "I couldn't reach the server just now — check your connection. ");
        var b = document.createElement('button');
        b.type = 'button'; b.className = 'ch-retry'; b.textContent = 'Try again';
        b.addEventListener('click', function () { var row = d.closest('.ch-row'); if (row) row.remove(); sendMessage(lastSent, { resend: true }); });
        d.appendChild(b);
        body.scrollTop = body.scrollHeight;
      });
  }

  function restartChat() {
    history = []; saveHistory();
    renderHistory();
    input.focus();
  }

  /* ---- voice input — the browser's FREE Web Speech API. Feature-detected:
     the mic hides entirely where unsupported (Firefox without flags). Live
     interim results stream into the input; recognition is tuned for
     Nigerian English and stops itself at end of speech. ---- */
  var SR = window.SpeechRecognition || window.webkitSpeechRecognition;
  var recog = null, listening = false, micBase = '';
  if (!SR) { mic.hidden = true; }
  function stopUI() {
    listening = false;
    mic.classList.remove('listening'); mic.setAttribute('aria-pressed', 'false');
    input.placeholder = 'Ask Chioma anything…';
  }
  function stopListen() { if (recog && listening) { try { recog.stop(); } catch (e) {} } stopUI(); }
  function startListen() {
    if (!SR || listening) return;
    try {
      recog = new SR();
      // en-NG gives the most accurate results for Nigerian voices; the page
      // language wins when it's something more specific than bare "en".
      var lang = document.documentElement.lang || '';
      recog.lang = (lang && lang.indexOf('-') > 0) ? lang : 'en-NG';
      recog.interimResults = true;
      recog.continuous = false;
      recog.maxAlternatives = 1;
      micBase = input.value.trim();
      recog.onresult = function (e) {
        var final = '', interim = '';
        for (var i = e.resultIndex; i < e.results.length; i++) {
          if (e.results[i].isFinal) final += e.results[i][0].transcript;
          else interim += e.results[i][0].transcript;
        }
        input.value = (micBase ? micBase + ' ' : '') + (final || interim).trim();
      };
      recog.onend = function () { stopUI(); if (input.value.trim()) input.focus(); };
      recog.onerror = function (ev) {
        stopUI();
        if (ev && ev.error === 'not-allowed') {
          addMsg('bot', 'I need microphone permission to hear you — allow it in your browser, or just type. 🙂');
        }
      };
      recog.start();
      listening = true;
      mic.classList.add('listening'); mic.setAttribute('aria-pressed', 'true');
      input.placeholder = 'Listening…';
    } catch (e) { stopUI(); }
  }
  if (SR) mic.addEventListener('click', function () { listening ? stopListen() : startListen(); });

  /* ---- public API (lets existing "chat with us" buttons open Chioma) ---- */
  window.chioma = {
    open: function (msg) { setOpen(true); if (msg) setTimeout(function () { sendMessage(msg); }, 200); },
    close: function () { setOpen(false); },
    toggle: function () { setOpen(!open); },
    // Programmatic ask — no UI. Resolves to Chioma's reply text.
    ask: function (text, opts) {
      opts = opts || {};
      return fetch(ENDPOINT, { method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'same-origin',
        body: JSON.stringify({ message: String(text || ''), history: opts.history || [], page: opts.page || context(), session: opts.session || sessionId }) })
        .then(function (r) { return r.json(); })
        .then(function (d) { if (!d || d.reply == null) throw new Error((d && d.error) || 'Chioma had trouble.'); return d.reply; });
    }
  };
  // Back-compat shim for the old Botpress/dock hooks that pages may still call.
  if (!window.botpress) window.botpress = { open: function () { setOpen(true); }, close: function () { setOpen(false); }, sendEvent: function () {} };

  /* ---- contextual "thought" bubble engine ---- */
  var BUBBLE_CAP = 2, bubbleTimer = null, hideTimer = null;
  function ss(k) { try { return sessionStorage.getItem(k); } catch (e) { return null; } }
  function ssSet(k, v) { try { sessionStorage.setItem(k, v); } catch (e) {} }
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
    scheduleBubble(38000);
  }
  function scheduleBubble(delay) {
    if (bubbleTimer) clearTimeout(bubbleTimer);
    if (muted() || bubbleCount() >= BUBBLE_CAP) return;
    bubbleTimer = setTimeout(function () { if (!open && !muted()) showBubble(esc(nextPhrase())); }, delay);
  }

  /* ---- events ---- */
  fab.addEventListener('click', function () { setOpen(!open); });
  root.querySelector('.ch-close').addEventListener('click', function () { setOpen(false); });
  root.querySelector('.ch-restart').addEventListener('click', function () {
    if (!history.length || confirm('Start a fresh conversation with Chioma?')) restartChat();
  });
  maxBtn.addEventListener('click', function () {
    var on = !root.classList.contains('is-max');
    root.classList.toggle('is-max', on);
    maxBtn.setAttribute('aria-pressed', String(on));
    maxBtn.title = on ? 'Restore size' : 'Maximize';
    maxBtn.setAttribute('aria-label', on ? 'Restore chat size' : 'Maximize chat');
    body.scrollTop = body.scrollHeight;
  });
  root.querySelector('#chForm').addEventListener('submit', function (e) { e.preventDefault(); sendMessage(input.value); });
  greet.querySelector('.ch-greet-x').addEventListener('click', function (e) { e.stopPropagation(); hideGreet(); muteBubbles(); });
  greet.addEventListener('click', function () { setOpen(true); });
  document.addEventListener('click', function (e) {
    if (greet.classList.contains('show') && !root.contains(e.target)) { hideGreet(); }
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
      if (listening) { stopListen(); return; }
      if (open) setOpen(false);
      else if (greet.classList.contains('show')) { hideGreet(); muteBubbles(); }
      return;
    }
    // Alt+C opens/closes Chioma from anywhere on the page.
    if (e.altKey && !e.ctrlKey && !e.metaKey && (e.key === 'c' || e.key === 'C')) { e.preventDefault(); setOpen(!open); }
  });

  /* ---- continuity: if the chat was open when the visitor navigated (Chioma
     often links to other pages mid-conversation), reopen it with the same
     transcript — without stealing keyboard focus from the new page. */
  if (ss('chioma.open') === '1' && history.length) {
    setOpen(true, { focus: false });
  }

  /* ---- first nudge shortly after load (once per session, if not opened) ---- */
  if (!greeted && !muted()) {
    bubbleTimer = setTimeout(function () {
      bubbleTimer = null;
      if (open || muted() || bubbleCount() >= BUBBLE_CAP) return;
      greet.classList.add('show'); fab.classList.add('ch-nudge');
      setTimeout(function () { fab.classList.remove('ch-nudge'); }, 1400);
      ssSet('chioma.bubbles', String(bubbleCount() + 1)); ssSet('chioma.greeted', '1');
      if (hideTimer) clearTimeout(hideTimer);
      hideTimer = setTimeout(hideGreet, 8000);
      scheduleBubble(40000);
    }, 5500);
  }
})();
