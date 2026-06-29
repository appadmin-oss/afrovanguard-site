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
    const CHEVRON = '<svg class="chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg>';
    const CLOSE = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg>';
    const ARROW = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg>';
}

const THEME_BOOT = "<script>(function(){var r=document.documentElement;r.classList.add('reveal-on');try{var t=localStorage.getItem('av.theme');if(!t){t=window.matchMedia&&window.matchMedia('(prefers-color-scheme: dark)').matches?'dark':'light';}r.setAttribute('data-theme',t);var s=localStorage.getItem('av.scale');if(s)r.style.setProperty('--reading-scale',s);}catch(e){}})();</script>";

/**
 * Render the document head + SEO.
 * $o keys: title, desc, canonical, slug, og_kind, image, image_alt,
 *          published, modified, section, tags(array), keywords,
 *          jsonld(array of schema nodes).
 */
function render_head(array $o): void {
    $title = $o['title']; $desc = $o['desc']; $canonical = $o['canonical'];
    $slug = $o['slug'] ?? ''; $ogKind = $o['og_kind'] ?? 'article';
    $image = $o['image'] ?? (rtrim(SITE_URL, '/') . '/Images/og-image.png');
    $imageAlt = $o['image_alt'] ?? $title;
    $jsonld = $o['jsonld'] ?? [];
    if (function_exists('send_security_headers')) send_security_headers('public'); ?>
<!DOCTYPE html>
<html lang="en-NG" prefix="og: https://ogp.me/ns#">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover" />
  <title><?= e($title) ?></title>
  <meta name="description" content="<?= e($desc) ?>" />
  <meta name="author" content="Afrovanguard — afrovanguard.org.ng" />
  <meta name="robots" content="<?= e($o['robots'] ?? 'index, follow, max-snippet:-1, max-image-preview:large, max-video-preview:-1') ?>" />
<?php if (!empty($o['keywords'])): ?>  <meta name="keywords" content="<?= e($o['keywords']) ?>" />
<?php endif; ?>  <link rel="canonical" href="<?= e($canonical) ?>" />
  <meta name="theme-color" content="#111827" media="(prefers-color-scheme: light)" />
  <meta name="theme-color" content="#070B14" media="(prefers-color-scheme: dark)" />

  <meta property="og:type" content="<?= e($ogKind) ?>" />
  <meta property="og:site_name" content="Afrovanguard" />
  <meta property="og:locale" content="en_NG" />
  <meta property="og:title" content="<?= e($title) ?>" />
  <meta property="og:description" content="<?= e($desc) ?>" />
  <meta property="og:url" content="<?= e($canonical) ?>" />
  <meta property="og:image" content="<?= e($image) ?>" />
  <meta property="og:image:width" content="1200" />
  <meta property="og:image:height" content="630" />
  <meta property="og:image:alt" content="<?= e($imageAlt) ?>" />
<?php if (!empty($o['published'])): ?>  <meta property="article:published_time" content="<?= e($o['published']) ?>" />
  <meta property="article:modified_time" content="<?= e($o['modified'] ?? $o['published']) ?>" />
  <meta property="article:publisher" content="https://www.facebook.com/afrovanguard/" />
<?php endif; if (!empty($o['section'])): ?>  <meta property="article:section" content="<?= e($o['section']) ?>" />
<?php endif; foreach (($o['tags'] ?? []) as $tag): ?>  <meta property="article:tag" content="<?= e($tag) ?>" />
<?php endforeach; ?>
  <meta name="twitter:card" content="summary_large_image" />
  <meta name="twitter:site" content="@afrovanguard" />
  <meta name="twitter:title" content="<?= e($title) ?>" />
  <meta name="twitter:description" content="<?= e($desc) ?>" />
  <meta name="twitter:image" content="<?= e($image) ?>" />
  <meta name="twitter:image:alt" content="<?= e($imageAlt) ?>" />

  <link rel="alternate" type="application/rss+xml" title="The Afrovanguard Diary" href="<?= e(diary_url('feed.xml')) ?>" />
  <link rel="sitemap" type="application/xml" href="<?= e(diary_url('sitemap.xml')) ?>" />
<?php if ($jsonld): ?>  <script type="application/ld+json"><?= json_encode(count($jsonld) === 1 ? $jsonld[0] : ['@context' => 'https://schema.org', '@graph' => $jsonld], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?></script>
<?php endif; ?>  <?= THEME_BOOT ?>

  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Cormorant:wght@400;500;600;700&family=Montserrat:wght@400;500;600;700;800&display=swap" rel="stylesheet" />
  <link href="/diary/diary.css" rel="stylesheet" />
  <link href="/assets/site/nav.css" rel="stylesheet" />
<?php foreach (($o['css'] ?? []) as $href): ?>  <link href="<?= e($href) ?>" rel="stylesheet" />
<?php endforeach; ?>  <link rel="icon" href="/favicon.ico" sizes="any" />
  <link rel="icon" type="image/png" sizes="192x192" href="/assets/site/icon-192.png" />
  <link rel="apple-touch-icon" href="/assets/site/icon-192.png" />
<?php if (!empty($o['manifest'])): ?>  <link rel="manifest" href="<?= e($o['manifest']) ?>" />
  <meta name="mobile-web-app-capable" content="yes" />
  <meta name="apple-mobile-web-app-capable" content="yes" />
  <meta name="apple-mobile-web-app-title" content="Afrovanguard" />
<?php endif; ?></head>
<body<?= $slug ? ' data-slug="' . e($slug) . '"' : '' ?><?= !empty($o['body_class']) ? ' class="' . e($o['body_class']) . '"' : '' ?>>
  <a href="#main-content" class="skip-link">Skip to content</a>
  <noscript><style>[data-reveal],.reveal-stagger>*{opacity:1!important;transform:none!important}</style></noscript>
  <div class="read-progress" id="read-progress"></div>
<?php }

const AV_VOLUNTEER_URL = 'https://cacentre.afrovanguard.org.ng/volunteer';
// Events live on the AFG sub-site; the whole site links out to it.
const AV_EVENTS_URL = 'https://afg.afrovanguard.org.ng/events';

/**
 * Canonical primary navigation model — the single definition for the whole
 * site (PHP pages render it live; static pages get it injected by
 * tools/build-chrome.php). Each item is a top-level link; items with a
 * "mega" key open a mega-menu panel (columns of links + a featured card
 * whose illustration lives at /assets/illustrations/nav-<key>.webp).
 * Custom-owned sections use root-relative paths (served ahead of WordPress).
 */
function av_nav_model(): array {
    // Same-site links are root-relative so they work on ANY host (production,
    // preview, local, or while DNS still points at the old site); only genuine
    // off-site subdomains (cacentre/next/…) stay absolute.
    $V = AV_VOLUNTEER_URL;
    return [
        'about'   => ['label' => 'About', 'href' => '/about.html', 'mega' => [
            'cols' => [
                ['title' => 'The organisation', 'links' => [
                    ['About us', '/about.html'], ['Our ethos', '/ethos/'],
                    ['Leadership & model', '/ethos/#leadership'], ['Our story', '/about.html#our-story'],
                ]],
                ['title' => 'Get involved', 'links' => [
                    ['Volunteer', $V], ['Donate', '/donate.html'],
                    ['Events', AV_EVENTS_URL], ['Contact us', '/contact.html'],
                ]],
            ],
            'feature' => ['kicker' => 'Our mission', 'title' => 'One million incorruptible leaders by 2040', 'text' => 'The vision, values and creed behind everything we build.', 'href' => '/ethos/', 'cta' => 'Read the ethos'],
        ]],
        'academy' => ['label' => 'Academy', 'href' => '/academy/', 'mega' => [
            'cols' => [
                ['title' => 'Learn with us', 'links' => [
                    ['All programmes', '/academy/'], ['Academy membership', '/academy/#membership'],
                    ['Teach with us', '/academy/teach/'], ['Verify a certificate', '/academy/'],
                ]],
                ['title' => 'Featured programmes', 'links' => [
                    ['Techome', '/academy/techome/'], ['MediaPro', '/academy/mediapro/'],
                    ['Africa GATES', '/academy/africa-gates/'], ['NGV Academy', '/academy/ngv-academy/'],
                ]],
            ],
            'feature' => ['kicker' => 'The Academy', 'title' => 'Learn. Build. Lead Africa.', 'text' => 'Free, hands-on programmes in technology, creativity and leadership.', 'href' => '/academy/', 'cta' => 'Explore the Academy'],
        ]],
        'projects' => ['label' => 'Projects', 'href' => '/projects/', 'mega' => [
            'cols' => [
                ['title' => 'Flagship programmes', 'links' => [
                    ['Street-To-Stardom', 'https://cacentre.afrovanguard.org.ng/street-to-stardom/'],
                    ['Next Generation Genius', 'https://next.afrovanguard.org.ng/'],
                    ['Techome', 'https://cacentre.afrovanguard.org.ng/techhome/'],
                    ['MediaPro', 'https://cacentre.afrovanguard.org.ng/mediapro/'],
                ]],
                ['title' => 'More', 'links' => [
                    ['Africa GATES', 'https://cacentre.afrovanguard.org.ng/africa-gates/'],
                    ['All projects', '/projects/'], ['Volunteer', $V], ['Events', AV_EVENTS_URL],
                ]],
            ],
            'feature' => ['kicker' => 'Our work', 'title' => 'Programmes changing lives', 'text' => 'Technology, creative and leadership initiatives across Lagos and beyond.', 'href' => '/projects/', 'cta' => 'See all projects'],
        ]],
        'diary'   => ['label' => 'Diary', 'href' => '/diary/', 'mega' => [
            'cols' => [
                ['title' => 'Browse the Diary', 'links' => [
                    ['All entries', '/diary/'], ['Technology', '/diary/?q=Technology'],
                    ['Creative', '/diary/?q=Creative'], ['Leadership', '/diary/?q=Leadership'],
                ]],
                ['title' => 'Follow along', 'links' => [
                    ['Subscribe', '/diary/#subscribe'], ['RSS feed', diary_url('feed.xml')],
                    ['Our ethos', '/ethos/'], ['Academy', '/academy/'],
                ]],
            ],
            'feature' => ['kicker' => 'The Afrovanguard Diary', 'title' => 'We publish the working', 'text' => 'Field notes and methodology as we build the movement.', 'href' => '/diary/', 'cta' => 'Read the Diary'],
        ]],
        'contact' => ['label' => 'Contact', 'href' => '/contact.html'],
    ];
}

/** Back-compat simple list (label,href) — used by older callers. */
function av_nav_items(): array {
    $out = [];
    foreach (av_nav_model() as $k => $v) { $out[$k] = [$v['label'], $v['href']]; }
    return $out;
}

/**
 * Contextual section sub-navigation — the SECOND nav tier (the Asana-style
 * "section bar"). Rendered ONLY for sections that genuinely have peer
 * sub-pages worth moving between — used deliberately, never on every page.
 * Keyed by the active nav section. Each entry: a section brand, its links,
 * an optional in-section search, and an optional right-aligned action.
 */
function av_subnav_model(): array {
    return [
        'academy' => [
            'key'    => 'academy',
            'brand'  => ['label' => 'Academy', 'href' => '/academy/'],
            'links'  => [
                ['Programmes',  '/academy/#catalogue'],
                ['Membership',  '/academy/#membership'],
                ['Teach',       '/academy/teach/'],
            ],
            'search' => ['placeholder' => 'Search the Academy…', 'target' => '/academy/'],
            'cta'    => ['label' => 'Log in', 'href' => '/login?next=/academy/'],
        ],
    ];
}

/**
 * Brand mark for a nav tier. Serves the committed logo image when one is
 * present (drop an SVG/PNG/WebP at /assets/site/logo-<key>.{svg,png,webp}),
 * otherwise an elegant Cormorant wordmark so the chrome is never blank.
 * is_file() resolves live on PHP pages and at build time for static pages,
 * so adding a logo file + re-running build-chrome.php swaps the wordmark out.
 */
function av_brand_mark(string $key = 'afrovanguard'): string {
    foreach (['.svg', '.png', '.webp'] as $ext) {
        $rel = '/assets/site/logo-' . $key . $ext;
        if (is_file(AV_ROOT . $rel)) {
            $alt = $key === 'academy' ? 'Afrovanguard Academy' : 'Afrovanguard';
            return '<img class="brand-logo brand-logo--' . e($key) . '" src="' . e($rel) . '" alt="' . e($alt) . '" />';
        }
    }
    if ($key === 'academy') {
        return '<span class="brand-wordmark brand-wordmark--academy">Academy</span>';
    }
    return '<span class="brand-wordmark"><span class="wm-1">Afro</span><span class="wm-2">vanguard</span></span>';
}

/**
 * Per-visit sign-in illustration, mirroring Afrostrength's auth backdrop.
 * Picks ONE webp from assets/illustrations/auth/ and keeps it for the browser
 * session via a session-scoped cookie (this app uses cookies, not PHP
 * sessions). Day-of-year rotation seeds the first pick so it varies over time
 * but stays stable within a visit. Returns '' when no art is present (the
 * layout then shows the brand gradient alone — never broken).
 */
function av_auth_illustration(): string {
    // 1) admin-managed, scheduled illustrations take precedence (holiday-aware).
    $urls = [];
    try {
        foreach (av_auth_art_active_today(Database::pdo()) as $r) {
            $u = trim((string) ($r['image_url'] ?? ''));
            if ($u !== '') $urls[] = $u;
        }
    } catch (Throwable $e) { /* DB unavailable → fall through to the filesystem */ }
    // 2) otherwise the committed filesystem drop-zone.
    if (!$urls) {
        $dir = AV_ROOT . '/assets/illustrations/auth';
        foreach (is_dir($dir) ? (glob($dir . '/*.webp') ?: []) : [] as $f) {
            $urls[] = '/assets/illustrations/auth/' . basename($f);
        }
    }
    if (!$urls) return '';                  // 3) nothing → the brand gradient alone
    sort($urls);
    // Pick one, stable for the visit (cookie), seeded by day-of-year.
    $chosen = (string) ($_COOKIE['av_illo'] ?? '');
    if (!in_array($chosen, $urls, true)) {
        $chosen = $urls[(int) date('z') % count($urls)];
        if (!headers_sent()) setcookie('av_illo', $chosen, ['path' => '/', 'httponly' => false, 'samesite' => 'Lax']);
        $_COOKIE['av_illo'] = $chosen;
    }
    return $chosen;
}

/** Build a sign-in URL that returns the user to $next (defaults to home). */
function av_login_url(string $next = ''): string {
    return '/login' . ($next !== '' ? '?next=' . rawurlencode($next) : '');
}

function render_nav(string $active = 'diary', array $opts = []): void {
    $S = rtrim(SITE_URL, '/');
    $showToggle = $opts['theme_toggle'] ?? true;
    $model = av_nav_model();
    $sub = av_subnav_model()[$active] ?? null;
    $cur = fn($n) => $n === $active ? ' aria-current="page"' : '';
    $illo = fn($k) => '/assets/illustrations/nav-' . $k . '.webp';
?>
  <header class="site-header<?= $sub ? ' has-subnav' : '' ?>" id="site-header" role="banner" data-section="<?= e($active) ?>">
    <!-- Tier 0 · thin utility strip (secondary actions) -->
    <div class="nav-utility-bar">
      <div class="container">
        <div class="nav-utility">
          <div class="nav-utility-actions">
<?php if ($showToggle): ?>            <button class="icon-btn theme-toggle" type="button" aria-label="Toggle dark mode" data-tip="Toggle theme"><?= Icons::SUN . Icons::MOON ?></button>
<?php endif; ?>            <a class="nav-signin-link" data-login-link href="/login">Sign in</a>
            <div class="nav-account" id="navAuth">
              <button class="acct-btn" id="acctBtn" type="button" aria-haspopup="true" aria-expanded="false" aria-controls="acctMenu" data-tip="Account" aria-label="Your account">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="8" r="3.6"/><path d="M4.5 20c0-3.8 3.4-6 7.5-6s7.5 2.2 7.5 6"/></svg>
              </button>
              <div class="acct-menu" id="acctMenu" role="menu" aria-label="Account" hidden>
                <div class="am-head">
                  <p class="am-title">Your Afrovanguard account</p>
                  <p class="am-sub">Sign in to track your learning, certificates and saved entries.</p>
                </div>
                <div class="am-actions">
                  <a class="am-btn am-btn-primary" data-login-link href="/login">Sign in</a>
                  <a class="am-btn am-btn-ghost" id="acctCreate" href="/login">Create account</a>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
    <!-- Tier 1 · global brand bar -->
    <div class="container">
        <nav class="nav-inner" aria-label="Main navigation">
          <a href="/" class="nav-logo" aria-label="Afrovanguard — Home"><?= av_brand_mark('afrovanguard') ?></a>
          <span class="nav-divider" aria-hidden="true"></span>
          <ul class="nav-links" role="list">
<?php foreach ($model as $k => $it): if (empty($it['mega'])): ?>
            <li><a href="<?= e($it['href']) ?>"<?= $cur($k) ?>><?= e($it['label']) ?></a></li>
<?php else: ?>
            <li class="has-mega" data-mega="<?= e($k) ?>">
              <a href="<?= e($it['href']) ?>"<?= $cur($k) ?> aria-haspopup="true" aria-expanded="false"><?= e($it['label']) ?> <?= Icons::CHEVRON ?></a>
              <div class="mega" role="region" aria-label="<?= e($it['label']) ?> menu">
                <div class="mega-inner">
                  <div class="mega-cols">
<?php foreach ($it['mega']['cols'] as $col): ?>                    <div class="mega-col">
                      <p class="mega-h"><?= e($col['title']) ?></p>
                      <ul role="list">
<?php foreach ($col['links'] as [$ll, $lh]): ?>                        <li><a href="<?= e($lh) ?>"><?= e($ll) ?></a></li>
<?php endforeach; ?>                      </ul>
                    </div>
<?php endforeach; ?>                  </div>
<?php $f = $it['mega']['feature']; ?>                  <a class="mega-feature" href="<?= e($f['href']) ?>" style="background-image:url('<?= e($illo($k)) ?>')">
                    <span class="mf-kicker"><?= e($f['kicker']) ?></span>
                    <span class="mf-title"><?= e($f['title']) ?></span>
                    <span class="mf-text"><?= e($f['text']) ?></span>
                    <span class="mf-cta"><?= e($f['cta']) ?> <?= Icons::ARROW ?></span>
                  </a>
                </div>
              </div>
            </li>
<?php endif; endforeach; ?>          </ul>
          <div class="nav-actions">
            <a class="nav-search-btn" href="/diary/" aria-label="Search Afrovanguard"><?= Icons::SEARCH ?><span>Search</span></a>
            <a href="/donate.html" class="nav-donate-btn">Donate</a>
            <a href="<?= e(AV_VOLUNTEER_URL) ?>" class="nav-cta">Join the Movement</a>
          </div>
          <button class="nav-burger" id="avBurger" aria-controls="avDrawer" aria-expanded="false" aria-label="Open menu">
            <span class="nav-toggle-line line-1"></span><span class="nav-toggle-line line-2"></span><span class="nav-toggle-line line-3"></span>
          </button>
        </nav>
    </div>
<?php if ($sub): ?>
    <!-- Tier 2 · contextual section bar (sticks on scroll) -->
    <div class="nav-sub" aria-label="<?= e($sub['brand']['label']) ?> section navigation">
      <div class="container">
        <div class="nav-sub-inner">
          <a class="nav-sub-brand" href="<?= e($sub['brand']['href']) ?>"><?= av_brand_mark($sub['key'] ?? 'afrovanguard') ?></a>
          <ul class="nav-sub-links" role="list">
<?php foreach ($sub['links'] as [$ll, $lh]): ?>            <li><a href="<?= e($lh) ?>"><?= e($ll) ?></a></li>
<?php endforeach; ?>          </ul>
          <div class="nav-sub-actions">
<?php if (!empty($sub['search'])): ?>            <form class="nav-sub-search" role="search" action="<?= e($sub['search']['target']) ?>" method="get">
              <?= Icons::SEARCH ?><input type="search" name="q" placeholder="<?= e($sub['search']['placeholder']) ?>" aria-label="Search this section" />
            </form>
<?php endif; if (!empty($sub['cta'])): ?>            <a class="nav-sub-cta" id="navSubLogin" data-login-link href="<?= e($sub['cta']['href']) ?>"><?= e($sub['cta']['label']) ?></a>
<?php endif; ?>          </div>
        </div>
      </div>
    </div>
<?php endif; ?>
  </header>
  <div class="scrim" data-close-drawer></div>
  <nav class="av-drawer" id="avDrawer" aria-label="Mobile navigation" inert>
    <div class="avd-head">
      <a href="/" class="nav-logo"><?= av_brand_mark('afrovanguard') ?></a>
      <button class="avd-close" data-close-drawer aria-label="Close menu"><?= Icons::CLOSE ?></button>
    </div>
    <div class="avd-scroll">
<?php if ($sub): ?>      <div class="avd-section">
        <p class="avd-section-h"><?= e($sub['brand']['label']) ?></p>
<?php foreach ($sub['links'] as [$ll, $lh]): ?>        <a class="avd-sub" href="<?= e($lh) ?>"><?= e($ll) ?></a>
<?php endforeach; ?>      </div>
<?php endif; foreach ($model as $k => $it): if (empty($it['mega'])): ?>
      <a class="avd-link" href="<?= e($it['href']) ?>"<?= $cur($k) ?>><?= e($it['label']) ?></a>
<?php else: ?>
      <div class="avd-acc">
        <button class="avd-acc-btn" aria-expanded="false"><span><?= e($it['label']) ?></span><?= Icons::CHEVRON ?></button>
        <div class="avd-acc-panel">
          <a class="avd-sub avd-sub-lead" href="<?= e($it['href']) ?>"><?= e($it['label']) ?> home</a>
<?php foreach ($it['mega']['cols'] as $col): foreach ($col['links'] as [$ll, $lh]): ?>          <a class="avd-sub" href="<?= e($lh) ?>"><?= e($ll) ?></a>
<?php endforeach; endforeach; ?>        </div>
      </div>
<?php endif; endforeach; ?>    </div>
    <div class="avd-foot">
      <a href="/login" class="btn btn-outline" data-login-link style="width:100%;">Sign in</a>
      <a href="<?= e(AV_VOLUNTEER_URL) ?>" class="btn btn-primary" style="width:100%;">Join the Movement</a>
      <a href="/donate.html" class="btn btn-outline" style="width:100%;">Donate</a>
      <div class="avd-social">
        <a href="https://www.instagram.com/afrovanguard/" aria-label="Instagram" target="_blank" rel="noopener"><svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2.16c3.2 0 3.58.01 4.85.07 3.25.15 4.77 1.69 4.92 4.92.06 1.27.07 1.65.07 4.85s-.01 3.58-.07 4.85c-.15 3.23-1.66 4.77-4.92 4.92-1.27.06-1.64.07-4.85.07s-3.58-.01-4.85-.07c-3.26-.15-4.77-1.7-4.92-4.92-.06-1.27-.07-1.64-.07-4.85s.01-3.58.07-4.85C2.4 3.93 3.92 2.38 7.15 2.23 8.42 2.17 8.8 2.16 12 2.16zM12 0C8.74 0 8.33.01 7.05.07 2.7.27.28 2.69.08 7.05.01 8.33 0 8.74 0 12s.01 3.67.07 4.95c.2 4.36 2.62 6.78 6.98 6.98C8.33 23.99 8.74 24 12 24s3.67-.01 4.95-.07c4.35-.2 6.78-2.62 6.98-6.98.06-1.28.07-1.69.07-4.95s-.01-3.67-.07-4.95c-.2-4.35-2.62-6.78-6.98-6.98C15.67.01 15.26 0 12 0zm0 5.84a6.16 6.16 0 100 12.32 6.16 6.16 0 000-12.32zM12 16a4 4 0 110-8 4 4 0 010 8zm6.41-11.85a1.44 1.44 0 100 2.88 1.44 1.44 0 000-2.88z"/></svg></a>
        <a href="https://twitter.com/afrovanguard" aria-label="X" target="_blank" rel="noopener"><?= Icons::X ?></a>
        <a href="https://www.linkedin.com/company/afrovanguard/" aria-label="LinkedIn" target="_blank" rel="noopener"><?= Icons::LI ?></a>
      </div>
    </div>
  </nav>
<?php }

/** A single entry card (used on the index and in related rails). */
function render_card(array $a): void {
    $search = strtolower($a['title'] . ' ' . $a['dek'] . ' ' . $a['category']);
    $url = '/diary/' . e($a['slug']) . '/';
    $cover = $a['cover_url'] ?? ''; ?>
        <article class="post-card" data-reveal data-cat="<?= e($a['category_slug']) ?>" data-slug="<?= e($a['slug']) ?>" data-search="<?= e($search) ?>">
<?php if ($cover): ?>
          <a class="pc-thumb has-cover" href="<?= $url ?>" aria-label="<?= e($a['title']) ?>" style="background-image:url('<?= e($cover) ?>')"><span class="pc-cat-tag"><?= e($a['category']) ?></span></a>
<?php else: ?>
          <a class="pc-thumb <?= e($a['gradient']) ?> g-grain" href="<?= $url ?>" aria-label="<?= e($a['title']) ?>"><span class="pc-mark"><?= $a['mc_title'] ?></span></a>
<?php endif; ?>
          <a class="pc-title" href="<?= $url ?>"><?= e($a['title']) ?></a>
          <div class="pc-meta"><span class="pc-cat"><?= e($a['category']) ?></span><span><?= e($a['published']) ?></span><span>· <?= (int)$a['read_minutes'] ?> min</span>
            <button class="pc-saved" data-bookmark="<?= e($a['slug']) ?>" aria-label="Save for later"><?= Icons::BOOKMARK ?></button>
          </div>
        </article>
<?php }

/** A course card for the Academy catalogue. */
function render_course_card(array $c): void {
    $url = '/academy/' . e($c['slug']) . '/';
    $cover = $c['cover_url'] ?? '';
    $search = strtolower($c['title'] . ' ' . $c['summary'] . ' ' . $c['category'] . ' ' . $c['level']); ?>
        <article class="ac-card" data-reveal data-cat="<?= e(slugify($c['category'])) ?>" data-search="<?= e($search) ?>">
          <a class="ac-thumb <?= $cover ? 'has-cover' : e($c['gradient']) . ' g-grain' ?>" href="<?= $url ?>" aria-label="<?= e($c['title']) ?>"<?= $cover ? ' style="background-image:url(\'' . e($cover) . '\')"' : '' ?>>
            <span class="ac-cat"><?= e($c['category']) ?></span>
<?php if (!$cover): ?>            <span class="ac-mark"><?= e($c['title']) ?></span>
<?php endif; ?>          </a>
          <div class="ac-body">
            <a class="ac-title" href="<?= $url ?>"><?= e($c['title']) ?></a>
            <p class="ac-summary"><?= e($c['summary']) ?></p>
            <div class="ac-meta">
              <span>◆ <?= e($c['level']) ?></span><span>● <?= e($c['format']) ?></span><span>◷ <?= e($c['duration']) ?></span>
            </div>
            <div class="ac-foot"><span class="ac-price"><?= e($c['price']) ?></span><a class="ac-link" href="<?= $url ?>">View programme →</a></div>
          </div>
        </article>
<?php }

function render_listen_bar(string $slug, string $canonical): void { ?>
        <div class="listen-bar" aria-label="Listen to this article and reading controls" data-slug="<?= e($slug) ?>" data-tts="<?= (class_exists('Tts') && Tts::available()) ? '1' : '0' ?>">
          <div class="listen-core">
            <button class="listen-play" aria-label="Listen to this article" title="Listen (l)"><?= Icons::PLAY ?></button>
            <div class="listen-readout">
              <span class="listen-time"><b class="listen-cur">0:00</b> / <span class="listen-total">0:00</span></span>
              <button class="listen-skip listen-back" aria-label="Back 10 seconds"><?= Icons::BACK ?><span>10</span></button>
              <button class="listen-skip listen-fwd" aria-label="Forward 10 seconds"><?= Icons::FWD ?><span>10</span></button>
              <button class="listen-rate" aria-label="Playback speed">1.0x</button>
              <select class="listen-voice" aria-label="Reader voice" title="Choose a voice" hidden></select>
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

/** The shared <footer> markup. No to-top/scripts/closing tags, so it can be
 *  reused verbatim by both PHP pages and the static-page chrome generator. */
function av_footer_inner(): void {
    $S = rtrim(SITE_URL, '/'); ?>
  <footer class="site-footer" role="contentinfo">
    <div class="container">
      <div class="footer-grid">
        <div class="footer-col">
          <div class="footer-logo-mark"><span class="afro">Afro</span><span class="van">vanguard</span></div>
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
            <li><a href="<?= $S ?>/about.html">About Us</a></li>
            <li><a href="/ethos/">Our Ethos</a></li>
            <li><a href="/academy/">Academy</a></li>
            <li><a href="<?= $S ?>/projects/">Projects</a></li>
            <li><a href="/diary/">The Diary</a></li>
            <li><a href="<?= e(AV_EVENTS_URL) ?>">Events</a></li>
            <li><a href="<?= e(AV_VOLUNTEER_URL) ?>">Volunteer</a></li>
            <li><a href="<?= $S ?>/donate.html">Donate</a></li>
            <li><a href="/login">Sign In</a></li>
            <li><a href="<?= $S ?>/contact.html">Contact</a></li>
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
              <input type="text" name="hp" class="hp" tabindex="-1" autocomplete="off" aria-hidden="true" />
              <button type="submit" class="btn btn-primary" style="width:100%;min-height:44px;">Subscribe →</button>
              <p class="sub-msg" role="status" aria-live="polite"></p>
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
<?php }

function render_footer(): void { ?>
  <button class="to-top" aria-label="Back to top" title="Back to top (t)"><?= Icons::ARROW_UP ?></button>
<?php av_footer_inner(); ?>
  <script src="/assets/site/nav.js" defer></script>
  <script src="/assets/site/celebrations.js" defer></script>
  <script src="/diary/diary.js" defer></script>
  <script src="/assets/site/chioma.js" defer></script>
</body>
</html>
<?php }
