<?php
/**
 * /mentorship/compliance/ — the compliance committee's desk (lib/Conduct.php).
 *
 * Mentors' fines and awards wait here until a committee member or a superadmin
 * approves or rejects them. Nothing a mentor proposes is real until then.
 * Superadmins also keep the committee list; the committee sets which pairings
 * are Vanguard Quest mentorships.
 */
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/lib/bootstrap.php';
require_once AV_ROOT . '/lib/partials.php';

$u = LmsAuth::user();
if (!$u) { header('Location: /login/?next=' . rawurlencode('/mentorship/compliance/')); exit; }
$can = Conduct::canDecide($u);
$superior = Conduct::isSuperior($u);
$tab = (string) ($_GET['tab'] ?? 'waiting');
if (!in_array($tab, ['waiting', 'decided', 'pairings', 'committee'], true)) $tab = 'waiting';
$done = (string) ($_GET['done'] ?? '');
$err = (string) ($_GET['err'] ?? '');
$doneWord = ['approved' => 'Approved. It is now real: the fine is on their account, or the points are theirs, and both people have been told.',
             'rejected' => 'Rejected. The mentor has been told why.', 'kind' => 'Saved.', 'added' => 'Added to the committee.', 'removed' => 'Removed from the committee.'];

render_head([
    'title' => 'Compliance — Afrovanguard', 'desc' => 'Fines and awards waiting for a decision.',
    'canonical' => rtrim(SITE_URL, '/') . '/mentorship/compliance/', 'robots' => 'noindex, nofollow',
    'css' => ['/assets/site/av-tokens.css', '/assets/site/avm.css', '/mentorship/conduct.css'],
]);
render_nav('mentorship');
$csrf = av_csrf_token();
$money = static fn(int $n): string => '₦' . number_format($n);
$reasonLabel = static fn(array $c): string => ($c['kind'] === 'fine' ? Conduct::FINE_REASONS : Conduct::AWARD_REASONS)[$c['reason']] ?? $c['reason'];
?>
<main class="avcd-page" id="main">
  <header>
    <h1>Compliance</h1>
    <p>Fines and awards mentors have put forward. Each becomes real only when you approve it — a fine is then posted to the mentee’s NGV account, an award adds their points — and you can never decide one you proposed or one about yourself.</p>
  </header>
<?php if (!$can): ?>
  <section class="avcd-box"><p class="avcd-p">This desk is for the compliance committee and superadmins. If you should be on the committee, ask a superadmin to add you.</p></section>
</main>
<?php render_footer(); exit; endif; ?>

<?php if ($done !== '' && isset($doneWord[$done])): ?>  <p class="avcd-note is-ok" role="status"><?= e($doneWord[$done]) ?></p>
<?php elseif ($err !== ''): ?>  <p class="avcd-note is-bad" role="alert"><?= e($err) ?></p>
<?php endif; ?>

  <nav class="avcd-tabs" aria-label="Compliance">
<?php $waiting = Conduct::queue('proposed');
foreach (['waiting' => 'Waiting (' . count($waiting) . ')', 'decided' => 'Decided', 'pairings' => 'Mentor kinds', 'committee' => 'Committee'] as $k => $l): ?>
    <a href="?tab=<?= e($k) ?>"<?= $tab === $k ? ' aria-current="page"' : '' ?>><?= e($l) ?></a>
<?php endforeach; ?>
  </nav>

<?php if ($tab === 'waiting' || $tab === 'decided'):
    $rows = $tab === 'waiting' ? $waiting : array_merge(Conduct::queue('approved', 50), Conduct::queue('rejected', 50)); ?>
  <section class="avcd-box" aria-label="<?= $tab === 'waiting' ? 'Waiting for a decision' : 'Decided' ?>">
<?php if (!$rows): ?>
    <p class="avcd-p"><?= $tab === 'waiting' ? 'Nothing is waiting. Mentors’ proposals arrive here, and you are notified.' : 'Nothing decided yet.' ?></p>
<?php else: ?>
    <ul class="avcd-cases">
<?php foreach ($rows as $c): $self = (int) $c['mentor_id'] === (int) $u['id'] || (int) $c['mentee_id'] === (int) $u['id']; ?>
      <li>
        <span class="avcd-what">
          <b><?= $c['kind'] === 'fine' ? 'Fine ' . $money((int) $c['amount']) : 'Award · ' . (int) $c['points'] . ' points' ?> for <?= e((string) $c['mentee_name']) ?></b>
          <span><?= e($c['title'] !== '' ? $c['title'] : $reasonLabel($c)) ?> · on <?= e((string) $c['occurred_on']) ?> · from <?= e((string) $c['mentor_name']) ?>, <?= e(Conduct::MENTOR_KINDS[$c['mentor_kind']] ?? 'mentor') ?></span>
          <span class="avcd-ev"><?= e((string) $c['evidence']) ?></span>
