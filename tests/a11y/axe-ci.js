/**
 * tests/a11y/axe-ci.js — the accessibility gate CI runs on every push.
 *
 * Loads a page of EVERY template family in Chromium against a local `php -S`
 * of this checkout — the Home chrome, the policy pages, the Academy chrome,
 * the lesson player and the learner's pages, the member portal, the
 * constitution, sign-in, the error pages and the static pages — at a desktop
 * width and a 320px phone width (what 400% zoom looks like), and fails if:
 *   - axe-core finds any WCAG 2.0/2.1/2.2 A or AA violation;
 *   - the page scrolls sideways at 320px (WCAG 1.4.10 Reflow);
 *   - Tab moves focus onto a control that a fixed or sticky bar then hides
 *     completely (WCAG 2.4.11 Focus Not Obscured).
 *
 *   BASE=http://127.0.0.1:8000 TOKEN=<av_lms session> MEMBER_TOKEN=<…> node tests/a11y/axe-ci.js
 *
 * TOKEN (from tests/a11y/ci-session.php) adds the pages only a signed-in
 * learner sees; MEMBER_TOKEN (ci-session.php --member) the members' pages:
 * the constitution and every view of the member portal. Without them they
 * are skipped, and the run says so.
 *
 * Then it runs again with the Accessibility panel's heaviest settings on
 * (the av_a11y cookie: 200% text, comfortable spacing, high contrast in light
 * and in dark), at both widths, so a setting cannot quietly break a page; and
 * with the search palette open (empty, then with results) and the settings
 * panel open, on a page of each chrome.
 *
 * axe catches roughly a third of real barriers. The rest — does the screen
 * reader make sense, can you finish the quiz by keyboard — is the manual
 * pass the accessibility statement (/accessibility/) describes; the tree
 * snapshots (tests/a11y/tree-ci.js) catch changes to names and structure.
 */
'use strict';
const { chromium } = require('playwright');
const fs = require('fs');

const BASE = (process.env.BASE || 'http://127.0.0.1:8000').replace(/\/$/, '');
const TOKEN = process.env.TOKEN || '';
const MEMBER_TOKEN = process.env.MEMBER_TOKEN || '';
const AXE = fs.readFileSync(require.resolve('axe-core/axe.min.js'), 'utf8');
const TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'];

/* One or more pages of every template family. */
const FAMILIES = {
  'home chrome': ['/', '/about.html', '/contact.html', '/donate.html', '/projects/', '/projects/sts/', '/how-it-works', '/ethos/',
    '/people/', '/people/organogram/', '/events/', '/diary/', '/give/', '/franchise', '/mentorship/become-a-mentor/', '/reports/', '/IQ/', '/search.php?q=tech'],
  'policy pages': ['/accessibility/', '/terms/', '/privacy-policy/'],
  'academy chrome': ['/academy/', '/academy/techome/', '/academy/programmes/', '/academy/ngv/', '/academy/ngv/register.php', '/academy/dns/', '/academy/verify.php'],
  'sign-in': ['/login'],
  'error pages': ['/404.html', '/500.html', '/no-such-page-for-ci'],
  'static pages': ['/assets/site/offline.html'],
};
const LEARNER = { 'lesson player': ['/academy/techome/learn/welcome-to-techome', '/academy/techome/learn/check-your-understanding'],
  'learner pages': ['/academy/my', '/academy/techome/grades/', '/academy/techome/discussions/'] };
const PORTAL_VIEWS = 'overview attendance bills chat community diary fines inventory learning membership mentorship ngv quest reading requests tasks tools volunteer wallet wifi'.split(' ');
const MEMBER = { 'constitution': ['/constitution/'], 'member portal': PORTAL_VIEWS.map(v => '/portal/#' + v) };
const SETTINGS = ['t200.spacing.hc-light', 't200.spacing.hc-dark'];
/* Open states: the search palette (empty, then with results) and the
   Accessibility panel, on a page of each chrome. */
const OPEN = [['/', ''], ['/academy/', ''], ['/search.php?q=tech', ''], ['/academy/techome/learn/welcome-to-techome', 'learner'], ['/portal/#overview', 'member']];
const SETTLE = '*,*::before,*::after{animation-duration:0s!important;animation-delay:0s!important;transition-duration:0s!important;transition-delay:0s!important}';
const NOTFOUND_OK = ['/no-such-page-for-ci'];

/* WCAG 2.4.11: Tab through the page; a focused control entirely under a fixed
   or sticky element (a header, a bar, a launcher) is a failure. */
