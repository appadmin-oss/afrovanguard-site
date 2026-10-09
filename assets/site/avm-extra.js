/* The few behaviours the portal needs that avm.js does not own. Loaded after
   it, and independent of it: nothing here reaches into its closure. */
(function () {
  'use strict';
  var root = document.querySelector('.avm'); if (!root) return;
  var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };

  /* "N values still to rate" — a count that does not move is a count nobody
     reads twice. avm.js owns the evidence rule and the submit; this owns the
     one line telling you how much is left. */
  var form = document.querySelector('[data-avm-values]');
  var left = document.querySelector('[data-avm-left]');
  if (form && left) {
    var sets = $$('fieldset.avm-val', form);
    var tick = function () {
      var n = sets.filter(function (fs) { return !fs.querySelector('input:checked'); }).length;
      left.textContent = n === 0 ? 'All seven rated' : n + (n === 1 ? ' value still to rate' : ' values still to rate');
    };
    form.addEventListener('change', tick);
    tick();
  }
})();
