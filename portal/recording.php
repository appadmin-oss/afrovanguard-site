<?php
/**
 * portal/recording.php — listen to a meeting's recordings (Meeting Recorder).
 *
 *   GET ?meeting=N                 the page: each recording, its marked moments, who talked how much
 *   GET ?action=audio&part=N       one part's audio, with byte ranges so the player can seek
 *   GET ?action=zip&rec=N          every part of one recording, as a zip
 *   GET ?action=transcript&rec=N   the whole transcript, as text (the meeting page keeps the first hour)
 *
 * Works with no AI transcriber at all: this is how a person writes the
 * minutes by hand. Only the meeting's participants (and those who may manage
 * it) may listen — Recorder::mayListen, stricter than reading the minutes.
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_once AV_ROOT . '/lib/partials.php';   /* av_login_url(): the sign-in redirect for a signed-out visitor */

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
$u = LmsAuth::user();
$action = (string) ($_GET['action'] ?? '');
$deny = static function (int $code, string $msg): void {
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    echo $msg . "\n";
    exit;
};
if (!$u) {
    if ($action !== '') $deny(401, 'Please sign in.');
    header('Location: ' . av_login_url((string) ($_SERVER['REQUEST_URI'] ?? '/portal/'))); exit;
}
$uid = (int) $u['id'];

/* ── one part's audio, seekable ───────────────────────────────────── */
if ($action === 'audio') {
    $f = Recorder::segmentFile((int) ($_GET['part'] ?? 0));
    if (!$f || !Recorder::mayListen($uid, (int) $f['meeting_id'])) $deny(404, 'Not found.');
    $path = (string) $f['path'];
    $size = (int) filesize($path);
    $mime = in_array((string) $f['mime'], ['audio/webm', 'audio/ogg', 'audio/mp4'], true) ? (string) $f['mime'] : 'audio/webm';
    $start = 0; $end = $size - 1;
    header('Accept-Ranges: bytes');
    header('Cache-Control: private, no-store');
    header('Content-Type: ' . $mime);
    if (preg_match('/^bytes=(\d*)-(\d*)$/', (string) ($_SERVER['HTTP_RANGE'] ?? ''), $m)) {
        if ($m[1] === '' && $m[2] !== '') { $start = max(0, $size - (int) $m[2]); }
        else { $start = (int) $m[1]; if ($m[2] !== '') $end = min($end, (int) $m[2]); }
        if ($start > $end || $start >= $size) { header('Content-Range: bytes */' . $size); http_response_code(416); exit; }
        http_response_code(206);
        header("Content-Range: bytes $start-$end/$size");
    }
    header('Content-Length: ' . ($end - $start + 1));
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD') exit;
    $fh = fopen($path, 'rb');
    fseek($fh, $start);
    $left = $end - $start + 1;
    while ($left > 0 && !feof($fh)) { $buf = fread($fh, (int) min(65536, $left)); echo $buf; $left -= strlen($buf); flush(); }
    fclose($fh);
    exit;
}

/* ── a whole recording, zipped ────────────────────────────────────── */
if ($action === 'zip') {
    $rid = (int) ($_GET['rec'] ?? 0);
    $st = Database::pdo()->prepare("SELECT meeting_id, started_at FROM recordings WHERE id = ? AND status <> 'cancelled'");
    $st->execute([$rid]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r || !Recorder::mayListen($uid, (int) $r['meeting_id'])) $deny(404, 'Not found.');
    if (!av_rate_ok('rec_zip_' . $uid, 10, 600)) $deny(429, 'Slow down a moment.');
    $files = Database::pdo()->prepare("SELECT seg_no, path FROM recording_segments WHERE recording_id = ? AND path <> '' ORDER BY seg_no");
    $files->execute([$rid]);
    $tmp = tempnam(sys_get_temp_dir(), 'avrz');
    $zip = new ZipArchive();
    if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) $deny(500, 'Could not make the zip.');
    $n = 0;
    foreach ($files->fetchAll(PDO::FETCH_ASSOC) ?: [] as $p) {
        if (!is_file((string) $p['path'])) continue;
        $zip->addFile((string) $p['path'], sprintf('part-%02d.webm', (int) $p['seg_no'] + 1));
        $zip->setCompressionName(sprintf('part-%02d.webm', (int) $p['seg_no'] + 1), ZipArchive::CM_STORE);   // already compressed audio
        $n++;
    }
    $zip->addFromString('README.txt', "A meeting recorded with the Afrovanguard Meeting Recorder.\nPlay the parts in order: each is up to ten minutes, in WebM (Opus) audio.\nChrome, Edge, Firefox and VLC play them.\n");
    $zip->close();
    if ($n === 0) { @unlink($tmp); $deny(404, 'The audio for this recording is no longer kept.'); }
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="meeting-recording-' . $rid . '-' . preg_replace('/[^0-9-]/', '', substr((string) $r['started_at'], 0, 10)) . '.zip"');
    header('Content-Length: ' . filesize($tmp));
    header('Cache-Control: private, no-store');
    readfile($tmp);
    @unlink($tmp);
    exit;
}
/* ── the whole transcript, as text ────────────────────────────────── */
if ($action === 'transcript') {
    $rid = (int) ($_GET['rec'] ?? 0);
    $st = Database::pdo()->prepare("SELECT meeting_id, started_at, transcript FROM recordings WHERE id = ? AND status <> 'cancelled'");
    $st->execute([$rid]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r || !Recorder::mayListen($uid, (int) $r['meeting_id']) || trim((string) $r['transcript']) === '') $deny(404, 'Not found.');
    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: attachment; filename="meeting-transcript-' . $rid . '-' . preg_replace('/[^0-9-]/', '', substr((string) $r['started_at'], 0, 10)) . '.txt"');
    header('Cache-Control: private, no-store');
    echo (string) $r['transcript'];
    exit;
}
if ($action !== '') $deny(400, 'Unknown action.');

