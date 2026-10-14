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
