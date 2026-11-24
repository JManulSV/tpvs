# Targets:
#   base  PHP with the extensions and Composer shared by every target.
#   dev   Development: code is bind-mounted, vendor is synced by the entrypoint.
#   prod  (SPEC 26 step 11)
#   web   (SPEC 26 step 11)
#
# The php:8.3-fpm image also ships the php CLI, so dev runs `artisan serve`
# and prod will run php-fpm from the same base.

FROM php:8.3-fpm AS base

RUN apt-get update \
    && apt-get install -y --no-install-recommends libpq-dev unzip git curl \
        libzip-dev libpng-dev libjpeg-dev libwebp-dev libicu-dev \
    && docker-php-ext-configure gd --with-jpeg --with-webp \
    && docker-php-ext-install pdo_pgsql zip gd pcntl intl bcmath \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY docker/php/uploads.ini /usr/local/etc/php/conf.d/uploads.ini

WORKDIR /var/www/html


FROM base AS dev

# Remap www-data to the host user so files written to the bind mount
# belong to that user instead of root.
ARG UID=1000
ARG GID=1000

RUN apt-get update \
    && apt-get install -y --no-install-recommends gosu \
    && rm -rf /var/lib/apt/lists/* \
    && groupmod --non-unique --gid "${GID}" www-data \
    && usermod --non-unique --uid "${UID}" --gid "${GID}" www-data \
    && mkdir -p /var/www/html/vendor /var/www/html/storage \
    && chown -R www-data:www-data /var/www/html

ENV COMPOSER_HOME=/tmp/composer

COPY docker/entrypoint-dev.sh /usr/local/bin/tpvs-entrypoint

USER www-data

ENTRYPOINT ["tpvs-entrypoint"]

EXPOSE 8000

CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000", "--no-reload"]
