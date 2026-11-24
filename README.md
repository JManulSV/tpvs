# TPVS

TPVS is an internal operations platform for turning supplier documents into validated financial records, delivery documents, and official reports.

The MVP replaces duplicate manual transcription between images, Excel, and delivery documents with a controlled workflow:

```text
Supplier image or manual capture
  -> AI extraction when an image is available
  -> Backend validation
  -> Human review
  -> Confirmation
  -> Delivery preparation
  -> Word and Excel outputs
```

The database is the source of truth. AI proposes data, the backend validates it, and an authorized human confirms it.

## Repository Structure

```text
.
├── backend/       Laravel API and database migrations
├── frontend/      Next.js application
├── docs/          Product, business, architecture, AI, UX, and roadmap documentation
├── docker/        PostgreSQL init script (creates tpvs_test)
├── specs/         SPECs that define each module
├── compose.yaml            Shared Docker Compose base
├── compose.override.yaml   Development (loaded automatically)
├── compose.prod.yaml       Application server
├── compose.ai-host.yaml    AI PC (Ollama only)
└── Makefile                Shortcuts: make help
```

## Current Status

The MVP modules (authentication, companies, manual and image records with AI extraction, review, history, delivery preparation, Word, reports, and Excel) are implemented, and the frontend uses the final design. End-to-end integration, release testing, and office-LAN deployment remain.

The single source of truth for module status is [`docs/roadmap.md`](docs/roadmap.md).

## Architecture

TPVS uses a modular monolith for the MVP:

| Component | Technology | Purpose |
| --- | --- | --- |
| Frontend | Next.js, React, TypeScript | Role-aware web interface and browser-facing BFF boundary |
| Backend | Laravel 12, PHP 8.3+ | JSON API, validation, business rules, jobs, and document generation |
| Database | PostgreSQL | Source of truth for users, companies, records, deliveries, and history |
| Queue | Laravel queue worker | Asynchronous AI processing and future long-running work |
| File storage | Private local filesystem | Original supplier images and application-managed files |
| AI host | Ollama with a vision model | Local image interpretation over the office LAN |

