<?php
/**
 * portal/recorder.php — connect the Meeting Recorder extension to your account.
 *
 *   GET                      the page: connect this browser, see and disconnect your devices
 *   POST ?action=pair        → { ok, code }   a single-use code the extension exchanges for its token
 *   POST ?action=revoke {id} → { ok }
 *
 * The page hands the code to the extension with window.postMessage; the
 * extension's content script for this page (extensions/meeting-recorder/
 * content-portal.js) picks it up. The code is never shown, typed or emailed.
 * Writes are same-origin + CSRF + rate-limited, like the rest of the portal.
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_once AV_ROOT . '/lib/partials.php';   /* av_login_url(): the sign-in redirect for a signed-out visitor */

$method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');
$action = (string) ($_GET['action'] ?? '');
$u = LmsAuth::user();

/* Who may record: members by default (the extension is force-installed for
   staff); AV_REC_AUDIENCE=all opens it to every signed-in account. */
$allowed = static function (?array $u): bool {
    if (!$u) return false;
    return strtolower((string) getenv('AV_REC_AUDIENCE')) === 'all' || LmsAuth::isOrgMember($u);
};

if ($method === 'POST') {
    if (!$u) json_out(['ok' => false, 'error' => 'Please sign in.'], 401);
    if (!$allowed($u)) json_out(['ok' => false, 'error' => 'The Meeting Recorder is for Afrovanguard members.'], 403);
    $uid = (int) $u['id'];
    av_require_write($uid, 'recorder', 20);
    $body = json_decode((string) file_get_contents('php://input'), true) ?: [];
    if ($action === 'pair') json_out(['ok' => true, 'code' => Recorder::createPairing($uid)]);
    if ($action === 'revoke') json_out(['ok' => Recorder::revoke($uid, (int) ($body['id'] ?? 0))]);
    json_out(['ok' => false, 'error' => 'Unknown action.'], 400);
}

if (!$u) { header('Location: ' . av_login_url('/portal/recorder.php')); exit; }
$devices = $allowed($u) ? Recorder::devices((int) $u['id']) : [];
$when = static fn(string $t): string => $t === '' ? '—' : date('j M Y, H:i', strtotime($t . ' UTC') ?: time());
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Meeting Recorder · Afrovanguard</title>
<style>
  :root { --ink:#15120e; --muted:#5d6470; --line:#e7e9ee; --bg:#f5f6f8; --card:#fff; --brand:#e4162b; --ok:#137a3a; }
  @media (prefers-color-scheme: dark) { :root { --ink:#f1efe9; --muted:#a8adb7; --line:#2b2f36; --bg:#111317; --card:#1a1d22; --ok:#4fc27a; } }
  * { box-sizing:border-box }
  body { margin:0; font-family:system-ui,-apple-system,"Segoe UI",sans-serif; background:var(--bg); color:var(--ink); line-height:1.5 }
  main { max-width:640px; margin:0 auto; padding:32px 16px 64px }
  h1 { font-size:26px; margin:0 0 4px } h2 { font-size:17px; margin:28px 0 10px }
  p { margin:0 0 12px } .muted { color:var(--muted) }
  .card { background:var(--card); border:1px solid var(--line); border-radius:16px; padding:20px; margin-top:18px }
  .btn { display:inline-flex; align-items:center; gap:8px; border:0; border-radius:999px; padding:11px 20px; font:600 15px/1 inherit; background:var(--brand); color:#fff; cursor:pointer }
  .btn[disabled] { opacity:.55; cursor:default }
  .btn--line { background:transparent; color:var(--ink); border:1px solid var(--line); padding:7px 14px; font-size:13px }
  .state { margin-top:12px; font-weight:600 } .state.ok { color:var(--ok) } .state.err { color:var(--brand) }
  ul.devices { list-style:none; margin:0; padding:0 } ul.devices li { display:flex; justify-content:space-between; align-items:center; gap:12px; padding:12px 0; border-top:1px solid var(--line) }
  ul.devices li:first-child { border-top:0 } .small { font-size:13px } a { color:var(--brand) }
  ol { padding-left:20px; margin:0 } ol li { margin-bottom:6px }
</style>
</head>
<body>
<main id="avrec" data-csrf="<?= e(av_csrf_token()) ?>">
  <p class="small"><a href="/portal/#meetings">← Meetings</a></p>
  <h1>Meeting Recorder</h1>
  <p class="muted">Record a meeting from your browser. When it ends, the minutes, decisions and action items appear on the meeting in the portal, the same as when the notetaker joins.</p>

<?php if (!$allowed($u)): ?>
  <div class="card"><p>The Meeting Recorder is for Afrovanguard members. If you are one, ask the office to verify your membership.</p></div>
<?php else: ?>
  <div class="card">
    <h2 style="margin-top:0">Connect this browser</h2>
    <p id="avrec-missing" class="muted" hidden>The recorder extension is not installed in this browser. On an Afrovanguard work account it is installed for you within a few minutes of signing in to Chrome; otherwise ask the office.</p>
    <button type="button" class="btn" id="avrec-connect" disabled>Connect the recorder</button>
    <p class="state" id="avrec-state" role="status" aria-live="polite"></p>
  </div>

  <div class="card">
    <h2 style="margin-top:0">How to record</h2>
    <ol>
      <li>Join the meeting in Chrome (Google Meet works best).</li>
      <li>Tell everyone in the call that you are recording, and give anyone who objects the chance to say so. The recorder does <b>not</b> tell them for you: its reminder appears only on your screen.</li>
      <li>Click the recorder's icon, or press <b>Alt+Shift+R</b>. On the banner, click <b>I've told everyone</b> — that is recorded with the meeting.</li>
      <li>Leave the call, or press the shortcut again, to stop. The minutes follow within a few minutes.</li>
    </ol>
  </div>

  <div class="card">
    <h2 style="margin-top:0">Your connected browsers</h2>
<?php if (!$devices): ?>
    <p class="muted" id="avrec-none">None yet.</p>
<?php else: ?>
    <ul class="devices">
<?php foreach ($devices as $d): ?>
      <li><span><b><?= e((string) $d['name']) ?></b><br><span class="muted small">Connected <?= e($when((string) $d['created_at'])) ?> · last used <?= e($when((string) $d['last_used'])) ?></span></span>
        <button type="button" class="btn btn--line" data-revoke="<?= (int) $d['id'] ?>">Disconnect</button></li>
<?php endforeach; ?>
    </ul>
<?php endif; ?>
  </div>
<?php endif; ?>
</main>
<script src="/portal/recorder.js" defer></script>
</body>
</html>
