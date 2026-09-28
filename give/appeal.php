<?php
/**
 * give/appeal.php — one public appeal. /give/<slug>/
 *
 * The page is ordered by what a first-time visitor needs, in that order: what
 * this is, how far along it is, what it costs TODAY, the story, what a given
 * amount buys, what the money has already done, who else has given. The ask is
 * never more than a thumb away — sticky beside the story on desktop, pinned to
 * the bottom of the viewport on a phone.
 *
 * Every figure on it comes from Appeals, which reads the verified donation
 * store. Nothing here recomputes money.
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_once AV_ROOT . '/lib/partials.php';

$slug = preg_replace('/[^a-z0-9\-]/', '', strtolower((string) ($_GET['slug'] ?? '')));
$a = $slug !== '' ? Appeals::bySlug($slug) : null;

/* A draft is somebody's unfinished writing. It is not "not found yet" to the
   public — it is not found, and it must not be guessable by trying slugs. */
if (!Appeals::isPublic($a)) {
    http_response_code(404);
    require AV_ROOT . '/404.html';
    exit;
}

$st      = Appeals::state($a);
$needs   = Appeals::currentNeeds((int) $a['id']);
$tiers   = Appeals::tiersFor((int) $a['id']);
$updates = Appeals::updatesFor((int) $a['id'], 20);
$donors  = Appeals::donors($a, 10);
$share   = Appeals::shareLinks($a);
$meta    = Appeals::meta($a);
$canonical = Appeals::url($a);
$closed  = (string) $a['status'] === 'closed';

Appeals::countView((int) $a['id']);

/** The story, as safe HTML. Markdown, via the library the site already uses. */
$storyHtml = '';
if (trim((string) $a['story']) !== '') {
    if (class_exists('ChiomaMarkdown')) {
        $storyHtml = ChiomaMarkdown::render((string) $a['story']);
    } else {
        $storyHtml = '<p>' . nl2br(e((string) $a['story'])) . '</p>';
    }
}

/** A YouTube/Vimeo id, or '' — only these two embed, and only by id, so a
 *  pasted tracking URL cannot become an arbitrary iframe src. */
$videoEmbed = '';
if (!empty($a['video_url'])) {
    if (preg_match('~(?:youtube\.com/(?:watch\?v=|embed/)|youtu\.be/)([A-Za-z0-9_\-]{6,20})~', (string) $a['video_url'], $m)) {
        $videoEmbed = 'https://www.youtube-nocookie.com/embed/' . $m[1];
    } elseif (preg_match('~vimeo\.com/(?:video/)?(\d{5,12})~', (string) $a['video_url'], $m)) {
        $videoEmbed = 'https://player.vimeo.com/video/' . $m[1];
    }
}

$kindWord = ['appeal' => 'Appeal', 'event' => 'Event', 'programme' => 'Programme', 'emergency' => 'Emergency appeal'];

render_head([
    'title'     => $meta['title'],
    'desc'      => $meta['desc'],
    'canonical' => $canonical,
    'slug'      => (string) $a['slug'],
    'og_kind'   => 'article',
    'image'     => Appeals::ogUrl($a),
    'image_alt' => (string) $a['title'],
    'keywords'  => trim((string) $a['keywords']) ?: ((string) $a['title'] . ', donate, Afrovanguard, Nigeria, charity'),
    /* A closed appeal stays readable — people follow old links and deserve to
       find out how it ended — but it stops competing in search with the ones
       actually asking. */
    'robots'    => $closed ? 'noindex, follow' : 'index, follow, max-snippet:-1, max-image-preview:large',
    'published' => $a['published_at'] ? gmdate('c', (int) strtotime((string) $a['published_at'])) : '',
    'modified'  => $a['updated_at'] ? gmdate('c', (int) strtotime((string) $a['updated_at'])) : '',
    'jsonld'    => array_merge([schema_org()], Appeals::jsonLd($a), [schema_breadcrumb([
        ['name' => 'Home', 'url' => rtrim(SITE_URL, '/') . '/'],
        ['name' => 'Give',  'url' => rtrim(SITE_URL, '/') . '/give/'],
        ['name' => (string) $a['title'], 'url' => $canonical],
    ])]),
    'css'        => ['/assets/site/editorial.css', '/give/give.css'],
    'body_class' => 'give has-give-bar',
]);
render_nav('involved');

