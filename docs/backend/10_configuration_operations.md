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

### Temporary Ollama AI Spike Service (Phase 8.8)

Ollama is available as an opt-in Docker Compose profile for local technical experiments. It is deliberately excluded from the normal application stack and is not connected to Laravel or its queue worker. The profile publishes Ollama only on `127.0.0.1` and persists downloaded models in the `tpvs-ollama-data` volume.

Start Ollama from the repository root:

```bash
docker compose --profile ai-spike up -d ollama
docker compose exec ollama ollama pull qwen2.5vl:3b
docker compose exec ollama ollama list
```

The host API is `http://127.0.0.1:11435` by default. This avoids colliding with an independently started Ollama service on port `11434`. Set `OLLAMA_PORT=11434` in the root `.env` only when that port is free. Use the spike runner from the same host:

```bash
python3 tools/ai-spike/run_ollama_spike.py test-images/prueba1.jpeg \
  --url http://127.0.0.1:11435 --model qwen2.5vl:3b
```

`OLLAMA_PORT` in the root `.env` optionally changes the host port; it defaults to `11435`. Do not publish the service to a LAN or public interface. The spike profile only supports local testing; the deployment architecture in phase 8.18 places Ollama on the designated AI host and requires separately planned LAN access controls.

Stop the temporary service while preserving downloaded model data:

```bash
docker compose --profile ai-spike stop ollama
```

Remove the container and its network while keeping the named model volume:

```bash
docker compose --profile ai-spike down
```

To remove the downloaded model data as well, explicitly delete the named volume:

```bash
docker volume rm tpvs_tpvs-ollama-data
```

Ollama/model storage can consume several gigabytes. This temporary profile does not represent production integration: no Laravel provider, queue job, backend URL, AI API endpoint, or production retry configuration is added in phase 8.8.

Root `.env` variables configure the Compose services. `.env.example` contains local-only defaults; set a unique `APP_KEY` and database password before deployment. PostgreSQL persists data in the `tpvs-postgres-data` volume. `docker compose down` keeps that volume; `docker compose down --volumes` deletes it.

For frontend authentication, configure:

| Variable | Purpose |
| --- | --- |
| `FRONTEND_ORIGIN` | Comma-separated trusted frontend origins for the Next.js BFF and SSR, including scheme and port |
| `SANCTUM_STATEFUL_DOMAINS` | Comma-separated frontend host:port values that Laravel Sanctum recognizes as stateful; keep aligned with frontend origins |
| `NEXT_ALLOWED_DEV_ORIGINS` | Hostnames allowed to request Next.js development assets over the LAN; hostnames only, no scheme or port |

The local `.env.example` includes localhost and an example LAN address `192.168.3.240`. Replace that address with the server's actual address on the office LAN in each applicable variable. `NEXT_ALLOWED_DEV_ORIGINS` is for local development only. Recreate the frontend and backend services after changing Compose environment values. Never expose `FRONTEND_ORIGIN` or `LARAVEL_API_URL` through a `NEXT_PUBLIC_*` variable.

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
| `SESSION_SECURE_COOKIE` | Optional Secure-cookie override; defaults to enabled in production deployments, which must use HTTPS |
| `SANCTUM_STATEFUL_DOMAINS` | Browser frontend origins allowed to use Sanctum's stateful session authentication |

`QUEUE_CONNECTION=sync` is suitable only for the current foundation. Operational AI processing requires an asynchronous worker and persistent failed-job handling.

### Asynchronous AI Extraction (SPEC 08 — Planned)

The production integration is configured in Laravel/worker environment only; never pass the Ollama host to Next.js or expose it to browsers. The regular Compose stack does not start Ollama. The existing opt-in `ai-spike` profile remains a local experiment and is not automatically wired into the application provider.

| Variable | Purpose | Initial value / rule |
| --- | --- | --- |
| `AI_OLLAMA_URL` | Ollama host reachable from Laravel and its queue worker. | Required when AI processing is enabled; backend/worker only. |
| `AI_OLLAMA_MODEL` | Vision model sent to Ollama. | `qwen2.5vl:3b`, the candidate selected by SPEC 06; override by environment. |
| `AI_OLLAMA_TIMEOUT` | Provider HTTP request timeout in seconds. | `300` maximum initial value. |
| `DB_QUEUE_RETRY_AFTER` | Database queue visibility timeout in seconds. | `360`, greater than the worker timeout. |

Run the queue worker with a timeout of `330` seconds so the order is provider HTTP timeout < worker timeout < queue `retry_after`. The image-processing job owns the three-call transient retry policy for its initial cycle; Compose must not globally cap jobs at one attempt. Manual retry dispatches are single-call. Keep PostgreSQL and private image storage backups coordinated, and monitor failed jobs plus persisted AIProcessing errors. These values document the planned SPEC 08 runtime and become operational requirements when the integration is implemented.

### Private Record Image Storage (Phase 8.7)

The implemented image configuration uses the `record_images` local private disk rooted at `storage/app/private/record-images`. It is separate from the public disk and does not depend on `FILESYSTEM_DISK`; do not run `storage:link` for the image root.

The image contract accepts JPEG, PNG, and WebP up to 10 MiB. PHP's Docker runtime uses `upload_max_filesize=10M` and `post_max_size=12M` so transport limits do not undercut Laravel validation. Host-based PHP deployments must set the same values in their PHP configuration and verify them with `php -i` or an equivalent runtime check.

Docker Compose currently persists Laravel's complete `backend/storage` directory in `tpvs-backend-storage`; keep that volume for uploaded private images. Backups and recovery must cover both PostgreSQL Image metadata and private image files. Restoring only one side can leave dangling metadata or orphaned files.

## Operational Checks

- Verify `GET /api/health`.
- Verify `GET /up`.
- Verify the `tpvs-queue-worker` service is running when using Docker.
- Run migrations before starting application workers in host-based development; Docker backend startup runs them automatically.
- Persist PostgreSQL data and private uploaded files.
- Keep the private image disk mounted/persisted and included in backup and restore verification.
- Verify effective PHP upload limits allow the documented 10 MiB image limit, with multipart overhead accommodated by `post_max_size=12M`.
- Back up database data, uploaded files, logs, and failed jobs according to deployment requirements.
- Never enable debug output in an operational environment.
- Keep `APP_KEY` stable and secret; Laravel uses it to protect session and CSRF cookies.
- In Docker Compose, Laravel's development server runs with `--no-reload` so it retains environment-injected values such as `APP_KEY` for encrypted cookies.
- Set `SESSION_SECURE_COOKIE=true` when local development is served through HTTPS; keep it enabled in production.
- Authentication uses a browser-session cookie, `SameSite=Lax`, and CSRF validation for state-changing requests. The BFF must preserve the cookie and CSRF headers.
- The dedicated `TestUsersSeeder` is invoked explicitly for local/test use only; it refuses to run in production and is not called by `DatabaseSeeder`.
