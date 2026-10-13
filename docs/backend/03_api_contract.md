# Backend API Contract

## Status

Only the health endpoints are implemented. Authentication and the resource endpoints described below are planned until their backend routes and tests exist.

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

## Planned Authentication Contract

Authentication uses Laravel Sanctum's stateful SPA mode and Laravel's database-backed session. It does not issue a JWT or personal access token.

| Method | Path | Purpose | Authentication |
| --- | --- | --- | --- |
| `POST` | `/api/auth/login` | Authenticate using `email` and `password`; regenerate the session and return the current user. | Public; CSRF required through the BFF |
| `POST` | `/api/auth/logout` | Invalidate the session and regenerate the CSRF token. | Required |
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

Laravel sets the `tpvs_session` cookie with `HttpOnly`, `SameSite=Lax`, `Secure` on HTTPS, and no persistent expiry so it expires when the browser closes. The server session expires after 120 minutes of inactivity. The BFF must preserve `Set-Cookie` and forward `Cookie`, `Origin`/`Referer`, and `X-XSRF-TOKEN` as required.

The BFF's browser-facing CSRF bridge is `GET /api/auth/csrf-cookie`, which proxies Laravel's Sanctum CSRF-cookie endpoint. The browser receives `XSRF-TOKEN` through the same-origin BFF and sends its decoded value in `X-XSRF-TOKEN` on state-changing requests. Laravel rejects invalid or missing CSRF tokens with `419`.

Authentication failures return `401`, forbidden role actions return `403`, and invalid input returns Laravel's standard `422` validation response. Logout response body and exact successful status are defined by the implementation while preserving this contract.

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
