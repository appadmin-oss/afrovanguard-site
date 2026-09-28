<?php
/**
 * give/index.php — every appeal worth showing. /give/
 *
 * The index answers one question before any other: what is Afrovanguard asking
 * for right now, and how close is each one. Urgent and ending-soon appeals sort
 * first, because an index that lists a finished campaign above a closing one is
 * costing the closing one its last week.
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_once AV_ROOT . '/lib/partials.php';

$appeals   = Appeals::published(120);
$summary   = Appeals::summary();
$needsNow  = Appeals::currentNeedsAll(6);
$needTotal = Appeals::needsTotal();

/* Live first, then urgency, then how close to done. A funded appeal is a good
   advertisement for the next one, so it stays on the page — at the bottom. */
usort($appeals, static function (array $x, array $y): int {
    $rank = static function (array $a): int {
        $s = Appeals::state($a);
        if ((string) $a['status'] !== 'live') return 3;
        if (!empty($a['urgent'])) return 0;
        if ($s['ending_soon'] && !$s['ended']) return 1;
        return 2;
    };
    $r = $rank($x) <=> $rank($y);
    if ($r !== 0) return $r;
    return ((int) $y['featured']) <=> ((int) $x['featured']);
});

$canonical = rtrim(SITE_URL, '/') . '/give/';
$desc = 'Live appeals from Afrovanguard — what we need today, this week and this season, and exactly what your gift pays for.';
if ($summary['appeals'] > 0 && $summary['raised'] > 0) {
    $desc = $summary['appeals'] . ' live appeal' . ($summary['appeals'] === 1 ? '' : 's')
          . ' · ' . Appeals::naira($summary['raised']) . ' raised from ' . $summary['donors'] . ' donors. '
          . 'See what we need today and what your gift pays for.';
}

