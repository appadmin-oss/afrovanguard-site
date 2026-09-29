/**
 * assets/site/give-items.js — the donate page's needs list, from the record.
 *
 * It used to be twenty-three hand-typed <li> rows. "18 needed" was a string,
 * so it said 18 in the month eleven laptops arrived, and it said 18 after the
 * eighteenth. Nobody was lying; the number simply had nowhere to come from.
 *
 * This reads /give/feed.json, which reads the same rows the giving console
 * writes. Progressive enhancement, exactly like appeals-band.js: the markup
 * ships with a fallback that stands on its own, and a failed fetch leaves a
 * page that still tells somebody how to give.
 */
(function () {
  'use strict';
  var root = document.querySelector('[data-give-items]');
  if (!root) return;

  var esc = function (s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  };

  var render = function (data) {
    var items = (data && data.items) || [];
    if (!items.length) return;                       /* keep the fallback */

    var sum = (data && data.itemsSummary) || {};
    var head = root.querySelector('[data-give-items-sum]');
    if (head) {
      var bits = [];
      if (sum.open) bits.push(sum.open + (sum.open === 1 ? ' thing' : ' things') + ' still needed');
      if (sum.covered) bits.push(sum.covered + ' already covered');
      head.textContent = bits.join(' · ');
    }

    /* Group in the order the catalogue returned them: the console's sort
       field is an editorial decision and the page should not second-guess it. */
    var order = [], groups = {};
    items.forEach(function (it) {
      var c = it.category || 'Other';
      if (!groups[c]) { groups[c] = []; order.push(c); }
      groups[c].push(it);
    });

    var html = order.map(function (cat) {
      var rows = groups[cat].map(function (it) {
        var price = it.kind === 'money' && it.price
          ? '<span class="gv-item-price">' + esc(it.price) +
            (it.unit ? '<small> / ' + esc(it.unit) + '</small>' : '') + '</span>'
          : '<span class="gv-item-price gv-item-kind">Given in kind</span>';

        var left = !it.open ? 'Covered — thank you'
          : (it.needed > 0 ? it.left + ' still needed' : 'Any number welcome');

        var meter = it.needed > 0
          ? '<span class="gv-meter" role="progressbar" aria-valuenow="' + it.pct +
            '" aria-valuemin="0" aria-valuemax="100" aria-label="' + it.funded + ' of ' + it.needed +
            ' covered"><span style="width:' + it.pct + '%"></span></span>'
          : '';

        /* Money goes to the form with the figure already in it; a thing goes
           to the people who arrange collection, because a card form cannot
           take a laptop. */
        /* In kind: if this page carries the material contribute form, fill it
           in place — sending somebody to a contact page to retype the name of
           the thing they are standing on is a step for nothing. Elsewhere,
           the contact route is the only one there is. */
        var cta = !it.open ? '' : (it.kind === 'money'
          ? '<a class="gv-item-cta" href="#donate-form" data-give-amount="' + it.cost + '">Fund one</a>'
          : (typeof window.matPrefill === 'function'
              ? '<a class="gv-item-cta" href="#matContributeSection" data-give-offer="' + esc(it.title) + '">Offer one</a>'
              : '<a class="gv-item-cta" href="/contact.html?about=' +
                encodeURIComponent('Donating: ' + it.title) + '">Offer one</a>'));

        return '<li class="gv-item' + (it.open ? '' : ' is-done') + '">' +
          '<div class="gv-item-main"><span class="gv-item-name">' + esc(it.title) + '</span>' +
          (it.detail ? '<span class="gv-item-detail">' + esc(it.detail) + '</span>' : '') +
          meter + '</div>' +
          '<div class="gv-item-side">' + price +
          '<span class="gv-item-left">' + esc(left) + '</span>' + cta + '</div></li>';
      }).join('');
      return '<h3 class="gv-cat">' + esc(cat) + '</h3><ul class="gv-items">' + rows + '</ul>';
    }).join('');

    var list = root.querySelector('[data-give-items-list]');
    if (list) list.innerHTML = html;

    /* "Fund one" fills the amount in rather than navigating away — the whole
       point of the price being here is that the decision and the form are in
       the same place. */
    root.querySelectorAll('[data-give-offer]').forEach(function (a) {
      a.addEventListener('click', function () {
        var name = a.getAttribute('data-give-offer') || '';
        try { window.matPrefill(name, 'equipment', 'I would like to donate: ' + name + '. '); }
        catch (e) { /* the anchor still moves them to the form */ }
      });
    });

    /* "Fund one" is the point of putting a price here: the amount goes into
       the form on this page rather than leaving somebody to read a figure,
       scroll, and retype it. The money form lives in the other tab, so the
       tab has to come with it — a filled field in a hidden panel is worse
       than doing nothing, because it looks like nothing happened. */
    root.querySelectorAll('[data-give-amount]').forEach(function (a) {
      a.addEventListener('click', function (ev) {
        var amt = parseInt(a.getAttribute('data-give-amount'), 10);
        if (!amt) return;
        ev.preventDefault();

        var tab = document.getElementById('tab-monetary');
        if (tab) tab.click();

        var field = document.getElementById('customAmt');
        if (field) {
          field.value = String(amt);
          /* The page's own handler owns the impact copy, the button label and
             the step state. Calling it beats re-implementing any of that. */
          if (typeof window.handleCustom === 'function') { try { window.handleCustom(); } catch (e) {} }
          else { field.dispatchEvent(new Event('input', { bubbles: true })); }
        }
        var form = document.getElementById('donate-form');
        if (form && form.scrollIntoView) form.scrollIntoView({ behavior: 'smooth', block: 'start' });
      });
    });
  };

  fetch('/give/feed.json', { credentials: 'same-origin' })
    .then(function (r) { return r.ok ? r.json() : null; })
    .then(function (d) { if (d) render(d); })
    .catch(function () { /* the fallback markup stays, which is the point */ });
})();
