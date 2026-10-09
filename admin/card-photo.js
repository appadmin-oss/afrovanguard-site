/* admin/card-photo.js — the member desk's "On the printed card": the photo and the
 * title under the name. The editor is shared with the member portal
 * (assets/site/avc-photo.js); this wires it to the admin API.
 */
(function () {
  'use strict';
  var A = window.AvAdmin;
  var $ = function (s) { return document.querySelector(s); };
  if (!A || !$('#mdCardPhoto') || !window.AvcPhoto) return;
  var member = 0;

  function load(id) {
    member = +id;
    A.api('card_photo_get&id=' + member).then(function (r) {
      var d = r.data || {};
      show(d.photo || '');
      $('#md_cardrole').value = d.role || '';
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

  $('#mdCpPick').addEventListener('click', function () { $('#mdCpFile').value = ''; $('#mdCpFile').click(); });
  $('#mdCpFile').addEventListener('change', function () {
    var file = this.files && this.files[0]; if (!file) return;
    window.AvcPhoto.edit({
      file: file,
      measure: function (b) { return A.api('card_photo_measure', { method: 'POST', body: form(b) }).then(function (m) { return m.data && m.data.ok ? m.data.geom : null; }); },
      save: function (b) { return A.api('card_photo_save', { method: 'POST', body: form(b, { id: String(member) }) }).then(function (r) { return r.data || {}; }); },
    }).then(function (url) { if (url) { show(url); A.toast('Card photo saved.'); } });
  });
  $('#mdCpClear').addEventListener('click', function () {
    A.post('card_photo_clear', { id: member }).then(function (r) { if (r.data && r.data.ok) { show(''); A.toast('Photo removed — the card prints initials.'); } });
  });
  $('#mdRoleSave').addEventListener('click', function () {
    A.post('card_role_save', { id: member, role: $('#md_cardrole').value }).then(function (r) {
      var d = r.data || {}; A.toast(d.ok ? (d.role ? 'Title saved.' : 'Title removed.') : (d.error || 'Could not save.'));
    });
  });

  window.AvCardPhoto = { load: load };
})();
