# Frontend Testing and Verification

How frontend changes are verified: static checks, Vitest, and manual browser checklists per feature. Dated results of past browser runs are in [`verification-log.md`](verification-log.md).

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
- history query pagination/order, displayed labels, and invalidation after record mutations;
- confirmed-record editing, reopening, and `422 RECORD_UPDATE_NO_CHANGES` handling;
- delivery cash-entry feedback, official denominations, stale-preparation indicators, mismatch errors, and no-op saves;
- delivery document amount choices, gross cash reconciliation within `±$1.00`, conditional customer-change declaration, and Word content;
- blocking Word generation when the current denomination edits have not been saved;
- report filters and file-download behavior.

Test externally observable behavior instead of implementation details such as component state names or internal hook layout.

## Browser Checklists

These manual checklists are reused for release testing (roadmap 8.17). Record the result of a run in [`verification-log.md`](verification-log.md).

### Setup

Run the stack (and the queue worker for image flows) from the repository root, and create the local-only test accounts if they are not present:

```bash
docker compose up -d
docker compose exec -T tpvs-backend php artisan db:seed --class=TestUsersSeeder
```

The seeder creates `editor@test.com` and `viewer@test.com`, both with password `12345678`. It is blocked in production and must only be used in local development.

When using a workstation on the LAN, configure `FRONTEND_ORIGIN`, `SANCTUM_STATEFUL_DOMAINS`, and `NEXT_ALLOWED_DEV_ORIGINS` for the exact frontend address before recreating the Compose services.

Use uniquely named QA companies and records, and remove only that QA data (including stored image files) in foreign-key order afterwards. Keep the test accounts and unrelated data. Never run the backend test suite against `tpvs`; use `tpvs_test`.

### Authentication (SPEC 01, 02)

Open the frontend using the same origin configured in `FRONTEND_ORIGIN` and `SANCTUM_STATEFUL_DOMAINS` (for the Compose LAN example, `http://192.168.3.240:3000`). Verify:

1. An anonymous visit to `/` redirects to `/login`; opening `/login` shows the login form.
2. Submit invalid credentials and confirm a safe error appears without a page reload or credentials in the URL.
3. Sign in as `editor@test.com`; the browser receives Laravel's `tpvs_session` cookie and navigates to `/dashboard`, which displays the current user's name and role from `/api/auth/me`.
4. Refresh `/dashboard`; the session and identity remain valid. Open `/login`; the authenticated user is redirected to `/dashboard`. Opening `/` also redirects to `/dashboard`.
5. Sign out; the browser returns to `/login`, and a subsequent `/dashboard` request redirects to login.
6. Repeat login as `viewer@test.com` and confirm the displayed role is `VIEWER`.
7. In browser developer tools, confirm credentials are only sent in the `POST /api/auth/login` JSON body, never in the URL or local storage. Confirm `GET /api/auth/csrf-cookie` returns the CSRF cookie and login/logout requests carry `X-XSRF-TOKEN`.

### Companies (SPEC 03)

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

### Manual records (SPEC 04)

Sign in through the browser and verify:

1. As `EDITOR`, open `/records/new`, select a company, enter a business year/week (including week 53 where needed), a valid date range that need not be Monday-Sunday, and at least one movement with its own affiliation, branch, income, and commission.
2. Save the record. Verify `POST /api/records` returns `201`, the UI remains on `/records/new`, a success status is announced, and all fields reset for another capture.
3. Verify the resulting record has source `MANUAL`, status `CONFIRMED`, and backend-calculated commission/return. Verify History has a single `record_created` event `null -> CONFIRMED` with the Editor; no Image or AIProcessing exists.
4. Open `/records`; verify the new row, business period, confirmed state, and calculated return. Open its detail and verify the same totals and no edit/confirm controls for the already confirmed manual record.
5. Sign in as `VIEWER`. Verify `/records` and record detail are readable, the `+ Nuevo registro` action is absent, and `/records/new` redirects to `/records`.
6. With the Viewer session and current CSRF token, attempt `POST /api/records`; verify Laravel rejects it with `403` and no row is created.
7. Confirm the form reports invalid/reversed dates and financial errors without a partial record save. Confirm valid custom business date ranges and week numbers are not rejected by ISO weekday rules.
8. Check the browser console for JavaScript exceptions during the accepted role flows.

