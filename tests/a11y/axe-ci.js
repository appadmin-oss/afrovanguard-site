/**
 * tests/a11y/axe-ci.js — the accessibility gate CI runs on every push.
 *
 * Loads each page in Chromium against a local `php -S` of this checkout, at a
 * desktop width and a 320px phone width (what 400% zoom looks like), and
 * fails if:
 *   - axe-core finds any WCAG 2.0/2.1/2.2 A or AA violation, or
 *   - the page scrolls sideways at 320px (WCAG 1.4.10 Reflow).
 *
 *   BASE=http://127.0.0.1:8000 TOKEN=<av_lms session> node tests/a11y/axe-ci.js
 *
 * TOKEN (from tests/a11y/ci-session.php) adds the pages only a signed-in
 * learner sees. Without it they are skipped, and the run says so.
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
  '/accessibility/', '/terms/', '/privacy-policy/', '/reports/', '/login',
  '/academy/', '/academy/techome/', '/academy/programmes/', '/academy/ngv/',
  '/academy/ngv/register.php', '/academy/dns/', '/academy/verify.php',
];
const LEARNER = [
  '/academy/my', '/academy/techome/learn/welcome-to-techome',
  '/academy/techome/learn/check-your-understanding',
  '/academy/techome/grades/', '/academy/techome/discussions/',
];

(async () => {
  const browser = await chromium.launch();
  const failures = [];
  const report = [];
  const pages = PUBLIC.concat(TOKEN ? LEARNER : []);
  for (const [w, h, label] of [[1280, 800, 'desktop'], [320, 640, '320px']]) {
    const ctx = await browser.newContext({ viewport: { width: w, height: h }, reducedMotion: 'reduce' });
    const cookies = [{ name: 'av_consent', value: 'analytics%3D0', url: BASE }];
    if (TOKEN) cookies.push({ name: 'av_lms', value: TOKEN, url: BASE });
    await ctx.addCookies(cookies);
    for (const p of pages) {
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
      if (label === '320px' && overflow > 1) bad.push(`reflow: the page is ${overflow}px wider than a 320px screen (WCAG 1.4.10)`);
      if (bad.length) { failures.push(...bad.map(b => `${label} ${p}: ${b}`)); report.push(`✗ ${label} ${p}\n    ` + bad.join('\n    ')); }
      else report.push(`✓ ${label} ${p}`);
      await pg.close();
    }
    await ctx.close();
  }
  await browser.close();
  console.log(report.join('\n'));
  if (!TOKEN) console.log('\n(no TOKEN: the signed-in learner pages were not tested)');
  if (failures.length) {
    console.log(`\n${failures.length} accessibility failure(s). Each line above says what, where, and links to how to fix it.`);
    process.exit(1);
  }
  console.log(`\nAll ${pages.length} pages pass axe (WCAG 2.2 A/AA) at desktop and 320px.`);
})().catch(e => { console.error(e); process.exit(1); });
