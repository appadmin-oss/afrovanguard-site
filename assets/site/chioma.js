/* ============================================================
   Chioma — Afrovanguard's site guide.

   She can look things up and fill forms in, so this file has three jobs
   beyond "show messages":

     1. Say what she is doing. A tool call takes seconds; a chat that goes
        silent for eight of them reads as broken. The header status names
        the step while it runs.
     2. Show where an answer came from. Replies that used the site index
        carry the pages underneath them.
     3. Render the forms she stages — and submit them to the site's OWN
        endpoints (process-contact.php, academy/api.php), so the existing
        validation, rate limits and notifications all apply. Nothing is
        sent until the visitor presses the button themselves.

   Bot HTML arrives already rendered and sanitised by lib/ChiomaMarkdown.php
   (raw HTML stripped at the parser). This file never builds markup from
   model output; when `html` is absent it escapes the plain text instead.
   ============================================================ */
(function () {
  'use strict';
  if (window.__chioma) return; window.__chioma = true;
  if (document.documentElement.hasAttribute('data-no-chioma')) return;

  var ENDPOINT = '/chioma.php';
  var AVATAR   = '/assets/site/chioma-avatar.png';

  /* Chioma's mark — a symbol, not a portrait.

     An open circular arc that reads as a speech bubble, with a four-pointed
     guiding star set in its opening: she is the thing that talks to you and
     the thing that points you somewhere. Drawn from arcs and straight tapers
     on a 32px grid so it stays sharp at favicon size, in two flat colours
     with no gradient or shadow — it has to hold up at 24px on a phone, where
     any shading turns to mud.

     It renders as an <img> when /assets/site/chioma-avatar.png is present, so
     a designed asset can replace this by dropping the file in, with no code
     change and no broken-image box while it is absent. */
  var MARK_SVG =
    '<svg class="ch-mark-svg" viewBox="0 0 32 32" fill="none" aria-hidden="true" focusable="false">' +
      '<path d="M23.39 24.81A11.5 11.5 0 1 1 26.81 12.07" stroke="currentColor" ' +
        'stroke-width="2.4" stroke-linecap="round"/>' +
      '<path d="M20.8 16 17.3 17.3 16 22.4 14.7 17.3 11.2 16 14.7 14.7 16 9.6 17.3 14.7Z" ' +
        'fill="currentColor"/>' +
    '</svg>';

  var ICON = {
    close: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"/></svg>',
    reset: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 3v5h5"/></svg>',
    send:  '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 2 11 13M22 2l-7 20-4-9-9-4 20-7z"/></svg>',
    down:  '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>'
  };

  function markHTML(cls) {
    return '<img class="ch-mark ' + cls + '" src="' + AVATAR + '" alt="" aria-hidden="true" ' +
           'onerror="this.outerHTML=window.__chiomaMark;">';
  }
  window.__chiomaMark = MARK_SVG;

  /* ---- stylesheet ---- */
  if (!document.querySelector('link[href="/assets/site/chioma.css"]')) {
    var l = document.createElement('link'); l.rel = 'stylesheet'; l.href = '/assets/site/chioma.css';
    document.head.appendChild(l);
  }

  /* ---- page context ---- */
  function routeKey() {
    var p = location.pathname.toLowerCase();
    if (/^\/academy/.test(p)) return 'academy';
    if (/^\/diary/.test(p) || /^\/blog/.test(p)) return 'diary';
    if (/^\/projects/.test(p)) return 'projects';
    if (/donate/.test(p)) return 'donate';
    if (/contact/.test(p)) return 'contact';
    if (/^\/about/.test(p)) return 'about';
    if (/^\/login/.test(p) || /^\/auth/.test(p)) return 'login';
    if (/^\/portal/.test(p) || /^\/member/.test(p) || /^\/donor-dashboard/.test(p)) return 'portal';
    if (/^\/events/.test(p)) return 'events';
    if (p === '/' || /^\/index/.test(p) || p === '') return 'home';
    return 'default';
  }
  var SECTION = { academy: 'Academy', diary: 'Diary', projects: 'Projects', donate: 'Donate',
    contact: 'Contact', about: 'About', login: 'Login', portal: 'Portal', events: 'Events', home: 'Home', default: '' };
  function context() {
    return {
      title: (document.title || '').replace(/\s*[|—–]\s*Afrovanguard.*$/i, '').trim() || 'Afrovanguard',
      path: location.pathname,
      section: document.body.getAttribute('data-section') || SECTION[routeKey()] || ''
    };
  }

  var BUBBLES = {
    home:     ['New here? I can point you to the right programme.', 'Looking for something? Ask me — I can search the site.'],
    academy:  ['I can check which courses are open right now.', 'Ask me how enrolment works.'],
    diary:    ['Want a summary of an entry? I can read it.', 'Looking for a topic? Ask me.'],
    donate:   ["Not sure how to give? I'll walk you through it.", 'We welcome materials too — ask me how.'],
    projects: ['Curious which programme fits you? Ask away.', 'I can find the story behind a project.'],
    contact:  ['I can draft your message to the team for you.', 'Tell me what you need and I’ll help you ask.'],
    about:    ['Want the short version of our mission? Ask me.', 'Curious how we started? I can look it up.'],
    login:    ['Trouble signing in? I can help.', 'Ask me what membership offers.'],
    portal:   ['Need a hand finding something? Just ask.'],
    events:   ['Want to know what’s coming up? Ask me.'],
    default:  ['Need a hand finding something? Just ask me.']
  };

  /* ---- state ---- */
  var history = [];
  try { history = JSON.parse(sessionStorage.getItem('chioma.history') || '[]') || []; } catch (e) {}
  var open = false, sending = false, greeted = false, lastFocus = null, workTimer = null;
  try { greeted = sessionStorage.getItem('chioma.greeted') === '1' || sessionStorage.getItem('av_onboarded_v2') != null; } catch (e) {}

  /* ---- DOM ---- */
  var root = document.createElement('div');
  root.className = 'chioma-root';
  root.setAttribute('data-no-export', '');
  if (document.documentElement.getAttribute('data-theme') === 'dark') root.setAttribute('data-dark', '1');
  root.innerHTML =
    '<div class="ch-scrim" id="chScrim"></div>' +
    '<div class="chioma-panel" id="chPanel" role="dialog" aria-modal="false" aria-labelledby="chName">' +
      '<div class="ch-head">' +
        '<span class="ch-ava">' + markHTML('') + '</span>' +
        '<div class="ch-head-txt"><div class="ch-name" id="chName">Chioma</div>' +
          '<div class="ch-status" id="chStatusT">Afrovanguard guide</div></div>' +
        '<button class="ch-head-btn" id="chReset" type="button" aria-label="Start a new conversation" title="New conversation">' + ICON.reset + '</button>' +
        '<button class="ch-head-btn" id="chClose" type="button" aria-label="Close chat">' + ICON.close + '</button>' +
      '</div>' +
      '<div class="ch-body" id="chBody" role="log" aria-live="polite" aria-relevant="additions" aria-label="Conversation with Chioma"></div>' +
      '<button class="ch-jump" id="chJump" type="button">' + ICON.down + ' Latest</button>' +
      '<div class="ch-chips" id="chChips"></div>' +

      '<form class="ch-foot" id="chForm">' +
        '<textarea id="chInput" rows="1" placeholder="Ask Chioma anything…" aria-label="Message Chioma" maxlength="1500"></textarea>' +
        '<button class="ch-send" id="chSend" type="submit" aria-label="Send message">' + ICON.send + '</button>' +
      '</form>' +
      '<div class="ch-tail">' +
        '<a href="/contact.html" data-ch-reach="email">Email</a>' +
        '<a href="https://wa.me/2349037776318" target="_blank" rel="noopener noreferrer">WhatsApp</a>' +
        '<a href="tel:+2349037776318">Call</a>' +
        '<a href="/donate.html">Donate</a>' +
        '<span class="ch-tail-note">Chioma is an AI guide — check anything important.</span>' +
      '</div>' +
    '</div>' +
    '<div class="chioma-greet" id="chGreet" role="status">' +
      '<button class="ch-greet-x" type="button" aria-label="Dismiss">' + ICON.close + '</button>' +
      '<span class="ch-greet-text"><b>Hi, I’m Chioma</b> — your guide to Afrovanguard. Need a hand finding something?</span>' +
    '</div>' +
    '<button class="chioma-fab" id="chFab" type="button" aria-label="Chat with Chioma" aria-expanded="false">' +
      markHTML('') + '<span class="ch-dot"></span></button>';
  (document.body || document.documentElement).appendChild(root);

  var panel = root.querySelector('#chPanel'), body = root.querySelector('#chBody'),
      input = root.querySelector('#chInput'), sendBtn = root.querySelector('#chSend'),
      chips = root.querySelector('#chChips'), greet = root.querySelector('#chGreet'),
      greetText = greet.querySelector('.ch-greet-text'), fab = root.querySelector('#chFab'),
      statusT = root.querySelector('#chStatusT'),
      jump = root.querySelector('#chJump'), scrim = root.querySelector('#chScrim');

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function el(tag, cls, html) {
    var d = document.createElement(tag);
    if (cls) d.className = cls;
    if (html != null) d.innerHTML = html;
    return d;
  }
  function atBottom() { return body.scrollHeight - body.scrollTop - body.clientHeight < 60; }
  function toBottom() { body.scrollTop = body.scrollHeight; jump.classList.remove('show'); }
  function append(node, keepPosition) {
    var stick = atBottom();
    body.appendChild(node);
    if (stick && !keepPosition) toBottom(); else jump.classList.add('show');
    return node;
  }

  /* ---- messages ---- */
  function addUser(text) { return append(el('div', 'ch-msg user', esc(text))); }
  function addBot(d) {
    // `html` is rendered and sanitised server-side; `reply` is the plain-text
    // fallback for the degraded path, and is escaped here.
    var html = (d && d.html) ? d.html : '<p>' + esc((d && d.reply) || '') + '</p>';
    return append(el('div', 'ch-msg bot', html));
  }

  /* What she is doing, named while she does it. The steps are a plausible
     sequence rather than a live feed — the endpoint answers once, at the end
     — so they are phrased as what she is doing, never as a count of results. */
  var WORK = ['Searching the site', 'Reading the page', 'Checking the web', 'Writing your answer'];
  function working(on) {
    var ex = body.querySelector('.ch-work');
    if (!on) {
      if (ex) ex.remove();
      if (workTimer) { clearInterval(workTimer); workTimer = null; }
      statusT.textContent = 'Afrovanguard guide';
      return;
    }
    if (ex) return;
    var i = 0;
    var node = append(el('div', 'ch-work', '<span class="ch-work-t">' + WORK[0] + '</span>'));
    statusT.textContent = 'Working';
    workTimer = setInterval(function () {
      i = Math.min(i + 1, WORK.length - 1);
      var t = node.querySelector('.ch-work-t');
      if (t) t.textContent = WORK[i];
      if (i === WORK.length - 1) { clearInterval(workTimer); workTimer = null; }
    }, 2600);
  }

  function addSources(list) {
    if (!list || !list.length) return;
    var wrap = el('div', 'ch-src');
    wrap.appendChild(el('span', 'ch-src-h', list.length === 1 ? 'Source' : 'Sources'));
    list.slice(0, 5).forEach(function (s, i) {
      var a = document.createElement('a');
      a.className = 'ch-src-a';
      a.href = s.url;
      if (s.kind === 'web') { a.target = '_blank'; a.rel = 'noopener noreferrer'; }
      a.innerHTML = '<span class="ch-src-n">' + (i + 1) + '</span>' +
                    '<span class="ch-src-t">' + esc(s.title) + '</span>';
      wrap.appendChild(a);
    });
    append(wrap);
  }

  /* ---- action cards ----------------------------------------------------
     A form Chioma filled in. It posts to the page's own endpoint, so every
     check that guards the real form guards this one too. */
  var PURPOSES = [['general', 'General enquiry'], ['volunteer', 'Volunteering'], ['business', 'Business / partnership'],
                  ['tech', 'Technology (Techome)'], ['media', 'Media (MediaPro)'], ['career', 'Career development'],
                  ['creative', 'Creative / cultural'], ['kingdom', 'Kingdom advancement'], ['donation', 'Donation enquiry']];

  function field(id, label, value, type, required) {
    var f = el('div', 'ch-f');
    var tag = type === 'textarea' ? 'textarea' : 'input';
    f.innerHTML = '<label for="' + id + '">' + esc(label) + (required ? '' : ' (optional)') + '</label>' +
      '<' + tag + ' id="' + id + '"' + (tag === 'input' ? ' type="' + (type || 'text') + '"' : '') +
      (required ? ' required' : '') + (type === 'email' ? ' autocomplete="email"' : '') +
      (id.indexOf('name') > -1 ? ' autocomplete="name"' : '') +
      (type === 'tel' ? ' autocomplete="tel"' : '') + '></' + tag + '>';
    f.querySelector(tag).value = value || '';
    return f;
  }

  function addAction(a) {
    if (!a || !a.kind) return;
    var uid = 'cha' + Math.random().toString(36).slice(2, 8);
    var card = el('div', 'ch-act');
    card.appendChild(el('div', 'ch-act-h', esc(a.label || 'Send')));
    var b = el('div', 'ch-act-b');
    var f = a.fields || {};
    var inputs = {};

    if (a.kind === 'contact') {
      var sel = el('div', 'ch-f');
      sel.innerHTML = '<label for="' + uid + 'p">What is it about</label><select id="' + uid + 'p">' +
        PURPOSES.map(function (p) {
          return '<option value="' + p[0] + '"' + (p[0] === f.purpose ? ' selected' : '') + '>' + esc(p[1]) + '</option>';
        }).join('') + '</select>';
      b.appendChild(sel); inputs.purpose = sel.querySelector('select');

      var nm = field(uid + 'n', 'Your name', f.name, 'text', true); b.appendChild(nm); inputs.name = nm.querySelector('input');
      var em = field(uid + 'e', 'Your email', f.email, 'email', true); b.appendChild(em); inputs.email = em.querySelector('input');
      var ms = field(uid + 'm', 'Message', f.message, 'textarea', true); b.appendChild(ms); inputs.message = ms.querySelector('textarea');

      var cs = el('div');
      cs.innerHTML = '<label class="ch-consent" for="' + uid + 'c">' +
        '<input type="checkbox" id="' + uid + 'c">' +
        '<span>I agree to Afrovanguard storing this message so the team can reply.</span></label>';
      b.appendChild(cs); inputs.consent = cs.querySelector('input');
    } else {
      var nm2 = field(uid + 'n', 'Your name', f.name, 'text', true); b.appendChild(nm2); inputs.name = nm2.querySelector('input');
      var em2 = field(uid + 'e', 'Your email', f.email, 'email', true); b.appendChild(em2); inputs.email = em2.querySelector('input');
      var ph = field(uid + 't', 'Phone', f.phone, 'tel', false); b.appendChild(ph); inputs.phone = ph.querySelector('input');
      var nt = field(uid + 'o', 'Anything to add', f.note, 'textarea', false); b.appendChild(nt); inputs.note = nt.querySelector('textarea');
    }

    var err = el('div', 'ch-f-err'); err.style.display = 'none'; b.appendChild(err);
    var foot = el('div', 'ch-act-foot');
    var go = el('button', 'ch-btn ch-btn-go'); go.type = 'button';
    go.textContent = a.kind === 'contact' ? 'Send message' : 'Send application';
    var no = el('button', 'ch-btn ch-btn-no'); no.type = 'button'; no.textContent = 'Not now';
    foot.appendChild(go); foot.appendChild(no); b.appendChild(foot);
    b.appendChild(el('div', 'ch-act-note', 'Chioma filled this in — check it before sending. Nothing goes until you press the button.'));
    card.appendChild(b);
    card.appendChild(el('div', 'ch-act-done', '<span class="ch-done-t">Sent.</span>'));

    function fail(msg, focusEl) {
      err.textContent = msg; err.style.display = '';
      go.disabled = false; go.textContent = a.kind === 'contact' ? 'Send message' : 'Send application';
      if (focusEl) focusEl.focus();
    }

    go.addEventListener('click', function () {
      err.style.display = 'none';
      var name = (inputs.name.value || '').trim(), email = (inputs.email.value || '').trim();
      if (!name) return fail('Please add your name.', inputs.name);
      if (!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(email)) return fail('Please check the email address.', inputs.email);
      if (a.kind === 'contact') {
        if (!(inputs.message.value || '').trim()) return fail('The message is empty.', inputs.message);
        if (!inputs.consent.checked) return fail('Please tick the box so we may store your message.', inputs.consent);
      }
      go.disabled = true; go.textContent = 'Sending…';

      var url, payload;
      if (a.kind === 'contact') {
        url = '/process-contact.php';
        payload = { action: 'submit_contact', purpose: inputs.purpose.value, name: name, email: email,
                    message: (inputs.message.value || '').trim(), consent: true, website_url: '' };
      } else {
        url = '/academy/api.php?action=enroll';
        payload = { course: f.course, name: name, email: email,
                    phone: (inputs.phone.value || '').trim(), note: (inputs.note.value || '').trim() };
      }

      fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json' },
                   credentials: 'same-origin', body: JSON.stringify(payload) })
        .then(function (r) { return r.json().catch(function () { return {}; }); })
        .then(function (d) {
          // The two endpoints disagree on the success key by history: the
          // contact processor answers {success}, the Academy API {ok}.
          var ok = d && (d.success === true || d.ok === true);
          if (!ok) return fail((d && (d.message || d.error)) || 'That did not go through. Please try again.');
          card.querySelector('.ch-done-t').textContent =
            (d.message && String(d.message)) || (a.kind === 'contact' ? 'Message sent — the team will reply by email.' : 'Application received.');
          card.classList.add('is-done');
          toBottom();
        })
        .catch(function () { fail('I could not reach the server. Check your connection and try again.'); });
    });

    no.addEventListener('click', function () {
      card.querySelector('.ch-done-t').textContent = 'Closed — ask me any time and I’ll bring it back.';
      card.classList.add('is-done');
    });

    append(card);
    // Focus the first thing the visitor still has to supply, so the form is
    // usable from the keyboard without hunting for it.
    var firstEmpty = a.kind === 'contact'
      ? (!inputs.name.value ? inputs.name : (!inputs.email.value ? inputs.email : null))
      : (!inputs.name.value ? inputs.name : (!inputs.email.value ? inputs.email : null));
    if (firstEmpty) setTimeout(function () { firstEmpty.focus(); }, 80);
  }

  function saveHistory() { try { sessionStorage.setItem('chioma.history', JSON.stringify(history.slice(-20))); } catch (e) {} }

  var CHIPS = [
    ['What courses are open?', 'What Academy courses are open right now?'],
    ['How do I donate?', 'How can I donate?'],
    ['Get involved', 'How can I get involved or volunteer?'],
    ['What is Afrovanguard?', 'What is Afrovanguard about?']
  ];
  function renderChips(show) {
    chips.innerHTML = '';
    if (!show) return;
    CHIPS.forEach(function (c) {
      var b = el('button', 'ch-chip'); b.type = 'button'; b.textContent = c[0];
      b.addEventListener('click', function () { sendMessage(c[1]); });
      chips.appendChild(b);
    });
  }

  function renderHistory() {
    body.innerHTML = '';
    if (!history.length) {
      append(el('div', 'ch-msg bot',
        '<p>Hi, I’m Chioma.</p>' +
        '<p>I can search this site and the web, read a page for you, or fill in a form so you can reach the team without leaving the chat.</p>'));
      renderChips(true);
    } else {
      history.forEach(function (h) {
        if (h.role === 'user') addUser(h.text); else addBot({ html: h.html, reply: h.text });
      });
      renderChips(false);
    }
    toBottom();
  }

  /* ---- focus containment -----------------------------------------------
     On a phone the panel is a full sheet over the page, so the page behind
     it is made inert. On desktop it is a floating card beside live content
     and the page stays usable, which is why this is width-conditional. */
  function isSheet() { return window.matchMedia('(max-width: 559px)').matches; }
  function pageInert(on) {
    var keep = [root];
    [].forEach.call(document.body.children, function (n) {
      if (keep.indexOf(n) !== -1) return;
      if (on) {
        if (!n.hasAttribute('inert')) { n.setAttribute('inert', ''); n.setAttribute('data-ch-inert', ''); }
      } else if (n.hasAttribute('data-ch-inert')) {
        n.removeAttribute('inert'); n.removeAttribute('data-ch-inert');
      }
    });
  }

  /* The visual viewport, not vh: on iOS the on-screen keyboard overlays the
     layout viewport, so a 100dvh sheet puts the composer underneath it. */
  function syncViewport() {
    if (!window.visualViewport || !isSheet()) { root.style.removeProperty('--ch-vh'); return; }
    root.style.setProperty('--ch-vh', window.visualViewport.height + 'px');
  }

  function setOpen(on) {
    open = on;
    root.classList.toggle('is-open', on);
    fab.setAttribute('aria-expanded', String(on));
    if (on) {
      lastFocus = document.activeElement;
      muteBubbles(); hideGreet(); renderHistory();
      syncViewport();
      if (isSheet()) pageInert(true);
      setTimeout(function () { input.focus(); }, 60);
    } else {
      pageInert(false);
      working(false);
      if (lastFocus && lastFocus.focus) lastFocus.focus(); else fab.focus();
    }
  }
  function hideGreet() { greet.classList.remove('show'); }

  /* ---- sending ---- */
  function sendMessage(text) {
    text = (text || '').trim();
    if (!text || sending) return;
    renderChips(false);
    addUser(text);
    history.push({ role: 'user', text: text }); saveHistory();
    input.value = ''; autoGrow();
    sending = true; sendBtn.disabled = true; working(true);

    fetch(ENDPOINT, {
      method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'same-origin',
      body: JSON.stringify({ message: text, history: history.slice(-12), page: context() })
    })
      .then(function (r) { return r.json().catch(function () { return { ok: false }; }); })
      .then(function (d) {
        working(false); sending = false; sendBtn.disabled = false;
        if (!d || (!d.reply && !d.error)) throw new Error('empty');
        var payload = d.reply ? d : { reply: d.error };
        addBot(payload);
        history.push({ role: 'bot', text: payload.reply, html: payload.html }); saveHistory();
        addSources(d.sources);
        (d.actions || []).forEach(addAction);
      })
      .catch(function () {
        working(false); sending = false; sendBtn.disabled = false;
        // Keep the visitor's words: a failed send that eats the message is the
        // one failure people do not forgive.
        var f = el('div', 'ch-fail', '<span>That didn’t send.</span>');
        var retry = el('button'); retry.type = 'button'; retry.textContent = 'Try again';
        retry.addEventListener('click', function () {
          f.remove();
          // Drop the optimistic user turn so the retry does not duplicate it.
          if (history.length && history[history.length - 1].role === 'user') history.pop();
          saveHistory();
          var last = body.querySelector('.ch-msg.user:last-of-type');
          if (last) last.remove();
          sendMessage(text);
        });
        f.appendChild(retry);
        append(f);
      });
  }

  /* ---- composer ---- */
  function autoGrow() {
    input.style.height = 'auto';
    var h = Math.min(input.scrollHeight, 120);
    input.style.height = h + 'px';
    // Only let it scroll once it has actually hit the ceiling: a permanent
    // scrollbar in a one-line composer reads as a rendering fault.
    input.style.overflowY = input.scrollHeight > 120 ? 'auto' : 'hidden';
  }
  autoGrow();
  input.addEventListener('input', autoGrow);
  input.addEventListener('keydown', function (e) {
    // Enter sends, Shift+Enter is a newline — the convention everywhere else.
    if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendMessage(input.value); }
  });
  root.querySelector('#chForm').addEventListener('submit', function (e) { e.preventDefault(); sendMessage(input.value); });

  /* ---- events ---- */
  fab.addEventListener('click', function () { setOpen(!open); });
  root.querySelector('#chClose').addEventListener('click', function () { setOpen(false); });
  scrim.addEventListener('click', function () { setOpen(false); });
  root.querySelector('#chReset').addEventListener('click', function () {
    history = []; saveHistory(); renderHistory(); input.focus();
  });
  jump.addEventListener('click', toBottom);
  body.addEventListener('scroll', function () { jump.classList.toggle('show', !atBottom()); });
  greet.querySelector('.ch-greet-x').addEventListener('click', function (e) { e.stopPropagation(); hideGreet(); muteBubbles(); });
  greet.addEventListener('click', function () { setOpen(true); });
  // The email channel opens the contact page, unless Chioma can draft it here.
  root.querySelector('[data-ch-reach="email"]').addEventListener('click', function (e) {
    e.preventDefault();
    sendMessage('I’d like to send a message to the team.');
  });

  document.addEventListener('click', function (e) {
    if (greet.classList.contains('show') && !root.contains(e.target)) hideGreet();
  });
  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape') return;
    if (open) setOpen(false);
    else if (greet.classList.contains('show')) { hideGreet(); muteBubbles(); }
  });
  // Keep focus inside the sheet while it covers the page.
  panel.addEventListener('keydown', function (e) {
    if (e.key !== 'Tab' || !open || !isSheet()) return;
    var f = panel.querySelectorAll('a[href],button:not(:disabled),textarea,input,select');
    var list = [].filter.call(f, function (n) { return n.getClientRects().length; });
    if (!list.length) return;
    var first = list[0], last = list[list.length - 1];
    if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
    else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
  });
  if (window.visualViewport) {
    window.visualViewport.addEventListener('resize', syncViewport);
    window.visualViewport.addEventListener('scroll', syncViewport);
  }
  window.addEventListener('resize', syncViewport);

  /* ---- public API ---- */
  window.chioma = {
    open: function (msg) { setOpen(true); if (msg) setTimeout(function () { sendMessage(msg); }, 200); },
    close: function () { setOpen(false); },
    toggle: function () { setOpen(!open); },
    ask: function (text, opts) {
      opts = opts || {};
      return fetch(ENDPOINT, { method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'same-origin',
        body: JSON.stringify({ message: String(text || ''), history: opts.history || [], page: opts.page || context() }) })
        .then(function (r) { return r.json(); })
        .then(function (d) { if (!d || d.reply == null) throw new Error((d && d.error) || 'Chioma had trouble.'); return d.reply; });
    }
  };
  if (!window.botpress) window.botpress = { open: function () { setOpen(true); }, close: function () { setOpen(false); }, sendEvent: function () {} };

  /* ---- the nudge, at most twice a session ---- */
  var CAP = 2, bubbleTimer = null, hideTimer = null;
  function ss(k) { try { return sessionStorage.getItem(k); } catch (e) { return null; } }
  function ssSet(k, v) { try { sessionStorage.setItem(k, v); } catch (e) {} }
  function muted() { return ss('chioma.muted') === '1'; }
  function muteBubbles() {
    ssSet('chioma.muted', '1'); ssSet('chioma.greeted', '1');
    if (bubbleTimer) { clearTimeout(bubbleTimer); bubbleTimer = null; }
    if (hideTimer) { clearTimeout(hideTimer); hideTimer = null; }
  }
  function count() { return parseInt(ss('chioma.bubbles') || '0', 10) || 0; }
  var rk = routeKey();
  function nextPhrase() {
    var list = BUBBLES[rk] || BUBBLES.default;
    var i = (parseInt(ss('chioma.phrase') || '0', 10) || 0) % list.length;
    ssSet('chioma.phrase', String(i + 1));
    return list[i];
  }
  function showBubble(text) {
    if (open || muted() || count() >= CAP) return;
    greetText.textContent = text;
    greet.classList.add('show');
    ssSet('chioma.bubbles', String(count() + 1));
    if (hideTimer) clearTimeout(hideTimer);
    hideTimer = setTimeout(hideGreet, 9000);
    schedule(40000);
  }
  function schedule(delay) {
    if (bubbleTimer) clearTimeout(bubbleTimer);
    if (muted() || count() >= CAP) return;
    bubbleTimer = setTimeout(function () { if (!open && !muted()) showBubble(nextPhrase()); }, delay);
  }
  if (!greeted && !muted()) {
    bubbleTimer = setTimeout(function () {
      bubbleTimer = null;
      if (open || muted() || count() >= CAP) return;
      greet.classList.add('show');
      ssSet('chioma.bubbles', String(count() + 1)); ssSet('chioma.greeted', '1');
      if (hideTimer) clearTimeout(hideTimer);
      hideTimer = setTimeout(hideGreet, 9000);
      schedule(42000);
    }, 5500);
  }
})();
