# Phase 8 - Technical Implementation Roadmap

## 0. Current Checkpoint

Phases 8.2 (Preparation), 8.3 (Database), 8.4 (Authentication), and 8.5 (Companies) are complete. Laravel 12, PostgreSQL, and Next.js start through Docker Compose; Compose includes a persistent PostgreSQL volume and a database queue worker; Laravel initializes runtime storage directories and applies migrations at startup. The frontend foundation includes TypeScript, Tailwind CSS, shadcn/ui, Axios, TanStack Query, React Hook Form, and Zod. Health, authentication, and Companies behavior have been verified. The remaining business modules in this roadmap have not yet been implemented.

The roadmap is a target implementation sequence. A module must not be marked complete because its documentation exists; it is complete only after its code, tests, operational requirements, and checkpoint are verified.

## 1. Purpose

Turn the approved product, business, data, AI, and UI/UX designs into an implementation sequence with a verifiable checkpoint at the end of every module.

A module is complete only when its behavior works, its acceptance checks pass, and the team can safely continue to the next module.

## 2. Implementation Strategy

Build bottom-up:

```text
Infrastructure
  -> Backend and database
  -> Business rules
  -> AI
  -> Documents
  -> Frontend
  -> End-to-end integration
  -> Testing
  -> Deployment
```

The AI model is validated early with a technical spike. Do not build the complete production AI pipeline before confirming that the selected local vision model can process representative images within acceptable time and resource limits.

## 3. Module Roadmap

| Phase | Module | Outcome |
| --- | --- | --- |
| 8.1 | Roadmap | Approved technical plan and checkpoints |
| 8.2 | Preparation | Backend, frontend, and local infrastructure foundations |
| 8.3 | Database | Migrations, constraints, indexes, and Eloquent relationships |
| 8.4 | Authentication | Login, logout, current user, roles, and authorization |
| 8.5 | Companies | Read and create company catalog entries |
| 8.6 | Manual records | Create, validate, and confirm records without AI |
| 8.7 | Images | Private upload, storage, association, and authorized viewing |
| 8.8 | AI spike | Measure Ollama and vision-model feasibility using sample images |
| 8.9 | AI pipeline | Asynchronous extraction, validation, persistence, and error handling |
| 8.10 | Review | Correct, confirm, and cancel records |
| 8.11 | History | Audit record and delivery changes |
| 8.12 | Delivery | Prepare delivery information and validate cash breakdown |
| 8.13 | Word | Generate the delivery document |
| 8.14 | Reports and Excel | Confirmed-record reports and workbook export |
| 8.15 | Frontend | Complete role-aware UI/UX |
| 8.16 | Integration | Verify the complete business flow end to end |
| 8.17 | Testing | Run normal, failure, authorization, and regression scenarios |
| 8.18 | Deployment | Deploy on the office LAN and verify from another workstation |

## 4. Phase Details

### 8.2 - Preparation

**Goal:** Create correctly configured, runnable projects and local infrastructure.

Backend:
- Laravel API
- PostgreSQL
- environment configuration through `.env`
- Docker support
- API health endpoint
- queue configuration and worker entry point
- private local storage configuration

Frontend:
- Next.js
- TypeScript
- Tailwind CSS
- shadcn/ui
- Axios
- TanStack Query
- React Hook Form
- Zod

Infrastructure:
- Git repository and ignore rules
- Docker Compose for application dependencies
- documented environment variables
- local network configuration for development

**Checkpoint: COMPLETE (2026-10-09).** Backend and frontend start successfully in Docker Compose. `GET /api/health`, `GET /up`, and frontend `GET /api/health` through the BFF all return HTTP 200. PostgreSQL reports healthy, all 12 migrations persist across `docker compose down` / `up`, the queue worker starts, and the backend test suite passes (9 tests, 20 assertions). Frontend lint, TypeScript, and production build pass.

**Commit:** `chore: initialize project infrastructure`

### 8.3 - Database

**Status: COMPLETE (2026-10-08).** The approved schema and Eloquent relationships are implemented and verified.

Implement the approved model:

```text
User
Company
Record
RecordDetail
Image
AIProcessing
History
Delivery
DeliveryCashDenomination
```

Implement migrations, foreign keys, indexes, check constraints, unique constraints, and Eloquent relationships. Cash denomination constraints must enforce positive quantities, allowed denomination values, and unique denomination values per Delivery. In particular:

