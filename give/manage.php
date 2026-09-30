<?php
/**
 * give/manage.php — the appeals console. Staff only.
 *
 * Everything an appeal needs, in the order somebody actually works: post an
 * appeal, say what today costs, tell people what the money did. The three are
 * separate because they happen at different rhythms — an appeal is written
 * once, a need is typed each morning, an update is posted when there is
 * something to say — and a single mega-form would make the daily job the
 * expensive one.
 *
 * Admin-gated exactly like the NGV console: av_admin_role() for the page, and
 * same-origin + CSRF + a per-user rate limit on every write.
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/bootstrap.php';

$role    = function_exists('av_admin_role') ? av_admin_role() : '';
$isAdmin = $role !== '';
$method  = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$actor   = function_exists('av_admin_actor') ? av_admin_actor() : 'admin';

/* ── image upload ────────────────────────────────────────────────────────
   A file arrives as multipart/form-data, which cannot be json_decode'd, so it
   is taken here before the JSON dispatcher below reads the request body. */
if ($method === 'POST' && !empty($_FILES['image'])) {
    if (!$isAdmin) json_out(['ok' => false, 'error' => 'Admin sign-in required.'], 403);
    $uid = (int) (class_exists('LmsAuth') && LmsAuth::user() ? LmsAuth::user()['id'] : 0);
    av_require_write($uid, 'give_manage', 120, 600);
    $r = Appeals::setItemImage((int) ($_POST['item_id'] ?? 0), $_FILES['image'], $actor);
    json_out($r, empty($r['ok']) ? 400 : 200);
}

/* ── JSON actions ────────────────────────────────────────────────────────── */
if ($method === 'POST') {
    if (!$isAdmin) json_out(['ok' => false, 'error' => 'Admin sign-in required.'], 403);
    $uid = (int) (class_exists('LmsAuth') && LmsAuth::user() ? LmsAuth::user()['id'] : 0);
    av_require_write($uid, 'give_manage', 120, 600);

    $in  = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($in)) $in = [];
    $act = (string) ($in['action'] ?? '');

    switch ($act) {
        case 'save_appeal': {
            $id = Appeals::save($in, $actor);
            if ($id <= 0) json_out(['ok' => false, 'error' => 'Give the appeal a title before saving.'], 400);
            $a = Appeals::byId($id);
            json_out(['ok' => true, 'id' => $id, 'slug' => (string) $a['slug'], 'url' => Appeals::url($a)]);
        }
        case 'set_status': {
            $ok = Appeals::setStatus((int) ($in['id'] ?? 0), (string) ($in['status'] ?? ''), $actor);
            json_out(['ok' => $ok, 'error' => $ok ? '' : 'That status is not one this page knows.'], $ok ? 200 : 400);
        }
        case 'delete_appeal': {
            $r = Appeals::delete((int) ($in['id'] ?? 0), $actor);
            json_out($r, empty($r['ok']) ? 400 : 200);
        }
        case 'post_need': {
            $r = Appeals::postNeed((int) ($in['appeal_id'] ?? 0), $in, $actor);
            json_out($r, empty($r['ok']) ? 400 : 200);
        }
        case 'save_item': {
            $r = Appeals::saveItem($in, $actor);
            json_out($r, empty($r['ok']) ? 400 : 200);
        }
        case 'fund_item': {
            $r = Appeals::fundItem((int) ($in['item_id'] ?? 0), (int) ($in['qty'] ?? 1), $actor);
            json_out($r, empty($r['ok']) ? 400 : 200);
        }
        case 'clear_item_image': {
            $r = Appeals::clearItemImage((int) ($in['item_id'] ?? 0), $actor);
            json_out($r, empty($r['ok']) ? 400 : 200);
        }
        case 'delete_item': {
            $ok = Appeals::deleteItem((int) ($in['item_id'] ?? 0));
            json_out(['ok' => $ok, 'error' => $ok ? '' : 'No such item.'], $ok ? 200 : 400);
        }
        case 'meet_need': {
            $ok = Appeals::meetNeed((int) ($in['need_id'] ?? 0), $in['amount'] ?? null, $actor);
            json_out(['ok' => $ok, 'error' => $ok ? '' : 'No such need.'], $ok ? 200 : 400);
        }
        case 'delete_need': {
            json_out(['ok' => Appeals::deleteNeed((int) ($in['need_id'] ?? 0))]);
        }
        case 'post_update': {
            $r = Appeals::postUpdate((int) ($in['appeal_id'] ?? 0), $in, $actor);
            json_out($r, empty($r['ok']) ? 400 : 200);
        }
        case 'delete_update': {
            json_out(['ok' => Appeals::deleteUpdate((int) ($in['update_id'] ?? 0))]);
        }
        case 'mail_update': {
            $r = Appeals::mailUpdate((int) ($in['update_id'] ?? 0));
            json_out($r, empty($r['ok']) ? 400 : 200);
        }
        case 'allocate_ngv': {
            $uidA = (int) (class_exists('LmsAuth') && LmsAuth::user() ? LmsAuth::user()['id'] : 0);
            $r = Appeals::allocateToVanguards((int) ($in['id'] ?? 0), (int) ($in['amount'] ?? 0), $uidA, $actor);
            json_out($r, empty($r['ok']) ? 400 : 200);
        }
        case 'stop_recurring': {
            $r = Appeals::stopRecurring((string) ($in['sub_code'] ?? ''), $actor);
            json_out($r, empty($r['ok']) ? 400 : 200);
        }
        case 'save_tiers': {
            $ok = Appeals::saveTiers((int) ($in['appeal_id'] ?? 0), is_array($in['tiers'] ?? null) ? $in['tiers'] : []);
            json_out(['ok' => $ok]);
        }
    }
    json_out(['ok' => false, 'error' => 'Unknown action.'], 400);
}

