<?php
/**
 * academy/_avcw.php — the frame for the Academy's learner pages that sit
 * outside the player: My learning, Grades, Discussions, Programmes and the
 * instructor's grading queue. Same chrome as the catalogue and course pages.
 * Styles: academy/avcw.css · behaviour: academy/avcw.js.
 */
declare(strict_types=1);
require_once AV_ROOT . '/lib/partials.php';
require_once AV_ROOT . '/partials/avh-chrome.php';
require_once __DIR__ . '/_avac.php';

/** Open the page. $o: title, desc, canonical, private (noindex), crumbs [[label, url|null]…], jsonld[] */
function avcw_open(array $o): void
{
    avac_head([
        'title' => (string) $o['title'],
        'desc' => (string) ($o['desc'] ?? ''),
        'canonical' => (string) ($o['canonical'] ?? rtrim(SITE_URL, '/') . '/academy/'),
        'robots' => !empty($o['private']) ? 'noindex, nofollow' : null,
        'jsonld' => $o['jsonld'] ?? [],
        'css' => ['/assets/site/avh.css', '/academy/avac.css', '/academy/avcw.css'],
        'js' => ['/assets/site/avh.js', '/academy/avac.js', '/academy/avcw.js'],
    ]);
    echo '<body class="avh avac avcw" id="top">' . "\n" . '<a class="avh-skip" href="#main">Skip to content</a>' . "\n" . '<div class="avh-page">' . "\n";
    avh_nav();
    echo '<main id="main" tabindex="-1" class="avcw-main avh-pad"><div class="avh-wrap">' . "\n";
    if (!empty($o['crumbs'])) {
        echo '<nav aria-label="Breadcrumb"><ol class="avac-crumbs">';
        foreach ($o['crumbs'] as [$label, $url]) {
            echo $url ? '<li><a href="' . e((string) $url) . '">' . e((string) $label) . '</a></li>' : '<li><span aria-current="page">' . e((string) $label) . '</span></li>';
        }
        echo '</ol></nav>' . "\n";
    }
}

function avcw_close(): void
{
    echo "</div></main>\n";
    avh_footer();
    echo "</div>\n<div class=\"avac-toast\" data-avac-toast role=\"status\" aria-live=\"polite\" hidden></div>\n</body>\n</html>";
}

/** Send a signed-out visitor to sign in, coming back here after. */
function avcw_need_login(): array
{
    $u = LmsAuth::user();
    if ($u) return $u;
    header('Location: /login?next=' . rawurlencode((string) ($_SERVER['REQUEST_URI'] ?? '/academy/')), true, 302);
    exit;
}

/** A status word as a chip: passed · failed · pending · returned · not_started. */
function avcw_status(string $st): string
{
    $m = ['passed' => ['Passed', 'ok'], 'failed' => ['Not passed', 'bad'], 'pending' => ['Awaiting grade', 'wait'],
          'returned' => ['Returned', 'bad'], 'not_started' => ['Not started', 'idle'], 'submitted' => ['Submitted', 'wait'], 'graded' => ['Graded', 'ok']];
    [$t, $c] = $m[$st] ?? [ucfirst($st), 'idle'];
    return '<span class="avcw-chip avcw-chip--' . $c . '">' . e($t) . '</span>';
}
