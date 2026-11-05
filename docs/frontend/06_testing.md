# Frontend Testing and Verification

## Status

The frontend uses Vitest for focused unit/Route Handler tests. Authentication has a browser acceptance flow against Laravel, verified with Playwright MCP; see the phase 8.4 evidence in [`../11_technical_roadmap.md`](../11_technical_roadmap.md). Backend-only phase 8.7 image HTTP acceptance is recorded in [`../11_technical_roadmap.md`](../11_technical_roadmap.md) and its API contract in [`../backend/03_api_contract.md`](../backend/03_api_contract.md). Phase 8.10 / SPEC 09 adds the review, correction, confirmation, and cancellation acceptance flow described below. Phase 8.11 / SPEC 10 adds change-history and confirmed-record reopening checks; its browser evidence is recorded below. Phase 8.12 / SPEC 11 adds delivery preparation checks; its browser evidence is recorded below, and the phase is complete once the frontend build and backend checks pass. Phase 8.13 / SPEC 12 adds the Word document download checks; its browser evidence is recorded below. Phase 8.14 / SPEC 13 adds the reports and Excel checks; its browser evidence is recorded below.

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

1. As `EDITOR`, open `/records/new`, select a company, enter a business year/week (including week 53 where needed), a valid date range that need not be Monday-Sunday, and at least one movement with its own affiliation, branch, income, and commission.
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

### Record change history browser acceptance (phase 8.11)

Run the stack, the queue worker, and the local test accounts.

```bash
docker compose up -d
docker compose exec -T tpvs-backend php artisan db:seed --class=TestUsersSeeder
```

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

Use uniquely named QA records/companies and remove only the QA data in foreign-key order after acceptance.

**Phase 8.11 browser verification (2026-10-26):** `EDITOR` created manual record `#69` and image record `#70` through the BFF. Manual History contained exactly one `record_created` `null -> CONFIRMED` row with the Editor, `Registro creado manualmente`, and no creation `status` event. The `prueba1.jpeg` upload returned `202` and wrote exactly one `record_created` `null -> PENDING` row with `Registro creado desde imagen`; Ollama `qwen2.5vl:3b` transitioned through `PROCESSING` to `COMPLETED` without any AI row in `History`, leaving a `COMPANY_NOT_FOUND` review issue that was resolved by selecting a company. Correcting a `NEEDS_REVIEW` aggregate changed company, week, and movement values and produced one row per changed field (`company`, `week`, `details[1].branch`, `details[1].income`, `details[1].commission_percentage`, calculated `commission`/`return_amount`) with canonical previous/new values, `details[1].*` names, Editor, reason, and timestamp. Re-submitting the same aggregate wrote a single `record_updated` with null values and returned `200`.

Editing confirmed records `#69` and `#70` wrote the field diff plus `status CONFIRMED -> NEEDS_REVIEW`, left them in `NEEDS_REVIEW`, and preserved `source`, Image, and `AIProcessing`; confirming again wrote `NEEDS_REVIEW -> CONFIRMED` with the source-appropriate reason (`Registro manual confirmado` or `Registro de imagen confirmado`). A no-change `PUT` on confirmed `#69` returned `422 RECORD_UPDATE_NO_CHANGES`, displayed the backend message, kept `CONFIRMED`, and added no History. Cancelling `NEEDS_REVIEW` record `#71` with a reason wrote `status NEEDS_REVIEW -> CANCELED` with the Editor and reason.

The `Historial de cambios` panel showed date, user, translated fields (`Movimiento 1 · …`, `Registro creado`, Spanish states), formatted previous/new values, and reasons. Its 25 event rows paginated at 20 per page (`1 de 2` and `2 de 2`); loading, error with retry, and empty states were verified with controlled network conditions. History refreshed after correction, reopening, confirmation, and cancellation through query invalidation. As `VIEWER`, the history was readable with no edit, confirm, cancel, or retry actions, and a CSRF-valid direct `PUT` returned `403`. API checks as `VIEWER` returned `200` with 25 entries, `422` for `per_page=101`, `404` for a missing record, and anonymous access returned `401`. Browser console errors were limited to the deliberately aborted history request and the expected `403`/`422`/`401` responses. QA records, histories, image, AI processing rows, companies, and the uploaded file were removed; the test accounts remain.

