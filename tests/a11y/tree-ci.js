/**
 * tests/a11y/tree-ci.js — what a screen reader is given, pinned.
 *
 * axe says whether a page breaks a rule; it does not notice that the search
 * button lost its name, that the footer nav was renamed, or that a page grew
 * a second main. This reads key pages through the browser's accessibility
 * tree (Playwright's locator.ariaSnapshot(), the same roles and names a
 * screen reader gets) and compares them with the snapshots in
 * tests/a11y/snapshots/:
 *
 *   - for each page, its OUTLINE: the landmarks (banner, navigation and its
 *     name, main, complementary, search, contentinfo) and the page's <h1> —
 *     the parts that stay the same whatever the database holds;
 *   - in full, the Accessibility settings panel and the empty search
 *     palette, which are the same on every page.
 *
 *   BASE=http://127.0.0.1:8000 TOKEN=… MEMBER_TOKEN=… node tests/a11y/tree-ci.js
 *   UPDATE=1 … node tests/a11y/tree-ci.js     # accept the current trees
 *
 * A difference is printed as a diff and fails the run. If the change was
 * meant, look at the new tree as a screen reader user would, then UPDATE=1.
 */
'use strict';
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const BASE = (process.env.BASE || 'http://127.0.0.1:8000').replace(/\/$/, '');
const TOKEN = process.env.TOKEN || '', MEMBER_TOKEN = process.env.MEMBER_TOKEN || '';
const UPDATE = !!process.env.UPDATE;
const DIR = path.join(__dirname, 'snapshots');

const PAGES = [
  ['home', '/', ''],
  ['accessibility', '/accessibility/', ''],
  ['academy', '/academy/', ''],
  ['lesson', '/academy/techome/learn/welcome-to-techome', 'learner'],
  ['portal', '/portal/#overview', 'member', { h1: 'greeting' }],   // the Today view's h1 greets the member by name
];
const KEEP = /^\s*- (banner|main|contentinfo|navigation|complementary|search|dialog)\b|^\s*- heading .*\[level=1\]/;

/* The landmarks and the h1, indented by depth, names kept. */
function outline(yaml) {
  return yaml.split('\n').filter(l => KEEP.test(l))
    .map(l => l.replace(/:\s*$/, '').replace(/^(\s*)/, (m, s) => ' '.repeat(Math.floor(s.length / 2) * 2)))
    .map(l => l.trimEnd()).join('\n') + '\n';
}
function diff(a, b) {
  const A = a.split('\n'), B = b.split('\n'), out = [];
  const n = Math.max(A.length, B.length);
  for (let i = 0; i < n; i++) if (A[i] !== B[i]) out.push(`  line ${i + 1}:\n    - ${A[i] === undefined ? '(none)' : A[i]}\n    + ${B[i] === undefined ? '(none)' : B[i]}`);
  return out.slice(0, 12).join('\n');
}

(async () => {
  if (!fs.existsSync(DIR)) fs.mkdirSync(DIR, { recursive: true });
  const browser = await chromium.launch();
  const failures = [], report = [];
  async function check(name, text) {
    const file = path.join(DIR, name + '.yml');
    if (UPDATE || !fs.existsSync(file)) { fs.writeFileSync(file, text); report.push(`• ${name}: snapshot ${UPDATE ? 'updated' : 'written'}`); return; }
    const want = fs.readFileSync(file, 'utf8');
    if (want === text) report.push(`✓ ${name}`);
    else { failures.push(name); report.push(`✗ ${name} — the accessibility tree changed:\n${diff(want, text)}`); }
  }
  for (const [name, p, who, opt] of PAGES) {
    const tok = who === 'learner' ? TOKEN : who === 'member' ? MEMBER_TOKEN : '';
    if (who && !tok) { report.push(`- ${name}: skipped (no ${who} token)`); continue; }
    const ctx = await browser.newContext({ viewport: { width: 1280, height: 800 }, reducedMotion: 'reduce' });
    const cookies = [{ name: 'av_consent', value: 'analytics%3D0', url: BASE }];
    if (tok) cookies.push({ name: 'av_lms', value: tok, url: BASE });
    await ctx.addCookies(cookies);
    const pg = await ctx.newPage();
    await pg.goto(BASE + p, { waitUntil: 'networkidle', timeout: 60000 });
    if (tok && /\/login/.test(new URL(pg.url()).pathname)) { failures.push(name); report.push(`✗ ${name}: the session token was not accepted`); await ctx.close(); continue; }
    await pg.waitForTimeout(400);
    await pg.evaluate(() => document.querySelectorAll('.avcel-layer,.ch-greet').forEach(e => e.remove()));
    let tree = outline(await pg.locator('body').ariaSnapshot());
    if (opt && opt.h1) tree = tree.replace(/(- heading )"[^"]*"( \[level=1\])/, '$1"(' + opt.h1 + ')"$2');
    await check('page-' + name, tree);
    if (name === 'accessibility') {
      await pg.keyboard.press('Alt+Shift+A');
      await pg.waitForSelector('#av-a11y[open]');
      await check('settings-panel', (await pg.locator('#av-a11y').ariaSnapshot()) + '\n');
      await pg.keyboard.press('Escape');
      await pg.keyboard.press('Control+k');
      await pg.waitForSelector('#av-search[open]');
      await pg.waitForTimeout(200);
      await check('search-palette', (await pg.locator('#av-search').ariaSnapshot()) + '\n');
    }
    await ctx.close();
  }
  await browser.close();
  console.log(report.join('\n'));
  if (failures.length) { console.log(`\n${failures.length} accessibility tree(s) changed. If that was meant, run again with UPDATE=1.`); process.exit(1); }
  console.log('\nThe accessibility trees match the snapshots.');
})().catch(e => { console.error(e); process.exit(1); });
