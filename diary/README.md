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
