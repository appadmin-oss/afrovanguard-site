<?php
/**
 * academy/ngv/books.php — the 24-book challenge's book list, for staff.
 *
 * Admins prepare the books: title, author and how many chapters. Vanguards
 * choose from this list when they record a book (lib/NgvReading.php), and
 * write a summary of each chapter as well as their reflection on the whole
 * book. A book is retired rather than deleted, so the claims already made on
 * it keep their book. Admin-gated exactly like fines.php; JSON actions POST
 * to this same URL, guarded by admin + same-origin + CSRF.
 */
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/lib/bootstrap.php';

$role    = function_exists('av_admin_role') ? av_admin_role() : '';
$isAdmin = in_array($role, ['admin', 'superadmin'], true);
$by      = (int) (LmsAuth::user()['id'] ?? 0);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!$isAdmin) json_out(['ok' => false, 'error' => 'Admin sign-in required.'], 403);
    require_same_origin();
    av_csrf_require();
    $in  = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($in)) $in = [];
    $act = (string) ($in['action'] ?? '');
    if ($act === 'save') json_out(NgvReading::saveBook($in, $by));
    if ($act === 'active') json_out(NgvReading::setBookActive((int) ($in['id'] ?? 0), !empty($in['active'])));
    json_out(['ok' => false, 'error' => 'Unknown action.'], 400);
}

$e     = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
$books = $isAdmin ? NgvReading::books(true) : [];
$live  = array_values(array_filter($books, static fn($b) => $b['active']));
$csrf  = av_csrf_token();
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Book list · NGV staff</title>
  <link href="/assets/site/fonts.css" rel="stylesheet" />