$donateHref = '/donate.html?campaign=' . rawurlencode((string) $a['slug']);
?>
<main id="main-content" class="give-wrap" style="padding-top:var(--afg-space-7);padding-bottom:var(--afg-space-8)">

  <span class="ed-kicker"><?= e($kindWord[(string) $a['kind']] ?? 'Appeal') ?><?php
      if (!empty($a['location'])): ?> · <?= e((string) $a['location']) ?><?php endif; ?></span>
  <h1 class="ed-display"><?= e((string) $a['title']) ?></h1>
  <?php if (!empty($a['tagline'])): ?><p class="ed-lede"><?= e((string) $a['tagline']) ?></p><?php endif; ?>
  <hr class="ed-rule" style="margin-bottom:var(--afg-space-7)">

  <div class="give-layout">
    <div>
      <?php if ($videoEmbed): ?>
        <div class="give-video">
          <iframe src="<?= e($videoEmbed) ?>" title="<?= e((string) $a['title']) ?>"
                  loading="lazy" allow="accelerometer; encrypted-media; picture-in-picture"
                  allowfullscreen referrerpolicy="strict-origin-when-cross-origin"></iframe>
        </div>
      <?php elseif (!empty($a['cover_url'])): ?>
        <img class="give-hero-img" src="<?= e((string) $a['cover_url']) ?>"
             alt="<?= e((string) $a['title']) ?>" width="1200" height="675"
             fetchpriority="high" decoding="async">
      <?php endif; ?>

      <?php /* The needs. This is the page's real ask and it goes above the
               story on purpose: "₦20,000 today for 40 hot meals" is a decision
               somebody can make in one breath, and "help us reach ₦4,000,000"
               is not. */ ?>
      <?php if ($needs): ?>
        <section class="ed-section" style="padding-block:0;margin-top:clamp(40px,6vw,72px)" style="margin-top:0" aria-labelledby="needs-h">
          <h2 class="ed-h2" id="needs-h">What we need right now</h2>
          <div class="give-needs">
            <?php foreach ($needs as $n):
              $met = $n['status'] === 'met';
              $when = $n['cadence'] === 'daily' ? 'Today' : ($n['cadence'] === 'weekly' ? 'This week' : 'Still open'); ?>
              <article class="give-need<?= $met ? ' is-met' : '' ?>">
                <div class="give-need-when"><?= e($when) ?><?= $met ? ' · met, thank you' : '' ?></div>
                <h3 class="give-need-title"><?= e((string) $n['title']) ?></h3>
                <div>
                  <span class="give-need-figure"><?= e(Appeals::naira((int) $n['target_ngn'])) ?></span>
                  <?php if ($n['units_target'] > 0 && $n['unit_label'] !== ''): ?>
                    <span class="give-need-unit">— <?= e(number_format((int) $n['units_target']) . ' ' . (string) $n['unit_label']
                          . ' at ' . Appeals::naira((int) $n['unit_cost']) . ' each') ?></span>
                  <?php endif; ?>
                </div>
                <?php if (!empty($n['detail'])): ?><p class="give-need-detail"><?= e((string) $n['detail']) ?></p><?php endif; ?>
              </article>
            <?php endforeach; ?>
          </div>
        </section>
      <?php endif; ?>

      <?php if ($storyHtml): ?>
        <section class="ed-section" style="padding-block:0;margin-top:clamp(40px,6vw,72px)" aria-labelledby="story-h">
          <h2 class="ed-h2" id="story-h">The story</h2>
          <div class="give-prose"><?= $storyHtml /* already sanitised by the renderer */ ?></div>
        </section>
      <?php endif; ?>

      <?php if (!empty($a['gallery'])): ?>
        <section class="ed-section" style="padding-block:0;margin-top:clamp(40px,6vw,72px)" aria-labelledby="gallery-h">
          <h2 class="ed-h2" id="gallery-h">From the ground</h2>
          <div class="give-grid" style="grid-template-columns:repeat(auto-fill,minmax(210px,1fr))">
            <?php foreach ($a['gallery'] as $i => $img): ?>
              <img src="<?= e($img) ?>" alt="<?= e((string) $a['title']) ?> — photograph <?= (int) $i + 1 ?>"
                   loading="lazy" decoding="async" class="give-card-img" style="border-radius:var(--afg-radius-sm);border:1px solid var(--afg-border)">
            <?php endforeach; ?>
          </div>
        </section>
      <?php endif; ?>

      <?php if ((int) $a['spent_ngn'] > 0 || trim((string) $a['spend_note']) !== ''): ?>
        <section class="ed-section" style="padding-block:0;margin-top:clamp(40px,6vw,72px)" aria-labelledby="spend-h">
          <h2 class="ed-h2" id="spend-h">Where the money has gone</h2>
          <?php if ((int) $a['spent_ngn'] > 0): ?>
            <p class="ed-raised" style="margin-bottom:var(--afg-space-2)"><?= e(Appeals::naira((int) $a['spent_ngn'])) ?>
              <span class="ed-goal">spent of <?= e(Appeals::naira($st['raised'])) ?> raised</span></p>
          <?php endif; ?>
          <?php if (trim((string) $a['spend_note']) !== ''): ?>
            <p class="give-prose"><?= nl2br(e((string) $a['spend_note'])) ?></p>
          <?php endif; ?>
        </section>
      <?php endif; ?>

      <?php if ($updates): ?>
        <section class="ed-section" style="padding-block:0;margin-top:clamp(40px,6vw,72px)" aria-labelledby="updates-h">
          <h2 class="ed-h2" id="updates-h">Updates</h2>
          <div class="give-updates">
            <?php foreach ($updates as $u): ?>
              <article class="give-update is-<?= e((string) $u['kind']) ?>">
                <div class="give-update-when"><time datetime="<?= e(gmdate('c', (int) strtotime((string) $u['created_at']))) ?>"><?= e(date('j F Y', (int) strtotime((string) $u['created_at']))) ?></time><?php
                  if ((int) $u['amount_ngn'] > 0): ?> · <?= e(Appeals::naira((int) $u['amount_ngn'])) ?><?php endif; ?></div>
                <?php if (!empty($u['title'])): ?><h3 class="give-update-title"><?= e((string) $u['title']) ?></h3><?php endif; ?>
                <?php if (!empty($u['image_url'])): ?>
                  <img src="<?= e((string) $u['image_url']) ?>" alt="" loading="lazy" decoding="async"
                       style="max-width:100%;border-radius:var(--afg-radius-sm);margin-bottom:var(--afg-space-3)">
                <?php endif; ?>
                <?php if (!empty($u['body'])): ?>
                  <div class="give-prose"><?= class_exists('ChiomaMarkdown') ? ChiomaMarkdown::render((string) $u['body']) : '<p>' . nl2br(e((string) $u['body'])) . '</p>' ?></div>
                <?php endif; ?>
              </article>
            <?php endforeach; ?>
          </div>
        </section>
      <?php endif; ?>
    </div>

    <?php /* ── the give panel ───────────────────────────────────────────── */ ?>
    <aside class="give-panel" aria-label="Give to this appeal">
      <div class="ed-meter">
        <div class="ed-figures">
          <span class="ed-raised"><?= e(Appeals::naira($st['raised'])) ?></span>
          <?php if ($st['goal'] > 0): ?>
            <span class="ed-goal">of <?= e(Appeals::naira($st['goal'])) ?></span>
          <?php else: ?>
            <span class="ed-goal">raised so far</span>
          <?php endif; ?>
        </div>
        <?php if ($st['percent'] !== null): ?>
          <div class="ed-bar<?= $st['met'] ? ' is-met' : '' ?>"
               role="progressbar" aria-valuenow="<?= (int) $st['percent'] ?>" aria-valuemin="0" aria-valuemax="100"
               aria-label="<?= (int) $st['percent'] ?>% of the <?= e(Appeals::naira($st['goal'])) ?> goal raised">
            <span style="width:<?= (int) $st['percent'] ?>%"></span>
          </div>
        <?php endif; ?>
        <div class="ed-figures">
          <?php /* Offline gifts carry no donor count, so a page showing a real
                   figure beside "0 donors" reads as a contradiction — and the
                   first person to notice will be the donor whose cash it is. */ ?>
          <span class="ed-goal"><?php if ($st['donors'] > 0): ?>
            <?= (int) $st['donors'] ?> donor<?= $st['donors'] === 1 ? '' : 's' ?>
          <?php elseif ($st['raised'] > 0): ?>recorded by our team
          <?php else: ?>no donors yet<?php endif; ?></span>
          <?php if ($st['days_left'] !== null && $st['days_left'] >= 0): ?>
            <span class="ed-goal"><?= (int) $st['days_left'] ?> day<?= $st['days_left'] === 1 ? '' : 's' ?> left</span>
          <?php endif; ?>
        </div>
      </div>

      <div class="give-badges">
        <?php if (!empty($a['urgent'])): ?><span class="give-badge is-urgent">Urgent</span><?php endif; ?>
        <?php if ($st['met']): ?><span class="give-badge is-met">Goal reached</span><?php endif; ?>
        <?php if ($st['match_live'] && $st['match_left'] > 0): ?><span class="give-badge is-match">Gifts doubled</span><?php endif; ?>
        <?php if ($st['ending_soon'] && !$st['ended']): ?><span class="give-badge is-urgent">Ending soon</span><?php endif; ?>
        <?php if ($closed): ?><span class="give-badge is-closed">Closed</span><?php endif; ?>
      </div>

      <?php if ($st['match_live'] && $st['match_left'] > 0): ?>
        <p class="give-note is-warn"><strong><?= e(Appeals::naira($st['match_left'])) ?> still to be matched.</strong>
          <?= $st['sponsor'] !== '' ? e($st['sponsor']) . ' is doubling' : 'A sponsor is doubling' ?> every gift
          <?= !empty($a['match_until']) ? 'until ' . e(date('j F', (int) strtotime((string) $a['match_until']))) : 'while the pledge lasts' ?>.</p>
      <?php endif; ?>

      <?php if ($st['accepting']): ?>
        <a class="give-btn give-btn-primary give-btn-block" href="<?= e($donateHref) ?>">Give to this appeal</a>
      <?php else: ?>
        <p class="give-note"><?= $closed
            ? 'This appeal has closed. Thank you to everyone who gave.'
            : 'This appeal is not taking donations at the moment.' ?>
          <a href="/donate.html">Give to Afrovanguard</a> instead.</p>
      <?php endif; ?>

      <?php if ($tiers && $st['accepting']): ?>
        <div class="give-tiers">
          <?php foreach ($tiers as $t): ?>
            <a class="give-tier" href="<?= e($donateHref . '&amount=' . (int) $t['amount_ngn']) ?>">
              <span class="give-tier-amt"><?= e(Appeals::naira((int) $t['amount_ngn'])) ?></span>
              <span class="give-tier-impact"><?= e((string) ($t['impact'] ?: $t['label'])) ?></span>
            </a>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <?php /* ── share ─────────────────────────────────────────────────── */ ?>
      <div>
        <p class="give-eyebrow" style="margin-bottom:var(--afg-space-2)">Share this appeal</p>
        <div class="give-share">
          <a href="<?= e($share['whatsapp']) ?>" target="_blank" rel="noopener" data-share="whatsapp">WhatsApp</a>
          <a href="<?= e($share['x']) ?>" target="_blank" rel="noopener" data-share="x">X</a>
          <a href="<?= e($share['facebook']) ?>" target="_blank" rel="noopener" data-share="facebook">Facebook</a>
          <a href="<?= e($share['telegram']) ?>" target="_blank" rel="noopener" data-share="telegram">Telegram</a>
          <a href="<?= e($share['email']) ?>" data-share="email">Email</a>
          <button type="button" id="giveCopy" data-copy="<?= e($share['copy']) ?>">Copy link</button>
        </div>
        <p class="give-sr" role="status" aria-live="polite" id="giveCopyStatus"></p>
      </div>

      <?php $qr = Appeals::qrSvg($a); if ($qr !== ''): ?>
        <div class="give-qr">
          <?= $qr /* generated markup, no user input inside it */ ?>
          <div>
            <p class="give-donor-name" style="margin:0 0 4px">Scan to give</p>
            <p class="ed-goal" style="margin:0">Put this on a flyer or a slide —
              <a href="/give/<?= e((string) $a['slug']) ?>/poster">printable poster</a>.</p>
          </div>
        </div>
      <?php endif; ?>

      <?php if ($donors): ?>
        <div>
          <p class="give-eyebrow" style="margin-bottom:var(--afg-space-3)">Recent donors</p>
          <div class="give-donors">
            <?php foreach ($donors as $d): ?>
              <div class="give-donor">
                <div>
                  <div class="give-donor-name"><?= e($d['name']) ?></div>
                  <?php if ($d['note'] !== ''): ?><div class="give-donor-note">“<?= e($d['note']) ?>”</div><?php endif; ?>
                </div>
                <div class="give-donor-amt"><?= e(Appeals::naira((int) $d['amount'])) ?></div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      <?php elseif ($st['accepting'] && $st['raised'] === 0): ?>
        <p class="give-note">No one has given yet. Be the first — and then send it to someone.</p>
      <?php endif; ?>

      <?php if ($st['offline'] > 0): ?>
        <p class="ed-goal"><?= e(Appeals::naira($st['online'])) ?> given through this site,
          <?= e(Appeals::naira($st['offline'])) ?> recorded offline by our team.</p>
      <?php endif; ?>
    </aside>
  </div>
