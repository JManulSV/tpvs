#!/bin/sh
# Production entrypoint for tpvs-migrate, tpvs-backend and tpvs-queue-worker.
set -eu

cd /var/www/html

# storage is a named volume; a new one starts empty.
mkdir -p \
    storage/app/private/record-images \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs

# Cache config, routes, events and views from the container's environment.
php artisan optimize

exec "$@"
