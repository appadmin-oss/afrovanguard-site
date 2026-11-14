# Accessible navigation

Every way of moving around the site must work with a keyboard alone and make
sense to a screen reader (WCAG 2.2 AA). This is how the navigation surfaces do
it, and what a new one owes. `tests/navaccessible.test.php` holds the rules on
the shared chrome, every static page with a nav, and the real pages fetched
through `router.php` (signed out, as a member who teaches, with a Studio
session). Run it with `php tests/run.php navaccessible`.

## The patterns

**Skip link, first.** The first thing Tab reaches on every layout is
`<a href="#…">Skip to main content</a>`, hidden until focused, landing on the
page's one visible `<main id="…" tabindex="-1">`. `render_head()` emits it for
site pages (`.avh-skip` → `#main`) and app shells (`.skip-link` → `#main-content`).
An app that shows one `<main hidden>` view at a time (the Studio) points the link
at a focusable marker before the views and moves focus on to the visible one in
script. Do not call `scrollIntoView()` on load to reveal a nav item: Chrome moves
its Tab starting point with it and the first Tab skips the skip link — scroll the
container (`scrollTop`) instead.

**Landmarks.** One `<header>` (banner) around the site nav, one `<main>`, one
`<footer>`. Every `<nav>` has a distinct `aria-label` — "Main", "Site menu" (the
phone drawer), "Footer", "Breadcrumb", "Sections", "Studio sections", "Academy
Studio sections", "Course tools", "Lessons", "Mentor portal"… — and no nav sits
inside another. A hero or banner image is a `<section>`, never a second
`<header>` outside `<main>`.

**Where you are, in words.** The link to the current page carries
`aria-current="page"` (colour and underline are extra). `avh_mark_current()` is
the one marker for the Home nav: `av_site_nav()` and `avh_nav(avh_here())` on PHP
pages, and `tools/build-avh-chrome.php` bakes it into each static page. App
views switched in script (portal, Studio, Academy Studio) set it in the server
markup for the default view and move it in the same function that switches
views. A lesson list uses `aria-current="page"` on the open lesson.

**Menus are disclosures.** Site navigation is links, so a menu button is a
`<button type="button" aria-expanded aria-controls="panel-id">` and the panel is
`hidden` (or `visibility:hidden` while it slides) when closed — never only moved
off-screen or made transparent, which leaves its links in the Tab order. No
`aria-haspopup="true"`/`role="menu"`: those promise arrow-key menu behaviour.
A button that opens a dialog says `aria-haspopup="dialog"`. Opening moves focus
to the first link (or the current item); Escape closes and returns focus to the
button; a click outside closes. Nothing opens on hover only.

**Tabs are honest.** A row of links to separate URLs (teach.php's course tools)
is a `<nav>` of links with `aria-current`. A real in-page tab set (the course
page) is `tablist`/`tab`/`tabpanel` with arrow keys, Home and End. Buttons that
filter a list in place are a `role="group"` of `aria-pressed` toggles.

**Breadcrumbs.** `<nav aria-label="Breadcrumb"><ol class="av-crumbs"><li>…</li></ol></nav>`,
separators `aria-hidden="true"`, the last item `aria-current="page"`.
`.av-crumbs` (tokens.css) keeps the row layout of the nav it sits in.

**Names and icons.** Every link and button has a name (text, `aria-label`, or
an image's `alt`). Decorative SVGs get `aria-hidden="true" focusable="false"`.
A count says what it counts: "Notifications, 3 unread", "Tasks 3 open" (a
visually hidden `.av-sr` suffix or the button's label), not a bare "3".

**Focus and size.** Every nav control shows a focus ring of at least 3:1 against
its background: the global `:focus-visible` ring in `tokens.css`
(`--afg-focus`), `--av-focus` on Home-chrome pages, gold only on dark navy. Never
`outline:none` without a replacement. Targets are at least 24×24px (44px on the
phone chrome).

## Adding or changing a nav

1. Use the patterns above; reuse `avh_nav()` / `render_nav()` for site pages.
2. Give the nav a name nobody else on the page uses.
3. Mark the current item with `aria-current`.
4. If it opens and closes, wire `aria-expanded`, `aria-controls`, Escape, focus
   in and focus back, click-outside, and hide the closed panel properly.
5. Add the page to the sweep in `tests/navaccessible.test.php` (static pages
   with a `<nav>` are picked up automatically) and run it; then Tab through it
   in a browser from the address bar.
