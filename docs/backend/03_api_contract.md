# Backend API Contract

## Status

The health, authentication, Companies, and Records endpoints are implemented and covered by backend feature tests. Image endpoints described below for phase 8.7 remain planned until implemented and verified.

## Base Path

Application API routes use `/api`.

## Implemented Health Endpoints

### Application Health

```http
GET /api/health
```

Current response:

```json
{
  "status": "ok",
  "service": "backend"
}
```

### Framework Health

```http
GET /up
```

This route is Laravel's framework health route configured in `backend/bootstrap/app.php`. It is separate from the application API health response.

## Planned API Rules

- Use nouns for resource paths.
- Use HTTP methods according to their standard meaning.
- Validate every incoming payload.
- Authorize mutations independently of frontend visibility.
- Return stable JSON structures through API Resources when resources exist.
- Do not expose private storage paths.
- Mark undocumented endpoints as planned, not implemented.

## Implemented Authentication Contract

Authentication uses Laravel Sanctum's stateful SPA mode and Laravel's database-backed session. It does not issue a JWT or personal access token.

| Method | Path | Purpose | Authentication |
| --- | --- | --- | --- |
| `POST` | `/api/auth/login` | Authenticate using `email` and `password`; regenerate the session and return the current user. | Public; CSRF required for stateful browser requests |
| `POST` | `/api/auth/logout` | Invalidate the session and regenerate the CSRF token. Returns `200` and a success message. | Required; CSRF required for stateful browser requests |
| `GET` | `/api/auth/me` | Return the authenticated user's public identity. | Required |

The current-user response is:

```json
{
  "data": {
    "id": 1,
    "name": "Test Editor",
    "email": "editor@test.com",
    "role": "EDITOR"
  }
}
```

Laravel sets the `tpvs_session` cookie with `HttpOnly`, `SameSite=Lax`, and no persistent expiry so it expires when the browser closes. Secure-cookie mode defaults on in production, which must use HTTPS; local HTTPS deployments must set `SESSION_SECURE_COOKIE=true`. The server session expires after 120 minutes of inactivity. Manual HTTP verification confirmed `tpvs_session` has no expiry attribute and is `HttpOnly`; `XSRF-TOKEN` is issued with a two-hour expiry and is not `HttpOnly`. The BFF must preserve `Set-Cookie` and forward `Cookie`, `Origin`/`Referer`, and `X-XSRF-TOKEN` as required.

The BFF's planned browser-facing CSRF bridge is `GET /api/auth/csrf-cookie`, which proxies Laravel's `GET /sanctum/csrf-cookie` endpoint. The browser receives `XSRF-TOKEN` through the same-origin BFF and sends its decoded value in `X-XSRF-TOKEN` on state-changing requests. Manual HTTP verification confirmed valid CSRF allows login and invalid CSRF is rejected with `419`.

Authentication failures return `401`, forbidden role actions return `403`, and invalid input returns Laravel's standard `422` validation response.

Authenticated users may access permitted reads. Mutation routes require the `EDITOR` role. `VIEWER` mutation attempts return `403`.

## Planned Error Shape

Validation errors should use Laravel's standard structure:

```json
{
  "message": "The given data was invalid.",
  "errors": {
    "field": [
      "The field is required."
    ]
  }
}
```

Business errors should expose a stable code when implemented:

```json
{
  "message": "The record cannot be confirmed.",
  "code": "RECORD_CONFIRMATION_BLOCKED",
  "details": {
    "reason": "FINANCIAL_MISMATCH"
  }
}
```

## Implemented Companies Contract — Phase 8.5

The company endpoints below are implemented and covered by `CompaniesApiTest`.

| Method | Path | Purpose | Authentication / authorization |
| --- | --- | --- | --- |
| `GET` | `/api/companies` | List companies with optional search and pagination. | Authenticated `EDITOR` or `VIEWER` |
| `GET` | `/api/companies/{id}` | Retrieve one company. | Authenticated `EDITOR` or `VIEWER` |
| `POST` | `/api/companies` | Create a company. | Authenticated `EDITOR` only |

### List and Search

`GET /api/companies` accepts these query parameters:

| Parameter | Rules | Default |
| --- | --- | --- |
| `search` | Optional string, trimmed before use, maximum 255 characters. Matches a substring of the company name without case sensitivity. | No filter |
| `page` | Integer greater than or equal to 1. Invalid values return `422`. | `1` |
| `per_page` | Integer from 1 to 100. Invalid values return `422`. | `15` |

