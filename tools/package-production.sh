#!/usr/bin/env bash
# tools/package-production.sh — build the zip a person uploads to cPanel.
#
#   tools/package-production.sh [out.zip]     (default: dist/afrovanguard-production.zip)
#
# The site is plain PHP with vendor/ committed (the host has no Composer), so
# the package is the committed tree at HEAD, minus what never runs on the host:
# the test suite, CI, internal docs and audits, Docker files and the build and
# dev tools. It never contains config.php, a .env or a database file: those are
# the server's, and `git archive` only sees tracked files.
#
# Extract the zip into the site's document root (public_html). docs/INSTALL.md
# in it (refused to the web by the root .htaccess, like all of docs/) is the
# install and update guide. Needs git, zip and php on the machine that builds it.
set -euo pipefail

root="$(cd "$(dirname "$0")/.." && pwd)"
out="${1:-$root/dist/afrovanguard-production.zip}"
case "$out" in /*) ;; *) out="$PWD/$out" ;; esac
stage="$(mktemp -d)"
trap 'rm -rf "$stage"' EXIT

cd "$root"
rev="$(git rev-parse --short HEAD)"
git archive --format=tar HEAD | tar -x -C "$stage"

# ── not runtime ─────────────────────────────────────────────────────────────
# Tests, CI and repository plumbing.
rm -rf "$stage/tests" "$stage/.github" "$stage/.gitattributes" "$stage/.gitignore"
# Containers (DEPLOY.md §2-4 build images from the repository, not from this zip).
rm -rf "$stage/deploy" "$stage/Dockerfile" "$stage/docker-compose.yml" "$stage/.dockerignore"
# Developer tooling: CI checks, generators and one-off seeds. The operational
# tools that run on the server (backup-decrypt, gate-revoke, directory-backfill,
# gws-sync/-watch, warm-error-poems, academy-a11y-audit, build-recorder) stay.
rm -rf "$stage/bin" "$stage/scripts" "$stage/composer.json" "$stage/composer.lock"
for t in build-avh-chrome.php build-chrome.php build-error-pages.php build-favicon.php stamp-assets.php \
         cms-annotate.php make-doodles.php make-vanquest-email-art.php optimize-illustrations.php cpanel-cron-setup.mjs \
         package-production.sh; do
  rm -f "$stage/tools/$t"
done
# Internal docs: audits, hand-offs, plans. The operator's guides stay in docs/,
# which the root .htaccess refuses to the web.
keep_docs="configuration.md EMAIL-APPS-SCRIPT.md db-portability.md google-workspace.md meeting-recorder-RUNBOOK.md webhooks.md"
mkdir -p "$stage/.keep-docs"
for d in $keep_docs; do [ -f "$stage/docs/$d" ] && mv "$stage/docs/$d" "$stage/.keep-docs/"; done
rm -rf "$stage/docs" && mv "$stage/.keep-docs" "$stage/docs"
mv "$stage/DEPLOY.md" "$stage/docs/DEPLOY.md"
rm -f "$stage"/*.md

# ── never the server's own files ───────────────────────────────────────────
rm -f "$stage/config.php" "$stage/.env" "$stage/.htpasswd"
bad="$(cd "$stage" && find . \( -name 'config.php' -path './config.php' -o -name '.env' -o -name '.env.*' ! -name '.env.example' \
        -o -name '*.sqlite' -o -name '*.sqlite-*' -o -name '*.rl' -o -name 'donations.json' -o -name 'contacts.json' \) -print)"
if [ -n "$bad" ]; then echo "Refusing to package server-owned files:" >&2; echo "$bad" >&2; exit 1; fi
for must in index.html router.php .htaccess config.example.php vendor/autoload.php vendor/phpmailer/phpmailer/src/PHPMailer.php lib/bootstrap.php db/schema.sql; do
  [ -e "$stage/$must" ] || { echo "Refusing to package: $must is missing" >&2; exit 1; }
done
# Every PHP file must at least parse with the PHP building this.
( cd "$stage" && find . -name '*.php' -not -path './vendor/*' -print0 | xargs -0 -n1 -P4 php -l >/dev/null )

# ── the guide ───────────────────────────────────────────────────────────────
cat > "$stage/docs/INSTALL.md" <<'TXT'
# Installing or updating Afrovanguard on cPanel (no SSH, no Composer)

Every library is already in the package (vendor/). The database creates and
migrates itself on the first request (lib/Database.php, from db/schema*.sql).

## Update an installed site

1. cPanel > File Manager > public_html > Upload this zip > Extract (overwrite).
   It never contains config.php, .env, the SQLite databases or uploads/, so
   none of those is touched.
2. Open the site once; the schema is brought up to date on that request.
   Studio > System shows the database driver and any problem.

## First install

1. cPanel > MultiPHP Manager: PHP 8.2 or newer, with pdo_mysql (or pdo_sqlite),
   mbstring, openssl, curl, gd, zip, intl, sodium.
2. cPanel > MySQL Databases: a database and a user with ALL PRIVILEGES.
3. Upload and extract the zip into public_html. The root `.htaccess` in it is
   the site's: it replaces WordPress's own (WORDPRESS-COEXISTENCE in the
   repository) and refuses lib/, db/, data/, docs/, tools/, vendor/ and every
   dotfile to the web.
4. Settings, ONE of:
   - a `.env` file ABOVE public_html (preferred; /home/<user>/.env), or beside
     index.php (the root .htaccess refuses dotfiles to the web), and/or
   - `config.php` copied from `config.example.php` (secrets only: the database
     settings must be in .env or the environment, see the top of that file).
   Not SetEnv in the root .htaccess: the next package overwrites that file.
   At least: SITE_URL, APP_KEY (32+ random characters), AV_DB_HOST, AV_DB_NAME,
   AV_DB_USER, AV_DB_PASS, AV_DB_STRICT=1, AV_CRON_KEY, the SMTP settings,
   AV_SSO_SECRET (the same value as on CACENTRE). Full list: docs/configuration.md
   and config.example.php.
5. Open https://<site>/health.php: it answers {"ok":true,"db":"mysql"}.
6. Make the first administrator with a one-off cPanel cron line, then delete
   the line, read the log and delete the log (it holds the first password):
   /opt/cpanel/ea-php84/root/usr/bin/php /home/<user>/public_html/db/seed_admin.php >> /home/<user>/seed-admin.log 2>&1
7. cPanel > Cron Jobs, every 5 minutes (reminders, task nudges, webhook
   retries, the daily backup):
   */5 * * * * curl -fsS --max-time 60 -H "X-AV-Cron-Key: <AV_CRON_KEY>" "https://<site>/tasks/cron.php" >/dev/null 2>&1
   tools/cpanel-cron-setup.md has more, including the backup key.
8. Writable by PHP: db/, data/, uploads/ (folders 755, files 644).

DEPLOY.md here covers moving to a container host and the integrations.
TXT
printf 'commit %s\nbuilt %s\n' "$rev" "$(date -u +%Y-%m-%dT%H:%MZ)" > "$stage/docs/BUILD.txt"

find "$stage" -type d -exec chmod 755 {} +
find "$stage" -type f -exec chmod 644 {} +

mkdir -p "$(dirname "$out")"
rm -f "$out"
( cd "$stage" && zip -qr -X "$out" . )
echo "$out ($(du -h "$out" | cut -f1), commit $rev)"
