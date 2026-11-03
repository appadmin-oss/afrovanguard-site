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
    /* A cover arrives as multipart, so it is handled before php://input is
       read — that stream is empty on a multipart request, and parsing it
       first would drop the upload on the floor. */
    if ((string) ($_POST['action'] ?? '') === 'cover') {
        json_out(NgvReading::setBookCover((int) ($_POST['id'] ?? 0), $_FILES['cover'] ?? null, $by,
                                          (string) ($_POST['remove'] ?? '') === '1'));
    }
    /* The e-version, the same way and for the same reason. A LINK posts here
       too rather than as JSON, so one handler answers both and the screen
       does not have to know which it is sending. */
    if ((string) ($_POST['action'] ?? '') === 'ebook') {
        json_out(NgvReading::setBookEbook((int) ($_POST['id'] ?? 0), $_FILES['ebook'] ?? null, $by,
                                          (string) ($_POST['remove'] ?? '') === '1',
                                          (string) ($_POST['link'] ?? ''), (string) ($_POST['note'] ?? '')));
    }
    $in  = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($in)) $in = [];
    $act = (string) ($in['action'] ?? '');
    if ($act === 'save') json_out(NgvReading::saveBook($in, $by));
    if ($act === 'active') json_out(NgvReading::setBookActive((int) ($in['id'] ?? 0), !empty($in['active'])));
    /* Who checks the reading. The grant is exactly that and nothing else —
       it opens academy/ngv/reading-review.php and no other NGV screen. */
    if ($act === 'reviewer') json_out(NgvReading::setReviewer((int) ($in['member_id'] ?? 0), !empty($in['on']), $by));
    if ($act === 'people') json_out(['ok' => true, 'people' => NgvFines::people((string) ($in['q'] ?? ''))]);
    json_out(['ok' => false, 'error' => 'Unknown action.'], 400);
}

$e     = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
$books = $isAdmin ? NgvReading::books(true) : [];
$revs  = $isAdmin ? NgvReading::reviewers() : [];
$live  = array_values(array_filter($books, static fn($b) => $b['active']));
$csrf  = av_csrf_token();
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Book list · NGV staff</title>
  <link href="<?= e(av_asset('/assets/site/fonts.css')) ?>" rel="stylesheet" />
<?php require __DIR__ . '/_staff-style.php'; ?>
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
    <p><a href="/academy/studio/">Sign in at the Academy Studio →</a></p>
  </div>
