# Frontend Testing and Verification

## Status

The frontend uses Vitest for focused unit/Route Handler tests. It does not currently use a browser end-to-end test runner. Authentication also has a documented manual browser-to-Laravel acceptance flow below.

## Verification Layers

### Static and build checks

Run the checks available in the repository for frontend changes:

```bash
npm run lint
npx tsc --noEmit
npm run build
```

Execute these from `frontend/`. If project scripts are added or changed, keep this guide in sync.

### Focused feature tests

Run the focused tests from `frontend/` with:

```bash
npm test
```

The BFF test suite covers the CSRF bridge, methods, upstream path/query and request forwarding, multiple `Set-Cookie` headers, Laravel statuses and response headers, safe 502 behavior, trusted-origin filtering, and upstream path validation.

Prioritize externally observable behavior that matters to each feature:

- form validation and mapping of Laravel field errors;
- loading, empty, success, and recoverable error states;
- query keys and invalidation for important mutations;
- role-aware actions and handling of `401`/`403` responses;
- state labels and blocked actions for record workflows;
- delivery cash-entry feedback and stale-preparation indicators;
- report filters and file-download behavior.

Test externally observable behavior instead of implementation details such as component state names or internal hook layout.

### Authentication browser acceptance

Run the stack from the repository root and create the local-only test accounts if they are not present:

```bash
docker compose up -d
docker compose exec -T tpvs-backend php artisan db:seed --class=TestUsersSeeder
```

The seeder creates `editor@test.com` and `viewer@test.com`, both with password `12345678`. It is blocked in production and must only be used in local development.

Open the frontend using the same origin configured in `FRONTEND_ORIGIN` and `SANCTUM_STATEFUL_DOMAINS` (for the Compose LAN example, `http://192.168.3.240:3000`). Verify:

1. An anonymous visit to `/` redirects to `/login`; opening `/login` shows the login form.
2. Submit invalid credentials and confirm a safe error appears without a page reload or credentials in the URL.
3. Sign in as `editor@test.com`; the browser receives Laravel's `tpvs_session` cookie and navigates to `/dashboard`, which displays the current user's name and role from `/api/auth/me`.
4. Refresh `/dashboard`; the session and identity remain valid. Open `/login`; the authenticated user is redirected to `/dashboard`. Opening `/` also redirects to `/dashboard`.
5. Sign out; the browser returns to `/login`, and a subsequent `/dashboard` request redirects to login.
6. Repeat login as `viewer@test.com` and confirm the displayed role is `VIEWER`.
7. In browser developer tools, confirm credentials are only sent in the `POST /api/auth/login` JSON body, never in the URL or local storage. Confirm `GET /api/auth/csrf-cookie` returns the CSRF cookie and login/logout requests carry `X-XSRF-TOKEN`.

When using a workstation on the LAN, configure `FRONTEND_ORIGIN`, `SANCTUM_STATEFUL_DOMAINS`, and `NEXT_ALLOWED_DEV_ORIGINS` for the exact frontend address before recreating the Compose services.

### Integration and end-to-end checks

Verify important user journeys against a running backend once their API contracts are implemented. Cover at least the accepted MVP flows in [`../11_technical_roadmap.md`](../11_technical_roadmap.md), including failure and authorization paths. Backend feature tests remain responsible for proving persistence, business validation, and authorization enforcement.

## Feature Acceptance Checklist

Before marking a frontend feature implemented:

- approved routes and screen behavior match [`../10_ui_ux.md`](../10_ui_ux.md);
- requests use the same-origin BFF and an implemented Laravel contract;
- loading, empty, error, success, and forbidden cases are handled;
- form/server validation errors are visible and actionable;
- role-specific actions are presented correctly without treating the UI as authorization;
- uploads/downloads handle the expected content type and response headers;
- appropriate automated tests or documented manual acceptance checks pass;
- lint, type checking, and production build pass;
- feature documentation status and roadmap evidence are current.

## Related Documents

- `03_api_bff_and_sessions.md`
- `04_data_fetching_and_forms.md`
- [`../10_ui_ux.md`](../10_ui_ux.md)
- [`../11_technical_roadmap.md`](../11_technical_roadmap.md)
- [`../backend/09_testing.md`](../backend/09_testing.md)
