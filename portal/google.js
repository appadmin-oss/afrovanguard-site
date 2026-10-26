/* portal/google.js — Google in the portal (lib/GoogleHub.php).

   The Workspace view is gone; what it showed is on Today now:
     · the Google card (#avpGoogleCard): unread mail, the next event, recent
       files, the inbox and what is coming up — filled once from
       /portal/workspace.php?action=me when the member is connected;
     · the connect round-trip's result (?google=connected / ?google_e=…),
       said once in the portal's toast, then taken out of the address bar
       so a reload does not say it again.
   State comes from <script id="avGoogleState"> written by portal/index.php. */
(function () {
  'use strict';
  var st = {};
  try { st = JSON.parse((document.getElementById('avGoogleState') || {}).textContent || '{}'); } catch (e) {}

  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function when(iso) {
    var t = Date.parse(iso); if (!t) return '';
    var d = new Date(t);
    return d.toLocaleDateString('en-GB', { weekday: 'short', day: 'numeric', month: 'short' }) + ' · ' + d.toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' });
  }
  function link(url, inner) {
    return /^https:\/\//.test(url || '') ? '<a href="' + esc(url) + '" target="_blank" rel="noopener noreferrer">' + inner + '</a>' : '<span>' + inner + '</span>';
  }

  /* The connect round-trip's notice. */
  if (st.notice && st.notice.text) {
    var say = function () {
      if (window.AVP && typeof window.AVP.toast === 'function') window.AVP.toast(st.notice.text, st.notice.tone === 'err' ? 'error' : 'ok');
      else {
        var box = document.createElement('p');
        box.className = 'avp-g-notice' + (st.notice.tone === 'err' ? ' is-err' : '');
        box.setAttribute('role', st.notice.tone === 'err' ? 'alert' : 'status');
        box.textContent = st.notice.text;
        var at = document.querySelector('#view-overview .avp-hello');
        if (at && at.parentNode) at.parentNode.insertBefore(box, at.nextSibling);
      }
    };
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', say); else say();
    try {
      var u = new URL(location.href);
      u.searchParams.delete('google'); u.searchParams.delete('google_e');
      history.replaceState(history.state, '', u.pathname + (u.search || '') + u.hash);
    } catch (e) {}
  }

  /* The Google card on Today. */
  var card = document.getElementById('avpGoogleCard');
  if (!card || !st.connected) return;
  var $ = function (id) { return document.getElementById(id); };
  fetch('/portal/workspace.php?action=me', { credentials: 'same-origin', headers: { Accept: 'application/json' } })
    .then(function (r) { return r.json(); })
    .then(function (d) {
      var m = d && d.ok && d.mine;
      if (!m) {
        ['gMail', 'gEvents', 'gFiles'].forEach(function (id) { if ($(id)) $(id).innerHTML = '<li class="avp-g-empty">Google did not answer. Reload in a moment.</li>'; });
        return;
      }
      var mail = m.mail || [], ev = m.events || [], files = m.files || [];
      $('gUnread').textContent = m.unread == null ? '—' : m.unread;
      $('gFileCount').textContent = files.length;
      if (ev[0]) { $('gNext').textContent = ev[0].title || 'Event'; $('gNextWhen').textContent = when(ev[0].start); }
      else { $('gNext').textContent = 'Nothing'; $('gNextWhen').textContent = ''; }
      $('gMail').innerHTML = mail.length ? mail.map(function (x) {
        return '<li class="avp-g-li' + (x.unread ? ' is-unread' : '') + '">' + link(x.url, '<b>' + esc(x.from) + '</b><span>' + esc(x.subject) + '</span>') + '</li>';
      }).join('') : '<li class="avp-g-empty">Your inbox is clear.</li>';
      $('gEvents').innerHTML = ev.length ? ev.map(function (x) {
        return '<li class="avp-g-li">' + link(x.url || x.meet_url, '<b>' + esc(x.title) + '</b><span>' + esc(when(x.start)) + '</span>') + '</li>';
      }).join('') : '<li class="avp-g-empty">Nothing coming up.</li>';
      $('gFiles').innerHTML = files.length ? files.map(function (x) {
        return '<li class="avp-g-li">' + link(x.url, '<b>' + esc(x.name) + '</b><span>' + esc(x.modified ? 'Edited ' + when(x.modified) : '') + '</span>') + '</li>';
      }).join('') : '<li class="avp-g-empty">No recent files.</li>';
    })
    .catch(function () {
      ['gMail', 'gEvents', 'gFiles'].forEach(function (id) { if ($(id)) $(id).innerHTML = '<li class="avp-g-empty">Could not reach Google. Reload in a moment.</li>'; });
    });
})();