### Record review, confirmation, and cancellation (SPEC 09)

The configured AI provider must be reachable for a live extraction; if it is not available, use a controlled IMAGE Record already in `NEEDS_REVIEW` and record that limitation in the evidence.

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

### Record change history (SPEC 10)

Sign in through the browser and verify:

1. As `EDITOR`, create a manual record and an image record. Confirm each History starts with a single `record_created` event (`CONFIRMED` or `PENDING`, as applicable) with the Editor's user and the fixed creation reason, and no creation `status` event.
2. Open a `NEEDS_REVIEW` record, correct company, period, and movement values with a reason. Verify one History row per changed field, with canonical previous/new values, `details[N].campo` for movements, user, reason, and date.
3. Submit the same `NEEDS_REVIEW` aggregate again without changes. Verify a single `record_updated` event with null values and a `200` response.
4. Confirm the record and verify a `status` `NEEDS_REVIEW -> CONFIRMED` event with the source-appropriate reason and user.
5. As `EDITOR`, choose `Editar registro` on the `CONFIRMED` record, change a field, and save with a reason. Verify the record becomes `NEEDS_REVIEW`, the field diff plus `status CONFIRMED -> NEEDS_REVIEW` appear, and `source`, Image, and AIProcessing are unchanged. Confirm again and verify a new confirmation event.
6. Attempt to save a `CONFIRMED` record without changes. Verify `422 RECORD_UPDATE_NO_CHANGES`, the backend message is shown, the record stays `CONFIRMED`, and no History row is added.
7. Cancel a `NEEDS_REVIEW` or `ERROR` record with a reason and verify the `status` event records the previous state, `CANCELED`, user, and reason.
8. Verify the `Historial de cambios` panel shows date, user, translated field labels (`Movimiento N · Campo`, `Registro creado`, Spanish states), previous/new values, and reason; paginate when there are more than 50 rows; and show loading, error, and empty states.
9. As `VIEWER`, open the same record and confirm the history is readable while all mutation actions, including `Editar registro`, are absent. Send a CSRF-valid direct `PUT` and confirm Laravel returns `403`.
10. Confirm the history refreshes after correction, reopening, confirmation, and cancellation, and check the browser console for unexpected JavaScript exceptions.

### Delivery preparation (SPEC 11)

Sign in through the browser and verify:

1. As `EDITOR`, open a `CONFIRMED` record and confirm `Preparación de entrega` links to `/records/{id}/delivery`.
2. Confirm the empty state when no Delivery exists and that the screen shows the current return and the official denominations (`1000`, `500`, `200`, `100`, `50`, `20`, `10`, `5`, `2`, `1`, `0.50`).
3. Enter a breakdown whose cash total matches the current return, save, and verify the success state, persisted values, `prepared_return_amount`, and a new `Preparación de entrega creada` History entry with `delivery_id`.
4. Update the breakdown with a different valid combination and verify the same Delivery row is updated, `prepared_return_amount` is refreshed, and `Preparación de entrega actualizada` History rows appear only for changed fields.
5. Submit the identical preparation again and verify `200`, no new History, and no visible timestamp change.
6. Submit a cash mismatch and verify `409 DELIVERY_PREPARATION_BLOCKED` with `CASH_MISMATCH`, expected/cash/difference values, and no persisted change.
7. Verify zero-quantity rows are ignored, duplicate positive denominations return `422`, and an empty breakdown is accepted only for a `0.00` return.
8. Verify an unsupported denomination and invalid date/name values return `422` with field-level messages.
9. Reopen the confirmed record, change its return, confirm again, and verify the delivery screen shows `is_stale=true` and the stale warning. Update the same Delivery to the new return and verify the warning clears.
10. As `VIEWER`, open the same delivery route and confirm values are readable with no save action. Send a CSRF-valid direct `PUT` and confirm `403`.
11. Attempt delivery preparation for a non-`CONFIRMED` record without a Delivery and confirm the UI explains the blocked state. Verify a direct `PUT` returns `409 INVALID_RECORD_STATE`.
12. Verify the history panel formats delivery fields, including `denominations[1000.00]`-style entries, and refreshes after a successful save.
13. Confirm the screen never sends `prepared_return_amount`.
14. Check the browser console for unexpected JavaScript exceptions.

