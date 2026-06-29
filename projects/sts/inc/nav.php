<?php
/** Top navigation. Highlights the active item from $PAGE_PATH. */
$NAV_PATH = $PAGE_PATH ?? ($_SERVER['REQUEST_URI'] ?? '/');
/** @return string ' aria-current="page"' when $href matches the current section. */
function sts_nav_active(string $href, string $current): string {
  $current = '/' . trim(parse_url($current, PHP_URL_PATH) ?? '/', '/') . '/';
  $h = '/' . trim($href, '/') . '/';
  if ($h === '//') $h = '/';
  $match = ($h === '/') ? ($current === '/') : (strpos($current, $h) === 0);
  return $match ? ' aria-current="page"' : '';
}
?>
<div aria-hidden="true" class="nav-backdrop"></div>
<nav class="nav" data-screen-label="Top nav">
  <div class="nav-inner">
    <a aria-label="Street-To-Stardom home" class="brand" href="/">
      <img alt="" class="sts-logo" height="32" src="/assets/sts-logo.svg" width="32"/>
      <span class="brand-text">
        <span class="brand-name">Street‑To‑Stardom</span>
        <span class="brand-sub">An Afrovanguard initiative</span>
      </span>
    </a>
    <div class="nav-links">
      <a<?= sts_nav_active('/about', $NAV_PATH) ?> href="/about">About</a><a<?= sts_nav_active('/programs', $NAV_PATH) ?> href="/programs">Programs</a><a<?= sts_nav_active('/methodology', $NAV_PATH) ?> href="/methodology">Methodology</a><a<?= sts_nav_active('/impact', $NAV_PATH) ?> href="/impact">Impact</a><a<?= sts_nav_active('/team', $NAV_PATH) ?> href="/team">Team</a><a<?= sts_nav_active('/units', $NAV_PATH) ?> href="/units">Units</a><a<?= sts_nav_active('/blog', $NAV_PATH) ?> href="/blog">Field notes</a><a<?= sts_nav_active('/contact', $NAV_PATH) ?> href="/contact">Contact</a><a href="/ceo" style="color:var(--crimson);font-weight:700;">2026 Speakers ↗</a>
    </div>
    <div class="nav-right">
      <div aria-label="Theme" class="theme-toggle" role="radiogroup">
        <button aria-label="Theme: system" data-mode="system" role="radio" title="Theme: System" type="button">
          <svg aria-hidden="true" fill="none" height="14" viewbox="0 0 14 14" width="14"><rect height="7.5" rx="1" stroke="currentColor" stroke-width="1.3" width="11" x="1.5" y="2.5"></rect><path d="M5 12h4M7 10v2" stroke="currentColor" stroke-linecap="round" stroke-width="1.3"></path></svg>
        </button>
        <button aria-label="Theme: light" data-mode="light" role="radio" title="Theme: Light" type="button">
          <svg aria-hidden="true" fill="none" height="14" viewbox="0 0 14 14" width="14"><circle cx="7" cy="7" r="2.6" stroke="currentColor" stroke-width="1.3"></circle><path d="M7 1.5v1.4M7 11.1v1.4M1.5 7h1.4M11.1 7h1.4M2.8 2.8l1 1M10.2 10.2l1 1M2.8 11.2l1-1M10.2 3.8l1-1" stroke="currentColor" stroke-linecap="round" stroke-width="1.3"></path></svg>
        </button>
        <button aria-label="Theme: dark" data-mode="dark" role="radio" title="Theme: Dark" type="button">
          <svg aria-hidden="true" fill="none" height="14" viewbox="0 0 14 14" width="14"><path d="M11.5 8.4A4.6 4.6 0 0 1 5.6 2.5 4.6 4.6 0 1 0 11.5 8.4Z" stroke="currentColor" stroke-linejoin="round" stroke-width="1.3"></path></svg>
        </button>
      </div>
      <a class="nav-cta" href="/get-involved">Get Involved</a>
      <a class="nav-ext" href="https://afrovanguard.org.ng" rel="noopener noreferrer" target="_blank" title="Visit Afrovanguard">
        <span>Afrovanguard</span>
        <svg aria-hidden="true" fill="none" height="10" viewbox="0 0 10 10" width="10"><path d="M2 8 L8 2 M3.5 2 L8 2 L8 6.5" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.4"></path></svg>
      </a>
      <button aria-expanded="false" aria-label="Open navigation" class="nav-burger"><span></span><span></span><span></span></button>
    </div>
  </div>
</nav>
