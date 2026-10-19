<?php
/**
 * Diary partials. One file, one function per block. Class prefix avd-.
 * require_once __DIR__ . '/partials.php'; then call the functions where noted.
 * All counts come from DiaryRepository. When counts are unavailable (offline / null) render nothing, never 0.
 */

function avd_compact(int $n): string {
  return $n >= 1000 ? rtrim(rtrim(number_format($n / 1000, 1), '0'), '.') . 'k' : (string)$n;
}

/** Listing card counts. Place under the date line inside each card. */
function avd_card_counts(?array $c): void {
  if ($c === null) return; ?>
  <p class="avd-counts av-num">
    <span><?= e(avd_compact($c['views'])) ?> views</span>
    <span><?= (int)$c['comments'] ?> comments</span>
    <span><?= (int)$c['claps'] ?> <span aria-hidden="true">👏</span><span class="av-sr">applause</span></span>
  </p>
<?php }

/** Listing toolbar: Sort select placed BEFORE the existing search. Keeps chip + search params. */
function avd_sort_select(string $current): void {
  $opts = ['latest' => 'Latest', 'read' => 'Most read', 'discussed' => 'Most discussed']; ?>
  <label class="avd-sort"><span class="av-sr">Sort entries</span>
    <select name="sort" onchange="this.form.submit()">
      <?php foreach ($opts as $k => $t): ?><option value="<?= $k ?>"<?= $k === $current ? ' selected' : '' ?>><?= $t ?></option><?php endforeach; ?>
    </select>
  </label>
<?php }

/** Hero meta additions (entry): views + comments link. Views appear ONLY here on the entry. */
function avd_hero_counts(?array $c): void {
  if ($c === null) return; ?>
  <span class="av-num"><?= e(number_format($c['views'])) ?> views</span>
  <a href="#comments" class="av-num"><?= (int)$c['comments'] ?> comments</a>
<?php }

/** Owner copy, verbatim. Listing: between grid and Series. Entry: after the article, before Keep reading. */
function avd_mission(): void { ?>
  <section class="avd-mission" aria-labelledby="avd-mission-h">
    <div class="avd-mission-copy">
      <p class="avd-mission-lede">Every piece here is about one mission: raising an Incorruptible Generation.</p>
      <h2 id="avd-mission-h">Don’t just watch the future. <em>Help build it.</em></h2>
    </div>
    <div class="avd-mission-actions">
      <p class="avd-eyebrow">Click to:</p>
      <div class="avd-mission-grid">
        <a class="avd-mbtn avd-mbtn--gold" href="/volunteer/">Join.</a>
        <a class="avd-mbtn" href="/mentorship/become-a-mentor/">Mentor.</a>
        <a class="avd-mbtn" href="/contact/">Partner.</a>
        <a class="avd-mbtn" href="/donate/">Donate.</a>
      </div>
    </div>
  </section>
<?php }

/** Listen button: first element inside the article. Hidden when audio is unavailable. */
function avd_listen(array $a, string $slug, ?array $audio): void {
  if (!$audio) return; ?>
  <div class="avd-listen" data-avd-audio-src="/diary/audio.php?slug=<?= e(rawurlencode($slug)) ?>" data-slug="<?= e($slug) ?>" data-title="<?= e($a['title']) ?>" data-duration="<?= (int)$audio['seconds'] ?>">
    <button type="button" class="avd-listen-btn" data-avd-play aria-pressed="false">Listen · <?= (int)ceil($audio['seconds'] / 60) ?> min</button>
    <span class="avd-listen-note">Keeps playing while you read</span>
    <button type="button" class="avd-link" data-avd-resume hidden>Resume from <span class="av-num"></span></button>
  </div>
<?php }

/** Docked audio bar. Render once, at the end of <body> on entry pages that have audio. */
function avd_audio_bar(): void { ?>
  <div class="avd-bar" data-avd-bar hidden role="region" aria-label="Audio player">
    <div class="avd-bar-track"><span class="avd-bar-fill" data-avd-fill></span>
      <input type="range" min="0" max="0" step="5" value="0" data-avd-seek aria-label="Seek">
    </div>
    <div class="avd-bar-row">
      <button type="button" class="avd-bar-play" data-avd-toggle aria-label="Play"><svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path data-avd-icon d="M8 5v14l11-7z" fill="currentColor"/></svg></button>
      <button type="button" class="avd-ibtn" data-avd-skip="-15" aria-label="Back 15 seconds"><svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true"><path d="M12 5V2L7 6l5 4V7a6 6 0 1 1-6 6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg></button>
      <button type="button" class="avd-ibtn" data-avd-skip="15" aria-label="Forward 15 seconds"><svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true"><path d="M12 5V2l5 4-5 4V7a6 6 0 1 0 6 6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg></button>
      <button type="button" class="avd-bar-chapter" data-avd-chapters aria-expanded="false" aria-controls="avd-chlist">
        <span class="avd-bar-ch" data-avd-chtitle></span>
        <span class="avd-bar-time av-num" data-avd-time></span>
      </button>
      <button type="button" class="avd-bar-speed av-num" data-avd-speed aria-label="Playback speed 1×">1×</button>
      <button type="button" class="avd-ibtn" data-avd-close aria-label="Close player"><svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg></button>
    </div>
    <ol class="avd-chlist" id="avd-chlist" data-avd-chlist hidden></ol>
    <p class="av-sr" aria-live="polite" data-avd-live></p>
  </div>
<?php }