Results are ordered by company name ascending and use Laravel's standard paginated JSON Resource response, with `data`, `links`, and `meta`. Each item contains `id`, `name`, and `records_count`. `records_count` counts associated records with status `CONFIRMED` only; it is a query result, not persisted company data.

### Detail

`GET /api/companies/{id}` returns `200` with `{ "data": { "id": 1, "name": "Acme" } }`. It returns `404` when the company does not exist. The detail response does not include `records_count`.

### Creation and Name Uniqueness

`POST /api/companies` accepts `{ "name": "Acme" }`. The name is required and may contain at most 255 characters. Laravel trims leading and trailing whitespace before persistence. Names that are equivalent after trimming and case-insensitive comparison are duplicates and return the standard `422` validation response. PostgreSQL enforces the same invariant with a unique expression index on `LOWER(BTRIM(name))`, including for concurrent writes. A `VIEWER` receives `403` for this mutation.

Successful creation returns `201` with `{ "data": { "id": 1, "name": "Acme" } }`.

## Records Contract — Phase 8.6 / SPEC 07 (Implemented) and SPEC 09 (Phase 8.10 contract)

The endpoints below implement the manual-record workflow for phase 8.6 and are covered by Laravel feature tests. SPEC 09 extends review, correction, confirmation, and cancellation as described below; its implementation status is tracked in the roadmap.

| Method | Path | Purpose | Authentication / authorization |
| --- | --- | --- | --- |
| `GET` | `/api/records` | List records using search, filters, and pagination. | Authenticated `EDITOR` or `VIEWER` |
| `POST` | `/api/records` | Create a manual record and its details. | Authenticated `EDITOR` only |
| `GET` | `/api/records/{id}` | Retrieve a record and ordered details. | Authenticated `EDITOR` or `VIEWER` |
| `PUT` | `/api/records/{id}` | Replace all editable record fields and details atomically. | Authenticated `EDITOR` only; `MANUAL` or `IMAGE` + `NEEDS_REVIEW` |
| `POST` | `/api/records/{id}/confirm` | Validate and confirm a review-required record. | Authenticated `EDITOR` only; `NEEDS_REVIEW` |
| `POST` | `/api/records/{id}/cancel` | Cancel a review-required or failed record with a reason. | Authenticated `EDITOR` only; `NEEDS_REVIEW` or `ERROR` |

All endpoints use the authenticated Sanctum session. Laravel remains authoritative for authorization, business validation, calculated amounts, and state transitions. Frontend visibility does not grant permission.

### Record List, Search, and Filters

`GET /api/records` accepts:

| Parameter | Rules | Default |
| --- | --- | --- |
| `search` | Optional string, trimmed, maximum 255 characters; case-insensitive partial match against company name, operation number, affiliation, or branch. | No filter |
| `company_id` | Optional existing company identifier. | No filter |
| `year` | Optional calendar/business year integer from 1 to 9999. | No filter |
| `month` | Optional integer from 1 to 12; filters by the month containing `week_end_date`. | No filter |
| `week` | Optional business week integer from 1 to 53; requires `year` and is not interpreted as ISO. | No filter |
| `status` | Optional valid Record status. | No filter |
| `source` | Optional `IMAGE` or `MANUAL`. | No filter |
| `page` | Integer greater than or equal to 1. Invalid values return `422`. | `1` |
| `per_page` | Integer from 1 to 100. Invalid values return `422`. | `15` |

When a `week` filter is supplied, `year` is required so the business period is unambiguous. The API does not validate `week` as an ISO week. Results are ordered by `updated_at` descending and use Laravel's standard paginated response (`data`, `links`, `meta`). List resources include record identity, nullable company and period, source, status, calculated income/commission/return totals, and the ordered movement summary needed by search and display. Affiliation and branch are returned on movement details, never on the Record root. Financial totals are calculated from `RecordDetail`; they are not stored as Record source-of-truth columns. Search matches affiliation and branch on any movement in the aggregate.

### Record Create and Replace Payload

`POST /api/records` accepts the aggregate fields below and returns `201` with `{ "data": { ...record } }`. `PUT /api/records/{id}` accepts the same complete editable aggregate plus a required `reason` for `MANUAL` or `IMAGE` records in `NEEDS_REVIEW`; it replaces, rather than patches, the record's full detail collection. The JSON example shows the common aggregate fields; include `reason` only for `PUT`. Neither request accepts `source`, `status`, `order`, `return_amount`, or aggregate totals as client-controlled values.

