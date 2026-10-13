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
└── docker-compose.yml
```

## Current Status

The repository currently contains the initial Laravel and Next.js foundations, Dockerfiles, PostgreSQL Compose infrastructure, and a backend health endpoint.

Implemented foundation:

- Laravel 12 backend with PHP 8.3 support.
- Next.js 16 frontend with React 19 and TypeScript.
- PostgreSQL service in Docker Compose.
- Backend health endpoint at `GET /api/health`.
- Initial Laravel migrations and test scaffolding.
- Local filesystem configuration for application files.

Planned MVP modules:

- Frontend authentication and BFF integration (Laravel Sanctum backend endpoints and `EDITOR`/`VIEWER` role middleware are implemented; the phase 8.4 checkpoint awaits BFF verification).
- Companies, records, record details, images, and audit history.
- Manual record capture and human review.
- Ollama vision-model processing and asynchronous AI jobs.
- Delivery preparation and cash-denomination validation.
- Word delivery document generation.
- Confirmed-record reports and Excel export.
- Office-LAN deployment and end-to-end integration.

The documented business API is not complete yet. At present, `/api/health` is the available API endpoint.

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

The current `docker-compose.yml` starts the frontend, backend, and PostgreSQL. Redis, Ollama, and the complete queue-based AI pipeline are architectural requirements for later MVP phases and are not configured in Compose yet.

## Prerequisites

### Docker Setup

For the current containerized setup, install:

- Docker Engine or Docker Desktop with Compose support.
- Git.

### Local Development Setup

Install these when running services directly on the host:

- PHP 8.3 or newer.
- Composer 2.
- Node.js 22 or newer and npm.
- PostgreSQL 17 or a compatible PostgreSQL version.

### AI Development

AI processing is planned around Ollama and a local vision model. When that phase is implemented, the AI host will need:

- Ollama installed and running.
- A vision model capable of processing the supplier document format.
- LAN connectivity from the Laravel worker to the Ollama host.
- Sufficient CPU, RAM, and optionally GPU resources for the selected model.

Before implementing the production AI pipeline, test approximately 5 to 10 real or anonymized representative images and record processing time, resource usage, extraction accuracy, JSON validity, and repeatability.

## Quick Start with Docker

From the repository root:

```bash
cp .env.example .env
docker compose up --build
```

Docker Compose starts PostgreSQL, Laravel, a Laravel database-queue worker, and Next.js. Laravel waits for PostgreSQL, creates its runtime storage directories, and runs migrations automatically before serving requests. PostgreSQL data is kept in the `tpvs-postgres-data` volume across container recreation and `docker compose down`.

For the local environment, `.env` can override `APP_ENV`, `APP_DEBUG`, `APP_URL`, `APP_KEY`, and the database name/user/password. The committed `.env.example` key and credentials are only for local development; use unique secrets before any deployment. To generate a different local Laravel key, run `docker compose exec tpvs-backend php artisan key:generate` after startup.

To remove containers while retaining PostgreSQL data:

```bash
docker compose down
```

To also delete the persisted database, explicitly remove the Compose volumes with `docker compose down --volumes`.

Open the frontend at:

```text
http://localhost:3000
```

Check the backend health endpoint at:

```text
http://localhost:8000/api/health
```

Laravel's framework health check is available at `http://localhost:8000/up`; the frontend-to-backend BFF health check is `http://localhost:3000/api/health`.

The Compose services are named:

- `tpvs-frontend`
- `tpvs-backend`
- `tpvs-queue-worker`
- `tpvs-postgres`

Stop the services with:

