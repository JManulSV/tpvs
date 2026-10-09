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
cp .env.example .env
docker compose up --build
```

Compose starts PostgreSQL, Laravel, the database queue worker, and Next.js. PostgreSQL must pass its health check before Laravel starts. Laravel creates runtime storage directories and runs migrations on startup; the queue worker starts after Laravel is healthy.

Root `.env` variables configure the Compose services. `.env.example` contains local-only defaults; set a unique `APP_KEY` and database password before deployment. PostgreSQL persists data in the `tpvs-postgres-data` volume. `docker compose down` keeps that volume; `docker compose down --volumes` deletes it.

For host-based Laravel development, copy `backend/.env.example` to `backend/.env`, generate `APP_KEY` with `php artisan key:generate`, use `DB_HOST=127.0.0.1`, and run migrations manually. If using the database queue driver, start a worker with `php artisan queue:work database` after migrations.

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
- Verify the `tpvs-queue-worker` service is running when using Docker.
- Run migrations before starting application workers in host-based development; Docker backend startup runs them automatically.
- Persist PostgreSQL data and private uploaded files.
- Back up database data, uploaded files, logs, and failed jobs according to deployment requirements.
- Never enable debug output in an operational environment.
