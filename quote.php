<?php
/**
 * quote.php — the page a shared quote of the week opens: /quote/week-N.
 *
 * The Home share menu links here (WhatsApp, LinkedIn, X, Copy link) and it
 * was a 404. It shows that week's quote from the one list Home rotates
 * (lib/QuoteOfWeek.php ← assets/site/avh.js), the card to download, and
 * uses the card as og:image so the link previews with it. No text is taken
 * from the URL: the week number only picks a row of the list.
 */
declare(strict_types=1);

require_once __DIR__ . '/lib/bootstrap.php';
require_once AV_ROOT . '/lib/partials.php';
require_once AV_ROOT . '/lib/QuoteOfWeek.php';

$list = QuoteOfWeek::all();
$now  = QuoteOfWeek::forDate();
$wk   = (int) ($_GET['week'] ?? 0);
if ($wk < 1 || $wk > 53) $wk = $now['n'];
$cur  = $wk === $now['n'];
[$text, $who, $where] = $list ? $list[$wk % count($list)] : [$now['text'], $now['who'], $now['where']];

$S = rtrim(SITE_URL, '/');
render_head([
    'title'      => '“' . mb_strimwidth($text, 0, 70, '…') . '” — ' . $who . ' · Afrovanguard',
    'desc'       => 'Quote of the week from Afrovanguard: ' . $text . ' — ' . $who . ($where !== '' ? ', ' . $where : '') . '.',
    'canonical'  => $S . '/quote/week-' . $wk,
    // The card is drawn for the current week only; an older link previews with the default card.
    'image'      => $cur ? $S . '/quote-card.php?format=feed' : $S . '/assets/og/og-default.png',
    'image_alt'  => 'Quote of the week: ' . $text . ' — ' . $who,
    'css'        => ['/assets/site/avqt.css'],
    'body_class' => 'avqt-page',
]);
render_nav('');
?>
<main id="main" tabindex="-1" class="avqt">
  <section class="avqt-in">
    <p class="avqt-kicker">Quote of the week<?= $cur ? ' · ' . e(preg_replace('/^Week \d+ · /', '', $now['week'])) : ' · Week ' . (int) $wk ?></p>
    <blockquote class="avqt-quote">
      <p>“<?= e($text) ?>”</p>
      <footer><cite><?= e($who) ?></cite><?php if ($where !== ''): ?><span><?= e($where) ?></span><?php endif; ?></footer>
    </blockquote>
<?php if ($cur): ?>
    <figure class="avqt-card">
      <img src="/quote-card.php?format=feed" width="1080" height="1350" alt="This week’s quote card" loading="lazy">
    </figure>
    <div class="avqt-actions" role="group" aria-label="Download the card">
      <a class="avqt-btn avqt-btn--primary" href="/quote-card.php?format=feed&amp;download=1">Download card</a>
      <a class="avqt-btn" href="/quote-card.php?format=square&amp;download=1">Square</a>
      <a class="avqt-btn" href="/quote-card.php?format=story&amp;download=1">Instagram story</a>
    </div>
<?php else: ?>
    <p class="avqt-note">This was the quote for week <?= (int) $wk ?>. <a href="/quote/week-<?= (int) $now['n'] ?>">See this week’s quote</a>.</p>
<?php endif; ?>
    <p class="avqt-back"><a href="/">Afrovanguard home</a> · <a href="/diary/">Read the Diary</a></p>
  </section>
</main>
<?php render_footer(); ?>
