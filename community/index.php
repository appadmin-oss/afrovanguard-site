<?php
/**
 * community/index.php — the Afrovanguard Community (spaces + feed).
 * Branded adaptation of the supplied design: site nav/footer, gold+navy palette.
 * Reading is public; posting is members-only (LmsAuth). First feed page is SSR'd;
 * community.js handles like/reply/post and progressive load-more.
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_once AV_ROOT . '/lib/partials.php';
require_once AV_ROOT . '/lib/Community.php';

$u = LmsAuth::user();
// The Community is a members-only space. Non-members are sent to sign in with a
// clean redirect — never an error page — and returned here afterwards.
if (!$u) {
    if (!headers_sent()) header('Location: /login?next=' . rawurlencode('/community/'));
    echo '<!doctype html><meta charset="utf-8"><title>Sign in — Afrovanguard Community</title>'
       . '<meta http-equiv="refresh" content="0;url=/login?next=%2Fcommunity%2F">'
       . '<p style="font-family:system-ui;margin:3rem">The Community is for Afrovanguard members. '
       . '<a href="/login?next=%2Fcommunity%2F">Sign in to continue →</a></p>';
    exit;
}
$viewerId = (int) $u['id'];
// Org members get the full community (directory + chat + @mentions); external
// members get the forum (the spaces/posts feed) only.
$isOrgMember = Community::isOrgMember($viewerId);
$orgDirectory = $isOrgMember ? Community::directory($viewerId, 60) : [];
$spaces   = Community::spaces();
$counts   = Community::spaceCounts();
$pulse    = Community::pulse();
$activeSp = ($_GET['space'] ?? '') !== '' ? preg_replace('/[^a-z0-9\-]/', '', strtolower((string) $_GET['space'])) : '';
$sort     = ($_GET['sort'] ?? '') === 'top' ? 'top' : 'latest';
$PER      = 12;
$posts    = Community::feed($activeSp ?: null, $sort, $PER + 1, 0, $viewerId);
$hasMore  = count($posts) > $PER;
$posts    = array_slice($posts, 0, $PER);

/** One post card (SSR; community.js mirrors this markup for appended posts). */
function comm_card(array $p, bool $thread = false): string
{
    $verified = $p['verified'] ? '<svg class="cm-check" viewBox="0 0 24 24" width="15" height="15" aria-label="Verified"><path fill="currentColor" d="m12 1 2.6 1.9 3.2-.3 1 3 2.7 1.8-1 3 1 3-2.7 1.8-1 3-3.2-.3L12 23l-2.6-1.9-3.2.3-1-3L2.5 16.6l1-3-1-3 2.7-1.8 1-3 3.2.3z"/><path d="m8.5 12 2.4 2.4 4.6-4.8" stroke="#fff" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round"/></svg>' : '';
    $pin = $p['pinned'] ? '<div class="cm-pin">📌 Pinned by the team</div>' : '';
    $likeCls = $p['liked'] ? ' is-liked' : '';
    $body = nl2br(e($p['body']));
    return '<article class="cm-post' . ($p['is_bot'] ? ' is-bot' : '') . '" data-id="' . (int) $p['id'] . '">'
        . $pin
        . '<div class="cm-post-in">'
        . '<div class="cm-head">'
        . '<span class="cm-av" style="--c:' . e($p['space']['color']) . '">' . e($p['initial']) . '</span>'
        . '<div class="cm-meta"><div class="cm-line1"><span class="cm-author">' . e($p['author']) . '</span>' . $verified
        . '<span class="cm-tier cm-tier--' . e(strtolower($p['tier'])) . '">' . e($p['tier']) . '</span>'
        . '<span class="cm-dot">·</span><span class="cm-ago">' . e($p['ago']) . '</span></div>'
        . '<div class="cm-line2"><span class="cm-space" style="--c:' . e($p['space']['color']) . '">' . e($p['space']['name']) . '</span></div></div></div>'
        . '<div class="cm-body">' . $body . '</div>'
        . ($thread ? '' : '<div class="cm-bar">'
            . '<button class="cm-act cm-like' . $likeCls . '" data-like="' . (int) $p['id'] . '" aria-pressed="' . ($p['liked'] ? 'true' : 'false') . '">'
            . '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.9"><path d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.6l-1-1a5.5 5.5 0 0 0-7.8 7.8l1 1L12 21l7.8-7.6 1-1a5.5 5.5 0 0 0 0-7.8Z"/></svg>'
            . '<span class="cm-likes">' . (int) $p['likes'] . '</span></button>'
            . '<button class="cm-act cm-reply-toggle" data-reply="' . (int) $p['id'] . '">'
            . '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M21 11.5a8.4 8.4 0 0 1-9 8.4 9 9 0 0 1-3.9-.9L3 20l1-3.1A8.4 8.4 0 1 1 21 11.5Z"/></svg>'
            . '<span class="cm-replies">' . (int) $p['reply_count'] . '</span></button></div>'
            . '<div class="cm-thread" data-thread="' . (int) $p['id'] . '" hidden></div>')
        . '</div></article>';
}

