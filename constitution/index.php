<?php
/**
 * constitution/index.php — /constitution/: the Constitution of Afrovanguard.
 * Text, access rule and PDF all live in lib/ConstitutionDoc.php; edit the
 * Markdown in docs/governance/constitution.md, not this file.
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_once AV_ROOT . '/lib/partials.php';
require_once AV_ROOT . '/lib/ConstitutionDoc.php';

ConstitutionDoc::gate('/constitution/');

$S  = rtrim(SITE_URL, '/');
$md = ConstitutionDoc::markdown();
if ($md === '') {
    http_response_code(503);
    $html = '<p>The constitution is being prepared. Please check back shortly.</p>';
    $toc  = [];
} else {
    [$html, $toc] = ConstitutionDoc::render($md);
    $html = ConstitutionDoc::webHtml($html);
}

render_head([
    'title'     => 'Constitution — Afrovanguard',
    'desc'      => 'The Constitution of Afrovanguard (' . ConstitutionDoc::EDITION . '): values, membership, governance, succession, finance and accountability.',
    'canonical' => ConstitutionDoc::PUBLIC ? "$S/constitution/" : '',
    'robots'    => ConstitutionDoc::PUBLIC ? 'index, follow' : 'noindex, nofollow',
    'css'       => ['/assets/legal.css', '/assets/constitution.css'],
]);
render_nav('');
?>
  <main id="main" tabindex="-1" class="legal-main const-main">
    <header class="legal-hero const-hero">
      <div class="container">
        <span class="legal-kicker"><?= ConstitutionDoc::PUBLIC ? 'Governance' : 'Internal &middot; Members only' ?></span>
        <h1>The Constitution of Afrovanguard</h1>
        <p class="const-motto">The Force for Good</p>
        <p class="legal-lede">Ambassadors for Community, Tech and Cultural Advancements. Foundational Constitution, <?= e(ConstitutionDoc::EDITION) ?>. Founded as Okun Alimosho on 9&nbsp;November&nbsp;2017.</p>
        <div class="const-actions">
          <a class="const-btn" href="/constitution/download/" download>
            <svg aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3v12"/><path d="m7 10 5 5 5-5"/><path d="M5 21h14"/></svg>
            Download PDF
          </a>
          <a class="const-btn const-btn--line" href="/constitution/download/?view=1" target="_blank" rel="noopener">Open in browser</a>
        </div>
        <p class="legal-updated">Version date: <?= e(ConstitutionDoc::VERSION) ?></p>
      </div>
    </header>

    <div class="legal-body">
      <div class="container">
        <div class="legal-layout">
          <nav class="legal-toc const-toc" aria-label="Contents">
            <h2>Contents</h2>
            <ol>
              <?php foreach ($toc as $part): ?>
                <li class="const-toc-part">
                  <a href="#<?= e($part['id']) ?>"><?= e($part['part']) ?></a>
                  <?php if ($part['items']): ?>
                    <ol>
                      <?php foreach ($part['items'] as [$id, $label]): ?>
                        <li><a href="#<?= e($id) ?>"><?= e($label) ?></a></li>
                      <?php endforeach; ?>
                    </ol>
                  <?php endif; ?>
                </li>
              <?php endforeach; ?>
            </ol>
          </nav>

          <div class="legal-content">
            <?php if (!ConstitutionDoc::PUBLIC): ?>
            <div class="legal-callout const-status" role="note">
              <p><strong>Internal document.</strong> For Afrovanguard members only. Please do not share or republish outside the organisation.</p>
            </div>
            <?php endif; ?>
            <?= A11y::listen('.const-doc', 'Listen to the constitution') ?>
            <article class="legal-section const-doc">
              <?= $html /* rendered from our own Markdown; raw HTML in it is escaped */ ?>
            </article>
          </div>
        </div>
      </div>
    </div>
  </main>
<?php render_footer(); ?>