```json
{
  "company_id": 1,
  "year": 2026,
  "week": 38,
  "week_start_date": "2026-09-14",
  "week_end_date": "2026-09-20",
  "details": [
    {
      "operation_number": "10231",
      "affiliation": "AFF-123",
      "branch": "Guadalajara",
      "income": "10000.00",
      "commission_percentage": "2.00"
    },
    {
      "operation_number": "10232",
      "affiliation": "AFF-456",
      "branch": "Zapopan",
      "income": "5000.00",
      "commission": "100.00"
    }
  ]
}
```

For replacement, add the required top-level field `"reason": "Corrección de captura"`. Each detail requires an operation number, affiliation, branch, and non-negative `income` with at most two decimal places. Exactly one of `commission_percentage` or fixed-amount `commission` must be supplied. The percentage is between 0 and 100 with at most two decimal places. A fixed commission is non-negative, has at most two decimal places, and cannot exceed income. Details must contain at least one item. Their `order` is assigned from array order, starting at 1.

For percentage input, Laravel calculates `commission` using decimal arithmetic and `ROUND_HALF_UP` to two decimal places. For fixed commission input, Laravel stores the supplied amount and sets `commission_percentage` to `null`. Laravel always calculates `return_amount = income - commission`; the client must not submit a return amount. Zero-valued income, commission, and return are permitted by the current schema, provided all rules above pass.

`year` and `week` are business period identifiers: `year` is a calendar year and `week` is an integer from 1 to 53. The backend does not interpret them as ISO week data or derive the dates from them. `week_start_date` and `week_end_date` must be valid dates and the start must not be after the end; the range need not start on Monday or end on Sunday. `company_id` and all period fields are required. Each detail requires `operation_number`, `affiliation`, `branch`, `income`, and exactly one commission input. The API rejects `affiliation` or `branch` at the Record root; there is no legacy dual-payload support. Laravel assigns `source=MANUAL` and `status=CONFIRMED` after successful validation. Creation persists the record, details, and a History event in one transaction and creates no Image or AIProcessing rows and dispatches no AI job. The creation History event has `field_name=status`, `previous_value=null`, `new_value=CONFIRMED`, the authenticated user, and fixed reason `Registro manual confirmado`.

On replacement, Laravel accepts a `MANUAL` or `IMAGE` record in `NEEDS_REVIEW`. It validates the complete new aggregate before atomically updating the Record and replacing its details. For IMAGE records, replacement preserves the associated Image and the existing `AIProcessing` row and result; it does not re-run AI, replace the image, or alter the AI evidence. The request must not change `source` or `status`, which remain as they were. Laravel creates one generic `History` event with `field_name=record_updated`, null `previous_value` and `new_value`, the authenticated `user_id`, and the supplied reason. The aggregate update and History event share one database transaction. Rows in `record_details` may be recreated by the full replacement; field-level before/after audit is deferred to phase 8.11.

### Record Resource and Detail

`GET /api/records/{id}` returns `200` with `{ "data": { ...record } }`, including `id`, nullable `company`, `source`, `status`, nullable `year`, `week`, `week_start_date`, and `week_end_date`, ordered `details`, and calculated aggregate totals. The Record root does not expose `affiliation` or `branch`. Each detail contains `id`, nullable `operation_number`, `affiliation`, `branch`, `income`, `commission_percentage`, `commission`, `return_amount`, and `order`; IMAGE extraction may leave detail values null until review. Totals with no calculable value may be absent, whereas a calculated zero is returned as `"0.00"`. MANUAL creation and all confirmation validation remain strict and reject incomplete business data. A missing record returns `404`. No private file paths are exposed.

The list resource uses the same record fields and may omit detail fields not needed by the list, but it includes nullable company/period fields, state, source, calculated totals, and ordered movement summaries including affiliation and branch. Response types must be kept consistent between list and detail where a field has the same meaning. If a provisional IMAGE aggregate has no calculable total, the corresponding total may be absent; zero totals remain `"0.00"`.

### Confirm a Review-Required Record

`POST /api/records/{id}/confirm` accepts an empty JSON object for records that require separate human review, including IMAGE/AI records. Laravel accepts only a record in `NEEDS_REVIEW`, revalidates its business data, and transactionally updates status to `CONFIRMED` with a History row for `status` (`NEEDS_REVIEW` to `CONFIRMED`) and the authenticated user. The fixed reason is `Registro manual confirmado` for MANUAL records and `Registro de imagen confirmado` for IMAGE records. For IMAGE records, confirmation additionally requires an active Image, a company, a complete business period, and complete movement data. AI issues are review signals and do not block confirmation once the persisted aggregate passes backend validation. Manual creation is different: Laravel validates and confirms it atomically when created, because the Editor enters and reviews the data together. Clients cannot choose the status or supply a confirmation reason.