render_head([
    'title'      => 'Community — Afrovanguard',
    'desc'       => 'Where the people behind the movement talk, share field notes, and lift each other up. Verified members, real conversations, fully moderated.',
    'canonical'  => rtrim(SITE_URL, '/') . '/community/',
    'css'        => ['/community/community.css'],
]);
render_nav('community');
?>
<main id="main-content" class="cm" data-sort="<?= e($sort) ?>" data-space="<?= e($activeSp) ?>" data-signed-in="<?= $u ? '1' : '0' ?>" data-org="<?= $isOrgMember ? '1' : '0' ?>" data-uid="<?= $viewerId ?>">
  <div class="cm-wrap">
    <!-- HERO -->
    <header class="cm-hero">
      <div class="cm-hero-txt">
        <span class="cm-online"><span class="cm-online-dot"></span><?= (int) $pulse['members'] ?> members · <?= $isOrgMember ? 'community' : 'forum' ?></span>
        <h1><?= $isOrgMember ? 'The Community' : 'Community Forum' ?></h1>
        <p><?= $isOrgMember
              ? 'Where the people behind Afrovanguard talk, debate ideas, and lift each other up. Verified members, real conversations, fully moderated.'
              : 'Share field notes, ask questions and learn alongside the wider Afrovanguard community. Real conversations, fully moderated.' ?></p>
      </div>
      <div class="cm-hero-stats">
        <div><b><?= number_format((int) $pulse['members']) ?></b><span>Members</span></div>
        <div><b><?= number_format((int) $pulse['posts_today']) ?></b><span>Posts today</span></div>
        <div><b><?= count($spaces) ?></b><span>Spaces</span></div>
      </div>
    </header>

    <div class="cm-grid">
      <!-- LEFT · SPACES -->
      <aside class="cm-rail cm-rail--l">
        <div class="cm-card cm-spaces">
          <p class="cm-rail-h">Spaces</p>
          <a class="cm-space-link<?= $activeSp === '' ? ' is-active' : '' ?>" href="/community/">All activity</a>
<?php foreach ($spaces as $sp): ?>
          <a class="cm-space-link<?= $activeSp === $sp['slug'] ? ' is-active' : '' ?>" href="/community/?space=<?= e($sp['slug']) ?>">
            <span class="cm-space-dot" style="background:<?= e($sp['color']) ?>"></span>
            <span class="cm-space-name"><?= e($sp['name']) ?></span>
            <span class="cm-space-n"><?= (int) ($counts[$sp['slug']] ?? 0) ?></span>
          </a>
<?php endforeach; ?>
        </div>
        <div class="cm-card cm-guidelines">
          <p class="cm-rail-h">House rules</p>
          <ul><li>Debate the work, never the person.</li><li>Sources beat opinions.</li><li>Keep canvassing out of the threads.</li></ul>
        </div>
      </aside>

      <!-- CENTER · FEED -->
      <section class="cm-feed">
        <div class="cm-composer">
<?php if ($u): ?>
          <form id="cmCompose" data-default-space="<?= e($activeSp ?: 'open-floor') ?>">
            <div class="cm-compose-row">
              <span class="cm-av cm-av--me"><?= e(mb_strtoupper(mb_substr((string) $u['name'], 0, 1))) ?></span>
              <textarea id="cmBody" rows="2" maxlength="5000" placeholder="Share something with the community…"></textarea>
            </div>
            <div class="cm-compose-foot">
              <select id="cmSpace" aria-label="Choose a space">
<?php foreach ($spaces as $sp): ?>
                <option value="<?= e($sp['slug']) ?>"<?= ($activeSp ?: 'open-floor') === $sp['slug'] ? ' selected' : '' ?>><?= e($sp['name']) ?></option>
<?php endforeach; ?>
              </select>
              <button type="button" class="cm-ask-btn" id="cmAsk" title="Ask the official Afrovanguard bot">✦ Ask the bot</button>
              <button type="submit" class="cm-post-btn">Post</button>
            </div>
            <p class="cm-msg" role="status" aria-live="polite"></p>
          </form>
