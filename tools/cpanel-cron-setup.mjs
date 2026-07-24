#!/usr/bin/env node
/**
 * tools/cpanel-cron-setup.mjs — create the Afrovanguard web-cron on shared
 * cPanel hosting WITHOUT SSH, by driving a browser.
 *
 * The portal's periodic jobs (reminders, task-deadline nudges, webhook retries,
 * birthday emails) run when something hits tasks/cron.php every few minutes.
 * On hosts with no shell/SSH you'd normally add that in cPanel → "Cron Jobs" by
 * hand. This script does it for you: it logs into cPanel in a real browser, then
 * calls cPanel's own UAPI (Cron::list_lines / Cron::add_line) from inside that
 * authenticated session — so it's reliable across cPanel themes (no fragile
 * form-scraping) and it's idempotent (won't add a duplicate line).
 *
 * ── One-time install (on YOUR machine, not the server) ──────────────────────
 *   npm i playwright          # installs Chromium too (~1 min)
 *
 * ── Run ─────────────────────────────────────────────────────────────────────
 *   node tools/cpanel-cron-setup.mjs \
 *     --cpanel https://yourhost.com:2083 \
 *     --user   your_cpanel_user \
 *     --site   https://afrovanguard.org.ng \
 *     --key    YOUR_AV_CRON_KEY \
 *     --schedule "EVERY-5-MIN"        # optional; default is every 5 minutes
 *
 * The cPanel password is read from the env var CPANEL_PASS, or you'll be
 * prompted for it (hidden). 2FA code: --otp 123456 (or env CPANEL_OTP).
 *
 * Safe first:   add --dry-run to log in, list existing cron jobs, and print the
 *               line it WOULD add (plus a screenshot) — without changing anything.
 * Watch it:     add --headful to see the browser.
 *
 * Notes
 *  • --cpanel must be your DIRECT cPanel login (usually https://host:2083, or
 *    https://cpanel.yourdomain.com). A hosting-provider SSO dashboard won't work.
 *  • The key value must match what the server expects: AV_CRON_KEY (or, if that
 *    isn't set, AV_ADMIN_TOKEN). See tasks/cron.php.
 *  • Nothing is stored: the password/key live only in memory for this run and
 *    are never written to disk or logged.
 */

import readline from 'node:readline';

/* ── tiny arg parser (--flag value / --flag=value / boolean --flag) ── */
function parseArgs(argv) {
  const out = {};
  for (let i = 0; i < argv.length; i++) {
    const a = argv[i];
    if (!a.startsWith('--')) continue;
    const eq = a.indexOf('=');
    if (eq !== -1) { out[a.slice(2, eq)] = a.slice(eq + 1); continue; }
    const key = a.slice(2);
    const next = argv[i + 1];
    if (next && !next.startsWith('--')) { out[key] = next; i++; } else { out[key] = true; }
  }
  return out;
}

function die(msg) { console.error('\n✖ ' + msg + '\n'); process.exit(1); }
function mask(s) { return s ? s.slice(0, 2) + '…' + s.slice(-2) : ''; }

/* ── hidden password prompt (no echo) ── */
function promptHidden(question) {
  return new Promise((resolve) => {
    const rl = readline.createInterface({ input: process.stdin, output: process.stdout });
    const onData = (ch) => {
      const s = ch.toString();
      if (s === '\n' || s === '\r' || s === '') { process.stdin.removeListener('data', onData); return; }
      // erase whatever readline echoed, keep the prompt line clean
      process.stdout.write('\x1b[2K\x1b[200D' + question);
    };
    process.stdout.write(question);
    process.stdin.on('data', onData);
    rl.question('', (answer) => { process.stdin.removeListener('data', onData); rl.close(); process.stdout.write('\n'); resolve(answer); });
  });
}

function validCron(expr) {
  const p = expr.trim().split(/\s+/);
  if (p.length !== 5) return false;
  return p.every((f) => /^[0-9*/,\-]+$/.test(f));
}

