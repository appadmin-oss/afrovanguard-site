<?php
/**
 * academy/ngv/courses.php — the course list, for staff.
 *
 * The other half of the learning record. Most of these courses are somebody
 * else's — freeCodeCamp, Google, a tutor at the centre — so a course here is
 * a NAME, a PROVIDER and a LINK, not a syllabus this site owns. The Academy's
 * own courses are a different thing and live in the Studio.
 *
 * The list is a MENU with a required start, and the screen says so:
 * Foundation first, because every vanguard is asked for it, then the track
 * groups, which somebody CHOOSES from with their track in mind. It is not a
 * syllabus of eighteen compulsory courses, and a screen that reads like one
 * tells an Art-track vanguard they are fourteen behind. Retire rather than
 * delete, so anybody who has taken a course keeps it.
 *
 * Admin-gated exactly like books.php; JSON actions POST to this same URL,
 * guarded by admin + same-origin + CSRF, and a cover arrives as multipart.
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
    /* Multipart first — php://input is empty on one, so reading it before
       the upload would drop the cover on the floor. */
    if ((string) ($_POST['action'] ?? '') === 'cover') {
        json_out(NgvCourses::setCourseCover((int) ($_POST['id'] ?? 0), $_FILES['cover'] ?? null, $by,
                                            (string) ($_POST['remove'] ?? '') === '1'));
    }
    $in  = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($in)) $in = [];
    $act = (string) ($in['action'] ?? '');
    if ($act === 'save')   json_out(NgvCourses::saveCourse($in, $by));
    if ($act === 'active') json_out(NgvCourses::setCourseActive((int) ($in['id'] ?? 0), !empty($in['active'])));
    if ($act === 'target') json_out(NgvCourses::setTargetNgv((int) ($in['target'] ?? 0), (string) $by));
    json_out(['ok' => false, 'error' => 'Unknown action.'], 400);
}

$e       = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
$groups  = $isAdmin ? NgvCourses::byGroup(false) : [];
$all     = $isAdmin ? NgvCourses::courses(false) : [];
$live    = array_values(array_filter($all, static fn($c) => $c['active']));
$target  = NgvCourses::targetNgv();
$csrf    = av_csrf_token();
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Course list · NGV staff</title>
  <link href="<?= e(av_asset('/assets/site/fonts.css')) ?>" rel="stylesheet" />
