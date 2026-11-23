# Backend Conventions

Laravel structure, coding conventions, persistence, queues, security, and rules for AI coding assistants. Business rules live in [`../business-rules.md`](../business-rules.md); the data model in [`../data-model.md`](../data-model.md).

## 1. Application Structure

```text
backend/app/
├── Console/Commands/
├── Domain/            # Business code by domain
│   ├── AI/            # Actions/, AiJobDispatcher, AiProcessingRunner, DTO/, Normalization/, validators
│   ├── Dashboard/
│   ├── Deliveries/    # Actions/, DeliveryInputNormalizer, CashBreakdown, DeliveryReturnPolicy, Exceptions/
│   ├── Documents/     # Actions/, Word and Excel builders, eligibility, TemporaryFile, Exceptions/
│   ├── Images/        # Actions/, RecordImageStorage, Exceptions/
│   ├── Records/       # Actions/, RecordQuery, RecordFilters, BusinessValidator, history, Exceptions/
│   ├── Reports/
│   └── Shared/        # Money, Exceptions/DomainRuleException, History/ (SnapshotDiff, CanonicalValue)
├── Enums/             # RecordStatus, RecordSource, AiProcessingStatus, UserRole
├── Http/
│   ├── Controllers/
│   ├── Middleware/
│   ├── Requests/
│   └── Resources/
├── Jobs/              # ProcessImageJob (queue settings only; delegates to AiProcessingRunner)
├── Models/
├── Policies/
└── Providers/
```

| Location | Responsibility |
| --- | --- |
| `routes/` | Route declarations only |
| `app/Http/Controllers/` | HTTP coordination |
| `app/Http/Requests/` | Payload validation and request-level authorization |
| `app/Http/Resources/` | Stable JSON response representations |
| `app/Domain/<Domain>/Actions/` | One class per use case with a `handle()` method |
| `app/Domain/<Domain>/` | Domain services, queries, and policies used by the actions |
| `app/Domain/<Domain>/Exceptions/` | `DomainRuleException` subclasses with named constructors |
| `app/Domain/Shared/` | Code shared by several domains, only where there was real duplication |
| `app/Models/` | Eloquent persistence, casts, relationships, simple scopes |
| `app/Policies/` | Resource authorization |
| `app/Jobs/` | Asynchronous or retryable work |
| `database/migrations/` | All schema changes |
| `tests/Feature/`, `tests/Unit/` | HTTP/workflow behavior and isolated logic |
| `tests/Support/Concerns/` | Test helper traits (`CreatesUsers`, `UploadsImages`, `CreatesDeliveries`, …) |

Use domain ownership for business code. Do not create a generic service directory that collects unrelated workflows, and create new folders only when a real feature needs them.

### Actions

A use case lives in `Domain/<Domain>/Actions/` as one class with a `handle()` method, for example `Records/Actions/ConfirmRecord` or `Deliveries/Actions/PrepareDelivery`. The action opens its own transaction, takes the locks the use case needs (`Record::findLockedOrFail()`, `lockForUpdate()`), writes History, and returns the model. Controllers resolve actions by method injection.

A controller only authorizes, calls `handle()`, and wraps the result in a Resource. It does not open transactions or lock rows. HTTP-only concerns stay in the controller: the response status, download headers, and the safe `500` message when an unexpected error happens after a file was stored.

### Domain errors

**Controllers never build business errors by hand.** An action throws a `DomainRuleException` subclass, and `bootstrap/app.php` renders it as:

```json
{ "message": "...", "code": "...", "details": { "reason": "...", "...": "..." } }
```

with the status the subclass declares. These exceptions are not reported to the log. Each domain keeps its subclasses in `Exceptions/` with named constructors (`RecordConfirmationBlocked::invalidState()`, `DeliveryPreparationBlocked::cashMismatch(...)`). Error codes that the AI job persists in `AIProcessing` are data, not HTTP responses, and do not use this hierarchy.

### Domain/Shared

- **`Money`**: an immutable amount in integer cents. Parsing truncates to two decimals and never rounds; `applyPercentage()` rounds half up to the cent; `floorToWholePeso()` floors. No code converts money through floats or keeps its own cents helpers. Display formatting (`$1,234.50`, Excel cells) belongs to `Domain/Documents`.
- **`Exceptions/DomainRuleException`**: the base class for business-rule errors described above.
- **`History/SnapshotDiff`** and **`History/CanonicalValue`**: the snapshot diff and value canonicalization used by `RecordHistoryService` and `DeliveryHistoryService`.

## 2. Laravel Conventions

- Follow PSR-12 and the repository's Laravel Pint configuration.
- Use strict, explicit names for classes, methods, states, and fields.
- Prefer dependency injection over service location.
- Read configuration through `config()`; never call `env()` outside configuration files.
- Keep changes small and verifiable.

Avoid:

- business logic in route closures or large controllers;
- external API calls from Eloquent models;
- unvalidated request data;
- hidden writes during read operations;
- generic abstractions without a current use case.

## 3. Database and Migrations

