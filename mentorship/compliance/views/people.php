<?php
/** Committee & kinds: who decides, and which pairings are Vanguard Quest mentorships. */
declare(strict_types=1);
$members = Conduct::members();
$pairs = Database::pdo()->query("SELECT m.id, m.kind, a.name AS mentor_name, b.name AS mentee_name FROM mentorships m
    LEFT JOIN lms_users a ON a.id = m.mentor_id LEFT JOIN lms_users b ON b.id = m.mentee_id
    WHERE m.status = 'active' ORDER BY a.name, b.name LIMIT 400")->fetchAll(PDO::FETCH_ASSOC) ?: [];
?>
      <section class="pview avp-view qd-view" id="view-people" data-view="people" hidden aria-labelledby="qdPeopleH">
        <div class="avp-view-head qd-head">
          <div><h1 class="avp-h1" id="qdPeopleH"><?= e($title) ?></h1>
            <p class="qd-sub">The committee decides alongside superadmins; nobody decides what they proposed or what is about them. A Vanguard Quest mentor is a mentee's real mentor — the one whose mentees count toward the pyramid, and who earns when a mentee rises.</p></div>
        </div>
        <div class="avp-cols">
          <div class="avp-col avp-col--side">
            <section class="avp-card" aria-labelledby="qdCommH">
              <div class="avp-card-head"><h2 id="qdCommH">The committee</h2><span class="avp-head-sub"><?= $superior ? 'You keep this list' : 'A superadmin keeps this list' ?></span></div>
              <div class="avp-card-body">
<?php if (!$members): ?>
                <p class="qd-empty">Nobody yet — superadmins decide until there is a committee.</p>
<?php else: ?>
                <ul class="qd-list">
<?php foreach ($members as $m): ?>
                  <li><span class="qd-list-main"><b><?= e((string) $m['name']) ?></b><span class="qd-cell-sub"><?= e((string) $m['email']) ?></span></span>
<?php if ($superior): ?>
                    <form method="post" action="/mentorship/conduct.php"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="op" value="member_remove"><input type="hidden" name="user_id" value="<?= (int) $m['user_id'] ?>">
                      <button class="avp-btn avp-btn--sq qd-btn-quiet" type="submit">Remove</button></form>
<?php endif; ?>
                  </li>
<?php endforeach; ?>
                </ul>
<?php endif; ?>
<?php if ($superior): ?>
                <form class="qd-inline qd-gap-s" method="post" action="/mentorship/conduct.php">
                  <input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="op" value="member_add">
                  <label class="qd-grow"><span class="av-sr">Email of the person to add</span><input type="email" name="email" required placeholder="Their email (signed in once)"></label>
                  <button class="avp-btn avp-btn--ink avp-btn--sq" type="submit">Add</button>
                </form>
<?php endif; ?>
              </div>
            </section>
          </div>
          <div class="avp-col avp-col--main">
            <section class="avp-card" aria-labelledby="qdKindsH">
              <div class="avp-card-head"><h2 id="qdKindsH">Mentor kinds</h2><span class="avp-head-sub av-num"><?= count($pairs) ?> active pairings</span></div>
<?php if (!$pairs): ?>
              <div class="avp-card-body"><p class="qd-empty">No active pairings.</p></div>
<?php else: ?>
              <div class="qd-table-wrap">
                <table class="qd-table qd-table--quiet">
                  <caption class="av-sr">Active pairings and their kind</caption>
                  <thead><tr><th scope="col">Mentor</th><th scope="col">Mentee</th><th scope="col">Kind</th></tr></thead>
                  <tbody>
<?php foreach ($pairs as $pr): ?>
                    <tr><td><?= e((string) $pr['mentor_name']) ?></td><td><?= e((string) $pr['mentee_name']) ?></td>
                      <td><form class="qd-inline" method="post" action="/mentorship/conduct.php" data-qd-ajax data-qd-autosubmit>
                        <input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="op" value="kind"><input type="hidden" name="pairing_id" value="<?= (int) $pr['id'] ?>">
                        <label><span class="av-sr">Kind of mentorship for <?= e((string) $pr['mentee_name']) ?></span>
                          <select name="kind"><?php foreach (Conduct::MENTOR_KINDS as $k => $l): ?><option value="<?= e($k) ?>"<?= ($pr['kind'] ?? 'academy') === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></label>
                        <button class="avp-btn avp-btn--sq qd-nojs" type="submit">Save</button>
                      </form></td></tr>
<?php endforeach; ?>
                  </tbody>
                </table>
              </div>
<?php endif; ?>
            </section>
          </div>
        </div>
      </section>
