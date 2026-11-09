# Authentication Feature

## Status

**Implemented and verified (2026-10-14).** Laravel Sanctum session authentication and the frontend BFF CSRF/cookie bridge are integrated. `/login`, protected `/dashboard`, current-user lookup, logout, and session-aware root/login redirects passed Vitest and Playwright browser verification against the running Laravel stack for both roles. See [`../06_testing.md`](../06_testing.md) and the phase 8.4 checkpoint in [`../../11_technical_roadmap.md`](../../11_technical_roadmap.md).

## Goal and Scope

Provide login and logout, expose the current authenticated user to the application shell, and handle expired or invalid sessions. Roles are `EDITOR` and `VIEWER`; their authoritative permissions are documented in [`../../backend/05_validation_authorization.md`](../../backend/05_validation_authorization.md) and [`../../10_ui_ux.md`](../../10_ui_ux.md).

## Routes and Screens

Implemented authentication routes:

```text
/login
/(app)/*    authenticated application route group; group name is not part of the URL
```

Login provides field validation, pending state, invalid-credential feedback, and a successful navigation outcome. Logout clears the visible user state and returns to the unauthenticated experience. Authenticated visits to `/login` redirect to `/dashboard`; `/` redirects according to session state.

### Login screen (SPEC 17)

- **Frame:** `frontend/src/app/(auth)/login/page.tsx` renders a full-height card (`rounded-xl`, `border-border`, `shadow-card`) with a 16 px margin on `bg-background`, matching the authenticated shell. The session-validation error state and the `Suspense` fallback keep their previous markup.
- **Hero:** `frontend/src/features/auth/components/login-hero.tsx` is a static Server Component (`<section aria-label="Presentación">`, `bg-hero text-on-hero`) with the platform overline, the headline (a `<p>`, not a heading), the three "Cómo funciona" steps and a decorative `brand-sky` dot pattern. Step chips use `bg-brand-sky text-hero`. The hero is shown only from `lg` (1024 px); below that the form is the only column.
- **Panel:** `frontend/src/features/auth/components/login-form.tsx` shows the "TPVS" brand and `ThemeToggle` in the top row, the page's only `<h1>` ("Iniciar sesión"), the form and the footer "¿No tienes acceso? Solicítalo a tu administrador.". `ThemeToggle` no longer floats fixed over the page.
- **Error alert:** request errors show an alert (`id="login-error"`, `role="alert"`, `status-error` tokens) with the fixed title "No pudimos iniciar tu sesión" and a message from `getLoginErrorDisplay` in `frontend/src/features/auth/login-error.ts`:
  - `401` → "El correo o la contraseña no coinciden. Revisa tus datos e inténtalo de nuevo." and both fields are marked `aria-invalid` and point to the alert with `aria-describedby`.
  - Any other `AuthApiError` (`419`, `422`, `403`, network, 5xx) → its own message, without marking the fields.
  - Anything else → "No se pudo iniciar sesión. Inténtalo nuevamente.".
  - `toAuthApiError` is unchanged, so logout and `me` keep their messages. The alert and invalid marks clear on the next submit attempt.
- **Fields:** both use `components/ui/input`. Zod and `422` field errors still render under each field (`email-error`, `password-error`). Invalid fields use a 2 px `border-status-error` passed through `className`; the base `Input` keeps its default `destructive` invalid style for other forms.
- **Show password:** a `type="button"` toggle inside the password field switches the input between `password` and `text` with the labels "Mostrar contraseña" / "Ocultar contraseña". `autoComplete="current-password"`, `id` and `name` are unchanged.
- **Coming soon controls:** "Mantener sesión iniciada" (checkbox) and "¿Olvidaste tu contraseña?" (a `<button>`, not a link) are rendered `disabled` with `title="Próximamente"` and an `aria-label` ending in "(próximamente)", following the SPEC 16 search/notifications pattern. Remember-me sessions and password recovery need backend work and their own specs.
- **Submit:** the button reads "Preparando formulario…" before hydration, "Iniciando sesión…" while submitting and "Iniciar sesión" at rest. Fields and buttons stay disabled until hydration (`useSyncExternalStore`), and the form keeps `action`, `method` and `preventDefault` to block native `POST /login` submissions.
- **Keyboard order:** `ThemeToggle`, email, password, show-password toggle, submit. Disabled "coming soon" controls are skipped.

## Logout UI

- Shared logout behavior lives in `frontend/src/features/auth/hooks/use-logout.ts`. It calls `authService.logout()`, clears the TanStack Query client, and replaces the current route with `/login`. Pending and error state are returned for the invoking control.
- The authenticated shell offers logout in two places: `frontend/src/features/auth/components/logout-nav-item.tsx` in the sidebar and `frontend/src/features/auth/components/logout-menu-item.tsx` in the topbar account menu. Both use `useLogout` and display any failure next to the item that initiated it.
- The legacy `frontend/src/features/auth/components/logout-button.tsx` also uses `useLogout` for other screens that need a standalone logout button.

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