header('Content-Type: text/html; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');
if (function_exists('send_security_headers')) send_security_headers('admin');

$e = 'e';
$csrf    = $isAdmin && function_exists('av_csrf_token') ? av_csrf_token() : '';
$appeals = $isAdmin ? Appeals::all('', 300) : [];
$sel     = null; $selNeeds = []; $selUpdates = []; $selTiers = [];
$selItems = []; $standingItems = Appeals::items(['appeal_id' => 0, 'limit' => 200]);
$selId   = (int) ($_GET['a'] ?? 0);
if ($isAdmin && $selId > 0) {
    $sel = Appeals::byId($selId);
    if ($sel) {
        $selNeeds   = Appeals::needsFor($selId, 60);
        $selItems   = Appeals::items(['appeal_id' => $selId, 'limit' => 200]);
        $selUpdates = Appeals::updatesFor($selId, 30);
        $selTiers   = Appeals::tiersFor($selId);
        $selRec     = Appeals::recurringFor($selId);
        $selAlloc   = Appeals::allocations($selId, 50);
        $selSpare   = Appeals::unallocated($sel);
        $selShort   = Appeals::ngvShortfall();
        $selMailTo  = Appeals::updateRecipients($sel);
    }
}
$summary = $isAdmin ? Appeals::summary() : ['appeals' => 0, 'raised' => 0, 'goal' => 0, 'donors' => 0];
?><!doctype html>
<html lang="en-NG">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Appeals · Afrovanguard Studio</title>
<link href="https://fonts.googleapis.com/css2?family=Cormorant:wght@600;700&family=Montserrat:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link href="/assets/site/tokens.css" rel="stylesheet">
<link href="/give/manage.css" rel="stylesheet">
</head>
<body>
<?php if (!$isAdmin): ?>
  <main class="gm-gate">
    <h1>Appeals</h1>
    <p>This page is for Afrovanguard staff. Sign in to the Studio to manage appeals.</p>
    <p><a class="gm-btn gm-primary" href="/admin/">Go to the Studio</a></p>
  </main>
<?php else: ?>
<header class="gm-topbar">
  <div class="gm-brand"><span class="wm">Afrovanguard</span> <span class="tag">Appeals</span></div>
  <div class="gm-actions">
    <a class="gm-btn gm-ghost" href="/give/" target="_blank" rel="noopener">View public page ↗</a>
    <button class="gm-btn gm-primary" id="newAppeal">New appeal</button>
  </div>
</header>

