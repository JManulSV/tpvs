# Configuration and Operations

## Local Setup

From `backend/`:

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan serve
```

Run tests with:

```bash
composer run test
```

## Docker Setup

From the repository root:

```bash
docker compose up --build
```

For Docker, `DB_HOST` must match the Compose service name: `tpvs-postgres`. For host-based development, use `127.0.0.1` or `localhost` instead.

## Important Variables

| Variable | Purpose |
| --- | --- |
| `APP_KEY` | Encryption key; required and secret |
| `APP_DEBUG` | Detailed errors; disable outside local development |
| `DB_*` | PostgreSQL connection |
| `QUEUE_CONNECTION` | Queue execution mode |
| `CACHE_STORE` | Cache backend |
| `FILESYSTEM_DISK` | Application file storage |

`QUEUE_CONNECTION=sync` is suitable only for the current foundation. Operational AI processing requires an asynchronous worker and persistent failed-job handling.

## Operational Checks

- Verify `GET /api/health`.
- Verify `GET /up`.
- Run migrations before starting application workers.
- Persist PostgreSQL data and private uploaded files.
- Back up database data, uploaded files, logs, and failed jobs according to deployment requirements.
- Never enable debug output in an operational environment.
