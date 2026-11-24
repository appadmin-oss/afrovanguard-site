/**
 * tests/a11y/axe-ci.js — the accessibility gate CI runs on every push.
 *
 * Loads each page in Chromium against a local `php -S` of this checkout, at a
 * desktop width and a 320px phone width (what 400% zoom looks like), and
 * fails if:
 *   - axe-core finds any WCAG 2.0/2.1/2.2 A or AA violation, or
 *   - the page scrolls sideways at 320px (WCAG 1.4.10 Reflow).
 *
 *   BASE=http://127.0.0.1:8000 TOKEN=<av_lms session> MEMBER_TOKEN=<…> node tests/a11y/axe-ci.js
 *
 * TOKEN (from tests/a11y/ci-session.php) adds the pages only a signed-in
 * learner sees; MEMBER_TOKEN (ci-session.php --member) the members' pages:
 * the constitution and every view of the member portal. Without them they
 * are skipped, and the run says so.
 *
 * Then it runs once more with the Accessibility panel's heaviest settings on
 * (the av_a11y cookie: 200% text, comfortable spacing, high contrast in light
 * and in dark), at both widths, so a setting cannot quietly break a page.
 *
 * axe catches roughly a third of real barriers. The rest — does the screen
 * reader make sense, can you finish the quiz by keyboard — is the manual
 * pass the accessibility statement (/accessibility/) describes.
 */
'use strict';
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const BASE = (process.env.BASE || 'http://127.0.0.1:8000').replace(/\/$/, '');
const TOKEN = process.env.TOKEN || '';
const AXE = fs.readFileSync(require.resolve('axe-core/axe.min.js'), 'utf8');
const TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'];

const PUBLIC = [
  '/', '/about.html', '/contact.html', '/donate.html', '/projects/',
  '/accessibility/', '/terms/', '/privacy-policy/', '/reports/', '/login', '/people/',
  '/academy/', '/academy/techome/', '/academy/programmes/', '/academy/ngv/',
  '/academy/ngv/register.php', '/academy/dns/', '/academy/verify.php',
];
const MEMBER_TOKEN = process.env.MEMBER_TOKEN || '';
const PORTAL_VIEWS = 'overview attendance bills chat community diary fines inventory learning membership mentorship ngv quest reading requests tasks tools volunteer wallet wifi'.split(' ');
const MEMBER = ['/constitution/'].concat(PORTAL_VIEWS.map(v => '/portal/#' + v));
const SETTINGS = ['t200.spacing.hc-light', 't200.spacing.hc-dark'];
/* Open states: the search palette (empty, then with results) and the
   Accessibility panel, on a page of each chrome. */
const PALETTE = ['/', '/academy/', '/search.php?q=tech'];
const LEARNER = [
  '/academy/my', '/academy/techome/learn/welcome-to-techome',
  '/academy/techome/learn/check-your-understanding',
  '/academy/techome/grades/', '/academy/techome/discussions/',
];