### Delivery preparation browser acceptance (phase 8.12)

Run the stack, the queue worker, and the local test accounts.

```bash
docker compose up -d
docker compose exec -T tpvs-backend php artisan db:seed --class=TestUsersSeeder
```

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
13. Confirm the screen never sends `prepared_return_amount` and does not offer Word generation in this phase.
14. Check the browser console for unexpected JavaScript exceptions.

Use uniquely named QA records/companies and remove only the QA data in foreign-key order after acceptance.

**Phase 8.12 browser verification (2026-10-29):** Playwright MCP drove the local stack as `EDITOR` and `VIEWER`. QA data was a manual company-`eventos` Record `#3` (week 20, 2026, one movement `10000.00` at 2%, return `9800.00`), created through `/records/new` with the form fields and then through the same BFF `POST` used by the form because the browser form submit did not register on that page. `#3` detail showed `Preparación de entrega` with `Preparar entrega`. The empty delivery screen showed the official return `$9,800.00` and the 11 denominations. Five `1000.00` bills (`$5,000.00`) returned `409 CASH_MISMATCH`; the screen showed expected `$9,800.00`, cash `$5,000.00`, and difference `-$4,800.00`, and nothing was persisted. The valid breakdown `1000×5, 500×5, 200×10, 100×3` returned `200` with the success message and persisted one Delivery row with `prepared_return_amount` `9800.00`, four denomination rows (zeros not stored), and seven `Preparación de entrega creada` History rows with `delivery_id` and `record_detail_id` null. Editing the Record to income `11000.00` with a reason reopened it (`Necesita revisión`), and reconfirmation produced `CONFIRMED`. The delivery screen then showed `La preparación está obsoleta`, current return `$10,780.00`, `Actualizar entrega`, and the calculated difference `-$980.00`. The breakdown `1000×10, 500×1, 200×1, 50×1, 20×1, 10×1` (`10780.00`) updated the same Delivery row, replaced denominations (`100.00` removed), refreshed `prepared_return_amount` to `10780.00`, and wrote eight `Preparación de entrega actualizada` rows only for changed fields. Repeating the identical save returned `200` with no new History and unchanged `updated_at`. The history panel showed `Fecha de entrega`, `Entrega realizada por`, `Importe preparado $9,800.00`, and `Denominación $1,000.00`. `VIEWER` saw `Ver entrega` on the detail and a read-only delivery screen with no buttons or inputs; a CSRF-valid direct `PUT` returned `403` and the stored values were unchanged. Console errors were limited to the expected `409` and `403`. QA Record `#3` with its History, denominations, Delivery, and details was removed in a single transaction. **Incident after this run:** running `php artisan test` from the backend container emptied the shared `tpvs` database, because `backend/phpunit.xml` sets `DB_DATABASE=tpvs` and the suite refreshes the schema. Records `#1`/`#2`, the existing Delivery, and the earlier QA companies were lost, with no backup; the test accounts were restored with `TestUsersSeeder`. Backend tests must not run against `tpvs` until `phpunit.xml` points to a dedicated test database. **Not repeated in the browser:** steps 7, 8, and 11 (zero, duplicate, and unofficial-value `422` cases, and `INVALID_RECORD_STATE` `409`); these are covered by `tests/Feature/DeliveryApiTest.php`. After the checkpoint, Next.js was upgraded to 16.4.3 (the build passes) and the backend suite (181 tests) and Pint pass against `tpvs_test`. Phase 8.12 is marked complete in the roadmap.

### Delivery Word document browser acceptance (phase 8.13)

Run the stack and the local test accounts as in phase 8.12. Prepare QA data with unique names: a confirmed Record with an Image and a matching Delivery, a confirmed Record with a Delivery but no Image, and a third Record with an Image and Delivery that is later changed and reconfirmed so its Delivery is stale.

Sign in through the browser and verify:

