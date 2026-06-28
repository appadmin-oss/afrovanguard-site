<?php
/**
 * mentorship/index.php — the Afrovanguard mentor network hub.
 * Signed-in members find a mentor, manage their mentorships, run their mentee
 * inbox, and (org members) publish a mentor profile. SSR + a small inline JS
 * that drives /mentorship/api.php and reloads on success.
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_once AV_ROOT . '/lib/partials.php';
require_once AV_ROOT . '/lib/Mentorship.php';

$u = LmsAuth::user();
$canonical = rtrim(SITE_URL, '/') . '/mentorship/';

render_head([
    'title'     => 'Mentorship — Afrovanguard',
    'desc'      => 'Find a mentor, manage your mentorships, and give back as a mentor in the Afrovanguard network.',
    'canonical' => $canonical,
    'robots'    => 'noindex, nofollow',
    'css'       => ['/community/community.css'],
]);
render_nav('mentorship');

if (!$u):
?>
<main id="main-content" class="cm"><div class="cm-wrap"><div class="mn-gate cm-card" style="max-width:560px;margin:60px auto;text-align:center;padding:36px">
  <h1 style="font-family:var(--font-heading);font-size:34px;margin:0 0 8px">Mentorship</h1>
  <p style="color:var(--muted);margin:0 0 18px">Sign in to find a mentor, manage your mentorships, or mentor others.</p>
  <a class="cm-post-btn" data-login-link href="/login?next=/mentorship/">Sign in</a>
</div></div></main>
<?php render_footer(); exit; endif;

$isOrg     = LmsAuth::isOrgMember($u);
$isMentor  = Mentorship::isMentor((int) $u['id']);
$profile   = Mentorship::profile((int) $u['id']);
$asMentee  = Mentorship::myMentors((int) $u['id']);
$asMentor  = $isMentor ? Mentorship::myMentees((int) $u['id']) : [];
$mentors   = Mentorship::availableMentors((int) $u['id']);
$pending   = array_values(array_filter($asMentor, fn($m) => $m['status'] === 'pending'));
$activeMen = array_values(array_filter($asMentor, fn($m) => $m['status'] === 'active'));

/** A small avatar chip. */
function mn_av(array $m): string {
    return '<span class="cm-av" style="--c:#27607a;width:40px;height:40px;font-size:17px">' . e($m['initial']) . '</span>';
}
?>
<main id="main-content" class="cm mn"><div class="cm-wrap">
  <header class="cm-hero">
    <div class="cm-hero-txt">
      <span class="cm-online"><span class="cm-online-dot"></span>Mentor network</span>
      <h1>Mentorship</h1>
      <p>Pair with people building the movement — get guidance, or give it. Sessions and requests live here.</p>
    </div>
  </header>

  <div class="mn-cols">
    <section class="mn-main">
<?php if ($pending): ?>
      <div class="cm-card mn-card">
        <h2 class="mn-h">Requests for you <span class="mn-badge"><?= count($pending) ?></span></h2>
<?php foreach ($pending as $m): ?>
        <div class="mn-row" data-id="<?= (int) $m['id'] ?>">
          <?= mn_av($m) ?>
          <div class="mn-row-bd"><b><?= e($m['name']) ?></b><?= $m['message'] !== '' ? '<p class="mn-msg">“' . e($m['message']) . '”</p>' : '' ?></div>
          <div class="mn-row-ops">
            <button class="cm-post-btn mn-sm" data-respond="<?= (int) $m['id'] ?>" data-accept="1">Accept</button>
            <button class="cm-ask-btn mn-sm" data-respond="<?= (int) $m['id'] ?>" data-accept="0">Decline</button>
          </div>
        </div>
<?php endforeach; ?>
      </div>
<?php endif; ?>

<?php if ($asMentee): ?>
      <div class="cm-card mn-card">
        <h2 class="mn-h">Your mentors</h2>
<?php foreach ($asMentee as $m): ?>
        <div class="mn-row">
          <?= mn_av($m) ?>
          <div class="mn-row-bd"><b><?= e($m['name']) ?></b>
            <span class="mn-status mn-status--<?= e($m['status']) ?>"><?= $m['status'] === 'pending' ? 'Awaiting reply' : 'Active' ?></span>