### Delivery Word document (SPEC 12)

Prepare QA data with unique names: a confirmed Record with an Image and a matching Delivery, a confirmed Record with a Delivery but no Image, and a third Record with an Image and Delivery that is later changed and reconfirmed so its Delivery is stale.

Sign in through the browser and verify:

1. As `EDITOR`, open `/records/{id}/delivery` for the valid Record and confirm the action bar shows an enabled `Generar recibo (Word)` button (SPEC 22; it replaced the former `Documento Word` section).
2. Click it and confirm the browser downloads `entrega-registro-{id}-semana-{week}-{year}.docx`, the action bar hint shows `Recibo generado.`, and the request goes through the BFF as `POST /api/deliveries/{delivery}/document`.
3. Open the file in Word and compare it with the office format: heading with weekday and month in uppercase with accents, `C. {delivering person}`, amount with the amount in words, week range, centered image, 11 denomination rows, two totals, and the ENTREGA/RECIBE signatures with names.
4. Confirm the Record history shows exactly one `Documento Word generado` entry with `delivery_id` per successful generation.
5. Open the delivery screen of the Record without an Image and confirm the button is disabled and the action bar explains the missing image (`Falta la imagen para generar el Word.`, or the blocking banner `Adjunta la imagen del registro para generar el Word.`); a direct `POST` returns `409 DOCUMENT_GENERATION_BLOCKED` with `MISSING_IMAGE`.
6. Open the delivery screen of the stale Delivery and confirm the stale banner (`La entrega se preparó con un monto anterior.`) appears, the title is `Preparar nueva entrega`, and `Generar recibo (Word)` is disabled until the delivery is saved again; a direct `POST` returns `409` with `STALE_DELIVERY`, `prepared_amount`, and `current_amount`.
7. Reopen a Record and confirm a direct `POST` returns `409` with `INVALID_RECORD_STATE`.
8. As `VIEWER`, confirm the action bar and its `Generar recibo (Word)` button are absent and a CSRF-valid direct `POST` returns `403`. A missing delivery returns `404`.
9. Confirm blocked requests wrote no `document_generated` History row.
10. Check the browser console for unexpected JavaScript exceptions.

### Reports and Excel (SPEC 13)

Prepare QA data with unique names: two companies with confirmed Records across several weeks (one week that ends in the next month), a Record in `NEEDS_REVIEW` and one `CANCELED` that must not count, a confirmed Record of another year, and Deliveries with cash denominations (one stale).

Sign in through the browser and verify:

1. As `EDITOR`, open `/reports` and confirm the year defaults to the current year, the totals equal the SQL sum of `CONFIRMED` details, the `Por empresa` table agrees, and non-confirmed Records are absent.
2. Open `/reports?year={y}&month={m}` and confirm a week is counted in the month of its `week_end_date`.
3. Click `Generar Excel` and confirm the request is `GET /api/documents/excel` with only the four filters (no `page`, no `per_page`) and the file is named `Reporte_{year}_Mes_{mm}.xlsx`.
4. Compare the four sheets (`Resumen`, `Registros`, `Detalles`, `Efectivo`) with SQL: totals, one row per Record, one row per movement, denominations, `Total efectivo`, and `Desactualizada`.
5. As `VIEWER`, confirm the same totals and that the export is allowed.
6. Send invalid filters with `Accept: application/json` (no `year`, `per_page=101`, `month=13`, `year=abc`) and confirm `422` with field messages.
7. Check the browser console for unexpected JavaScript exceptions.

