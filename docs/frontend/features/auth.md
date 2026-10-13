# Authentication Feature

## Status

**Planned.** Backend authentication and frontend session handling are not yet implemented. The approved backend contract uses Laravel Sanctum stateful SPA authentication; the existing BFF proxy still needs the CSRF bridge and verified cookie/header forwarding.

## Goal and Scope

Provide login and logout, expose the current authenticated user to the application shell, and handle expired or invalid sessions. Roles are `EDITOR` and `VIEWER`; their authoritative permissions are documented in [`../../backend/05_validation_authorization.md`](../../backend/05_validation_authorization.md) and [`../../10_ui_ux.md`](../../10_ui_ux.md).

## Routes and Screens

Target routes, subject to the approved UX and final routing implementation:

```text
/login
/(app)/*    authenticated application route group; group name is not part of the URL
```

Login should provide field validation, pending state, invalid-credential feedback, and a clear successful navigation outcome. Logout should clear the visible user state and return to the unauthenticated experience.

## Approved Backend Session Contract

Laravel uses Sanctum stateful SPA authentication and owns the database-backed session. It sets the `tpvs_session` browser-session cookie with `HttpOnly`, `SameSite=Lax`, and `Secure` under HTTPS. The cookie expires when the browser closes; the server session expires after 120 minutes of inactivity. Login regenerates the session and logout invalidates it.

The browser calls the same-origin Next.js BFF only. The BFF must expose `GET /api/auth/csrf-cookie` to proxy Laravel's Sanctum CSRF-cookie endpoint, preserve `Set-Cookie`, and forward session/CSRF cookies, `Origin`/`Referer`, and `X-XSRF-TOKEN` on state-changing requests. Do not expose a bearer token or session credential to Client Components, URLs, or local storage. Do not rely on route hiding as authorization; Laravel enforces permissions.

## Target API

The planned Laravel endpoints are:

| Method | Path | Request | Result |
| --- | --- | --- | --- |
| `POST` | `/api/auth/login` | `{ "email": "...", "password": "..." }` | Authenticates, regenerates session, returns current user |
| `POST` | `/api/auth/logout` | No body | Invalidates the session |
| `GET` | `/api/auth/me` | No body | `{ "data": { "id", "name", "email", "role" } }` |

Expected failures are `401` for invalid credentials or missing/expired session, `403` for forbidden role actions, `419` for CSRF failure, and `422` for invalid input. Confirm the actual implementation and tests before consuming the contract. The logout success status/body will be documented after backend implementation.

## Acceptance Criteria

- A valid user can log in and reach the intended application route.
- Invalid credentials produce a safe, useful message.
- Refreshing an authenticated page preserves the session according to the agreed cookie/session design.
- Logout ends the session and removes user-scoped client cache.
- Expired or invalid sessions return the user to login without revealing protected data.
- Server-side authorization remains effective even if the UI is manipulated.
- `EDITOR` and `VIEWER` UI affordances follow the approved permission matrix.
- Authenticated views use Laravel `me` as the source of identity and clear user-scoped client state after logout or identity change.

## Related Documents

- `../03_api_bff_and_sessions.md`
- `../02_routing_and_rendering.md`
- [`../../04_technical_architecture.md`](../../04_technical_architecture.md)
- [`../../backend/03_api_contract.md`](../../backend/03_api_contract.md)
- [`../../backend/05_validation_authorization.md`](../../backend/05_validation_authorization.md)
- [`../../backend/11_security.md`](../../backend/11_security.md)
- [`../../11_technical_roadmap.md`](../../11_technical_roadmap.md) (phase 8.4)
