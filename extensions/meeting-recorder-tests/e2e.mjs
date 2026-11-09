/* e2e.mjs — the Meeting Recorder, end to end, in a real Chromium.
 *
 *   node extensions/meeting-recorder-tests/e2e.mjs
 *
 * Starts the site on a throwaway database, builds the extension for it with a
 * throwaway key, loads it into Chromium, and then:
 *   1. connects it from the portal's real "Connect the recorder" page,
 *   2. records a generated tone through the real SegmentedRecorder (three
 *      segments), saved slice by slice into IndexedDB,
 *   3. lets the real uploader send it to integrations/recorder.php,
 *   4. runs the server's cron sweep (with a stand-in transcriber, so no AI key
 *      is needed) and checks the minutes landed on the meeting,
 *   5. checks the popup shows it and the local audio was freed.
 * Tab capture itself is not exercised: Chrome grants it only after a real
 * click on the extension, which automation cannot give. */
import { createRequire } from 'node:module';
import { spawn, execFileSync } from 'node:child_process';
import { mkdtempSync, cpSync, rmSync, existsSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const require = createRequire(import.meta.url);
const { chromium } = require('playwright');
const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..', '..');
const PORT = 8000 + Math.floor(Math.random() * 900);
const SITE = 'http://127.0.0.1:' + PORT;
const work = mkdtempSync(join(tmpdir(), 'avrec-e2e-'));
const env = { ...process.env, AV_DB_PATH: join(work, 'site.db'), AV_DB_DRIVER: 'sqlite', AV_DB_DSN: '', APP_KEY: 'e2e_key_0123456789abcdefghijklmnop',
  AV_REC_DIR: join(work, 'recordings'), AV_REC_CHUNK_MAX: '65536', AV_MAIL_DISABLED: '1', SITE_URL: SITE, AV_SITE_URL: SITE };
const php = (code) => execFileSync('php', ['-r', 'require "' + ROOT + '/lib/bootstrap.php"; ' + code], { env, cwd: ROOT }).toString();

let pass = 0, fail = 0;
const ck = (label, cond, extra = '') => { if (cond) { pass++; console.log('  ok   ' + label); } else { fail++; console.log('  FAIL ' + label + (extra ? ' — ' + extra : '')); } };
const wait = (ms) => new Promise((r) => setTimeout(r, ms));
async function until(fn, ms = 20000, step = 300) { const t = Date.now(); for (;;) { const v = await fn(); if (v) return v; if (Date.now() - t > ms) return v; await wait(step); } }

let server = null;
async function serverUp() {
  server = spawn('php', ['-S', '127.0.0.1:' + PORT, 'router.php'], { cwd: ROOT, env, stdio: 'ignore' });
  await until(async () => { try { return (await fetch(SITE + '/health.php')).status > 0; } catch (e) { return false; } }, 10000);
}
function serverDown() { if (server) server.kill(); server = null; }
let ctx;
try {
  await serverUp();

  // Never touch a real database: stop unless the site is on the throwaway one.
  const dbPath = php('echo AV_DB_PATH, "|", Database::configSource()["source"];');
  if (!dbPath.startsWith(work)) throw new Error('Refusing to run: the site would use ' + dbPath);
  // A member to sign in as, and a meeting with the code the harness "records".
  php(`Meetings::ensure(); Recorder::ensure(); $db = Database::pdo();
    $db->prepare("INSERT INTO lms_users (name,email,password_hash,role,status,email_verified) VALUES ('Tess Tester','tess@example.com',?,'member','active',1)")->execute([password_hash('Secret-pass-123', PASSWORD_DEFAULT)]);
    $uid = (int) $db->lastInsertId();
    $db->prepare("INSERT INTO meetings (creator_id,title,agenda,scheduled_at,duration_min,frequency,context,context_id,status,created_at,meet_code) VALUES (?,'Weekly standup','',?,30,'once','workspace',0,'scheduled',?,'abc-defg-hij')")->execute([$uid, gmdate('Y-m-d H:i:s'), gmdate('Y-m-d H:i:s')]);`);

  const build = join(work, 'ext');
  execFileSync('php', [join(ROOT, 'tools/build-recorder.php'), '--site=' + SITE, '--key=' + join(work, 'key.pem'), '--new-key', '--out=' + join(work, 'out'), '--unpacked=' + build], { env });
  cpSync(join(ROOT, 'extensions/meeting-recorder-tests/harness.html'), join(build, 'harness.html'));
  cpSync(join(ROOT, 'extensions/meeting-recorder-tests/harness.js'), join(build, 'harness.js'));

  console.log('\n# install');
  ctx = await chromium.launchPersistentContext(join(work, 'profile'), {
    channel: 'chromium', headless: true, args: ['--disable-extensions-except=' + build, '--load-extension=' + build, '--autoplay-policy=no-user-gesture-required'],
  });
  let [sw] = ctx.serviceWorkers();
  if (!sw) sw = await ctx.waitForEvent('serviceworker');
  const id = new URL(sw.url()).host;
  ck('the extension loads with the ID its key gives', id === execFileSync('cat', [join(work, 'out/extension-id.txt')]).toString().trim(), id);
  const welcome = await until(() => ctx.pages().find((p) => p.url().includes('/welcome.html')), 5000);
  ck('first run opens the set-up page', !!welcome);
  ck('the badge asks to be connected', (await sw.evaluate(() => chrome.action.getBadgeText({}))) === '!');

  console.log('\n# connect');
  const page = await ctx.newPage();
  await page.goto(SITE + '/academy/');
  const login = await page.evaluate(async () => (await fetch('/academy/api.php?action=login', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ email: 'tess@example.com', password: 'Secret-pass-123' }) })).json());
  ck('the test member signs in', !!login.ok, JSON.stringify(login));
  await page.goto(SITE + '/portal/recorder.php');
  ck('the page sees the extension', await until(() => page.evaluate(() => !document.getElementById('avrec-connect').disabled), 5000));
  await page.click('#avrec-connect');
  const said = await until(async () => { const t = await page.textContent('#avrec-state'); return /Connected|could not|did not/.test(t) && t; }, 15000);
  ck('connecting succeeds on the page', /^Connected/.test(said || ''), said);
  const local = await sw.evaluate(() => chrome.storage.local.get(['token', 'user']));
  ck('the extension holds a device token', /^av_rec_[a-f0-9]{48}$/.test(local.token || ''));
  ck('and knows who it is for', (local.user || {}).email === 'tess@example.com');
  await page.reload();
  ck('the page lists the connected browser', /Chrome on/.test(await page.textContent('ul.devices')));

  console.log('\n# record');
  const h = await ctx.newPage();
  await h.goto('chrome-extension://' + id + '/harness.html');
  await h.waitForFunction(() => document.getElementById('out').textContent === 'ready');
  const forged = await h.evaluate(() => chrome.runtime.sendMessage({ type: 'pair', code: 'a'.repeat(32) }));
  ck('a pairing code from anywhere but the portal page is refused', forged && forged.ok === false, JSON.stringify(forged));
  const pz = await h.evaluate(() => window.runPauseAndSilence());
  ck('pause works and is reported', pz.paused === true && pz.kinds.includes('paused') && pz.kinds.includes('resumed'), JSON.stringify(pz.kinds));
  ck('the recorded-time clock holds still while paused', pz.whilePaused - pz.atPause <= 1 && pz.after - pz.whilePaused >= 2, JSON.stringify([pz.atPause, pz.whilePaused, pz.after]));
  ck('a paused recorder never counts towards the silence stop', pz.pausedLevel && pz.pausedLevel.paused === true && pz.pausedLevel.silentFor === 0);
  ck('a tab sending nothing at all is seen as dead', pz.maxDead >= 2, JSON.stringify(pz));
  const rec = await h.evaluate(() => window.runRecording({ seconds: 7.5, segmentSeconds: 3, sliceMs: 500, marksAt: [1, 4.2] }));
  ck('Alt+Shift+M marks are taken by the background worker', rec.marked.length === 2 && rec.marked.every((m) => m && m.ok), JSON.stringify(rec.marked));
  ck('and saved with the recording, in seconds', rec.marks.length === 2 && rec.marks[0] >= 0 && rec.marks[0] <= 2 && rec.marks[1] >= 3 && rec.marks[1] <= 6, JSON.stringify(rec.marks));
  ck('three segments were recorded', rec.segments === 3, JSON.stringify(rec));
  ck('every segment was closed', rec.closed === 3);
  ck('slices were saved to IndexedDB as they came', rec.slices >= 12 && rec.bytes > 5000, rec.slices + ' slices, ' + rec.bytes + ' B');

  console.log('\n# upload');
  const up = await until(async () => { await h.evaluate(() => window.sync()); const l = await h.evaluate((r) => window.local(r), rec.rec); return l.rec.state === 'uploaded' && l; }, 30000, 800);
  ck('the uploader sends everything and the server confirms', !!up, up ? '' : JSON.stringify(await h.evaluate((r) => window.local(r), rec.rec)));
  ck('the local audio is freed once the server has it', up && up.slices === 0);
  ck('the recording found the scheduled meeting by its Meet code', up && up.rec.meetingTitle === 'Weekly standup');

  const files = JSON.parse(php(`$db = Database::pdo(); $out = [];
    foreach ($db->query("SELECT seg_no, path, bytes, offset_sec FROM recording_segments ORDER BY seg_no")->fetchAll(PDO::FETCH_ASSOC) as $s)
      $out[] = ['seg' => (int) $s['seg_no'], 'bytes' => (int) $s['bytes'], 'offset' => (int) $s['offset_sec'], 'ebml' => is_file($s['path']) && bin2hex((string) file_get_contents($s['path'], false, null, 0, 4)) === '1a45dfa3'];
    echo json_encode($out);`));
  ck('the server assembled three segment files', files.length === 3, JSON.stringify(files));
  ck('each is a standalone WebM file (EBML header)', files.every((f) => f.ebml));
  ck('their start times are in order', files[0].offset === 0 && files[1].offset >= 2 && files[2].offset > files[1].offset, JSON.stringify(files.map((f) => f.offset)));

  console.log('\n# minutes');
  const sweep = JSON.parse(php(`echo json_encode(Recorder::sweep(20, static fn($b, $m) => ['ok' => true, 'text' => 'Heard ' . strlen($b) . ' bytes of ' . $m]));`));
  ck('the cron transcribes all three parts and writes the minutes', sweep.transcribed === 3 && sweep.finalised === 1, JSON.stringify(sweep));
  const tr = php(`echo (string) Database::pdo()->query("SELECT raw_text FROM meeting_transcripts t JOIN meetings m ON m.id = t.meeting_id WHERE m.title = 'Weekly standup'")->fetchColumn();`);
  ck('the meeting has the stitched transcript', /^\[00:00:00\]\nHeard \d+ bytes of audio\/webm/.test(tr) && (tr.match(/\[00:00:0\d\]/g) || []).length === 3, JSON.stringify(tr.slice(0, 120)));
  ck('the marked moments are in the minutes\' transcript', (tr.match(/★ Marked as important/g) || []).length === 2, JSON.stringify(tr));
  ck('the transcriber was given the meeting context', php(`echo json_encode(Recorder::hints((int) Database::pdo()->query("SELECT id FROM recordings ORDER BY id LIMIT 1")->fetchColumn(), 1));`).includes('Weekly standup'));
  const done = await until(async () => { await h.evaluate(() => window.sync()); const l = await h.evaluate((r) => window.local(r), rec.rec); return l.rec.state === 'done' && l; }, 15000, 800);
  ck('the extension learns the minutes are ready', !!done);

  const b64 = php(`echo base64_encode((string) file_get_contents((string) Database::pdo()->query("SELECT path FROM recording_segments WHERE seg_no = 1")->fetchColumn()));`);
  const dur = await h.evaluate(async (b) => { const bytes = Uint8Array.from(atob(b), (c) => c.charCodeAt(0)); const ac = new AudioContext(); try { return (await ac.decodeAudioData(bytes.buffer)).duration; } catch (e) { return 'error: ' + e.message; } }, b64.trim());
  ck('a middle segment plays on its own (Chromium decodes it)', typeof dur === 'number' && dur > 2 && dur < 4.5, String(dur));

  console.log('\n# offline, and a segment bigger than one chunk');
  serverDown();
  const rec2 = await h.evaluate(() => window.runRecording({ seconds: 22, segmentSeconds: 30, sliceMs: 1000 }));
  ck('it records with the server unreachable', rec2.segments === 1 && rec2.bytes > 70000, rec2.bytes + ' B');
  const offline = await h.evaluate(() => window.sync());
  ck('the uploader reports it is offline and keeps the audio', offline.error === 'offline' && (await h.evaluate((r) => window.local(r), rec2.rec)).slices > 0, JSON.stringify(offline));
  await serverUp();
  const up2 = await until(async () => { await h.evaluate(() => window.sync()); const l = await h.evaluate((r) => window.local(r), rec2.rec); return l.rec.state === 'uploaded' && l; }, 30000, 800);
  ck('when the server is back the upload finishes by itself', !!up2);
  const seg2 = JSON.parse(php(`$r = Database::pdo()->query("SELECT s.bytes, s.path FROM recording_segments s JOIN recordings r ON r.id = s.recording_id ORDER BY r.id DESC, s.seg_no LIMIT 1")->fetch(PDO::FETCH_ASSOC); echo json_encode(['bytes' => (int) $r['bytes'], 'ok' => is_file($r['path'])]);`));
  ck('the segment was sent as several chunks and joined exactly', seg2.ok && seg2.bytes === rec2.bytes, seg2.bytes + ' vs ' + rec2.bytes);
  php(`Recorder::sweep(20, static fn() => ['ok' => true, 'text' => 'second']);`);
  await until(async () => { await h.evaluate(() => window.sync()); return (await h.evaluate((r) => window.local(r), rec2.rec)).rec.state !== 'uploaded'; }, 15000, 800);

  console.log('\n# popup');
  const pop = await ctx.newPage();
  await pop.setViewportSize({ width: 340, height: 460 });
  await pop.goto('chrome-extension://' + id + '/popup.html');
  await pop.waitForSelector('#queue li');
  ck('the popup says who it is connected as', /Tess Tester/.test(await pop.textContent('#who')));
  ck('the popup lists the recording with its minutes', /Minutes ready/.test(await pop.textContent('#queue')));
  if (process.env.SHOT) await pop.screenshot({ path: process.env.SHOT });
  ck('the badge is clear when nothing is waiting', (await sw.evaluate(() => chrome.action.getBadgeText({}))) === '');
} catch (e) {
  fail++; console.log('  FAIL (threw) ' + (e && e.stack || e));
} finally {
  if (ctx) await ctx.close().catch(() => {});
  serverDown();
  if (!process.env.KEEP) rmSync(work, { recursive: true, force: true }); else console.log('kept ' + work);
}
console.log('\n' + (fail ? 'FAILED — ' + fail + ' of ' + (pass + fail) : 'OK — ' + pass + ' checks passed'));
process.exit(fail ? 1 : 0);
