/* assets/site/avcv.js — browser twin of partials/av-cover.php.
   window.avCover(o) returns the cover element for cards built from JSON.
   Same options and the same markup as av_cover() (see that file's header);
   styles in assets/site/avcv.css. Load it before the script that calls it. */
(function () {
  'use strict';
  const CATS = { summit: ['Summit', 'arcs'], gala: ['Gala', 'hatch'], workshop: ['Workshop', 'dots'], bootcamp: ['Bootcamp', 'grid'], townhall: ['Town hall', 'contour'],
    meetup: ['Community', 'contour'], arts: ['Arts & showcase', 'chevron'], faith: ['Faith', 'rays'], school: ['Schools', 'grid'], sports: ['Sports & wellbeing', 'chevron'],
    leadership: ['Leadership', 'arcs'], technology: ['Technology', 'grid'], culture: ['Culture', 'chevron'], education: ['Education', 'dots'], nation: ['Nation-building', 'contour'], general: ['', 'hatch'] };
  const HOL = { independence: ['Independence Day', '10-01'], democracyday: ['Democracy Day', '06-12'], christmas: ['Christmas', '12-25'], newyear: ['New Year', '01-01'],
    africaday: ['Africa Day', '05-25'], childrensday: ['Children’s Day', '05-27'], founding: ['Anniversary', '11-14'] };
  const SRC = { event: 'Afrovanguard · Events', diary: 'Afrovanguard · The Diary', project: 'Afrovanguard · Projects', appeal: 'Afrovanguard · Appeal', course: 'Afrovanguard · Academy', news: 'Afrovanguard' };
  const RATIOS = { '16:9': [16, 9], '1.91:1': [1.91, 1], '3:2': [3, 2], '4:5': [4, 5], '1:1': [1, 1], '9:16': [9, 16] };
  const INFER = [[/summit|conference|congress/, 'summit'], [/gala|dinner|award|banquet/, 'gala'], [/bootcamp|hackathon|coding|techome/, 'bootcamp'], [/workshop|masterclass|training|clinic|seminar|webinar/, 'workshop'],
    [/town ?hall|forum|assembly|agm/, 'townhall'], [/storm|school|students|tutor|waec|exam/, 'school'], [/showcase|concert|art|music|stardom|talent|film|media/, 'arts'],
    [/prayer|worship|devotion|faith|kingdom|church|mosque|carol/, 'faith'], [/football|sport|match|race|fitness|wellbeing|health/, 'sports'],
    [/meetup|get-together|community|volunteer|chapter|parade/, 'meetup'], [/leader|incorrupt|integrity|character|creed|genius/, 'leadership'],
    [/tech|digital|code|data|laptop/, 'technology'], [/culture|heritage|language|tradition/, 'culture'], [/nation|governance|democracy|citizen|corruption|africa/, 'nation'],
    [/education|learn|appraisal|book|read/, 'education']];
  const M = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
  const ISO = /^\d{4}-\d{2}-\d{2}$/;

  const infer = t => { t = String(t || '').toLowerCase(); for (const [re, k] of INFER) if (re.test(t)) return k; return 'general'; };
  const hash = s => { let h = 2166136261; for (let i = 0, n = s.length; i < n; i++) { h ^= s.charCodeAt(i); h = Math.imul(h, 16777619); } return Math.abs(h); };
  const el = (tag, cls, text) => { const n = document.createElement(tag); if (cls) n.className = cls; if (text != null) n.textContent = text; return n; };
  const ymd = d => d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');

  function avCover(o) {
    o = o || {};
    const s = k => String(o[k] == null ? '' : o[k]).trim();
    const kind = SRC[s('kind')] ? s('kind') : 'event', title = s('title'), cat = s('category');
    const ck = CATS[cat] ? cat : infer(title + ' ' + cat), [label, motif] = CATS[ck], h = hash(title);
    const rk = RATIOS[s('ratio')] ? s('ratio') : '16:9', [rw, rh] = RATIOS[rk], r = rh / rw;
    const shape = r >= 1 ? 'tall' : r <= 0.56 ? 'wide' : 'mid', light = s('tone') === 'light';
    const today = s('today') || ymd(new Date());
    const date = ISO.test(s('date')) ? s('date') : '', end = ISO.test(s('end')) ? s('end') : '', isEv = kind === 'event';
    const live = isEv && !!date && today >= date && today <= (end || date), past = isEv && !!date && today > (end || date);
    let hk = HOL[s('holiday')] ? s('holiday') : '';
    if (!hk && date) hk = Object.keys(HOL).find(k => HOL[k][1] === date.slice(5, 10)) || '';
    const kick = { event: label || 'Event', diary: label || 'Field note', project: label || 'Programme', appeal: 'Live appeal', course: label || 'Course', news: label || 'Update' }[kind];
    const d = date ? new Date(date + 'T00:00:00') : null;
    const parts = kind === 'event' ? [s('time'), s('location')] : kind === 'diary' ? [s('readTime'), d ? d.getDate() + ' ' + M[d.getMonth()] + ' ' + d.getFullYear() : '']
      : kind === 'appeal' ? [s('raised') && s('goal') ? s('raised') + ' of ' + s('goal') : ''] : [s('meta')];
    const meta = parts.filter(Boolean).join(' · ');
    const pct = Math.max(0, Math.min(100, Math.round(Number(o.progress) || 0)));
    const source = s('source') || SRC[kind] + (hk ? ' · ' + HOL[hk][0] : '');

    let ox = '0%', oy = '0%', ang = '0deg';
    if (motif === 'arcs') { ox = (88 + h % 14) + '%'; oy = (118 + h % 20) + '%'; }
    if (motif === 'contour') { ox = (80 + h % 20) + '%'; oy = (-20 + h % 25) + '%'; }
    if (motif === 'hatch') ang = (45 + (h % 3) * 15) + 'deg';
    if (motif === 'rays') ang = (h % 30) + 'deg';
    const lx = 70 + h % 30, len = title.length;

    const root = el('div', 'avcv avcv--' + ck + ' avcv-m-' + motif + ' avcv--' + shape + (light ? ' avcv--light' : '') + (past ? ' is-past' : '')
      + (len > 72 ? ' is-longer' : len > 46 ? ' is-long' : '') + (s('class') ? ' ' + s('class') : ''));
    root.setAttribute('role', 'img');
    root.setAttribute('aria-label', kick + ': ' + title + (meta ? ' — ' + meta : ''));
    root.style.cssText = `--cv-ar:${rw}/${rh};--cv-lx:${lx}%;--cv-ly:${h % 40}%;--cv-gx:${100 - (lx - 70)}%;--cv-gl:${Math.trunc(100 - lx / 2)}%;--cv-mx:${h % 22}px;--cv-my:${h % 13}px;--cv-ox:${ox};--cv-oy:${oy};--cv-ang:${ang}`
      + (kind === 'appeal' ? ';--cv-bar:' + pct + '%' : '');
    root.append(el('span', 'avcv-motif'), el('span', 'avcv-light'), el('span', 'avcv-grain'));
    const inner = el('span', 'avcv-in'), top = el('span', 'avcv-top'), src = el('span', 'avcv-src');
    const seal = el('span', 'avcv-seal');
    src.append(seal, el('span', null, source));
    top.append(src);
    if (live) top.append(el('span', 'avcv-live', 'Live now'));
    else if (s('badge')) top.append(el('span', 'avcv-badge', s('badge')));
    const bot = el('span', 'avcv-bot');
    if (isEv && d) {
      const multi = end && end !== date, de = multi ? new Date(end + 'T00:00:00') : null;
      const dt = el('span', 'avcv-date'), mon = el('span', 'avcv-mon');
      mon.append(el('span', null, M[d.getMonth()] + ' ' + d.getFullYear()), el('span', null, past ? 'Took place' : multi ? '– ' + de.getDate() + ' ' + M[de.getMonth()] : kick));
      dt.append(el('span', 'avcv-day', String(d.getDate())), mon);
      bot.append(dt);
    } else bot.append(el('span', 'avcv-kick', kick));
    bot.append(el('span', 'avcv-title', title));
    if (kind === 'appeal') { const b = el('span', 'avcv-bar'); b.append(el('span')); bot.append(b); }
    if (meta) { const m = el('span', 'avcv-meta'); m.append(el('span', null, meta)); bot.append(m); }
    inner.append(top, bot);
    root.append(inner);
    if (hk) root.append(el('span', 'avcv-stripe avcv-hol-' + hk));
    return root;
  }
  window.avCover = avCover;
})();