### Delivery cash adjustment (SPEC 14)

Use isolated QA data: a confirmed Record with an Image and a Delivery. Remove only QA data after verification.

1. As `EDITOR`, open `/records/{id}/delivery`. For official return `$1,298.57`, verify the document amount selector offers `$1,298.00`, `$1,298.50`, and `$1,299.00`.
2. Save gross cash exactly `$1.00` below the official return. Verify success, `prepared_return_amount` remains `$1,298.57`, and no adjustment/change fields are written to `Delivery` or `History`.
3. Submit gross cash `$1.50` below the official return. Verify `409 DELIVERY_PREPARATION_BLOCKED` with `CASH_MISMATCH` and no partial writes. Verify any gross excess can be saved while `prepared_return_amount` remains the official return.
4. Choose a document amount and generate with gross cash exactly `$1.00` above it. Verify Word downloads, shows selected amount in digits and words, labels gross cash, and omits the customer-change line.
5. Prepare gross cash `$150.00` for document amount `$120.00`. Verify Word generation is disabled until the Editor marks `El cliente dará cambio`, then download the `.docx` and verify `Efectivo bruto: $150.00`, `Importe de entrega: $120.00`, and `Cambio que dará el cliente: $30.00`.
6. Modify denomination quantities after the last save and verify Word generation is blocked with an instruction to save first. Save and verify the action becomes available again when change declaration requirements are met.
7. Attempt direct document requests with an unsupported document amount, missing/invalid change flag, shortfall over `$1.00`, excess without declaration, and excess with declaration. Verify `422` for malformed/unsupported input, `409 CASH_MISMATCH` for excess shortfall, `409 CHANGE_REQUIRED` for excess without declaration, and successful `.docx` generation when declared.
8. Verify successful generation writes one `document_generated` event only; the selected amount and change declaration do not persist. Verify a Viewer cannot generate (`403`) and blocked generations write no event.

Automated coverage is in `tests/Feature/Deliveries/` (`ReadDeliveryTest`, `PrepareDeliveryTest`, `UpdateDeliveryTest`), the unit tests in `tests/Unit/Deliveries/` (`DeliveryInputNormalizerTest`, `CashBreakdownTest`, `DeliveryReturnPolicyTest`), `DeliveryDocumentApiTest`, `DeliveryDocumentEligibilityTest`, `DeliveryDocumentBuilderTest`, and `tests/delivery-document.test.ts`.

## Integration and End-to-End Checks

Pending (roadmap 8.16–8.17): verify the full journey and the failure and authorization scenarios listed in [`../roadmap.md`](../roadmap.md). Backend feature tests remain responsible for proving persistence, business validation, and authorization enforcement.

## Feature Acceptance Checklist

Before marking a frontend feature implemented:

- approved routes and screen behavior match [`../ui-ux.md`](../ui-ux.md);
- requests use the same-origin BFF and an implemented Laravel contract;
- loading, empty, error, success, and forbidden cases are handled;
- form/server validation errors are visible and actionable;
- role-specific actions are presented correctly without treating the UI as authorization;
- uploads/downloads handle the expected content type and response headers;
- appropriate automated tests or documented manual acceptance checks pass;
- lint, type checking, and production build pass;
- the SPEC status and its row in `../roadmap.md` are updated.

## Related Documents

- [`conventions.md`](conventions.md)
- [`verification-log.md`](verification-log.md)
- [`../ui-ux.md`](../ui-ux.md)
- [`../roadmap.md`](../roadmap.md)
- [`features/deliveries.md`](features/deliveries.md)
- [`../backend/testing.md`](../backend/testing.md)
