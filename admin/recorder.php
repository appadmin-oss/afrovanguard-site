<?php
/**
 * admin/recorder.php — publish the Meeting Recorder from the Studio. No SSH.
 *
 *   GET                       the page: what is published, and what to do next
 *   POST action=build         sign and publish (makes the signing key the first time)
 *   POST action=download_key  the signing key, to keep safe (once, then from your backup)
 *   POST action=restore_key   put a backed-up key on a new host (only when none is there)
 *
 * Superadmins only. Every POST is same-origin + CSRF and goes in the audit log.
 * The build itself is lib/RecorderBuild.php, shared with tools/build-recorder.php.
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_once AV_ROOT . '/lib/RecorderBuild.php';

header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
$role = function_exists('av_admin_role') ? av_admin_role() : '';
if ($role !== 'superadmin') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo $role === '' ? "Sign in to the Studio first.\n" : "Publishing the Meeting Recorder is for superadmins.\n";
    exit;
}
$audit = static function (string $what, string $detail = ''): void {
    try { (new LmsRepository())->audit('recorder_' . $what, 'meeting-recorder', $detail, av_admin_actor()); } catch (Throwable $e) { error_log('[recorder-admin] audit: ' . $e->getMessage()); }
};

$flash = '';
$flashOk = true;
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    require_same_origin();
    if (!av_csrf_valid((string) ($_POST['csrf'] ?? ''))) { http_response_code(403); exit('This form expired. Go back and reload the page.'); }
    if (!av_rate_ok('recorder_admin', 20, 600)) { http_response_code(429); exit('Slow down a moment.'); }
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'download_key') {
        $path = RecorderBuild::keyPath();
        if (!is_file($path)) { $flash = 'There is no key yet. Publish first.'; $flashOk = false; }
        else {
            $audit('key_downloaded');
            header('Content-Type: application/x-pem-file');
            header('Content-Disposition: attachment; filename="afrovanguard-meeting-recorder-key.pem"');
            readfile($path);
            exit;
        }
    } elseif ($action === 'revoke_device') {
        $id = (int) ($_POST['id'] ?? 0);
        $ok = Recorder::adminRevoke($id);
        $flash = $ok ? 'Disconnected. That browser can no longer send recordings.' : 'That browser was already disconnected.';
        $flashOk = $ok;
        if ($ok) $audit('device_revoked', 'device #' . $id);
    } elseif ($action === 'restore_key') {
        $f = $_FILES['key'] ?? null;
        $pem = ($f && (int) $f['error'] === UPLOAD_ERR_OK && (int) $f['size'] < 16384) ? (string) file_get_contents((string) $f['tmp_name']) : '';
        $r = $pem === '' ? ['ok' => false, 'error' => 'Choose the key file you downloaded earlier.'] : RecorderBuild::restoreKey($pem);
        $flash = $r['ok'] ? 'Key restored. Publish to sign with it.' : $r['error'];
        $flashOk = $r['ok'];
        if ($r['ok']) $audit('key_restored');
    } elseif ($action === 'build') {
        $made = false;
        if (!RecorderBuild::status()['has_key']) {
            $k = RecorderBuild::createKey();
            if (!$k['ok']) { $flash = $k['error']; $flashOk = false; }
            else { $made = true; $audit('key_created'); }
        }
        if ($flash === '') {
            $r = RecorderBuild::build(SITE_URL);
            if ($r['ok']) {
                $flash = 'Published version ' . $r['version'] . '.' . ($made ? ' A signing key was made — download it below and keep it safe.' : '');
                $audit('published', 'version ' . $r['version'] . ', id ' . $r['id']);
            } else { $flash = $r['error']; $flashOk = false; }
        }
    }
}

$s = RecorderBuild::status();
$needsBuild = $s['has_key'] && $s['source_version'] !== '' && $s['source_version'] !== $s['built_version'];
$canBuild = $s['openssl'] && $s['zip'] && $s['writable'];
$transcribes = class_exists('Recorder') && Recorder::canTranscribe();
$backlog = class_exists('Recorder') ? Recorder::backlog() : ['waiting' => 0, 'oldest' => '', 'bytes' => 0, 'free' => null];
$mb = static fn(int $b): string => $b >= 1073741824 ? round($b / 1073741824, 1) . ' GB' : max(1, (int) round($b / 1048576)) . ' MB';
$csrf = av_csrf_token();
$devicesAll = Recorder::allDevices();
header('Content-Type: text/html; charset=utf-8');
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Meeting Recorder · Studio</title>
<style>
  :root { --ink:#15120e; --muted:#5d6470; --line:#e7e9ee; --bg:#f5f6f8; --card:#fff; --brand:#e4162b; --ok:#137a3a; --warn:#9a5b00; }
  @media (prefers-color-scheme: dark) { :root { --ink:#f1efe9; --muted:#a8adb7; --line:#2b2f36; --bg:#111317; --card:#1a1d22; --ok:#4fc27a; --warn:#e0a640; } }
  * { box-sizing:border-box }
  body { margin:0; font-family:system-ui,-apple-system,"Segoe UI",sans-serif; background:var(--bg); color:var(--ink); line-height:1.5 }
  main { max-width:720px; margin:0 auto; padding:32px 16px 64px }
  h1 { font-size:26px; margin:0 0 4px } h2 { font-size:17px; margin:0 0 10px }
  p { margin:0 0 10px } .muted { color:var(--muted) } .small { font-size:13px }
  .card { background:var(--card); border:1px solid var(--line); border-radius:16px; padding:20px; margin-top:16px }
  .btn { border:0; border-radius:999px; padding:11px 20px; font:600 15px/1 inherit; background:var(--brand); color:#fff; cursor:pointer }
  .btn--line { background:transparent; color:var(--ink); border:1px solid var(--line) }
  .btn[disabled] { opacity:.5; cursor:default }
  .flash { border-radius:12px; padding:12px 14px; margin-top:16px; font-weight:600 }
  .flash.ok { background:color-mix(in srgb, var(--ok) 14%, transparent); color:var(--ok) }
  .flash.err { background:color-mix(in srgb, var(--brand) 12%, transparent); color:var(--brand) }
  dl { display:grid; grid-template-columns:max-content 1fr; gap:6px 16px; margin:0 }
  dt { color:var(--muted) } dd { margin:0; overflow-wrap:anywhere }
  code { font:13px ui-monospace,Menlo,monospace; background:var(--bg); padding:2px 6px; border-radius:6px; user-select:all }
  ol { padding-left:20px; margin:0 } ol li { margin-bottom:8px }
  .ok { color:var(--ok) } .warn { color:var(--warn) }
  a { color:var(--brand) }
  @media (max-width:520px) { dl { grid-template-columns:1fr } dt { margin-top:6px } }
</style>
</head>
<body>
<main>
  <p class="small"><a href="/admin/">← Studio</a></p>
  <h1>Meeting Recorder</h1>
  <p class="muted">Publish the Chrome extension from here, then install it for staff from the Google Admin console. No SSH needed.</p>
<?php if ($flash !== ''): ?>
  <p class="flash <?= $flashOk ? 'ok' : 'err' ?>" role="status"><?= e($flash) ?></p>
<?php endif; ?>

  <div class="card">
    <h2>1. Publish</h2>
<?php if (!$canBuild): ?>
    <p class="warn">This host cannot publish yet:
      <?= !$s['openssl'] ? 'PHP has no OpenSSL. ' : '' ?><?= !$s['zip'] ? 'PHP has no Zip extension (cPanel → Select PHP Version → tick “zip”). ' : '' ?><?= !$s['writable'] ? 'The site cannot write the <code>downloads/</code> folder (File Manager → create <code>downloads</code> in the web root, permissions 755).' : '' ?></p>
<?php elseif (!$s['has_key']): ?>
    <p>Not published yet. Publishing makes the extension’s signing key on this server, signs the extension and puts it where Chrome can fetch it.</p>
<?php elseif ($needsBuild): ?>
    <p class="warn">The extension’s code is version <?= e($s['source_version']) ?>; the published one is <?= e($s['built_version'] ?: 'none') ?>. Publish to update everyone’s copy.</p>
<?php else: ?>
    <p class="ok">Version <?= e($s['built_version']) ?> is published. Installed copies update themselves within a few hours.</p>
<?php endif; ?>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="action" value="build">
      <button class="btn" <?= $canBuild ? '' : 'disabled' ?>><?= $s['has_key'] ? 'Publish version ' . e($s['source_version']) : 'Publish for the first time' ?></button>
    </form>
  </div>

<?php if ($s['has_key']): ?>
  <div class="card">
    <h2>2. Keep the signing key safe</h2>
    <p>The key is the extension’s identity: every installed copy accepts updates only if they are signed with it. It stays on this server, out of reach of the web. Download a copy once and keep it with the organisation’s other passwords. If the site moves host, you upload it again below.</p>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="action" value="download_key">
      <button class="btn btn--line">Download the key</button>
    </form>
  </div>

  <div class="card">
    <h2>3. Install it for staff (once)</h2>
    <dl>
      <dt>Extension ID</dt><dd><code><?= e($s['extension_id']) ?></code></dd>
      <dt>Update URL</dt><dd><code><?= e($s['update_url']) ?></code></dd>
    </dl>
    <ol style="margin-top:14px">
      <li>Open <a href="https://admin.google.com/ac/chrome/apps/user" target="_blank" rel="noopener">admin.google.com → Devices → Chrome → Apps &amp; extensions → Users &amp; browsers</a>.</li>
      <li>Choose the staff organisational unit on the left.</li>
      <li>Click the yellow <b>+</b> → <b>Add Chrome app or extension by ID</b> → <b>From a custom URL</b>. Paste the ID and the Update URL above, then save.</li>
      <li>Set its installation policy to <b>Force install</b> (and <b>Pin to browser toolbar</b>). Save.</li>
      <li>Within about an hour it appears for anyone signed in to Chrome with an afrovanguard.org.ng account. To check sooner on a laptop: <code>chrome://policy</code> → Reload policies.</li>
    </ol>
  </div>
<?php else: ?>
  <div class="card">
    <h2>Moving host?</h2>
    <p>If the recorder was published before on another server, upload the key you downloaded then, so the extension keeps its ID. Otherwise ignore this.</p>
    <form method="post" enctype="multipart/form-data">
      <input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="action" value="restore_key">
      <input type="file" name="key" accept=".pem" required> <button class="btn btn--line">Restore the key</button>
    </form>
  </div>
<?php endif; ?>

<?php $ck = av_cron_key(); ?>
  <div class="card">
    <h2>Scheduled job (cron)</h2>
    <p>Recordings are transcribed, and old audio deleted, by the site's scheduled job. In cPanel → <b>Cron Jobs</b>, run this every 5 minutes:</p>
<?php if ($ck['key'] !== ''): ?>
    <p><code style="display:block;white-space:pre-wrap;word-break:break-all;padding:10px">curl -fsS --max-time 60 -H "X-AV-Cron-Key: <?= e($ck['key']) ?>" "<?= e(rtrim(SITE_URL, '/')) ?>/tasks/cron.php" &gt;/dev/null 2&gt;&amp;1</code></p>
    <p class="muted small"><?= $ck['source'] === 'env' ? 'The key is AV_CRON_KEY from the server settings.' : 'The key is derived from this site\'s secret; set AV_CRON_KEY to choose your own.' ?> The admin token is no longer accepted as the cron key — if your cron job used it, replace the command with this one. Keep the key private: it lets anyone run the scheduled job.</p>
<?php else: ?>
    <p class="warn">No cron key can be made: set APP_KEY (or AV_CRON_KEY) in the server settings.</p>
<?php endif; ?>
  </div>

  <div class="card">
    <h2>Connected browsers</h2>
<?php if (!$devicesAll): ?>
    <p class="muted">None yet.</p>
<?php else: ?>
    <p class="muted small">Disconnect a lost laptop, or someone who has left. A browser stops working by itself when its owner is no longer a member.</p>
    <table style="width:100%;border-collapse:collapse;font-size:14px">
<?php foreach ($devicesAll as $d): ?>
      <tr style="border-top:1px solid var(--line)">
        <td style="padding:8px 8px 8px 0"><b><?= e((string) ($d['user_name'] ?: $d['user_email'])) ?></b><br><span class="muted small"><?= e((string) $d['user_email']) ?> · <?= e((string) $d['name']) ?> · last used <?= e((string) $d['last_used']) ?> UTC</span></td>
        <td style="text-align:right"><form method="post"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="action" value="revoke_device"><input type="hidden" name="id" value="<?= (int) $d['id'] ?>"><button class="btn btn--line" style="padding:7px 14px;font-size:13px">Disconnect</button></form></td>
      </tr>
<?php endforeach; ?>
    </table>
<?php endif; ?>
  </div>

  <div class="card">
    <h2>Transcription</h2>
<?php if ($transcribes): ?>
    <p class="ok">A transcription key is set — recordings become minutes.</p>
<?php else: ?>
    <p class="warn">No transcription key yet. Recordings still upload, and attendees can listen to them from the meeting in the portal (🎧 Listen) and write the minutes by hand. Add a key in Studio → Setup → AI (OpenAI for Whisper, or Gemini) and every waiting recording is transcribed by itself, oldest first.</p>
<?php endif; ?>
<?php if ($backlog['waiting'] > 0 || $backlog['bytes'] > 0): ?>
    <dl style="margin-top:12px">
<?php if ($backlog['waiting'] > 0): ?>
      <dt><?= $transcribes ? 'Being transcribed' : 'Waiting for a transcriber' ?></dt><dd><?= (int) $backlog['waiting'] ?> recording<?= $backlog['waiting'] === 1 ? '' : 's' ?><?= $backlog['oldest'] !== '' ? ', the oldest from ' . e(date('j M Y', strtotime($backlog['oldest'] . ' UTC') ?: time())) : '' ?></dd>
<?php endif; ?>
      <dt>Audio kept on this host</dt><dd><?= e($mb((int) $backlog['bytes'])) ?><?= $backlog['free'] !== null ? ' · ' . e($mb((int) $backlog['free'])) . ' free on the disk' : '' ?></dd>
    </dl>
<?php if ($backlog['free'] !== null && $backlog['free'] < 1073741824): ?>
    <p class="warn" style="margin-top:8px">The host has less than 1 GB free. An hour of recording is about 14 MB. Audio waiting for a transcriber is never deleted. Adding a key lets the waiting recordings be transcribed, after which the 30-day clean-up frees the space.</p>
<?php endif; ?>
<?php if (!$transcribes): ?>
    <p class="muted small" style="margin-top:8px">Audio waiting for a transcriber is kept until it is transcribed; the 30-day clean-up starts only after the minutes are written.</p>
<?php endif; ?>
<?php endif; ?>
    <p class="muted small">Recordings are transcribed by the site’s scheduled job (cPanel → Cron Jobs). The full guide is <code>docs/meeting-recorder-RUNBOOK.md</code>.</p>
  </div>
</main>
</body>
</html>
