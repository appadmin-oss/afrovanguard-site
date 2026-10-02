/* portal/palette.js — the command palette.
 *
 * ── THE SAME FILE AS CACENTRE'S ─────────────────────────────────────────────
 * A byte-for-byte copy of cac/assets/js/palette.js, because the two sites are
 * two repositories and there is nowhere shared to put it. Everything that
 * differs between them is passed in as data through #cmdk-data — which screens
 * exist, which actions, where records come from — so this file needs no
 * knowledge of either site and a change belongs in BOTH copies. Each repo's
 * tests assert the parts they depend on.
 *
 * The original header follows, and applies here unchanged.
 *
 * ── ─────────────────────────────────────────────────────────────────────────
 * The command palette, and the keyboard the console
 * did not have.
 *
 * ── WHY A PALETTE AND NOT MORE MENUS ────────────────────────────────────────
 * Four people work this screen daily for hours. Consumer heuristics invert at
 * that point: they do not want progressive disclosure, they want throughput.
 * A palette is the one control that gets faster the more you know, and costs
 * a beginner nothing because it is hidden until asked for.
 *
 * ── IT SHOWS ITS SHORTCUTS ──────────────────────────────────────────────────
 * Every row that has a key prints it. That is a deliberate trade — a little
 * more to read in exchange for the shortcuts being learnable at all. A
 * shortcut nobody can discover is a shortcut nobody uses, and the usual place
 * people go looking is the thing they already have open.
 *
 * ── AND THE PLAIN PATH STILL WORKS ──────────────────────────────────────────
 * Nothing here is the only way to do anything. Every destination is a link in
 * the sidebar and every action is a button on a screen. This file is an
 * accelerator laid over a console that already worked without it; if the
 * script fails to load, nobody is stuck.
 *
 * ── THE PARTS THAT ARE ACCESSIBILITY, NOT POLISH ────────────────────────────
 *   · role=dialog + aria-modal, a real focus trap, and focus returned to
 *     whatever opened it. A palette you can tab out of behind is a trap for
 *     a screen reader and invisible to everyone else.
 *   · role=listbox / role=option with aria-activedescendant, so the row the
 *     arrow keys moved to is announced. Moving a CSS class alone is silent.
 *   · Every shortcut is refused while somebody is typing. That is the bug
 *     that makes people turn shortcuts off: a single letter eating a word.
 */
