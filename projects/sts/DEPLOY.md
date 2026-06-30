# Street-To-Stardom — Deploy & Operations

Plain PHP. No build step. Drop the repo on any Apache/LiteSpeed shared
host (cPanel, etc.) with PHP 8.x and it runs.

## It is operational out of the box

With **no configuration at all**, the backend uses a self-creating
**SQLite** database at `api/data/sts.sqlite` (auto-seeded on first
request). All of the following work immediately:

- Contact, Volunteer, Sponsor, Partner and Newsletter forms (persisted)
- Live stats, programme sessions ("next intake"), and field notes
- CSRF protection (cookie-based) and per-IP rate limiting

`api/data/` must be writable by PHP (it is created automatically). It is
git-ignored and blocked from the web by `api/.htaccess`.

## Going live on the subdomain

STS is served at **https://sts.afrovanguard.org.ng/**. Point that
subdomain's document root at this folder (the `projects/sts` directory in
the afrovanguard-site repo doubles as the docroot). Requests to the
legacy `afrovanguard.org.ng/projects/sts/*` path 301-redirect here.

## Optional: upgrade to MySQL

To use MySQL instead of the SQLite fallback, copy `api/.env.example` to
`api/.env` and set:

```
DB_HOST=localhost
DB_NAME=your_db
DB_USER=your_user
DB_PASS=your_pass
SITE_URL=https://sts.afrovanguard.org.ng
```

Then apply the schema + seed:

```
mysql -u your_user -p your_db < migrations/001_init.sql
mysql -u your_user -p your_db < migrations/002_seed.sql
```

The code auto-detects the driver — when `DB_NAME` is set it uses MySQL,
otherwise SQLite. The marketing pages and the API read the same source.

## Optional: outbound email

Form notifications and the newsletter welcome use, in order: PHPMailer
SMTP → Resend API → PHP `mail()`. Set the relevant values in `api/.env`
(see `api/.env.example`). With none configured, submissions still persist
and the site stays fully functional — only the notification email is
skipped.

## Security checklist before launch

- [ ] Rotate the keys committed in earlier snapshots of
      `ceo/api/config.php` (Gemini API key, Apps Script key, SMTP
      password). That file is git-ignored now; set real values on the
      host only.
- [ ] Confirm `api/.env`, `api/data/`, `api/logs/` are not web-readable
      (enforced by `.htaccess`; verify on the live host).
- [ ] Set `SITE_URL` so unsubscribe links resolve.
