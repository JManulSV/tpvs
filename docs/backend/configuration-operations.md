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

The suite aborts unless the connected database is `tpvs_test` (guard in `tests/TestCase.php`). Under Docker use `make test`; see [`testing.md`](testing.md).

Create the local authentication test accounts explicitly, only in a non-production environment:

```bash
php artisan db:seed --class=TestUsersSeeder
```

This creates `editor@test.com` (`EDITOR`) and `viewer@test.com` (`VIEWER`), both with the test-only password `12345678`. The seeder is not called by `DatabaseSeeder` and refuses to run in production. Do not use these credentials operationally.

## Docker Setup (SPEC 26)

Docker Compose 2.24.4 or newer is required (`depends_on.required: false` needs 2.20, `!override` needs 2.24.4). Under Docker, the root `.env` is the only configuration source: Compose defines every variable Laravel reads, so `backend/.env` contributes nothing and the backend entrypoint prints a warning when it exists. `backend/.env.example` is only for running Laravel outside Docker.

### Files

| File | Purpose |
| --- | --- |
| `compose.yaml` | Shared base: services, networks, volumes, healthchecks, and the `x-laravel-env` anchor with every Laravel runtime variable. Project name `tpvs`. |
| `compose.override.yaml` | Development. Loaded automatically by `docker compose`: bind mounts, published ports, `dev` build targets, dev commands. |
| `compose.prod.yaml` | Application server: `docker compose -f compose.yaml -f compose.prod.yaml …`. |
| `compose.ai-host.yaml` | AI PC, standalone: `docker compose -f compose.ai-host.yaml …`. |
| `Makefile` | Shortcuts for the commands below. `make help` lists them. |
| `backend/Dockerfile` | Targets `base`, `dev`, `prod` (php-fpm) and `web` (nginx). |
| `frontend/Dockerfile` | Targets `deps`, `dev`, `build` and `prod` (Next.js `standalone`). |
| `backend/docker/entrypoint-dev.sh`, `frontend/docker/entrypoint-dev.sh` | Development entrypoints: storage directories, ownership fix, lockfile-based dependency sync. |
| `backend/docker/queue-listen.sh` | Development worker wrapper with graceful shutdown. |
| `backend/docker/entrypoint-prod.sh` | Production entrypoint: storage directories and `php artisan optimize`. |
| `backend/docker/nginx/default.conf` | Production nginx: `:80` → Next.js, `:8080` (internal) → php-fpm. |
| `docker/postgres/init/01-create-test-db.sh` | Creates `tpvs_test` the first time PostgreSQL starts with an empty volume. |

### Services

| Service | Development | Production | Networks |
| --- | --- | --- | --- |
| `tpvs-postgres` | `127.0.0.1:5432` | no port | `data` |
| `tpvs-migrate` | one-shot `migrate --force`, `dev` target | same, `prod` target | `data` |
| `tpvs-backend` | `artisan serve` with `PHP_CLI_SERVER_WORKERS=4`, `127.0.0.1:8000` | php-fpm `:9000`, no port | `edge`, `data`, `ai` |
| `tpvs-queue-worker` | `queue:listen database --timeout=330` | `queue:work database --timeout=330 --max-time=3600` | `data`, `ai` |
| `tpvs-frontend` | `next dev`, `0.0.0.0:3000` (LAN) | `node server.js`, no port | `edge` |
| `tpvs-web` | — | nginx, `0.0.0.0:80`, the only published port | `edge` |
| `tpvs-ollama` | profile `ai`, `127.0.0.1:${OLLAMA_PORT:-11435}` | not used (AI PC) | `ai` |
| `tpvs-ollama-pull` | profile `ai`, one-shot `ollama pull` | not used | `ai` |

Startup order: PostgreSQL healthy → `tpvs-migrate` exits `0` → backend and worker start. Migrations no longer run in the backend entrypoint; a failed migration keeps backend and worker stopped and shows its error in `docker compose logs tpvs-migrate`. With the `ai` profile, the worker also waits for `tpvs-ollama-pull` (`required: false`, so without the profile the stack starts the same). Long-running services use `restart: unless-stopped`.

Networks isolate traffic: `edge` (frontend ↔ backend, nginx), `data` (backend and worker ↔ PostgreSQL), `ai` (backend and worker ↔ Ollama). The frontend cannot resolve `tpvs-postgres` or `tpvs-ollama`.