(function () {
  'use strict';

  var dataEl = document.getElementById('cmdk-data');
  if (!dataEl) return;

  var CFG;
  try { CFG = JSON.parse(dataEl.textContent || '{}'); } catch (e) { return; }
  var ACTIONS = CFG.actions || [];
  var FIND    = CFG.find || '';

  /* ── Where the list of screens comes from ─────────────────────────────
     The CRM hands it over as data, built from CrmWorkspace::NAV — the same
     constant its sidebar is drawn from.

     The admin panel's sidebar is hand-written markup, and it is already
     filtered for members by _layout.php post-processing its own buffer. So
     rather than keeping a second copy of forty-four items in PHP and hoping
     the two stay in step, the palette READS that sidebar. It cannot drift
     from the navigation because it is the navigation. */
  var NAV = CFG.nav || [];
  if (!NAV.length && CFG.navFrom) {
    NAV = Array.prototype.map.call(document.querySelectorAll(CFG.navFrom), function (a) {
      var label = (a.textContent || '').replace(/\s+/g, ' ').trim();
      /* The badge count sits inside the link. "Applications 6" is a label
         that stops matching the moment somebody files one. */
      var badge = a.querySelector('.admin-nav-badge, .so-pill, .pnav-badge');
      if (badge) label = label.replace(badge.textContent.trim(), '').trim();
      return { label: label, href: a.getAttribute('href') };
    }).filter(function (n) { return n.label && n.href; });

    /* The admin shell renders its sidebar TWICE — once for the desktop rail
       and once for the drawer under 900px — so scraping it gave every screen
       two identical rows, forty-seven of each. Deduped by destination, which
       is what makes two rows the same row. */
    var byHref = {};
    NAV = NAV.filter(function (n) {
      if (byHref[n.href]) return false;
      byHref[n.href] = 1;
      return true;
    });
  }

  /* ── Is somebody typing? ──────────────────────────────────────────────
     Asked before every single-key shortcut. A `<select>` counts: typing a
     letter there jumps to an option, and stealing it is the same bug. */
  function typing(e) {
    var t = e.target;
    if (!t) return false;
    if (t.isContentEditable) return true;
    var n = (t.tagName || '').toUpperCase();
    return n === 'INPUT' || n === 'TEXTAREA' || n === 'SELECT';
  }

  var mac = /Mac|iPhone|iPad/.test(navigator.platform || navigator.userAgent || '');
  var MOD = mac ? '⌘' : 'Ctrl';

  /* ══ The overlay ═══════════════════════════════════════════════════════ */

  var root, input, listEl, emptyEl, opener = null, rows = [], cursor = 0, mode = 'cmd';

  function build() {
    root = document.createElement('div');
    root.className = 'cmdk';
    root.hidden = true;
    root.innerHTML =
      '<div class="cmdk-scrim" data-close></div>' +
      '<div class="cmdk-box" role="dialog" aria-modal="true" aria-label="Command palette">' +
        '<input class="cmdk-in" type="text" autocomplete="off" spellcheck="false"' +
             ' role="combobox" aria-expanded="true" aria-controls="cmdk-list"' +
             ' aria-autocomplete="list" placeholder="Search, or jump to a screen">' +
        '<ul class="cmdk-list" id="cmdk-list" role="listbox" aria-label="Results"></ul>' +
        '<p class="cmdk-empty" hidden></p>' +
        '<div class="cmdk-foot">' +
          '<span><kbd>↑</kbd><kbd>↓</kbd> move</span>' +
          '<span><kbd>↵</kbd> open</span>' +
          '<span><kbd>esc</kbd> close</span>' +
          '<span><kbd>?</kbd> all shortcuts</span>' +
        '</div>' +
      '</div>';
    document.body.appendChild(root);

    input   = root.querySelector('.cmdk-in');
    listEl  = root.querySelector('.cmdk-list');
    emptyEl = root.querySelector('.cmdk-empty');

    root.addEventListener('mousedown', function (e) {
      if (e.target.hasAttribute && e.target.hasAttribute('data-close')) close();
    });
    input.addEventListener('input', onType);
    input.addEventListener('keydown', onKeys);
  }

  function open(which) {
    if (!root) build();
    mode = which || 'cmd';
    opener = document.activeElement;
    root.hidden = false;
    document.documentElement.classList.add('cmdk-on');
    input.value = '';
    input.placeholder = mode === 'help'
      ? 'Filter the shortcuts'
      : 'Search, or jump to a screen';
    render(baseRows());
    input.focus();
  }

  function close() {
    if (!root || root.hidden) return;
    root.hidden = true;
    document.documentElement.classList.remove('cmdk-on');
    /* Back where they were. Losing focus to <body> drops a keyboard user at
       the top of the document, which is a long way from what they were on. */
    if (opener && opener.focus) { try { opener.focus(); } catch (e) {} }
    opener = null;
  }

  /* ══ What is offered ═══════════════════════════════════════════════════ */

  function baseRows() {
    if (mode === 'help') return shortcutRows();
    return NAV.map(function (n) {
      return { kind: 'Go to', label: n.label, sub: n.sub || '', href: n.href, keys: n.keys || '' };
    }).concat(ACTIONS.map(function (a) {
      return { kind: 'Do', label: a.label, sub: a.sub || '', href: a.href, keys: a.keys || '' };
    }));
  }

  function shortcutRows() {
    var out = [
      { kind: 'Anywhere', label: 'Open this palette',    keys: MOD + ' K' },
      { kind: 'Anywhere', label: 'Show these shortcuts', keys: '?' },
      { kind: 'Anywhere', label: 'Search this screen',   keys: '/' },
      { kind: 'Anywhere', label: 'Close what is open',   keys: 'Esc' }
    ];
    NAV.forEach(function (n) {
      if (n.keys) out.push({ kind: 'Go to', label: n.label, keys: n.keys, href: n.href });
    });
    ACTIONS.forEach(function (a) {
      if (a.keys) out.push({ kind: 'Do', label: a.label, keys: a.keys, href: a.href });
    });
    return out;
  }

  /* A match on the start of a word beats one in the middle: typing "le"
     should offer Leads before "Print a label". */
  function score(hay, needle) {
    hay = (hay || '').toLowerCase();
    if (hay.indexOf(needle) === 0) return 0;
    if (hay.indexOf(' ' + needle) > -1) return 1;
    return hay.indexOf(needle) > -1 ? 2 : -1;
  }

  function filterLocal(q) {
    var n = q.toLowerCase();
    return baseRows()
      .map(function (r) { return { r: r, s: score(r.label, n) }; })
      .filter(function (x) { return x.s > -1; })
      .sort(function (a, b) { return a.s - b.s; })
      .map(function (x) { return x.r; });
  }

  /* ══ Records, from the server ══════════════════════════════════════════ */

  var timer = null, seq = 0;

  function onType() {
    var q = input.value.trim();
    if (timer) clearTimeout(timer);

    if (q === '') { render(baseRows()); return; }

    /* Screens and actions answer instantly from what the page already knows;
       only records need the server. Showing the local matches first means the
       palette never looks like it is thinking when it is not. */
    var local = filterLocal(q);
    render(local);

    if (mode === 'help' || q.length < 2 || !FIND) return;

    timer = setTimeout(function () {
      var mine = ++seq;
      fetch(FIND + (FIND.indexOf('?') > -1 ? '&' : '?') + 'q=' + encodeURIComponent(q), {
        credentials: 'same-origin'
      }).then(function (r) { return r.json(); }).then(function (d) {
        /* An answer to a query somebody has already typed past is worse than
           no answer: it replaces what they are reading with older results. */
        if (mine !== seq || root.hidden) return;
        if (!d || !d.ok || !d.items || !d.items.length) return;
        render(local.concat(d.items.map(function (i) {
          return { kind: i.kind, label: i.label, sub: i.sub, href: i.href };
        })));
      }).catch(function () { /* the local matches are still on screen */ });
    }, 140);
  }

  /* ══ Drawing ═══════════════════════════════════════════════════════════ */

  function render(list) {
    rows = list || [];
    cursor = 0;
    listEl.textContent = '';

    if (!rows.length) {
      emptyEl.hidden = false;
      emptyEl.textContent = input.value.trim()
        ? 'Nothing matches that. Records need two letters or more.'
        : 'Nothing to show.';
      input.removeAttribute('aria-activedescendant');
      return;
    }
    emptyEl.hidden = true;

    rows.forEach(function (r, i) {
      var li = document.createElement('li');
      li.className = 'cmdk-row';
      li.id = 'cmdk-r' + i;
      li.setAttribute('role', 'option');
      li.setAttribute('aria-selected', i === 0 ? 'true' : 'false');

      var kind = document.createElement('span');
      kind.className = 'cmdk-kind';
      kind.textContent = r.kind || '';
      li.appendChild(kind);

      var body = document.createElement('span');
      body.className = 'cmdk-body';
      var lab = document.createElement('span');
      lab.className = 'cmdk-label';
      lab.textContent = r.label;
      body.appendChild(lab);
      if (r.sub) {
        var sub = document.createElement('span');
        sub.className = 'cmdk-sub';
        sub.textContent = r.sub;
        body.appendChild(sub);
      }
      li.appendChild(body);

      if (r.keys) {
        var k = document.createElement('span');
        k.className = 'cmdk-keys';
        String(r.keys).split(' ').forEach(function (part) {
          var kbd = document.createElement('kbd');
          kbd.textContent = part;
          k.appendChild(kbd);
        });
        li.appendChild(k);
      }

      li.addEventListener('mousemove', function () { move(i - cursor); });
      li.addEventListener('click', function () { cursor = i; go(); });
      listEl.appendChild(li);
    });

    mark();
  }

  function mark() {
    var kids = listEl.children;
    for (var i = 0; i < kids.length; i++) {
      var on = i === cursor;
      kids[i].classList.toggle('is-on', on);
      kids[i].setAttribute('aria-selected', on ? 'true' : 'false');
    }
    if (kids[cursor]) {
      input.setAttribute('aria-activedescendant', kids[cursor].id);
      kids[cursor].scrollIntoView({ block: 'nearest' });
    }
  }

  function move(by) {
    if (!rows.length) return;
    cursor = (cursor + by + rows.length) % rows.length;
    mark();
  }

  function go() {
    var r = rows[cursor];
    if (!r || !r.href) return;
    close();
    window.location.href = r.href;
  }

  function onKeys(e) {
    if (e.key === 'Escape')    { e.preventDefault(); close(); return; }
    if (e.key === 'ArrowDown') { e.preventDefault(); move(1);  return; }
    if (e.key === 'ArrowUp')   { e.preventDefault(); move(-1); return; }
    if (e.key === 'Enter')     { e.preventDefault(); go();     return; }
    if (e.key === 'Home')      { e.preventDefault(); cursor = 0; mark(); return; }
    if (e.key === 'End')       { e.preventDefault(); cursor = rows.length - 1; mark(); return; }
    /* The trap. One field and one list, so Tab has nowhere useful to go and
       letting it leave would put focus behind the scrim. */
    if (e.key === 'Tab')       { e.preventDefault(); move(e.shiftKey ? -1 : 1); }
  }

  /* ══ The keyboard, outside the palette ═════════════════════════════════ */

  var chord = null, chordAt = 0;

  document.addEventListener('keydown', function (e) {
    /* Cmd/Ctrl+K works even in a field: it is the one shortcut somebody
       reaches for mid-typing, and a modifier combination is not going to be
       mistaken for a character. */
    if ((e.metaKey || e.ctrlKey) && (e.key === 'k' || e.key === 'K')) {
      e.preventDefault();
      root && !root.hidden ? close() : open('cmd');
      return;
    }
    if (e.metaKey || e.ctrlKey || e.altKey) return;
    if (typing(e)) return;
    if (root && !root.hidden) return;

    if (e.key === '?') { e.preventDefault(); open('help'); return; }

    /* `g` then a letter, the way Gmail and GitHub do it. Two seconds, then
       the g is forgotten — otherwise a g pressed by accident silently eats
       the next letter somebody types. */
    if (e.key === 'g') { chord = 'g'; chordAt = Date.now(); return; }
    if (chord === 'g') {
      var within = Date.now() - chordAt < 2000;
      chord = null;
      if (!within) return;
      var hit = NAV.filter(function (n) { return n.keys === 'g ' + e.key; })[0];
      if (hit) { e.preventDefault(); window.location.href = hit.href; }
    }
  });

  /* The button in the bar is rendered saying "Ctrl" because the server has no
     idea what somebody is typing on. Corrected here, where we do. */
  Array.prototype.forEach.call(document.querySelectorAll('.cmdk-mod'), function (k) {
    k.textContent = MOD;
  });

  /* An action that lands on #fNew should put the cursor IN the form, not
     merely scroll it into view. A fragment scrolls; it does not focus, so
     without this "New lead" ends with somebody reaching for the mouse — which
     is the round trip the palette exists to remove. */
  if (location.hash === '#fNew') {
    var form = document.getElementById('fNew');
    var first = form && form.querySelector('input:not([type=hidden]), textarea, select');
    if (first) { try { first.focus({ preventScroll: false }); } catch (e) { first.focus(); } }
  }

  /* Anything on the page that wants to open it — a button in a header, the
     search box's own hint — without every screen repeating this file. */
  document.addEventListener('click', function (e) {
    var t = e.target.closest ? e.target.closest('[data-cmdk]') : null;
    if (!t) return;
    e.preventDefault();
    open(t.getAttribute('data-cmdk') === 'help' ? 'help' : 'cmd');
  });
})();
