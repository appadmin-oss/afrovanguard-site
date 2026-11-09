/* content-portal.js — runs only on the portal's "Connect the recorder" page.
 * Marks the page so it knows the extension is here, and carries ONE thing
 * across: the single-use pairing code the signed-in page made. */
(function () {
  'use strict';
  document.documentElement.setAttribute('data-av-recorder', chrome.runtime.getManifest().version);
  window.addEventListener('message', function (ev) {
    if (ev.source !== window || ev.origin !== location.origin) return;
    var d = ev.data;
    if (!d || d.type !== 'av-recorder-pair' || typeof d.code !== 'string' || !/^[a-f0-9]{32}$/.test(d.code)) return;
    chrome.runtime.sendMessage({ type: 'pair', code: d.code }, function (r) {
      var res = r || { ok: false, error: (chrome.runtime.lastError && chrome.runtime.lastError.message) || 'The recorder did not answer.' };
      window.postMessage({ type: 'av-recorder-paired', ok: !!res.ok, error: res.error || '' }, location.origin);
    });
  });
})();
