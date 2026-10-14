# Technical Architecture and Stack Recommendations

## 0. Current Implementation Status

This document describes the target MVP architecture and the recommended Laravel boundaries. The repository currently implements the foundation, authentication, and Companies catalog; remaining business modules are planned.

| Area | Status | Current location or note |
| --- | --- | --- |
| Laravel application bootstrap | Implemented | `backend/bootstrap/app.php` |
| PostgreSQL configuration | Implemented | `backend/config/database.php` and `.env.example` |
| Application health endpoint | Implemented | `GET /api/health` in `backend/routes/api.php` |
| Laravel framework health endpoint | Implemented | `GET /up`, configured in `backend/bootstrap/app.php` |
| Users foundation | Partially implemented | Default Laravel model and migration only |
| Authentication and roles | Planned | Not implemented |
| Domain modules | Planned | Not implemented |
| Business API | Planned | Only `/api/health` is currently available |
| Queue-based AI processing | Planned | Queue configuration exists; workflow does not |
| Reports and document generation | Planned | Not implemented |

The target architecture below must not be used to claim that planned endpoints or modules are available. Verify implementation in `backend/` before depending on a feature.

## 1. Recommended Architecture Style

Use a modular monolith for the MVP. Keep the codebase divided by domain, but avoid premature microservices.

Why:
- faster delivery;
- easier debugging;
- simpler deployment;
- better fit for AI-assisted development;
- still easy to split later if the product grows.

## 2. Suggested Stack

### Frontend
- Next.js
- React
- TypeScript
- Tailwind CSS
- shadcn/ui or a similar lightweight component library
- React Hook Form
- Zod
- TanStack Table
- Axios for API services
- TanStack Query for server state

### Backend
- Laravel
- PHP 8.3+
- PostgreSQL
- Redis for background jobs
- Queue workers for async processing
- JSON API consumed by the Next.js BFF

### Laravel Application Conventions

Use Laravel's standard boundaries as the backend grows:

- Routes declare HTTP endpoints and do not contain business workflows.
- Controllers coordinate HTTP concerns and remain thin.
- Form Requests validate incoming payloads and request authorization where appropriate.
- API Resources define stable JSON representations.
- Eloquent models define persistence, casts, and relationships.
- Policies enforce resource authorization.
- Actions or domain services coordinate multi-step use cases.
- Jobs handle asynchronous or retryable work.
- Migrations are the only supported mechanism for schema changes.
- Feature tests verify endpoint, authorization, transaction, and workflow behavior.

Do not introduce a large abstraction layer before a concrete domain workflow requires it. Keep the modular-monolith boundary explicit without creating empty folders or speculative services.

### Storage
- Local filesystem for the MVP
- Store only file metadata in the database

### Document Generation
- Excel generation through Laravel-compatible libraries
- Word generation through Laravel-compatible libraries

### Document Services
- `MoneyToWordsService` for deterministic amount-to-words conversion
- templates for Excel and Word outputs

## 3. Why This Stack

This stack is practical because it provides:
- a clear HTTP API boundary;
- reliable financial persistence in PostgreSQL;
- queue-based AI processing;
- modular Laravel services;
- manageable file storage;
- document generation from server-side jobs.

## 4. Domain Modules

### Auth Module
Responsibilities:
- login;
- logout;
- Laravel Sanctum stateful SPA authentication using the database-backed Laravel session;
- CSRF protection for state-changing requests;
- role checks.

### Record Module
Responsibilities:
- create and update records;
- support manual record capture;
- manage state transitions;
- support review and confirmation;
- support cancellation.

### Image Module
Responsibilities:
- upload images;
- replace final image when needed;
- store local references and metadata.

### AI Processing Module
Responsibilities:
- create processing attempts;
- call AI provider;
- store results;
- handle errors and retries.

### Manual Capture Module
Responsibilities:
- create records without images;
- allow an image to be attached later without starting AI processing;
- skip AI processing for manual records;
- route manual records into the same review flow;
- apply the same business validation rules.

### Validation Module
Responsibilities:
- validate commission and return;
- move inconsistent records to review.

### Reporting Module
Responsibilities:
- compute totals;
- filter by company and period;
- provide confirmed-record data for reports;
- export Excel.

### Document Generation Module
Responsibilities:
- generate the weekly Excel report on demand;
- generate the delivery Word document from `Delivery` and its associated `Record`;
- keep document output ephemeral;
- use the stored image and cash breakdown data.