/* ── the page ─────────────────────────────────────────────────────── */
$mid = (int) ($_GET['meeting'] ?? 0);
$st = Database::pdo()->prepare('SELECT id, title, scheduled_at FROM meetings WHERE id = ?');
$st->execute([$mid]);
$meeting = $st->fetch(PDO::FETCH_ASSOC);
if (!$meeting || !Recorder::mayListen($uid, $mid)) $deny(404, 'There is no recording here that you can listen to.');
$recs = Recorder::forMeeting($mid);
$clock = static function (int $s): string { return sprintf($s >= 3600 ? '%d:%02d:%02d' : '%2$d:%3$02d', intdiv($s, 3600), intdiv($s % 3600, 60), $s % 60); };
$mins = static function (int $s): string { return $s < 60 ? $s . ' s' : round($s / 60) . ' min'; };
$when = static fn(string $t): string => $t === '' ? '' : date('j M Y, H:i', strtotime($t . ' UTC') ?: time());
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: private, no-store');
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Meeting recording · Afrovanguard</title><!-- never the meeting's name: a page title is what analytics reports, and these meetings can be private -->
<style>
  :root { --ink:#15120e; --muted:#5d6470; --line:#e7e9ee; --bg:#f5f6f8; --card:#fff; --brand:#e4162b; --me:#1f6feb; --them:#8a5cf6; --warn:#9a5b00; }
  @media (prefers-color-scheme: dark) { :root { --ink:#f1efe9; --muted:#a8adb7; --line:#2b2f36; --bg:#111317; --card:#1a1d22; --me:#58a6ff; --them:#b392f0; --warn:#e0a640; } }
  * { box-sizing:border-box }
  body { margin:0; font-family:system-ui,-apple-system,"Segoe UI",sans-serif; background:var(--bg); color:var(--ink); line-height:1.5 }
  main { max-width:720px; margin:0 auto; padding:28px 16px 64px }
  h1 { font-size:24px; margin:0 0 2px } h2 { font-size:16px; margin:0 0 4px }
  p { margin:0 0 10px } .muted { color:var(--muted) } .small { font-size:13px }
  a { color:var(--brand) }
  .card { background:var(--card); border:1px solid var(--line); border-radius:16px; padding:18px; margin-top:16px }
  audio { width:100%; margin:10px 0 6px }
  .now { font-size:13px; color:var(--muted) }
  .note { border-radius:10px; padding:10px 12px; background:color-mix(in srgb, var(--warn) 12%, transparent); color:var(--warn); font-size:14px; margin:10px 0 }
  .talk { display:flex; height:10px; border-radius:5px; overflow:hidden; background:var(--bg); margin:8px 0 4px }
  .talk .me { background:var(--me) } .talk .them { background:var(--them) }
  .legend { display:flex; gap:14px; flex-wrap:wrap; font-size:13px; color:var(--muted) }
  .legend i { display:inline-block; width:10px; height:10px; border-radius:2px; margin-right:6px; vertical-align:-1px }
  .chips { display:flex; flex-wrap:wrap; gap:8px; margin-top:10px }
  .chip { border:1px solid var(--line); background:transparent; color:var(--ink); border-radius:999px; padding:6px 12px; font:500 13px/1 inherit; cursor:pointer }
  .chip:hover, .chip:focus-visible { border-color:var(--brand) }
  .parts { display:flex; flex-wrap:wrap; gap:6px; margin-top:10px }
  .parts .chip.is-on { background:var(--ink); color:var(--bg); border-color:var(--ink) }
  .actions { display:flex; gap:14px; flex-wrap:wrap; margin-top:12px; font-size:14px }
</style>
</head>
<body>
<main>
  <p class="small"><a href="/portal/#meetings">← Meetings</a></p>
  <h1><?= e((string) $meeting['title']) ?></h1>
  <p class="muted"><?= e($when((string) $meeting['scheduled_at'])) ?> · <?= count($recs) === 1 ? '1 recording' : count($recs) . ' recordings' ?></p>
<?php if (!$recs): ?>
  <div class="card"><p>No recordings of this meeting.</p></div>
<?php endif; ?>
<?php foreach ($recs as $r):
    $total = $r['talk']['me'] + $r['talk']['them'];
    $playable = array_values(array_filter($r['parts'], static fn($p) => $p['here']));
    $themName = $r['talk']['them_name'] !== '' ? $r['talk']['them_name'] : 'Others'; ?>
  <section class="card" data-rec="<?= (int) $r['id'] ?>"
           data-parts="<?= e(json_encode(array_map(static fn($p) => ['id' => $p['id'], 'n' => $p['n'], 'offset' => $p['offset']], $playable))) ?>">
    <h2>Recorded by <?= e($r['by'] ?: 'a member') ?></h2>
    <p class="small" style="margin:0 0 4px"><?= $r['consent'] === 'confirmed'
        ? '✓ ' . e($r['by'] ?: 'The person recording') . ' confirmed they told everyone in the call' . ($r['consent_at'] !== '' ? ' (' . e($when($r['consent_at'])) . ')' : '') . '.'
        : ($r['consent'] === 'reminded' ? 'The person recording was reminded to tell everyone, but did not confirm they did.' : 'There is no record that the others in the call were told.') ?></p>
    <p class="muted small"><?= e($when($r['started_at'])) ?><?= count($r['parts']) ? ' · ' . count($r['parts']) . (count($r['parts']) === 1 ? ' part' : ' parts') . ' of up to 10 minutes' : '' ?></p>
<?php if ($r['waiting']): ?>
    <p class="note">No transcriber is set up on this site yet, so there are no written minutes for this recording. Listen here and write them on the meeting; or ask an admin to add one (Studio → Setup → AI) — it will then be transcribed by itself.</p>
<?php elseif (in_array($r['status'], ['recording', 'finished', 'summarising'], true)): ?>
    <p class="note">The minutes are still being written. You can listen in the meantime.</p>
<?php endif; ?>

<?php if ($playable): ?>
    <audio controls preload="metadata" data-player></audio>
    <p class="now" data-now aria-live="polite"></p>
<?php if (count($playable) > 1): ?>
    <div class="parts" role="group" aria-label="Parts">
<?php foreach ($playable as $p): ?>
      <button type="button" class="chip" data-part="<?= (int) $p['n'] ?>">Part <?= (int) $p['n'] + 1 ?> · <?= e($clock($p['offset'])) ?></button>
<?php endforeach; ?>
    </div>
<?php endif; ?>
<?php elseif ($r['parts']): ?>
    <p class="muted">The audio is no longer kept on this site<?= array_filter($r['parts'], static fn($p) => $p['drive'] !== '') ? ' — it was moved to Google Drive:' : '.' ?></p>
<?php foreach ($r['parts'] as $p): if ($p['drive'] === '') continue; ?>
    <a href="<?= e($p['drive']) ?>" target="_blank" rel="noopener">Part <?= (int) $p['n'] + 1 ?></a>&nbsp;
<?php endforeach; ?>
<?php else: ?>
    <p class="muted">No audio arrived for this recording.</p>
<?php endif; ?>

<?php if ($r['marks']): ?>
    <h2 style="margin-top:16px">Marked as important</h2>
    <div class="chips">
<?php foreach ($r['marks'] as $m): ?>
      <button type="button" class="chip" data-jump="<?= (int) $m ?>" <?= $playable ? '' : 'disabled' ?>>★ <?= e($clock($m)) ?></button>
<?php endforeach; ?>
    </div>
<?php endif; ?>

<?php if ($total > 0): ?>
    <h2 style="margin-top:16px">Who talked</h2>
    <div class="talk" role="img" aria-label="<?= e($r['talk']['me_name'] . ' ' . $mins($r['talk']['me']) . ', ' . $themName . ' ' . $mins($r['talk']['them'])) ?>">
      <span class="me" style="width:<?= round(100 * $r['talk']['me'] / $total, 1) ?>%"></span><span class="them" style="width:<?= round(100 * $r['talk']['them'] / $total, 1) ?>%"></span>
    </div>
    <div class="legend"><span><i style="background:var(--me)"></i><?= e($r['talk']['me_name'] ?: 'The person recording') ?> · <?= e($mins($r['talk']['me'])) ?></span><span><i style="background:var(--them)"></i><?= e($themName) ?> · <?= e($mins($r['talk']['them'])) ?></span></div>
<?php endif; ?>

<?php if ($playable): ?>
    <div class="actions"><a href="/portal/recording.php?action=zip&amp;rec=<?= (int) $r['id'] ?>">Download the recording (zip)</a><?php if ($r['has_transcript']): ?><a href="/portal/recording.php?action=transcript&amp;rec=<?= (int) $r['id'] ?>">Download the whole transcript</a><?php endif; ?></div>
<?php endif; ?>
  </section>
<?php endforeach; ?>
</main>
<script src="/portal/recording.js" defer></script>
</body>
</html>
