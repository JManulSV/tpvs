# Frontend Testing and Verification

## Status

The frontend uses Vitest for focused unit/Route Handler tests. Authentication has a browser acceptance flow against Laravel, verified with Playwright MCP; see the phase 8.4 evidence in [`../11_technical_roadmap.md`](../11_technical_roadmap.md). Backend-only phase 8.7 image HTTP acceptance is recorded in [`../11_technical_roadmap.md`](../11_technical_roadmap.md) and its API contract in [`../backend/03_api_contract.md`](../backend/03_api_contract.md). Phase 8.10 / SPEC 09 adds the review, correction, confirmation, and cancellation acceptance flow described below; its evidence is recorded when the implementation checkpoint passes.

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

### Companies browser acceptance (phase 8.5)

Run the stack and create the local-only test accounts if they are not present:

```bash
docker compose up -d
docker compose exec -T tpvs-backend php artisan db:seed --class=TestUsersSeeder
```

Open the frontend at the same trusted origin used for authentication and verify:

1. Sign in as `editor@test.com` with the local test password `12345678`; open `/companies` from the authenticated navigation.
2. Confirm the basic Spanish catalog shows the company name and confirmed-record count, plus the search field and `Nueva empresa` action.
3. Create a company with a unique name. Confirm the dialog closes, the success message appears, filters reset, and the new company is listed.
4. Try creating the same name again with different capitalization or surrounding spaces. Confirm the dialog stays open and Laravel's validation message is associated with the name field.
5. Search using a case-insensitive substring; confirm the list filters and returns to page 1. With enough seeded companies, verify the page controls and page indicator.
6. Sign out and sign in as `viewer@test.com`; open `/companies` and confirm the catalog remains readable while `Nueva empresa` is not rendered.
7. Submit an authorized same-origin POST to `/api/companies` using the current session and CSRF cookie/header. Confirm Laravel returns `403` for the Viewer mutation.
8. Confirm no unexpected browser JavaScript exceptions occur. A direct forbidden API request may be reported as an HTTP error by the browser console; the response status should be `403`.

The automated Laravel feature tests remain authoritative for persistence, pagination, role enforcement, and database constraints. Use a test database isolated from the application database when running the backend suite; `RefreshDatabase` rebuilds the configured test schema.

**Phase 8.5 browser verification (2026-10-14):** Against the local Docker stack, `EDITOR` signed in through the BFF, loaded `/companies`, created a company (`201`), saw the updated list (`200`), and received field validation for a duplicate (`422`). `VIEWER` signed in, loaded the same catalog (`200`) without a create action, and a CSRF-valid direct creation request returned `403`. Temporary company entries were removed after verification. No unexpected browser JavaScript exceptions were observed during the successful role flows.

### Manual Records browser acceptance (phase 8.6)

Run the stack and create the local-only test accounts if they are not present:

```bash
docker compose up -d
docker compose exec -T tpvs-backend php artisan db:seed --class=TestUsersSeeder
```

Sign in through the browser and verify:

1. As `EDITOR`, open `/records/new`, select a company, enter a business year/week (including week 53 where needed), a valid date range that need not be Monday-Sunday, and at least one movement with its own operation number, affiliation, branch, income, and commission.
2. Save the record. Verify `POST /api/records` returns `201`, the UI remains on `/records/new`, a success status is announced, and all fields reset for another capture.
3. Verify the resulting record has source `MANUAL`, status `CONFIRMED`, and backend-calculated commission/return. Verify a `History` status event records null -> `CONFIRMED`, the Editor, and `Registro manual confirmado`; no Image or AIProcessing exists.
4. Open `/records`; verify the new row, business period, confirmed state, and calculated return. Open its detail and verify the same totals and no edit/confirm controls for the already confirmed manual record.
5. Sign in as `VIEWER`. Verify `/records` and record detail are readable, the `+ Nuevo registro` action is absent, and `/records/new` redirects to `/records`.
6. With the Viewer session and current CSRF token, attempt `POST /api/records`; verify Laravel rejects it with `403` and no row is created.
7. Confirm the form reports invalid/reversed dates and financial errors without a partial record save. Confirm valid custom business date ranges and week numbers are not rejected by ISO weekday rules.
8. Check the browser console for JavaScript exceptions during the accepted role flows.

Use a uniquely named QA company/record when the local database is operationally shared, then remove only the QA record's History and details, the record, and the QA company in foreign-key order after acceptance. Keep the existing test accounts and unrelated data.