```text
Record
  ├── RecordDetails
  ├── Image (0..1)
  ├── History
  └── Delivery (0..1)
        └── DeliveryCashDenominations
```

`Delivery` is the single editable delivery-preparation record associated with a `Record` in the MVP. It is not a versioned document entity. Generated `.docx` files remain ephemeral.

**Checkpoint: COMPLETE (2026-10-08).** PostgreSQL contains all nine domain entities, their foreign keys, indexes, unique constraints, and checks. Feature tests persist and reload a complete Record aggregate with details, image/AI processing, history, delivery, and cash denominations; duplicate images/deliveries/denominations, invalid cash quantities, and unsupported denominations are rejected. All 12 migrations were run, rolled back, and rerun successfully in a temporary PostgreSQL database; the development database was left intact. Backend suite: 9 tests, 20 assertions passed.

**Commit:** `feat: create core database schema`

### 8.4 - Authentication and Authorization

**Status: COMPLETE (2026-10-14). Backend, frontend/BFF, and browser checkpoint verified.**

Implement:
- login;
- logout;
- current-user (`me`) endpoint;
- Laravel Sanctum stateful SPA authentication using the database-backed session and an HttpOnly `tpvs_session` cookie;
- session cookie expires when the browser closes, with a 120-minute inactivity timeout; logout invalidates the server session;
- CSRF protection for state-changing requests, with the Next.js BFF forwarding the session and CSRF cookies/headers;
- `EDITOR` and `VIEWER` roles: authenticated users may read, and only `EDITOR` may mutate; resource Policies are added with their endpoints.
- Next.js same-origin CSRF bridge, trusted-origin and session-cookie forwarding, authentication services, basic `/login`, server-protected `/dashboard`, logout, and session-aware `/` and `/login` redirects.
- Vitest coverage for the BFF contract and a documented browser acceptance flow.

**Checkpoint: COMPLETE (2026-10-14).** Backend feature tests verify `EDITOR` mutation, `VIEWER` forbidden behavior, and authenticated reads. Vitest BFF tests (12/12), lint, TypeScript, and production build pass. Playwright browser verification against the running LAN stack passed for anonymous access and both `EDITOR` and `VIEWER`: invalid credentials show feedback without reload or credentials in the URL; valid login establishes the HttpOnly browser-session cookie; `/me` returns the expected role; `/dashboard` displays the identity and survives refresh; authenticated visits to `/login` and `/` reach `/dashboard`; logout returns to `/login` and protects `/dashboard`. No browser JavaScript exceptions or unexpected console errors were observed. The expected `401` for invalid credentials was visible in the browser console as a failed HTTP request.

**Commit:** `feat: implement authentication and authorization`

### 8.5 - Companies

**Status: COMPLETE (2026-10-14).** Company catalog API, PostgreSQL uniqueness invariant, and basic role-aware frontend workflow are implemented and verified.

Implement:

```text
GET  /api/companies
POST /api/companies
GET  /api/companies/{id}
```

The list supports case-insensitive substring search and pagination (`page=1`, `per_page=15`, maximum 100), ordered by company name. Each list item includes the count of associated `CONFIRMED` records. Company names are trimmed and immutable after creation in the MVP; normalized duplicates are rejected by Laravel and protected by a PostgreSQL unique expression index. No rename or delete operation is exposed. The basic Spanish interface provides search, pagination, a creation dialog for `EDITOR`, and consultation for `VIEWER`; official UI/UX design remains in phase 8.15.

**Checkpoint: COMPLETE (2026-10-14).** Backend feature tests verify authenticated reads, `EDITOR` creation and `VIEWER` rejection, validation, normalized duplicate rejection, search, pagination, confirmed-record counts, detail retrieval, and Company/Record association. The new PostgreSQL migration was applied, rolled back, and reapplied in a temporary database; a pre-existing normalized-name collision stopped the migration with IDs and names, and the unique index rejected an equivalent duplicate. Backend suite: 31 tests, 102 assertions passed. Frontend Vitest: 15 tests passed; lint, TypeScript, production build, and Pint checks for changed backend files passed. Browser acceptance through the BFF verified `EDITOR` creation (`201`), list refresh (`200`), duplicate feedback (`422`), `VIEWER` read (`200`), hidden create action, and forbidden create (`403`). Temporary verification rows and database were removed. See `docs/frontend/06_testing.md` for the browser acceptance record.

**Suggested commit:** `feat: implement company management` (not yet committed).

### 8.6 - Manual Records

