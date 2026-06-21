<?php
/**
 * lib/partials.php — the Diary's shared view layer.
 *
 * One definition of the <head>, navigation, footer, cards and reader
 * controls, reused by diary/index.php and diary/article.php so the
 * chrome is always in sync. Markup mirrors the Afrovanguard brand.
 */
declare(strict_types=1);

final class Icons
{
    const SUN = '<svg class="sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/></svg>';
    const MOON = '<svg class="moon" viewBox="0 0 24 24" fill="currentColor"><path d="M21 12.79A9 9 0 1111.21 3 7 7 0 0021 12.79z"/></svg>';
    const BOOKMARK = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21l-7-5-7 5V5a2 2 0 012-2h10a2 2 0 012 2z"/></svg>';
    const SHARE = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><path d="M8.6 13.5l6.8 4M15.4 6.5l-6.8 4"/></svg>';
    const PRINTER = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9V2h12v7M6 18H4a2 2 0 01-2-2v-5a2 2 0 012-2h16a2 2 0 012 2v5a2 2 0 01-2 2h-2"/><path d="M6 14h12v8H6z"/></svg>';
    const ARROW_UP = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M12 19V5M5 12l7-7 7 7"/></svg>';
    const SEARCH = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4-4"/></svg>';
    const PLAY = '<svg class="icon-play" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M8 5v14l11-7z"/></svg><svg class="icon-pause" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" style="display:none"><path d="M6 5h4v14H6zm8 0h4v14h-4z"/></svg>';
    const MINI_PLAY = '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M8 5v14l11-7z"/></svg>';
    const BACK = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M11 17l-5-5 5-5"/><path d="M18 17l-5-5 5-5"/></svg>';
    const FWD = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M13 17l5-5-5-5"/><path d="M6 17l5-5-5-5"/></svg>';
    const X = '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z"/></svg>';
    const LI = '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M20.447 20.452h-3.554v-5.569c0-1.328-.027-3.037-1.852-3.037-1.853 0-2.136 1.445-2.136 2.939v5.667H9.351V9h3.414v1.561h.046c.477-.9 1.637-1.85 3.37-1.85 3.601 0 4.267 2.37 4.267 5.455v6.286zM5.337 7.433a2.062 2.062 0 01-2.063-2.065 2.064 2.064 0 112.063 2.065zm1.782 13.019H3.555V9h3.564v11.452z"/></svg>';
}

const THEME_BOOT = "<script>(function(){try{var t=localStorage.getItem('av.theme');if(!t){t=window.matchMedia&&window.matchMedia('(prefers-color-scheme: dark)').matches?'dark':'light';}document.documentElement.setAttribute('data-theme',t);var s=localStorage.getItem('av.scale');if(s)document.documentElement.style.setProperty('--reading-scale',s);}catch(e){}})();</script>";

function render_head(string $title, string $desc, string $canonical, string $slug = '', string $ogKind = 'article'): void { ?>
<!DOCTYPE html>
<html lang="en-NG" prefix="og: https://ogp.me/ns#">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover" />
  <title><?= e($title) ?></title>
  <meta name="description" content="<?= e($desc) ?>" />
  <meta name="author" content="Afrovanguard — afrovanguard.org.ng" />
  <meta name="robots" content="index, follow, max-image-preview:large" />
  <link rel="canonical" href="<?= e($canonical) ?>" />
  <meta name="theme-color" content="#111827" media="(prefers-color-scheme: light)" />
  <meta name="theme-color" content="#070B14" media="(prefers-color-scheme: dark)" />
  <meta property="og:type" content="<?= e($ogKind) ?>" />
  <meta property="og:site_name" content="Afrovanguard" />
  <meta property="og:title" content="<?= e($title) ?>" />
  <meta property="og:description" content="<?= e($desc) ?>" />
  <meta property="og:url" content="<?= e($canonical) ?>" />
  <meta name="twitter:card" content="summary_large_image" />
  <meta name="twitter:title" content="<?= e($title) ?>" />
  <meta name="twitter:description" content="<?= e($desc) ?>" />
  <?= THEME_BOOT ?>

  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Cormorant+SC:wght@400;500;600;700&family=Montserrat:wght@400;500;600;700;800&display=swap" rel="stylesheet" />
  <link href="/diary/diary.css" rel="stylesheet" />
</head>
<body<?= $slug ? ' data-slug="' . e($slug) . '"' : '' ?>>
  <a href="#main-content" class="skip-link">Skip to content</a>
  <div class="read-progress" id="read-progress"></div>
<?php }

