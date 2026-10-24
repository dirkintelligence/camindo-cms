# camindo CMS core (camindo/cms) for Anthropic's OSS Scanner.
#
# /src           the core checkout (the repository named in project.yaml)
# /opt/camindo   a project built from the skeleton in install/draft (= camindo/project), installed
#                with `camindo.php install`; /opt/camindo/Core is a copy of /src (`camindo-sync`
#                copies /src there again after a patch)
#
# Apache serves /opt/camindo/public with .htaccess enabled (AllowOverride All), as the
# installation guide asks. The database is MariaDB inside the image. Start both with
# `camindo-start`; the site listens on http://127.0.0.1/ (backend: /backend/index.php).
FROM php:8.4-apache-bookworm

ENV DEBIAN_FRONTEND=noninteractive \
    COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_NO_INTERACTION=1 \
    COMPOSER_MEMORY_LIMIT=-1

RUN apt-get update && apt-get install -y --no-install-recommends \
      ca-certificates git unzip curl procps tzdata mariadb-server mariadb-client \
      libfreetype6-dev libjpeg62-turbo-dev libpng-dev libwebp-dev libicu-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" gd intl mysqli \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

# Production-like PHP: errors are logged, not shown; OPcache picks up edited files immediately
# so a patch in /src can be checked straight away.
RUN { echo 'memory_limit=256M'; \
      echo 'error_reporting=E_ALL'; \
      echo 'display_errors=Off'; \
      echo 'log_errors=On'; \
      echo 'error_log=/dev/stderr'; \
      echo 'opcache.validate_timestamps=1'; \
      echo 'opcache.revalidate_freq=0'; \
    } > /usr/local/etc/php/conf.d/oss-scanner.ini

RUN a2enmod rewrite headers expires \
    && sed -ri 's!/var/www/html!/opt/camindo/public!g' /etc/apache2/sites-available/000-default.conf \
    && printf '<Directory /opt/camindo/public>\n  Options -MultiViews +FollowSymLinks\n  AllowOverride All\n  Require all granted\n</Directory>\nServerName 127.0.0.1\n' \
         > /etc/apache2/conf-available/camindo.conf \
    && a2enconf camindo

# camindo-start starts MariaDB and Apache (there is no init system in the container). Safe to run again.
RUN printf '%s\n' '#!/bin/sh' 'set -e' \
      'mkdir -p /run/mysqld && chown mysql:mysql /run/mysqld' \
      'mariadb-admin ping --silent 2>/dev/null || (mysqld_safe >/dev/null 2>&1 &)' \
      'until mariadb-admin ping --silent 2>/dev/null; do sleep 0.5; done' \
      'pgrep -x apache2 >/dev/null || apachectl start' \
      > /usr/local/bin/camindo-start \
    && printf '%s\n' '#!/bin/sh' 'apachectl stop 2>/dev/null; mariadb-admin shutdown 2>/dev/null; true' \
      > /usr/local/bin/camindo-stop \
    && chmod +x /usr/local/bin/camindo-start /usr/local/bin/camindo-stop

# scanner contract: the core checkout lives inside the image, at /src
COPY . /src
WORKDIR /src

# Project from the skeleton, the core from /src as a path repository (like
# .github/ci/install-test.sh). The core resolves its paths from its own location, so it
# is copied, not symlinked. The composer plugin comes from Packagist.
RUN cp -R /src/install/draft /opt/camindo \
    && rm -f /opt/camindo/composer-dev.json \
    && cd /opt/camindo \
    && version=$(sed -n "s/^define('CAMINDO_VERSION', '\(.*\)');/\1/p" /src/Core.php) \
    && composer config repositories.core "{\"type\": \"path\", \"url\": \"/src\", \"options\": {\"symlink\": false, \"versions\": {\"camindo/cms\": \"$version\"}}}" \
    && composer install --no-progress

# Install on MariaDB (with the time zone tables camindo needs), then add a test instance and a restricted backend user:
#   admin  / Scanner-Admin-2026!x    superuser (group 1), fully trusted
#   editor / Scanner-Editor-2026!x   group "Editor": module Record only, instance "Scanner"
#   (anonymous)                      no login
# The passwords exist only in this throwaway image.
ENV CAMINDO_DB_HOST=127.0.0.1 CAMINDO_DB_PORT=3306 CAMINDO_DB_NAME=camindo CAMINDO_DB_USER=camindo \
    CAMINDO_ADMIN_NAME=admin CAMINDO_ADMIN_EMAIL=admin@example.com CAMINDO_ADMIN_LANG=en_US \
    CAMINDO_TIMEZONE=Europe/Berlin CAMINDO_LOCALE=en_US CAMINDO_HTTPS=n CAMINDO_BACKEND_DOMAIN= \
    CAMINDO_WEB_USER=www-data
RUN camindo-start \
    && mariadb-tzinfo-to-sql /usr/share/zoneinfo 2>/dev/null | mariadb mysql \
    && mariadb -e "CREATE DATABASE camindo CHARACTER SET utf8mb4; \
         CREATE USER 'camindo'@'%' IDENTIFIED BY 'camindo'; CREATE USER 'camindo'@'localhost' IDENTIFIED BY 'camindo'; \
         GRANT ALL ON camindo.* TO 'camindo'@'%'; GRANT ALL ON camindo.* TO 'camindo'@'localhost';" \
    && cd /opt/camindo \
    && printf camindo > /tmp/dbpass \
    && CAMINDO_ADMIN_PASS='Scanner-Admin-2026!x' php Core/cron/camindo.php install --no-interaction --db-pass-file=/tmp/dbpass \
    && rm /tmp/dbpass \
    && php /src/.oss-scanner/setup.php \
    && php Core/cron/camindo.php cache all \
    && chown -R www-data:www-data /opt/camindo/var /opt/camindo/public \
    && camindo-stop

# camindo runs its CLI as www-data, the user Apache runs as, so var/ stays writable by both.
# camindo-sync copies /src to /opt/camindo/Core again (offline: path repository).
RUN printf '#!/bin/sh\ncd /opt/camindo && exec setpriv --reuid=www-data --regid=www-data --init-groups env HOME=/tmp php Core/cron/camindo.php "$@"\n' \
      > /usr/local/bin/camindo \
    && printf '#!/bin/sh\nset -e\ncd /opt/camindo && composer reinstall --no-progress camindo/cms && chown -R www-data:www-data var public\n' \
      > /usr/local/bin/camindo-sync \
    && chmod +x /usr/local/bin/camindo /usr/local/bin/camindo-sync

# Smoke test: the backend answers, the self-tests run.
# A failing self-test does not abort the build.
RUN camindo-start \
    && curl -fsS -o /dev/null http://127.0.0.1/backend/index.php \
    && camindo status \
    && (cd /opt/camindo && for t in check_dateparse check_template_params check_nested_select check_tree_positions \
          check_batch_tree check_include_cache check_webauthn check_record_files check_field_trash check_template_trash; do \
          setpriv --reuid=www-data --regid=www-data --init-groups env HOME=/tmp php Core/cron/$t.php \
            || echo "WARNING: self-test $t reported failures"; done) \
    && camindo-stop

WORKDIR /src