Implement the first complete business workflow without AI:

```text
Create Record
  -> Add RecordDetails
  -> Calculate and validate
  -> CONFIRMED
```

Build `BusinessValidator` for required fields, commission, return, totals, and applicable business rules. A valid manual `Record` is confirmed in the save transaction because the Editor enters and reviews the data together. The business period uses the office's week number (1–53), calendar year, and valid start/end dates; do not apply ISO-week or Monday-Sunday assumptions. Image-derived records still require a separate human review before confirmation. A manual Record can be created and confirmed without an image. Delivery can also be prepared without an image; an image must be attached before Word generation.

**Checkpoint: COMPLETE (2026-10-15).** Manual capture validates the business period without ISO weekday assumptions, calculates financial values, creates the record directly as `CONFIRMED`, and writes a `null -> CONFIRMED` History event atomically without Image or `AIProcessing`. The frontend keeps the user on `/records/new`, reports success, and resets the form for the next capture. Backend suite: 55 tests, 313 assertions passed. Frontend Vitest: 21 tests passed; lint, TypeScript, production build, and changed-file Pint checks passed. Browser acceptance verified Editor creation (`201`), backend calculations, form reset, list and detail, Viewer read-only behavior, and direct Viewer mutation rejection (`403`). QA data was removed. See `docs/frontend/06_testing.md` for the browser acceptance record.

**Suggested commit:** `feat: implement manual record workflow` (not yet committed).

**Commit:** `feat: implement manual record workflow`

### 8.7 - Images

**Status: COMPLETE (2026-10-15). Backend implementation and HTTP acceptance verified.** Implemented private image upload/replacement, `Image` metadata, association with `Record`, and authorized viewing. JPEG, PNG, and WebP content up to 10 MiB is detected and validated; names are sanitized and storage keys are generated. The dedicated private local disk is persisted by Docker and PHP upload limits are `10M`/`12M`. `EDITOR` can upload/replace except on canceled Records or images with AI attempts; authenticated `EDITOR` and `VIEWER` can view images on non-canceled Records. Upload does not start AI or change Record state. Backend suite: 70 tests, 401 assertions passed. Focused image suite: 15 tests, 88 assertions passed. HTTP acceptance verified Editor upload (`201`) and read (`200`), Viewer read (`200`) and direct upload rejection (`403`), MIME and private cache headers, and metadata without storage paths. QA data and seeded test users were removed. See `docs/backend/03_api_contract.md` and `docs/backend/08_files_and_documents.md` for the contract and operational rules.

**Checkpoint:** An `EDITOR` can attach an image to a `Record` and view it through an authorized request.

**Commit:** `feat: implement record image storage`

### 8.8 - Technical AI Spike

**Status: COMPLETE — SPEC 06 candidate selected (2026-10-20).** The v1–v3 `OPTIMIZE` checkpoint and its evidence remain historical in [`ai-spike/benchmark-v3.md`](ai-spike/benchmark-v3.md). SPEC 06 compared `gemma3:4b`, `qwen2.5vl:3b`, and `minicpm-v:8b` in 30 serial runs on five corrected samples using prompt v4. `qwen2.5vl:3b` is the recommended SPEC 08 candidate by the agreed priority order (138/154 visible fields exact); all ten outputs failed structural validation, which remains an explicit production concern and requires review. See [`ai-spike/benchmark-v4.md`](ai-spike/benchmark-v4.md). The comparison does not block SPEC 07 or SPEC 08. A temporary opt-in Compose profile is documented in [`backend/10_configuration_operations.md`](backend/10_configuration_operations.md).

This is a controlled experiment, not the production AI module. Use 5-10 real or anonymized representative images and test:

- image submission to Ollama and the candidate vision model;
- end-to-end processing time;
- memory and hardware usage;
- JSON contract adherence;
- extraction accuracy for required fields;
- detection and ordering of multiple movements;
- failure modes and repeatability.

Record measurements and sample outcomes. Decide whether to continue, optimize the model/runtime, or change the model before building the complete pipeline. Do not build production architecture solely on an unvalidated assumption.

**Checkpoint: COMPLETE.** A documented optimize decision is supported by measured latency, contract adherence, extraction outcomes, representative-run CPU/RAM samples, and controlled failure tests. Production AI integration remains in phase 8.9.

### 8.9 - AI Pipeline

