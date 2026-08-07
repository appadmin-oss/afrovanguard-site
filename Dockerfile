# Afrovanguard site — portable container for any host (GCP Cloud Run / App Engine
# Flex, AWS App Runner / Elastic Beanstalk / ECS / Lightsail, Azure, Render,
# Fly.io, a plain VM, or local Docker). PHP + Apache with the app's .htaccess
# routing intact. All configuration is via environment variables (see DEPLOY.md)
# — no code changes needed to move between hosts.
FROM php:8.2-apache

# Apache: rewrite + headers for the app's .htaccess (routing + CSP).
RUN a2enmod rewrite headers \
 && sed -ri 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf

# PHP extensions the app can use: MySQL + Postgres PDO (SQLite PDO is built in),
# plus gd/zip/intl for images and i18n. curl/openssl/mbstring ship with the image.
RUN apt-get update && apt-get install -y --no-install-recommends \
      libpq-dev libzip-dev libpng-dev libjpeg62-turbo-dev libicu-dev \
 && docker-php-ext-configure gd --with-jpeg \
 && docker-php-ext-install -j"$(nproc)" pdo_mysql mysqli pdo_pgsql gd zip intl \
 && rm -rf /var/lib/apt/lists/*

# Production PHP defaults.
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
 && printf "expose_php=Off\nmemory_limit=256M\nupload_max_filesize=25M\npost_max_size=27M\n" \
      > "$PHP_INI_DIR/conf.d/zz-app.ini"

WORKDIR /var/www/html
COPY . /var/www/html/

# Writable paths: the SQLite dir (default DB) and local uploads fallback. On
# read-only/ephemeral hosts (Cloud Run) point AV_DB_* at a managed DB instead —
# see DEPLOY.md. Never bake secrets into the image; pass them as env vars.
RUN mkdir -p /var/www/html/db /var/www/html/uploads /var/www/html/data \
 && chown -R www-data:www-data /var/www/html/db /var/www/html/uploads /var/www/html/data

# Listen on $PORT (Cloud Run/App Runner inject it; defaults to 8080).
ENV PORT=8080
EXPOSE 8080
HEALTHCHECK --interval=30s --timeout=5s --start-period=20s \
  CMD curl -fsS "http://127.0.0.1:${PORT}/health.php" >/dev/null || exit 1

COPY deploy/docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh
ENTRYPOINT ["docker-entrypoint.sh"]
