#!/bin/sh
# Bind Apache to the platform-provided $PORT (Cloud Run / App Runner set it;
# defaults to 8080), then hand off to Apache. Keeps one image portable across
# hosts that dictate the listen port.
set -e

PORT="${PORT:-8080}"
sed -ri "s/^Listen [0-9]+/Listen ${PORT}/" /etc/apache2/ports.conf || true
sed -ri "s!<VirtualHost \*:[0-9]+>!<VirtualHost *:${PORT}>!" /etc/apache2/sites-available/000-default.conf || true

# Optional: fail fast if a strict primary DB is configured but unreachable.
# (The app otherwise auto-provisions SQLite and migrates on first request.)
exec apache2-foreground