1. As `EDITOR`, open `/records/{id}/delivery` for the valid Record and confirm the `Documento Word` section shows an enabled `Generar documento Word` button.
2. Click it and confirm the browser downloads `entrega-registro-{id}-semana-{week}-{year}.docx`, the section shows `Documento generado y descargado.`, and the request goes through the BFF as `POST /api/deliveries/{delivery}/document`.
3. Open the file in Word and compare it with the office format: heading with weekday and month in uppercase with accents, `C. {delivering person}`, amount with the amount in words, week range, centered image, 11 denomination rows, two totals, and the ENTREGA/RECIBE signatures with names.
4. Confirm the Record history shows exactly one `Documento Word generado` entry with `delivery_id` per successful generation.
5. Open the delivery screen of the Record without an Image and confirm the button is disabled with `Adjunta una imagen al registro para generar el documento.`; a direct `POST` returns `409 DOCUMENT_GENERATION_BLOCKED` with `MISSING_IMAGE`.
6. Open the delivery screen of the stale Delivery and confirm the button is disabled with `La entrega usa un importe anterior; actualízala antes de generar el documento.`; a direct `POST` returns `409` with `STALE_DELIVERY`, `prepared_amount`, and `current_amount`.
7. Reopen a Record and confirm a direct `POST` returns `409` with `INVALID_RECORD_STATE`.
8. As `VIEWER`, confirm the `Documento Word` section and button are absent and a CSRF-valid direct `POST` returns `403`. A missing delivery returns `404`.
9. Confirm blocked requests wrote no `document_generated` History row.
10. Check the browser console for unexpected JavaScript exceptions.

Use uniquely named QA records and companies, and remove them and the stored image files in foreign-key order after acceptance.

**Phase 8.13 browser verification (2026-10-29):** Playwright MCP drove the local stack as `EDITOR` and `VIEWER` against the real BFF and Laravel. QA data was company `QA Word Eventos` with Records `#69` (image, Delivery `1`, return `920.00`, cash `500×1, 200×2, 10×2`), `#70` (Delivery `2`, no Image), and `#71` (image, Delivery `3`, later raised to income `1100.00` and reconfirmed, return `1012.00`). For `#69` the button was enabled, the click produced a `POST /api/deliveries/1/document` and downloaded `entrega-registro-69-semana-37-2026.docx` (valid Word 2007+ file, one embedded PNG), and the section showed `Documento generado y descargado.`. The document text matched the office format: `MIÉRCOLES 08 DE JULIO DE 2026, GUADALAJARA, JALISCO.`, `C. SOL QUEZAL FLORES GÓMEZ`, `$920.00 (NOVECIENTOS VEINTE PESOS 00/100 M.N.)`, `07 DE SEPTIEMBRE AL 13 DE SEPTIEMBRE DE 2026`, the 11 denomination rows, two `$920.00` totals, and `SOL QUEZAL FLORES GÓMEZ` / `JUAN PÉREZ` under ENTREGA/RECIBE. The Editor also opened the file in Word and approved the layout. The history panel showed one `Documento Word generado` row with `delivery_id` `1`, and no row was written for blocked requests. `#70` showed the button disabled with the missing-image reason and a direct `POST` returned `409 MISSING_IMAGE`. `#71` returned `409 INVALID_RECORD_STATE` while in `NEEDS_REVIEW` and, once reconfirmed, `409 STALE_DELIVERY` with `prepared_amount` `920.00` and `current_amount` `1012.00`; its screen showed the button disabled with the stale reason. As `VIEWER`, `#69` showed no `Documento Word` section or button, a CSRF-valid direct `POST` returned `403`, and a missing delivery returned `404`. Console errors were limited to the expected `409`, `403`, and `404` responses and to `419`/`422` responses from the QA data-preparation requests (missing CSRF header and a payload that sent both commission fields). QA records, histories, denominations, deliveries, images, the stored image files, and the company were removed; the test accounts remain. **Not exercised in the browser:** the in-UI rendering of a `409` returned while the button is enabled (covered by `tests/delivery-document.test.ts`), `401`, `419`, `IMAGE_UNAVAILABLE`, `CASH_MISMATCH`, and JPEG/WebP images (covered by `DeliveryDocumentApiTest` and `DeliveryDocumentBuilderTest`). Two Playwright-only quirks occurred and are unrelated to the feature: clicks sent before hydration finished were ignored, and the `Cerrar sesión` click did not redirect during the run, so the session was closed through `POST /api/auth/logout`.

### Reports and Excel browser acceptance (phase 8.14)

