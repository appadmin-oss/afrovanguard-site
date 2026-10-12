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

    /** The mentor portal's nav and tab-bar glyphs. One stroke weight, one grid. */
    private const MENTOR = [
        'today'  => '<path d="M8 2v4M16 2v4M3 10h18"/><rect x="3" y="4" width="18" height="18" rx="2"/>',
        'people' => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/>',
        'star'   => '<path d="M12 3l2.6 5.6 6 .8-4.4 4.2 1.1 6.1L12 16.9 6.7 19.7l1.1-6.1L3.4 9.4l6-.8z"/>',
        'check'  => '<path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/>',
        'book'   => '<path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/>',
        'inbox'  => '<path d="M22 12h-6l-2 3h-4l-2-3H2"/><path d="M5.5 5h13l3.5 7v6a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2v-6z"/>',
        'cap'    => '<path d="M22 10L12 5 2 10l10 5 10-5z"/><path d="M6 12v5c0 1.7 2.7 3 6 3s6-1.3 6-3v-5"/>',
        'person' => '<circle cx="12" cy="8" r="4"/><path d="M4 21v-1a6 6 0 0 1 6-6h4a6 6 0 0 1 6 6v1"/>',
        'help'   => '<circle cx="12" cy="12" r="9"/><path d="M9.5 9.5a2.5 2.5 0 1 1 3.3 2.4c-.7.3-1.3.9-1.3 1.8v.3"/><path d="M12 17.5h.01"/>',
        'more'   => '<circle cx="5" cy="12" r="1.6"/><circle cx="12" cy="12" r="1.6"/><circle cx="19" cy="12" r="1.6"/>',
    ];

    public static function mentorPortal(string $key): string
    {
        $d = self::MENTOR[$key] ?? self::MENTOR['more'];
        return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $d . '</svg>';
    }
}

const THEME_BOOT = "<script>(function(){var r=document.documentElement;r.classList.add('reveal-on');try{var t=localStorage.getItem('av.theme');if(!t){t=window.matchMedia&&window.matchMedia('(prefers-color-scheme: dark)').matches?'dark':'light';}r.setAttribute('data-theme',t);var s=localStorage.getItem('av.scale');if(s)r.style.setProperty('--reading-scale',s);}catch(e){}})();</script>";

/**
 * Render the document head + SEO.
 * $o keys: title, desc, canonical, slug, og_kind, image, image_alt,
 *          published, modified, section, tags(array), keywords,
 *          jsonld(array of schema nodes).
 */
/**
 * Brand accent overrides set in the Studio's Design panel. Returns the body of
 * an inline <style> that re-points the gold/accent CSS variables (both the
 * content-surface --gold tokens and the nav/chrome --m-gold tokens) to the
 * admin's chosen colours, or '' when the brand is unchanged. Purely additive:
 * if the value is missing or malformed the built-in palette stands. Cached per
 * request and fail-safe (a DB hiccup never breaks the page head).
 */
function av_brand_css(): string {
    static $cache = null;
    if ($cache !== null) return $cache;
    $cache = '';
    try {
        $raw = class_exists('Database') ? Database::metaGet('brand_theme') : null;
        if (!$raw) return $cache;
        $b = json_decode($raw, true);
        if (!is_array($b)) return $cache;
        $hex = static fn($v) => (is_string($v) && preg_match('/^#[0-9a-fA-F]{6}$/', $v)) ? $v : null;
        $a = $hex($b['accent'] ?? null);
        $d = $hex($b['accent_deep'] ?? null);
        if (!$a && !$d) return $cache;
        $a = $a ?: $d; $d = $d ?: $a;
        // Repoint the brand accent across every namespace, including the shared
        // --afg-* token layer, so a custom Studio brand colour flows everywhere.
        $cache = ':root{--gold:' . $a . ';--gold-deep:' . $d . ';--m-gold:' . $a . ';--m-gold-deep:' . $d
            . ';--afg-gold:' . $a . ';--afg-accent:' . $a . ';--afg-accent-ink:' . $d . ';}';
    } catch (Throwable $e) { $cache = ''; }
    return $cache;
}

