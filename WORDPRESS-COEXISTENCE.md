# Running alongside WordPress — the custom app is the source of truth

This custom PHP app shares a document root with a WordPress install. The
design goal: **the custom app always wins; WordPress must never override
or intercept the custom routes, files, or SEO assets.**

## Why the custom app wins (and how to keep it that way)
WordPress's root `.htaccess` only rewrites a request to `index.php` when
the path is **neither a real file nor a real directory**:

```
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule . /index.php [L]
```

So **anything that exists on disk is served directly and is never seen by
WordPress.** The custom app leans on this:

- **Real directories** — `/diary/`, `/academy/`, `/ethos/`, `/admin/`,
  `/blog/` — are served by their own scoped `.htaccess`, so WordPress's
  permalink engine never touches them. Pretty sub-paths (e.g.
  `/diary/<slug>/`) are resolved by that folder's `.htaccess` with `[L]`,
  which short-circuits the root WordPress rules.
- **Real files** — `/robots.txt`, `/sitemap.xml`, `/error.php` — physically
  exist, so they override any virtual `robots.txt` / `sitemap.xml` a
  WordPress SEO plugin would otherwise generate. The custom versions are
  authoritative.

Do **not** delete these physical files/folders expecting WordPress to fill
in — that is exactly when WordPress would take over. Keeping them on disk
is what guarantees the custom app stays in control.

## Custom-owned paths (WordPress is shadowed here, by design)
| Path                | Served by (authoritative)                    |
|---------------------|----------------------------------------------|
| `/diary/…`          | The Diary (custom)                           |
| `/academy/…`        | The Academy / LMS (custom)                   |
| `/ethos/`           | Ethos page (custom)                          |
| `/blog/…`           | 301 → `/diary/…` (custom)                     |
| `/admin/`           | Diary/Academy Studio (custom)                |
| `/robots.txt`       | Custom (authoritative; lists all sitemaps)   |
| `/sitemap.xml`      | Custom (authoritative)                       |
| `/diary/sitemap.xml`, `/diary/feed.xml`, `/academy/sitemap.xml` | Custom |
| `/assets/…`, `/error.php` | Custom static assets / error handler   |

`robots.txt` also references WordPress's own sitemap (`/wp-sitemap.xml`) so
WordPress content is still indexed — the custom app simply owns the root.

## No global rewrites; internals locked down
- The custom app never ships or edits the document-root `.htaccess`. Every
  custom rewrite is scoped with `RewriteBase` to its own folder and wrapped
  in `<IfModule mod_rewrite.c>`.
- `/lib/` and `/db/` (PHP source + the SQLite database) return 403 to direct
  web requests (`Require all denied`, 2.2 fallback); PHP still reads them
  from the filesystem.

## No build step / no SSH required
The database auto-creates and migrates on first request; generated assets
(OG images, error art, certificates) are produced by PHP at request time
or committed. Nothing needs to run on the server.
