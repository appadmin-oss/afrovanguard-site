<?php
/**
 * lib/community_view.php — the Afrovanguard Community, rendered as a reusable
 * block so it can live inside the member portal (a tab) rather than as its own
 * page. One source of truth for the markup that community.js enhances.
 *
 *   av_render_community($viewerId, $isOrg, [
 *     'hero'  => bool,   // show the big gradient hero (portal passes false)
 *     'space' => string, // preselected space slug
 *   ]);
 *
 * Space + sort links carry data-attributes so community.js can switch them
 * client-side (no full reload) inside the portal; the hrefs remain valid as a
 * no-JS fallback.
 */
declare(strict_types=1);
require_once __DIR__ . '/Community.php';

if (!function_exists('av_community_card')) {
    /** One post card (SSR; community.js mirrors this markup for appended posts). */
    function av_community_card(array $p, bool $thread = false): string
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
            . (isset($p['classification']) ? '<span class="cm-class cm-class--' . e($p['classification']) . '" title="' . e($p['class_label']) . '">' . e($p['class_label']) . '</span>' : '')
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
}

if (!function_exists('av_render_community')) {
    function av_render_community(int $viewerId, bool $isOrg, array $opt = []): void
    {
        $hero    = $opt['hero'] ?? true;
        $u       = LmsAuth::user();
        $uname   = (string) ($u['name'] ?? 'You');
        $activeSp = isset($opt['space']) && $opt['space'] !== '' ? preg_replace('/[^a-z0-9\-]/', '', strtolower((string) $opt['space'])) : '';
        $sort     = 'latest';
        $PER      = 12;

        $isAdmin = Community::isAdmin($viewerId);
        $orgDirectory = $isOrg ? Community::directory($viewerId, 60) : [];
        $spaces  = Community::spaces();
        $counts  = Community::spaceCounts();
        $pulse   = Community::pulse();
        $posts   = Community::feed($activeSp ?: null, $sort, $PER + 1, 0, $viewerId);
        $hasMore = count($posts) > $PER;
        $posts   = array_slice($posts, 0, $PER);
?>
<div class="cm cm--embed" data-sort="<?= e($sort) ?>" data-space="<?= e($activeSp) ?>" data-initial-space="<?= e($activeSp) ?>" data-signed-in="1" data-org="<?= $isOrg ? '1' : '0' ?>" data-admin="<?= $isAdmin ? '1' : '0' ?>" data-uid="<?= $viewerId ?>">
  <div class="cm-wrap">
<?php if ($hero): ?>
    <header class="cm-hero">
      <div class="cm-hero-txt">
        <span class="cm-online"><span class="cm-online-dot"></span><?= (int) $pulse['members'] ?> members · <?= $isOrg ? 'community' : 'forum' ?></span>
        <h1><?= $isOrg ? 'The Community' : 'Community Forum' ?></h1>
        <p><?= $isOrg
              ? 'Where the people behind Afrovanguard talk, debate ideas, and lift each other up. Verified members, real conversations, fully moderated.'
              : 'Share field notes, ask questions and learn alongside the wider Afrovanguard community. Real conversations, fully moderated.' ?></p>
      </div>
      <div class="cm-hero-stats">
        <div><b><?= number_format((int) $pulse['members']) ?></b><span>Members</span></div>
        <div><b><?= number_format((int) $pulse['posts_today']) ?></b><span>Posts today</span></div>
        <div><b><?= count($spaces) ?></b><span>Spaces</span></div>
      </div>
    </header>
<?php endif; ?>

    <div class="cm-grid">
      <!-- LEFT · SPACES -->
      <aside class="cm-rail cm-rail--l">
        <div class="cm-card cm-spaces">
          <p class="cm-rail-h">Spaces</p>
          <a class="cm-space-link<?= $activeSp === '' ? ' is-active' : '' ?>" href="/community/" data-space="">All activity</a>
<?php foreach ($spaces as $sp): ?>
          <a class="cm-space-link<?= $activeSp === $sp['slug'] ? ' is-active' : '' ?>" href="/community/?space=<?= e($sp['slug']) ?>" data-space="<?= e($sp['slug']) ?>">
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
          <form id="cmCompose" data-default-space="<?= e($activeSp ?: 'open-floor') ?>">
            <div class="cm-compose-row">
              <span class="cm-av cm-av--me"><?= e(mb_strtoupper(mb_substr($uname, 0, 1))) ?></span>
              <textarea id="cmBody" rows="2" maxlength="5000" placeholder="Share something with the community…"></textarea>
            </div>
            <div class="cm-compose-foot">
              <select id="cmSpace" aria-label="Choose a space">
<?php foreach ($spaces as $sp): ?>
                <option value="<?= e($sp['slug']) ?>"<?= ($activeSp ?: 'open-floor') === $sp['slug'] ? ' selected' : '' ?>><?= e($sp['name']) ?></option>
<?php endforeach; ?>
              </select>
<?php $myClasses = Community::allowedClasses(Community::clearance($viewerId)); if (count($myClasses) > 1): ?>
              <select id="cmClass" aria-label="Who can see this post">
<?php foreach ($myClasses as $ck): ?>
                <option value="<?= e($ck) ?>"<?= $ck === 'members' ? ' selected' : '' ?>><?= e(Community::CLASSES[$ck]) ?></option>
<?php endforeach; ?>
              </select>
<?php endif; ?>
              <button type="button" class="cm-ask-btn" id="cmAsk" title="Ask the official Afrovanguard bot">✦ Ask the bot</button>
<?php if ($isAdmin): ?>              <button type="button" class="cm-ask-btn" id="cmAnnounce" title="Post an official Afrovanguard announcement">📣 Announce</button>
<?php endif; ?>              <button type="submit" class="cm-post-btn">Post</button>
            </div>
            <p class="cm-msg" role="status" aria-live="polite"></p>
          </form>
        </div>

        <div class="cm-sort">
          <a href="?<?= e($activeSp ? 'space=' . $activeSp . '&' : '') ?>sort=latest" class="cm-sort-tab is-active" data-sort="latest">Latest</a>
          <a href="?<?= e($activeSp ? 'space=' . $activeSp . '&' : '') ?>sort=top" class="cm-sort-tab" data-sort="top">Top</a>
          <span class="cm-feed-label"><?= $activeSp ? e(ucwords(str_replace('-', ' ', $activeSp))) : 'All activity' ?></span>
        </div>

        <div id="cmFeed" class="cm-posts">
<?php if (!$posts): ?>
          <div class="cm-empty">No posts here yet. Be the first to share something.</div>
<?php else: foreach ($posts as $p) { echo av_community_card($p); } endif; ?>
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
<?php if ($isOrg): ?>
        <!-- ORG-ONLY · who's here -->
        <div class="cm-card cm-directory">
          <p class="cm-rail-h">Members · <span id="cmDirCount"><?= count($orgDirectory) ?></span></p>
          <div class="cm-dir-list" id="cmDirectory">
<?php foreach ($orgDirectory as $m): ?>
            <div class="cm-dir-row" data-member="<?= (int) $m['id'] ?>" tabindex="0" role="button" aria-label="View <?= e($m['name']) ?>'s profile"<?= $m['is_me'] ? ' data-me="1"' : '' ?>>
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
        <div class="cm-card cm-chat" id="cmChat" data-channel="general">
          <p class="cm-rail-h">Members chat <span class="cm-chat-hint">@ to mention</span></p>
          <div class="cm-chan" id="cmChan" role="tablist" aria-label="Chat channels">
<?php foreach (Community::CHAT_CHANNELS as $ck => $cl): ?>            <button type="button" class="cm-chan-btn<?= $ck === 'general' ? ' is-on' : '' ?>" data-chan="<?= e($ck) ?>"># <?= e($cl) ?></button>
<?php endforeach; ?>          </div>
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
          <p>Afrovanguard members get a private directory and live chat. The forum here is open to every learner.</p>
        </div>
<?php endif; ?>
      </aside>
    </div>
  </div>
</div>
<?php
    }
}
