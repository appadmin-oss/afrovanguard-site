<?php
/**
 * diary/me.php — the member's Vanguard Diary (composer + personal streams).
 *
 * Signed-in members log three kinds of entry:
 *   📅 Event   — institutional happenings at a CACENTRE hub (backdatable)
 *   🔒 Private — personal reflection, visible only to them
 *   🌐 Public  — submitted to the moderation queue; once approved it joins
 *                the public Diary feed
 *
 * Private + event entries never leave this page. The page is noindex — it's a
 * personal workspace, not public content.
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_once AV_ROOT . '/lib/partials.php';

$user      = LmsAuth::user();
$entries   = $user ? (new DiaryJournal())->mine((int) $user['id']) : [];
$canonical = diary_url('me/');
$today     = date('Y-m-d');

/** Presentation metadata per entry, derived purely for display. */
function vd_badge(array $e): array {
    // [icon, kind label, status label, status css, optional link slug]
    $icon = ['event' => '📅', 'private' => '🔒', 'public' => '🌐'][$e['kind']] ?? '📝';
    $kindLabel = ['event' => 'Event diary', 'private' => 'Private journal', 'public' => 'Public journal'][$e['kind']] ?? 'Entry';
    if ($e['kind'] === 'public') {
        switch ($e['status']) {
            case 'pending':  return [$icon, $kindLabel, 'Pending review', 'is-pending', null];
            case 'approved': return [$icon, $kindLabel, 'Published',      'is-live',    $e['published_slug'] ?? null];
            case 'rejected': return [$icon, $kindLabel, 'Not approved',   'is-rejected', null];
        }
    }
    return [$icon, $kindLabel, 'Logged', 'is-logged', null];
}

render_head([
    'title'     => 'My Vanguard Diary — Afrovanguard',
    'desc'      => 'Your private Vanguard Diary: log institutional events, keep a personal journal, and submit public reflections to inspire the movement.',
    'canonical' => $canonical,
    'og_kind'   => 'website',
    'robots'    => 'noindex, nofollow',
    'body_class'=> 'diary-me',
]);
render_nav('diary');
?>
<main id="main-content">
  <section class="diary-hero vd-hero">
    <div class="container">
      <span class="diary-eyebrow">Vanguard Diary</span>
      <h1>Your Diary</h1>
      <p>Document what you build, reflect in private, and share what could inspire the movement. <strong>Public entries are reviewed before they appear on the Diary.</strong></p>
    </div>
  </section>

  <div class="container vd-wrap">
<?php if (!$user): ?>
    <!-- Signed out: inline sign-in (reuses the Academy account system) -->
    <section class="vd-card vd-signin" aria-labelledby="vd-signin-h">
      <h2 id="vd-signin-h">Sign in to your diary</h2>
      <p class="vd-muted">Your Vanguard Diary uses your Afrovanguard Academy account.</p>
      <form class="vd-form" id="vd-login" novalidate>
        <label class="vd-field">
          <span>Email</span>
          <input type="email" name="email" autocomplete="email" required placeholder="you@example.com" />
        </label>
        <label class="vd-field">
          <span>Password</span>
          <input type="password" name="password" autocomplete="current-password" required placeholder="••••••••" />
        </label>
        <button type="submit" class="btn btn-primary">Sign in →</button>
        <p class="vd-msg" role="status" aria-live="polite"></p>
      </form>
      <p class="vd-muted">New to the movement? <a href="/academy/">Join the Academy</a> to create an account.</p>
    </section>
