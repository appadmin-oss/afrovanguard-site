# Speed and accessibility — what keeps pages light and usable

## Weight
- **Fonts**: browsers get WOFF2 subsets (`assets/site/fonts/*.woff2`, about 0.4 MB
  for all eleven, against 2.8 MB of TTF). The TTFs stay: dompdf and the card
  renderer read TrueType, and `fonts.css` lists WOFF2 first, TTF second, so each
  reader takes what it can. After changing a TTF, regenerate its WOFF2:

      pyftsubset X.ttf --flavor=woff2 --output-file=X.woff2 --layout-features='*' --no-hinting \
        --unicodes="U+0000-024F,U+0300-036F,U+1E00-1EFF,U+2000-206F,U+20A0-20CF,U+2100-214F,U+2190-21FF,U+2212,U+2215,U+25A0-25FF,U+2713,U+2715,U+FB00-FB06"

  The ranges keep Latin Extended Additional and combining marks (Yorùbá's ẹ ọ ṣ
  and tones) and the naira sign.
- **The seal**: the nav uses `av-seal-96.png` (18 KB); screen cards and
  celebrations `av-seal-256.png`. `av-seal.png` (640 px) is for print and the
  NGG card export only (CardBundle finds it by that path).
- **Libraries load when needed**, not with the page: in the Volunteer dashboard
  FullCalendar (the Calendar view), uPlot (My record's chart), Tom Select (adding
  somebody to a team) and SortableJS (the minutes editor) are fetched on first use.
- Every local CSS/JS URL is stamped with its hash (`av_asset()`,
  `tools/stamp-assets.php`), so a cached copy is never the wrong one.

## Accessibility (WCAG 2.1 AA, checked with axe-core)
- Announcements go through live regions that exist from page load (a region
  added at the moment it speaks is often not read); errors are assertive.
- Toggle groups (filters, channels) are `role="group"` with `aria-pressed`,
  not tablists; real tabs move with the arrow keys.
- Anything you can drag you can also move with buttons (“Move up / Move
  down”), and the new position is spoken.
- Charts have a table view; calendar events and month changes are named.
- The dashboard's minute-by-minute refresh waits while focus is inside it, so
  a screen reader's place is not moved.
- Colour contrast at least 4.5:1 for text, in light and dark themes.