<?php else: ?>
<header class="top">
  <h1><b>NextGen Vanguard</b> <span>· book list</span></h1>
  <span class="sp"></span>
  <a href="/academy/ngv/members.php">Vanguards</a>
  <a href="/academy/ngv/attendance.php">Attendance</a>
  <a href="/academy/ngv/fines.php">Fines</a>
  <a href="/academy/ngv/reading-review.php">Reading review</a>
  <a href="/academy/ngv/books.php" aria-current="page">Book list</a>
  <a href="/academy/ngv/courses.php">Course list</a>
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
        <fieldset style="flex:1 1 100%;border:1px solid var(--line);border-radius:10px;padding:.6rem .8rem;margin:0">
          <legend style="font-size:.78rem;font-weight:700;color:var(--muted);padding:0 .3rem">Which of our seven values is it on the list for?</legend>
          <div class="chips">
            <?php foreach (NgvReading::values() as $vk => $vl): ?>
              <label class="check" style="margin-right:.9rem"><input type="checkbox" class="b_val" value="<?= $e($vk) ?>"> <?= $e($vl) ?></label>
            <?php endforeach; ?>
          </div>
        </fieldset>
        <button class="btn primary" id="b_go" type="submit">Add to the list</button>
        <button class="btn" id="b_cancel" type="button" hidden>Cancel editing</button>
      </form>
      <p class="sub" id="b_out" role="status"></p>
    </div>
  </section>

  <section class="card" aria-labelledby="h-list" id="list">
    <header><h2 id="h-list">The list</h2><span class="sp"></span><span class="sub"><?= count($books) ?> book<?= count($books) === 1 ? '' : 's' ?><?= count($books) > count($live) ? ' · ' . (count($books) - count($live)) . ' retired' : '' ?></span></header>
    <div class="body scroll">
      <p class="sub" style="margin-top:0">
        <b>E-version</b> is a copy somebody can open — a PDF or EPUB this office holds, or a link to
        where the book is free and legitimate. Most of the list will have neither, which is fine: a
        book with no e-version is one they borrow or buy. Only upload a copy the organisation has the
        right to share. This server accepts uploads up to <?= $e(NgvReading::sizeWords(NgvReading::uploadLimitBytes())) ?>;
        anything larger goes in as a link.
      </p>
      <?php if (!$books): ?>
        <p class="sub">No books yet. Until there are, vanguards have nothing to choose from — add the first above.</p>
      <?php else: ?>
      <table>
        <thead><tr><th>Cover</th><th>Title</th><th>Author</th><th>Values</th><th class="num">Chapters</th><th>E-version</th><th class="num">Recorded</th><th>On the list</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($books as $b): $use = NgvReading::bookUse((int) $b['id']); ?>
          <tr class="<?= $b['active'] ? '' : 'off' ?>">
            <td class="bkcov-cell">
              <?php if ((string) $b['cover_url'] !== ''): ?>
                <img class="bkcov" src="<?= $e((string) $b['cover_url']) ?>" alt="Cover of <?= $e((string) $b['title']) ?>" loading="lazy" width="44" height="62">
              <?php else: ?>
                <span class="bkcov bkcov--none" aria-hidden="true"><?= $e(mb_strtoupper(mb_substr((string) $b['title'], 0, 1))) ?></span>
              <?php endif; ?>
              <label class="bkcov-pick">
                <input type="file" class="b_cover" data-book="<?= (int) $b['id'] ?>" accept="image/jpeg,image/png,image/webp,image/gif" hidden>
                <span class="btn sm"><?= (string) $b['cover_url'] !== '' ? 'Replace' : 'Add cover' ?></span>
              </label>
              <?php if ((string) $b['cover_url'] !== ''): ?>
                <button class="btn sm" type="button" data-coverclear="<?= (int) $b['id'] ?>">Remove</button>
              <?php endif; ?>
            </td>
            <td><strong><?= $e((string) $b['title']) ?></strong><?= (string) $b['note'] !== '' ? '<div class="sub">' . $e((string) $b['note']) . '</div>' : '' ?></td>
            <td><?= $e((string) $b['author']) ?></td>
            <td><?= $b['value_labels'] ? implode(' · ', array_map($e, $b['value_labels'])) : '<span class="sub">none named</span>' ?></td>
            <td class="num"><?= (int) $b['chapters'] ?></td>
            <?php /* The e-version. A file the office holds, or a link to where
                     it is free — most of the list is neither, and that is
                     fine: a book with no e-version is one somebody borrows. */ ?>
            <td class="bke-cell">
              <?php if ((string) $b['ebook_url'] !== ''): ?>
                <a class="bke-have" href="<?= $e((string) $b['ebook_url']) ?>" target="_blank" rel="noopener noreferrer">
                  <span class="pill s-on"><?= $e((string) $b['ebook_label']) ?></span> open</a>
                <?php if ((string) $b['ebook_note'] !== ''): ?><div class="sub"><?= $e((string) $b['ebook_note']) ?></div><?php endif; ?>
              <?php else: ?>
                <span class="sub">none</span>
              <?php endif; ?>
              <div class="bke-acts">
                <label class="bke-pick">
                  <input type="file" class="b_ebook" data-book="<?= (int) $b['id'] ?>" accept="application/pdf,application/epub+zip,.pdf,.epub" hidden>
                  <span class="btn sm"><?= (string) $b['ebook_url'] !== '' ? 'Replace file' : 'Upload' ?></span>
                </label>
                <button class="btn sm" type="button" data-ebooklink="<?= (int) $b['id'] ?>" data-note="<?= $e((string) $b['ebook_note']) ?>">Link</button>
                <?php if ((string) $b['ebook_url'] !== ''): ?>
                  <button class="btn sm" type="button" data-ebookclear="<?= (int) $b['id'] ?>">Remove</button>
                <?php endif; ?>
              </div>
            </td>
            <td class="num"><?= $use ?></td>
            <td><span class="pill <?= $b['active'] ? 's-on' : 's-off' ?>"><?= $b['active'] ? 'Yes' : 'Retired' ?></span></td>
            <td style="white-space:nowrap">
              <button class="btn sm" type="button" data-edit="<?= (int) $b['id'] ?>" data-title="<?= $e((string) $b['title']) ?>" data-author="<?= $e((string) $b['author']) ?>" data-chapters="<?= (int) $b['chapters'] ?>" data-note="<?= $e((string) $b['note']) ?>" data-values="<?= $e(implode(',', $b['value_list'])) ?>">Edit book</button>
              <button class="btn sm" type="button" data-active="<?= (int) $b['id'] ?>" data-to="<?= $b['active'] ? '0' : '1' ?>"><?= $b['active'] ? 'Retire from the list' : 'Put back on the list' ?></button>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
      <p class="sub" style="margin-bottom:0"><b>Retire</b> takes a book off the list for new claims; anybody who already recorded it keeps it. Changing the chapter count applies to claims started after the change — a summary somebody has begun keeps the chapters it was started with.</p>
    </div>
  </section>

  <?php /* WHAT THE LIST IS FOR. The value tags are curation: a programme can
           see it has four books on Diligence and none on Communal Spirit
           before it hands the list to a cohort. They are not asked of a
           reader and nothing is measured from them — what a book grew in a
           particular person is a judgement for the spoken check, not a
           dropdown. */
  $cov = []; foreach ($books as $b) foreach ($b['value_list'] as $vk) $cov[$vk] = ($cov[$vk] ?? 0) + ($b['active'] ? 1 : 0); ?>
  <section class="card" aria-labelledby="h-cov" id="values">
    <header><h2 id="h-cov">What the list covers</h2><span class="sp"></span>
      <span class="sub"><?= count(array_filter($cov)) ?> of <?= count(NgvReading::values()) ?> values have a book</span></header>
    <div class="body scroll">
      <table>
        <thead><tr><th>Value</th><th>What it is taught as</th><th class="num">Books on the list</th></tr></thead>
        <tbody>
        <?php foreach (NgvReading::values() as $vk => $vl): $n = (int) ($cov[$vk] ?? 0); ?>
          <tr<?= $n === 0 ? ' class="off"' : '' ?>>
            <td><strong><?= $e($vl) ?></strong></td>
            <td class="sub"><?= $e(NgvReading::valueMotto($vk)) ?></td>
            <td class="num"><?= $n ?: '<span class="pill s-off">none</span>' ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <p class="sub" style="margin-bottom:0">A value with no books is one the list cannot speak to. This is for choosing
        what to add next; it is not a measure of anybody's reading.</p>
    </div>
  </section>

  <?php /* Who checks the reading. Every page the participants read says their
           TRACK LEAD checks the book, and until now the only people who could
           were full NGV admins — which is why the work piled up in two or
           three inboxes. This grant is the reading and nothing else. */ ?>
  <section class="card" aria-labelledby="h-rev" id="reviewers">
    <header><h2 id="h-rev">Who checks the reading</h2><span class="sp"></span>
      <span class="sub"><?= count($revs) ?> besides the admins</span></header>
    <div class="body">
      <p class="sub" style="margin-top:0">A track lead added here can open the
        <a href="/academy/ngv/reading-review.php">reading review desk</a>: the claims waiting, the
        rejections that can be reopened, and the conversations that are due. It does not give them the
        roster, the fines desk, the ledger or anything else. Every NGV admin can already do all of it.</p>
      <div class="row">
        <div class="picker"><input id="r_find" placeholder="Search a member by name, email or NGV ID" autocomplete="off" aria-label="Find a member"><div class="found" id="r_found" hidden></div></div>
      </div>
      <?php if ($revs): ?>
      <div class="scroll" style="margin-top:12px">
        <table>
          <thead><tr><th>Who</th><th>Added</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($revs as $r): ?>
            <tr>
              <td><strong><?= $e((string) ($r['name'] ?? '') !== '' ? (string) $r['name'] : 'Member #' . (int) $r['member_id']) ?></strong><?= (string) ($r['email'] ?? '') !== '' ? '<div class="sub">' . $e((string) $r['email']) . '</div>' : '' ?></td>
              <td class="sub"><?= $e(substr((string) $r['created_at'], 0, 10)) ?></td>
              <td style="white-space:nowrap"><button class="btn sm" type="button" data-rev-off="<?= (int) $r['member_id'] ?>">Remove</button></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php else: ?>
        <p class="sub" style="margin-bottom:0">Nobody yet — only NGV admins check the reading, which is how it falls behind.</p>
      <?php endif; ?>
    </div>
  </section>