Docker Compose starts the frontend, backend, PostgreSQL, a one-shot migration service, and the database queue worker used by the asynchronous AI pipeline. Ollama runs only with the optional `ai` profile in development; in production it runs on the AI PC (`compose.ai-host.yaml`). Files, networks, and production use are described in [`docs/backend/configuration-operations.md`](docs/backend/configuration-operations.md#docker-setup-spec-26).

## Prerequisites

### Docker Setup

For the current containerized setup, install:

- Docker Engine or Docker Desktop with Compose 2.24.4 or newer.
- Git.
- `make` (optional; every target is a plain `docker compose` command, shown with `make -n <target>`).

### Local Development Setup

Install these when running services directly on the host:

- PHP 8.3 or newer.
- Composer 2.
- Node.js 22 or newer and npm.
- PostgreSQL 17 or a compatible PostgreSQL version.

### AI Development

AI extraction uses Ollama and the selected local vision model. The AI host needs:

- Ollama installed and running.
- A vision model capable of processing the supplier document format.
- LAN connectivity from the Laravel worker to the Ollama host.
- Sufficient CPU, RAM, and optionally GPU resources for the selected model.

The selected `qwen2.5vl:3b` candidate and measured benchmark are documented in `docs/ai-spike/benchmark-v4.md`; extracted values always require Editor review.

## Quick Start with Docker

From the repository root:

```bash
cp .env.example .env
sed -i "s|^APP_KEY=.*|APP_KEY=base64:$(openssl rand -base64 32)|" .env   # unique key
make up              # or: docker compose up -d --build
make up-ai           # optional: also start Ollama and download the model
```

The root `.env` is the only configuration source under Docker; `APP_KEY` is required. Set `UID`/`GID` to your host user (`id -u`, `id -g`) so files written by the containers belong to you. `tpvs-migrate` applies migrations, then Laravel and the worker start. Dependencies are reinstalled automatically when `composer.lock` or `package-lock.json` changes. PostgreSQL data is kept in the `tpvs-postgres-data` volume across container recreation and `make down`; `docker compose down --volumes` deletes it.

PostgreSQL (`5432`) and Laravel (`8000`) listen on `127.0.0.1` only; Next.js (`3000`) is reachable from the LAN. `make help` lists every shortcut.

Open the frontend at:

```text
http://localhost:3000
```

Check the backend health endpoint at:

```text
http://localhost:8000/api/health
```

Laravel's framework health check is available at `http://localhost:8000/up`; the frontend-to-backend BFF health check is `http://localhost:3000/api/health`.

The Compose services are named `tpvs-frontend`, `tpvs-backend`, `tpvs-queue-worker`, `tpvs-postgres`, and the one-shot `tpvs-migrate`; with the `ai` profile, also `tpvs-ollama` and `tpvs-ollama-pull`. Containers appear as `tpvs-tpvs-<service>-1`.

Stop the services with:

```bash
make down
```

## Backend Local Setup

Run these commands from `backend/`:

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan serve
```

The API is then available at `http://localhost:8000`.

The backend also contains a setup script that installs PHP and frontend dependencies, creates the environment file, generates the application key, runs migrations, and builds the frontend assets:

```bash
composer run setup
```

The Laravel development script starts the Laravel server, queue listener, log viewer, and Vite process together:

```bash
composer run dev
```

Run backend tests against a separate test database, never the development one. In Docker, use the command in [Health Check and Verification](#health-check-and-verification).

### Backend Environment

Copy `backend/.env.example` to `backend/.env` and review the values before starting Laravel.

For host-based development, `DB_HOST` should point to the PostgreSQL host, commonly `127.0.0.1` or `localhost`.

Under Docker, Compose sets every Laravel variable from the root `.env`, so `backend/.env` is not used. Inside the containers the database hostname is the Compose service name:

```dotenv
DB_HOST=tpvs-postgres
DB_PORT=5432
DB_DATABASE=tpvs
DB_USERNAME=tpvs
DB_PASSWORD=tpvs
```

For host-based development, use `DB_HOST=127.0.0.1` or `DB_HOST=localhost`; Compose exposes PostgreSQL on `127.0.0.1:5432`.

The repository's `backend/.env.example` uses the Compose hostname `tpvs-postgres`. For host-based development, replace it with `127.0.0.1` or `localhost` as appropriate.

Relevant current variables include:

| Variable | Purpose | Current default |
| --- | --- | --- |
| `APP_ENV` | Application environment | `local` |
| `APP_KEY` | Laravel encryption key | Generated locally |
| `APP_URL` | Backend URL | `http://localhost:8000` |
| `DB_CONNECTION` | Database driver | `pgsql` |
| `DB_HOST` | PostgreSQL hostname | Environment-dependent |
| `DB_PORT` | PostgreSQL port | `5432` |
| `DB_DATABASE` | Database name | `tpvs` |
| `DB_USERNAME` | Database user | `tpvs` |
| `DB_PASSWORD` | Database password | `tpvs` |
| `QUEUE_CONNECTION` | Laravel queue driver | `database` |
| `AI_OLLAMA_URL` | Ollama host reachable by backend and worker | Configure in root `.env` |
| `AI_OLLAMA_MODEL` | Ollama vision model | `qwen2.5vl:3b` |
| `AI_OLLAMA_TIMEOUT` | Provider HTTP timeout (seconds) | `300` |
| `DB_QUEUE_RETRY_AFTER` | Database queue visibility timeout (seconds) | `360` |
| `FILESYSTEM_DISK` | Application file storage | `local` |

Use the asynchronous database queue and dedicated worker (`--timeout=330`); the queue visibility timeout is `360` seconds. Do not use the `sync` driver for image extraction. Configure `AI_OLLAMA_URL` in the root `.env` for backend and worker. Ollama is never exposed to the browser.

## Frontend Local Setup

Run these commands from `frontend/`:

```bash
npm ci
npm run dev
```

Open `http://localhost:3000` in a browser.

Browser API requests use the Next.js BFF at `/api/*`. The BFF forwards them to Laravel using `LARAVEL_API_URL`, a server-only environment variable, and passes through authorization and cookie headers. Docker Compose sets it to `http://tpvs-backend:8000`; when running the frontend directly on the host, create `frontend/.env.local` with:

```dotenv
LARAVEL_API_URL=http://localhost:8000
```

The frontend includes Axios (`src/lib/api-client.ts`) configured to call the BFF, TanStack Query with a global `QueryProvider`, React Hook Form, Zod, Vitest for BFF contract tests (`npm test` from `frontend/`), and shadcn/ui. Add shadcn components as needed with `npx shadcn add <component>` from `frontend/`.

Other available commands:

```bash
npm run lint
npm run build
npm run start
```

For VS Code Remote SSH diagnostics, install frontend packages on the server from `frontend/` with `npm ci`; Docker's `node_modules` volume is separate from the host filesystem.

In development the frontend container runs the Next.js development server on port `3000`. Docker Compose mounts `frontend/` into the container for hot reload and stores `node_modules` and `.next` in separate named volumes. The entrypoint runs `npm ci` automatically when `package-lock.json` changes; restart the service with `docker compose restart tpvs-frontend`. The production image (`prod` target) runs the `standalone` output with `node server.js`.

## Health Check and Verification

Check the backend is up:

```bash
curl http://localhost:8000/api/health
```

Expected response:

```json
{
  "status": "ok",
  "service": "backend"
}
```

Backend tests must run against the `tpvs_test` database. Use `make test`, which creates `tpvs_test` if missing and always passes the variable. Without `make`, **pass it explicitly**: the container exports `DB_DATABASE=tpvs` and the suite rebuilds the schema. `tests/TestCase.php` aborts any run whose database is not `tpvs_test`, before anything is touched.

```bash
make test
# equivalent:
docker compose exec -T -e DB_DATABASE=tpvs_test tpvs-backend php artisan test
```

Frontend checks, from `frontend/`:

```bash
npm test && npm run lint && npx tsc --noEmit && npm run build
```

Release testing scenarios are listed in [`docs/roadmap.md`](docs/roadmap.md).

## Office-LAN Deployment Requirements

The intended deployment separates application and AI responsibilities:

- The application server hosts Next.js, Laravel, PostgreSQL, the queue worker, and private storage.
- The AI PC hosts Ollama and the selected vision model.
- Laravel communicates with Ollama over the office LAN.
- Users access the frontend from another office workstation.
- Browser clients do not call PostgreSQL, private storage, or Ollama directly.
- Authentication, role authorization, upload validation, and session expiration must be enabled before operational use.
- Persist and back up PostgreSQL data and private uploaded images together (see [`docs/backend/configuration-operations.md`](docs/backend/configuration-operations.md)).

## Documentation

Start with [`docs/README.md`](docs/README.md) for the complete documentation map.

| Document | Contents |
| --- | --- |
| [`docs/frontend/README.md`](docs/frontend/README.md) | Next.js frontend architecture, conventions, feature guides, and verification |
| [`docs/product.md`](docs/product.md) | Product vision, scope, personas, and requirements |
| [`docs/business-rules.md`](docs/business-rules.md) | State machine, validations, manual capture, reporting, and delivery rules |
| [`docs/data-model.md`](docs/data-model.md) | Entities, fields, relationships, and constraints |
| [`docs/architecture.md`](docs/architecture.md) | Stack, modules, data flow, security, and API direction |
| [`docs/ai-extraction.md`](docs/ai-extraction.md) | AI contracts, validation layers, retry behavior, and infrastructure |
| [`docs/document-generation.md`](docs/document-generation.md) | Excel and Word generation rules |
| [`docs/ui-ux.md`](docs/ui-ux.md) | Screens, navigation, role behavior, and user flows |
| [`docs/roadmap.md`](docs/roadmap.md) | Module status by SPEC, pending phases, and definition of done |
| [`docs/BACKLOG.md`](docs/BACKLOG.md) | Pending ideas that do not have a SPEC yet |
| [`docs/backend/README.md`](docs/backend/README.md) | Laravel backend conventions, API contract, workflows, and operations |

## Contribution Guidelines

Implement one domain module at a time and keep each module verifiable. Every completed module should include:

- Input validation and explicit error behavior.
- Authorization where applicable.
- Tests appropriate to the risk.
- A successful checkpoint demonstration.
- Configuration and operational requirements in the documentation.

Do not bypass backend validation, the human confirmation rule, role authorization, audit history, or confirmed-only reporting.