function obscuredCheck() {
  return (async () => {
    const bad = [];
    const els = [...document.querySelectorAll('a[href],button,input,select,textarea,summary,[tabindex]:not([tabindex="-1"])')]
      .filter(e => e.getClientRects().length && !e.disabled && e.tabIndex >= 0 && !e.closest('[inert],[hidden],dialog:not([open])')).slice(0, 120);
    for (const e of els) {
      e.focus({ preventScroll: true });
      if (document.activeElement !== e) continue;
      e.scrollIntoView({ block: 'nearest' });
      await new Promise(r => setTimeout(r, 0));
      const rc = e.getBoundingClientRect();
      if (rc.width < 2 || rc.height < 2) continue;
      if (rc.bottom < 0 || rc.top > innerHeight || rc.right < 0 || rc.left > innerWidth) continue;
      const pts = [[rc.left + rc.width / 2, rc.top + rc.height / 2], [rc.left + 2, rc.top + 2], [rc.right - 2, rc.bottom - 2], [rc.left + 2, rc.bottom - 2], [rc.right - 2, rc.top + 2]];
      let covered = 0, by = '';
      for (const [x, y] of pts) {
        const t = document.elementFromPoint(Math.min(innerWidth - 1, Math.max(0, x)), Math.min(innerHeight - 1, Math.max(0, y)));
        if (!t || t === e || e.contains(t) || t.contains(e)) continue;
        for (let a = t; a && a !== document.body; a = a.parentElement) {
          const p = getComputedStyle(a).position;
          if (p === 'fixed' || p === 'sticky') { covered++; by = (a.id ? '#' + a.id : a.tagName.toLowerCase() + '.' + String(a.className).split(' ')[0]); break; }
        }
      }
      if (covered === pts.length) bad.push(((e.id ? '#' + e.id : e.tagName.toLowerCase() + '.' + String(e.className).split(' ')[0]) + ' hidden under ' + by).slice(0, 120));
    }
    return [...new Set(bad)].slice(0, 5);
  })();
}

async function settle(pg) {
  /* Settle the page the way a reader would see it: animations finished,
     scroll-triggered reveals revealed. Otherwise axe measures text halfway
     through a fade and reports contrast that no reader ever sees. */
  await pg.addStyleTag({ content: SETTLE });
  await pg.evaluate(async () => { for (let y = 0; y < document.body.scrollHeight; y += innerHeight / 2) { scrollTo(0, y); await new Promise(r => setTimeout(r, 25)); } scrollTo(0, 0); });
  await pg.waitForTimeout(300);
  // A seasonal greeting layer can sit over the page; test the page beneath.
  await pg.evaluate(() => document.querySelectorAll('.avcel-layer').forEach(e => e.remove()));
}
async function axeRun(pg) {
  await pg.addScriptTag({ content: AXE });
  return pg.evaluate(async tags => (await window.axe.run(document, { runOnly: { type: 'tag', values: tags }, resultTypes: ['violations'] }))
    .violations.map(v => `${v.id} (${v.impact}, ${v.nodes.length}×): ${v.help} — ${v.nodes.slice(0, 3).map(n => n.target.join(' ')).join(' | ')}\n      ${v.helpUrl}`), TAGS);
}