function render_head(array $o): void {
    // `??` on all three. A by-link page (diary/shared.php, diary/notebook.php)
    // has no canonical BY DEFINITION — it is noindex and unlisted — and the bare
    // access emitted an "Undefined array key" warning into the error log on every
    // one of those requests. A page with no canonical is a legitimate page, not a
    // caller mistake.
    $title = $o['title'] ?? ''; $desc = $o['desc'] ?? ''; $canonical = $o['canonical'] ?? '';
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
<?php if (!empty($o['csrf'])): /* Pages whose JS writes back. A token in the markup
     is the only place the browser can read one from: the session cookie is
     httpOnly by design, so a page that posts needs this meta or it cannot. */ ?>
  <meta name="csrf-token" content="<?= e(av_csrf_token()) ?>" />
<?php endif; ?>  <meta name="robots" content="<?= e($o['robots'] ?? 'index, follow, max-snippet:-1, max-image-preview:large, max-video-preview:-1') ?>" />
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

<?php /* G-06: Cormorant Garamond and Source Sans 3, self-hosted. The old
     <link> loaded Cormorant — a different face by the same designer — and
     'Source Sans 3', from fonts.googleapis.com. Self-hosting is not only about
     the third-party request: the PDF renderer cannot fetch a webfont, so the
     printed card and the screen now read off the SAME files and cannot drift
     onto different typefaces. */ ?>
  <link href="/assets/site/fonts.css" rel="stylesheet" />
  <link href="/assets/site/tokens.css" rel="stylesheet" />
  <link href="/diary/diary.css" rel="stylesheet" />
  <link href="/assets/site/av-tokens.css" rel="stylesheet" />
  <link href="/assets/site/avh-nav.css" rel="stylesheet" />
  <link href="/assets/site/page.css" rel="stylesheet" />
<?php foreach (($o['css'] ?? []) as $href): ?>  <link href="<?= e($href) ?>" rel="stylesheet" />
<?php endforeach;
  // Studio Design panel brand override — last, so it re-points the accent vars
  // after every default stylesheet. Validated hex only; empty ⇒ built-in palette.
  $__brand = av_brand_css(); if ($__brand !== ''): ?>  <style id="av-brand"><?= $__brand ?></style>
<?php endif; ?>  <link rel="icon" href="/favicon.ico" sizes="any" />
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

/* The other Afrovanguard sites, named once. Every link out was hand-typed,
   which is how four of the five programme links in the footer came to point
   at cacentre.afrovanguard.org.ng/<slug> — CACENTRE serves its programmes at
   /programs/<slug>, so all four 404ed, and one of them spelt Techome with an
   h it does not have. */
const AV_CACENTRE_URL  = 'https://cacentre.afrovanguard.org.ng';
const AV_AFG_URL       = 'https://afg.afrovanguard.org.ng';
const AV_VOLUNTEER_URL = AV_CACENTRE_URL . '/volunteer';
// Events live on the AFG sub-site; the whole site links out to it.
const AV_EVENTS_URL = AV_AFG_URL . '/events';

/**
 * The navigation model — the single definition for the whole site. PHP pages
 * render it live through render_nav(); the static .html pages get the same
 * markup written in by bin/sync-nav.php (lib/NavSync.php), and the suite fails
 * when one falls behind. Each section opens a mega panel at ≥ 1180px and an
 * accordion in the drawer below that.
 *
 * Copy and images are the 9th-anniversary handoff's (implementation/index.html):
 * `intro` and `intro_cta` head the panel, `feature` is the picture card. Links
 * are root-relative so they work on any host; only genuine off-site subdomains
 * stay absolute, and av_nav_offsite() marks those as leaving.
 */
function av_nav_model(): array {
    $V = AV_VOLUNTEER_URL;
    return [
        'about' => ['label' => 'About', 'href' => '/about.html',
            'intro' => 'One million incorruptible leaders by 2040 — the vision, values and creed behind everything we build.',
            'intro_cta' => 'Read the ethos',
            'cols' => [
                ['title' => 'The organisation', 'links' => [
                    ['About us', '/about.html'], ['How it works', '/how-it-works'],
                    ['Our ethos', '/ethos/'], ['Leadership & model', '/ethos/#leadership'],
                ]],
                ['title' => 'Connect', 'links' => [
                    ['Contact us', '/contact.html'], ['The Diary', '/diary/'], ['Events', AV_EVENTS_URL],
                ]],
            ],
            'feature' => ['kicker' => 'Our mission', 'title' => 'One million incorruptible leaders by 2040', 'href' => '/ethos/', 'img' => '/Images/alimosho.jpg'],
        ],
        // Learning only; on-the-ground programmes live under Projects.
        'academy' => ['label' => 'Academy', 'href' => '/academy/',
            'intro' => 'Structured leadership formation, practical training and doctrine — where Vanguards are made.',
            'intro_cta' => 'All programmes',
            'cols' => [
                ['title' => 'Learn with us', 'links' => [
                    ['All programmes', '/academy/'], ['NextGen Vanguard', '/academy/ngv/'],
                    ["D'Vanguard National Summit", '/academy/dns/'],
                    ['Become a member', '/academy/#membership'],
                    ['Teach with us', '/academy/teach/'], ['Verify a certificate', '/academy/verify.php'],
                ]],
                ['title' => 'Get started', 'links' => [
                    ['Create an account', '/login'], ['Member portal', '/portal/'],
                    ['Become a mentor', '/mentorship/become-a-mentor/'],
                    ['IQ — Quizzes & games', '/IQ/'],
                ]],
            ],
            'feature' => ['kicker' => 'Flagship programme', 'title' => 'NextGen Vanguard — learn, earn & lead', 'href' => '/academy/ngv/', 'img' => '/Images/gates1.png'],
        ],
        // Every flagship programme with a page here links here; only what has
        // no page on this site goes out to its own.
        'projects' => ['label' => 'Projects', 'href' => '/projects/',
            'intro' => 'Technology, creative and leadership initiatives across Lagos and beyond.',
            'intro_cta' => 'See all projects',
            'cols' => [
                ['title' => 'Flagship programmes', 'links' => [
                    ['Street-To-Stardom', '/projects/sts/'], ['Techome', '/projects/techhome/'],
                    ['MediaPro', '/projects/mediapro/'], ['Africa GATES', AV_AFG_URL],
                ]],
                ['title' => 'More programmes', 'links' => [
                    ['Business Executive Club', '/projects/bec/'], ['Career Hub', '/projects/career-hub/'],
                    ['Kingdom Advancement', '/projects/kap/'],
                    ['Next Generation Genius', 'https://next.afrovanguard.org.ng/'],
                    ['All projects', '/projects/'],
                ]],
            ],
            'feature' => ['kicker' => 'Our work', 'title' => 'Programmes changing lives', 'href' => '/projects/', 'img' => '/Images/summer1.png'],
        ],
        // One home for every conversion path.
        'involved' => ['label' => 'Get involved', 'href' => '/donate.html',
            'intro' => 'Every live appeal says what it is for, how far along it is and what a given amount pays for.',
            'intro_cta' => 'See live appeals',
            'cols' => [
                ['title' => 'Give', 'links' => [
                    ['Live appeals', '/give/'], ['Donate', '/donate.html'], ['Become a member', '/academy/#membership'],
                ]],
                ['title' => 'Give your time & grow', 'links' => [
                    ['Volunteer', $V], ['Become a mentor', '/mentorship/become-a-mentor/'], ['Partner with us', '/contact.html'],
                    ['Franchise a CACENTRE', '/franchise'], ['Visit CACENTRE', AV_CACENTRE_URL],
                ]],
            ],
            'feature' => ['kicker' => 'Stand with us', 'title' => 'See exactly what we need', 'href' => '/give/', 'img' => '/Images/storm2.jpg'],
        ],
        'diary' => ['label' => 'Diary', 'href' => '/diary/',
            'intro' => 'We publish the working — field notes and methodology as we build the movement.',
            'intro_cta' => 'Read the Diary',
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
            'feature' => ['kicker' => 'The Afrovanguard Diary', 'title' => 'Field notes from a youth movement', 'href' => '/diary/', 'img' => '/Images/bootcamp1.png'],
        ],
    ];
}

/**
 * Mark up a navigation link that leaves this site: a new tab, a decorative
 * arrow hidden from assistive tech, and the destination named in words for
 * anyone who cannot see it. Same-site links (www or not) get nothing.
 *
 * @return array{0:string,1:string} [attributes, trailing markup]
 */
function av_nav_offsite(string $href): array
{
    if (!preg_match('~^https?://~i', $href)) return ['', ''];
    $host = strtolower((string) parse_url($href, PHP_URL_HOST));
    $self = strtolower((string) parse_url(SITE_URL, PHP_URL_HOST));
    $bare = static fn(string $h): string => preg_replace('~^www\.~', '', $h) ?? $h;
    if ($host === '' || $bare($host) === $bare($self)) return ['', ''];
    return [
        ' target="_blank" rel="noopener"',
        '<span class="avh-ext" aria-hidden="true"> ↗</span><span class="avh-sr"> (opens ' . e($host) . ' in a new tab)</span>',
    ];
}

/**
 * The section bar: a second row under the nav for sections whose peer pages a
 * reader moves between. Keyed by the active section; only the Academy has one.
 */
function av_subnav_model(): array {
    return [
        'academy' => [
            'brand'  => ['label' => 'Academy', 'href' => '/academy/'],
            'links'  => [
                ['Programmes', '/academy/#catalogue'], ['NextGen Vanguard', '/academy/ngv/'],
                ["DNS '26", '/academy/dns/'], ['Membership', '/academy/#membership'], ['Teach', '/academy/teach/'],
            ],
            'search' => ['placeholder' => 'Search the Academy…', 'target' => '/academy/'],
            'cta'    => ['label' => 'Log in', 'href' => '/login?next=/academy/'],
        ],
    ];
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

/**
 * The site navigation: sticky bar with mega menus at ≥ 1180px, a drawer below,
 * and the signed-in state avh-nav.js fills from /academy/api.php?action=me.
 * Markup is the 9th-anniversary handoff's (implementation/index.html), drawn
 * from av_nav_model() so a link changes in one place. Rendered with no session:
 * a static page must never have a member baked into it.
 *
 * Needs /assets/site/av-tokens.css + avh-nav.css (render_head) and avh-nav.js
 * (render_footer). $active picks the section bar; the bar carries no "current"
 * state because the handoff's menu buttons open panels rather than pages.
 */
function render_nav(string $active = '', array $opts = []): void {
    $model = av_nav_model();
    $sub = av_subnav_model()[$active] ?? null;
    $V = AV_VOLUNTEER_URL;
    $chev = '<svg viewBox="0 0 10 10" aria-hidden="true"><path d="M2 3.5 5 6.5 8 3.5" fill="none" stroke="currentColor" stroke-width="1.3"/></svg>';
    $link = static function (string $label, string $href): string {
        [$xa, $xm] = av_nav_offsite($href);
        return '<a href="' . e($href) . '"' . $xa . '>' . e($label) . $xm . '</a>';
    };
?>
<div class="avh-chrome" data-avh-chrome>
<nav class="avh-nav" aria-label="Main" data-avh-nav>
  <div class="avh-nav-in avh-pad">
    <a class="avh-brand" href="/" data-avh-close><img src="/assets/site/av-seal.png" alt="" width="40" height="40"><span>Afrovanguard</span></a>
    <div class="avh-wide">
<?php foreach ($model as $k => $it): ?>
      <button type="button" class="avh-navbtn" data-avh-menu="<?= e($k) ?>" aria-expanded="false" aria-haspopup="true" aria-controls="avh-mega-<?= e($k) ?>"><?= e($it['label']) . $chev ?></button>
<?php endforeach; ?>
      <a class="avh-signin" href="/login" data-avh-close data-avh-login>Sign in</a>
      <div class="avh-acct" data-avh-acct>
        <button type="button" class="avh-acct-btn" data-avh-acct-btn data-avh-close aria-expanded="false" aria-haspopup="true" aria-controls="avh-acct-menu"><span class="avh-av" data-avh-me-initial>M</span><span data-avh-me-first>Member</span><svg viewBox="0 0 10 10" aria-hidden="true"><path d="M1.5 3.5 5 7l3.5-3.5" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg></button>
        <div class="avh-acct-menu" id="avh-acct-menu" data-avh-acct-menu hidden>
          <div class="avh-acct-head"><span class="avh-av avh-av--lg" data-avh-me-initial>M</span><span class="avh-acct-id"><span class="avh-acct-name" data-avh-me-name>Member</span><span class="avh-acct-email" data-avh-me-email hidden></span></span></div>
          <a class="avh-acct-cta" href="/portal/">Your portal<span aria-hidden="true">→</span></a>
          <nav class="avh-acct-links" aria-label="Your account"><a href="/academy/">Academy</a><a href="/diary/">The Diary</a><a href="/community/">Community</a></nav>
          <button type="button" class="avh-acct-out" data-avh-logout>Sign out</button>
        </div>
      </div>
      <div class="avh-ctas">
        <a class="avh-join" href="<?= e($V) ?>" data-avh-close>Join the Movement</a>
        <a class="avh-donate" href="/donate.html" data-avh-close>Donate</a>
      </div>
    </div>
    <div class="avh-narrow">
      <a class="avh-join" href="<?= e($V) ?>">Join the Movement</a>
      <a class="avh-acct-mini" href="/portal/" aria-label="Your portal"><span class="avh-av" data-avh-me-initial>M</span></a>
      <button type="button" class="avh-burger" data-avh-burger aria-label="Open menu" aria-expanded="false" aria-controls="avh-drawer"><span></span><span></span><span></span></button>
    </div>
  </div>
<?php if ($sub): ?>
  <div class="avh-sub" aria-label="<?= e($sub['brand']['label']) ?> section">
    <div class="avh-sub-in avh-pad">
      <a class="avh-sub-brand" href="<?= e($sub['brand']['href']) ?>"><?= e($sub['brand']['label']) ?></a>
      <div class="avh-sub-links"><?php foreach ($sub['links'] as [$ll, $lh]) echo $link($ll, $lh); ?></div>
<?php if (!empty($sub['search'])): ?>      <form class="avh-sub-search" role="search" action="<?= e($sub['search']['target']) ?>" method="get"><label><span class="avh-sr">Search this section</span><input type="search" name="q" placeholder="<?= e($sub['search']['placeholder']) ?>"></label></form>
<?php endif; if (!empty($sub['cta'])): ?>      <a class="avh-sub-cta" href="<?= e($sub['cta']['href']) ?>" data-avh-login><?= e($sub['cta']['label']) ?></a>
<?php endif; ?>
    </div>
  </div>
<?php endif; foreach ($model as $k => $it): $f = $it['feature']; ?>
  <div class="avh-mega" id="avh-mega-<?= e($k) ?>" data-avh-panel="<?= e($k) ?>" hidden>
    <div class="avh-mega-in avh-pad">
      <div class="avh-mega-intro"><div><?= e($it['label']) ?></div><p><?= e($it['intro']) ?></p><a href="<?= e($it['href']) ?>"><?= e($it['intro_cta']) ?> <span aria-hidden="true">→</span></a></div>
<?php foreach ($it['cols'] as $col): ?>
      <div class="avh-mega-col"><div><?= e($col['title']) ?></div><?php foreach ($col['links'] as [$ll, $lh]) echo $link($ll, $lh); ?></div>
<?php endforeach; ?>
      <a class="avh-feat" href="<?= e($f['href']) ?>"><img src="<?= e($f['img']) ?>" alt="<?= e($f['title']) ?>" loading="lazy"><span><?= e($f['kicker']) ?></span><b><?= e($f['title']) ?></b></a>
    </div>
  </div>
<?php endforeach; ?>
</nav>

<div class="avh-backdrop" data-avh-backdrop hidden></div>
<div class="avh-drawer" id="avh-drawer" role="dialog" aria-modal="true" aria-label="Menu" data-avh-drawer hidden>
  <div class="avh-drawer-top"><button type="button" class="avh-x" data-avh-drawer-close aria-label="Close menu"><span></span><span></span></button></div>
  <div class="avh-drawer-body">
    <div class="avh-drawer-me"><span class="avh-av avh-av--lg" data-avh-me-initial>M</span><span class="avh-acct-id"><span class="avh-acct-name" data-avh-me-name>Member</span><span class="avh-acct-email" data-avh-me-email hidden></span></span><a class="avh-drawer-portal" href="/portal/">Your portal<span aria-hidden="true">→</span></a></div>
<?php foreach ($model as $k => $it): ?>
    <div class="avh-acc">
      <button type="button" data-avh-acc aria-expanded="false" aria-controls="avh-acc-<?= e($k) ?>"><?= e($it['label']) ?><span class="avh-plus" aria-hidden="true"></span></button>
      <div class="avh-acc-links" id="avh-acc-<?= e($k) ?>" hidden><?= $link($it['label'] . ' home', $it['href']) ?><?php foreach ($it['cols'] as $col) foreach ($col['links'] as [$ll, $lh]) echo $link($ll, $lh); ?></div>
    </div>
<?php endforeach; ?>
    <a class="avh-drawer-signin" href="/login" data-avh-login>Sign in</a>
    <button type="button" class="avh-drawer-out" data-avh-logout>Sign out</button>
  </div>
  <div class="avh-drawer-ctas"><a href="<?= e($V) ?>">Join the Movement</a><a href="/donate.html">Donate</a></div>
</div>
</div>
<?php }

/** A single entry card (used on the index and in related rails). */
function render_card(array $a): void {
    // The reference code is part of what the filter box matches, so a code typed
    // into the Diary filter narrows to that entry.
    $search = strtolower($a['title'] . ' ' . $a['dek'] . ' ' . $a['category'] . ' ' . ($a['ref_code'] ?? ''));
    $url = '/diary/' . e($a['slug']) . '/';
    $cover = $a['cover_url'] ?? ''; ?>
        <article class="post-card" data-reveal data-cat="<?= e($a['category_slug']) ?>" data-slug="<?= e($a['slug']) ?>" data-search="<?= e($search) ?>">
<?php if ($cover): ?>
          <a class="pc-thumb has-cover" href="<?= $url ?>" aria-label="<?= e($a['title']) ?>" style="background-image:url('<?= e($cover) ?>')"><span class="pc-cat-tag"><?= e($a['category']) ?></span></a>
<?php else: ?>
          <a class="pc-thumb <?= e($a['gradient']) ?> g-grain" href="<?= $url ?>" aria-label="<?= e($a['title']) ?>"><span class="pc-mark"><?= e($a['mc_title']) ?></span></a>
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
          <h2>Programmes</h2>
          <ul class="footer-links">
            <li><a href="<?= e(AV_CACENTRE_URL) ?>/programs/street-to-stardom">Street-To-Stardom</a></li>
            <li><a href="https://next.afrovanguard.org.ng/">Next Generation Genius</a></li>
            <li><a href="<?= e(AV_CACENTRE_URL) ?>/programs/techome">Techome</a></li>
            <li><a href="<?= e(AV_CACENTRE_URL) ?>/programs/mediapro">MediaPro</a></li>
            <li><a href="<?= e(AV_AFG_URL) ?>">Africa GATES</a></li>
          </ul>
        </div>
        <div class="footer-col">
          <h2>Organization</h2>
          <ul class="footer-links">
            <li><a href="<?= $S ?>/about.html">About Us</a></li>
            <li><a href="/ethos/">Our Ethos</a></li>
            <li><a href="/academy/">Academy</a></li>
            <li><a href="<?= $S ?>/projects/">Projects</a></li>
            <li><a href="/franchise">Franchise</a></li>
            <li><a href="/diary/">The Diary</a></li>
            <li><a href="<?= e(AV_EVENTS_URL) ?>">Events</a></li>
            <li><a href="<?= e(AV_VOLUNTEER_URL) ?>">Volunteer</a></li>
            <li><a href="<?= $S ?>/donate.html">Donate</a></li>
            <li><a href="/login">Sign In</a></li>
            <li><a href="<?= $S ?>/contact.html">Contact</a></li>
          </ul>
        </div>
        <div class="footer-col footer-contact">
          <h2>Get in Touch</h2>
          <p>Afrovanguard HQ<br/>Alimosho LGA, Lagos, Nigeria</p>
          <p><a class="footer-mail" href="mailto:cacentre@afrovanguard.org.ng">cacentre@afrovanguard.org.ng</a></p>
          <div class="footer-newsletter-mini">
            <h3>Get the Diary</h3>
            <form class="diary-subscribe" novalidate>
              <input type="email" name="email" placeholder="Your email address" aria-label="Newsletter email" autocomplete="email" required />
              <input type="text" name="hp" class="hp" tabindex="-1" autocomplete="off" aria-hidden="true" />
              <button type="submit" class="btn btn-primary">Subscribe →</button>
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
  <script src="/assets/site/avh-nav.js" defer></script>
  <script src="/assets/site/celebrations.js" defer></script>
  <script src="/assets/site/color-aware.js" defer></script>
  <script src="/diary/diary.js" defer></script>
  <script src="/assets/site/chioma.js" defer></script>
</body>
</html>
<?php }