function render_nav(string $active = 'diary'): void {
    $S = rtrim(SITE_URL, '/');
    $cur = fn($n) => $n === $active ? ' aria-current="page"' : ''; ?>
  <header class="site-header" id="site-header" role="banner">
    <div class="container">
      <nav class="nav-inner" aria-label="Main navigation">
        <a href="<?= $S ?>/" class="nav-logo" aria-label="Afrovanguard — Home">
          <span class="nav-logo-mark"><span class="afro">AFRO</span><span class="van">VANGUARD</span></span>
          <span class="nav-badge">.ORG.NG</span>
        </a>
        <ul class="nav-links" role="list">
          <li><a href="<?= $S ?>/">Home</a></li>
          <li><a href="<?= $S ?>/about/">About</a></li>
          <li><a href="<?= $S ?>/projects">Projects</a></li>
          <li><a href="<?= $S ?>/events/">Events</a></li>
          <li><a href="/diary/"<?= $cur('diary') ?>>Diary</a></li>
          <li><a href="<?= $S ?>/contact/">Contact</a></li>
        </ul>
        <div class="nav-actions">
          <button class="icon-btn theme-toggle" aria-label="Toggle dark mode" title="Toggle theme (d)"><?= Icons::SUN . Icons::MOON ?></button>
          <a href="<?= $S ?>/donate.html" class="nav-donate">Donate</a>
          <a href="https://cacentre.afrovanguard.org.ng/volunteer" class="btn btn-primary btn-sm nav-cta">Join the Movement</a>
        </div>
        <button class="nav-toggle" id="nav-toggle" aria-controls="nav-mobile" aria-expanded="false" aria-label="Open navigation menu">
          <span class="nav-toggle-line line-1"></span><span class="nav-toggle-line line-2"></span><span class="nav-toggle-line line-3"></span>
        </button>
      </nav>
    </div>
  </header>
  <div class="scrim"></div>
  <nav class="nav-mobile" id="nav-mobile" aria-label="Mobile navigation" inert>
    <a href="<?= $S ?>/">Home</a>
    <a href="<?= $S ?>/about/">About</a>
    <a href="<?= $S ?>/projects">Projects</a>
    <a href="<?= $S ?>/events/">Events</a>
    <a href="/diary/">Diary</a>
    <a href="<?= $S ?>/contact/">Contact</a>
    <a href="<?= $S ?>/donate.html">Donate</a>
    <div class="mobile-cta-wrap">
      <a href="https://cacentre.afrovanguard.org.ng/volunteer" class="btn btn-primary" style="width:100%;">Join the Movement</a>
      <a href="<?= $S ?>/donate.html" class="btn btn-outline" style="width:100%;">Fund a Leader</a>
    </div>
  </nav>
<?php }

/** A single entry card (used on the index and in related rails). */
function render_card(array $a): void {
    $search = strtolower($a['title'] . ' ' . $a['dek'] . ' ' . $a['category']);
    $url = '/diary/' . e($a['slug']) . '/'; ?>
        <article class="post-card" data-cat="<?= e($a['category_slug']) ?>" data-slug="<?= e($a['slug']) ?>" data-search="<?= e($search) ?>">
          <a class="pc-thumb <?= e($a['gradient']) ?> g-grain" href="<?= $url ?>" aria-label="<?= e($a['title']) ?>"><span class="pc-mark"><?= $a['mc_title'] ?></span></a>
          <a class="pc-title" href="<?= $url ?>"><?= e($a['title']) ?></a>
          <div class="pc-meta"><span class="pc-cat"><?= e($a['category']) ?></span><span><?= e($a['published']) ?></span><span>· <?= (int)$a['read_minutes'] ?> min</span>
            <button class="pc-saved" data-bookmark="<?= e($a['slug']) ?>" aria-label="Save for later"><?= Icons::BOOKMARK ?></button>
          </div>
        </article>
<?php }