(async () => {
  const browser = await chromium.launch();
  const failures = [];
  const report = [];
  // [pages, session token, settings cookie]
  const runs = [];
  for (const set of [''].concat(SETTINGS)) {
    runs.push([PUBLIC.concat(TOKEN ? LEARNER : []), TOKEN, set]);
    if (MEMBER_TOKEN) runs.push([MEMBER, MEMBER_TOKEN, set]);
  }
  const pages = runs.filter(r => !r[2]).reduce((n, r) => n.concat(r[0]), []);
  for (const [list, tok, set] of runs) for (const [w, h, size] of [[1280, 800, 'desktop'], [320, 640, '320px']]) {
    const label = size + (set ? ' [' + set + ']' : '');
    const ctx = await browser.newContext({ viewport: { width: w, height: h }, reducedMotion: 'reduce' });
    const cookies = [{ name: 'av_consent', value: 'analytics%3D0', url: BASE }];
    if (tok) cookies.push({ name: 'av_lms', value: tok, url: BASE });
    if (set) cookies.push({ name: 'av_a11y', value: set, url: BASE });
    await ctx.addCookies(cookies);
    for (const p of list) {
      const pg = await ctx.newPage();
      const resp = await pg.goto(BASE + p, { waitUntil: 'networkidle', timeout: 60000 }).catch(e => ({ status: () => 0, err: e }));
      const status = resp.status();
      if (status >= 400 || status === 0) { failures.push(`${label} ${p}: HTTP ${status}`); report.push(`✗ ${label} ${p} — HTTP ${status}`); await pg.close(); continue; }
      /* Settle the page the way a reader would see it: animations finished,
         scroll-triggered reveals revealed. Otherwise axe measures text halfway
         through a fade and reports contrast that no reader ever sees — and a
         gate that fails at random is a gate people learn to ignore. */
      await pg.addStyleTag({ content: '*,*::before,*::after{animation-duration:0s!important;animation-delay:0s!important;transition-duration:0s!important;transition-delay:0s!important}' });
      await pg.evaluate(async () => { for (let y = 0; y < document.body.scrollHeight; y += innerHeight / 2) { scrollTo(0, y); await new Promise(r => setTimeout(r, 30)); } scrollTo(0, 0); });
      await pg.waitForTimeout(300);
      // A seasonal greeting layer can sit over the page; test the page beneath.
      await pg.evaluate(() => document.querySelectorAll('.avcel-layer').forEach(e => e.remove()));
      await pg.addScriptTag({ content: AXE });
      const res = await pg.evaluate(async tags => {
        const r = await window.axe.run(document, { runOnly: { type: 'tag', values: tags }, resultTypes: ['violations'] });
        return r.violations.map(v => ({ id: v.id, impact: v.impact, help: v.help, url: v.helpUrl, nodes: v.nodes.slice(0, 3).map(n => n.target.join(' ')), count: v.nodes.length }));
      }, TAGS);
      const overflow = await pg.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
      const bad = [];
      for (const v of res) bad.push(`${v.id} (${v.impact}, ${v.count}×): ${v.help} — ${v.nodes.join(' | ')}\n      ${v.url}`);
      if (size === '320px' && overflow > 1) bad.push(`reflow: the page is ${overflow}px wider than a 320px screen (WCAG 1.4.10)`);
      if (bad.length) { failures.push(...bad.map(b => `${label} ${p}: ${b}`)); report.push(`✗ ${label} ${p}\n    ` + bad.join('\n    ')); }
      else report.push(`✓ ${label} ${p}`);
      await pg.close();
    }
    await ctx.close();
  }
  /* The palette and the panel, open, at both widths. */
  for (const [w, h, size] of [[1280, 800, 'desktop'], [320, 640, '320px']]) {
    const ctx = await browser.newContext({ viewport: { width: w, height: h }, reducedMotion: 'reduce' });
    await ctx.addCookies([{ name: 'av_consent', value: 'analytics%3D0', url: BASE }]);
    for (const p of PALETTE) for (const state of ['palette empty', 'palette results', 'panel']) {
      const label = `${size} [${state}]`;
      const pg = await ctx.newPage();
      await pg.goto(BASE + p, { waitUntil: 'networkidle', timeout: 60000 }).catch(() => {});
      await pg.addStyleTag({ content: '*,*::before,*::after{animation-duration:0s!important;animation-delay:0s!important;transition-duration:0s!important;transition-delay:0s!important}' });
      await pg.evaluate(() => document.querySelectorAll('.avcel-layer').forEach(e => e.remove()));
      /* By its shortcut: the launcher may rightly be tucked (a control under its spot). */
      if (state === 'panel') { await pg.keyboard.press('Alt+Shift+A'); }
      else {
        await pg.keyboard.press('Control+k');
        if (state === 'palette results') { await pg.keyboard.type('tech'); await pg.waitForResponse(r => r.url().includes('/search.php?format=json'), { timeout: 10000 }).catch(() => {}); await pg.waitForTimeout(300); }
      }
      await pg.waitForTimeout(250);
      const opened = await pg.evaluate(s => !!document.querySelector(s === 'panel' ? '#av-a11y[open]' : '#av-search[open]'), state);
      await pg.addScriptTag({ content: AXE });
      const res = await pg.evaluate(async tags => (await window.axe.run(document, { runOnly: { type: 'tag', values: tags }, resultTypes: ['violations'] }))
        .violations.map(v => `${v.id} (${v.impact}, ${v.nodes.length}×): ${v.help} — ${v.nodes.slice(0, 3).map(n => n.target.join(' ')).join(' | ')}`), TAGS);
      if (!opened) res.push(`${state} did not open`);
      if (res.length) { failures.push(...res.map(b => `${label} ${p}: ${b}`)); report.push(`✗ ${label} ${p}\n    ` + res.join('\n    ')); }
      else report.push(`✓ ${label} ${p}`);
      await pg.close();
    }
    await ctx.close();
  }
  await browser.close();
  console.log(report.join('\n'));
  if (!TOKEN) console.log('\n(no TOKEN: the signed-in learner pages were not tested)');
  if (!MEMBER_TOKEN) console.log('(no MEMBER_TOKEN: the constitution and the member portal were not tested)');
  if (failures.length) {
    console.log(`\n${failures.length} accessibility failure(s). Each line above says what, where, and links to how to fix it.`);
    process.exit(1);
  }
  console.log(`\nAll ${pages.length} pages pass axe (WCAG 2.2 A/AA) at desktop and 320px, and again with ${SETTINGS.join(' and ')}.`);
})().catch(e => { console.error(e); process.exit(1); });
