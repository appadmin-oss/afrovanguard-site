<?php
/**
 * diary/shared.php — read-only view of a diary entry shared via a secret link.
 *
 * The author mints an unguessable token for one of their own entries (even a
 * private one) and shares the resulting URL. Anyone with the link can read
 * that single entry — nothing else — and the author can revoke it anytime.
 * noindex: shared links are private-by-link, never surfaced to search.
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_once AV_ROOT . '/lib/partials.php';

$token = (string) ($_GET['t'] ?? '');
$entry = $token !== '' ? (new DiaryJournal())->bySharedToken($token) : null;

if (!$entry) {
    http_response_code(404);
    render_head(['title' => 'Link not found — Afrovanguard Diary', 'robots' => 'noindex, nofollow']);
    render_nav('diary');
    echo '<main id="main-content" class="container" style="padding:80px 0;text-align:center">'
       . '<h1 style="font-family:var(--font-heading)">This shared entry isn’t available</h1>'
       . '<p style="color:var(--muted)">The link may have been revoked, or it’s incorrect. '
       . '<a href="/diary/">Browse the Diary →</a></p></main>';
    render_footer();
    exit;
}

$dateStr = date('F j, Y', strtotime((string) $entry['entry_date']) ?: time());
$title   = (string) ($entry['title'] ?: 'A diary entry');

render_head([
    'title'     => $title . ' — shared from the Afrovanguard Diary',
    'desc'      => DiaryJournal::excerpt((string) $entry['body'], 160),
    'robots'    => 'noindex, nofollow',
    'css'       => ['/diary/diary.css'],
]);
render_nav('diary');
?>
<main id="main-content" class="container shared-entry" style="max-width:760px;padding:48px 0 90px">
  <p class="shared-flag" style="display:inline-flex;align-items:center;gap:8px;font-size:12px;font-weight:700;letter-spacing:.05em;text-transform:uppercase;color:var(--gold-deep);margin:0 0 14px">
    🔗 Shared privately with you
  </p>
  <article>
    <h1 style="font-family:var(--font-heading);font-size:clamp(28px,4vw,42px);line-height:1.15;color:var(--ink);margin:0 0 10px"><?= e($title) ?></h1>
    <p style="color:var(--muted);font-size:14px;margin:0 0 28px">By <?= e((string) $entry['author_name']) ?> · <?= e($dateStr) ?></p>
    <div class="shared-body" style="font-size:17px;line-height:1.75;color:var(--body)"><?= DiaryJournal::bodyToHtml((string) $entry['body']) ?></div>
  </article>
  <p style="margin-top:40px;padding-top:20px;border-top:1px solid var(--divider);color:var(--muted);font-size:14px">
    Shared from the <a href="/diary/">Afrovanguard Diary</a>. Only people with this link can see this entry.
  </p>
</main>
<?php render_footer();