**Status: COMPLETE (2026-10-22).** The backend routes, private image aggregate, Ollama provider, extraction validator, financial proposal persistence, asynchronous job, bounded retries, safe resource, manual retry endpoint, and IMAGE confirmation gate are implemented. The UI remains in 8.15; human correction and audit history remain in 8.10/8.11.

Implement:

```text
ProcessImageJob
  -> AIService
  -> OllamaAIService
  -> AiExtractionResult
  -> ExtractionValidator
  -> RecordExtractionService
  -> BusinessValidator
  -> NEEDS_REVIEW or ERROR
```

Persist the latest result/error in one reusable `AIProcessing` row per image. Technical failures move the record to `ERROR`; usable extraction results always require human review and never auto-confirm. Initial transient failures may call the provider at most three times; each manual Editor retry makes one call.

**Checkpoint: COMPLETE (2026-10-22).** An authenticated Editor can upload an image and receive `202`; the worker persists a usable proposal in `NEEDS_REVIEW` or a diagnostic `ERROR`, without confirming the record. The complete backend suite passed against a temporary PostgreSQL database: 125 tests, 687 assertions. Compose configuration validation passed; the full runtime with a live Ollama host remains an operational exercise.

**Commit:** `feat: implement AI extraction pipeline`

### 8.10 - Review and Confirmation

Implement Editor review, correction, validation, confirmation, and cancellation:

```text
NEEDS_REVIEW
  -> Editor corrects or accepts
  -> Backend validates
  -> CONFIRMED or remains NEEDS_REVIEW
```

**Checkpoint:** Complete image upload -> AI extraction -> review -> correction -> confirmation. A record is confirmed only by an authorized human after backend validation.

**Status: COMPLETE (2026-10-22).** Image upload, processing progress, human review, correction with mandatory reason, confirmation, cancellation from `NEEDS_REVIEW`/`ERROR`, and manual retry are implemented. Backend feature suite: 133 tests, 778 assertions. Vitest, ESLint, TypeScript, production build, and changed-file Pint passed.

Browser verification used Ollama with `qwen2.5vl:3b`: a real `prueba1.jpeg` upload returned `202`, polled through `PROCESSING`, and ended in `ERROR` with safe `INVALID_CONFIDENCE` diagnostics because the model returned confidence `0.5` for null values (the known output-contract limitation from SPEC 08). The same live Record then exercised manual retry once and cancellation to `CANCELED`, preserving image and AI evidence while the image endpoint returned `409`. A controlled IMAGE Record in `NEEDS_REVIEW` exercised the review, correction, confirmation, and `VIEWER` flows; a stale-state cancellation returned `409` and refreshed the screen. Verification also fixed three UI defects: `$NaN` totals for empty aggregates, missing company preselection in edit mode, and the review form remaining open after a successful correction.

**Commit:** `feat: complete record review and cancellation checkpoint`

### 8.11 - History

Record the field, old value, new value, user, reason, and timestamp for meaningful record and delivery-preparation changes. When a confirmed record is modified, preserve the required `CONFIRMED -> NEEDS_REVIEW -> CONFIRMED` workflow and its audit entries.

**Checkpoint:** Changes to record fields and delivery preparation can be traced to the user and before/after values.

**Status: COMPLETE for record history (2026-10-26); delivery history remains in 8.12.** Record creation, field-level correction diffs, the no-op `record_updated`, audited reopening of `CONFIRMED` records, confirmation/cancellation status events, and the paginated read API and panel are implemented. Backend suite: 149 tests, 922 assertions against a temporary PostgreSQL database. Pint, Vitest (36 tests), ESLint, TypeScript, and production build passed.

Browser verification covered manual and image creation (`record_created` with fixed reasons and no creation `status` event), AI transitions without History, correction and no-op diffs, confirmation, reopening with preserved `source`/Image/`AIProcessing`, reconfirmation, the `CONFIRMED` no-change `422`, cancellation, history pagination/loading/error/empty states, `VIEWER` read access, and a forbidden direct `PUT`. The evidence is recorded in [`frontend/06_testing.md`](frontend/06_testing.md); QA data was removed and test accounts remain.

**Commit:** `feat: complete record change history checkpoint`

### 8.12 - Delivery Preparation

Implement the single `Delivery` associated with a confirmed `Record`. It centralizes delivery date, delivering person, receiving person, prepared return amount, and `DeliveryCashDenomination` rows.

Validate:

```text
SUM(denomination.value * denomination.quantity)
  = current Record return amount
```

