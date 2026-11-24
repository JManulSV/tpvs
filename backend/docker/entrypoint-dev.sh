#!/bin/sh
# Development entrypoint for tpvs-migrate, tpvs-backend and tpvs-queue-worker.
set -eu

cd /var/www/html

# Only tpvs-migrate starts as root (see compose.override.yaml). It fixes the
# ownership of named volumes written by older root images, then drops to www-data.
if [ "$(id -u)" = "0" ]; then
    for dir in vendor storage bootstrap/cache; do
        mkdir -p "$dir"
        if [ -n "$(find "$dir" ! -user www-data -print -quit)" ]; then
            echo "tpvs-entrypoint: fixing ownership of $dir"
            chown -R www-data:www-data "$dir"
        fi
    done
    exec gosu www-data "$0" "$@"
fi

mkdir -p \
    bootstrap/cache \
    storage/app/private/record-images \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/testing \
    storage/framework/views \
    storage/logs

if [ -f .env ]; then
    echo "tpvs-entrypoint: WARNING backend/.env exists. Under Docker all configuration" \
        "comes from the root .env and Compose overrides these values." \
        "Keep backend/.env only to run Laravel outside Docker." >&2
fi

# Reinstall vendor when composer.lock changes. The lock serializes containers
# that start at the same time and share the vendor volume.
lock_hash=$(sha256sum composer.lock | cut -d ' ' -f 1)
stamp=vendor/.composer-lock.sha256
(
    flock 9
    if [ ! -f "$stamp" ] || [ "$(cat "$stamp")" != "$lock_hash" ]; then
        echo "tpvs-entrypoint: composer.lock changed, running composer install"
        composer install --no-interaction --prefer-dist --no-progress
        echo "$lock_hash" > "$stamp"
    fi
) 9> vendor/.sync.lock

exec "$@"