<main class="gm-main">
  <div class="gm-strip">
    <div><div class="k">Live appeals</div><div class="v"><?= (int) $summary['appeals'] ?></div></div>
    <div><div class="k">Raised</div><div class="v"><?= $e(Appeals::naira((int) $summary['raised'])) ?></div></div>
    <?php /* Offline gifts carry no donor count, so this must not read "0"
             beside a real total — the same contradiction the public pages had. */ ?>
    <div><div class="k">Donors</div><div class="v"><?= $summary['donors'] > 0
        ? number_format((int) $summary['donors'])
        : '<span style="font-size:var(--afg-text-sm);color:var(--afg-muted);font-family:var(--afg-font-body)">none online yet</span>' ?></div></div>
    <div><div class="k">Aiming for</div><div class="v"><?= $e(Appeals::naira((int) $summary['goal'])) ?></div></div>
  </div>

  <div class="gm-cols">
    <!-- ── the list ───────────────────────────────────────────────────── -->
    <section class="gm-list" aria-label="All appeals">
      <h2 class="gm-h2">All appeals <span class="gm-count"><?= count($appeals) ?></span></h2>
      <?php if (!$appeals): ?>
        <div class="gm-empty">
          <p><strong>No appeals yet.</strong></p>
          <p>Start one and it gets a public page, a share card, a QR poster and its own line in the sitemap.</p>
        </div>
      <?php endif; ?>
      <?php foreach ($appeals as $a): $st = Appeals::state($a); ?>
        <a class="gm-row<?= $a['id'] === $selId ? ' is-sel' : '' ?>" href="?a=<?= (int) $a['id'] ?>">
          <div class="gm-row-top">
            <span class="gm-title"><?= $e((string) $a['title']) ?></span>
            <span class="gm-pill is-<?= $e((string) $a['status']) ?>"><?= $e((string) $a['status']) ?></span>
          </div>
          <div class="gm-row-meta">
            <?= $e(Appeals::naira($st['raised'])) ?><?php if ($st['goal'] > 0): ?> of <?= $e(Appeals::naira($st['goal'])) ?><?php endif; ?>
            <?php if ($st['donors'] > 0): ?> · <?= (int) $st['donors'] ?> donor<?= $st['donors'] === 1 ? '' : 's' ?>
            <?php elseif ($st['offline'] > 0): ?> · offline<?php endif; ?>
            <?php if ($st['days_left'] !== null && $st['days_left'] >= 0): ?> · <?= (int) $st['days_left'] ?>d left<?php endif; ?>
          </div>
          <?php if ($st['percent'] !== null): ?>
            <div class="gm-bar"><span style="width:<?= (int) $st['percent'] ?>%"></span></div>
          <?php endif; ?>
        </a>
      <?php endforeach; ?>
    </section>

    <!-- ── the editor ─────────────────────────────────────────────────── -->
    <section class="gm-panel" aria-label="Edit appeal">
      <?php if (!$sel): ?>
        <div class="gm-empty gm-empty-lg">
          <h2>Pick an appeal, or start a new one</h2>
          <p>An appeal is one specific ask with a story and a number. Once it is live it has a public page,
             a WhatsApp share card, a printable QR poster and an embeddable widget — all generated, none to maintain.</p>
        </div>
      <?php else: $st = Appeals::state($sel); ?>
        <div class="gm-panel-head">
          <div>
            <h2 class="gm-h2" id="selTitle"><?= $e((string) $sel['title']) ?></h2>
            <p class="gm-sub">
              <a href="<?= $e(Appeals::url($sel)) ?>" target="_blank" rel="noopener">/give/<?= $e((string) $sel['slug']) ?>/</a>
              · <a href="/give/<?= $e((string) $sel['slug']) ?>/poster" target="_blank" rel="noopener">poster</a>
              · <a href="/give/<?= $e((string) $sel['slug']) ?>/embed" target="_blank" rel="noopener">embed</a>
              · <a href="<?= $e(Appeals::ogUrl($sel)) ?>" target="_blank" rel="noopener">share card</a>
              · <?= (int) $sel['view_count'] ?> views · <?= (int) $sel['share_count'] ?> shares
            </p>
          </div>
          <div class="gm-status-set" role="group" aria-label="Status">
            <?php foreach (Appeals::STATUSES as $s): ?>
              <button type="button" class="gm-chip<?= (string) $sel['status'] === $s ? ' is-on' : '' ?>"
                      data-status="<?= $e($s) ?>"><?= $e($s) ?></button>
            <?php endforeach; ?>
          </div>
        </div>

        <div class="gm-tabs" role="tablist">
          <button class="gm-tab is-on" role="tab" aria-selected="true"  aria-controls="t-appeal" id="tab-appeal">The appeal</button>
          <button class="gm-tab" role="tab" aria-selected="false" aria-controls="t-needs"  id="tab-needs">Needs <span class="gm-count"><?= count($selNeeds) ?></span></button>
          <button class="gm-tab" role="tab" aria-selected="false" aria-controls="t-items" id="tab-items">Items <span class="gm-count"><?= count($selItems) + count($standingItems) ?></span></button>
          <button class="gm-tab" role="tab" aria-selected="false" aria-controls="t-updates" id="tab-updates">Updates <span class="gm-count"><?= count($selUpdates) ?></span></button>
          <button class="gm-tab" role="tab" aria-selected="false" aria-controls="t-tiers"  id="tab-tiers">Tiers <span class="gm-count"><?= count($selTiers) ?></span></button>
          <button class="gm-tab" role="tab" aria-selected="false" aria-controls="t-donors" id="tab-donors">Recurring <span class="gm-count"><?= (int) $selRec['count'] ?></span></button>
          <?php if (!empty($sel['funds_ngv'])): ?>
            <button class="gm-tab" role="tab" aria-selected="false" aria-controls="t-ngv" id="tab-ngv">Sponsorship <span class="gm-count"><?= (int) $selAlloc['participants'] ?></span></button>
          <?php endif; ?>
        </div>

        <!-- The appeal itself -->
        <div class="gm-tabpane" id="t-appeal" role="tabpanel" aria-labelledby="tab-appeal">
          <form id="appealForm" class="gm-form" autocomplete="off">
            <input type="hidden" name="id" value="<?= (int) $sel['id'] ?>">
            <label class="gm-field gm-span2"><span>Title</span>
              <input name="title" value="<?= $e((string) $sel['title']) ?>" maxlength="160" required></label>
            <label class="gm-field gm-span2"><span>Tagline <em>one sentence, shown under the title and in search results</em></span>
              <input name="tagline" value="<?= $e((string) $sel['tagline']) ?>" maxlength="240"></label>
            <label class="gm-field"><span>Kind</span>
              <select name="kind"><?php foreach (Appeals::KINDS as $k): ?>
                <option value="<?= $e($k) ?>"<?= (string) $sel['kind'] === $k ? ' selected' : '' ?>><?= $e($k) ?></option>
              <?php endforeach; ?></select></label>
            <label class="gm-field"><span>Programme <em>puts this appeal on that project's own page</em></span>
              <select name="project"><?php foreach (Appeals::projects() as $pk => $pl): ?>
                <option value="<?= $e($pk) ?>"<?= (string) $sel['project'] === $pk ? ' selected' : '' ?>><?= $e($pl) ?></option>
              <?php endforeach; ?></select></label>
            <label class="gm-field"><span>Pays NGV training fees <em>lets you hand the money to participants who are behind</em></span>
              <select name="funds_ngv">
                <option value="0"<?= empty($sel['funds_ngv']) ? ' selected' : '' ?>>No</option>
                <option value="1"<?= !empty($sel['funds_ngv']) ? ' selected' : '' ?>>Yes — a sponsorship appeal</option>
              </select></label>
            <label class="gm-field"><span>Goal (₦) <em>0 means an open appeal with no finish line</em></span>
              <input name="goal_ngn" type="number" min="0" step="1000" value="<?= (int) $sel['goal_ngn'] ?>"></label>
            <label class="gm-field"><span>Starts</span>
              <input name="starts_on" type="date" value="<?= $e((string) $sel['starts_on']) ?>"></label>
            <label class="gm-field"><span>Ends <em>drives "days left"</em></span>
              <input name="ends_on" type="date" value="<?= $e((string) $sel['ends_on']) ?>"></label>
            <label class="gm-field"><span>Who it helps</span>
              <input name="beneficiary" value="<?= $e((string) $sel['beneficiary']) ?>" maxlength="160"></label>
            <label class="gm-field"><span>Location</span>
              <input name="location" value="<?= $e((string) $sel['location']) ?>" maxlength="120"></label>
            <label class="gm-field gm-span2"><span>Cover image URL</span>
              <input name="cover_url" value="<?= $e((string) $sel['cover_url']) ?>" maxlength="500" placeholder="/uploads/… or https://…"></label>
            <label class="gm-field gm-span2"><span>Video <em>YouTube or Vimeo — shown instead of the cover</em></span>
              <input name="video_url" value="<?= $e((string) $sel['video_url']) ?>" maxlength="500"></label>
            <label class="gm-field gm-span2"><span>Gallery <em>one image URL per line, up to 12</em></span>
              <textarea name="gallery" rows="3"><?= $e(implode("\n", (array) $sel['gallery'])) ?></textarea></label>
            <label class="gm-field gm-span2"><span>The story <em>Markdown — headings, lists and links all work</em></span>
              <textarea name="story" rows="12"><?= $e((string) $sel['story']) ?></textarea></label>

            <fieldset class="gm-fieldset gm-span2">
              <legend>Matched giving</legend>
              <p class="gm-hint">A sponsor pledging to double what the public gives is the strongest thing on a
                fundraising page after the story. It is a pledge, so it is never added to the raised total.</p>
              <div class="gm-grid3">
                <label class="gm-field"><span>Match ceiling (₦)</span>
                  <input name="match_ngn" type="number" min="0" step="1000" value="<?= (int) $sel['match_ngn'] ?>"></label>
                <label class="gm-field"><span>Sponsor</span>
                  <input name="match_sponsor" value="<?= $e((string) $sel['match_sponsor']) ?>" maxlength="120"></label>
                <label class="gm-field"><span>Matching until</span>
                  <input name="match_until" type="date" value="<?= $e((string) $sel['match_until']) ?>"></label>
              </div>
            </fieldset>

            <fieldset class="gm-fieldset gm-span2">
              <legend>Event details <em>used only when the kind is “event” — this is what puts it in Google's event listings</em></legend>
              <div class="gm-grid3">
                <label class="gm-field"><span>Starts</span>
                  <input name="event_start" type="datetime-local" value="<?= $e(str_replace(' ', 'T', (string) $sel['event_start'])) ?>"></label>
                <label class="gm-field"><span>Ends</span>
                  <input name="event_end" type="datetime-local" value="<?= $e(str_replace(' ', 'T', (string) $sel['event_end'])) ?>"></label>
                <label class="gm-field"><span>Venue</span>
                  <input name="event_venue" value="<?= $e((string) $sel['event_venue']) ?>" maxlength="200"></label>
              </div>
            </fieldset>

            <fieldset class="gm-fieldset gm-span2">
              <legend>Money recorded by hand</legend>
              <p class="gm-hint">Cash, a branch transfer, a gift in kind you have valued. Kept separate from what
                Paystack verified and shown separately on the page, so the total can always be broken back apart.</p>
              <div class="gm-grid3">
                <label class="gm-field"><span>Offline raised (₦)</span>
                  <input name="offline_ngn" type="number" min="0" step="500" value="<?= (int) $sel['offline_ngn'] ?>"></label>
                <label class="gm-field"><span>Spent so far (₦)</span>
                  <input name="spent_ngn" type="number" min="0" step="500" value="<?= (int) $sel['spent_ngn'] ?>"></label>
                <label class="gm-field"><span>Urgent</span>
                  <select name="urgent"><option value="0"<?= !$sel['urgent'] ? ' selected' : '' ?>>No</option>
                    <option value="1"<?= $sel['urgent'] ? ' selected' : '' ?>>Yes — badge it</option></select></label>
              </div>
              <label class="gm-field"><span>What the money has done</span>
                <textarea name="spend_note" rows="3"><?= $e((string) $sel['spend_note']) ?></textarea></label>
            </fieldset>

            <fieldset class="gm-fieldset gm-span2">
              <legend>Search &amp; sharing</legend>
              <p class="gm-hint">All three are optional — left blank, the title and tagline are used and the
                progress figures are appended automatically.</p>
              <label class="gm-field"><span>SEO title <em>65 characters</em></span>
                <input name="seo_title" value="<?= $e((string) $sel['seo_title']) ?>" maxlength="70"></label>
              <label class="gm-field"><span>Meta description <em>155 characters</em></span>
                <input name="seo_desc" value="<?= $e((string) $sel['seo_desc']) ?>" maxlength="200"></label>
              <label class="gm-field"><span>Keywords</span>
                <input name="keywords" value="<?= $e((string) $sel['keywords']) ?>" maxlength="300"></label>
            </fieldset>

            <div class="gm-form-foot gm-span2">
              <button type="submit" class="gm-btn gm-primary">Save appeal</button>
              <button type="button" class="gm-btn gm-danger" id="deleteAppeal">Delete</button>
              <span class="gm-saved" id="appealSaved" role="status" aria-live="polite"></span>
            </div>
          </form>
        </div>

        <!-- Needs -->
        <div class="gm-tabpane" id="t-needs" role="tabpanel" aria-labelledby="tab-needs" hidden>
          <form id="needForm" class="gm-form gm-form-inline">
            <input type="hidden" name="appeal_id" value="<?= (int) $sel['id'] ?>">
            <label class="gm-field"><span>When</span>
              <select name="cadence"><option value="daily">Today (daily)</option>
                <option value="weekly">This week (weekly)</option>
                <option value="once">One-off</option></select></label>
            <label class="gm-field"><span>For the date</span><input name="date" type="date" value="<?= $e(av_today_tz()) ?>"></label>
            <label class="gm-field gm-span2"><span>What is needed</span>
              <input name="title" maxlength="160" placeholder="Hot meals for the drilling team" required></label>
            <label class="gm-field"><span>Unit</span><input name="unit_label" maxlength="60" placeholder="hot meals"></label>
            <label class="gm-field"><span>Cost each (₦)</span><input name="unit_cost" type="number" min="0" step="50"></label>
            <label class="gm-field"><span>How many</span><input name="units_target" type="number" min="0" step="1"></label>
            <label class="gm-field"><span>…or a flat figure (₦)</span><input name="target_ngn" type="number" min="0" step="500"></label>
            <label class="gm-field gm-span2"><span>Detail</span><textarea name="detail" rows="2" maxlength="1200"></textarea></label>
            <div class="gm-form-foot gm-span2">
              <button type="submit" class="gm-btn gm-primary">Post this need</button>
              <span class="gm-hint">Posting again for the same day replaces it rather than adding a second.</span>
            </div>
          </form>
          <div class="gm-needs">
            <?php if (!$selNeeds): ?><div class="gm-empty"><p>No needs posted yet. A daily need is the single
              most effective thing on the page — “₦20,000 today for 40 hot meals” is a decision somebody can
              make in one breath.</p></div><?php endif; ?>
            <?php foreach ($selNeeds as $n): ?>
              <div class="gm-need<?= $n['status'] === 'met' ? ' is-met' : ($n['lapsed'] ? ' is-lapsed' : '') ?>">
                <div>
                  <div class="gm-need-when"><?= $e((string) $n['cadence']) ?> · <?= $e((string) $n['period']) ?><?php
                    if ($n['status'] === 'met'): ?> · met<?php elseif ($n['lapsed']): ?> · lapsed<?php endif; ?></div>
                  <div class="gm-need-title"><?= $e((string) $n['title']) ?></div>
                  <div class="gm-need-fig"><?= $e(Appeals::naira((int) $n['target_ngn'])) ?><?php
                    if ($n['units_target'] > 0 && $n['unit_label'] !== ''): ?>
                    <span class="gm-hint">— <?= (int) $n['units_target'] ?> <?= $e((string) $n['unit_label']) ?>
                      at <?= $e(Appeals::naira((int) $n['unit_cost'])) ?></span><?php endif; ?></div>
                </div>
                <div class="gm-need-act">
                  <?php if ($n['status'] !== 'met'): ?>
                    <button class="gm-btn gm-ghost gm-sm" data-meet="<?= (int) $n['id'] ?>">Mark met</button>
                  <?php endif; ?>
                  <button class="gm-btn gm-ghost gm-sm" data-delneed="<?= (int) $n['id'] ?>">Delete</button>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>

        <!-- Items: the concrete things, priced and counted -->
        <div class="gm-tabpane" id="t-items" role="tabpanel" aria-labelledby="tab-items" hidden>
          <form id="itemForm" class="gm-form gm-form-inline">
            <label class="gm-field"><span>Kind</span>
              <select name="kind" id="itemKind">
                <option value="money">Priced — somebody funds it</option>
                <option value="goods">In kind — somebody gives the thing</option>
              </select></label>
            <label class="gm-field"><span>Belongs to</span>
              <select name="appeal_id">
                <option value="<?= (int) $sel['id'] ?>">This appeal — <?= $e(mb_substr((string) $sel['title'], 0, 40)) ?></option>
                <option value="0">Standing — the whole organisation</option>
              </select></label>
            <label class="gm-field gm-span2"><span>What it is</span>
              <input name="title" maxlength="160" placeholder="A term&rsquo;s exercise books for one child" required></label>
            <label class="gm-field"><span>Category</span>
              <input name="category" maxlength="60" placeholder="Classroom" list="itemCats">
              <datalist id="itemCats"><?php foreach (array_keys(Appeals::itemsByCategory(['limit' => 300])) as $c): ?>
                <option value="<?= $e((string) $c) ?>"><?php endforeach; ?></datalist></label>
            <label class="gm-field"><span>Cost each (₦) <em>priced items only</em></span>
              <input name="unit_cost" id="itemCost" type="number" min="0" step="50"></label>
            <label class="gm-field"><span>Unit</span><input name="unit_label" maxlength="40" placeholder="set"></label>
            <label class="gm-field"><span>How many needed</span><input name="qty_needed" type="number" min="0" step="1" value="1"></label>
            <label class="gm-field"><span>Order</span><input name="sort" type="number" min="0" step="1" value="0"></label>
            <label class="gm-field gm-span2"><span>Detail</span>
              <textarea name="detail" rows="2" maxlength="800" placeholder="Any working condition — we service them before they go out."></textarea></label>
            <div class="gm-form-foot gm-span2">
              <button type="submit" class="gm-btn gm-primary">Add this item</button>
              <span class="gm-hint">A priced item needs its price. Things given in kind are “in kind” and carry no figure.</span>
            </div>
          </form>

          <?php
          /* Both lists live here rather than behind another level of
             navigation: an item is either this appeal's or the
             organisation's, and somebody adding one wants to see both without
             hunting for the other page. */
          $itemBlocks = [
            ['For this appeal', $selItems, 'Nothing yet. An item is the bit a donor actually understands — “₦4,500, a term&rsquo;s exercise books, 40 needed”.'],
            ['Standing — shows on the donate page', $standingItems, 'Nothing standing yet. These are the organisation&rsquo;s running needs: laptops, chairs, a generator.'],
          ];
          foreach ($itemBlocks as [$blockTitle, $blockItems, $blockEmpty]): ?>
            <h4 class="gm-subhead"><?= $e($blockTitle) ?> <span class="gm-count"><?= count($blockItems) ?></span></h4>
            <div class="gm-items">
              <?php if (!$blockItems): ?><div class="gm-empty"><p><?= $blockEmpty ?></p></div><?php endif; ?>
              <?php foreach ($blockItems as $it): ?>
                <div class="gm-item<?= $it['status'] === 'funded' ? ' is-met' : '' ?>" data-item="<?= (int) $it['id'] ?>">
                  <?php if (trim((string) $it['image_url']) !== ''): ?>
                    <img class="gm-item-img" src="<?= $e((string) $it['image_url']) ?>" alt="" width="64" height="64" loading="lazy">
                  <?php endif; ?>
                  <div>
                    <div class="gm-need-when"><?= $e((string) ($it['category'] !== '' ? $it['category'] : 'Uncategorised')) ?>
                      · <?= $it['kind'] === 'money' ? 'priced' : 'in kind' ?><?php
                      if ($it['status'] === 'funded'): ?> · covered<?php endif; ?></div>
                    <div class="gm-need-title"><?= $e((string) $it['title']) ?></div>
                    <div class="gm-need-fig">
                      <?php if ($it['kind'] === 'money' && $it['unit_cost'] > 0): ?>
                        <?= $e(Appeals::naira((int) $it['unit_cost'])) ?><?php
                        if ($it['unit_label'] !== ''): ?> <span class="gm-hint">each <?= $e((string) $it['unit_label']) ?></span><?php endif; ?>
                      <?php else: ?><span class="gm-hint">given in kind</span><?php endif; ?>
                      <?php if ($it['qty_needed'] > 0): ?>
                        <span class="gm-hint">— <?= (int) $it['qty_funded'] ?> of <?= (int) $it['qty_needed'] ?>
                          (<?= (int) $it['qty_left'] ?> to go)</span>
                      <?php endif; ?>
                    </div>
                  </div>
                  <div class="gm-need-act">
                    <?php /* The picture lives on the row it belongs to, so
                             there is no separate "media" step to forget. */ ?>
                    <label class="gm-btn gm-ghost gm-sm gm-upl">
                      <?= trim((string) $it['image_url']) !== '' ? 'Replace picture' : 'Add picture' ?>
                      <input type="file" accept="image/jpeg,image/png,image/webp,image/heic"
                             data-itemimg="<?= (int) $it['id'] ?>" hidden>
                    </label>
                    <?php if (trim((string) $it['image_url']) !== ''): ?>
                      <button class="gm-btn gm-ghost gm-sm" data-imgclear="<?= (int) $it['id'] ?>">Remove picture</button>
                    <?php endif; ?>
                    <?php if ($it['is_open']): ?>
                      <button class="gm-btn gm-ghost gm-sm" data-funditem="<?= (int) $it['id'] ?>">+1 covered</button>
                    <?php endif; ?>
                    <button class="gm-btn gm-ghost gm-sm" data-hideitem="<?= (int) $it['id'] ?>"
                      data-status="<?= $it['status'] === 'hidden' ? 'live' : 'hidden' ?>"><?= $it['status'] === 'hidden' ? 'Show' : 'Hide' ?></button>
                    <button class="gm-btn gm-ghost gm-sm" data-delitem="<?= (int) $it['id'] ?>">Delete</button>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endforeach; ?>
        </div>

        <!-- Updates -->
        <div class="gm-tabpane" id="t-updates" role="tabpanel" aria-labelledby="tab-updates" hidden>
          <form id="updateForm" class="gm-form">
            <input type="hidden" name="appeal_id" value="<?= (int) $sel['id'] ?>">
            <label class="gm-field"><span>Kind</span>
              <select name="kind"><option value="update">Update</option><option value="milestone">Milestone</option>
                <option value="thanks">Thank you</option><option value="spend">What we spent</option></select></label>
            <label class="gm-field"><span>Amount (₦) <em>for a spend update</em></span>
              <input name="amount_ngn" type="number" min="0" step="500"></label>
            <label class="gm-field gm-span2"><span>Title</span><input name="title" maxlength="160"></label>
            <label class="gm-field gm-span2"><span>Body <em>Markdown</em></span><textarea name="body" rows="5"></textarea></label>
            <label class="gm-field gm-span2"><span>Image URL</span><input name="image_url" maxlength="500"></label>
            <div class="gm-form-foot gm-span2"><button type="submit" class="gm-btn gm-primary">Post update</button></div>
          </form>
          <p class="gm-hint" style="margin-top:var(--afg-space-4)">
            <strong><?= count($selMailTo) ?></strong>
            <?= count($selMailTo) === 1 ? 'person has' : 'people have' ?> given to this appeal and can be emailed.
            Only people who gave to <em>this</em> appeal are on that list, and anyone who has unsubscribed is off it.
            Sending is batched — the first <?= 60 ?> go now and the cron finishes the rest, so a milestone announcement
            cannot spend the whole hour's mail allowance.
          </p>
          <div class="gm-updates">
            <?php if (!$selUpdates): ?><div class="gm-empty"><p>No updates yet. An appeal that reports what the
              money did is the one people give to a second time.</p></div><?php endif; ?>
            <?php foreach ($selUpdates as $u): ?>
              <div class="gm-need">
                <div>
                  <div class="gm-need-when"><?= $e((string) $u['kind']) ?> · <?= $e(date('j M Y', (int) strtotime((string) $u['created_at']))) ?><?php
                    if ((int) $u['amount_ngn'] > 0): ?> · <?= $e(Appeals::naira((int) $u['amount_ngn'])) ?><?php endif; ?></div>
                  <div class="gm-need-title"><?= $e((string) $u['title']) ?></div>
                </div>
                <div class="gm-need-act">
                  <button class="gm-btn gm-ghost gm-sm" data-mail="<?= (int) $u['id'] ?>"
                          title="Email the people who gave to this appeal">Email donors</button>
                  <button class="gm-btn gm-ghost gm-sm" data-delupdate="<?= (int) $u['id'] ?>">Delete</button>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>

        <!-- Recurring givers -->
        <div class="gm-tabpane" id="t-donors" role="tabpanel" aria-labelledby="tab-donors" hidden>
          <div class="gm-strip" style="margin-bottom:var(--afg-space-5)">
            <div><div class="k">Active pledges</div><div class="v"><?= (int) $selRec['count'] ?></div></div>
            <div><div class="k">Worth per year</div><div class="v"><?= $e(Appeals::naira((int) $selRec['annualised'])) ?></div></div>
            <div><div class="k">Collected so far</div><div class="v"><?= $e(Appeals::naira((int) $selRec['collected'])) ?></div></div>
          </div>
          <?php if (!$selRec['rows']): ?>
            <div class="gm-empty">
              <p><strong>No recurring gifts yet.</strong></p>
              <p>The appeal page offers monthly, quarterly and yearly alongside a one-off gift.
                 A recurring giver is worth many times a single donation, and the ask costs nothing extra.</p>
            </div>
          <?php endif; ?>
          <?php foreach ($selRec['rows'] as $sub): ?>
            <div class="gm-need">
              <div>
                <div class="gm-need-when"><?= $e((string) $sub['interval_k']) ?> ·
                  <?= (int) $sub['charges'] ?> collected ·
                  since <?= $e(substr((string) $sub['started_at'], 0, 10)) ?></div>
                <div class="gm-need-title"><?= $e((string) ($sub['name'] ?: $sub['email'])) ?></div>
                <div class="gm-need-fig"><?= $e(Appeals::naira((int) $sub['amount_ngn'])) ?>
                  <span class="gm-hint">— <?= $e(Appeals::naira((int) $sub['total_ngn'])) ?> in total so far</span></div>
              </div>
              <div class="gm-need-act">
                <button class="gm-btn gm-ghost gm-sm" data-stopsub="<?= $e((string) $sub['sub_code']) ?>">Cancel</button>
              </div>
            </div>
          <?php endforeach; ?>
        </div>

        <?php if (!empty($sel['funds_ngv'])): ?>
        <!-- Sponsorship: hand appeal money to NGV participants who are behind -->
        <div class="gm-tabpane" id="t-ngv" role="tabpanel" aria-labelledby="tab-ngv" hidden>
          <div class="gm-strip" style="margin-bottom:var(--afg-space-5)">
            <div><div class="k">Held by this appeal</div><div class="v"><?= $e(Appeals::naira((int) $selSpare)) ?></div></div>
            <div><div class="k">Vanguards behind</div><div class="v"><?= (int) $selShort['participants'] ?></div></div>
            <div><div class="k">Due right now</div><div class="v"><?= $e(Appeals::naira((int) $selShort['outstanding'])) ?></div></div>
            <div><div class="k">Already handed on</div><div class="v"><?= $e(Appeals::naira((int) $selAlloc['total'])) ?></div></div>
          </div>
          <p class="gm-hint">
            Allocating posts a real payment on each participant's NGV account — their balance moves and they
            get a receipt, exactly as if they had paid it themselves. The money goes to whoever is furthest
            behind first, because spreading it evenly leaves everybody still short and nobody actually through.
            <br><br>
            <strong>Only what is currently due can be paid.</strong> Training fees bill monthly, so an appeal
            holding <?= $e(Appeals::naira((int) $selSpare)) ?> may only be able to place
            <?= $e(Appeals::naira((int) min($selSpare, $selShort['outstanding']))) ?> today. The rest stays here for
            next month, which is better than paying ahead and locking it to somebody who may not finish.
          </p>
          <form id="allocForm" class="gm-form gm-form-inline" style="margin-top:var(--afg-space-4)">
            <label class="gm-field"><span>Hand on (₦)</span>
              <input name="amount" type="number" min="0" step="1000"
                     max="<?= (int) $selSpare ?>" value="<?= (int) min($selSpare, $selShort['outstanding']) ?>"></label>
            <div class="gm-form-foot">
              <button type="submit" class="gm-btn gm-primary"
                      <?= $selSpare <= 0 || (int) $selShort['outstanding'] <= 0 ? 'disabled' : '' ?>>Allocate to Vanguards</button>
              <?php if ($selSpare <= 0): ?><span class="gm-hint">Nothing left to allocate.</span>
              <?php elseif ((int) $selShort['outstanding'] <= 0): ?><span class="gm-hint">Nobody is behind on their fees right now.</span><?php endif; ?>
            </div>
          </form>
          <div class="gm-needs">
            <?php if (!$selAlloc['rows']): ?>
              <div class="gm-empty"><p>Nothing handed on yet.</p></div>
            <?php endif; ?>
            <?php foreach ($selAlloc['rows'] as $al): ?>
              <div class="gm-need is-met">
                <div>
                  <div class="gm-need-when"><?= $e(substr((string) $al['created_at'], 0, 16)) ?></div>
                  <div class="gm-need-title">Participant #<?= (int) $al['member_id'] ?></div>
                  <div class="gm-need-fig"><?= $e(Appeals::naira((int) $al['amount_ngn'])) ?></div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
        <?php endif; ?>

        <!-- Tiers -->
        <div class="gm-tabpane" id="t-tiers" role="tabpanel" aria-labelledby="tab-tiers" hidden>
          <p class="gm-hint">A figure with a consequence attached. Somebody deciding what to give is asking
            “what does this buy” — a bare row of amounts makes them do that arithmetic themselves.</p>
          <div id="tierRows">
            <?php foreach ($selTiers ?: [['amount_ngn' => '', 'label' => '', 'impact' => '']] as $t): ?>
              <div class="gm-tier-row">
                <input class="t-amt" type="number" min="0" step="500" placeholder="5000" value="<?= $e((string) $t['amount_ngn']) ?>">
                <input class="t-label" maxlength="80" placeholder="A week of lunches" value="<?= $e((string) $t['label']) ?>">
                <input class="t-impact" maxlength="200" placeholder="Feeds one child for five school days" value="<?= $e((string) $t['impact']) ?>">
                <button type="button" class="gm-btn gm-ghost gm-sm" data-droprow>Remove</button>
              </div>
            <?php endforeach; ?>
          </div>
          <div class="gm-form-foot">
            <button type="button" class="gm-btn gm-ghost" id="addTier">Add a tier</button>
            <button type="button" class="gm-btn gm-primary" id="saveTiers">Save tiers</button>
            <span class="gm-saved" id="tierSaved" role="status" aria-live="polite"></span>
          </div>
        </div>
      <?php endif; ?>
    </section>
  </div>
</main>

<div class="gm-toast" id="toast" role="status" aria-live="polite" hidden></div>
<script>window.GIVE_CSRF = <?= json_encode($csrf) ?>; window.GIVE_APPEAL = <?= (int) ($sel['id'] ?? 0) ?>;</script>
<script src="/give/manage.js" defer></script>
<?php endif; ?>
</body>
</html>
