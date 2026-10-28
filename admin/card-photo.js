/* admin/card-photo.js — the member desk's "On the printed card": the photo and the
 * title under the name. The editor is shared with the member portal
 * (assets/site/avc-photo.js); this wires it to the admin API.
 *
 * Every save says what happened. A refusal, a host error page or a dropped
 * connection shows its reason in a toast and beside the controls — never nothing.
 */
(function () {
  'use strict';
  var $ = function (s) { return document.querySelector(s); };
  if (!$('#mdCardPhoto')) return;
  var member = 0, savedRole = '';

  /* admin/app.js publishes the request path (with its CSRF token). Looked up at use,
     not at load, so a slow or stale app.js shows a reason rather than dead buttons. */
  function A() {
    if (window.AvAdmin) return window.AvAdmin;
    var why = 'The admin page is out of date in this browser. Reload it (Ctrl+Shift+R) and try again.';
    return {
      api: function () { return Promise.resolve({ status: 0, data: { ok: false, error: why } }); },
      post: function () { return Promise.resolve({ status: 0, data: { ok: false, error: why } }); },
      toast: function (m) { say(m, true); },
    };
  }
  function say(text, bad) {
    var n = $('#mdCpMsg');
    if (n) { n.textContent = text || ''; n.classList.toggle('is-bad', !!bad); }
  }
  function fail(d, fallback) {
    var m = (d && d.error) || fallback;
    say(m, true); A().toast(m);
  }

  function load(id) {
    member = +id; say('');
    A().api('card_photo_get&id=' + member).then(function (r) {
      var d = r.data || {};
      if (!d.ok) { fail(d, 'Could not read this member’s card photo.'); return; }
      show(d.photo || '');
      $('#md_cardrole').value = savedRole = d.role || '';
    });
  }
  function show(url) {
    var img = $('#mdCpImg');
    img.hidden = !url; $('#mdCpNone').hidden = !!url; $('#mdCpClear').hidden = !url;
    $('#mdCpPick').textContent = url ? 'Replace photo' : 'Add photo';
    if (url) img.src = url + (url.indexOf('?') < 0 ? '?' : '&') + 't=' + Date.now(); else img.removeAttribute('src');
  }
  function form(blob, extra) {
    var fd = new FormData(); fd.append('file', blob, 'photo.jpg');
    Object.keys(extra || {}).forEach(function (k) { fd.append(k, extra[k]); });
    return fd;
  }

  $('#mdCpPick').addEventListener('click', function () {
    if (!window.AvcPhoto) { fail(null, 'The photo editor did not load. Reload the page (Ctrl+Shift+R) and try again.'); return; }
    if (!member) { fail(null, 'Open a member first.'); return; }
    $('#mdCpFile').value = ''; $('#mdCpFile').click();
  });
  $('#mdCpFile').addEventListener('change', function () {
    var file = this.files && this.files[0]; if (!file || !window.AvcPhoto) return;
    var who = member;
    window.AvcPhoto.edit({
      file: file,
      measure: function (b) { return A().api('card_photo_measure', { method: 'POST', body: form(b) }).then(function (m) { return m.data && m.data.ok ? m.data.geom : null; }); },
      save: function (b) {
        return A().api('card_photo_save', { method: 'POST', body: form(b, { id: String(who) }) }).then(function (r) {
          var d = r.data || {};
          if (!d.ok && !d.error) d.error = 'The photo was not saved (HTTP ' + r.status + ').';
          return d;
        });
      },
    }).then(function (url) {
      if (url === null) return;                       // cancelled
      if (who !== member) return;                     // another member was opened meanwhile
      show(url); say('Photo saved.'); A().toast('Card photo saved.');
    });
  });
  $('#mdCpClear').addEventListener('click', function () {
    A().post('card_photo_clear', { id: member }).then(function (r) {
      var d = r.data || {};
      if (d.ok) { show(''); say('Photo removed — the card prints initials.'); A().toast('Photo removed — the card prints initials.'); }
      else fail(d, 'Could not remove the photo.');
    });
  });
  /* → {ok, role} | {ok:false, error} */
  function saveRole() {
    var btn = $('#mdRoleSave'), role = $('#md_cardrole').value; btn.disabled = true;
    return A().post('card_role_save', { id: member, role: role }).then(function (r) {
      btn.disabled = false;
      var d = r.data || {};
      if (!d.ok) { fail(d, 'Could not save the title.'); return { ok: false, error: d.error || 'Could not save the title.' }; }
      savedRole = d.role || '';
      say(d.role ? 'Title saved.' : 'Title removed.');
      return d;
    });
  }
  $('#mdRoleSave').addEventListener('click', function () {
    if (!member) { fail(null, 'Open a member first.'); return; }
    saveRole().then(function (d) { if (d.ok) A().toast(d.role ? 'Title saved.' : 'Title removed.'); });
  });
  $('#md_cardrole').addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); $('#mdRoleSave').click(); } });

  /* For the desk's own Save (admin/app.js): the title too, when it was changed. */
  function saveTitleIfChanged() {
    if (!member || $('#md_cardrole').value.trim() === savedRole) return Promise.resolve(null);
    return saveRole();
  }

  window.AvCardPhoto = { load: load, saveTitleIfChanged: saveTitleIfChanged };
})();