/** Engagement bar: after tags, before author card. */
function avd_engagement(array $c, bool $clapped, bool $saved, string $url, string $title): void { ?>
  <div class="avd-engage" data-avd-engage data-url="<?= e($url) ?>" data-title="<?= e($title) ?>">
    <button type="button" class="avd-pill<?= $clapped ? ' is-on' : '' ?>" data-avd-clap aria-pressed="<?= $clapped ? 'true' : 'false' ?>"><span aria-hidden="true">👏</span> <span class="av-num" data-avd-clapn><?= (int)$c['claps'] ?></span><span class="av-sr"> applause</span></button>
    <a class="avd-pill" href="#comments"><span aria-hidden="true">💬</span> <span class="av-num"><?= (int)$c['comments'] ?></span><span class="av-sr"> comments</span></a>
    <span class="avd-flex"></span>
    <button type="button" class="avd-pill<?= $saved ? ' is-on' : '' ?>" data-avd-save aria-pressed="<?= $saved ? 'true' : 'false' ?>"><?= $saved ? 'Saved' : 'Save' ?></button>
    <div class="avd-share">
      <button type="button" class="avd-pill avd-pill--ink" data-avd-share aria-expanded="false" aria-haspopup="menu">Share</button>
      <div class="avd-menu" role="menu" hidden data-avd-sharemenu>
        <a role="menuitem" data-share="whatsapp" target="_blank" rel="noopener">WhatsApp</a>
        <a role="menuitem" data-share="x" target="_blank" rel="noopener">X</a>
        <a role="menuitem" data-share="linkedin" target="_blank" rel="noopener">LinkedIn</a>
        <button type="button" role="menuitem" data-share="copy">Copy link</button>
      </div>
    </div>
  </div>
<?php }

/** Author card. */
function avd_author(array $au): void { $first = explode(' ', $au['name'])[0]; ?>
  <aside class="avd-author">
    <span class="avd-avatar avd-avatar--lg" aria-hidden="true"><?= e($au['initials']) ?></span>
    <div class="avd-author-body">
      <p class="avd-eyebrow avd-eyebrow--muted">Written by</p>
      <p class="avd-author-name"><?= e($au['name']) ?></p>
      <p class="avd-author-role"><?= e($au['role']) ?></p>
      <div class="avd-row">
        <a class="avd-btn" href="/diary/?author=<?= e(rawurlencode($au['slug'])) ?>">More by <?= e($first) ?></a>
        <button type="button" class="avd-btn" data-avd-follow data-author="<?= e($au['slug']) ?>" data-first="<?= e($first) ?>" aria-pressed="false">Follow</button>
      </div>
      <form class="avd-follow-form" data-avd-follow-form hidden novalidate>
        <label for="avd-follow-email">Get an email when <?= e($first) ?> publishes something new.</label>
        <div class="avd-follow-row">
          <input id="avd-follow-email" type="email" name="email" placeholder="you@example.com" autocomplete="email" required>
          <input type="text" name="hp" class="avd-hp" tabindex="-1" autocomplete="off" aria-hidden="true">
          <button type="submit" class="avd-btn avd-btn--ink">Follow</button>
        </div>
      </form>
      <p class="avd-follow-msg" data-avd-follow-msg role="status" aria-live="polite"></p>
    </div>
  </aside>
<?php }

/** Series prev/next. $s = ['name','part','of','prev'=>?['title','url'],'next'=>?[...]] */
function avd_series(?array $s): void { if (!$s) return; ?>
  <nav class="avd-series" aria-label="Series">
    <p class="avd-eyebrow"><?= e($s['name']) ?> · Part <?= (int)$s['part'] ?> of <?= (int)$s['of'] ?></p>
    <div class="avd-series-grid">
      <?php if ($s['prev']): ?><a class="avd-scard" href="<?= e($s['prev']['url']) ?>"><span>← Previous</span><strong><?= e($s['prev']['title']) ?></strong></a><?php else: ?><span></span><?php endif; ?>
      <?php if ($s['next']): ?><a class="avd-scard avd-scard--next" href="<?= e($s['next']['url']) ?>"><span>Next →</span><strong><?= e($s['next']['title']) ?></strong></a><?php endif; ?>
    </div>
  </nav>
<?php }