```bash
docker compose down
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

Run backend tests with:

```bash
composer run test
```

Or:

```bash
php artisan test
```

### Backend Environment

Copy `backend/.env.example` to `backend/.env` and review the values before starting Laravel.

For host-based development, `DB_HOST` should point to the PostgreSQL host, commonly `127.0.0.1` or `localhost`.

For Docker-based development, the database hostname inside the backend container must be the Compose service name:

```dotenv
DB_HOST=tpvs-postgres
DB_PORT=5432
DB_DATABASE=tpvs
DB_USERNAME=tpvs
DB_PASSWORD=tpvs
```

For host-based development, use `DB_HOST=127.0.0.1` or `DB_HOST=localhost`; Compose exposes PostgreSQL on port `5432`.

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
| `QUEUE_CONNECTION` | Laravel queue driver | `sync` in `.env.example` |
| `FILESYSTEM_DISK` | Application file storage | `local` |

The planned production-like setup should use an asynchronous queue connection and a dedicated queue worker. Do not rely on `sync` for AI processing once the AI pipeline is enabled.

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

The frontend foundation includes Axios (`src/lib/api-client.ts`) configured to call the BFF, TanStack Query with a global `QueryProvider`, React Hook Form, Zod, and shadcn/ui. Add shadcn components as needed with `npx shadcn add <component>` from `frontend/`.

Other available commands:

```bash
npm run lint
npm run build
npm run start
```

For VS Code Remote SSH diagnostics, install frontend packages on the server from `frontend/` with `npm ci`; Docker's `node_modules` volume is separate from the host filesystem.

The frontend Docker image currently runs the Next.js development server and exposes port `3000`.
Docker Compose mounts `frontend/` into the container for hot reload and stores `node_modules` and `.next` in separate named volumes so the source mount does not hide installed dependencies or Next.js cache. After changing `package.json` or `package-lock.json`, refresh dependencies with:

```bash
docker compose exec tpvs-frontend npm ci
```

If the frontend container was already running before these mounts were added, recreate it once with `docker compose up --build --force-recreate tpvs-frontend`.

## Database and Storage Requirements

PostgreSQL is the authoritative store for application data. Do not use Excel files as an input source or as the source of official totals.

The planned MVP data model includes:

- `User`
- `Company`
- `Record`
- `RecordDetail`
- `Image`
- `AIProcessing`
- `History`
- `Delivery`
- `DeliveryCashDenomination`

Application-managed files must remain private. Store file metadata in PostgreSQL and use authorized backend requests to view or download images. Generated Word and Excel documents are on-demand outputs and should not be treated as permanent source records.

Before LAN or production-like use, configure persistent storage for:

- PostgreSQL data.
- Private uploaded images.
- Laravel application storage.
- Logs and failed queue jobs.

## Ollama and AI Requirements

The planned AI architecture is:

```text
Frontend
  -> Laravel API
  -> Queue
  -> Laravel worker
  -> Ollama over the LAN
  -> Vision model
