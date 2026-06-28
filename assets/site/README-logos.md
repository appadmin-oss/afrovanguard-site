# Brand logo slots (drop-zone)

The two-tier nav uses a wordmark by default (the **Afrovanguard** name set in
Cormorant). `av_brand_mark()` in `lib/partials.php` will serve a committed logo
image instead, the moment one is present at the paths below.

## What to add

| File (any one extension)                         | Where it shows                                  |
|--------------------------------------------------|-------------------------------------------------|
| `assets/site/logo-afrovanguard.svg` (or `.png`/`.webp`) | Tier-1 brand on every page + the mobile drawer + the sign-in aside fallback |
| `assets/site/logo-academy.svg` (or `.png`/`.webp`)      | Tier-2 (the Academy section bar) brand          |

- **Preferred format:** `.svg` (crisp at any size); `.png`/`.webp` also work.
- **Lookup order:** `.svg` → `.png` → `.webp`. First match wins.
- **Sizing:** rendered at ~34px tall in tier 1 and ~30px in the subnav (height
  is fixed in `nav.css`; width scales). Supply a horizontal lockup; transparent
  background. The tier-1 bar is **dark**, so the logo must read on dark.

## After adding a logo

Re-bake the static marketing pages so they pick it up too:

```bash
php tools/build-chrome.php
```

PHP pages (Academy, Diary, Ethos, …) pick it up automatically on the next
request — no rebuild needed.
