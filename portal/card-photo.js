/* portal/card-photo.js — a member puts their own photo on their card.
 * The editor is assets/site/avc-photo.js (framing, crop, resample); this sends
 * it to card/photo.php, which only ever acts on the signed-in member's card. */
(function () {
  'use strict';
  var box = document.querySelector('[data-avc-self]');
  if (!box) return;
  var csrf = box.getAttribute('data-csrf') || '';
  var file = box.querySelector('[data-avc-self-file]');
  var note = document.querySelector('[data-avc-self-msg]');
  function call(action, blob) {
    var fd = new FormData(); if (blob) fd.append('file', blob, 'photo.jpg');
    return fetch('/card/photo.php?action=' + action, { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-CSRF-Token': csrf } })
      .then(function (r) {
        return r.text().then(function (t) {
          try { return JSON.parse(t); }
          catch (e) { return { ok: false, error: 'The server answered HTTP ' + r.status + ' without data. Reload the page and try again.' }; }
        });
      }, function () { return { ok: false, error: 'Network error — check your connection and try again.' }; });
  }
  /* The card is drawn by the server: after a change, the page draws it again, on this view. */
  function redraw(text) {
    if (note) note.textContent = text;
    setTimeout(function () { location.hash = 'membership'; location.reload(); }, 600);
  }
  /* Front / back. The button says what it will show, and its pressed state says which side is up. */
  var flip = box.querySelector('[data-avc-flip]');
  if (flip) flip.addEventListener('click', function () {
    var h = box.closest('.avc-holder'), back = h.getAttribute('data-side') === 'front';
    h.setAttribute('data-side', back ? 'back' : 'front');
    flip.setAttribute('aria-pressed', String(back));
    flip.textContent = back ? 'Show front' : 'Show back';
  });
  box.querySelector('[data-avc-self-pick]').addEventListener('click', function () {
    if (!window.AvcPhoto) { if (note) note.textContent = 'The photo editor did not load. Reload the page and try again.'; return; }
    file.value = ''; file.click();
  });
  file.addEventListener('change', function () {
    var f = file.files && file.files[0]; if (!f || !window.AvcPhoto) return;
    window.AvcPhoto.edit({
      file: f,
      measure: function (b) { return call('measure', b).then(function (r) { return r && r.ok ? r.geom : null; }); },
      save: function (b) { return call('save', b); },
    }).then(function (url) { if (url !== null) redraw('Photo saved. Updating your card…'); });
  });
  var clear = box.querySelector('[data-avc-self-clear]');
  if (clear) clear.addEventListener('click', function () {
    call('clear').then(function (r) { if (r && r.ok) redraw('Photo removed — your card shows your initials.'); else if (note) note.textContent = (r && r.error) || 'Could not remove it.'; });
  });
})();
