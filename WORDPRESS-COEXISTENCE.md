# Running alongside WordPress — coexistence notes

This custom PHP app is deployed into the **same document root as a
WordPress install**. It is designed to never interfere with WordPress.

## How coexistence works
- **No root `.htaccess` changes.** This repo never ships or edits the
  document-root `.htaccess` — that file belongs to WordPress. WordPress's
  permalink rules (`RewriteRule . /index.php`) only fire for paths that do
  **not** resolve to a real file or directory.
- **The custom app lives in real subdirectories** (`/diary/`, `/academy/`,
  `/ethos/`, `/admin/`). Each has its **own** `.htaccess` whose rewrites are
  scoped with `RewriteBase` to that folder only, wrapped in
  `<IfModule mod_rewrite.c>`. Because those folders physically exist, Apache
  serves them from the folder's `.htaccess` and the request never reaches
  WordPress's root rules. No global rewrites, no clashes.
- **SEO files are NOT placed at the document root.** We do not ship a root
  `robots.txt` or `sitemap.xml` (WordPress / Yoast / Rank Math own those).
  Section sitemaps live at non-conflicting paths and can be added to your
  WP SEO plugin's sitemap index if you want them aggregated:
    - `/diary/sitemap.xml`, `/diary/feed.xml`
    - `/academy/sitemap.xml`

## Reserved paths — do NOT create WordPress pages/permalinks with these slugs
Creating a WP page at one of these would be shadowed by the custom app:

| Path        | Owned by        |
|-------------|-----------------|
| `/diary/`   | The Diary (custom) |
| `/academy/` | The Academy / LMS (custom) |
| `/ethos/`   | Ethos page (custom) |
| `/admin/`   | Diary/Academy Studio (custom) — distinct from WP's `/wp-admin/` |
| `/assets/`  | Static assets (custom) |

`/blog` is intentionally **left to WordPress** — the custom blog lives at
`/diary/`. (If you don't use `/blog` in WP and want old links redirected to
`/diary`, add a single line in your WP SEO/redirect plugin — we don't add a
`/blog` folder so it can't shadow WordPress.)

## Internals are not web-readable
`/lib/` and `/db/` (PHP source + the SQLite database) are blocked from
direct web access via a `Require all denied` `.htaccess` in each. PHP still
reads them from the filesystem.

## No build step / no SSH required
The database auto-creates and migrates on first request; all generated
assets (OG images, error-page art, certificates) are produced by PHP at
request time or committed. Nothing needs to run on the server.
