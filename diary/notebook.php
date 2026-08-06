<?php
/**
 * diary/notebook.php — read-only view of a whole NOTEBOOK shared via a link.
 *
 * The sibling of shared.php, one level up: that shares a single entry, this
 * shares the container. The difference matters for ongoing work — a link to a
 * notebook keeps showing entries added after it was sent, which is the whole
 * reason notebook sharing exists (see lib/DiaryNotebooks).
 *
 * Deliberately absent, both from the page and from the data behind it:
 *
 *   · who else the notebook is shared with — a link is held by people the
 *     owner has not vetted, and the member list is not theirs to see
 *   · anything editable — no compose box, no tabs to open, no account prompt
 *
 * noindex, like every by-link surface here.
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_once AV_ROOT . '/lib/partials.php';

$token = (string) ($_GET['t'] ?? '');
$view  = $token !== '' ? (new DiaryNotebooks())->byLinkToken($token) : null;

if (!$view) {
    http_response_code(404);
    render_head(['title' => 'Notebook not found — Afrovanguard Diary', 'robots' => 'noindex, nofollow']);
    render_nav('diary');
    echo '<main id="main-content" class="container" style="padding:80px 0;text-align:center">'
       . '<h1 style="font-family:var(--font-heading)">This notebook isn’t available</h1>'
       . '<p style="color:var(--muted)">The link may have been revoked, or it’s incorrect. '
       . '<a href="/diary/">Browse the Diary →</a></p></main>';
    render_footer();
    exit;
}

$nb      = $view['notebook'];
$entries = $view['entries'];

render_head([
    'title'  => $nb['name'] . ' — a notebook shared from the Afrovanguard Diary',
    'desc'   => $nb['description'] !== '' ? $nb['description'] : ('A notebook shared by ' . $nb['owner_name'] . '.'),
    'robots' => 'noindex, nofollow',
    'css'    => ['/diary/diary.css'],
]);
render_nav('diary');

/** Spine colours mirror lib/DiaryNotebooks::COLOURS. */
$spines = ['ink' => '#10292c', 'green' => '#237b22', 'gold' => '#c9a24b',
           'clay' => '#b0453f', 'sky' => '#3d6f8e', 'plum' => '#6b4674'];
$spine  = $spines[$nb['colour']] ?? $spines['ink'];
?>
<main id="main-content" class="container" style="max-width:820px;padding:48px 0 90px">
  <p style="display:inline-flex;align-items:center;gap:8px;font-size:12px;font-weight:700;letter-spacing:.05em;text-transform:uppercase;color:var(--gold-deep);margin:0 0 16px">
    🔗 A notebook shared privately with you
  </p>

  <header style="display:flex;gap:16px;align-items:flex-start;margin:0 0 34px">
    <span aria-hidden="true" style="flex:0 0 auto;width:5px;align-self:stretch;min-height:52px;border-radius:3px;background:<?= e($spine) ?>"></span>
    <div>
      <h1 style="font-family:var(--font-heading);font-size:clamp(26px,4vw,38px);line-height:1.15;color:var(--ink);margin:0 0 8px"><?= e($nb['name']) ?></h1>
      <?php if ($nb['description'] !== ''): ?>
        <p style="color:var(--body);font-size:15.5px;line-height:1.6;margin:0 0 8px;max-width:62ch"><?= e($nb['description']) ?></p>
      <?php endif; ?>
      <p style="color:var(--muted);font-size:14px;margin:0">
        Kept by <?= e($nb['owner_name']) ?> · <?= count($entries) ?> entr<?= count($entries) === 1 ? 'y' : 'ies' ?>
      </p>
    </div>
  </header>

  <?php if (!$entries): ?>
    <p style="color:var(--muted);font-size:15px">This notebook is empty for now.</p>
  <?php else: foreach ($entries as $e):
      $when = date('j F Y', strtotime((string) $e['entry_date']) ?: time());
  ?>
    <article style="padding:22px 0;border-top:1px solid var(--divider)">
      <h2 style="font-family:var(--font-heading);font-size:20px;line-height:1.3;color:var(--ink);margin:0 0 6px">
        <?= e((string) ($e['title'] ?: 'Untitled entry')) ?>
      </h2>
      <p style="color:var(--muted);font-size:13px;margin:0 0 12px">
        <?= e($when) ?><?php if ((string) $e['author_name'] !== $nb['owner_name']): ?> · <?= e((string) $e['author_name']) ?><?php endif; ?>
      </p>
      <div style="font-size:16px;line-height:1.72;color:var(--body)"><?= DiaryJournal::bodyToHtml((string) $e['body']) ?></div>
    </article>
  <?php endforeach; endif; ?>

  <p style="margin-top:40px;padding-top:20px;border-top:1px solid var(--divider);color:var(--muted);font-size:14px">
    Shared from the <a href="/diary/">Afrovanguard Diary</a>. Only people with this link can see this notebook,
    and the owner can revoke it at any time.
  </p>
</main>
<?php render_footer();