### Cancel a Review-Required or Failed Record

`POST /api/records/{id}/cancel` accepts:

```json
{ "reason": "La imagen no es legible y no se puede reutilizar" }
```

Laravel accepts only a `NEEDS_REVIEW` or `ERROR` record. `reason` is required, trimmed, and limited to 2000 characters. The request must not accept client-controlled `status` or `source`. Laravel locks the Record, rechecks its state, changes it to `CANCELED`, and creates one `History` event for `status` with the previous state (`NEEDS_REVIEW` or `ERROR`), `new_value=CANCELED`, the authenticated user, and the supplied reason, all in one transaction. Cancellation preserves the Record's Image, private file, and `AIProcessing` evidence; it does not delete them. After cancellation, `GET /api/records/{record}/image` returns `409` as described in the Record Images contract. Clients cannot cancel `CONFIRMED`, `PENDING`, `PROCESSING`, or already `CANCELED` records.

### Record Errors

- Unauthenticated requests return `401`; a `VIEWER` mutation returns `403`; missing resources return `404`.
- Shape, relationship, invalid-date, inverted date-range, and financial input errors return Laravel's standard `422` validation structure, with errors keyed to the relevant field or detail index where possible.
- A disallowed state transition returns `409` with a stable business code. Use `RECORD_UPDATE_BLOCKED` for replacement, `RECORD_CONFIRMATION_BLOCKED` for confirmation, and `RECORD_CANCELLATION_BLOCKED` for cancellation, with a stable reason in `details` such as `INVALID_STATE` or `BUSINESS_VALIDATION_FAILED`.
- All multi-row create, replace, confirmation, and cancellation operations roll back completely if persistence or History creation fails.

## Record Images Contract — Phase 8.7 (Implemented)

The endpoints below implement private Record image handling. Uploading an image stores and associates the file only; it does not create an AI processing attempt or dispatch a job.

| Method | Path | Purpose | Authentication / authorization |
| --- | --- | --- | --- |
| `POST` | `/api/records/{record}/image` | Upload the first image or replace the active image. | Authenticated `EDITOR` only; stateful SPA requests require CSRF. |
| `GET` | `/api/records/{record}/image` | Stream the associated private image. | Authenticated `EDITOR` or `VIEWER`. |

### Upload

`POST /api/records/{record}/image` accepts `multipart/form-data` with one required `image` file. Accepted detected formats are JPEG, PNG, and WebP; the file must be non-empty and no larger than 10 MiB (10,485,760 bytes). Validate content-derived MIME in addition to client filename and `Content-Type`. The generated storage key uses a server-generated UUID and extension derived from detected MIME. The sanitized original filename is metadata only and never determines a storage path.

The route stores the file on a dedicated private local disk, not the public disk or a disk selected by `FILESYSTEM_DISK`. It accepts any existing Record except one in `CANCELED`. The endpoint does not accept client-controlled `record_id`, `file_path`, `mime_type`, or `file_size` fields. Laravel derives association and metadata from the route model, detected file, and stored bytes.

First attachment returns `201` with `{ "data": { "id", "original_file_name", "mime_type", "file_size", "created_at" } }`. A permitted replacement returns `200` with the same shape. Neither response exposes `file_path`, disk root, storage key, or a public URL. Upload does not change Record source/status, create `History`/`AIProcessing`, or dispatch jobs.

An existing image may be replaced only while it has no associated `AIProcessing` rows. If attempts exist, return `409` with `code=IMAGE_REPLACEMENT_BLOCKED`; keep the current image and all attempts intact. This protects the association between an AI attempt and the bytes it processed. Policy for replacement after AI processing is deferred to the AI pipeline phase.

Store the new file before changing the database reference. Update/create Image metadata in a database transaction while locking the Record and rechecking state and AI attempts. If database persistence fails, remove the new file and preserve the previous association. Delete the old file only after commit. If post-commit cleanup fails, keep the new association active and log the failure for operator cleanup; do not represent the committed database update as rolled back.

### Authorized Image Response

`GET /api/records/{record}/image` returns `200` with the original bytes, the MIME type detected from stored content, `Content-Disposition: inline`, and `Cache-Control: private, no-store`. The response must not redirect to a public URL or disclose physical paths or storage keys. Byte-range behavior is not part of this contract.

