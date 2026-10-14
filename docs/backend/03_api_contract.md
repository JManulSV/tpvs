# Backend API Contract

## Status

The health, authentication, and Companies endpoints are implemented and covered by backend feature tests. Other business resource endpoints described in the global architecture remain planned.

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

## Records Contract — Phase 8.6 (Defined, Not Yet Implemented)

The endpoints below define the manual-record workflow and are planned until Laravel routes, policies, resources, and feature tests are implemented.

| Method | Path | Purpose | Authentication / authorization |
| --- | --- | --- | --- |
| `GET` | `/api/records` | List records using search, filters, and pagination. | Authenticated `EDITOR` or `VIEWER` |
| `POST` | `/api/records` | Create a manual record and its details. | Authenticated `EDITOR` only |
| `GET` | `/api/records/{id}` | Retrieve a record and ordered details. | Authenticated `EDITOR` or `VIEWER` |
| `PUT` | `/api/records/{id}` | Replace all editable record fields and details atomically. | Authenticated `EDITOR` only; only `MANUAL` + `NEEDS_REVIEW` |
| `POST` | `/api/records/{id}/confirm` | Validate and confirm a manual record. | Authenticated `EDITOR` only; only `MANUAL` + `NEEDS_REVIEW` |

All endpoints use the authenticated Sanctum session. Laravel remains authoritative for authorization, business validation, calculated amounts, and state transitions. Frontend visibility does not grant permission.

### Record List, Search, and Filters

`GET /api/records` accepts:

| Parameter | Rules | Default |
| --- | --- | --- |
| `search` | Optional string, trimmed, maximum 255 characters; case-insensitive partial match against company name, operation number, affiliation, or branch. | No filter |
| `company_id` | Optional existing company identifier. | No filter |
| `year` | Optional ISO week-year. | No filter |
| `month` | Optional integer from 1 to 12; filters by the month containing `week_end_date`. | No filter |
| `week` | Optional ISO week number valid for the supplied ISO week-year; requires `year`. | No filter |
| `status` | Optional valid Record status. | No filter |
| `source` | Optional `IMAGE` or `MANUAL`. | No filter |
| `page` | Integer greater than or equal to 1. Invalid values return `422`. | `1` |
| `per_page` | Integer from 1 to 100. Invalid values return `422`. | `15` |

When a `week` filter is supplied, `year` is required so the period is unambiguous. Results are ordered by `updated_at` descending and use Laravel's standard paginated response (`data`, `links`, `meta`). List resources include record identity, company, source, status, period, affiliation, branch, calculated income/commission/return totals, and the ordered movement summary needed by search and display. Financial totals are calculated from `RecordDetail`; they are not stored as Record source-of-truth columns.

### Manual Record Create and Replace Payload

`POST /api/records` accepts the aggregate fields below and returns `201` with `{ "data": { ...record } }`. `PUT /api/records/{id}` accepts the same complete editable aggregate plus a required `reason`; it replaces, rather than patches, the record's full detail collection. The JSON example shows the common aggregate fields; include `reason` only for `PUT`. Neither request accepts `source`, `status`, `order`, `return_amount`, or aggregate totals as client-controlled values.

```json
{
  "company_id": 1,
  "year": 2026,
  "week": 38,
  "week_start_date": "2026-09-14",
  "week_end_date": "2026-09-20",
  "affiliation": "AFF-123",
  "branch": "Guadalajara",
  "details": [
    {
      "operation_number": "10231",
      "income": "10000.00",
      "commission_percentage": "2.00"
    },
    {
      "operation_number": "10232",
      "income": "5000.00",
      "commission": "100.00"
    }
  ]
}
```

For replacement, add the required top-level field `"reason": "Corrección de captura"`. Each detail requires an operation number and non-negative `income` with at most two decimal places. Exactly one of `commission_percentage` or fixed-amount `commission` must be supplied. The percentage is between 0 and 100 with at most two decimal places. A fixed commission is non-negative, has at most two decimal places, and cannot exceed income. Details must contain at least one item. Their `order` is assigned from array order, starting at 1.

For percentage input, Laravel calculates `commission` using decimal arithmetic and `ROUND_HALF_UP` to two decimal places. For fixed commission input, Laravel stores the supplied amount and sets `commission_percentage` to `null`. Laravel always calculates `return_amount = income - commission`; the client must not submit a return amount. Zero-valued income, commission, and return are permitted by the current schema, provided all rules above pass.

`year` and `week` identify an ISO week. The supplied dates must be exactly that ISO week’s Monday and Sunday, and the `year` is the ISO week-year (which can differ from a date's calendar year around New Year). `company_id`, `affiliation`, and `branch` are required. Laravel assigns `source=MANUAL` and initial `status=NEEDS_REVIEW`. Creation persists the record and all details in one transaction and creates no Image or AIProcessing rows and dispatches no AI job.

On replacement, Laravel accepts only a `MANUAL` record in `NEEDS_REVIEW`. It validates the complete new aggregate before atomically updating the Record and replacing its details. It creates one generic `History` event with `field_name=manual_record_updated`, null `previous_value` and `new_value`, the authenticated `user_id`, and the supplied reason. The aggregate update and History event share one database transaction.

### Record Resource and Detail

`GET /api/records/{id}` returns `200` with `{ "data": { ...record } }`, including `id`, `company`, `source`, `status`, `year`, `week`, `week_start_date`, `week_end_date`, `affiliation`, `branch`, ordered `details`, and calculated aggregate totals. Each detail contains `id`, `operation_number`, `income`, `commission_percentage`, `commission`, `return_amount`, and `order`. A missing record returns `404`. No private file paths are exposed.

The list resource uses the same record fields and may omit detail fields not needed by the list, but it includes the company, state, period, source, and calculated totals. Response types must be kept consistent between list and detail where a field has the same meaning.

### Confirm Manual Record

`POST /api/records/{id}/confirm` accepts an empty JSON object. Laravel accepts only a `MANUAL` record in `NEEDS_REVIEW`, revalidates its business data, and transactionally updates status to `CONFIRMED` with a History row for `status` (`NEEDS_REVIEW` to `CONFIRMED`), the authenticated user, and the fixed reason `Registro manual confirmado`. Success returns `200` with the updated record resource. Confirmation is an explicit human action; no creation or update request can set `CONFIRMED` directly.

### Record Errors

- Unauthenticated requests return `401`; a `VIEWER` mutation returns `403`; missing resources return `404`.
- Shape, relationship, ISO-period, and financial input errors return Laravel's standard `422` validation structure, with errors keyed to the relevant field or detail index where possible.
- A disallowed state/source transition returns `409` with a stable business code. Use `RECORD_UPDATE_BLOCKED` for replacement and `RECORD_CONFIRMATION_BLOCKED` for confirmation, with a stable reason in `details` such as `INVALID_STATE`, `INVALID_SOURCE`, or `BUSINESS_VALIDATION_FAILED`.
- All multi-row create, replace, and confirmation operations roll back completely if persistence or History creation fails.