<?= mn_sessions_html($m['sessions']) ?>
          </div>
          <div class="mn-row-ops"><?= $m['status'] === 'active' ? '<button class="cm-ask-btn mn-sm" data-end="' . (int) $m['id'] . '">End</button>' : '' ?></div>
        </div>
<?php endforeach; ?>
      </div>
<?php endif; ?>

<?php if ($activeMen): ?>
      <div class="cm-card mn-card">
        <h2 class="mn-h">Your mentees</h2>
<?php foreach ($activeMen as $m): ?>
        <div class="mn-row mn-row--col" data-id="<?= (int) $m['id'] ?>">
          <div class="mn-row-top"><?= mn_av($m) ?><div class="mn-row-bd"><b><?= e($m['name']) ?></b><span class="mn-status mn-status--active">Active</span></div></div>
<?= mn_sessions_html($m['sessions']) ?>
          <form class="mn-session-form" data-session="<?= (int) $m['id'] ?>">
            <input type="text" name="title" placeholder="Session title (e.g. Career check-in)" />
            <input type="datetime-local" name="when" />
            <button type="submit" class="cm-ask-btn mn-sm">+ Schedule</button>
          </form>
        </div>
<?php endforeach; ?>
      </div>
<?php endif; ?>

      <!-- Find a mentor -->
      <div class="cm-card mn-card">
        <h2 class="mn-h">Find a mentor</h2>
<?php if (!$mentors): ?>
        <p class="mn-empty">No mentors are accepting requests right now. Check back soon<?= $isOrg ? ' — or become one below.' : '.' ?></p>
<?php else: ?>
        <div class="mn-grid">
<?php foreach ($mentors as $m): ?>
          <div class="mn-mentor" data-mentor="<?= (int) $m['user_id'] ?>">
            <div class="mn-mentor-head"><?= mn_av($m) ?><div><b><?= e($m['name']) ?></b><span class="mn-mentor-line"><?= (int) $m['mentees'] ?>/<?= (int) $m['capacity'] ?> mentees</span></div></div>
            <p class="mn-mentor-headline"><?= e($m['headline']) ?></p>
<?php if ($m['focus']): ?>            <div class="mn-tags"><?php foreach (array_slice($m['focus'], 0, 4) as $t): ?><span class="mn-tag"><?= e($t) ?></span><?php endforeach; ?></div>
<?php endif; ?>
<?php if ($m['my_status']): ?>            <span class="mn-have"><?= $m['my_status'] === 'active' ? 'Your mentor' : 'Requested' ?></span>
<?php elseif ($m['full']): ?>            <span class="mn-have">At capacity</span>
<?php else: ?>            <button class="cm-post-btn mn-sm" data-request="<?= (int) $m['user_id'] ?>">Request</button>
<?php endif; ?>
          </div>
<?php endforeach; ?>
        </div>
<?php endif; ?>
      </div>
    </section>

    <!-- Become a mentor -->
    <aside class="mn-side">
      <div class="cm-card mn-card">
        <h2 class="mn-h">Become a mentor</h2>
<?php if (!$isOrg): ?>
        <p class="mn-empty">Mentoring is open to Afrovanguard members. Sign in with your <strong>@<?= e(defined('AV_ORG_DOMAIN') ? AV_ORG_DOMAIN : 'afrovanguard.org.ng') ?></strong> account to publish a profile.</p>
<?php else: ?>
        <p class="mn-sub"><?= $isMentor ? 'Update your mentor profile.' : 'Publish a profile and start accepting mentees.' ?></p>
        <form id="mnBecome" class="mn-form">
          <label class="mn-field"><span>Headline</span><input name="headline" maxlength="160" placeholder="e.g. Product & leadership mentor" value="<?= e((string) ($profile['headline'] ?? '')) ?>" required /></label>
          <label class="mn-field"><span>Focus areas (comma-separated)</span><input name="focus" maxlength="255" placeholder="leadership, tech, civics" value="<?= e((string) ($profile['focus'] ?? '')) ?>" /></label>
          <label class="mn-field"><span>About your mentoring</span><textarea name="bio" rows="4" maxlength="4000" placeholder="What you can help with, your background…"><?= e((string) ($profile['bio'] ?? '')) ?></textarea></label>
          <div class="mn-field-row">
            <label class="mn-field mn-field--sm"><span>Capacity</span><input name="capacity" type="number" min="1" max="50" value="<?= (int) ($profile['capacity'] ?? 3) ?>" /></label>
            <label class="mn-check"><input type="checkbox" name="accepting" <?= ($profile === null || (int) $profile['accepting'] === 1) ? 'checked' : '' ?> /> <span>Accepting requests</span></label>
          </div>
          <button type="submit" class="cm-post-btn"><?= $isMentor ? 'Save profile' : 'Become a mentor' ?></button>
          <p class="mn-formmsg" role="status" aria-live="polite"></p>
        </form>