If a non-canceled Record has no associated Image, return `404`. If the Record is `CANCELED`, GET returns `409` with `code=IMAGE_ACCESS_BLOCKED` and `details.reason=RECORD_CANCELED`. Upload/replacement on a canceled Record returns `409` with `code=IMAGE_UPLOAD_BLOCKED` and the same reason.

Unauthenticated requests return `401`; `VIEWER` upload attempts return `403`; missing Records return `404`. Invalid/empty files, unsupported or mismatched content, unsafe/empty sanitized filenames, and files above 10 MiB return Laravel's standard `422` validation response. Storage or metadata persistence failures return `500` with a safe generic message and no exception details or storage paths; the staged file is removed when possible. A post-commit deletion failure does not fail the already committed upload, keeps the new association active, and is logged for operator cleanup. Stateful mutation requests retain Laravel CSRF behavior, including `419` for invalid CSRF.

### Record Image Metadata

The Record resource includes an `image` object or `null`:

```json
{
  "image": {
    "id": 12,
    "original_file_name": "documento.jpg",
    "mime_type": "image/jpeg",
    "file_size": 184320,
    "created_at": "2026-10-15T12:00:00.000000Z"
  }
}
```

The metadata contains no `file_path`, storage key, physical path, or public URL. It is included in Record detail and Record responses where the relation is loaded; a Record without an image reports `image: null`.

## Asynchronous AI Image Extraction — SPEC 08 (Implemented; PostgreSQL verified)

The following routes implement the SPEC 08 asynchronous pipeline. They require Laravel's authenticated Sanctum session. Stateful browser mutations also require valid CSRF.

| Method | Path | Purpose | Authentication / authorization |
| --- | --- | --- | --- |
| `POST` | `/api/records/from-image` | Accept a private image upload, create a provisional IMAGE Record/Image/AIProcessing aggregate, enqueue extraction after commit, and return `202 Accepted`. | Authenticated `EDITOR`; stateful SPA requests require CSRF. |
| `POST` | `/api/records/{record}/ai-processing/retry` | Retry an IMAGE Record whose Record and AIProcessing statuses are `ERROR`, reusing its single AIProcessing row for exactly one provider call. | Authenticated `EDITOR`; stateful SPA requests require CSRF. |
| `GET` | `/api/records/{id}` | Include the safe `ai_processing` result for an IMAGE record. | Authenticated `EDITOR` or `VIEWER`. |

The creation request accepts only `multipart/form-data` with the `image` field. It does not accept company, status, period, detail, or financial values. Upload type, content validation, size, private disk, and safe metadata follow the Record Image contract above. A successful request creates the Record in `PENDING`, Image association, and AIProcessing in `PENDING` atomically and returns `202` with the provisional Record resource. Queue dispatch occurs only after commit. If persistence fails, the newly staged file is removed where possible; if dispatch fails after commit, both processing models are moved to `ERROR` with a safe diagnostic code.

`ai_processing` may expose `status`, `provider`, `model`, `started_at`, `completed_at`, `normalized_extraction`, `issues`, and `unconvertible_numeric_count`. It must never expose `raw_response`, private storage metadata, provider host/configuration, stack traces, or unsanitized exceptions. The latest raw provider response is retained in backend persistence for diagnosis but is not serialized or written to public logs.

An extraction that is structurally usable completes as `AIProcessing.COMPLETED` and `Record.NEEDS_REVIEW`, including when fields are missing or issues remain. Structural failures, non-transient provider errors, or five or more non-convertible non-null numeric values complete as `AIProcessing.ERROR` and `Record.ERROR`; no partial domain values are applied for the five-or-more case. Zero through four such values allow the other usable values to be proposed and still require human review. AI never confirms a Record.

Only an IMAGE Record with an active Image and AIProcessing `ERROR` is eligible for manual retry. Other states return `409` with a stable business code. Automatic retries are limited to transient connection, timeout, and HTTP 5xx failures: at most three provider calls in the initial cycle. A manual retry performs one call per request; Editors may request another after a subsequent `ERROR`. Duplicate jobs must not repeat a completed extraction or overwrite a usable result.

Authentication and authorization errors remain `401` and `403`; invalid CSRF remains `419`; missing Record is `404`; invalid upload is `422`; disallowed retry is `409`. Asynchronous provider errors are persisted and do not change the original `202` response.

The `202` response uses the Record resource envelope (`data`) and initially returns `PENDING` states. The API resource never includes the provider response body, private storage key, or internal error detail.