</main>

<?php if ($st['accepting']): ?>
<div class="give-sticky">
  <div class="s-fig">
    <div class="s-raised"><?= e(Appeals::naira($st['raised'])) ?><?php if ($st['goal'] > 0): ?> <span class="s-goal">of <?= e(Appeals::naira($st['goal'])) ?></span><?php endif; ?></div>
    <?php if ($st['percent'] !== null): ?>
      <div class="ed-bar" style="margin-top:4px" role="progressbar"
           aria-valuenow="<?= (int) $st['percent'] ?>" aria-valuemin="0" aria-valuemax="100"
           aria-label="<?= (int) $st['percent'] ?>% raised"><span style="width:<?= (int) $st['percent'] ?>%"></span></div>
    <?php endif; ?>
  </div>
  <a class="give-btn give-btn-primary" href="<?= e($donateHref) ?>">Give</a>
</div>
<?php endif; ?>

<script>
/* Copy-link, with the clipboard API's failure handled rather than assumed:
   it rejects on an insecure origin and in some in-app browsers, which is
   exactly where a shared link gets opened. The fallback selects the URL so it
   can still be copied by hand, and the status is announced, not just coloured. */
(function () {
  var btn = document.getElementById('giveCopy');
  if (!btn) return;
  var status = document.getElementById('giveCopyStatus');
  btn.addEventListener('click', function () {
    var url = btn.getAttribute('data-copy') || location.href;
    var done = function (msg) { btn.textContent = msg; if (status) status.textContent = msg;
                                setTimeout(function () { btn.textContent = 'Copy link'; }, 2200); };
    if (navigator.clipboard && window.isSecureContext) {
      navigator.clipboard.writeText(url).then(function () { done('Link copied'); },
                                              function () { window.prompt('Copy this link', url); done('Copy link'); });
    } else {
      window.prompt('Copy this link', url);
      done('Copy link');
    }
  });
  /* Count a share when one is actually opened. keepalive so the request
     survives the tab handing off to WhatsApp. */
  document.querySelectorAll('[data-share]').forEach(function (el) {
    el.addEventListener('click', function () {
      try {
        fetch('/give/share.php', { method: 'POST', keepalive: true,
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: 'slug=<?= e(rawurlencode((string) $a['slug'])) ?>&via=' + encodeURIComponent(el.getAttribute('data-share') || '') });
      } catch (e) { /* a metric, never the share */ }
    });
  });
})();
</script>
<?php render_footer();