- PostgreSQL is the source of truth. Excel is never an input or an official total source.
- Use a Laravel migration for every schema change; do not edit a migration already applied in a shared or deployed environment.
- Define foreign keys, indexes, and check constraints explicitly; use database constraints for invariants that must hold regardless of application code.
- Use decimal types for money; never use floating-point money arithmetic. Do not persist calculated totals as authoritative values.
- Use Query Builder for complex reporting queries when it improves clarity; use raw SQL only when necessary and document why.
- Company names are unique case-insensitively after trimming (PostgreSQL expression index).

**Transactions:** use one whenever a use case changes several related rows or writes an audit entry with the change — record creation with details, state transitions with History, delivery updates with denominations.

Schema changes require tests for foreign keys, uniqueness, nullability, precision, check constraints, and the related workflow.

## 4. Queues, Jobs, and AI Integration

Image extraction runs on the asynchronous database queue; `QUEUE_CONNECTION=sync` must not be used for it. The browser never calls Ollama — only Laravel does, through `ProcessImageJob` and an injectable provider interface.

```text
ProcessImageJob -> AI provider interface -> Ollama provider
  -> extraction contract validation -> business validation -> NEEDS_REVIEW or ERROR
```

- One logical `AIProcessing` row per Image stores provider, model, latest raw result (backend-private), normalized result, status, timestamps, and safe failure information. A manual retry replaces the current result/error on the same row.
- `ProcessImageJob` keeps only its queue settings (`$tries`, `$timeout`) and `failed()`; `Domain/AI/AiProcessingRunner` runs the attempt. Issues are built with `Domain/AI/DTO/ExtractionIssue`, and raw values are normalized by `Domain/AI/Normalization/ExtractedValueParser`.
- `Domain/AI/AiJobDispatcher` dispatches the job after the Record/Image/AIProcessing transaction commits. A dispatch failure is recorded as `Record.ERROR` and `AIProcessing.ERROR` with the `AI_JOB_DISPATCH_FAILED` issue, and the endpoint still answers `202`.
- The job locks its aggregate before provider work; duplicate or stale jobs must not call Ollama.
- The initial cycle allows up to three provider calls, retrying only connection errors, timeouts, and HTTP 5xx. Structural failures and HTTP 4xx are terminal.
- A manual retry (only from `ERROR`) makes exactly one provider call.
- A usable extraction moves to `Record.NEEDS_REVIEW` / `AIProcessing.COMPLETED`. **No AI job ever moves a Record to `CONFIRMED`**, and this workflow writes no `History` rows.
- Timeouts: HTTP client ≤ 300 s, worker `--timeout=330`, `DB_QUEUE_RETRY_AFTER=360`. The worker leaves retries to the job's `$tries`.

## 5. Security

- `APP_DEBUG=false` outside local development; never commit `.env` or secrets.
- Sanctum stateful SPA authentication with a database session; no JWTs or personal access tokens for the browser flow.
- Session cookie `tpvs_session`: `HttpOnly`, `SameSite=Lax`, `Secure` under HTTPS, browser-session lifetime, 120-minute inactivity timeout.
- CSRF on every state-changing request; the BFF forwards the session cookie, CSRF cookie/header, and origin headers.
- `401` for unauthenticated, `403` for role-forbidden. Validate all input with Form Requests; authorize with Policies; add authorization tests for every mutation.
- Never expose PostgreSQL, private storage, or Ollama to browsers. Never log passwords, tokens, keys, image contents, or provider payloads.

**Uploads:** accept only non-empty JPEG, PNG, or WebP up to 10 MiB, detecting MIME from bytes. Store under a server-generated UUID key on a dedicated private disk with no public links. Never return paths, storage keys, or public URLs. Serve bytes only from authenticated routes authorized against the parent Record, with `Content-Disposition: inline` and `Cache-Control: private, no-store`. Only `EDITOR` mutates images; both roles view images on non-canceled Records. Replacement is rejected for canceled Records and while the Image has AIProcessing attempts. Write the new file first, update metadata in a transaction under a Record lock, delete the new file if the transaction fails, and delete the old file only after commit.

**AI:** provider responses are untrusted input. Only an `EDITOR` with valid CSRF may create an IMAGE record or request a retry; client-supplied extracted or financial values are never accepted. `AI_OLLAMA_URL` stays server-side. The raw response, image bytes, storage keys, raw provider errors, and stack traces never appear in API resources or public logs — expose stable error codes only.

**Auditability:** meaningful changes to records and delivery preparation record user, reason, before value, after value, and timestamp (see the data model).

## 6. Rules for AI Coding Assistants

- Read the actual files under `backend/` before editing; verify status in code, never from documentation alone.
- Do not invent routes, models, migrations, or dependencies.
- Keep routes declarative and controllers thin; use validation, policies, transactions, jobs, and tests where appropriate.
- Add or update tests with every behavior change, and run them against `tpvs_test` (see [`testing.md`](testing.md)).
- Never allow AI extraction to confirm a financial record.
- Update documentation only after code and tests are verified.