function render_listen_bar(string $slug, string $canonical): void { ?>
        <div class="listen-bar" aria-label="Listen to this article and reading controls">
          <div class="listen-core">
            <button class="listen-play" aria-label="Listen to this article" title="Listen (l)"><?= Icons::PLAY ?></button>
            <div class="listen-readout">
              <span class="listen-time"><b class="listen-cur">0:00</b> / <span class="listen-total">0:00</span></span>
              <button class="listen-skip listen-back" aria-label="Back 10 seconds"><?= Icons::BACK ?><span>10</span></button>
              <button class="listen-skip listen-fwd" aria-label="Forward 10 seconds"><?= Icons::FWD ?><span>10</span></button>
              <button class="listen-rate" aria-label="Playback speed">1.0x</button>
            </div>
          </div>
          <div class="reader-tools">
            <div class="tool-group" role="group" aria-label="Text size">
              <button data-font="dec" aria-label="Decrease text size">A−</button>
              <button data-font="inc" aria-label="Increase text size" style="font-size:16px">A+</button>
            </div>
            <button class="tool-btn" data-bookmark="<?= e($slug) ?>" aria-label="Save for later" title="Save (b)"><?= Icons::BOOKMARK ?></button>
            <button class="tool-btn" data-share="<?= e($canonical) ?>" aria-label="Share or copy link" title="Share"><?= Icons::SHARE ?></button>
            <button class="tool-btn" onclick="window.print()" aria-label="Print this article" title="Print"><?= Icons::PRINTER ?></button>
          </div>
        </div>
<?php }

function render_subbar(string $title, string $slug, string $canonical): void { ?>
  <div class="subbar" aria-hidden="true">
    <div class="container"><div class="subbar-inner">
      <span class="subbar-title"><?= e($title) ?></span>
      <div class="subbar-actions">
        <button class="mini-play" aria-label="Listen to this article"><?= Icons::MINI_PLAY ?></button>
        <button class="chip-btn" data-bookmark="<?= e($slug) ?>" aria-label="Save for later"><?= Icons::BOOKMARK ?></button>
        <button class="chip-btn" data-share="<?= e($canonical) ?>" aria-label="Share"><?= Icons::SHARE ?></button>
      </div>
    </div></div>
  </div>
<?php }

