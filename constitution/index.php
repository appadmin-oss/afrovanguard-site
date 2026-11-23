<?php
/**
 * constitution/index.php — /constitution/: the Constitution of Afrovanguard
 * (2026 Revised Edition). INTERNAL: verified Afrovanguard members only —
 * signed-out visitors go to sign-in, everyone else gets a 403. Not indexed,
 * not in the sitemap, not linked from the public footer.
 *
 * The text lives in docs/governance/constitution.md (docs/ is never served over
 * HTTP; this page reads it on the server). Edit the Markdown, not this file:
 * the page builds its table of contents and anchors from the headings —
 *   ## Part …      a part divider
 *   ### Article …  a TOC entry, id "article-<roman>"
 *   #### Section … an anchor, id "article-<roman>-section-<n>"
 *   ### Schedule … a TOC entry, id "schedule-<letter>"
 * Everything above the first "---" rule (title, status, document control) is
 * shown in the page header instead of the body.
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_once AV_ROOT . '/lib/partials.php';

header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: private, no-store');
$u = LmsAuth::user();
if (!$u) { header('Location: ' . av_login_url('/constitution/')); exit; }
if (!LmsAuth::isOrgMember($u)) {
    http_response_code(403);
    render_head(['title' => 'Members only — Afrovanguard', 'robots' => 'noindex, nofollow', 'css' => ['/assets/legal.css']]);
    render_nav('');
    echo '<main id="main" tabindex="-1" class="legal-main"><header class="legal-hero"><div class="container">'
       . '<span class="legal-kicker">Internal</span><h1>Members only</h1>'
       . '<p class="legal-lede">The Constitution of Afrovanguard is available to verified Afrovanguard members. '
       . 'If you are a member and see this, ask the membership team to verify your account.</p>'
       . '</div></header></main>';
    render_footer();
    exit;
}

$S         = rtrim(SITE_URL, '/');
$source    = AV_ROOT . '/docs/governance/constitution.md';
$updated   = '23 November 2026';

/** Render the Markdown body and collect the TOC. Returns [html, toc]. */
function av_constitution_render(string $md): array
{
    // Drop the front matter: everything before the first horizontal rule.
    $parts = preg_split('/^---\s*$/m', $md, 2);
    $body  = trim($parts[1] ?? $md);

    if (class_exists(\League\CommonMark\MarkdownConverter::class)) {
        $env = new \League\CommonMark\Environment\Environment([
            'html_input' => 'escape', 'allow_unsafe_links' => false,
        ]);
        $env->addExtension(new \League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension());
        $env->addExtension(new \League\CommonMark\Extension\Table\TableExtension());
        $html = (string) (new \League\CommonMark\MarkdownConverter($env))->convert($body);
    } else {
        $html = '<pre>' . htmlspecialchars($body, ENT_QUOTES, 'UTF-8') . '</pre>';
    }

    $toc = [];           // [['part' => title, 'items' => [[id, label], …]], …]
    $article = '';
    $html = preg_replace_callback('~<h([234])>(.*?)</h\1>~s', static function ($m) use (&$toc, &$article) {
        $level = (int) $m[1];
        $text  = trim(html_entity_decode(strip_tags($m[2]), ENT_QUOTES, 'UTF-8'));
        if ($level === 2) {
            $id = 'part-' . trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($text)), '-');
            $toc[] = ['part' => $text, 'id' => $id, 'items' => []];
            $article = '';
            return '<h2 class="const-part" id="' . $id . '">' . $m[2] . '</h2>';
        }
        if ($level === 3) {
            if (preg_match('/^Article\s+([IVXLC]+)/', $text, $a))      $id = 'article-' . strtolower($a[1]);
            elseif (preg_match('/^Schedule\s+([A-Z])/', $text, $a))    $id = 'schedule-' . strtolower($a[1]);
            else $id = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($text)), '-');
            $article = $id;
            if ($toc) $toc[count($toc) - 1]['items'][] = [$id, $text];
            return '<h3 class="const-article" id="' . $id . '">' . $m[2] . '</h3>';
        }
        $id = '';
        if ($article !== '' && preg_match('/^Section\s+(\d+)/', $text, $s)) $id = $article . '-section-' . $s[1];
        return $id !== '' ? '<h4 id="' . $id . '">' . $m[2] . '</h4>' : '<h4>' . $m[2] . '</h4>';
    }, $html) ?? $html;

    // Tables scroll inside their own box on narrow screens.
    $html = preg_replace('~<table>~', '<div class="legal-table-wrap" tabindex="0"><table class="legal-table">', $html) ?? $html;
    $html = str_replace('</table>', '</table></div>', $html);
    // Lettered and numbered clauses carry their own markers.
    $html = preg_replace('~<ul>(\s*<li>\([a-z]\))~', '<ul class="const-clauses">$1', $html) ?? $html;

    return [$html, $toc];
}

$md = is_file($source) ? (string) file_get_contents($source) : '';
if ($md === '') {
    http_response_code(503);
    $html = '<p>The constitution is being prepared. Please check back shortly.</p>';
    $toc  = [];
} else {
    [$html, $toc] = av_constitution_render($md);
}

render_head([
    'title'     => 'Constitution — Afrovanguard',
    'desc'      => 'The Constitution of Afrovanguard (2026 Revised Edition). Internal — members only.',
    'robots'    => 'noindex, nofollow',
    'css'       => ['/assets/legal.css', '/assets/constitution.css'],
]);
render_nav('');
?>
  <main id="main" tabindex="-1" class="legal-main const-main">
    <header class="legal-hero">
      <div class="container">
        <span class="legal-kicker">Internal &middot; Members only</span>
        <h1>The Constitution of Afrovanguard</h1>
        <p class="legal-lede">Ambassadors for Community, Tech and Cultural Advancements &middot; <em>The Force for Good</em>. Foundational Constitution, 2026 Revised Edition. Founded as Okun Alimosho on 9 November 2017.</p>
        <p class="legal-updated">Version date: <?= e($updated) ?></p>
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
            <div class="legal-callout const-status" role="note">
              <p><strong>Internal document.</strong> For Afrovanguard members only. Please do not share or republish outside the organisation.</p>
            </div>
            <article class="legal-section const-doc">
              <?= $html /* rendered from our own Markdown; raw HTML in it is escaped */ ?>
            </article>
          </div>
        </div>
      </div>
    </div>
  </main>
<?php render_footer(); ?>