<?php require __DIR__ . '/_staff-style.php'; ?>
<style>
.off td{color:var(--muted)}
.s-on{background:var(--green-bg);color:var(--green)}.s-off{background:#eef0f3;color:var(--muted)}
#c_title{min-width:15rem}
.grp-h{font-weight:800;font-size:.82rem;letter-spacing:.04em;text-transform:uppercase;color:var(--muted);padding:.9rem .3rem .3rem}
.grp-h .n{font-weight:600;text-transform:none;letter-spacing:0}
.c-prov{color:var(--muted);font-size:.82rem}
.c-link{font-size:.8rem;word-break:break-all}
</style>
</head>
<body>
<?php if (!$isAdmin): ?>
  <div class="gatebox">
    <h1>Admin sign-in required</h1>
    <p>This page holds the NextGen Vanguard course list. Sign in to the Academy Studio, then come back.</p>
    <p><a href="/academy/studio/">Sign in at the Academy Studio →</a></p>
  </div>
<?php else: ?>
<header class="top">
  <h1><b>NextGen Vanguard</b> <span>· course list</span></h1>
  <span class="sp"></span>
  <a href="/academy/ngv/members.php">Vanguards</a>
  <a href="/academy/ngv/attendance.php">Attendance</a>
  <a href="/academy/ngv/fines.php">Fines</a>
  <a href="/academy/ngv/reading-review.php">Reading review</a>
  <a href="/academy/ngv/books.php">Book list</a>
  <a href="/academy/ngv/courses.php" aria-current="page">Course list</a>
  <span class="msg" id="msg" role="status" aria-live="polite"></span>
</header>
<main class="wrap">
  <section class="stats" aria-label="The list at a glance">
    <div class="stat"><div class="k">On the list</div><div class="v"><?= count($live) ?></div><div class="s">vanguards choose from these</div></div>
    <div class="stat"><div class="k">A vanguard's year</div><div class="v"><?= (int) $target ?></div><div class="s">courses, alongside the 24 books</div></div>
    <div class="stat"><div class="k">Groups</div><div class="v"><?= count(NgvCourses::GROUPS) ?></div><div class="s">Foundation is asked for; the tracks are chosen</div></div>
  </section>

  <section class="card" aria-labelledby="h-add" id="add">
    <header><h2 id="h-add">Add a course</h2><span class="sp"></span><span class="sub">vanguards can only record courses on this list</span></header>
    <div class="body">
      <p class="sub" style="margin-top:0">Most of these are <b>external</b> — freeCodeCamp, Google, a tutor at the centre. The link is how somebody finds it, and a course taught here needs none. Somebody who records a course logs the time they put in, a note per module if the course has them, and a reflection on the whole thing. A certificate can be attached and is never what it rests on.</p>
      <form class="row" id="c_form">
        <input type="hidden" id="c_id" value="">
        <label>Course <input id="c_title" maxlength="200" required placeholder="Responsive Web Design"></label>
        <label>Provider <input id="c_provider" maxlength="120" placeholder="freeCodeCamp (optional)"></label>
        <label>Group <select id="c_grp">
          <?php foreach (NgvCourses::GROUPS as $gk => $gl): ?><option value="<?= $e($gk) ?>"><?= $e($gl) ?></option><?php endforeach; ?>
        </select></label>
        <label>Modules <input id="c_modules" type="number" min="0" max="<?= (int) NgvCourses::MAX_MODULES ?>" inputmode="numeric" size="5" placeholder="0"></label>
        <label>Hours <input id="c_hours" type="number" min="0" max="2000" inputmode="numeric" size="5" placeholder="0"></label>
        <label style="flex:1;min-width:16rem">Link <input id="c_url" maxlength="300" placeholder="https://… (optional)"></label>
        <label style="flex:1;min-width:14rem">Note for vanguards <input id="c_note" maxlength="300" placeholder="Which track it suits, what to do first (optional)"></label>
        <button class="btn primary" id="c_go" type="submit">Add to the list</button>
        <button class="btn" id="c_cancel" type="button" hidden>Cancel editing</button>
      </form>
      <p class="sub" id="c_out" role="status"></p>
    </div>
  </section>

  <section class="card" aria-labelledby="h-target">
    <header><h2 id="h-target">A vanguard's year</h2></header>
    <div class="body">
      <p class="sub" style="margin-top:0">How many courses a NextGen Vanguard is asked for in a year, alongside the twenty-four books. <b>Members and volunteers have no target and no clock</b> — they log what they do, and may set themselves a number from their own page. That asymmetry is deliberate: a vanguard is on a programme with a shape, and somebody doing this alongside a job is not.</p>
      <form class="row" id="t_form">
        <label>Courses a year <input id="t_n" type="number" min="1" max="<?= (int) NgvCourses::TARGET_MAX ?>" value="<?= (int) $target ?>" inputmode="numeric" size="5" required></label>
        <button class="btn primary" type="submit">Save the target</button>
      </form>
    </div>
  </section>

  <section class="card" aria-labelledby="h-list" id="list">
    <header><h2 id="h-list">The progression</h2><span class="sp"></span><span class="sub"><?= count($all) ?> course<?= count($all) === 1 ? '' : 's' ?><?= count($all) > count($live) ? ' · ' . (count($all) - count($live)) . ' retired' : '' ?></span></header>
    <div class="body scroll">
      <?php if (!$all): ?>
        <p class="sub">No courses yet. Until there are, vanguards have nothing to choose from — add the first above.</p>
      <?php else: ?>
      <?php foreach ($groups as $gk => $g): if (!$g['courses']) continue; ?>
        <p class="grp-h"><?= $e($g['label']) ?> <span class="n">· <?= count($g['courses']) ?> · <?= $g['required'] ? 'every vanguard does these' : 'chosen, with a track in mind' ?></span></p>
        <table>
          <thead><tr><th>Cover</th><th>Course</th><th>Where it is</th><th class="num">Modules</th><th class="num">Hours</th><th class="num">Taken</th><th>On the list</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($g['courses'] as $c): $use = NgvCourses::courseUse((int) $c['id']); ?>
            <tr class="<?= $c['active'] ? '' : 'off' ?>">
              <td class="bkcov-cell">
                <?php if ($c['cover_url'] !== ''): ?>
                  <img class="bkcov" src="<?= $e($c['cover_url']) ?>" alt="" loading="lazy" width="44" height="62">
                <?php else: ?>
                  <span class="bkcov bkcov--none" aria-hidden="true"><?= $e(mb_strtoupper(mb_substr((string) $c['title'], 0, 1))) ?></span>
                <?php endif; ?>
                <label class="bkcov-pick">
                  <input type="file" class="c_cover" data-course="<?= (int) $c['id'] ?>" accept="image/jpeg,image/png,image/webp,image/gif" hidden>
                  <span class="btn sm"><?= $c['cover_url'] !== '' ? 'Replace' : 'Add cover' ?></span>
                </label>
                <?php if ($c['cover_url'] !== ''): ?>
                  <button class="btn sm" type="button" data-coverclear="<?= (int) $c['id'] ?>">Remove</button>
                <?php endif; ?>
              </td>
              <td><strong><?= $e((string) $c['title']) ?></strong><?= (string) $c['note'] !== '' ? '<div class="sub">' . $e((string) $c['note']) . '</div>' : '' ?></td>
              <td>
                <?= (string) $c['provider'] !== '' ? '<span class="c-prov">' . $e((string) $c['provider']) . '</span>' : '<span class="sub">at the centre</span>' ?>
                <?php if ((string) $c['url'] !== ''): ?><div class="c-link"><a href="<?= $e((string) $c['url']) ?>" target="_blank" rel="noopener noreferrer"><?= $e((string) $c['url']) ?></a></div><?php endif; ?>
              </td>
              <td class="num"><?= (int) $c['modules'] ?: '—' ?></td>
              <td class="num"><?= (int) $c['hours'] ?: '—' ?></td>
              <td class="num"><?= $use ?></td>
              <td><span class="pill <?= $c['active'] ? 's-on' : 's-off' ?>"><?= $c['active'] ? 'Yes' : 'Retired' ?></span></td>
              <td style="white-space:nowrap">
                <button class="btn sm" type="button" data-edit="<?= (int) $c['id'] ?>"
                        data-title="<?= $e((string) $c['title']) ?>" data-provider="<?= $e((string) $c['provider']) ?>"
                        data-url="<?= $e((string) $c['url']) ?>" data-grp="<?= $e((string) $c['grp']) ?>"
                        data-modules="<?= (int) $c['modules'] ?>" data-hours="<?= (int) $c['hours'] ?>"
                        data-note="<?= $e((string) $c['note']) ?>">Edit course</button>
                <button class="btn sm" type="button" data-active="<?= (int) $c['id'] ?>" data-to="<?= $c['active'] ? '0' : '1' ?>"><?= $c['active'] ? 'Retire from the list' : 'Put back on the list' ?></button>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      <?php endforeach; ?>
      <p class="sub" style="margin-bottom:0"><b>Retire</b> takes a course off the list for new records; anybody who already took it keeps it. <b>Foundation</b> is the part every vanguard is asked for; Business, Art, Media and Tech are a menu they choose from. The groups are that distinction, not a filing system.</p>
      <?php endif; ?>
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
    $('c_id').value = ''; $('c_form').reset();
    $('c_go').textContent = 'Add to the list'; $('c_cancel').hidden = true;
    $('h-add').textContent = 'Add a course';
  }
  $('c_form').addEventListener('submit', function (ev) {
    ev.preventDefault();
    var btn = $('c_go'); btn.disabled = true;
    post({ action: 'save', id: +$('c_id').value || 0, title: $('c_title').value, provider: $('c_provider').value,
           url: $('c_url').value, grp: $('c_grp').value, modules: +$('c_modules').value || 0,
           hours: +$('c_hours').value || 0, note: $('c_note').value }).then(function (r) {
      btn.disabled = false;
      if (!r.ok) { $('c_out').textContent = r.error || 'Not saved.'; return; }
      toast('Saved', true); location.reload();
    });
  });
  $('c_cancel').addEventListener('click', reset);
  $('t_form').addEventListener('submit', function (ev) {
    ev.preventDefault();
    post({ action: 'target', target: +$('t_n').value || 0 }).then(function (r) {
      toast(r.ok ? 'A vanguard\'s year is ' + r.target + ' courses' : (r.error || 'Not saved.'), !!r.ok);
    });
  });
  document.addEventListener('click', function (ev) {
    var ed = ev.target.closest ? ev.target.closest('[data-edit]') : null;
    var ac = ev.target.closest ? ev.target.closest('[data-active]') : null;
    var cc = ev.target.closest ? ev.target.closest('[data-coverclear]') : null;
    if (ed) {
      $('c_id').value = ed.getAttribute('data-edit');
      $('c_title').value = ed.getAttribute('data-title') || '';
      $('c_provider').value = ed.getAttribute('data-provider') || '';
      $('c_url').value = ed.getAttribute('data-url') || '';
      $('c_grp').value = ed.getAttribute('data-grp') || 'foundation';
      $('c_modules').value = ed.getAttribute('data-modules') || '';
      $('c_hours').value = ed.getAttribute('data-hours') || '';
      $('c_note').value = ed.getAttribute('data-note') || '';
      $('c_go').textContent = 'Save the course'; $('c_cancel').hidden = false;
      $('h-add').textContent = 'Edit a course';
      $('add').scrollIntoView({ block: 'start' }); $('c_title').focus();
    } else if (ac) {
      post({ action: 'active', id: +ac.getAttribute('data-active'), active: ac.getAttribute('data-to') === '1' })
        .then(function (r) { if (!r.ok) { toast(r.error || 'Could not.', false); return; } location.reload(); });
    } else if (cc) {
      if (!confirm('Remove this cover? The course stays on the list.')) return;
      var fd = new FormData(); fd.append('action', 'cover'); fd.append('id', cc.getAttribute('data-coverclear')); fd.append('remove', '1');
      coverSend(fd, 'Cover removed');
    }
  });
  function coverSend(fd, said) {
    return fetch(location.pathname, { method: 'POST', credentials: 'same-origin', headers: { 'X-CSRF-Token': CSRF }, body: fd })
      .then(function (r) { return r.json().catch(function () { return { ok: false, error: 'Unreadable reply.' }; }); })
      .then(function (r) { if (!r.ok) { toast(r.error || 'The cover was not saved.', false); return; } toast(said, true); location.reload(); })
      .catch(function () { toast('No connection.', false); });
  }
  document.addEventListener('change', function (ev) {
    var inp = ev.target.closest ? ev.target.closest('.c_cover') : null;
    if (!inp || !inp.files || !inp.files[0]) return;
    var fd = new FormData();
    fd.append('action', 'cover'); fd.append('id', inp.getAttribute('data-course')); fd.append('cover', inp.files[0]);
    toast('Uploading the cover…', true);
    coverSend(fd, 'Cover saved');
  });
})();
</script>
<?php endif; ?>
</body>
</html>
