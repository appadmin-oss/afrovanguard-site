<?php
/**
 * portal/announcement.php — one announcement, read in full.
 *
 * Today shows the latest announcement as a single scrolling line (a marquee:
 * it never wraps into a paragraph on a phone). "Read" opens it here, whole.
 *
 * Only posts in the Announcements space, and only those the member's clearance
 * allows — Community::post() refuses one above it, so an id cannot be used to
 * read something they could not see in the feed.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_once AV_ROOT . '/lib/partials.php';
require_once AV_ROOT . '/lib/Community.php';   /* not in the bootstrap: the portal loads it through lib/community_view.php */

$u = LmsAuth::user();
if (!$u) { header('Location: ' . av_login_url('/portal/announcement.php?id=' . (int) ($_GET['id'] ?? 0))); exit; }

$post = null;
try { $post = Community::post((int) ($_GET['id'] ?? 0), (int) $u['id']); } catch (Throwable $e) { error_log('[announcement] ' . $e->getMessage()); }
if ($post && ($post['space']['slug'] ?? '') !== 'announcements') $post = null;
if (!$post) http_response_code(404);

$body  = $post ? trim((string) $post['body']) : '';
$title = $post ? trim((string) strtok($body, "\n")) : 'Announcement not found';
$rest  = $post ? trim((string) substr($body, strlen((string) strtok($body, "\n")))) : '';
$ptheme = (($_COOKIE['av_portal_theme'] ?? 'light') === 'dark') ? 'dark' : 'light';

render_head([
    'title'      => ($post ? mb_strimwidth($title, 0, 60, '…') : 'Announcement') . ' — Afrovanguard',
    'desc'       => 'An announcement from the Afrovanguard secretariat.',
    'canonical'  => rtrim(SITE_URL, '/') . '/portal/announcement.php',
    'robots'     => 'noindex, nofollow',
    'body_class' => 'portal-app avp' . ($ptheme === 'dark' ? ' is-dark' : ''),
    'css'        => ['/assets/site/av-tokens.css', '/portal/avp.css', '/portal/home.css'],
    'chrome'     => 'app',
]);
?>
<main id="main-content" class="hv hv-read" tabindex="-1">
  <article class="hv-read-card" aria-labelledby="hvReadT">
    <p class="hv-lbl">Announcement</p>
<?php if ($post): ?>
    <h1 class="hv-read-t" id="hvReadT"><?= e($title) ?></h1>
    <p class="hv-small hv-muted"><?= e((string) $post['author']) ?> · <time datetime="<?= e((string) $post['created_at']) ?>"><?= e(date('j M Y, H:i', strtotime((string) $post['created_at']) ?: time())) ?></time></p>
<?php if ($rest !== ''): ?>
    <div class="hv-read-body"><?= nl2br(e($rest)) ?></div>
<?php endif; ?>
<?php else: ?>
    <h1 class="hv-read-t" id="hvReadT">This announcement is not here</h1>
    <p class="hv-muted">It may have been taken down, or it is not one you can see.</p>
<?php endif; ?>
    <p class="hv-read-acts">
      <a class="hv-btn hv-btn--ink" href="/portal/#overview">Back to Today</a>
      <a class="hv-btn" href="/portal/?space=announcements#community">All announcements</a>
    </p>
  </article>
</main>
</body>
</html>
