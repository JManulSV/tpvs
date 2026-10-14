# Authentication Feature

## Status

**Backend and core frontend/BFF authentication implemented; checkpoint verification and documentation alignment remain in progress.** Laravel Sanctum session authentication is implemented. The frontend BFF provides the CSRF bridge and cookie/header forwarding; `/login`, protected `/dashboard`, current-user lookup, logout, and session-aware root/login redirects are implemented. See the manual acceptance flow in [`../06_testing.md`](../06_testing.md).

## Goal and Scope

Provide login and logout, expose the current authenticated user to the application shell, and handle expired or invalid sessions. Roles are `EDITOR` and `VIEWER`; their authoritative permissions are documented in [`../../backend/05_validation_authorization.md`](../../backend/05_validation_authorization.md) and [`../../10_ui_ux.md`](../../10_ui_ux.md).

## Routes and Screens

Implemented authentication routes:

```text
/login
/(app)/*    authenticated application route group; group name is not part of the URL
```

Login provides field validation, pending state, invalid-credential feedback, and a successful navigation outcome. Logout clears the visible user state and returns to the unauthenticated experience. Authenticated visits to `/login` redirect to `/dashboard`; `/` redirects according to session state.

## Approved Backend Session Contract

Laravel uses Sanctum stateful SPA authentication and owns the database-backed session. It sets the `tpvs_session` browser-session cookie with `HttpOnly` and `SameSite=Lax`. Secure-cookie mode is enabled by default in production, which must use HTTPS; local HTTPS requires `SESSION_SECURE_COOKIE=true`. Manual HTTP checks confirmed the cookie expires when the browser closes and has `HttpOnly`; the server session expires after 120 minutes of inactivity. Login regenerates the session and logout invalidates it with HTTP `200` and `{ "message": "Logged out successfully." }`.

The browser calls the same-origin Next.js BFF only. The BFF exposes `GET /api/auth/csrf-cookie` as a bridge to Laravel's `GET /sanctum/csrf-cookie`, preserves `Set-Cookie`, and forwards session/CSRF cookies, validated `Origin`/`Referer`, and `X-XSRF-TOKEN` on state-changing requests. If the browser omits `Origin`, the BFF supplies a configured trusted frontend origin. Invalid CSRF returns `419`; the frontend refreshes the token and requires explicit user retry without replaying the mutation. Do not expose session credentials to Client Components, URLs, or local storage. Laravel remains the authorization authority.

## Backend API Contract

The implemented Laravel endpoints are:

| Method | Path | Request | Result |
| --- | --- | --- | --- |
| `POST` | `/api/auth/login` | `{ "email": "...", "password": "..." }` | Authenticates, regenerates session, returns current user |
| `POST` | `/api/auth/logout` | No body | Invalidates the session; `200` with a success message |
| `GET` | `/api/auth/me` | No body | `{ "data": { "id", "name", "email", "role" } }` |

Expected failures are `401` for invalid credentials or missing/expired session, `403` for forbidden role actions, `419` for CSRF failure, and `422` for invalid input. Backend feature tests cover these responses and both role outcomes. The BFF must preserve these statuses.

## Acceptance Criteria

- A valid user can log in and reach the intended application route.
- Invalid credentials produce a safe, useful message.
- Refreshing an authenticated page preserves the session according to the agreed cookie/session design.
- Logout ends the session and removes user-scoped client cache.
- Expired or invalid sessions return the user to login without revealing protected data.
- Server-side authorization remains effective even if the UI is manipulated.
- `EDITOR` and `VIEWER` UI affordances follow the approved permission matrix.
- Authenticated views use Laravel `me` as the source of identity and clear user-scoped client state after logout or identity change.
- The BFF CSRF bridge, session/cookie forwarding, login UI, and protected navigation pass the automated and manual checks documented in `../06_testing.md`.

## Related Documents

- `../03_api_bff_and_sessions.md`
- `../02_routing_and_rendering.md`
- [`../../04_technical_architecture.md`](../../04_technical_architecture.md)
- [`../../backend/03_api_contract.md`](../../backend/03_api_contract.md)
- [`../../backend/05_validation_authorization.md`](../../backend/05_validation_authorization.md)
- [`../../backend/11_security.md`](../../backend/11_security.md)
- [`../../11_technical_roadmap.md`](../../11_technical_roadmap.md) (phase 8.4)