</main>
<script>
(function () {
  var CSRF = <?= json_encode($csrf) ?>;
  /* What this server will really take, from PHP's own ini — not a number we
     hope is right. */
  var EBOOK_CAP = <?= (int) NgvReading::uploadLimitBytes() ?>;
  var EBOOK_CAP_WORDS = <?= json_encode(NgvReading::sizeWords(NgvReading::uploadLimitBytes())) ?>;
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
    document.querySelectorAll('.b_val').forEach(function (c) { c.checked = false; });
    $('b_go').textContent = 'Add to the list'; $('b_cancel').hidden = true;
    $('h-add').textContent = 'Add a book';
  }
  $('b_form').addEventListener('submit', function (ev) {
    ev.preventDefault();
    var btn = $('b_go'); btn.disabled = true;
    var vals = Array.prototype.slice.call(document.querySelectorAll('.b_val:checked')).map(function (c) { return c.value; });
    post({ action: 'save', id: +$('b_id').value || 0, title: $('b_title').value, author: $('b_author').value,
           chapters: +$('b_chapters').value || 0, note: $('b_note').value, value_keys: vals }).then(function (r) {
      btn.disabled = false;
      if (!r.ok) { $('b_out').textContent = r.error || 'Not saved.'; return; }
      toast('Saved', true); location.reload();
    });
  });
  $('b_cancel').addEventListener('click', reset);

  /* Covers. Multipart, so it does not go through post() — and the file input
     is inside a label, so the whole control is one keyboard-reachable
     button rather than the browser's own "no file chosen" chrome. */
  function coverSend(fd, said) {
    return fetch(location.pathname, { method: 'POST', credentials: 'same-origin',
      headers: { 'X-CSRF-Token': CSRF }, body: fd })
      .then(function (r) { return r.json().catch(function () { return { ok: false, error: 'Unreadable reply.' }; }); })
      .then(function (r) {
        if (!r.ok) { toast(r.error || 'The cover was not saved.', false); return; }
        toast(said, true); location.reload();
      })
      .catch(function () { toast('No connection.', false); });
  }
  document.addEventListener('change', function (ev) {
    var inp = ev.target.closest ? ev.target.closest('.b_cover') : null;
    if (!inp || !inp.files || !inp.files[0]) return;
    var fd = new FormData();
    fd.append('action', 'cover'); fd.append('id', inp.getAttribute('data-book')); fd.append('cover', inp.files[0]);
    toast('Uploading the cover…', true);
    coverSend(fd, 'Cover saved');
  });
  /* The e-version. Same multipart path as the cover, because a link posts
     through it too — one handler, so the two cannot drift into saving
     differently. */
  function ebookSend(fd, said) {
    return fetch(location.pathname, { method: 'POST', credentials: 'same-origin',
      headers: { 'X-CSRF-Token': CSRF }, body: fd })
      .then(function (r) { return r.json().catch(function () { return { ok: false, error: 'Unreadable reply.' }; }); })
      .then(function (r) {
        if (!r.ok) { toast(r.error || 'It was not saved.', false); return; }
        toast(r.said || said, true); location.reload();
      })
      .catch(function () { toast('No connection.', false); });
  }
  document.addEventListener('change', function (ev) {
    var inp = ev.target.closest ? ev.target.closest('.b_ebook') : null;
    if (!inp || !inp.files || !inp.files[0]) return;
    var f = inp.files[0];
    if (f.size > EBOOK_CAP) {
      /* Refused here as well as on the server: a file over the server's
         own limit never reaches our code at all — PHP drops it and $_FILES
         comes back empty — so without this the operator watches a long
         upload finish and then reads "no file arrived". */
      toast('That file is ' + (f.size / 1048576).toFixed(1) + 'MB and this server accepts up to '
            + EBOOK_CAP_WORDS + '. Put it in as a link instead.', false);
      inp.value = '';
      return;
    }
    var fd = new FormData();
    fd.append('action', 'ebook'); fd.append('id', inp.getAttribute('data-book')); fd.append('ebook', f);
    toast('Uploading…', true);
    ebookSend(fd, 'Saved');
  });
  document.addEventListener('click', function (ev) {
    var lk = ev.target.closest ? ev.target.closest('[data-ebooklink]') : null;
    if (!lk) return;
    var url = prompt('Where can this book be read, free and legitimately?\n\nA full https:// address — Project Gutenberg, the publisher\u2019s own page, the author\u2019s site.');
    if (url === null) return;
    url = url.trim();
    if (!url) { toast('No address given.', false); return; }
    var note = prompt('Anything to say about it? (optional)\n\ne.g. "chapters 1-8 only", "the 1994 edition", "free on Gutenberg"',
                      lk.getAttribute('data-note') || '');
    var fd = new FormData();
    fd.append('action', 'ebook'); fd.append('id', lk.getAttribute('data-ebooklink'));
    fd.append('link', url); fd.append('note', note === null ? '' : note);
    ebookSend(fd, 'Link saved');
  });
  document.addEventListener('click', function (ev) {
    var b = ev.target.closest ? ev.target.closest('[data-ebookclear]') : null;
    if (!b) return;
    if (!confirm('Remove this e-version? The book stays on the list.')) return;
    var fd = new FormData();
    fd.append('action', 'ebook'); fd.append('id', b.getAttribute('data-ebookclear')); fd.append('remove', '1');
    ebookSend(fd, 'Removed');
  });

  document.addEventListener('click', function (ev) {
    var b = ev.target.closest ? ev.target.closest('[data-coverclear]') : null;
    if (!b) return;
    if (!confirm('Remove this cover? The book stays on the list.')) return;
    var fd = new FormData();
    fd.append('action', 'cover'); fd.append('id', b.getAttribute('data-coverclear')); fd.append('remove', '1');
    coverSend(fd, 'Cover removed');
  });

  /* Who checks the reading: the same people-search the fines desk uses. */
  (function () {
    var input = $('r_find'), box = $('r_found'), t, seq = 0;
    if (!input) return;
    function esc(x) { return String(x == null ? '' : x).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
    input.addEventListener('input', function () {
      clearTimeout(t); var q = input.value.trim();
      if (q.length < 2) { box.hidden = true; return; }
      t = setTimeout(function () {
        var mine = ++seq;
        post({ action: 'people', q: q }).then(function (r) {
          if (mine !== seq) return;
          var list = (r && r.people) || [];
          box.innerHTML = list.length
            ? list.map(function (p) { return '<button type="button" data-rev-on="' + p.id + '">' + esc(p.name) + '<small>' + esc(p.detail) + '</small></button>'; }).join('')
            : '<div class="sub" style="padding:.5rem .7rem">Nobody by that.</div>';
          box.hidden = false;
        });
      }, 180);
    });
    input.addEventListener('keydown', function (ev) { if (ev.key === 'Escape') box.hidden = true; });
    document.addEventListener('click', function (ev) { if (!box.contains(ev.target) && ev.target !== input) box.hidden = true; });
  })();
  document.addEventListener('click', function (ev) {
    var ed = ev.target.closest('[data-edit]');
    if (ed) {
      $('b_id').value = ed.getAttribute('data-edit');
      $('b_title').value = ed.getAttribute('data-title');
      $('b_author').value = ed.getAttribute('data-author');
      $('b_chapters').value = ed.getAttribute('data-chapters');
      $('b_note').value = ed.getAttribute('data-note');
      var have = (ed.getAttribute('data-values') || '').split(',');
      document.querySelectorAll('.b_val').forEach(function (c) { c.checked = have.indexOf(c.value) >= 0; });
      $('b_go').textContent = 'Save book changes'; $('b_cancel').hidden = false;
      $('h-add').textContent = 'Edit a book';
      $('b_title').focus();
      return;
    }
    var ron = ev.target.closest('[data-rev-on]'), roff = ev.target.closest('[data-rev-off]');
    if (ron || roff) {
      var who = +(ron || roff).getAttribute(ron ? 'data-rev-on' : 'data-rev-off');
      if (roff && !window.confirm('Stop them checking the reading?')) return;
      (ron || roff).disabled = true;
      post({ action: 'reviewer', member_id: who, on: !!ron }).then(function (r) {
        if (!r.ok) { (ron || roff).disabled = false; toast(r.error || 'Not changed.', false); return; }
        location.reload();
      });
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