Volume keys are unchanged (`tpvs-postgres-data`, `tpvs-backend-storage`, `tpvs-backend-vendor`, `tpvs-frontend-node-modules`, `tpvs-frontend-next`, `tpvs-ollama-data`); with `name: tpvs` the real names stay `tpvs_<key>`. `docker compose down` keeps them; `docker compose down --volumes` deletes them, including the database.

### Development

```bash
cp .env.example .env    # then set a unique APP_KEY (see below) and UID/GID (`id -u`, `id -g`)
make up                 # docker compose up -d --build
```

Without `make`, use the equivalent commands in [make Commands](#make-commands). `APP_KEY` is required: `docker compose config` fails with `APP_KEY is required` when it is missing. Compose cannot start without it, so generate it on the host with `echo "base64:$(openssl rand -base64 32)"` and paste the line into `.env` as `APP_KEY=…`.

- **Ports.** PostgreSQL (5432) and Laravel (8000) bind to `127.0.0.1` only. Next.js (3000) stays open to the LAN. For LAN access set `FRONTEND_ORIGIN`, `SANCTUM_STATEFUL_DOMAINS` and `NEXT_ALLOWED_DEV_ORIGINS` in `.env` (Compose has no LAN-IP fallback).
- **Users.** Containers run as `www-data` (backend) and `node` (frontend), remapped at build time to `UID`/`GID` from `.env`, so files written to the bind mounts belong to the host user. `tpvs-migrate` alone starts as root to fix the ownership of named volumes left by older root images, then drops to `www-data`. If the frontend reports that `node_modules` or `.next` is not writable, recreate those cache volumes as the error message explains.
- **Dependencies.** The entrypoints compare the SHA-256 of `composer.lock` with `vendor/.composer-lock.sha256`, and of `package-lock.json` with `node_modules/.package-lock.sha256`. When they differ, they run `composer install` or `npm ci`. Restarting the service is enough; `--build` is not needed.
- **Worker.** `queue:listen` starts a fresh PHP process per job, so a change in `backend/app` applies to the next job without restarting. `backend/docker/queue-listen.sh` handles the stop signal: it freezes the listener, waits for the job in progress, and exits; an idle worker stops in about two seconds. `stop_grace_period: 340s` is the upper bound before Docker kills it. The `dev` target sets `STOPSIGNAL SIGTERM` because `php:8.3-fpm` defaults to `SIGQUIT`.

### make Commands

`make` is optional (`sudo apt install make` on Debian/Ubuntu). Every target is a plain `docker compose` command; the last column is what to run without `make`.

| Command | Action | Equivalent without `make` |
| --- | --- | --- |
| `make up` | Build and start the stack, without Ollama | `docker compose up -d --build` |
| `make up-ai` | Build and start the stack with the `ai` profile | `docker compose --profile ai up -d --build` |
| `make down` | Stop and remove the containers; volumes are kept | `docker compose --profile ai down` |
| `make ps` | All containers, including finished one-shot services | `docker compose --profile ai ps -a` |
| `make logs s=<service>` | Follow logs (`tpvs-backend` by default) | `docker compose logs -f --tail=100 <service>` |
| `make shell s=<service>` | Shell in a service (`tpvs-backend` by default) | `docker compose exec <service> sh` |
| `make test ARGS="…"` | Backend suite, always against `tpvs_test`; creates it first if missing | `docker compose exec -T tpvs-postgres sh /docker-entrypoint-initdb.d/01-create-test-db.sh`<br>`docker compose exec -T -e DB_DATABASE=tpvs_test tpvs-backend php artisan test` |
| `make pint ARGS="…"` | Pint in the backend container | `docker compose exec -T tpvs-backend vendor/bin/pint …` |
| `make migrate` | Run `tpvs-migrate` once | `docker compose run --rm tpvs-migrate` |
| `make ai-pull` | Download `AI_OLLAMA_MODEL` (profile `ai`) | `docker compose --profile ai run --rm tpvs-ollama-pull` |
| `make prod-config` | Validate and print the merged production configuration | `docker compose -f compose.yaml -f compose.prod.yaml config` |

Without `make`, never drop `-e DB_DATABASE=tpvs_test` from the test command (see [`testing.md`](testing.md)). The first `test` line only creates `tpvs_test` when it is missing; skip it once the database exists.

Keep this table in sync with the root `Makefile`.

### Ollama in Development (profile `ai`)

```bash
make up-ai              # docker compose --profile ai up -d --build
```

`tpvs-ollama` uses the pinned image `ollama/ollama:${OLLAMA_IMAGE_TAG}` (never `latest`), `OLLAMA_NUM_PARALLEL=1`, `OLLAMA_MAX_LOADED_MODELS=1`, `OLLAMA_KEEP_ALIVE=30m`, `mem_limit: 8g`, and a `ollama list` healthcheck. `tpvs-ollama-pull` downloads `AI_OLLAMA_MODEL` once Ollama is healthy; it finishes in seconds when the model is already in `tpvs-ollama-data`. Laravel and the worker reach it at `AI_OLLAMA_URL=http://tpvs-ollama:11434`. Without the profile, Ollama does not start and image extraction waits in the queue.

The host port `127.0.0.1:${OLLAMA_PORT:-11435}` is for local tools such as the SPEC 06 benchmark runner:

```bash
python3 tools/ai-spike/run_ollama_spike.py test-images/prueba1.jpeg \
  --url http://127.0.0.1:11435 --model qwen2.5vl:3b
```

To update Ollama, change `OLLAMA_IMAGE_TAG` deliberately and test an extraction. To free the model storage (several gigabytes), stop the profile and run `docker volume rm tpvs_tpvs-ollama-data`.

### Production (application server)

```bash
make prod-config        # validate first
docker compose -f compose.yaml -f compose.prod.yaml up -d --build
```

- Images are named `tpvs-backend:prod`, `tpvs-frontend:prod` and `tpvs-web:prod`, so they never overwrite the development builds.
- Code is baked into the images; there are no bind mounts. The backend runs `composer install --no-dev`, `php.ini-production`, OPcache with `validate_timestamps=0` (rebuild the image to deploy new code), and `php artisan optimize` on start.
- `APP_ENV=production` and `APP_DEBUG=false` are fixed in `compose.prod.yaml`; no `.env` value can enable debug mode. `AI_OLLAMA_URL` is required (`http://<ai-pc-ip>:11434`).
- `tpvs-web` (nginx) is the only entry point and the only published port (80): `:80` proxies to Next.js, and `:8080`, internal only, serves Laravel through php-fpm. The BFF uses `LARAVEL_API_URL=http://tpvs-web:8080`. HTTPS will be added in nginx with the LAN deployment (roadmap 8.18).
- The worker runs `queue:work --max-time=3600` and restarts every hour through `restart: unless-stopped`.
- **Login does not work over plain HTTP in production**: the session cookie is `Secure` (`SESSION_SECURE_COOKIE`). Until HTTPS arrives, verify only the login page, `/up`, and the healthchecks.
- Production and development share the project name and volumes: stop one before starting the other on the same machine.

### AI PC

```bash
docker compose -f compose.ai-host.yaml up -d
curl http://<ai-pc-ip>:11434/api/tags
```

The file is standalone (project `tpvs-ai`) and runs only Ollama on CPU plus the one-shot model download. It publishes `${OLLAMA_BIND:-0.0.0.0}:11434`. On the application server set `AI_OLLAMA_URL=http://<ai-pc-ip>:11434`.

**Firewall (required).** Ollama has no authentication. On the AI PC, allow inbound TCP 11434 only from the application server's IP and deny it for every other host, and set `OLLAMA_BIND` to the AI PC's LAN IP rather than `0.0.0.0`. Docker-published ports bypass some host firewalls (for example UFW); filter in the `DOCKER-USER` iptables chain or at the network level. The firewall rules are documented here, not automated.

Root `.env` variables configure the Compose services. `.env.example` contains local-only defaults; set a unique `APP_KEY` and database password before deployment.

For frontend authentication, configure:

| Variable | Purpose |
| --- | --- |
| `FRONTEND_ORIGIN` | Comma-separated trusted frontend origins for the Next.js BFF and SSR, including scheme and port |
| `SANCTUM_STATEFUL_DOMAINS` | Comma-separated frontend host:port values that Laravel Sanctum recognizes as stateful; keep aligned with frontend origins |
| `NEXT_ALLOWED_DEV_ORIGINS` | Hostnames allowed to request Next.js development assets over the LAN; hostnames only, no scheme or port |

The local `.env.example` includes localhost and an example LAN address `192.168.3.240`; Compose itself has no LAN-address fallback. Replace that address with the server's actual address on the office LAN in each applicable variable. `NEXT_ALLOWED_DEV_ORIGINS` is for local development only. Recreate the frontend and backend services after changing Compose environment values. Never expose `FRONTEND_ORIGIN` or `LARAVEL_API_URL` through a `NEXT_PUBLIC_*` variable.

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

The implemented AI pipeline requires the asynchronous database queue and persistent failed-job handling. Do not use `QUEUE_CONNECTION=sync` for image extraction.

### Asynchronous AI Extraction (SPEC 08)

The production integration is configured in Laravel/worker environment only; never pass the Ollama host to Next.js or expose it to browsers. In development the optional `ai` profile starts `tpvs-ollama` on the `ai` network; in production Ollama runs on the AI PC (see [Docker Setup](#docker-setup-spec-26)).

| Variable | Purpose | Initial value / rule |
| --- | --- | --- |
| `AI_OLLAMA_URL` | Ollama host reachable from Laravel and its queue worker. | `http://tpvs-ollama:11434` in development; `http://<ai-pc-ip>:11434` in production (required). Backend/worker only. |
| `AI_OLLAMA_MODEL` | Vision model sent to Ollama. | `qwen2.5vl:3b`, the candidate selected by SPEC 06; override by environment. |
| `AI_OLLAMA_TIMEOUT` | Provider HTTP request timeout in seconds. | `300` maximum initial value. |
| `DB_QUEUE_RETRY_AFTER` | Database queue visibility timeout in seconds. | `360`, greater than the worker timeout. |

Run the queue worker with a timeout of `330` seconds so the order is provider HTTP timeout < worker timeout < queue `retry_after`. The image-processing job owns the three-call transient retry policy for its initial cycle; Compose must not globally cap jobs at one attempt. Manual retry dispatches are single-call. The PHP image includes `pcntl`, which `queue:work` needs to enforce `--timeout` and to finish the current job on `SIGTERM`. Keep PostgreSQL and private image storage backups coordinated, and monitor failed jobs plus persisted AIProcessing errors. Recreate the backend and worker containers after changing these variables.

### Private Record Image Storage (SPEC 05)

The implemented image configuration uses the `record_images` local private disk rooted at `storage/app/private/record-images`. It is separate from the public disk and does not depend on `FILESYSTEM_DISK`; do not run `storage:link` for the image root.

The image contract accepts JPEG, PNG, and WebP up to 10 MiB. PHP's Docker runtime uses `upload_max_filesize=10M` and `post_max_size=12M` so transport limits do not undercut Laravel validation. Host-based PHP deployments must set the same values in their PHP configuration and verify them with `php -i` or an equivalent runtime check.

Docker Compose currently persists Laravel's complete `backend/storage` directory in `tpvs-backend-storage`; keep that volume for uploaded private images. Backups and recovery must cover both PostgreSQL Image metadata and private image files. Restoring only one side can leave dangling metadata or orphaned files.

### Word Delivery Documents (SPEC 12)

Word generation uses `phpoffice/phpword`. The backend PHP runtime requires the `zip` extension to write `.docx` files and the `gd` extension, built with JPEG, PNG, and WebP support, to read image dimensions and convert WebP images to PNG. The Docker image installs both; host-based PHP deployments must install them and verify with `php -m`.

Generated documents are written to the system temporary directory and deleted after the response is sent. They are not stored and need no backup.

### Excel Reports (SPEC 13)

Excel generation uses `phpoffice/phpspreadsheet`. It needs the `zip` extension to write `.xlsx` files and no extension beyond those already installed for Word documents. Workbooks are written to the system temporary directory and deleted after the response is sent. They are not stored and need no backup.

## Operational Checks

- Verify `GET /api/health`.
- Verify `GET /up`.
- Verify the `tpvs-queue-worker` service is running when using Docker.
- Run migrations before starting application workers in host-based development; under Docker the one-shot `tpvs-migrate` service runs them before backend and worker start.
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
