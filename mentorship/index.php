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
<main id="main-content" class="cm"><div class="cm-wrap"><div class="mn-gate cm-card" style="max-width:560px;margin:60px auto;padding:36px">
  <header class="page-head page-head--center" style="padding:0">
    <p class="page-eyebrow">Mentor network</p>
    <h1 class="page-title">Mentorship</h1>
    <p class="page-lead">Sign in to find a mentor, manage your mentorships, or mentor others.</p>
    <div class="page-actions"><a class="cm-post-btn" data-login-link href="/login?next=/mentorship/">Sign in</a></div>
  </header>
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
// A member's mentorship footprint across every pairing — real hours logged.
$myStats   = Mentorship::memberConsistency((int) $u['id']);
$myHours   = (float) ($myStats['hours'] ?? 0);
$hoursDisp = rtrim(rtrim(number_format($myHours, 1), '0'), '.');

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
<?php if ((int) ($myStats['held'] ?? 0) > 0): ?>
    <dl class="mn-stats" aria-label="Your mentorship at a glance">
      <div class="mn-stat"><dt><?= e($hoursDisp) ?></dt><dd>Hour<?= $myHours == 1.0 ? '' : 's' ?> logged</dd></div>
      <div class="mn-stat"><dt><?= (int) $myStats['attended'] ?></dt><dd>Sessions attended</dd></div>
<?php if ($myStats['rate'] !== null): ?>      <div class="mn-stat"><dt><?= (int) $myStats['rate'] ?>%</dt><dd>Consistency</dd></div>
<?php endif; ?>
    </dl>
<?php endif; ?>
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
<?= mn_goals_html($m) ?>
<?= mn_consistency_html($m['consistency'] ?? []) ?>
<?= mn_sessions_html($m['sessions'], false) ?>
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
<?= mn_goals_html($m) ?>
<?= mn_consistency_html($m['consistency'] ?? []) ?>
<?= mn_sessions_html($m['sessions'], true) ?>
          <form class="mn-session-form" data-session="<?= (int) $m['id'] ?>">
            <select name="type" title="Session type" aria-label="Session type">