<?php else: ?>
          <div class="cm-signin-prompt">
            <p><strong>Join the conversation.</strong> Sign in to post, reply and react.</p>
            <a class="cm-post-btn" data-login-link href="/login">Sign in</a>
          </div>
<?php endif; ?>
        </div>

        <div class="cm-sort">
          <a href="?<?= e($activeSp ? 'space=' . $activeSp . '&' : '') ?>sort=latest" class="cm-sort-tab<?= $sort === 'latest' ? ' is-active' : '' ?>">Latest</a>
          <a href="?<?= e($activeSp ? 'space=' . $activeSp . '&' : '') ?>sort=top" class="cm-sort-tab<?= $sort === 'top' ? ' is-active' : '' ?>">Top</a>
          <span class="cm-feed-label"><?= $activeSp ? e(ucwords(str_replace('-', ' ', $activeSp))) : 'All activity' ?></span>
        </div>

        <div id="cmFeed" class="cm-posts">
<?php if (!$posts): ?>
          <div class="cm-empty">No posts here yet. <?= $u ? 'Be the first to share something.' : 'Sign in to start the conversation.' ?></div>
<?php else: foreach ($posts as $p) { echo comm_card($p); } endif; ?>
        </div>
        <button id="cmMore" class="cm-more"<?= $hasMore ? '' : ' hidden' ?> data-offset="<?= $PER ?>">Load older posts</button>
      </section>

      <!-- RIGHT · PULSE -->
      <aside class="cm-rail cm-rail--r">
        <div class="cm-card cm-pulse">
          <p class="cm-rail-h cm-rail-h--light">Community pulse · today</p>
          <div class="cm-pulse-grid">
            <div><b><?= number_format((int) $pulse['posts_today']) ?></b><span>New posts</span></div>
            <div><b><?= number_format((int) $pulse['replies_today']) ?></b><span>Replies</span></div>
            <div><b><?= count($spaces) ?></b><span>Spaces</span></div>
            <div><b><?= number_format((int) $pulse['members']) ?></b><span>Members</span></div>
          </div>
        </div>
<?php if ($isOrgMember): ?>
        <!-- ORG-ONLY · who's here -->
        <div class="cm-card cm-directory">
          <p class="cm-rail-h">Members · <span id="cmDirCount"><?= count($orgDirectory) ?></span></p>
          <div class="cm-dir-list" id="cmDirectory">
<?php foreach ($orgDirectory as $m): ?>
            <div class="cm-dir-row"<?= $m['is_me'] ? ' data-me="1"' : '' ?>>
              <span class="cm-dir-av"><?= e($m['initial']) ?></span>
              <span class="cm-dir-txt">
                <span class="cm-dir-name"><?= e($m['name']) ?><?= $m['is_me'] ? ' <em>(you)</em>' : '' ?></span>
<?php if ($m['headline'] !== ''): ?>                <span class="cm-dir-role"><?= e($m['headline']) ?></span>
<?php endif; ?>              </span>
            </div>
<?php endforeach; ?>
          </div>
        </div>
        <!-- ORG-ONLY · live members chat (@mention to tag) -->
        <div class="cm-card cm-chat" id="cmChat">
          <p class="cm-rail-h">Members chat <span class="cm-chat-hint">@ to mention</span></p>
          <div class="cm-chat-log" id="cmChatLog" aria-live="polite" aria-label="Members chat messages">
            <div class="cm-chat-empty">Say hello — this channel is just for Afrovanguard members.</div>
          </div>
          <form class="cm-chat-form" id="cmChatForm" autocomplete="off">
            <div class="cm-chat-inwrap">
              <textarea id="cmChatInput" rows="1" maxlength="2000" placeholder="Message members… use @ to mention" aria-label="Write a message"></textarea>
              <div class="cm-mention-pop" id="cmMentionPop" role="listbox" hidden></div>
            </div>
            <button type="submit" class="cm-chat-send" aria-label="Send message">
              <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M22 2 11 13M22 2l-7 20-4-9-9-4 20-7Z"/></svg>
            </button>
          </form>
          <p class="cm-chat-msg" role="status" aria-live="polite"></p>
        </div>
<?php else: ?>
        <div class="cm-card cm-event">
          <span class="cm-event-tag">Members</span>
          <h3>Behind the movement</h3>
          <p>Afrovanguard members get a private directory and live chat. Reading and the forum are open to everyone here.</p>
        </div>
<?php endif; ?>
      </aside>
    </div>
  </div>
</main>
<script src="/community/community.js" defer></script>
<?php render_footer();
