#!/bin/sh
# Development entrypoint for tpvs-frontend. Runs as the node user.
set -eu

cd /app

for dir in node_modules .next; do
    if [ ! -w "$dir" ]; then
        echo "tpvs-entrypoint: ERROR /app/$dir is not writable by UID $(id -u)." >&2
        echo "It is a cache volume created by an older root image. Recreate it with:" >&2
        echo "  docker compose rm -sf tpvs-frontend" >&2
        echo "  docker volume rm tpvs_tpvs-frontend-node-modules tpvs_tpvs-frontend-next" >&2
        echo "  docker compose up -d tpvs-frontend" >&2
        exit 1
    fi
done

# Reinstall node_modules when package-lock.json changes.
lock_hash=$(sha256sum package-lock.json | cut -d ' ' -f 1)
stamp=node_modules/.package-lock.sha256
if [ ! -f "$stamp" ] || [ "$(cat "$stamp")" != "$lock_hash" ]; then
    echo "tpvs-entrypoint: package-lock.json changed, running npm ci"
    npm ci --no-audit --no-fund
    echo "$lock_hash" > "$stamp"
fi

exec "$@"
