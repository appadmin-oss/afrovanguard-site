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
/* This appeal's own items — the priced, counted list of what it is actually
   buying. Needs above say what today costs, items say what the whole thing is
   made of. */
$aItems  = Appeals::itemsByCategory(['appeal_id' => (int) $a['id'], 'limit' => 120]);
$aItemSum = Appeals::itemsSummary(['appeal_id' => (int) $a['id'], 'limit' => 120]);
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

      <?php if ($aItems): ?>
        <?php /* The breakdown. Same rows as the giving page uses, so the two
                 cannot drift, and each priced line can be funded on its own —
                 which is what makes a large appeal approachable: somebody who
                 cannot give the whole thing can still buy one chair. */ ?>
        <section class="ed-section" style="padding-block:0;margin-top:clamp(40px,6vw,72px)" aria-labelledby="items-h">
          <div class="ed-head">
            <div><h2 class="ed-h2" id="items-h">What it is made of</h2></div>
            <?php if ($aItemSum['outstanding_ngn'] > 0): ?>
              <span class="ed-link" style="pointer-events:none"><?= e(Appeals::naira((int) $aItemSum['outstanding_ngn'])) ?> still to raise</span>
            <?php endif; ?>
          </div>
          <?php foreach ($aItems as $cat => $list): ?>
            <h3 class="gv-cat"><?= e((string) $cat) ?></h3>
            <ul class="gv-items">
              <?php foreach ($list as $it): ?>
                <li class="gv-item<?= $it['is_open'] ? '' : ' is-done' ?>">
                  <div class="gv-item-main">
                    <span class="gv-item-name"><?= e((string) $it['title']) ?></span>
                    <?php if (trim((string) $it['detail']) !== ''): ?>
                      <span class="gv-item-detail"><?= e((string) $it['detail']) ?></span>
                    <?php endif; ?>
                    <?php if ($it['qty_needed'] > 0): ?>
                      <span class="gv-meter" role="progressbar" aria-valuenow="<?= (int) $it['pct'] ?>"
                            aria-valuemin="0" aria-valuemax="100"
                            aria-label="<?= (int) $it['qty_funded'] ?> of <?= (int) $it['qty_needed'] ?> covered">
                        <span style="width:<?= (int) $it['pct'] ?>%"></span></span>
                    <?php endif; ?>
                  </div>
                  <div class="gv-item-side">
                    <?php if ($it['kind'] === 'money' && $it['unit_cost'] > 0): ?>
                      <span class="gv-item-price"><?= e(Appeals::naira((int) $it['unit_cost'])) ?><?php
                        if (trim((string) $it['unit_label']) !== ''): ?><small> / <?= e((string) $it['unit_label']) ?></small><?php endif; ?></span>
                    <?php else: ?>
                      <span class="gv-item-price gv-item-kind">Given in kind</span>
                    <?php endif; ?>
                    <span class="gv-item-left"><?php
                      if (!$it['is_open']) { echo 'Covered — thank you'; }
                      elseif ($it['qty_needed'] > 0) { echo (int) $it['qty_left'] . ' still needed'; }
                      else { echo 'Any number welcome'; } ?></span>
                    <?php if ($it['is_open']): ?>
                      <a class="gv-item-cta"
                         <?php if ($it['kind'] === 'money'): ?>
                           data-gv-pay="<?= (int) $it['unit_cost'] ?>"
                           data-gv-item="<?= e((string) $it['title']) ?>"
                           data-gv-slug="<?= e((string) $it['slug']) ?>"
                           href="<?= e('/donate.html?amount=' . (int) $it['unit_cost'] . '&campaign=' . rawurlencode((string) $a['slug'])) ?>"
                         <?php else: ?>
                           href="<?= e('/contact.html?about=' . rawurlencode('Donating: ' . (string) $it['title'])) ?>"
                         <?php endif; ?>><?= $it['kind'] === 'money' ? 'Fund one' : 'Offer one' ?></a>
                    <?php endif; ?>
                  </div>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php endforeach; ?>
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

      <?php if (!empty($a['funds_ngv'])):
        /* Named nobody. A participant who cannot afford their training fee has
           not volunteered to have that published beside a donate button — "14
           Vanguards" is a cause, "Ada, who is behind" is an exposure. */
        $ngvShort = Appeals::ngvShortfall();
        if ($ngvShort['available'] && $ngvShort['participants'] > 0): ?>
        <p class="give-note"><strong><?= (int) $ngvShort['participants'] ?>
          <?= $ngvShort['participants'] === 1 ? 'Vanguard is' : 'Vanguards are' ?> behind on training fees right now</strong>,
          by <?= e(Appeals::naira((int) $ngvShort['outstanding'])) ?> between them. What you give here is paid
          straight onto their accounts — it is not a fund we hold.</p>
        <?php endif; ?>
      <?php endif; ?>

      <?php if ($st['match_live'] && $st['match_left'] > 0): ?>
        <p class="give-note is-warn"><strong><?= e(Appeals::naira($st['match_left'])) ?> still to be matched.</strong>
          <?= $st['sponsor'] !== '' ? e($st['sponsor']) . ' is doubling' : 'A sponsor is doubling' ?> every gift
          <?= !empty($a['match_until']) ? 'until ' . e(date('j F', (int) strtotime((string) $a['match_until']))) : 'while the pledge lasts' ?>.</p>
      <?php endif; ?>

      <?php if (!$st['accepting']): ?>
        <p class="give-note"><?= $closed
            ? 'This appeal has closed. Thank you to everyone who gave.'
            : 'This appeal is not taking donations at the moment.' ?>
          <a href="/donate.html">Give to Afrovanguard</a> instead.</p>
      <?php else:
        /* ── the giving widget ────────────────────────────────────────────
           Frequency first, then amount, then one button whose label says
           exactly what pressing it does. Asking "how much" before "how often"
           makes somebody re-decide the amount when they change their mind
           about the frequency, which is the commonest way a donation form
           loses the person halfway through.

           It works without JavaScript: the whole thing is a form that posts to
           the ordinary donate page, and the script only upgrades the recurring
           path — which genuinely needs a round trip, because a Paystack Plan
           has to exist before a subscription can. */
        $amounts = [];
        foreach ($tiers as $t) $amounts[] = (int) $t['amount_ngn'];
        if (!$amounts) $amounts = [2000, 5000, 10000, 25000];
        $amounts = array_values(array_unique(array_filter($amounts)));
        sort($amounts);
        $amounts = array_slice($amounts, 0, 4);
        $impactFor = [];
        foreach ($tiers as $t) $impactFor[(int) $t['amount_ngn']] = (string) ($t['impact'] ?: $t['label']);
      ?>
        <form class="gw" id="giveWidget" method="get" action="/donate.html"
              data-slug="<?= e((string) $a['slug']) ?>" data-min="<?= (int) (defined('MIN_DONATION_AMOUNT') ? MIN_DONATION_AMOUNT : 1000) ?>">
          <input type="hidden" name="campaign" value="<?= e((string) $a['slug']) ?>">

          <fieldset class="gw-freq">
            <legend class="gw-legend">How often</legend>
            <div class="gw-seg" role="radiogroup" aria-label="How often to give">
              <label class="gw-seg-opt">
                <input type="radio" name="frequency" value="once" checked>
                <span>Once</span>
              </label>
              <?php foreach (Appeals::INTERVALS as $k => $word): ?>
                <label class="gw-seg-opt">
                  <input type="radio" name="frequency" value="<?= e($k) ?>">
                  <span><?= e($k === 'monthly' ? 'Monthly' : ($k === 'quarterly' ? 'Quarterly' : 'Yearly')) ?></span>
                </label>
              <?php endforeach; ?>
            </div>
          </fieldset>

          <fieldset class="gw-amts">
            <legend class="gw-legend">How much</legend>
            <div class="gw-chips">
              <?php foreach ($amounts as $i => $amt): ?>
                <label class="gw-chip">
                  <input type="radio" name="amount" value="<?= (int) $amt ?>" <?= $i === 1 || count($amounts) === 1 ? 'checked' : '' ?>>
                  <span class="gw-chip-amt"><?= e(Appeals::naira((int) $amt)) ?></span>
                  <?php if (!empty($impactFor[$amt])): ?>
                    <span class="gw-chip-impact"><?= e($impactFor[$amt]) ?></span>
                  <?php endif; ?>
                </label>
              <?php endforeach; ?>
              <label class="gw-chip gw-chip--other">
                <input type="radio" name="amount" value="other">
                <span class="gw-chip-amt">Other</span>
              </label>
            </div>
            <label class="gw-other" hidden>
              <span class="give-sr">Your amount in naira</span>
              <span class="gw-other-pre" aria-hidden="true">₦</span>
              <input type="number" id="gwOther" name="custom_amount" min="<?= (int) (defined('MIN_DONATION_AMOUNT') ? MIN_DONATION_AMOUNT : 1000) ?>"
                     step="500" inputmode="numeric" placeholder="Amount">
            </label>
          </fieldset>

          <label class="gw-email" hidden>
            <span class="gw-legend">Your email <em>so we can send the receipt and set up the schedule</em></span>
            <input type="email" id="gwEmail" name="email" autocomplete="email" placeholder="you@example.com">
          </label>

          <button type="submit" class="gw-go" id="gwGo">Give <span id="gwGoAmt"></span></button>
          <p class="gw-summary" id="gwSummary" role="status" aria-live="polite"></p>
          <p class="gw-err" id="gwErr" role="alert" hidden></p>
          <p class="gw-fine">Card, bank transfer and USSD. Secured by Paystack.
            <?php if ($st['match_live'] && $st['match_left'] > 0): ?><br><strong>Doubled while the match lasts.</strong><?php endif; ?>
            <br>You can stop a recurring gift any time — just reply to the receipt.</p>
        </form>
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
<div class="gvpay" id="gvPay" hidden role="dialog" aria-modal="true" aria-labelledby="gvPayTitle">
  <div class="gvpay-card" role="document">
    <button type="button" class="gvpay-x" id="gvPayX" aria-label="Close">&times;</button>
    <h2 class="gvpay-h" id="gvPayTitle">Fund one</h2>
    <p class="gvpay-what" id="gvPayWhat"></p>
    <label class="gvpay-field"><span>Your email <em>for the receipt</em></span>
      <input type="email" id="gvPayEmail" autocomplete="email" placeholder="you@example.com" required></label>
    <label class="gvpay-field"><span>Your name <em>optional</em></span>
      <input type="text" id="gvPayName" autocomplete="name" placeholder="So we can thank you properly"></label>
    <button type="button" class="gvpay-go" id="gvPayGo">Give <span id="gvPayAmt"></span></button>
    <p class="gvpay-err" id="gvPayErr" role="alert" hidden></p>
    <p class="gvpay-fine">Card, bank transfer and USSD. Secured by Paystack. You stay on this page.</p>
  </div>
</div>
<script src="/assets/site/give-pay.js" defer></script>
<script src="/give/pay-sheet.js" defer></script>
<script src="/give/give.js" defer></script>
<?php render_footer();