**Phase 8.6 browser verification (2026-10-15):** `EDITOR` saved manual record `#37` against QA company `#47` through `/records/new`; BFF POST returned `201`, persisted status `CONFIRMED`, and calculated income `$123.45`, commission `$6.17`, return `$117.28`. A custom business week 53 with date range `2026-10-01` through `2026-10-08` was accepted. The form stayed on `/records/new`, announced success, and reset all fields. The record appeared in `/records` and its detail displayed the calculations with no edit/confirm actions. `VIEWER` could list and view records, had no create link, `/records/new` redirected to `/records`, and a CSRF-valid direct POST returned `403`. QA record/company were removed; test accounts remain. The first browser attempt occurred before restoring a seeded company and submitted invalid data; it returned `422`, created no row, and was not counted as the successful checkpoint flow.

### Record review, correction, confirmation, and cancellation browser acceptance (phase 8.10)

Run the stack, the queue worker, and the local test accounts. The configured AI provider must be reachable for a live extraction; if it is not available, use a controlled IMAGE Record already in `NEEDS_REVIEW` and record that limitation in the evidence.

```bash
docker compose up -d
docker compose exec -T tpvs-backend php artisan db:seed --class=TestUsersSeeder
```

Sign in through the browser and verify:

1. As `EDITOR`, open `/records/new`, choose image upload, and submit a valid JPEG/PNG/WebP no larger than 10 MiB. Verify the request returns `202`, the UI redirects to the new Record, and `PENDING`/`PROCESSING` states are shown while polling.
2. Confirm polling stops when the Record reaches `NEEDS_REVIEW`, `ERROR`, `CONFIRMED`, or `CANCELED`.
3. For `NEEDS_REVIEW`, verify the screen shows the authorized image, the current status, safe AI issues, and persisted business data. Confirm no private path, storage key, provider host, raw AI response, or numeric confidence value is exposed.
4. As `EDITOR`, correct the aggregate (company, period, movements, and reason). Verify the request succeeds, the Record remains `NEEDS_REVIEW`, `source` is unchanged, and the image/AI evidence is unchanged.
5. Confirm the corrected Record. Verify Laravel revalidates, the state becomes `CONFIRMED`, and the UI no longer offers review actions. Verify History records the source-appropriate confirmation reason.
6. As `EDITOR`, open or create an `ERROR` Record. Verify the safe diagnostic, retry action, and cancellation action are available. Use retry once and verify it performs a single manual attempt; then cancel with a reason and verify the state becomes `CANCELED`.
7. Verify a canceled Record remains readable, its image is no longer accessible, and its image/AI evidence was not deleted.
8. Verify a `NEEDS_REVIEW` Record can also be canceled with a reason, while `CONFIRMED`, `PENDING`, and `PROCESSING` Records reject cancellation.
9. As `VIEWER`, open a reviewable Record and verify the image, status, data, and safe AI issues are readable while all mutation actions are absent. Send a CSRF-valid direct correction/confirmation/cancellation request and confirm Laravel returns `403`.
10. Force a stale-state action where practical and verify the UI handles the resulting `409` by refreshing the Record and showing the backend message. Check the browser console for unexpected JavaScript exceptions; expected `401`/`403`/`409` HTTP responses may appear as failed requests.

Use uniquely named QA records/companies and remove only the QA data in foreign-key order after acceptance. Do not remove unrelated data or the local test accounts.

**Phase 8.10 browser verification (2026-10-22):** `EDITOR` uploaded `test-images/prueba1.jpeg` through `/records/new`; the BFF returned `202`, the screen showed `PENDING`/`PROCESSING` with polling, and the Record reached `ERROR` with safe AI diagnostics after the live provider completed. The AI provider was Ollama with `qwen2.5vl:3b`; the model returned confidence `0.5` for null values, so the documented SPEC 08 structural limitation produced `ERROR/INVALID_CONFIDENCE` instead of a reviewable proposal. Using a controlled IMAGE Record in `NEEDS_REVIEW`, the Editor saw the authorized image, status, safe issues, and persisted data without paths, raw responses, or numeric confidence. Correction changed the branch and income with a reason; the Record remained `NEEDS_REVIEW`, kept its `source`, image, and AI evidence, and wrote `record_updated` with calculated commission and return. Confirmation produced `CONFIRMED` and the source-specific reason `Registro de imagen confirmado`; review actions disappeared. The live `ERROR` Record exposed retry and cancellation; retry made one manual attempt, returned to `ERROR`, and cancellation with a reason produced `CANCELED` with previous state `ERROR`. Image and `AIProcessing` rows remained, while `GET /api/records/{record}/image` returned `409`. A `NEEDS_REVIEW` Record was also canceled with a reason. `VIEWER` could read the image, status, data, and issues with no mutation actions, and CSRF-valid direct correction, confirmation, and cancellation requests returned `403`. A stale-state cancellation returned `409`, refreshed the Record to its real `CONFIRMED` state, and displayed the backend message. Browser console errors were limited to the expected `403`/`409` HTTP responses. QA records and the QA company were removed; test accounts remain. The checkpoint also fixed `$NaN` totals for records without details, missing company preselection in the review form, and the review form remaining open after a successful correction.

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
