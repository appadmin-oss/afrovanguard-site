<?php
/**
 * reports/index.php — the reports Afrovanguard publishes (/reports/).
 *
 * The list is lib/Reports.php; how to add or finish a report is
 * docs/REPORTS.md. Linked from the footer and the About menu.
 *
 * What a reader needs first: the newest report and when the next one is due
 * (the summary), a way to jump to a year, and — because these PDFs run to
 * several megabytes and many readers are on mobile data — the size before
 * they open one, with reading and downloading as two separate choices.
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_once AV_ROOT . '/lib/partials.php';

$S       = rtrim(SITE_URL, '/');
$contact = 'cacentre@afrovanguard.org.ng';
$rows    = Reports::all();
$head    = Reports::headline($rows);
$years   = [];
foreach ($rows as $r) $years[$r['year']][] = $r;
/* Requests go through the contact form, not a mailto: link — a link that
   needs a mail app does nothing on many phones and office computers, and a
   form submission is stored, acknowledged and lands in the Studio's inbox. */
$ask = static fn (string $subject, string $message): string => '/contact/?' . http_build_query(
    ['purpose' => 'general', 'subject' => $subject, 'message' => $message], '', '&', PHP_QUERY_RFC3986) . '#contact-form';
$rid = static fn (array $r): string => 'r' . (int) $r['year'] . '-' . trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower((string) $r['title'])), '-');

render_head([
    'title'     => 'Reports — Afrovanguard',
    'desc'      => 'Afrovanguard’s annual and project reports: what each year’s work reached, and when the next report is due.',
    'canonical' => "$S/reports/",
    'robots'    => 'index, follow',
    'css'       => ['/assets/legal.css'],
]);
render_nav('');
?>
  <main id="main" tabindex="-1" class="legal-main">
    <header class="legal-hero">
      <div class="container">
        <span class="legal-kicker">Accountability</span>
        <h1>Reports</h1>
        <p class="legal-lede">What our projects did, year by year. Afrovanguard was founded in 2017 in Alimosho, Lagos.</p>
      </div>
    </header>

    <div class="legal-body">
      <div class="container">
        <div class="legal-content rep-wrap">
<?php if ($head['latest'] || $head['next']): ?>
          <div class="rep-glance" aria-label="At a glance">
<?php if ($head['latest']): ?>            <div><span>Latest</span><a href="#<?= e($rid($head['latest'])) ?>"><?= e($head['latest']['title']) ?></a></div>
<?php endif; if ($head['next']): ?>            <div><span>Next</span><b><?= e($head['next']['title']) ?></b> <small>expected <?= e($head['next']['expected']) ?></small></div>
<?php endif; ?>          </div>
<?php endif; ?>
<?php if (count($years) > 1): ?>
          <nav class="rep-years" aria-label="Jump to a year">
<?php foreach (array_keys($years) as $y): ?>            <a href="#y<?= (int) $y ?>"><?= (int) $y ?></a>
<?php endforeach; ?>            <a href="#earlier">Earlier</a>
          </nav>
<?php endif; ?>
<?php if (!$rows): ?>
          <p class="rep-empty">No reports are published here yet. Email <a href="mailto:<?= e($contact) ?>"><?= e($contact) ?></a> and we will send you the latest.</p>
<?php endif; ?>
<?php foreach ($years as $year => $list): ?>
          <section class="legal-section rep-year" id="y<?= (int) $year ?>" aria-labelledby="y<?= (int) $year ?>-h">
            <h2 id="y<?= (int) $year ?>-h"><?= (int) $year ?></h2>
            <ul class="rep-list">
<?php foreach ($list as $r): $ext = $r['href'] !== '' && !str_starts_with($r['href'], '/'); ?>
              <li class="rep-item<?= $r['status'] === 'preparing' ? ' rep-item--soon' : '' ?>" id="<?= e($rid($r)) ?>">
                <div class="rep-top">
                  <span class="rep-kind"><?= e(Reports::KINDS[$r['kind']]) ?></span>
<?php if ($r['status'] === 'partial'): ?>                  <span class="rep-badge">Partly complete</span>
<?php elseif ($r['status'] === 'preparing'): ?>                  <span class="rep-badge rep-badge--soon">In preparation</span>
<?php endif; ?>                </div>
                <h3 class="rep-title"><?= e($r['title']) ?></h3>
<?php if ($r['note'] !== ''): ?>                <p class="rep-note"><?= e($r['note']) ?></p>
<?php endif; ?>
<?php if ($r['status'] === 'preparing'): ?>
                <p class="rep-meta">Expected <?= e($r['expected']) ?>.</p>
                <div class="rep-actions"><a class="rep-btn rep-btn--line" href="<?= e($ask('Send me the ' . $r['title'], 'Please email me the ' . $r['title'] . ' when it is published.')) ?>">Email me when it&rsquo;s out</a></div>
<?php else: ?>
<?php if ($r['label'] !== ''): ?>                <p class="rep-meta"><?= e($r['label']) ?></p>
<?php endif; ?>
                <div class="rep-actions">
                  <a class="rep-btn" href="<?= e($r['href']) ?>" target="_blank" rel="noopener">Read online<span class="av-sr"> — <?= e($r['title']) ?> (opens in a new tab)</span></a>
<?php if ($r['download'] !== ''): ?>                  <a class="rep-btn rep-btn--line" href="<?= e($r['href']) ?>" download="<?= e($r['download']) ?>">Download<span class="av-sr"> — <?= e($r['title']) ?></span></a>
<?php endif; ?>                </div>
<?php endif; ?>
              </li>
<?php endforeach; ?>
            </ul>
          </section>
<?php endforeach; ?>
          <section class="legal-section rep-year" id="earlier" aria-labelledby="earlier-h">
            <h2 id="earlier-h">Earlier years</h2>
            <div class="rep-item rep-item--plain">
              <p class="rep-note">Reports from 2017 onwards, and the figures behind any number on this site, are available on request while we prepare them for publishing here.</p>
              <div class="rep-actions"><a class="rep-btn rep-btn--line" href="<?= e($ask('Request: an earlier Afrovanguard report', 'Please send me the report for the year: ')) ?>">Request a report</a></div>
            </div>
          </section>

          <p class="rep-foot">Afrovanguard · CACENTRE Egbeda, 2 Oremeji Street, off Akowonjo Road, Bakey Bus Stop, Egbeda, Alimosho, Lagos, Nigeria · <a href="mailto:<?= e($contact) ?>"><?= e($contact) ?></a></p>
        </div>
      </div>
    </div>
  </main>
<?php render_footer(); ?>