Only positive quantities are persisted. A mismatch blocks successful preparation and document generation. If the record's current return changes after preparation, show that the delivery uses an older amount and allow the Editor to update the same delivery. Do not introduce delivery versions or delivery status states in the MVP.

**Checkpoint:** A valid delivery is saved and its cash total equals the confirmed record return; a mismatch is rejected.

**Status: COMPLETE (2026-10-29).** Delivery preparation is implemented: one editable `Delivery` per confirmed `Record`, cash denominations, stale detection, field-level history with `delivery_id`, and the `/records/[recordId]/delivery` screen linked from the confirmed record detail. Verification: backend suite 181 tests (1126 assertions) against `tpvs_test`, Pint, Vitest 61, ESLint, TypeScript, and production build (Next.js 16.4.3). Browser checkpoint evidence is recorded in [`frontend/06_testing.md`](frontend/06_testing.md).

**Commit:** `feat: implement delivery preparation`

### 8.13 - Word Document

Generate `.docx` from the current `Delivery`, its `Record`, the associated `Image`, and cash denominations. The image is mandatory. Use `MoneyToWordsService` for the amount in words. Generated files are returned for download and are not stored as delivery versions.

**Checkpoint:** A valid prepared delivery with an image generates a Word document; a missing image or invalid delivery blocks generation.

**Commit:** `feat: implement delivery document generation`

### 8.14 - Reports and Excel

Implement reports and Excel using `CONFIRMED` records only. Support filters for year, month, week, and company. The report month is the month containing `week_end_date`. Excel includes Summary, Records, Details, and Cash sheets; there is no AI extraction sheet.

**Checkpoint:** Filters, totals, company breakdowns, included records, and all four sheets agree with confirmed database data.

**Commit:** `feat: implement reports and Excel export`

### 8.15 - Frontend

Implement the approved UI/UX:

```text
Login
  -> Dashboard
  -> Records (new, list, review, detail)
  -> Companies
  -> Reports
  -> Prepare delivery
```

Respect role-based actions and the frontend boundary:

```text
UI/hooks -> services -> Next.js BFF -> Laravel API
```

Deliver in small module-based commits rather than one large frontend change.

**Checkpoint:** The defined role-specific screens and core actions work against the implemented APIs.

### 8.16 - End-to-End Integration

Verify the full business journey:

```text
Supplier sends image through WhatsApp
  -> Editor downloads image
  -> Upload
  -> AI
  -> Review
  -> Confirm
  -> Prepare delivery
  -> Word
  -> Reports
  -> Excel
```

WhatsApp remains a manual source in the MVP; no direct integration is added.

**Checkpoint:** The complete workflow succeeds with persisted data and correct role enforcement. This is the MVP end-to-end milestone.

### 8.17 - Testing

Cover normal flows:
- readable image;
- multiple movements;
- human review and confirmation;
- valid delivery;
- Word generation;
- Excel generation.

Cover failure and authorization flows:
- unreadable image;
- invalid AI JSON;
- unknown company;
- missing field;
- incorrect commission;
- incorrect return;
- mismatched cash;
- `VIEWER` attempting a mutation;
- canceled record excluded from official reports;
- confirmed record modified and returned to review;
- delivery prepared with an older amount;
- missing image blocking Word generation.

**Checkpoint:** Relevant automated tests and regression checks pass; unresolved failures block release.

### 8.18 - Deployment

Deploy on the office LAN. The application server hosts Next.js, Laravel, PostgreSQL, the queue worker, and private storage. The AI PC hosts Ollama and the vision model; Laravel communicates with it over the LAN.

**Checkpoint:** Complete a real workflow from another office workstation, including authorized access to the application and the AI service.

**Commit:** `chore: deploy MVP to office network`

## 5. Definition of Done

Every module must have:

- implemented scope matching the approved documentation;
- validated inputs and explicit error behavior;
- authorization where relevant;
- tests appropriate to its risk and behavior;
- a successful checkpoint demonstration;
- documented configuration or operational requirements where needed.

For Laravel backend modules, also require:

- routes, controllers, requests, resources, policies, jobs, models, and services are placed according to the documented application boundaries;
- migrations are reversible where practical and do not rewrite applied history;
- API behavior and error responses are documented;
- database transactions protect multi-entity workflows;
- authorization tests cover both allowed and forbidden roles;
- the module's implementation status is updated only after the checkpoint passes.

No module may bypass backend validation, role authorization, the human confirmation rule, or confirmed-only official reporting.