render_head([
    'title'     => 'Give — live appeals · Afrovanguard',
    'desc'      => mb_substr($desc, 0, 185),
    'canonical' => $canonical,
    'og_kind'   => 'website',
    'keywords'  => 'donate Nigeria, charity appeal, Afrovanguard, give, fundraising, community development',
    'jsonld'    => [
        schema_org(),
        schema_breadcrumb([
            ['name' => 'Home', 'url' => rtrim(SITE_URL, '/') . '/'],
            ['name' => 'Give', 'url' => $canonical],
        ]),
        /* An ItemList of the live appeals. It earns no rich result on its own,
           but it tells a crawler these are separate things rather than one page
           of prose — which is how each appeal gets indexed in its own right. */
        [
            '@type' => 'ItemList',
            'name'  => 'Afrovanguard appeals',
            'itemListElement' => array_values(array_map(
                static fn(int $i, array $a): array => [
                    '@type' => 'ListItem', 'position' => $i + 1,
                    'name' => (string) $a['title'], 'url' => Appeals::url($a),
                ],
                array_keys($appeals), $appeals
            )),
        ],
    ],
    'css'        => ['/assets/site/editorial.css', '/give/give.css'],
    'body_class' => 'give',
]);
render_nav('involved');
?>
<main id="main-content">

  <!-- ── masthead ───────────────────────────────────────────────────────── -->
  <header class="ed-wrap ed-section ed-section--tight">
    <span class="ed-kicker">Give</span>
    <h1 class="ed-display">What we need, and what it costs</h1>
    <p class="ed-lede">Every appeal below says exactly what it is for, how far along it is, and what a
      given amount actually pays for. Nothing is rounded up and nothing is guessed.</p>
    <?php if ($summary['appeals'] > 0): ?>
      <div class="ed-stats" style="margin-top:var(--afg-space-6)">
        <div class="ed-stat"><span class="ed-stat-n"><?= (int) $summary['appeals'] ?></span><span class="ed-stat-l">Live appeals</span></div>
        <div class="ed-stat"><span class="ed-stat-n"><?= e(Appeals::naira((int) $summary['raised'])) ?></span><span class="ed-stat-l">Raised</span></div>
        <?php /* Only when there are any. Offline gifts carry no donor count, so
                 a stat row reading "₦5,200,000 · 0 donors" states a contradiction
                 in the largest type on the page. */ ?>
        <?php if ($summary['donors'] > 0): ?>
          <div class="ed-stat"><span class="ed-stat-n"><?= number_format((int) $summary['donors']) ?></span><span class="ed-stat-l">Donors</span></div>
        <?php elseif ($needTotal['today'] > 0): ?>
          <div class="ed-stat"><span class="ed-stat-n"><?= e(Appeals::naira((int) $needTotal['today'])) ?></span><span class="ed-stat-l">Needed today</span></div>
        <?php endif; ?>
        <?php if ($summary['goal'] > 0): ?>
          <div class="ed-stat"><span class="ed-stat-n"><?= e(Appeals::naira((int) $summary['goal'])) ?></span><span class="ed-stat-l">Together aiming for</span></div>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </header>

  <?php /* ── the needs board ──────────────────────────────────────────────
       This goes first, above even the leading appeal. A goal is an institution
       asking to be cared about; a need is a thing somebody can buy today. */ ?>
  <?php if ($needsNow): ?>
    <section class="ed-band" aria-labelledby="needs-h">
      <div class="ed-wrap">
        <div class="ed-head">
          <div>
            <span class="ed-kicker">Right now</span>
            <h2 class="ed-h2" id="needs-h">What today and this week actually cost</h2>
          </div>
          <?php if ($needTotal['today'] > 0 || $needTotal['week'] > 0): ?>
            <p class="ed-story-meta" style="margin:0">
              <?php if ($needTotal['today'] > 0): ?>
                <span><strong style="color:#fff"><?= e(Appeals::naira((int) $needTotal['today'])) ?></strong> needed today</span>
              <?php endif; ?>
              <?php if ($needTotal['week'] > 0): ?>
                <span><strong style="color:#fff"><?= e(Appeals::naira((int) $needTotal['week'])) ?></strong> this week</span>
              <?php endif; ?>
            </p>
          <?php endif; ?>
        </div>
        <div class="ed-needs-board">
          <?php foreach ($needsNow as $n): ?>
            <a class="ed-need" href="<?= e($n['appeal']['url']) ?>">
              <span class="ed-need-when"><?= e($n['cadence'] === 'daily' ? 'Today' : ($n['cadence'] === 'weekly' ? 'This week' : 'Still needed')) ?></span>
              <span class="ed-need-fig"><?= e(Appeals::naira((int) $n['target_ngn'])) ?></span>
              <span class="ed-need-title"><?= e((string) $n['title']) ?></span>
              <?php if ($n['units_target'] > 0 && $n['unit_label'] !== ''): ?>
                <span class="ed-need-unit"><?= e(number_format((int) $n['units_target']) . ' ' . (string) $n['unit_label']
                  . ' at ' . Appeals::naira((int) $n['unit_cost']) . ' each') ?></span>
              <?php endif; ?>
              <span class="ed-need-for"><?= e((string) $n['appeal']['title']) ?></span>
            </a>
          <?php endforeach; ?>
        </div>
      </div>
    </section>
  <?php endif; ?>

  <div class="ed-wrap"><hr class="ed-rule"></div>

  <?php if (!$appeals): ?>
    <section class="ed-wrap ed-section">
      <div class="give-empty">
        <h2>No appeals are running right now</h2>
        <p>When there is something specific to raise for, it will appear here with the full figures.<br>
          In the meantime you can <a href="/donate.html">give to Afrovanguard's general fund</a>.</p>
      </div>
    </section>
  <?php else:
    /* The first live appeal is the lead and gets the room. Everything after it
       is a tile — an index where every item is equally loud is an index nobody
       reads past the third row. */
    $lead = $appeals[0];
    $rest = array_slice($appeals, 1);
    $leadSt = Appeals::state($lead); ?>

    <section class="ed-wrap ed-section" aria-labelledby="lead-h">
      <a class="ed-story ed-feature" href="<?= e('/give/' . rawurlencode((string) $lead['slug']) . '/') ?>">
        <div class="ed-story-img-wrap">
          <img class="ed-story-img" src="<?= e((string) $lead['cover_url'] ?: Appeals::ogUrl($lead)) ?>"
               alt="" width="960" height="600" fetchpriority="high" decoding="async">
        </div>
        <div>
          <span class="ed-kicker"><?= e($leadSt['urgent'] ? 'Urgent appeal' : 'Leading appeal') ?><?php
            if (!empty($lead['location'])): ?> · <?= e((string) $lead['location']) ?><?php endif; ?></span>
          <h2 class="ed-story-title" id="lead-h"><?= e((string) $lead['title']) ?></h2>
          <?php if (!empty($lead['tagline'])): ?>
            <p class="ed-story-excerpt" style="font-size:var(--afg-text-md)"><?= e((string) $lead['tagline']) ?></p>
          <?php endif; ?>
          <div class="ed-meter" style="margin:var(--afg-space-4) 0">
            <?php if ($leadSt['percent'] !== null): ?>
              <div class="ed-bar<?= $leadSt['met'] ? ' is-met' : '' ?>" role="progressbar"
                   aria-valuenow="<?= (int) $leadSt['percent'] ?>" aria-valuemin="0" aria-valuemax="100"
                   aria-label="<?= (int) $leadSt['percent'] ?>% raised"><span style="width:<?= (int) $leadSt['percent'] ?>%"></span></div>
            <?php endif; ?>
            <div class="ed-figures">
              <span class="ed-raised"><?= e(Appeals::naira($leadSt['raised'])) ?></span>
              <span class="ed-goal"><?= $leadSt['goal'] > 0 ? 'of ' . e(Appeals::naira($leadSt['goal'])) : 'raised so far' ?></span>
            </div>
          </div>
          <span class="ed-link">See this appeal</span>
        </div>
      </a>
    </section>

    <?php if ($rest): ?>
      <div class="ed-wrap"><hr class="ed-rule"></div>
      <section class="ed-wrap ed-section" aria-labelledby="more-h">
        <div class="ed-head">
          <div><span class="ed-kicker ed-kicker--muted">Also open</span>
            <h2 class="ed-h2" id="more-h">More ways to give</h2></div>
          <a class="ed-link" href="/donate.html">Give to the general fund</a>
        </div>
        <div class="ed-stories">
          <?php foreach ($rest as $a): $st = Appeals::state($a); ?>
            <a class="ed-story" href="<?= e('/give/' . rawurlencode((string) $a['slug']) . '/') ?>">
              <div class="ed-story-img-wrap">
                <img class="ed-story-img" src="<?= e((string) $a['cover_url'] ?: Appeals::ogUrl($a)) ?>"
                     alt="" loading="lazy" decoding="async" width="580" height="387">
              </div>
              <span class="ed-kicker"><?php
                if ((string) $a['status'] === 'funded') { echo 'Funded'; }
                elseif (!empty($a['urgent'])) { echo 'Urgent'; }
                elseif ($st['ending_soon'] && !$st['ended']) { echo (int) $st['days_left'] . ' days left'; }
                else { echo e(ucfirst((string) $a['kind'])); }
              ?></span>
              <h3 class="ed-story-title"><?= e((string) $a['title']) ?></h3>
              <?php if (!empty($a['tagline'])): ?>
                <p class="ed-story-excerpt"><?= e(mb_strimwidth((string) $a['tagline'], 0, 116, '…')) ?></p>
              <?php endif; ?>
              <div class="ed-meter" style="margin-bottom:var(--afg-space-3)">
                <?php if ($st['percent'] !== null): ?>
                  <div class="ed-bar<?= $st['met'] ? ' is-met' : '' ?>" role="progressbar"
                       aria-valuenow="<?= (int) $st['percent'] ?>" aria-valuemin="0" aria-valuemax="100"
                       aria-label="<?= (int) $st['percent'] ?>% raised"><span style="width:<?= (int) $st['percent'] ?>%"></span></div>
                <?php endif; ?>
              </div>
              <div class="ed-story-meta">
                <strong style="color:var(--afg-ink)"><?= e(Appeals::naira($st['raised'])) ?></strong>
                <?php if ($st['goal'] > 0): ?><span>of <?= e(Appeals::naira($st['goal'])) ?></span>
                <?php elseif ($st['donors'] > 0): ?><span><?= (int) $st['donors'] ?> donors</span>
                <?php else: ?><span>raised so far</span><?php endif; ?>
              </div>
            </a>
          <?php endforeach; ?>
        </div>
      </section>
    <?php endif; ?>
  <?php endif; ?>

  <!-- ── who runs these ─────────────────────────────────────────────────── -->
  <div class="ed-wrap"><hr class="ed-rule"></div>
  <section class="ed-section">
    <div class="ed-wrap">
      <div class="ed-feature">
        <div>
          <span class="ed-kicker">How this works</span>
          <h2 class="ed-h2">Every appeal here is ours, and every figure is the verified one</h2>
          <p class="ed-lede">Afrovanguard runs each of these itself. There are no third-party fundraisers
            and nobody outside the organisation can start one on this page. Payments are taken by Paystack,
            and the totals you see are read from the verified payment record — not typed in by us.</p>
          <a class="ed-link" href="/contact.html">Ask us anything about an appeal</a>
        </div>
        <figure class="ed-quote">
          <blockquote>“Tell people exactly what today costs, and they will pay for today.”</blockquote>
          <figcaption><strong>Afrovanguard</strong>Ambassadors for Community, Tech and Cultural Advancements</figcaption>
        </figure>
      </div>
    </div>
  </section>
</main>
<?php render_footer();
