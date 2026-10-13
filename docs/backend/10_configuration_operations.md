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

Create the local authentication test accounts explicitly, only in a non-production environment:

```bash
php artisan db:seed --class=TestUsersSeeder
```

This creates `editor@test.com` (`EDITOR`) and `viewer@test.com` (`VIEWER`), both with the test-only password `12345678`. The seeder is not called by `DatabaseSeeder` and refuses to run in production. Do not use these credentials operationally.

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
| `SESSION_DRIVER` | Session persistence; use the existing database driver |
| `SESSION_LIFETIME` | Session inactivity timeout; authentication contract uses 120 minutes |
| `SESSION_COOKIE` | Session cookie name; authentication contract uses `tpvs_session` |
| `SESSION_SECURE_COOKIE` | Optional explicit Secure-cookie override; by default enabled when `APP_ENV=production` |
| `SANCTUM_STATEFUL_DOMAINS` | Browser frontend origins allowed to use Sanctum's stateful session authentication |

`QUEUE_CONNECTION=sync` is suitable only for the current foundation. Operational AI processing requires an asynchronous worker and persistent failed-job handling.

## Operational Checks

- Verify `GET /api/health`.
- Verify `GET /up`.
- Verify the `tpvs-queue-worker` service is running when using Docker.
- Run migrations before starting application workers in host-based development; Docker backend startup runs them automatically.
- Persist PostgreSQL data and private uploaded files.
- Back up database data, uploaded files, logs, and failed jobs according to deployment requirements.
- Never enable debug output in an operational environment.
- Keep `APP_KEY` stable and secret; Laravel uses it to protect session and CSRF cookies.
- Authentication uses a browser-session cookie, `SameSite=Lax`, and CSRF validation for state-changing requests. The BFF must preserve the cookie and CSRF headers.
- The dedicated `TestUsersSeeder` is invoked explicitly for local/test use only; it refuses to run in production and is not called by `DatabaseSeeder`.
