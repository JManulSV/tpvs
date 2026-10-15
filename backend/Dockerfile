FROM php:8.3-cli

RUN apt-get update \
    && apt-get install -y --no-install-recommends libpq-dev unzip git curl \
    && docker-php-ext-install pdo_pgsql \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY docker/php/uploads.ini /usr/local/etc/php/conf.d/uploads.ini

WORKDIR /var/www/html

COPY composer.json composer.lock ./
RUN composer install --no-interaction --prefer-dist --no-progress --no-scripts

COPY . .

RUN cp .env.example .env \
    && composer dump-autoload --optimize --no-interaction

COPY docker-entrypoint.sh /usr/local/bin/tpvs-entrypoint
RUN chmod +x /usr/local/bin/tpvs-entrypoint

ENTRYPOINT ["tpvs-entrypoint"]

EXPOSE 8000

CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