<?php else: ?>
    <!-- Composer -->
    <section class="vd-card vd-compose" aria-labelledby="vd-compose-h">
      <h2 id="vd-compose-h">📝 New diary entry</h2>
      <form class="vd-form" id="vd-compose-form" novalidate>
        <div class="vd-row">
          <label class="vd-field">
            <span>Category</span>
            <select name="kind" id="vd-kind" required>
              <option value="event">📅 Event diary — an institutional log</option>
              <option value="private" selected>🔒 Private journal — only you can see it</option>
              <option value="public">🌐 Public journal — submit to inspire the movement</option>
            </select>
          </label>
          <label class="vd-field">
            <span>Date</span>
            <input type="date" name="entry_date" id="vd-date" value="<?= e($today) ?>" max="<?= e($today) ?>" />
          </label>
        </div>
        <label class="vd-field">
          <span>Title <span class="vd-opt">(optional)</span></span>
          <input type="text" name="title" maxlength="160" placeholder="A short headline" />
        </label>
        <label class="vd-field">
          <span>Your entry</span>
          <textarea name="body" rows="6" required placeholder="Share your entry here…"></textarea>
        </label>
        <p class="vd-hint" id="vd-hint" aria-live="polite">🔒 Private entries are visible only to you.</p>
        <div class="vd-actions">
          <button type="submit" class="btn btn-primary">Save entry</button>
          <p class="vd-msg" role="status" aria-live="polite"></p>
        </div>
      </form>
    </section>

    <!-- Past streams -->
    <section class="vd-card vd-streams" aria-labelledby="vd-streams-h">
      <h2 id="vd-streams-h">📂 Past diary streams</h2>
      <ul class="vd-list" id="vd-list">
<?php foreach ($entries as $e): [$icon, $kindLabel, $st, $stCls, $slug] = vd_badge($e); ?>
        <li class="vd-item" data-id="<?= (int) $e['id'] ?>">
          <div class="vd-item-head">
            <span class="vd-kind"><?= $icon ?> <?= e($kindLabel) ?></span>
            <span class="vd-status <?= e($stCls) ?>"><?= e($st) ?></span>
          </div>
<?php if ($e['title'] !== ''): ?>          <p class="vd-item-title"><?= e($e['title']) ?></p>
<?php endif; ?>          <p class="vd-item-body"><?= e(DiaryJournal::excerpt($e['body'], 220)) ?></p>
          <div class="vd-item-foot">
            <time datetime="<?= e($e['entry_date']) ?>"><?= e(date('M j, Y', strtotime($e['entry_date']) ?: time())) ?></time>
<?php if ($slug): ?>            · <a href="/diary/<?= e($slug) ?>/">View on the Diary →</a>
<?php endif; ?><?php if ($e['kind'] === 'public' && $e['status'] === 'rejected' && !empty($e['review_note'])): ?>            · <span class="vd-note"><?= e($e['review_note']) ?></span>
<?php endif; ?>            <button type="button" class="vd-del" data-id="<?= (int) $e['id'] ?>" aria-label="Delete this entry">Delete</button>
          </div>
        </li>
<?php endforeach; ?>
      </ul>
      <p class="vd-empty"<?= $entries ? ' hidden' : '' ?>>No entries yet. Your first one will appear here.</p>
    </section>
<?php endif; ?>
  </div>
</main>