<?php foreach (Mentorship::sessionTypes() as $tk => $tl): ?>              <option value="<?= e($tk) ?>"<?= $tk === 'checkin' ? ' selected' : '' ?>><?= e($tl) ?></option>
<?php endforeach; ?>
            </select>
            <input type="text" name="title" placeholder="Session title (optional — defaults to the type)" />
            <input type="datetime-local" name="when" />
            <input type="number" name="duration_min" min="15" max="240" step="15" value="60" title="Planned length (minutes)" aria-label="Session length in minutes" />
            <input type="url" name="meet_url" placeholder="Google Meet link (optional)" />
            <input type="text" name="notes" placeholder="Agenda for this session (optional)" />
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
      <div class="cm-card mn-card mn-standard">
        <h2 class="mn-h">The Afrovanguard mentorship standard</h2>
        <p class="mn-sub">Every mentorship runs the same professional way.</p>
        <ol class="mn-std-list">
          <li><b>Set goals first.</b> Agree 1–3 clear goals at the kick-off — the compass for every session.</li>
          <li><b>Meet on a steady cadence.</b> Aim for a session at least every two weeks, on Google Meet.</li>
          <li><b>Every session is structured.</b> A type and agenda going in; attendance, hours and action items logged after.</li>
          <li><b>Stay accountable.</b> Consistency, hours and outcomes are tracked for both mentor and mentee.</li>
          <li><b>Safeguard &amp; respect.</b> Keep it professional, confidential and kind — always.</li>
        </ol>
      </div>
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
function mn_sessions_html(array $sessions, bool $asMentor = false): string {
    if (!$sessions) return '';
    $attLabel = ['scheduled' => 'Scheduled', 'attended' => 'Attended', 'missed' => 'Missed', 'cancelled' => 'Cancelled'];
    $out = '<ul class="mn-sessions">';
    foreach ($sessions as $s) {
        $when = $s['when'] !== '' ? date('M j, g:ia', strtotime($s['when'] . ' UTC') ?: time()) : 'TBD';
        $att  = (string) ($s['attendance'] ?? 'scheduled');
        $meet = (string) ($s['meet_url'] ?? '');
        $tr   = (string) ($s['transcript_url'] ?? '');
        $dur  = (int) ($s['duration_min'] ?? 0);
        $durTxt = $att === 'attended' ? ' · ' . ($dur > 0 ? $dur : 60) . ' min' : ($dur > 0 && $att === 'scheduled' ? ' · ' . $dur . ' min' : '');
        $type = (string) ($s['type'] ?? 'checkin');
        $agenda = trim((string) ($s['notes'] ?? ''));
        $outcome = trim((string) ($s['outcome'] ?? ''));
        $out .= '<li class="mn-sess mn-sess--' . e($att) . '">';
        $out .= '<div class="mn-sess-row"><span class="mn-sess-title"><span class="mn-type">' . e(Mentorship::typeLabel($type)) . '</span> <b>' . e($s['title']) . '</b> · ' . e($when) . e($durTxt) . '</span>';
        $out .= '<span class="mn-att mn-att--' . e($att) . '">' . e($attLabel[$att] ?? $att) . '</span></div>';
        if ($agenda !== '')  $out .= '<p class="mn-sess-agenda"><b>Agenda:</b> ' . e($agenda) . '</p>';
        if ($outcome !== '') $out .= '<p class="mn-sess-outcome"><b>Outcome &amp; action items:</b> ' . e($outcome) . '</p>';
        $out .= '<div class="mn-sess-links">';
        if ($meet !== '') $out .= '<a class="mn-join" href="' . e($meet) . '" target="_blank" rel="noopener">▶ Join Meet</a>';
        if ($tr !== '')   $out .= '<a class="mn-transcript" href="' . e($tr) . '" target="_blank" rel="noopener">📄 Transcript</a>';
        $out .= '</div>';
        if ($asMentor) {
            $out .= '<div class="mn-sess-ctl">'
                . '<button type="button" class="mn-chip" data-attend="' . (int) $s['id'] . '" data-status="attended">Attended</button>'
                . '<button type="button" class="mn-chip" data-attend="' . (int) $s['id'] . '" data-status="missed">Missed</button>'
                . '<button type="button" class="mn-chip" data-outcome="' . (int) $s['id'] . '">' . ($outcome === '' ? 'Log outcome' : 'Edit outcome') . '</button>'
                . '<button type="button" class="mn-chip" data-meet="' . (int) $s['id'] . '">' . ($meet === '' ? 'Add Meet' : 'Edit Meet') . '</button>'
                . '<button type="button" class="mn-chip" data-transcript="' . (int) $s['id'] . '">' . ($tr === '' ? 'Add transcript' : 'Edit transcript') . '</button>'
                . '</div>';
        }
        $out .= '</li>';
    }
    return $out . '</ul>';
}

/** The pairing's shared goals — the professional anchor for the relationship. */
function mn_goals_html(array $m): string {
    if (($m['status'] ?? '') !== 'active') return '';
    $g = trim((string) ($m['goals'] ?? ''));
    $id = (int) $m['id'];
    if ($g === '') {
        return '<div class="mn-goals mn-goals--empty"><span>No goals set yet.</span>'
            . '<button type="button" class="mn-goals-edit" data-goals="' . $id . '">Set goals</button></div>';
    }
    return '<div class="mn-goals"><span class="mn-goals-lbl">Goals</span><span class="mn-goals-txt">' . e($g) . '</span>'
        . '<button type="button" class="mn-goals-edit" data-goals="' . $id . '">Edit</button></div>';
}