Run the stack and the local test accounts as in phase 8.12. Prepare QA data with unique names: two companies with confirmed Records across several weeks (one week that ends in the next month), a Record in `NEEDS_REVIEW` and one `CANCELED` that must not count, a confirmed Record of another year, and Deliveries with cash denominations (one stale).

Sign in through the browser and verify:

1. As `EDITOR`, open `/reports` and confirm the year defaults to the current year, the totals equal the SQL sum of `CONFIRMED` details, the `Por empresa` table agrees, and non-confirmed Records are absent.
2. Open `/reports?year={y}&month={m}` and confirm a week is counted in the month of its `week_end_date`.
3. Click `Generar Excel` and confirm the request is `GET /api/documents/excel` with only the four filters (no `page`, no `per_page`) and the file is named `Reporte_{year}_Mes_{mm}.xlsx`.
4. Compare the four sheets (`Resumen`, `Registros`, `Detalles`, `Efectivo`) with SQL: totals, one row per Record, one row per movement, denominations, `Total efectivo`, and `Desactualizada`.
5. As `VIEWER`, confirm the same totals and that the export is allowed.
6. Send invalid filters with `Accept: application/json` (no `year`, `per_page=101`, `month=13`, `year=abc`) and confirm `422` with field messages.
7. Check the browser console for unexpected JavaScript exceptions.

Use uniquely named QA records and companies and remove them in foreign-key order after acceptance.

**Phase 8.14 browser verification (2026-11-03):** Playwright MCP drove the local stack as `EDITOR` and `VIEWER` against the real BFF and Laravel. QA data was companies `QA Acme` and `QA Zeta`: `QA Acme` confirmed weeks 38 (two movements, `1000.00`, Delivery with `500×1, 200×2`), 39 (`500.00`) and 40 (`250.25`, ends `2026-10-04`), plus a `NEEDS_REVIEW` (`9999.00`) and a `CANCELED` (`8888.00`) Record that must not count; `QA Zeta` confirmed week 38 of 2026 (`300.00`, stale Delivery `270.00` against return `250.50`) and week 50 of 2025. For 2026 the screen showed `4` Records, Ingreso `$2,050.25`, Comisión `$224.53`, Retorno `$1,825.72`, with `QA Acme` `3` / `$1,750.25` and `QA Zeta` `1` / `$300.00`, equal to the SQL sums. With `month=9` it showed `$1,800.00` / `$199.50` / `$1,600.50` and excluded week 40. Clicking `Generar Excel` produced `GET /api/documents/excel?year=2026&month=9` (`200`, `Reporte_2026_Mes_09.xlsx`, `Cache-Control: no-store`, valid xlsx). The same workbook, built by `ReportWorkbookBuilder` and read back, had `Resumen` (`QA Acme` `1500/150/1350`, `QA Zeta` `300/49.5/250.5`, `TOTAL` `1800/199.5/1600.5`), `Registros` with 3 rows, `Detalles` with 4 rows, and `Efectivo` with 2 rows, where the Zeta Delivery showed total `250.5` and `Desactualizada` `Sí`. As `VIEWER` the totals were identical, the button was enabled, and `GET /api/documents/excel?year=2026` returned `200` (`Reporte_2026.xlsx`). With `Accept: application/json`, no `year`, `per_page=101`, `year=abc`, and `month=13` each returned `422` with the field message. Console errors were limited to those expected `422` responses. QA records, details, deliveries, denominations, and companies were removed; the test accounts remain. **Not exercised:** opening the `.xlsx` in Excel to review the visual formatting (no Excel in the environment; formats are asserted by `ReportWorkbookBuilderTest`), the in-UI rendering of API errors (covered by `tests/reports.test.ts`), `401`, `403`, `500`, and pagination beyond one page (covered by `ReportApiTest`). Two Playwright-only quirks occurred and are unrelated to the feature: clicks sent before hydration finished were ignored (login and `Cerrar sesión`), so the login form was submitted with `requestSubmit()`.

### Delivery cash adjustment acceptance (SPEC 14)

Run the stack and local test accounts as described above. Use isolated QA data: a confirmed Record with an Image and a Delivery. Remove only QA data after verification.