/** Comments. Server renders first page (3 top-level + replies). JS handles sort, show all, post, like, report. */
function avd_comments(string $slug, int $total, array $comments, string $csrf): void { ?>
  <section class="avd-comments" id="comments" aria-labelledby="avd-c-h" data-avd-comments data-slug="<?= e($slug) ?>" data-total="<?= $total ?>">
    <div class="avd-c-head">
      <h2 id="avd-c-h">Conversation <span class="av-num"><?= $total ?></span></h2>
      <div class="avd-seg" role="radiogroup" aria-label="Sort comments">
        <button type="button" role="radio" aria-checked="true" data-sort="top">Top</button>
        <button type="button" role="radio" aria-checked="false" data-sort="new">Newest</button>
      </div>
    </div>

    <form class="avd-compose" data-avd-compose novalidate>
      <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
      <input type="hidden" name="parent_id" value="">
      <label class="av-sr" for="avd-c-body">Your comment</label>
      <textarea id="avd-c-body" name="body" rows="1" placeholder="Add to the conversation…"></textarea>
      <div class="avd-compose-more" hidden>
        <div class="avd-compose-fields">
          <label>Name<input name="name" autocomplete="name"></label>
          <label>Email <span class="avd-hint">never shown</span><input name="email" type="email" autocomplete="email"></label>
        </div>
        <p class="avd-err" role="alert" data-avd-err></p>
        <button class="avd-btn avd-btn--ink" type="submit">Post</button>
      </div>
    </form>

    <ol class="avd-c-list" data-avd-list>
      <?php if (!$comments): ?>
        <li class="avd-c-empty">Be the first to add to the conversation.</li>
      <?php endif; ?>
      <?php foreach ($comments as $c) avd_comment($c, false); ?>
    </ol>
    <?php if ($total > 3): ?><button type="button" class="avd-btn" data-avd-all>Show all <?= $total ?> comments</button><?php endif; ?>
    <p class="avd-c-foot">Comments are reviewed before they appear publicly. Disagreement is welcome; abuse and spam are removed.</p>
  </section>
<?php }

/** One comment. $c = id, name, initials, is_team, date, body, likes, liked, pending, replies[] */
function avd_comment(array $c, bool $isReply): void { ?>
  <li class="avd-c<?= $isReply ? ' avd-c--reply' : '' ?>" data-id="<?= (int)$c['id'] ?>">
    <span class="avd-avatar" aria-hidden="true"><?= e($c['initials']) ?></span>
    <div class="avd-c-body">
      <p class="avd-c-meta"><strong><?= e($c['name']) ?></strong><?php if ($c['is_team']): ?> <span class="avd-team">Team</span><?php endif; ?> <time><?= e($c['date']) ?></time></p>
      <?php if (!empty($c['pending'])): ?><p class="avd-c-pending">Only you can see this until it’s reviewed.</p><?php endif; ?>
      <p class="avd-c-text"><?= nl2br(e($c['body'])) ?></p>
      <div class="avd-c-actions">
        <button type="button" data-avd-like aria-pressed="<?= $c['liked'] ? 'true' : 'false' ?>"><span aria-hidden="true">👏</span> <span class="av-num"><?= (int)$c['likes'] ?></span><span class="av-sr"> likes</span></button>
        <?php if (!$isReply): ?><button type="button" data-avd-reply>Reply</button><?php endif; ?>
        <button type="button" data-avd-report>Report</button>
      </div>
      <?php if (!$isReply && !empty($c['replies'])): ?>
        <ol class="avd-c-replies"><?php foreach ($c['replies'] as $r) avd_comment($r, true); ?></ol>
      <?php endif; ?>
    </div>
  </li>
<?php }

/** Keep reading. $items = 3 × [category,title,url,date,minutes,views] */
function avd_keep_reading(array $items): void { ?>
  <section class="avd-keep" aria-labelledby="avd-k-h">
    <div class="avd-keep-head"><h2 id="avd-k-h">Keep reading</h2><a href="/diary/">All entries →</a></div>
    <div class="avd-keep-grid">
      <?php foreach (array_slice($items, 0, 3) as $it): ?>
        <a class="avd-kcard" href="<?= e($it['url']) ?>">
          <span class="avd-eyebrow"><?= e($it['category']) ?></span>
          <strong><?= e($it['title']) ?></strong>
          <span class="av-num"><?= e($it['date']) ?> · <?= (int)$it['minutes'] ?> min read · <?= e(avd_compact($it['views'])) ?> views</span>
        </a>
      <?php endforeach; ?>
    </div>
  </section>
<?php }

/** Highlight-to-share toolbar. Render once per entry page. */
function avd_highlight_toolbar(): void { ?>
  <div class="avd-hl" data-avd-hl hidden role="toolbar" aria-label="Share selection">
    <a data-share="x" target="_blank" rel="noopener">Share on X</a>
    <a data-share="whatsapp" target="_blank" rel="noopener">WhatsApp</a>
    <button type="button" data-share="copy">Copy</button>
  </div>
<?php }
