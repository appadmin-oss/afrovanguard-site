/* avfr.js — the franchise page's "On this page" rail: marks the section in view.
   On phones the rail is a scrolling bar; the active link is brought into view by
   scrolling the bar itself (never the page). */
(function () {
  'use strict';
  var links = [].slice.call(document.querySelectorAll('.avfr-toc-list a'));
  if (!links.length || !('IntersectionObserver' in window)) return;
  var bar = document.querySelector('.avfr-toc-list'), byId = {};
  links.forEach(function (a) { byId[a.getAttribute('href').slice(1)] = a; });
  var obs = new IntersectionObserver(function (entries) {
    entries.forEach(function (en) {
      if (!en.isIntersecting) return;
      var a = byId[en.target.id]; if (!a) return;
      links.forEach(function (l) { l.classList.remove('is-active'); l.removeAttribute('aria-current'); });
      a.classList.add('is-active'); a.setAttribute('aria-current', 'location');
      if (bar && bar.scrollWidth > bar.clientWidth) bar.scrollLeft = a.offsetLeft - (bar.clientWidth - a.offsetWidth) / 2;
    });
  }, { rootMargin: '-20% 0px -70% 0px', threshold: 0 });
  document.querySelectorAll('.avfr-sec[id]').forEach(function (s) { obs.observe(s); });
})();