### History Module
Responsibilities:
- record edits;
- store old and new values;
- track who made changes and why.

### Cash Breakdown Module
Responsibilities:
- store denomination quantities under `Delivery`;
- validate the sum of delivery cash denominations against the current Record return amount;
- keep only denominations with quantity greater than zero.
- support the official `0.50` denomination.

### Delivery Module
Responsibilities:
- prepare one editable `Delivery` per confirmed `Record`;
- store delivery date, delivering and receiving names, and the return amount used for cash preparation;
- detect stale preparation by comparing `prepared_return_amount` with the current Record return total;
- update the same Delivery when preparation is repeated; do not add delivery versions or states in the MVP.

## 5. Backend Data Flow

1. User uploads an image.
2. The system creates a record and stores the file locally.
3. The system creates an AI processing attempt.
4. AI returns structured data.
5. Backend normalizes and validates data.
6. The record moves to `NEEDS_REVIEW`; AI and backend processing never confirm it.
7. The Editor reviews and confirms the record after backend validation.
8. The Editor may prepare a Delivery for the confirmed Record.
9. Reports read only confirmed records.

Manual flow:

1. Editor creates a manual record.
2. The system creates the record without image or AI processing.
3. The Editor captures the data manually.
4. Backend applies the same business validation.
5. The record moves to `NEEDS_REVIEW`.
6. The Editor reviews and confirms the record.

## 6. Local Storage Strategy

For the MVP:
- store files in a local folder managed by the application;
- save `path` as the local path or generated key;
- keep original file metadata in the database;
- use secure access for download/view actions.

## 7. Security Baseline

- Hash passwords.
- Use Laravel Sanctum stateful SPA authentication. Laravel owns the database-backed session and sets an HttpOnly `tpvs_session` cookie; the Next.js BFF forwards the browser session and CSRF exchange.
- Use a session cookie that expires when the browser closes, with a 120-minute inactivity timeout. Use `SameSite=Lax` and set `Secure` for HTTPS.
- Protect state-changing requests with Laravel CSRF validation. The BFF exposes a same-origin CSRF-cookie bridge and forwards the session cookie, `XSRF-TOKEN` cookie, `X-XSRF-TOKEN`, and required origin headers.
- Use role-based guards and policies for `EDITOR` and `VIEWER`.
- In the MVP, authenticated users may read; only `EDITOR` may mutate. Add resource Policies with their endpoints.
- Validate all incoming payloads.
- Protect uploads with file type and size checks.
- Log changes to critical data.
- Do not expose the Ollama service directly to browsers; Laravel calls the AI host over the office LAN.

## 8. Performance and Reliability

- Add indexes on foreign keys and report filters.
- Use background jobs for AI processing if response times are variable.
- Preserve every AI attempt for troubleshooting.
- Generate exports asynchronously if files become large.

## 9. Recommended Storage Rules

- Only the final active image is used in the workflow.
- The system does not need to preserve multiple image versions in the MVP.
- If an image is replaced, the active reference should point only to the new file.

## 10. Implementation Constraints

- Do not over-engineer permissions beyond the two initial roles.
- Do not introduce microservices during the MVP.
- Do not store totals as source-of-truth columns.
- Do not allow AI to confirm financial validity.

## 11. Official API Surface

The endpoint groups in this section are target interfaces, not a list of currently implemented routes. Implemented application routes currently include `GET /api/health`, authentication endpoints under `/api/auth`, and the Companies endpoints under `/api/companies`. Other resource groups remain planned; Laravel also exposes `GET /up` as its framework health route.

Base path:

```text
/api
```

Core resource groups:

```text
/api/auth
/api/companies
/api/records
/api/reports
/api/documents
```

### Weekly Reports

```text
GET /api/reports/weekly?year=2026&week=38&month=9&company_id=7
```

Reports only consider records with status `CONFIRMED`. Optional filters include year, month, week, and company; month filtering uses the month containing `Record.week_end_date`.

### Delivery Preparation and Document

```text
GET  /api/records/{record}/delivery
PUT  /api/records/{record}/delivery
POST /api/deliveries/{delivery}/document
```

Delivery preparation is stored as a single editable `Delivery` per confirmed Record. Word output is generated on demand from that Delivery and requires a valid cash breakdown and an associated Image.

### Weekly Excel Report

```text
GET /api/documents/excel?year=2026&week=38&month=9&company_id=7
```

The Excel file is generated from confirmed records only and is not stored permanently.