function mn_consistency_html(array $c): string {
    if ((int) ($c['held'] ?? 0) <= 0) return '';
    $rate = (int) ($c['rate'] ?? 0);
    $streak = (int) ($c['streak'] ?? 0);
    $hours = (float) ($c['hours'] ?? 0);
    $hrTxt = $hours > 0 ? ' · ' . rtrim(rtrim(number_format($hours, 1), '0'), '.') . ' hr' . ($hours == 1.0 ? '' : 's') . ' logged' : '';
    return '<div class="mn-consist" title="Attendance & hours of past sessions">'
        . '<div class="mn-consist-bar"><span style="width:' . $rate . '%"></span></div>'
        . '<span class="mn-consist-txt">' . $rate . '% consistent · ' . (int) $c['attended'] . '/' . (int) $c['held'] . ' attended'
        . $hrTxt . ($streak > 1 ? ' · ' . $streak . '🔥' : '') . '</span></div>';
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
  .mn-sessions { list-style: none; margin: 10px 0 0; padding: 0; display: flex; flex-direction: column; gap: 8px; }
  .mn-sessions b { color: var(--ink); }
  .mn-sess { border: 1px solid var(--divider); border-radius: 12px; padding: 10px 12px; background: var(--surface); }
  .mn-sess--attended { border-left: 3px solid #1a8a3f; } .mn-sess--missed { border-left: 3px solid #c0392b; }
  .mn-sess--scheduled { border-left: 3px solid var(--gold); }
  .mn-sess-row { display: flex; align-items: center; justify-content: space-between; gap: 10px; }
  .mn-sess-title { font-size: 13px; color: var(--body); }
  .mn-att { font-size: 10px; font-weight: 800; letter-spacing: .04em; text-transform: uppercase; padding: 2px 8px; border-radius: 999px; white-space: nowrap; }
  .mn-att--scheduled { background: rgba(243,180,22,.16); color: var(--gold-deep); }
  .mn-att--attended { background: rgba(26,138,63,.14); color: #1a8a3f; }
  .mn-att--missed { background: rgba(192,57,43,.12); color: #c0392b; }
  .mn-att--cancelled { background: var(--surface-2); color: var(--muted); }
  .mn-sess-links { display: flex; gap: 10px; margin-top: 6px; flex-wrap: wrap; }
  .mn-sess-links a { font-size: 12px; font-weight: 700; text-decoration: none; }
  .mn-join { color: #fff; background: var(--gold); padding: 4px 12px; border-radius: 999px; }
  .mn-join:hover { filter: brightness(.96); } .mn-transcript { color: var(--link); align-self: center; }
  .mn-sess-ctl { display: flex; gap: 6px; margin-top: 8px; flex-wrap: wrap; }
  .mn-chip { font-size: 11px; font-weight: 700; padding: 4px 10px; border-radius: 999px; border: 1px solid var(--divider); background: var(--surface-2); color: var(--body); cursor: pointer; }
  .mn-chip:hover { border-color: var(--gold); color: var(--ink); }
  /* standardised session structure */
  .mn-type { display: inline-block; font-size: 10px; font-weight: 800; letter-spacing: .05em; text-transform: uppercase; color: var(--gold-deep); background: var(--gold-soft); padding: 2px 8px; border-radius: 999px; margin-right: 4px; vertical-align: middle; }
  .mn-sess-agenda, .mn-sess-outcome { font-size: 12.5px; line-height: 1.5; margin: 6px 0 0; color: var(--body); }
  .mn-sess-agenda b, .mn-sess-outcome b { color: var(--ink); }
  .mn-sess-outcome { padding: 8px 10px; background: var(--surface-2); border-radius: 8px; }
  /* goals (the professional anchor) */
  .mn-goals { display: flex; align-items: baseline; gap: 8px; flex-wrap: wrap; margin: 8px 0 2px; padding: 10px 12px; background: var(--surface-2); border-radius: 8px; }
  .mn-goals-lbl { font-size: 10px; font-weight: 800; letter-spacing: .08em; text-transform: uppercase; color: var(--gold-deep); }
  .mn-goals-txt { flex: 1; font-size: 13px; line-height: 1.5; color: var(--ink); font-weight: 600; }
  .mn-goals--empty { color: var(--muted); font-size: 12.5px; }
  .mn-goals-edit { margin-left: auto; font-size: 11px; font-weight: 700; color: var(--gold-deep); background: none; border: 0; cursor: pointer; padding: 0; }
  .mn-goals-edit:hover { text-decoration: underline; }
  /* the standard panel */
  .mn-standard .mn-std-list { margin: 10px 0 0; padding: 0 0 0 18px; display: flex; flex-direction: column; gap: 8px; }
  .mn-standard .mn-std-list li { font-size: 13px; line-height: 1.5; color: var(--body); }
  .mn-standard .mn-std-list b { color: var(--ink); }
  .mn-stats { display: flex; gap: 26px; flex-wrap: wrap; margin: 18px 0 0; padding: 0; }
  .mn-stat dt { font-family: var(--font-heading, 'Cormorant', Georgia, serif); font-weight: 700; font-size: 30px; line-height: 1; color: var(--ink); }
  .mn-stat dd { margin: 4px 0 0; font-size: 12px; font-weight: 700; letter-spacing: .04em; text-transform: uppercase; color: var(--muted); }
  .mn-consist { margin: 10px 0 2px; }
  .mn-consist-bar { height: 7px; border-radius: 999px; background: var(--surface-2); overflow: hidden; }
  .mn-consist-bar > span { display: block; height: 100%; background: linear-gradient(90deg, var(--gold), #1a8a3f); border-radius: 999px; }
  .mn-consist-txt { display: block; margin-top: 5px; font-size: 11.5px; font-weight: 600; color: var(--muted); }
  .mn-session-form input[type=url] { margin-top: 6px; }
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
      return;
    }
    var att = e.target.closest('[data-attend]');
    if (att) {
      var status = att.getAttribute('data-status');
      var payload = { session_id: +att.getAttribute('data-attend'), status: status };
      // Marking a session attended logs its length → real mentorship hours.
      if (status === 'attended') {
        var mins = prompt('How long did this session run? (minutes)', '60');
        if (mins === null) return;
        var n = parseInt(mins, 10);
        if (isFinite(n) && n > 0) payload.duration_min = n;
      }
      att.disabled = true;
      post('attend', payload)
        .then(function (d) { if (d.ok) reloadSoon(); else { att.disabled = false; alert(d.error || 'Failed.'); } });
      return;
    }
    var meet = e.target.closest('[data-meet]');
    if (meet) {
      var u = prompt('Paste the Google Meet link for this session.\nTip: open meet.google.com/new in another tab to create one, then paste it here.', '');
      if (u === null) return;
      post('meet', { session_id: +meet.getAttribute('data-meet'), meet_url: u }).then(function (d) { if (d.ok) reloadSoon(); else alert(d.error || 'Failed.'); });
      return;
    }
    var tr = e.target.closest('[data-transcript]');
    if (tr) {
      var t = prompt('Paste the transcript link (a Google Doc or Drive file from the Meet recording).', '');
      if (t === null) return;
      post('transcript', { session_id: +tr.getAttribute('data-transcript'), url: t }).then(function (d) { if (d.ok) reloadSoon(); else alert(d.error || 'Failed.'); });
      return;
    }
    var oc = e.target.closest('[data-outcome]');
    if (oc) {
      var o = prompt('Record the outcome and action items from this session (what was covered, what the mentee will do next).', '');
      if (o === null) return;
      post('outcome', { session_id: +oc.getAttribute('data-outcome'), outcome: o }).then(function (d) { if (d.ok) reloadSoon(); else alert(d.error || 'Failed.'); });
      return;
    }
    var gl = e.target.closest('[data-goals]');
    if (gl) {
      var g = prompt('What are the goals for this mentorship? (e.g. "Build confidence leading a team; ship one community project by December.")', '');
      if (g === null) return;
      post('goals', { id: +gl.getAttribute('data-goals'), goals: g }).then(function (d) { if (d.ok) reloadSoon(); else alert(d.error || 'Failed.'); });
    }
  });

  document.addEventListener('submit', function (e) {
    var sf = e.target.closest('[data-session]');
    if (sf) {
      e.preventDefault();
      var title = sf.querySelector('[name=title]').value, when = sf.querySelector('[name=when]').value;
      if (!title.trim()) { sf.querySelector('[name=title]').focus(); return; }
      var meetEl = sf.querySelector('[name=meet_url]');
      var durEl = sf.querySelector('[name=duration_min]');
      var typeEl = sf.querySelector('[name=type]');
      var notesEl = sf.querySelector('[name=notes]');
      post('session', { id: +sf.getAttribute('data-session'), title: title, when: when, meet_url: meetEl ? meetEl.value : '', duration_min: durEl ? (parseInt(durEl.value, 10) || 60) : 60, type: typeEl ? typeEl.value : 'checkin', notes: notesEl ? notesEl.value : '' }).then(function (d) { if (d.ok) reloadSoon(); else alert(d.error || 'Failed.'); });
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
