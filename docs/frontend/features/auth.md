# Authentication Feature

## Status

**Planned.** Authentication, roles, and session handling are not implemented in the current frontend/backend foundation. The existing BFF forwards selected cookies and headers but does not provide an authenticated session contract.

## Goal and Scope

Provide login and logout, expose the current authenticated user to the application shell, and handle expired or invalid sessions. Roles are `EDITOR` and `VIEWER`; their authoritative permissions are documented in [`../../backend/05_validation_authorization.md`](../../backend/05_validation_authorization.md) and [`../../10_ui_ux.md`](../../10_ui_ux.md).

## Routes and Screens

Target routes, subject to the approved UX and final routing implementation:

```text
/login
/(app)/*    authenticated application route group; group name is not part of the URL
```

Login should provide field validation, pending state, invalid-credential feedback, and a clear successful navigation outcome. Logout should clear the visible user state and return to the unauthenticated experience.

## Session Contract Prerequisite

Before implementation, reconcile the JWT/HttpOnly-cookie statement in [`../../04_technical_architecture.md`](../../04_technical_architecture.md) with the BFF requirement in [`../../11_technical_roadmap.md`](../../11_technical_roadmap.md). Specify credential ownership, cookie settings, CSRF behavior, current-user endpoint, logout behavior, and expiry handling in the backend API/session contract and [`../03_api_bff_and_sessions.md`](../03_api_bff_and_sessions.md).

Do not store bearer tokens in local storage or expose them to Client Components. Do not rely on route hiding as authorization; Laravel must enforce permissions.

## Target API

The roadmap calls for login, logout, and current-user (`me`) endpoints. Their precise paths, payloads, response shapes, and error codes remain subject to the implemented Laravel API contract. Verify `backend/routes/` and backend tests before consuming them.

## Acceptance Criteria

- A valid user can log in and reach the intended application route.
- Invalid credentials produce a safe, useful message.
- Refreshing an authenticated page preserves the session according to the agreed cookie/session design.
- Logout ends the session and removes user-scoped client cache.
- Expired or invalid sessions return the user to login without revealing protected data.
- Server-side authorization remains effective even if the UI is manipulated.
- `EDITOR` and `VIEWER` UI affordances follow the approved permission matrix.

## Related Documents

- `../03_api_bff_and_sessions.md`
- `../02_routing_and_rendering.md`
- [`../../04_technical_architecture.md`](../../04_technical_architecture.md)
- [`../../backend/03_api_contract.md`](../../backend/03_api_contract.md)
- [`../../backend/05_validation_authorization.md`](../../backend/05_validation_authorization.md)
- [`../../backend/11_security.md`](../../backend/11_security.md)
- [`../../11_technical_roadmap.md`](../../11_technical_roadmap.md) (phase 8.4)