<?php endif; ?>
      </div>
    </aside>
  </div>
</div></main>

<?php
/** Render a small sessions list. (Declared after use is fine in PHP for functions.) */
function mn_sessions_html(array $sessions): string {
    if (!$sessions) return '';
    $out = '<ul class="mn-sessions">';
    foreach ($sessions as $s) {
        $when = $s['when'] !== '' ? date('M j, g:ia', strtotime($s['when'] . ' UTC') ?: time()) : 'TBD';
        $out .= '<li><b>' . e($s['title']) . '</b> · ' . e($when) . '</li>';
    }
    return $out . '</ul>';
}
?>
<style>
  .mn .cm-hero { padding-bottom: 18px; }
  .mn-cols { display: grid; grid-template-columns: minmax(0,1fr) 320px; gap: 24px; align-items: start; }
  .mn-main { display: flex; flex-direction: column; gap: 18px; }
  .mn-card { padding: 22px 22px 24px; }
  .mn-h { font-family: var(--font-heading); font-size: 22px; color: var(--ink); margin: 0 0 14px; display: flex; align-items: center; gap: 10px; }
  .mn-badge { background: var(--gold); color: #111827; font-family: var(--font-body); font-size: 12px; font-weight: 800; border-radius: 999px; padding: 1px 9px; }
  .mn-row { display: flex; align-items: center; gap: 12px; padding: 12px 0; border-bottom: 1px solid var(--divider); }
  .mn-row:last-child { border-bottom: none; }
  .mn-row--col { flex-direction: column; align-items: stretch; gap: 10px; }
  .mn-row-top { display: flex; align-items: center; gap: 12px; }
  .mn-row-bd { flex: 1; min-width: 0; } .mn-row-bd b { color: var(--ink); font-size: 14.5px; }
  .mn-msg { margin: 3px 0 0; font-size: 13px; color: var(--muted); font-style: italic; }
  .mn-row-ops { display: flex; gap: 8px; flex-shrink: 0; }
  .mn-sm { padding: 7px 14px !important; min-height: 36px !important; font-size: 12.5px !important; }
  .mn-status { font-size: 11px; font-weight: 800; letter-spacing: .04em; text-transform: uppercase; padding: 2px 8px; border-radius: 999px; margin-left: 8px; }
  .mn-status--active { background: rgba(26,138,63,.14); color: #1a8a3f; }
  .mn-status--pending { background: var(--gold-soft); color: var(--gold-deep); }
  .mn-sessions { margin: 8px 0 0; padding-left: 18px; } .mn-sessions li { font-size: 13px; color: var(--body); margin: 3px 0; }
  .mn-sessions b { color: var(--ink); }
  .mn-session-form { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }
  .mn-session-form input { border: 1px solid var(--divider); border-radius: 10px; padding: 8px 11px; font: inherit; font-size: 13px; background: var(--bg); color: var(--ink); }
  .mn-session-form input[name=title] { flex: 1; min-width: 160px; }
  .mn-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
  .mn-mentor { border: 1px solid var(--divider); border-radius: var(--radius-md); padding: 15px; display: flex; flex-direction: column; gap: 9px; }
  .mn-mentor-head { display: flex; align-items: center; gap: 10px; } .mn-mentor-head b { color: var(--ink); font-size: 14.5px; display: block; }
  .mn-mentor-line { font-size: 12px; color: var(--muted); }
  .mn-mentor-headline { font-size: 13.5px; color: var(--body); line-height: 1.5; margin: 0; flex: 1; }
  .mn-tags { display: flex; flex-wrap: wrap; gap: 6px; } .mn-tag { font-size: 11px; font-weight: 700; color: var(--gold-deep); background: var(--gold-soft); padding: 3px 9px; border-radius: 999px; }
  .mn-have { font-size: 12px; font-weight: 700; color: var(--muted); }
  .mn-form { display: flex; flex-direction: column; gap: 12px; } .mn-field { display: flex; flex-direction: column; gap: 5px; font-size: 13px; font-weight: 600; color: var(--ink); }
  .mn-field span { color: var(--muted); } .mn-field input, .mn-field textarea { border: 1px solid var(--divider); border-radius: 10px; padding: 10px 12px; font: inherit; font-size: 14px; background: var(--bg); color: var(--ink); }
  .mn-field-row { display: flex; gap: 14px; align-items: flex-end; } .mn-field--sm { width: 90px; } .mn-field--sm input { width: 100%; }
  .mn-check { display: flex; align-items: center; gap: 8px; font-size: 13px; color: var(--ink); } .mn-check input { width: auto; }
  .mn-sub { font-size: 13.5px; color: var(--muted); margin: -4px 0 4px; } .mn-empty { color: var(--muted); font-size: 14px; }
  .mn-formmsg { font-size: 13px; margin: 0; } .mn-formmsg.ok { color: #1a8a3f; } .mn-formmsg.err { color: #c0392b; }
  @media (max-width: 900px) { .mn-cols { grid-template-columns: 1fr; } .mn-grid { grid-template-columns: 1fr; } }
</style>
<script>
(function () {
  'use strict';
  var API = '/mentorship/api.php';
  function post(action, payload) {
    return fetch(API + '?action=' + action, { method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'same-origin', body: JSON.stringify(payload || {}) })
      .then(function (r) { return r.json().catch(function () { return { ok: false, error: 'Server error.' }; }); });
  }
  function reloadSoon() { setTimeout(function () { location.reload(); }, 350); }

  document.addEventListener('click', function (e) {
    var req = e.target.closest('[data-request]');
    if (req) {
      var note = prompt('Add a short note for this mentor (optional):') || '';
      req.disabled = true; req.textContent = 'Requesting…';
      post('request', { mentor_id: +req.getAttribute('data-request'), message: note }).then(function (d) {
        if (d.ok) { req.textContent = 'Requested ✓'; reloadSoon(); }
        else { req.disabled = false; req.textContent = 'Request'; alert(d.error || 'Could not request.'); }
      }); return;
    }
    var resp = e.target.closest('[data-respond]');
    if (resp) {
      resp.disabled = true;
      post('respond', { id: +resp.getAttribute('data-respond'), accept: resp.getAttribute('data-accept') === '1' })
        .then(function (d) { if (d.ok) reloadSoon(); else { resp.disabled = false; alert(d.error || 'Failed.'); } });
      return;
    }
    var end = e.target.closest('[data-end]');
    if (end) {
      if (!confirm('End this mentorship?')) return;
      end.disabled = true;
      post('end', { id: +end.getAttribute('data-end') }).then(function (d) { if (d.ok) reloadSoon(); else { end.disabled = false; alert(d.error || 'Failed.'); } });
    }
  });

  document.addEventListener('submit', function (e) {
    var sf = e.target.closest('[data-session]');
    if (sf) {
      e.preventDefault();
      var title = sf.querySelector('[name=title]').value, when = sf.querySelector('[name=when]').value;
      if (!title.trim()) { sf.querySelector('[name=title]').focus(); return; }
      post('session', { id: +sf.getAttribute('data-session'), title: title, when: when }).then(function (d) { if (d.ok) reloadSoon(); else alert(d.error || 'Failed.'); });
      return;
    }
    if (e.target.id === 'mnBecome') {
      e.preventDefault();
      var f = e.target, msg = f.querySelector('.mn-formmsg');
      msg.textContent = 'Saving…'; msg.className = 'mn-formmsg';
      post('become', {
        headline: f.headline.value, focus: f.focus.value, bio: f.bio.value,
        capacity: f.capacity.value, accepting: f.accepting.checked,
      }).then(function (d) {
        if (d.ok) { msg.textContent = 'Saved.'; msg.className = 'mn-formmsg ok'; reloadSoon(); }
        else { msg.textContent = d.error || 'Could not save.'; msg.className = 'mn-formmsg err'; }
      }).catch(function () { msg.textContent = 'Network error.'; msg.className = 'mn-formmsg err'; });
    }
  });
})();
</script>
<?php render_footer();