<style>
  /* Vanguard Diary — member composer (scoped .vd-*; reuses site tokens). */
  .vd-hero { padding-bottom: 8px; }
  .vd-wrap { max-width: 760px; padding-bottom: 64px; }
  .vd-card { background: var(--surface, #fff); border: 1px solid var(--divider, #e7e4dd);
             border-radius: var(--radius-md, 16px); padding: clamp(20px, 4vw, 30px); margin-top: 22px; }
  .vd-card h2 { font-size: clamp(18px, 3.4vw, 22px); margin: 0 0 16px; }
  .vd-muted { color: var(--muted, #6b6b6b); font-size: 14.5px; }
  .vd-muted a { color: var(--gold, #b8860b); font-weight: 600; }
  .vd-form { display: flex; flex-direction: column; gap: 16px; }
  .vd-row { display: grid; grid-template-columns: 1fr; gap: 16px; }
  @media (min-width: 560px) { .vd-row { grid-template-columns: 1.4fr 1fr; } }
  .vd-field { display: flex; flex-direction: column; gap: 6px; }
  .vd-field > span { font-size: 13px; font-weight: 600; letter-spacing: .01em; }
  .vd-opt { color: var(--muted, #6b6b6b); font-weight: 400; }
  .vd-field input, .vd-field select, .vd-field textarea {
    width: 100%; font: inherit; padding: 12px 14px; border-radius: 12px;
    border: 1px solid var(--divider, #d9d6cf); background: #fff; color: var(--ink, #1a1a1a);
    -webkit-appearance: none; appearance: none; min-height: 46px; /* comfortable tap target */
  }
  .vd-field textarea { min-height: 140px; resize: vertical; line-height: 1.6; }
  .vd-field :is(input, select, textarea):focus-visible {
    outline: 2px solid var(--gold, #b8860b); outline-offset: 1px; border-color: var(--gold, #b8860b);
  }
  .vd-hint { font-size: 13.5px; color: var(--muted, #6b6b6b); margin: -4px 0 0; }
  .vd-actions { display: flex; align-items: center; gap: 16px; flex-wrap: wrap; }
  .vd-msg { font-size: 14px; margin: 0; }
  .vd-msg.is-ok { color: #1f7a4d; } .vd-msg.is-err { color: #b3261e; }

  .vd-list { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 14px; }
  .vd-item { border: 1px solid var(--divider, #e7e4dd); border-radius: 14px; padding: 16px 18px; }
  .vd-item-head { display: flex; align-items: center; justify-content: space-between; gap: 10px; margin-bottom: 8px; }
  .vd-kind { font-size: 12.5px; font-weight: 700; letter-spacing: .04em; text-transform: uppercase; }
  .vd-status { font-size: 11px; font-weight: 700; letter-spacing: .05em; text-transform: uppercase;
               padding: 3px 9px; border-radius: 999px; white-space: nowrap; }
  .vd-status.is-logged   { background: #eef0f2; color: #41464d; }
  .vd-status.is-pending  { background: #fff3d6; color: #8a6100; }
  .vd-status.is-live     { background: #e2f5ea; color: #1f7a4d; }
  .vd-status.is-rejected { background: #fde7e5; color: #b3261e; }
  .vd-item-title { font-weight: 700; margin: 0 0 4px; }
  .vd-item-body { color: var(--muted, #555); font-size: 14.5px; line-height: 1.55; margin: 0 0 10px; white-space: pre-wrap; }
  .vd-item-foot { display: flex; align-items: center; flex-wrap: wrap; gap: 8px; font-size: 12.5px; color: var(--muted, #6b6b6b); }
  .vd-item-foot a { color: var(--gold, #b8860b); font-weight: 600; }
  .vd-note { font-style: italic; }
  .vd-del { margin-left: auto; background: none; border: 0; color: #b3261e; font: inherit; font-size: 12.5px;
            cursor: pointer; padding: 4px 6px; border-radius: 8px; }
  .vd-del:hover { background: #fde7e5; }
  .vd-empty { color: var(--muted, #6b6b6b); text-align: center; padding: 18px 0; }
</style>

<script>
(function () {
  'use strict';
  var API = '/diary/api.php';
  function post(action, payload) {
    return fetch(API + '?action=' + action, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      credentials: 'same-origin',
      body: JSON.stringify(payload || {})
    }).then(function (r) { return r.json().catch(function () { return { ok: false, error: 'Server error.' }; }); });
  }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
    return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
  }); }

  /* ── Signed-out: inline sign-in via the Academy account system ── */
  var login = document.getElementById('vd-login');
  if (login) {
    login.addEventListener('submit', function (e) {
      e.preventDefault();
      var msg = login.querySelector('.vd-msg');
      msg.textContent = 'Signing in…'; msg.className = 'vd-msg';
      var fd = new FormData(login);
      fetch('/academy/api.php?action=login', {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ email: fd.get('email'), password: fd.get('password') })
      }).then(function (r) { return r.json(); }).then(function (d) {
        if (d && d.ok) { location.reload(); }
        else { msg.textContent = (d && d.error) || 'Could not sign in.'; msg.className = 'vd-msg is-err'; }
      }).catch(function () { msg.textContent = 'Network error — try again.'; msg.className = 'vd-msg is-err'; });
    });
    return;
  }

  /* ── Composer ── */
  var form = document.getElementById('vd-compose-form');
  if (!form) return;
  var list = document.getElementById('vd-list');
  var empty = document.querySelector('.vd-empty');
  var kind = document.getElementById('vd-kind');
  var hint = document.getElementById('vd-hint');

  var HINTS = {
    event:   '📅 Event entries log institutional happenings. You can backdate them.',
    private: '🔒 Private entries are visible only to you.',
    public:  '🌐 Public entries are reviewed by an admin before they appear on the Diary.'
  };
  kind.addEventListener('change', function () { hint.textContent = HINTS[kind.value] || ''; });

  var META = {
    event:   { icon: '📅', label: 'Event diary' },
    private: { icon: '🔒', label: 'Private journal' },
    public:  { icon: '🌐', label: 'Public journal' }
  };
  function statusFor(k) {
    if (k === 'public') return { txt: 'Pending review', cls: 'is-pending' };
    return { txt: 'Logged', cls: 'is-logged' };
  }
  function itemHTML(en) {
    var m = META[en.kind] || { icon: '📝', label: 'Entry' };
    var s = statusFor(en.kind);
    var d = new Date(en.entry_date + 'T00:00:00');
    var date = isNaN(d) ? en.entry_date : d.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
    return '<li class="vd-item" data-id="' + en.id + '">'
      + '<div class="vd-item-head"><span class="vd-kind">' + m.icon + ' ' + esc(m.label) + '</span>'
      + '<span class="vd-status ' + s.cls + '">' + s.txt + '</span></div>'
      + (en.title ? '<p class="vd-item-title">' + esc(en.title) + '</p>' : '')
      + '<p class="vd-item-body">' + esc(en.body.length > 220 ? en.body.slice(0, 219) + '…' : en.body) + '</p>'
      + '<div class="vd-item-foot"><time datetime="' + esc(en.entry_date) + '">' + esc(date) + '</time>'
      + '<button type="button" class="vd-del" data-id="' + en.id + '" aria-label="Delete this entry">Delete</button></div></li>';
  }

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    var msg = form.querySelector('.vd-msg');
    var btn = form.querySelector('button[type=submit]');
    var fd = new FormData(form);
    var payload = { kind: fd.get('kind'), title: fd.get('title'), body: fd.get('body'), entry_date: fd.get('entry_date') };
    if (!String(payload.body || '').trim()) { msg.textContent = 'Write something before saving.'; msg.className = 'vd-msg is-err'; return; }
    btn.disabled = true; msg.textContent = 'Saving…'; msg.className = 'vd-msg';
    post('entry.create', payload).then(function (d) {
      btn.disabled = false;
      if (!d.ok) { msg.textContent = d.error || 'Could not save.'; msg.className = 'vd-msg is-err'; return; }
      var en = { id: d.id, kind: d.kind, title: payload.title, body: payload.body, entry_date: payload.entry_date };
      if (empty) empty.hidden = true;
      list.insertAdjacentHTML('afterbegin', itemHTML(en));
      msg.textContent = d.kind === 'public' ? 'Submitted for review — you’ll see it here once approved.' : 'Saved.';
      msg.className = 'vd-msg is-ok';
      form.querySelector('[name=title]').value = '';
      form.querySelector('[name=body]').value = '';
    }).catch(function () { btn.disabled = false; msg.textContent = 'Network error — try again.'; msg.className = 'vd-msg is-err'; });
  });

  list && list.addEventListener('click', function (e) {
    var del = e.target.closest('.vd-del'); if (!del) return;
    if (!confirm('Delete this entry? This cannot be undone.')) return;
    var li = del.closest('.vd-item'); var id = del.getAttribute('data-id');
    post('entry.delete', { id: id }).then(function (d) {
      if (d.ok) { li.remove(); if (list && !list.children.length && empty) empty.hidden = false; }
      else { alert(d.error || 'Could not delete.'); }
    });
  });
})();
</script>
<?php render_footer();