```

Ollama must not be exposed directly to browsers. Laravel should be the only application component calling the AI host.

AI processing must follow these rules:

- Preserve every processing result and technical failure.
- Use structured JSON with all expected properties present.
- Use `null` and confidence `0` for unreadable values rather than inventing data.
- Preserve the visual order of multiple movements.
- Treat low confidence and inconsistent calculations as review issues.
- Never allow AI to confirm a record or calculate official financial truth.
- Technical failures should be distinguishable from usable results that need human review.

## MVP Workflow

### Image-Based Record

1. An Editor uploads one supplier image.
2. The backend stores the image and creates the record.
3. A queue job sends the image to the AI service.
4. The backend validates the extracted structure and financial values.
5. The record moves to `NEEDS_REVIEW`.
6. An Editor corrects or accepts the data.
7. The backend validates the final values.
8. The Editor confirms the record.
9. The confirmed record can be used for delivery preparation and official reports.

### Manual Record

1. An Editor creates a record without an image.
2. No AI processing attempt is created.
3. The Editor enters the movement data.
4. The backend applies the same business validations as image-based records.
5. The record goes through human review before confirmation.
6. An image may be attached later if a Word delivery document is required.

## Business Rules

- Only `CONFIRMED` records are included in official reports and Excel outputs.
- `PENDING`, `PROCESSING`, `NEEDS_REVIEW`, `ERROR`, and `CANCELED` records are excluded from official totals.
- AI output is never automatically confirmed.
- A confirmed record must move back to `NEEDS_REVIEW` before it can be modified.
- Meaningful changes require a history entry with the user, reason, and before/after values.
- A record period is identified by `week` and `year` together.
- Month filtering uses the month containing `week_end_date` when a week spans two months.
- Company names are fixed after creation in the MVP.
- A record can contain multiple movement details.
- A `CONFIRMED` record can have at most one editable `Delivery`.
- Delivery cash denominations must total the current record return amount.
- Only positive denomination quantities are stored.
- The official denomination list includes `0.50`.
- A Word document requires a confirmed record, valid delivery preparation, and an associated image.
- Return-in-words conversion is deterministic and must not use AI.

## Roles

| Capability | `EDITOR` | `VIEWER` |
| --- | --- | --- |
| Consult records and reports | Yes | Yes |
| Search and filter | Yes | Yes |
| Create records | Yes | No |
| Upload images | Yes | No |
| Review, edit, confirm, or cancel | Yes | No |
| Prepare delivery | Yes | No |
| Generate Word documents | Yes | No |
| Generate Excel reports | Yes | Yes |
| Create companies | Yes | No |
| Rename or delete companies | No | No |

## Health Check and Verification

Verify the current backend foundation with:

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

For a basic local verification, run:

```bash
cd backend
php artisan test
cd ../frontend
npm run lint
npm run build
```

The complete MVP verification must eventually include normal, failure, authorization, and regression scenarios such as invalid AI JSON, unreadable images, financial mismatches, invalid cash breakdowns, unauthorized Viewer mutations, canceled records, stale deliveries, and missing images for Word generation.

## API Direction

The planned API base path is `/api`.

Planned resource groups include:

```text
/api/auth
/api/companies
/api/records
/api/reports
/api/documents
```

Planned document endpoints include:

```text
GET  /api/documents/excel
GET  /api/records/{record}/delivery
PUT  /api/records/{record}/delivery
POST /api/deliveries/{delivery}/document
```

These endpoints are documented target interfaces and are not all implemented in the current repository.

## Office-LAN Deployment Requirements

The intended deployment separates application and AI responsibilities:

- The application server hosts Next.js, Laravel, PostgreSQL, the queue worker, and private storage.
- The AI PC hosts Ollama and the selected vision model.
- Laravel communicates with Ollama over the office LAN.
- Users access the frontend from another office workstation.
- Browser clients do not call PostgreSQL, private storage, or Ollama directly.
- Authentication, role authorization, upload validation, and session expiration must be enabled before operational use.
- Backups must cover PostgreSQL and private uploaded files.

## Documentation

Start with [`docs/README.md`](docs/README.md) for the complete documentation map.

| Document | Contents |
| --- | --- |
| [`docs/frontend/README.md`](docs/frontend/README.md) | Next.js frontend architecture, conventions, feature guides, and verification |
| [`docs/01_product_prd.md`](docs/01_product_prd.md) | Product vision, scope, personas, and requirements |
| [`docs/02_business_rules_and_states.md`](docs/02_business_rules_and_states.md) | State machine, validations, reporting rules, and delivery rules |
| [`docs/03_data_model.md`](docs/03_data_model.md) | Entities, fields, relationships, and constraints |
| [`docs/04_technical_architecture.md`](docs/04_technical_architecture.md) | Stack, modules, data flow, security, and API direction |
| [`docs/05_ai_development_blueprint.md`](docs/05_ai_development_blueprint.md) | AI-assisted implementation prompts and testing guidance |
| [`docs/06_ai_design.md`](docs/06_ai_design.md) | AI contracts, validation layers, retry behavior, and infrastructure |
| [`docs/07_ai_design_overview.md`](docs/07_ai_design_overview.md) | Short AI implementation summary |
| [`docs/08_document_generation.md`](docs/08_document_generation.md) | Excel and Word generation rules |
| [`docs/09_manual_capture.md`](docs/09_manual_capture.md) | Manual record workflow |
| [`docs/10_ui_ux.md`](docs/10_ui_ux.md) | Screens, navigation, role behavior, and user flows |
| [`docs/11_technical_roadmap.md`](docs/11_technical_roadmap.md) | Implementation phases, checkpoints, and definition of done |

## Contribution Guidelines

Implement one domain module at a time and keep each module verifiable. Every completed module should include:

- Input validation and explicit error behavior.
- Authorization where applicable.
- Tests appropriate to the risk.
- A successful checkpoint demonstration.
- Configuration and operational requirements in the documentation.

Do not bypass backend validation, the human confirmation rule, role authorization, audit history, or confirmed-only reporting.