<style>
:root{--red:#e4162b;--orange:#ff6a1a;--gold:#ffb703;--ink:#15120e;--line:#e7e9ee;--muted:#5f6874;--bg:#f5f6f8;--card:#fff;
  --green:#137a3a;--green-bg:#e6f7ec;--amber:#9a5b00;--amber-bg:#fffaf0;--danger:#c0322b;--danger-bg:#fdecec;--blue:#1d4ed8;--blue-bg:#eef4ff;--r:14px}
*{box-sizing:border-box}
body{margin:0;font-family:'Source Sans 3',system-ui,sans-serif;background:var(--bg);color:var(--ink);line-height:1.5}
a{color:var(--red)}
h1,h2,h3{margin:0;font-weight:800;letter-spacing:-.01em}
:focus-visible{outline:3px solid var(--orange);outline-offset:2px}
.top{position:sticky;top:0;z-index:20;background:rgba(21,18,14,.97);color:#fff;display:flex;gap:12px;align-items:center;padding:10px 20px;flex-wrap:wrap}
.top h1{font-size:.95rem}.top h1 b{color:var(--gold)}.top h1 span{font-weight:600;color:rgba(255,255,255,.7)}
.top .sp{flex:1}.top a{color:rgba(255,255,255,.85);text-decoration:none;font-weight:600;font-size:.86rem}
.top a[aria-current]{color:#fff;border-bottom:2px solid var(--gold)}
.msg{font-size:.85rem;font-weight:700}
.wrap{max-width:1180px;margin:22px auto 56px;padding:0 16px;display:flex;flex-direction:column;gap:18px}
.gatebox{max-width:520px;margin:12vh auto;background:#fff;border:1px solid var(--line);border-radius:18px;padding:36px;text-align:center}
.card{background:var(--card);border:1px solid var(--line);border-radius:var(--r);overflow:hidden}
.card>header{padding:13px 18px;border-bottom:1px solid var(--line);display:flex;gap:10px;align-items:center;flex-wrap:wrap}
.card>header h2{font-size:1rem}.card>header .sp{flex:1}
.card>.body{padding:14px 18px 18px}
.stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px}
.stat{background:#fff;border:1px solid var(--line);border-radius:var(--r);padding:12px 16px}
.stat .k{font-size:.75rem;color:var(--muted);font-weight:700;text-transform:uppercase;letter-spacing:.04em}
.stat .v{font-size:1.5rem;font-weight:800;font-variant-numeric:tabular-nums}
.stat .s{font-size:.8rem;color:var(--muted)}
table{width:100%;border-collapse:collapse;font-size:.88rem}
th,td{text-align:left;padding:.45rem .55rem;border-top:1px solid var(--line);vertical-align:top}
th{font-size:.72rem;color:var(--muted);text-transform:uppercase;letter-spacing:.04em;border-top:0;white-space:nowrap}
td.num,th.num{font-variant-numeric:tabular-nums;text-align:right;white-space:nowrap}
.pill{display:inline-block;border-radius:999px;padding:.05rem .55rem;font-size:.76rem;font-weight:700;white-space:nowrap}
.s-owing{background:var(--danger-bg);color:var(--danger)}.s-part{background:var(--amber-bg);color:var(--amber)}
.s-settled{background:var(--green-bg);color:var(--green)}.s-waived{background:var(--blue-bg);color:var(--blue)}.s-voided{background:#eef0f3;color:var(--muted);text-decoration:line-through}
.s-ready,.s-fined{background:var(--green-bg);color:var(--green)}.s-ambiguous{background:var(--amber-bg);color:var(--amber)}
.s-unmatched,.s-invalid{background:var(--danger-bg);color:var(--danger)}.s-already,.s-duplicate{background:#eef0f3;color:var(--muted)}
.btn{font:inherit;font-size:.82rem;font-weight:700;border:1px solid var(--line);background:#fff;border-radius:9px;padding:.4rem .75rem;cursor:pointer;color:var(--ink);text-decoration:none;display:inline-block}
.btn.primary{background:var(--ink);color:#fff;border-color:var(--ink)}
.btn.sm{font-size:.76rem;padding:.2rem .5rem}
.btn:disabled{opacity:.5;cursor:not-allowed}
.row{display:flex;gap:10px;flex-wrap:wrap;align-items:end}
.row label,.lbl{font-size:.78rem;font-weight:700;color:var(--muted);display:flex;flex-direction:column;gap:4px}
input,select,textarea{font:inherit;font-size:.88rem;padding:.42rem .55rem;border:1px solid var(--line);border-radius:9px;background:#fff;color:var(--ink);min-width:0}
textarea{width:100%;min-height:7rem;font-family:ui-monospace,monospace;font-size:.8rem}
.check{flex-direction:row!important;align-items:center;gap:6px!important;font-weight:600!important}
.sub{color:var(--muted);font-size:.84rem}
.note{background:var(--amber-bg);border:1px solid #f2c98a;border-radius:12px;padding:.7rem .9rem;font-size:.9rem;margin:0}
.scroll{overflow-x:auto}
.chips{display:flex;flex-wrap:wrap;gap:6px;min-height:1.8rem}
.chip{background:#f1f2f5;border-radius:999px;padding:.15rem .3rem .15rem .65rem;font-size:.82rem;font-weight:600;display:inline-flex;gap:4px;align-items:center}
.chip button{border:0;background:none;cursor:pointer;font-size:1rem;line-height:1;color:var(--muted)}
.picker{position:relative;flex:1;min-width:16rem}
.picker input{width:100%}
.found{position:absolute;left:0;right:0;top:100%;z-index:5;background:#fff;border:1px solid var(--line);border-radius:10px;box-shadow:0 10px 30px rgba(0,0,0,.08);margin-top:4px;max-height:16rem;overflow:auto}
.found button{display:block;width:100%;text-align:left;border:0;background:none;padding:.45rem .7rem;font:inherit;font-size:.86rem;cursor:pointer}
.found button:hover,.found button:focus{background:#f5f6f8}
.found small{color:var(--muted);display:block}
.grid input,.grid select{width:100%;font-size:.8rem;padding:.25rem .35rem;border-radius:7px}
.grid td{padding:.3rem .3rem;min-width:6.5rem}
.grid td.w{min-width:10rem}
.grid tr.bad td{background:#fff8f7}
.grid tr.warn td{background:#fffdf5}
.why{font-size:.76rem;color:var(--muted);margin-top:3px}
.why.err{color:var(--danger)}
.tabs{display:flex;gap:6px;flex-wrap:wrap}
.tabs a{font-size:.8rem;font-weight:700;padding:.25rem .65rem;border-radius:999px;border:1px solid var(--line);text-decoration:none;color:var(--ink);background:#fff}
.tabs a.on{background:var(--ink);color:#fff;border-color:var(--ink)}
.cat{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:10px}
@media (max-width:640px){.top{padding:10px 16px}.wrap{padding:0 12px}.card>.body{padding:12px}}
</style>
<style>
.off td{color:var(--muted)}
.s-on{background:var(--green-bg);color:var(--green)}.s-off{background:#eef0f3;color:var(--muted)}
#b_title{min-width:16rem}
</style>
</head>
<body>
<?php if (!$isAdmin): ?>
  <div class="gatebox">
    <h1>Admin sign-in required</h1>
    <p>This page holds the NextGen Vanguard book list. Sign in to the Academy Studio, then come back.</p>
    <p><a href="/academy/studio/">Go to the Studio →</a></p>
  </div>
<?php else: ?>
<header class="top">
  <h1><b>NextGen Vanguard</b> <span>· book list</span></h1>
  <span class="sp"></span>
  <a href="/academy/ngv/members.php">Vanguards</a>
  <a href="/academy/ngv/attendance.php">Attendance</a>
  <a href="/academy/ngv/fines.php">Fines</a>
  <a href="/academy/ngv/books.php" aria-current="page">Book list</a>
  <span class="msg" id="msg" role="status" aria-live="polite"></span>
</header>
<main class="wrap">
  <section class="stats" aria-label="The list at a glance">
    <div class="stat"><div class="k">On the list</div><div class="v"><?= count($live) ?></div><div class="s">vanguards choose from these</div></div>
    <div class="stat"><div class="k">Challenge</div><div class="v"><?= (int) NgvReading::TOTAL ?></div><div class="s">books each</div></div>
    <div class="stat"><div class="k">Per chapter</div><div class="v"><?= (int) NgvReading::MIN_CHAPTER ?>+</div><div class="s">characters of summary</div></div>
  </section>

  <section class="card" aria-labelledby="h-add" id="add">
    <header><h2 id="h-add">Add a book</h2><span class="sp"></span><span class="sub">vanguards can only record books on this list</span></header>
    <div class="body">
      <p class="sub" style="margin-top:0">A vanguard who records a book writes a summary of <b>each chapter</b> — at least <?= (int) NgvReading::MIN_CHAPTER ?> characters each — and then a reflection on the <b>whole book</b> and one thing they did because of it. So the chapter count is what decides how much they write: count the chapters of the edition they will read.</p>
      <form class="row" id="b_form">
        <input type="hidden" id="b_id" value="">
        <label>Title <input id="b_title" maxlength="200" required></label>
        <label>Author <input id="b_author" maxlength="120" required></label>
        <label>Chapters <input id="b_chapters" type="number" min="1" max="<?= (int) NgvReading::MAX_CHAPTERS ?>" inputmode="numeric" size="5" required></label>
        <label style="flex:1;min-width:14rem">Note for vanguards <input id="b_note" maxlength="300" placeholder="Edition, where to find it (optional)"></label>
        <button class="btn primary" id="b_go" type="submit">Add to the list</button>
        <button class="btn" id="b_cancel" type="button" hidden>Cancel</button>
      </form>
      <p class="sub" id="b_out" role="status"></p>
    </div>
  </section>

  <section class="card" aria-labelledby="h-list" id="list">
    <header><h2 id="h-list">The list</h2><span class="sp"></span><span class="sub"><?= count($books) ?> book<?= count($books) === 1 ? '' : 's' ?><?= count($books) > count($live) ? ' · ' . (count($books) - count($live)) . ' retired' : '' ?></span></header>
    <div class="body scroll">
      <?php if (!$books): ?>
        <p class="sub">No books yet. Until there are, vanguards have nothing to choose from — add the first above.</p>
      <?php else: ?>
      <table>
        <thead><tr><th>Title</th><th>Author</th><th class="num">Chapters</th><th class="num">Recorded</th><th>On the list</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($books as $b): $use = NgvReading::bookUse((int) $b['id']); ?>
          <tr class="<?= $b['active'] ? '' : 'off' ?>">
            <td><strong><?= $e((string) $b['title']) ?></strong><?= (string) $b['note'] !== '' ? '<div class="sub">' . $e((string) $b['note']) . '</div>' : '' ?></td>
            <td><?= $e((string) $b['author']) ?></td>
            <td class="num"><?= (int) $b['chapters'] ?></td>
            <td class="num"><?= $use ?></td>
            <td><span class="pill <?= $b['active'] ? 's-on' : 's-off' ?>"><?= $b['active'] ? 'Yes' : 'Retired' ?></span></td>
            <td style="white-space:nowrap">
              <button class="btn sm" type="button" data-edit="<?= (int) $b['id'] ?>" data-title="<?= $e((string) $b['title']) ?>" data-author="<?= $e((string) $b['author']) ?>" data-chapters="<?= (int) $b['chapters'] ?>" data-note="<?= $e((string) $b['note']) ?>">Edit</button>
              <button class="btn sm" type="button" data-active="<?= (int) $b['id'] ?>" data-to="<?= $b['active'] ? '0' : '1' ?>"><?= $b['active'] ? 'Retire' : 'Restore' ?></button>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
      <p class="sub" style="margin-bottom:0"><b>Retire</b> takes a book off the list for new claims; anybody who already recorded it keeps it. Changing the chapter count applies to claims started after the change — a summary somebody has begun keeps the chapters it was started with.</p>
    </div>
  </section>
</main>
<script>
(function () {
  var CSRF = <?= json_encode($csrf) ?>;
  var msg = document.getElementById('msg'), mt;
  function $(id) { return document.getElementById(id); }
  function toast(t, good) { msg.textContent = t; msg.style.color = good === false ? '#ffb4ab' : '#9be7b4'; clearTimeout(mt); mt = setTimeout(function () { msg.textContent = ''; }, 3500); }
  function post(body) {
    return fetch(location.pathname, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF }, body: JSON.stringify(body) })
      .then(function (r) { return r.json().catch(function () { return { ok: false, error: 'Unreadable reply.' }; }); })
      .catch(function () { return { ok: false, error: 'No connection.' }; });
  }
  function reset() {
    $('b_id').value = ''; $('b_form').reset();
    $('b_go').textContent = 'Add to the list'; $('b_cancel').hidden = true;
    $('h-add').textContent = 'Add a book';
  }
  $('b_form').addEventListener('submit', function (ev) {
    ev.preventDefault();
    var btn = $('b_go'); btn.disabled = true;
    post({ action: 'save', id: +$('b_id').value || 0, title: $('b_title').value, author: $('b_author').value,
           chapters: +$('b_chapters').value || 0, note: $('b_note').value }).then(function (r) {
      btn.disabled = false;
      if (!r.ok) { $('b_out').textContent = r.error || 'Not saved.'; return; }
      toast('Saved', true); location.reload();
    });
  });
  $('b_cancel').addEventListener('click', reset);
  document.addEventListener('click', function (ev) {
    var ed = ev.target.closest('[data-edit]');
    if (ed) {
      $('b_id').value = ed.getAttribute('data-edit');
      $('b_title').value = ed.getAttribute('data-title');
      $('b_author').value = ed.getAttribute('data-author');
      $('b_chapters').value = ed.getAttribute('data-chapters');
      $('b_note').value = ed.getAttribute('data-note');
      $('b_go').textContent = 'Save changes'; $('b_cancel').hidden = false;
      $('h-add').textContent = 'Edit a book';
      $('b_title').focus();
      return;
    }
    var ac = ev.target.closest('[data-active]');
    if (ac) {
      ac.disabled = true;
      post({ action: 'active', id: +ac.getAttribute('data-active'), active: ac.getAttribute('data-to') === '1' }).then(function (r) {
        if (!r.ok) { ac.disabled = false; toast(r.error || 'Not changed.', false); return; }
        location.reload();
      });
    }
  });
})();
</script>
<?php endif; ?>
</body>
</html>
