# The Afrovanguard Diary — architecture

A database-backed, modular section of the site. Content lives in a real
(SQLite) database; pages and the API share one data layer and one set of
view partials, so everything stays connected and in sync.

```
lib/                         shared backend (modular, used by every page)
  bootstrap.php              entry point: config + DB + repository
  Database.php               PDO/SQLite singleton; auto-migrates + seeds
  DiaryRepository.php        all queries (articles, sections, claps, subscribers)
  partials.php               shared view: head, nav, footer, cards, reader bar
  helpers.php                e(), json_out(), same-origin guard, urls
db/
  schema.sql                 tables (categories, articles, sections, related,
                             reactions, subscribers)
  content.php                CANONICAL content — the single source of truth
  seed.php                   loads content.php into the database
  diary.sqlite               runtime DB (git-ignored, auto-created)
diary/
  index.php                  listing, rendered from the DB
  article.php                one entry, rendered from the DB (/diary/<slug>/)
  api.php                    JSON API (list / article / reactions / react / subscribe)
  .htaccess                  pretty-URL routing (Apache)
  diary.css  diary.js        front-end (theme, reader, bookmarks, search, …)
router.php                   local-dev router for `php -S` (mirrors .htaccess)
```

## How it fits together
- **Single source of truth.** All article text lives in `db/content.php`
  and is seeded into the database. Pages never hard-code content.
- **One data layer.** `DiaryRepository` is the only thing that touches SQL,
  used identically by the pages and the API.
- **Shared chrome.** `lib/partials.php` defines the nav, footer, head and
  cards once; `index.php` and `article.php` both render through it.
- **Real, shared engagement.** Applause and newsletter sign-ups POST to
  `diary/api.php` and persist in SQLite, so counts are the same for every
  visitor — not just per-browser.

## Authoring — Diary Studio (`/admin/`)
A standard rich-text CMS for writing entries, gated by the admin bearer token.

- `admin/index.php` + `admin/app.js` — token login, entry list (drafts +
  published), and a TinyMCE editor with image upload, cover image,
  category/related pickers, draft/publish and live preview.
- `admin/api.php` — authenticated CRUD (`list / get / save / delete`) plus
  `upload`. On save, the TOC is derived automatically from the `<h2>`
  headings and read-time is estimated from word count.
- Only `status = 'published'` entries appear on the public Diary, in the
  sitemap, and in the RSS feed; drafts are private.

## Media — Cloudinary (`lib/Cloudinary.php`)
Image uploads (in-body and cover) go to **Cloudinary** when
`CLOUDINARY_CLOUD_NAME` / `CLOUDINARY_API_KEY` / `CLOUDINARY_API_SECRET`
are configured (signed, server-side). Without keys they fall back to local
storage under `/uploads` so the editor still works in development.

Covers feed straight into the card thumbnails, the article hero, and the
auto-generated social image (`diary/og.php` composites the cover under a
brand scrim).

## Working with it
```bash
# Local preview (pretty URLs work via router.php)
php -S 127.0.0.1:8000 router.php
#   → http://127.0.0.1:8000/diary/

# Re-seed after editing db/content.php
php db/seed.php --fresh
```

The database auto-creates itself from `schema.sql` + `content.php` on the
first request, so a fresh deploy needs no manual migration — just make sure
the `db/` directory is writable by PHP.