async function main() {
  const args = parseArgs(process.argv.slice(2));
  if (args.help || args.h) { console.log('See the header of this file for usage.'); process.exit(0); }

  const cpanel = (args.cpanel || process.env.CPANEL_URL || '').trim().replace(/\/+$/, '');
  const user   = (args.user   || process.env.CPANEL_USER || '').trim();
  const site   = (args.site   || process.env.SITE_URL || '').trim().replace(/\/+$/, '');
  const key    = (args.key    || process.env.CRON_KEY || process.env.AV_CRON_KEY || '').trim();
  const schedule = (args.schedule || process.env.CRON_SCHEDULE || '*/5 * * * *').trim();
  const runner = (args.wget ? 'wget' : 'curl');
  const dryRun = !!args['dry-run'];
  const headful = !!args.headful;
  let pass = process.env.CPANEL_PASS || '';
  let otp  = (args.otp || process.env.CPANEL_OTP || '').toString().trim();

  if (!cpanel) die('Missing --cpanel (e.g. https://yourhost.com:2083)');
  if (!user)   die('Missing --user (your cPanel username)');
  if (!site)   die('Missing --site (e.g. https://afrovanguard.org.ng)');
  if (!key)    die('Missing --key (the AV_CRON_KEY value configured on the server)');
  if (!/^https?:\/\//.test(cpanel)) die('--cpanel must start with https:// (or http://)');
  if (!/^https?:\/\//.test(site))   die('--site must start with https://');
  if (!validCron(schedule)) die('--schedule must be a 5-field cron expression, e.g. "*/5 * * * *"');

  let chromium;
  try { ({ chromium } = await import('playwright')); }
  catch { die('Playwright is not installed. Run:  npm i playwright   (then re-run this script)'); }

  if (!pass) pass = await promptHidden(`cPanel password for "${user}": `);
  if (!pass) die('No password provided.');

  // The command cPanel will run. Redirect output so cron doesn't email every run.
  const url = `${site}/tasks/cron.php?key=${encodeURIComponent(key)}`;
  const command = runner === 'wget'
    ? `wget -q -O /dev/null --timeout=60 "${url}"`
    : `curl -fsS --max-time 60 "${url}" >/dev/null 2>&1`;

  const [minute, hour, day, month, weekday] = schedule.split(/\s+/);

  console.log('\nAfrovanguard cPanel cron setup');
  console.log('  cPanel : ' + cpanel);
  console.log('  user   : ' + user + (otp ? '  (+2FA)' : ''));
  console.log('  key    : ' + mask(key));
  console.log('  cron   : ' + schedule);
  console.log('  runs   : ' + command.replace(key, mask(key)));
  console.log(dryRun ? '  mode   : DRY RUN (no changes)\n' : '');

  const browser = await chromium.launch({ headless: !headful });
  const ctx = await browser.newContext({ ignoreHTTPSErrors: true }); // some shared hosts have imperfect chains
  const page = await ctx.newPage();
  let ok = false;
  try {
    // 1) Log in.
    await page.goto(cpanel + '/login/?login_only=1', { waitUntil: 'domcontentloaded', timeout: 45000 })
      .catch(() => page.goto(cpanel, { waitUntil: 'domcontentloaded', timeout: 45000 }));
    await page.fill('input[name="user"], #user', user, { timeout: 20000 }).catch(() => die('Could not find the cPanel username field — is --cpanel the direct cPanel login URL?'));
    await page.fill('input[name="pass"], #pass', pass, { timeout: 20000 });
    await Promise.all([
      page.waitForNavigation({ timeout: 45000 }).catch(() => {}),
      page.click('#login_submit, button[type="submit"], input[type="submit"]'),
    ]);

    // 2) Optional 2FA.
    const otpField = await page.$('input[name="security_code"], #security_code');
    if (otpField) {
      if (!otp) { await browser.close(); die('This account has 2FA on. Re-run with --otp <code> (codes expire fast, so have it ready).'); }
      await otpField.fill(otp);
      await Promise.all([ page.waitForNavigation({ timeout: 45000 }).catch(() => {}), page.click('#security_code_submit, button[type="submit"], input[type="submit"]') ]);
    }

    // Detect a failed login (still on a login page / error banner).
    const landed = page.url();
    if (/\/login\//.test(landed) || await page.$('.errors, #errors, .alert-danger')) {
      const err = (await page.$eval('.errors, #errors, .alert-danger', (e) => e.textContent.trim()).catch(() => '')) || 'Login failed (check username/password, or 2FA).';
      await browser.close(); die(err);
    }
    console.log('✓ Logged in.');

    // 3) Derive the authenticated session base (…/cpsessXXXX/). cPanel embeds a
    //    per-session security token in the path; UAPI lives under it. If tokens
    //    are disabled, fall back to the origin (cookies alone authenticate).
    const m = landed.match(/^(https?:\/\/[^/]+\/cpsess\d+)\//);
    const base = m ? m[1] : new URL(landed).origin;
    const uapi = (module, fn, params) => {
      const qs = params ? '?' + new URLSearchParams(params).toString() : '';
      return `${base}/execute/${module}/${fn}${qs}`;
    };

    // Helper: call UAPI from inside the page (same-origin fetch → sends cookies).
    const call = async (url, method = 'GET', body = null) => {
      return await page.evaluate(async ({ url, method, body }) => {
        const opt = { method, credentials: 'include', headers: {} };
        if (body) { opt.headers['Content-Type'] = 'application/x-www-form-urlencoded'; opt.body = body; }
        const r = await fetch(url, opt);
        const text = await r.text();
        try { return { status: r.status, json: JSON.parse(text) }; } catch { return { status: r.status, text: text.slice(0, 400) }; }
      }, { url, method, body });
    };

    // 4) Idempotency — is this exact command already scheduled?
    const list = await call(uapi('Cron', 'list_lines'));
    const lines = (list.json && (list.json.data || list.json.result || [])) || [];
    const flat = Array.isArray(lines) ? lines.map((x) => (typeof x === 'string' ? x : (x.command || x.line || JSON.stringify(x)))) : [];
    const already = flat.some((l) => typeof l === 'string' && l.includes('/tasks/cron.php'));

    if (dryRun) {
      console.log('\nExisting cron lines that hit tasks/cron.php:');
      const hits = flat.filter((l) => l.includes('/tasks/cron.php'));
      console.log(hits.length ? hits.map((l) => '  • ' + l.replace(key, mask(key))).join('\n') : '  (none)');
      console.log('\nWould add:\n  ' + schedule + '  ' + command.replace(key, mask(key)));
      const shot = 'cpanel-cron-dryrun.png';
      await page.goto(base + '/frontend/jupiter/cron/index.html', { waitUntil: 'domcontentloaded', timeout: 30000 }).catch(() => {});
      await page.screenshot({ path: shot, fullPage: true }).catch(() => {});
      console.log('\nScreenshot of the Cron Jobs page saved to ' + shot);
      ok = true;
    } else if (already) {
      console.log('\n✓ A cron job for tasks/cron.php already exists — nothing to do. (Idempotent.)');
      ok = true;
    } else {
      // 5) Add the line via UAPI.
      const body = new URLSearchParams({ command, minute, hour, day, month, weekday }).toString();
      let res = await call(uapi('Cron', 'add_line'), 'POST', body);
      if (!(res.json && res.json.status === 1)) {
        // Some builds only accept GET for UAPI; retry.
        res = await call(uapi('Cron', 'add_line', { command, minute, hour, day, month, weekday }));
      }
      if (res.json && res.json.status === 1) {
        console.log('\n✓ Cron job created:\n  ' + schedule + '  ' + command.replace(key, mask(key)));
        console.log('  Verify anytime in cPanel → Cron Jobs.');
        ok = true;
      } else {
        const why = (res.json && (res.json.errors || []).join('; ')) || res.text || ('HTTP ' + res.status);
        die('cPanel refused to add the cron line: ' + why + '\n  (Try --dry-run to inspect, or add it by hand in cPanel → Cron Jobs.)');
      }
    }
  } catch (e) {
    console.error('\n✖ ' + (e && e.message ? e.message : e));
  } finally {
    await browser.close();
  }
  process.exit(ok ? 0 : 1);
}

main();