1. As `EDITOR`, open `/records/{id}/delivery`. For official return `$1,298.57`, verify the document amount selector offers `$1,298.00`, `$1,298.50`, and `$1,299.00`.
2. Save gross cash exactly `$1.00` below the official return. Verify success, `prepared_return_amount` remains `$1,298.57`, and no adjustment/change fields are written to `Delivery` or `History`.
3. Submit gross cash `$1.50` below the official return. Verify `409 DELIVERY_PREPARATION_BLOCKED` with `CASH_MISMATCH` and no partial writes. Verify any gross excess can be saved while `prepared_return_amount` remains the official return.
4. Choose a document amount and generate with gross cash exactly `$1.00` above it. Verify Word downloads, shows selected amount in digits and words, labels gross cash, and omits the customer-change line.
5. Prepare gross cash `$150.00` for document amount `$120.00`. Verify Word generation is disabled until the Editor marks `El cliente dará cambio`, then download the `.docx` and verify `Efectivo bruto: $150.00`, `Importe de entrega: $120.00`, and `Cambio que dará el cliente: $30.00`.
6. Modify denomination quantities after the last save and verify Word generation is blocked with an instruction to save first. Save and verify the action becomes available again when change declaration requirements are met.
7. Attempt direct document requests with an unsupported document amount, missing/invalid change flag, shortfall over `$1.00`, excess without declaration, and excess with declaration. Verify `422` for malformed/unsupported input, `409 CASH_MISMATCH` for excess shortfall, `409 CHANGE_REQUIRED` for excess without declaration, and successful `.docx` generation when declared.
8. Verify successful generation writes one `document_generated` event only; the selected amount and change declaration do not persist. Verify a Viewer cannot generate (`403`) and blocked generations write no event.

Automated coverage is in `DeliveryApiTest`, `DeliveryValidatorTest`, `DeliveryDocumentApiTest`, `DeliveryDocumentEligibilityTest`, `DeliveryDocumentBuilderTest`, and `tests/delivery-document.test.ts`.

**SPEC 14 browser verification (2026-11-05):** Playwright MCP used the local BFF/Laravel stack with `EDITOR` and `VIEWER`. QA Company `QA SPEC14 Cash Adjustment`, Record `#179`, Image `#11`, and Delivery `#27` were created and removed after acceptance. For official return `$1,298.57`, the delivery screen offered `$1,298.00`, `$1,298.50`, and `$1,299.00`; an API save with denominations totaling `$1,298.50` returned `200` and kept `prepared_return_amount=1298.57`. A save request totaling `$1,288.50` returned `409 DELIVERY_PREPARATION_BLOCKED/CASH_MISMATCH` with the expected, cash, and `-$10.07` difference values and did not persist partial data. For the Word change flow, the QA Record was then edited and reconfirmed at `$120.00`; the Delivery held `$150.00` gross cash. At document amount `$120.00`, the UI showed the `$30.00` customer-change checkbox and kept Word disabled until checked. As `EDITOR`, `POST /api/deliveries/27/document` with `document_return_amount=120.00` and `customer_will_give_change=true` returned `200` with the `.docx`; the file contained `$120.00` and `CIENTO VEINTE PESOS 00/100 M.N.`, `Efectivo bruto: $150.00`, `Importe de entrega: $120.00`, and `Cambio que dará el cliente: $30.00`. Sending the same request without declaring change returned `409 CHANGE_REQUIRED`; an invalid CSRF request returned `419`. As `VIEWER`, a CSRF-valid document mutation returned `403`. Directly editing denomination quantity in the UI caused the Word action to show `Guarda el desglose de efectivo antes de generar el documento.`. QA Record, Delivery, denomination rows, History, Image row and private file, and QA Company were removed. The test accounts remain. **Not exercised:** the final click-triggered browser download callback and opening the downloaded Word file in a Word application; the same endpoint returned the verified Word binary and the builder/API tests verify the document contents. One login attempt while switching roles returned `401` after logout, then Editor login was restored; expected negative API responses were `409`, `419`, and `403`.

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
- [`features/deliveries.md`](features/deliveries.md)
- [`../backend/09_testing.md`](../backend/09_testing.md)
- [`../../specs/11-delivery-preparation.md`](../../specs/11-delivery-preparation.md)
