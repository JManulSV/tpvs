# Targets:
#   base  PHP with the extensions and Composer, www-data remapped to UID/GID.
#   dev   Development: code is bind-mounted, vendor is synced by the entrypoint.
#   prod  Production: code and vendor baked in, php-fpm on :9000.
#   web   Production nginx: :80 -> Next.js, :8080 (internal) -> php-fpm.
#
# The php:8.3-fpm image also ships the php CLI, so dev runs `artisan serve`
# and prod runs php-fpm from the same base.

FROM php:8.3-fpm AS base

# Remap www-data to the host user so files written to bind mounts and volumes
# belong to that user instead of root.
ARG UID=1000
ARG GID=1000

RUN apt-get update \
    && apt-get install -y --no-install-recommends libpq-dev unzip git curl \
        libzip-dev libpng-dev libjpeg-dev libwebp-dev libicu-dev \
    && docker-php-ext-configure gd --with-jpeg --with-webp \
    && docker-php-ext-install pdo_pgsql zip gd pcntl intl bcmath opcache \
    && rm -rf /var/lib/apt/lists/* \
    && groupmod --non-unique --gid "${GID}" www-data \
    && usermod --non-unique --uid "${UID}" --gid "${GID}" www-data \
    && chown www-data:www-data /var/www

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY docker/php/uploads.ini /usr/local/etc/php/conf.d/uploads.ini

ENV COMPOSER_HOME=/tmp/composer

WORKDIR /var/www/html


FROM base AS dev

RUN apt-get update \
    && apt-get install -y --no-install-recommends gosu \
    && rm -rf /var/lib/apt/lists/* \
    && mkdir -p /var/www/html/vendor /var/www/html/storage \
    && chown -R www-data:www-data /var/www/html

COPY docker/entrypoint-dev.sh /usr/local/bin/tpvs-entrypoint

USER www-data

# php:8.3-fpm sets STOPSIGNAL SIGQUIT for php-fpm. artisan serve and the queue
# worker shut down gracefully on SIGTERM, so dev restores the usual signal.
STOPSIGNAL SIGTERM

ENTRYPOINT ["tpvs-entrypoint"]

EXPOSE 8000

CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000", "--no-reload"]


FROM base AS prod

ENV APP_ENV=production \
    APP_DEBUG=false

RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

COPY docker/php/opcache-prod.ini /usr/local/etc/php/conf.d/opcache-prod.ini

# Dependencies first, so this layer stays cached until composer.lock changes.
COPY --chown=www-data:www-data composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist --no-progress \
        --no-scripts --no-autoloader \
    && rm -rf "$COMPOSER_HOME"

COPY --chown=www-data:www-data . .

RUN composer dump-autoload --optimize --no-dev --no-interaction \
    && rm -rf "$COMPOSER_HOME" \
    && mkdir -p storage bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

COPY docker/entrypoint-prod.sh /usr/local/bin/tpvs-entrypoint

USER www-data

# php-fpm finishes in-flight requests on SIGQUIT (inherited from php:8.3-fpm),
# and queue:work handles SIGQUIT like SIGTERM.

ENTRYPOINT ["tpvs-entrypoint"]

EXPOSE 9000

CMD ["php-fpm"]


FROM nginx:alpine AS web

COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf

# nginx serves static files from public/ and sends PHP to php-fpm, which
# resolves SCRIPT_FILENAME against the same path inside tpvs-backend.
COPY --from=prod /var/www/html/public /var/www/html/public

EXPOSE 80 8080
