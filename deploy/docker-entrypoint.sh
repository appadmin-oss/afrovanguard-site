#!/bin/sh
# Bind Apache to the platform-provided $PORT (Cloud Run / App Runner set it;
# defaults to 8080), then hand off to Apache. Keeps one image portable across
# hosts that dictate the listen port.
set -e

PORT="${PORT:-8080}"
sed -ri "s/^Listen [0-9]+/Listen ${PORT}/" /etc/apache2/ports.conf || true
sed -ri "s!<VirtualHost \*:[0-9]+>!<VirtualHost *:${PORT}>!" /etc/apache2/sites-available/000-default.conf || true

# The signing key is required. A placeholder signs every session and CSRF
# token with a value printed in the repository.
case "${APP_KEY:-}" in
  ""|change-me*|*change-me-32*) echo "APP_KEY is not set (or is the placeholder). Set 32+ random characters." >&2; exit 1 ;;
esac
if [ "${#APP_KEY}" -lt 32 ]; then echo "APP_KEY must be at least 32 characters." >&2; exit 1; fi

# Moving off the old whole-db/ volume: copy its databases to the data volume
# once, never over a newer copy.
for f in diary.sqlite ngv.sqlite; do
  src="/var/www/html/db-legacy/$f"; dst="$(dirname "${AV_DB_PATH:-/var/lib/afrovanguard/diary.sqlite}")/$f"
  if [ -f "$src" ] && [ ! -f "$dst" ]; then cp -p "$src" "$dst" && chown www-data:www-data "$dst" && echo "copied $f from the old db volume"; fi
done
if [ -d /var/www/html/db-legacy/private ]; then cp -rpn /var/www/html/db-legacy/private/. /var/www/html/db/private/ 2>/dev/null || true; fi

# Optional: fail fast if a strict primary DB is configured but unreachable.
# (The app otherwise auto-provisions SQLite and migrates on first request.)
exec apache2-foreground
