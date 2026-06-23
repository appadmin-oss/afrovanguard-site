/* ============================================================
   assets/site/spotlight.js — context-aware Celebration Spotlight.
   Fills #celebration-spotlight with whatever matters most today:
     1. a team birthday or a holiday (from api.php?action=celebrations)
     2. otherwise the current Volunteer of the Month (api.php?action=votm)
     3. otherwise nothing — the section stays hidden.
   The featured share poster comes from celebrate.php and can be shared
   (Web Share API w/ image file) or downloaded.
   ============================================================ */
(function () {
  'use strict';
  var sec = document.getElementById('celebration-spotlight');
  if (!sec) return;
  var GOLD = '#f3b416';
  var origin = location.origin;

  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
  function get(u) { return fetch(u, { credentials: 'same-origin' }).then(function (r) { return r.ok ? r.json() : null; }).catch(function () { return null; }); }

  function basicShare(title, text, url) {
    if (navigator.share) { navigator.share({ title: title, text: text, url: url }).catch(function () {}); return; }
    window.open('https://wa.me/?text=' + encodeURIComponent(text + ' ' + url), '_blank', 'noopener');
  }
  function doShare(title, text, url, img) {
    if (navigator.canShare && img) {
      fetch(img).then(function (r) { return r.blob(); }).then(function (b) {
        var f = new File([b], 'afrovanguard-celebration.png', { type: 'image/png' });
        if (navigator.canShare({ files: [f] })) return navigator.share({ files: [f], title: title, text: text });
        throw 0;
      }).catch(function () { basicShare(title, text, url); });
    } else basicShare(title, text, url);
  }

  var ICON_SHARE = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><path d="M8.6 13.5l6.8 4M15.4 6.5l-6.8 4"/></svg>';
  var ICON_DL = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3v12m0 0l-4-4m4 4l4-4M5 21h14"/></svg>';
  var ARROW = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:15px;height:15px"><path d="M5 12h14M13 6l6 6-6 6"/></svg>';
  var SPARK = '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 0l2.4 8.2L22 12l-7.6 3.8L12 24l-2.4-8.2L2 12l7.6-3.8z"/></svg>';

  /* o = {eyebrow, emoji, title, message, isQuote, theme, poster, link, linkLabel, shareTitle, shareText, shareUrl} */
  function render(o) {
    var theme = o.theme || GOLD;
    sec.style.setProperty('--ct', theme);
    var linkHtml = (o.link && o.linkLabel)
      ? '<a class="cspot-link" href="' + esc(o.link) + '">' + esc(o.linkLabel) + ' ' + ARROW + '</a>' : '';
    sec.innerHTML =
      '<div class="cspot-bg"></div>'
      + '<span class="cspot-spark s1">' + SPARK + '</span><span class="cspot-spark s2">' + SPARK + '</span>'
      + '<span class="cspot-spark s3">' + SPARK + '</span><span class="cspot-spark s4">' + SPARK + '</span>'
      + '<div class="container cspot-inner">'
      +   '<div class="cspot-copy">'
      +     '<span class="cspot-eyebrow"><span class="cspot-emoji">' + esc(o.emoji || '🎉') + '</span>' + esc(o.eyebrow) + '</span>'
      +     '<h2 class="cspot-title">' + esc(o.title) + '</h2>'
      +     '<p class="cspot-msg' + (o.isQuote ? ' is-quote' : '') + '">' + esc(o.message || '') + '</p>'
      +     '<div class="cspot-actions">'
      +       '<button class="cspot-btn cspot-btn--share" type="button">' + ICON_SHARE + 'Share the moment</button>'
      +       '<a class="cspot-btn cspot-btn--ghost" href="' + esc(o.poster) + '&dl=1" download>' + ICON_DL + 'Download</a>'
      +       linkHtml
      +     '</div>'
      +   '</div>'
      +   '<div class="cspot-visual">'
      +     '<span class="cspot-glow"></span>'
      +     '<div class="cspot-card"><img src="' + esc(o.poster) + '" alt="' + esc(o.title) + ' — share card" loading="lazy" /></div>'
      +   '</div>'
      + '</div>';
    sec.classList.add('cspot');
    sec.hidden = false;
    sec.querySelector('.cspot-btn--share').addEventListener('click', function () {
      doShare(o.shareTitle, o.shareText, o.shareUrl, o.poster);
    });
  }

  function renderCelebration(c) {
    var p = c.primary;
    var poster = (p.type === 'birthday' && p.people && p.people[0])
      ? '/celebrate.php?type=birthday&id=' + p.people[0].id
      : '/celebrate.php?type=holiday&date=' + encodeURIComponent(c.date);
    var eyebrow, link = '', linkLabel = '';
    if (p.type === 'birthday') {
      eyebrow = 'Celebrating today';
      if (p.people && p.people[0]) { link = '/people/' + p.people[0].id + '/'; linkLabel = 'View profile'; }
    } else {
      eyebrow = ({ african: 'African celebration', international: 'Global celebration', internal: 'Afrovanguard celebration' })[p.scope] || 'Today we celebrate';
    }
    render({
      eyebrow: eyebrow, emoji: p.emoji || '🎉', title: p.title, message: p.message,
      theme: p.theme || GOLD, poster: poster, link: link, linkLabel: linkLabel,
      shareTitle: p.title + ' · Afrovanguard', shareText: p.message || p.title,
      shareUrl: link ? origin + link : origin + '/'
    });
  }

  var MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
  function monthName(m) { var n = parseInt(m, 10); return (n >= 1 && n <= 12) ? MONTHS[n - 1] : String(m || ''); }

  function renderVotm(v) {
    var month = (v.month ? monthName(v.month) : '') + (v.year ? ' ' + v.year : '');
    month = month.trim();
    render({
      eyebrow: 'Volunteer of the Month' + (month ? ' · ' + month : ''), emoji: '⭐',
      title: v.name || 'Our Volunteer of the Month',
      message: v.quote || v.reason || 'Celebrating service that moves the mission forward.',
      isQuote: !!v.quote, theme: GOLD, poster: '/celebrate.php?type=votm',
      link: v.id ? '/people/' + v.id + '/' : '', linkLabel: v.id ? 'View profile' : '',
      shareTitle: (v.name || 'Volunteer of the Month') + ' — Volunteer of the Month · Afrovanguard',
      shareText: v.quote || v.reason || (v.name + ' is our Volunteer of the Month.'),
      shareUrl: v.id ? origin + '/people/' + v.id + '/' : origin + '/'
    });
  }

  get('/api.php?action=celebrations').then(function (d) {
    var c = d && d.celebration;
    if (c && c.primary) { renderCelebration(c); return; }
    get('/api.php?action=votm').then(function (v) {
      if (v && v.status === 'ok' && v.votm && v.votm.name) renderVotm(v.votm);
      // else: nothing to celebrate — section stays hidden.
    });
  });
})();