(async () => {
  const browser = await chromium.launch();
  const failures = [], report = [];
  const groups = [[FAMILIES, '']];
  if (TOKEN) groups.push([LEARNER, TOKEN]);
  if (MEMBER_TOKEN) groups.push([MEMBER, MEMBER_TOKEN]);
  let pageCount = 0;
  /* SETS=… (comma-separated, "none" for no settings) and SKIP_OPEN=1 split a run across machines or processes. */
  const SETS = process.env.SETS ? process.env.SETS.split(',').map(x => x === 'none' ? '' : x) : [''].concat(SETTINGS);
  for (const set of SETS) for (const [fams, tok] of groups) for (const [w, h, size] of [[1280, 800, 'desktop'], [320, 640, '320px']]) {
    const ctx = await browser.newContext({ viewport: { width: w, height: h }, reducedMotion: 'reduce' });
    const cookies = [{ name: 'av_consent', value: 'analytics%3D0', url: BASE }];
    if (tok) cookies.push({ name: 'av_lms', value: tok, url: BASE });
    if (set) cookies.push({ name: 'av_a11y', value: set, url: BASE });
    await ctx.addCookies(cookies);
    for (const [fam, list] of Object.entries(fams)) for (const p of list) {
      if (set === SETS[0] && size === 'desktop') pageCount++;
      const label = `${size}${set ? ' [' + set + ']' : ''} ${fam}`;
      const pg = await ctx.newPage();
      const resp = await pg.goto(BASE + p, { waitUntil: 'networkidle', timeout: 60000 }).catch(() => null);
      const status = resp ? resp.status() : 0;
      if (!status || (status >= 400 && !(status === 404 && NOTFOUND_OK.includes(p)))) { failures.push(`${label} ${p}: HTTP ${status}`); report.push(`✗ ${label} ${p} — HTTP ${status}`); await pg.close(); continue; }
      if (tok && /\/login/.test(new URL(pg.url()).pathname)) { failures.push(`${label} ${p}: the session token was not accepted (landed on sign-in)`); report.push(`✗ ${label} ${p} — not signed in`); await pg.close(); continue; }
      await settle(pg);
      const bad = await axeRun(pg);
      if (size === '320px') {
        const overflow = await pg.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
        if (overflow > 1) bad.push(`reflow: the page is ${overflow}px wider than a 320px screen (WCAG 1.4.10)`);
      }
      for (const o of await pg.evaluate(obscuredCheck)) bad.push(`focus obscured: ${o} (WCAG 2.4.11)`);
      if (bad.length) { failures.push(...bad.map(b => `${label} ${p}: ${b}`)); report.push(`✗ ${label} ${p}\n    ` + bad.join('\n    ')); }
      else report.push(`✓ ${label} ${p}`);
      if (process.env.PROGRESS) console.log(report[report.length - 1]);
      await pg.close();
    }
    await ctx.close();
  }
  /* The palette and the panel, open, at both widths, on a page of each chrome. */
  for (const [w, h, size] of (process.env.SKIP_OPEN ? [] : [[1280, 800, 'desktop'], [320, 640, '320px']])) for (const [p, who] of OPEN) {
    const tok = who === 'learner' ? TOKEN : who === 'member' ? MEMBER_TOKEN : '';
    if (who && !tok) continue;
    const ctx = await browser.newContext({ viewport: { width: w, height: h }, reducedMotion: 'reduce' });
    const cookies = [{ name: 'av_consent', value: 'analytics%3D0', url: BASE }];
    if (tok) cookies.push({ name: 'av_lms', value: tok, url: BASE });
    await ctx.addCookies(cookies);
    for (const state of ['palette empty', 'palette results', 'panel']) {
      const label = `${size} [${state}]`;
      const pg = await ctx.newPage();
      await pg.goto(BASE + p, { waitUntil: 'networkidle', timeout: 60000 }).catch(() => {});
      await pg.addStyleTag({ content: SETTLE });
      await pg.evaluate(() => document.querySelectorAll('.avcel-layer').forEach(e => e.remove()));
      /* By its shortcut: the launcher may rightly be tucked (a control under its spot).
         The portal keeps Ctrl+K for its own palette, so there the header control opens the site search. */
      if (state === 'panel') await pg.keyboard.press('Alt+Shift+A');
      else {
        const own = await pg.evaluate(() => !!window.AvSearch && document.documentElement.hasAttribute('data-own-search-keys') || !!document.querySelector('script[src*="/portal/palette.js"]'));
        if (own) await pg.evaluate(() => window.AvSearch && window.AvSearch.open()); else await pg.keyboard.press('Control+k');
        if (state === 'palette results') { await pg.keyboard.type('tech'); await pg.waitForResponse(r => r.url().includes('/search.php?format=json'), { timeout: 10000 }).catch(() => {}); await pg.waitForTimeout(300); }
      }
      await pg.waitForTimeout(250);
      const opened = await pg.evaluate(s => !!document.querySelector(s === 'panel' ? '#av-a11y[open]' : '#av-search[open]'), state);
      const res = await axeRun(pg);
      if (!opened) res.push(`${state} did not open`);
      if (res.length) { failures.push(...res.map(b => `${label} ${p}: ${b}`)); report.push(`✗ ${label} ${p}\n    ` + res.join('\n    ')); }
      else report.push(`✓ ${label} ${p}`);
      await pg.close();
    }
    await ctx.close();
  }
  await browser.close();
  console.log(report.join('\n'));
  if (!TOKEN) console.log('\n(no TOKEN: the lesson player and the learner pages were not tested)');
  if (!MEMBER_TOKEN) console.log('(no MEMBER_TOKEN: the constitution and the member portal were not tested)');
  if (failures.length) {
    console.log(`\n${failures.length} accessibility failure(s). Each line above says what, where, and links to how to fix it.`);
    process.exit(1);
  }
  console.log(`\nAll ${pageCount} pages (${Object.keys(FAMILIES).length + (TOKEN ? 2 : 0) + (MEMBER_TOKEN ? 2 : 0)} template families) pass axe (WCAG 2.2 A/AA), reflow and focus-not-obscured at desktop and 320px, with settings: ${SETS.map(x => x || 'none').join(', ')}${process.env.SKIP_OPEN ? '' : '; the palette and the panel pass open'}.`);
})().catch(e => { console.error(e); process.exit(1); });