function render_footer(): void {
    $S = rtrim(SITE_URL, '/'); ?>
  <button class="to-top" aria-label="Back to top" title="Back to top (t)"><?= Icons::ARROW_UP ?></button>
  <footer class="site-footer" role="contentinfo">
    <div class="container">
      <div class="footer-grid">
        <div class="footer-col">
          <div class="footer-logo-mark"><span class="afro">AFRO</span><span class="van">VANGUARD</span></div>
          <p class="footer-tagline">Ambassadors for Community, Tech &amp; Cultural Advancements. Raising 1 million incorruptible African leaders by 2040.</p>
          <div class="footer-socials">
            <a href="https://www.instagram.com/afrovanguard/" class="footer-social-link" aria-label="Instagram" target="_blank" rel="noopener"><svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zM12 0C8.741 0 8.333.014 7.053.072 2.695.272.273 2.69.073 7.052.014 8.333 0 8.741 0 12c0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98C8.333 23.986 8.741 24 12 24c3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98C15.668.014 15.259 0 12 0zm0 5.838a6.162 6.162 0 100 12.324 6.162 6.162 0 000-12.324zM12 16a4 4 0 110-8 4 4 0 010 8zm6.406-11.845a1.44 1.44 0 100 2.881 1.44 1.44 0 000-2.881z"/></svg></a>
            <a href="https://twitter.com/afrovanguard" class="footer-social-link" aria-label="Twitter / X" target="_blank" rel="noopener"><?= Icons::X ?></a>
            <a href="https://www.facebook.com/afrovanguard/" class="footer-social-link" aria-label="Facebook" target="_blank" rel="noopener"><svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor"><path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/></svg></a>
            <a href="https://www.linkedin.com/company/afrovanguard/" class="footer-social-link" aria-label="LinkedIn" target="_blank" rel="noopener"><?= Icons::LI ?></a>
            <a href="https://www.youtube.com/@afrovanguard" class="footer-social-link" aria-label="YouTube" target="_blank" rel="noopener"><svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor"><path d="M23.498 6.186a3.016 3.016 0 00-2.122-2.136C19.505 3.545 12 3.545 12 3.545s-7.505 0-9.377.505A3.017 3.017 0 00.502 6.186C0 8.07 0 12 0 12s0 3.93.502 5.814a3.016 3.016 0 002.122 2.136c1.871.505 9.376.505 9.376.505s7.505 0 9.377-.505a3.015 3.015 0 002.122-2.136C24 15.93 24 12 24 12s0-3.93-.502-5.814zM9.545 15.568V8.432L15.818 12l-6.273 3.568z"/></svg></a>
          </div>
        </div>
        <div class="footer-col">
          <h4>Programmes</h4>
          <ul class="footer-links">
            <li><a href="https://cacentre.afrovanguard.org.ng/street-to-stardom/">Street-To-Stardom</a></li>
            <li><a href="https://next.afrovanguard.org.ng/">Next Generation Genius</a></li>
            <li><a href="https://cacentre.afrovanguard.org.ng/techhome/">Techome</a></li>
            <li><a href="https://cacentre.afrovanguard.org.ng/mediapro/">MediaPro</a></li>
            <li><a href="https://cacentre.afrovanguard.org.ng/africa-gates/">Africa GATES</a></li>
          </ul>
        </div>
        <div class="footer-col">
          <h4>Organization</h4>
          <ul class="footer-links">
            <li><a href="<?= $S ?>/about/">About Us</a></li>
            <li><a href="<?= $S ?>/events/">Events</a></li>
            <li><a href="/diary/">The Diary</a></li>
            <li><a href="https://cacentre.afrovanguard.org.ng/volunteer">Join Us</a></li>
            <li><a href="<?= $S ?>/donate.html">Donate</a></li>
            <li><a href="<?= $S ?>/contact/">Contact</a></li>
          </ul>
        </div>
        <div class="footer-col footer-contact">
          <h4>Get in Touch</h4>
          <p>Afrovanguard HQ<br/>Alimosho LGA, Lagos, Nigeria</p>
          <p><a href="mailto:cacentre@afrovanguard.org.ng" style="color:rgba(255,255,255,0.6)">cacentre@afrovanguard.org.ng</a></p>
          <div class="footer-newsletter-mini">
            <h4 style="margin-bottom:8px;">Get the Diary</h4>
            <form class="diary-subscribe" novalidate style="display:flex;flex-direction:column;gap:8px">
              <input type="email" name="email" placeholder="Your email address" aria-label="Newsletter email" autocomplete="email" required />
              <button type="submit" class="btn btn-primary" style="width:100%;min-height:44px;">Subscribe →</button>
            </form>
          </div>
        </div>
      </div>
      <div class="footer-bottom">
        <p class="footer-legal">&copy; 2026 Afrovanguard. All rights reserved. <a href="<?= $S ?>/privacy-policy/">Privacy Policy</a> · <a href="<?= $S ?>/terms/">Terms of Use</a></p>
        <p class="footer-legal">Built with purpose. Powered by passion. For Africa.</p>
      </div>
    </div>
  </footer>
  <script src="/diary/diary.js" defer></script>
</body>
</html>
<?php }