<?php if ($tab === 'decided'): ?>          <span><?= e(ucfirst((string) $c['status'])) ?> by <?= e((string) ($c['decider_name'] ?? '')) ?> on <?= e(substr((string) $c['decided_at'], 0, 10)) ?><?= trim((string) $c['decision_note']) !== '' ? ' — ' . e((string) $c['decision_note']) : '' ?></span>
<?php endif; ?>
        </span>
<?php if ($tab === 'waiting'): ?>
<?php if ($self): ?>
        <span class="avcd-chip">Somebody else decides this one</span>
<?php else: ?>
        <form class="avcd-decide" method="post" action="/mentorship/conduct.php">
          <input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="case_id" value="<?= (int) $c['id'] ?>">
          <input type="text" name="note" maxlength="1000" placeholder="Note — required to reject" aria-label="Note for case <?= (int) $c['id'] ?>">
          <button class="avm-btn avm-btn--ink" type="submit" name="op" value="approve">Approve</button>
          <button class="avm-btn avm-btn--danger" type="submit" name="op" value="reject">Reject</button>
        </form>
<?php endif; ?>
<?php else: ?>
        <span class="avcd-chip" data-tone="<?= $c['status'] === 'approved' ? 'green' : 'red' ?>"><?= e(ucfirst((string) $c['status'])) ?></span>
<?php endif; ?>
      </li>
<?php endforeach; ?>
    </ul>
<?php endif; ?>
  </section>

<?php elseif ($tab === 'pairings'):
    $pairs = Database::pdo()->query("SELECT m.id, m.kind, a.name AS mentor_name, b.name AS mentee_name FROM mentorships m
        LEFT JOIN lms_users a ON a.id = m.mentor_id LEFT JOIN lms_users b ON b.id = m.mentee_id
        WHERE m.status = 'active' ORDER BY a.name, b.name LIMIT 300")->fetchAll(PDO::FETCH_ASSOC) ?: []; ?>
  <section class="avcd-box" aria-label="Mentor kinds">
    <p class="avcd-p">A Vanguard Quest mentor is the mentee’s real mentor, who tracks them throughout. Every other pairing is an Academy mentorship from the /mentorship community. Both may put fines and awards forward; the committee sees which kind asked.</p>
<?php if (!$pairs): ?>    <p class="avcd-p">No active pairings.</p>
<?php else: ?>
    <ul class="avcd-cases">
<?php foreach ($pairs as $pr): ?>
      <li>
        <span class="avcd-what"><b><?= e((string) $pr['mentor_name']) ?> → <?= e((string) $pr['mentee_name']) ?></b></span>
        <form class="avcd-decide" method="post" action="/mentorship/conduct.php">
          <input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="op" value="kind"><input type="hidden" name="pairing_id" value="<?= (int) $pr['id'] ?>">
          <select class="avm-select" name="kind" aria-label="Kind of mentorship for <?= e((string) $pr['mentee_name']) ?>">
<?php foreach (Conduct::MENTOR_KINDS as $k => $l): ?>            <option value="<?= e($k) ?>"<?= ($pr['kind'] ?? 'academy') === $k ? ' selected' : '' ?>><?= e($l) ?></option>
<?php endforeach; ?>
          </select>
          <button class="avm-btn" type="submit">Save</button>
        </form>
      </li>
<?php endforeach; ?>
    </ul>
<?php endif; ?>
  </section>

<?php else: $members = Conduct::members(); ?>
  <section class="avcd-box" aria-label="Committee">
    <p class="avcd-p">Committee members decide mentors’ fines and awards, alongside superadmins. <?= $superior ? 'You keep this list.' : 'A superadmin keeps this list.' ?></p>
<?php if (!$members): ?>    <p class="avcd-p">Nobody yet — superadmins decide until there is a committee.</p>
<?php else: ?>
    <ul class="avcd-cases">
<?php foreach ($members as $m): ?>
      <li>
        <span class="avcd-what"><b><?= e((string) $m['name']) ?></b><span><?= e((string) $m['email']) ?></span></span>
<?php if ($superior): ?>
        <form method="post" action="/mentorship/conduct.php">
          <input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="op" value="member_remove"><input type="hidden" name="user_id" value="<?= (int) $m['user_id'] ?>">
          <button class="avm-btn" type="submit">Remove</button>
        </form>
<?php endif; ?>
      </li>
<?php endforeach; ?>
    </ul>
<?php endif; ?>
<?php if ($superior): ?>
    <form class="avcd-decide" method="post" action="/mentorship/conduct.php">
      <input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="op" value="member_add">
      <input type="text" name="email" required placeholder="Their email (they must have signed in once)" aria-label="Email of the person to add">
      <button class="avm-btn avm-btn--ink" type="submit">Add to the committee</button>
    </form>
<?php endif; ?>
  </section>
<?php endif; ?>
</main>
<?php render_footer();
