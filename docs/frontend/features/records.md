# Records Feature

## Status

**Phase 8.6 and SPEC 07 are implemented (2026-10-20).** Manual capture, record list/detail, backend validation and confirmation, role-aware actions, and movement-level affiliation/branch are implemented. **Phase 8.10 / SPEC 09 is implemented and verified (2026-10-22).** Image upload, processing progress, human review, correction, confirmation, cancellation, and retry are available with role-aware actions. **Phase 8.11 / SPEC 10 is implemented and verified (2026-10-26):** record change history with user, reason, and before/after values, plus audited editing and reopening of `CONFIRMED` records. **SPEC 11 / phase 8.12 and SPEC 12 / phase 8.13 are implemented. SPEC 14 cash-adjustment behavior is integrated; focused checks pass.** The checkpoint is recorded in the roadmap.

## Goal and Scope

Support record consultation, manual capture, and the human review workflow. Phase 8.6 includes a general list and detail for all record sources/statuses. A valid manual record is confirmed by Laravel during save. Phase 8.10 adds the minimum functional flow that resolves IMAGE proposals in `NEEDS_REVIEW` and failed records in `ERROR`; the official UI/UX design remains in phase 8.15. Product screen details are in [`../../10_ui_ux.md`](../../10_ui_ux.md); authoritative state and business rules are in [`../../02_business_rules_and_states.md`](../../02_business_rules_and_states.md), and the manual capture contract is in [`../../09_manual_capture.md`](../../09_manual_capture.md).

## Target Routes and Screens

```text
/records
/records/new
/records/[recordId]
/records/[recordId]/delivery
```

The list supports case-insensitive substring search by company, affiliation, and branch (searching every movement); filters include company, business year, month, week, status, and source. Provisional IMAGE records may lack company, period, movements, or movement values; the list and detail screens render these as unavailable. Pagination uses `page` and `per_page` (default 15, maximum 100), sorted by `updated_at` descending. Month filtering uses the month containing `week_end_date`. The list can start from `status`, `year`, and `month` in the URL; see [Initial filters from the URL](#initial-filters-from-the-url). Its layout and role differences are described in [List layout (SPEC 19)](#list-layout-spec-19). The detail/review screen presents period, company, each movement's affiliation/branch, backend-calculated amounts, current backend status, the authorized image, and safe AI diagnostics. `/records/new` allows choosing manual capture or image upload. The same `/records/{id}` screen acts as detail and as the review workspace for `NEEDS_REVIEW` and `ERROR` records. Confirmed records also link to `/records/{id}/delivery` for delivery preparation.

## Initial filters from the URL

`/records` reads `status`, `year`, and `month` from the query string once, when the screen mounts, and uses them as the starting filters (SPEC 18). The Dashboard links use this: a status cell or «Ir a revisión» opens the list already filtered.

- Accepted values: `status` must be one of the six record statuses; `year` must be a positive integer up to 9999; `month` must be an integer from 1 to 12. Any other value is dropped and the list loads without that filter. An invalid query never produces an error screen.
- A year outside the last eight years is added to the year select so the value is visible.
- Other parameters (`week`, `company_id`, `source`, `search`) are not read from the URL.
- Changing filters on the screen afterwards does not update the URL. Navigating back or refreshing returns to the URL's filters.
- Parsing lives in `frontend/src/features/records/record-filters.ts` and is covered by `frontend/tests/records.test.ts`.

## List layout (SPEC 19)

`/records` renders inside the shared app shell and follows the `RecordsEditor{,Mobile}` templates in `docs/design/templates/`, translated to the global design tokens. The page keeps a single visible `<h1>` («Registros»); search, «Nuevo registro», and notifications are not repeated in the page because the shell already provides them.

- **Filters:** from `md`, a «Filtros» card with the search field and the Empresa, Estado, Año, Mes, Semana, and Fuente selects. The grid has 4 columns when its container is at least 48 rem wide and 2 below that. Below `md`, a 48 px search field plus a horizontally scrollable row of 44 px chips whose options carry a prefix («Año 2026», «Mes: enero»), and a «N registros» summary line. A chip with a value other than «Todos» uses the accent style.
- **Selects:** the shadcn `Select` (`frontend/src/components/ui/select.tsx`). The empty option uses the internal value `"all"` (`ALL_FILTER_VALUE`), which `selectValueToFilter` turns into no filter; `"all"` is never sent to the backend.
- **Clearing:** «Limpiar filtros» (card, empty state) and «Limpiar» (mobile) reset the search text, every filter, and the page. They only appear when `hasActiveRecordFilters` is true.
- **Table or cards:** a container query (`@container`, 60 rem) chooses the presentation, not the window width, because the sidebar changes the usable space. At 60 rem or more the list is a semantic table with `Empresa · Semana · Fin de semana · Fuente · Retorno · Estado · Acción` and no horizontal scroll; below that it is a list of cards. Both are rendered and CSS hides one with `display: none`, so the hidden one is outside the accessibility tree and the Tab order.
- **Row click:** the whole row or card is clickable. The company name is the `Link` to `/records/{id}`, and a pseudo-element covers the row (`relative` on the `<tr>`/`<li>`). The link carries an `aria-label` from `getRecordRowLabel`, and its focus ring surrounds the whole row.
- **Row content:** the subtitle shows the first movement's affiliation and branch plus «+N movimientos», or a waiting text for `PENDING`, `PROCESSING`, and `ERROR` records without movements. Records without company show «Sin empresa asignada»; missing week, end date, and return render as «—». A `CANCELED` or amount-less row shows the amount muted. Dates and amounts use `formatDate` and `formatMoney`.
- **Role difference:** the `EDITOR` sees «Revisar» on `NEEDS_REVIEW` rows and «Ver error» on `ERROR` rows; the `VIEWER` sees only the chevron. «Nuevo registro» is the topbar button on desktop and the shell's floating button on mobile, for the `EDITOR` only. The UI is a convenience; the backend still enforces permissions.
- **States:** loading shows five skeleton rows or cards and an `sr-only` «Cargando registros…», never «0 registros». An error shows a `role="alert"` card with «Reintentar». With no records and no filters it shows «Todavía no hay registros.»; with filters and no results it shows «No hay registros con estos filtros» and a «Limpiar filtros» button.
- **Footer:** the table card ends with a footer showing the total (`meta.total`) and «Página X de Y» with «Anterior»/«Siguiente»; on mobile the same pagination sits under the cards without repeating the count. No return total is shown, since the list is paginated and official totals are not computed in the browser.
- **Code:** presentation lives in `frontend/src/features/records/components/` (`records-screen`, `records-filter-bar`, `records-table`, `records-card-list`, `records-skeleton`, `records-pagination`). Pure helpers are in `frontend/src/features/records/record-list-utils.ts`, covered by `frontend/tests/record-list-utils.test.ts`.

## Capture and Review Flows

- **Origin selection:** `/records/new` lets the Editor choose manual capture or image upload. `VIEWER` cannot access mutation flows.
- **Manual capture:** require company, business year/week, valid date range (start <= end), and one or more ordered movements. Week numbers are not ISO validated and dates need not be Monday/Sunday. Each movement requires affiliation, branch, income, and exactly one commission input: percentage or fixed amount. Affiliation and branch are movement-level fields, not Record-level fields. Do not submit source, status, order, commission result, return, or totals as authoritative values.
- **Manual save:** Laravel validates and confirms a valid manual record in the create request. It returns calculated commission, return, and aggregate totals. After success, show a confirmation message, clear the form, and remain on `/records/new` for the next capture.
- **Image upload:** accept JPEG, PNG, or WebP up to 10 MiB, send `multipart/form-data` to `POST /api/records/from-image`, and rely on Laravel for content validation. A successful `202` redirects to `/records/{id}`. Show a safe failure state for `422`, `403`, `401`, or network errors.
- **Processing progress:** while the Record is `PENDING` or `PROCESSING`, poll the backend detail at a bounded interval and show the current state. Stop polling when the Record reaches a final state (`NEEDS_REVIEW`, `ERROR`, `CONFIRMED`, or `CANCELED`).
- **Review:** for `NEEDS_REVIEW`, show the authorized image, status, safe AI issues, and the persisted business data. If AI could not resolve a company, show the extracted company name as a hint and let the Editor choose an existing company. Do not show numeric confidence values in this phase.
- **Correction:** allow `EDITOR` to replace the full aggregate for `MANUAL` or `IMAGE` records in `NEEDS_REVIEW`, including a required reason. The form is prefilled from backend data. The response remains `NEEDS_REVIEW`; never change source/status from the client. Correction must not re-run AI or alter the image/AI evidence. After success, refresh the record and invalidate its history query.
- **Confirmation:** allow `EDITOR` to confirm `NEEDS_REVIEW` after backend validation. Show a confirmation dialog that makes the human decision explicit. AI issues are review signals and do not block confirmation once the aggregate is valid. Display the backend state after success and invalidate the record history query.
- **Cancellation:** allow `EDITOR` to cancel `NEEDS_REVIEW` or `ERROR` records with a required reason. After success, show `CANCELED`; image access is no longer available, but the record remains readable. Invalidate the record history query.
- **Failed processing:** for `ERROR`, show a safe diagnostic summary and offer the existing retry action and cancellation. Retry uses `POST /api/records/{record}/ai-processing/retry` and performs one provider call per request.
- **Confirmed-record editing/reopening:** show `Editar registro` to `EDITOR` for `CONFIRMED` records. Reuse the current edit form with a required reason and explain that saving reopens the record to `NEEDS_REVIEW`. After a successful save, show the reopened state and offer `Confirmar registro`; a `422 RECORD_UPDATE_NO_CHANGES` keeps `CONFIRMED` and shows the backend message. Do not expose the action to `VIEWER` or for other states.
- **Change history:** `/records/{id}` shows a `Historial de cambios` panel for `EDITOR` and `VIEWER` with loading, error, empty, and pagination states. Each row shows date, user, field, previous value, new value, and reason. Translate fields to readable labels; render `details[N].campo` as `Movimiento N · Campo` and `record_created` as `Registro creado`. Use Spanish labels for states. Format amounts, percentages, and dates for display while keeping backend values authoritative.
- **Delivery preparation:** for `CONFIRMED` records, show `Preparación de entrega` to both roles. `EDITOR` opens `/records/{id}/delivery` to create or update the single Delivery; `VIEWER` sees the same route read-only. The screen shows the official return, denominations, gross cash, document amount choices, stale warning, and reconciliation feedback. Word generation uses transient amount/change choices.
- **Role and state:** `EDITOR` may create, upload, review, correct, reopen, confirm, cancel, and retry where the state allows, and may read history. `VIEWER` may list, view, and read history only. Do not show mutation actions for `VIEWER` or for states that do not allow the operation; the backend remains authoritative.

Do not calculate official commission, return, totals, or state transitions as client authority. The UI may display backend-provided calculated results, validation explanations, and safe AI diagnostics.

## API Contract

The implemented phase 8.6, SPEC 09, and SPEC 10 contracts are documented in [`../../backend/03_api_contract.md`](../../backend/03_api_contract.md): `GET /api/records`, `POST /api/records`, `GET /api/records/{id}`, full-replacement `PUT /api/records/{id}`, `POST /api/records/{id}/confirm`, `POST /api/records/{id}/cancel`, `GET /api/records/{id}/history`, `POST /api/records/from-image`, `POST /api/records/{record}/ai-processing/retry`, and authorized `GET /api/records/{record}/image`. All browser requests use same-origin services through the Next.js BFF. Standard validation errors are `422`; unauthorized, forbidden, missing-resource, and disallowed transition responses retain `401`, `403`, `404`, and `409` respectively.

The list query uses `search`, `company_id`, `year`, `month`, `week`, `status`, `source`, `page`, and `per_page`. Search spans company name, affiliation, and branch across any movement. A week filter is scoped by business `year`, not ISO; month filters by `week_end_date`. The create payload requires company, business period identifiers and valid date range, and non-empty ordered `details`. Each detail includes `affiliation`, `branch`, `income`, and exactly one of `commission_percentage` or fixed `commission`. Affiliation and branch are not accepted at the Record root. Laravel calculates `commission` and `return_amount`. Replacement submits the full aggregate plus required `reason` and accepts `MANUAL` or `IMAGE` records in `NEEDS_REVIEW` or `CONFIRMED`; a changed `CONFIRMED` replacement returns `NEEDS_REVIEW`, while a no-change replacement returns `422 RECORD_UPDATE_NO_CHANGES`. Confirmation accepts no client state or reason. Cancellation requires `reason` and accepts `NEEDS_REVIEW` or `ERROR`. IMAGE records may expose nullable company, period, and provisional detail fields; this does not relax MANUAL capture or confirmation validation.

History uses `GET /api/records/{id}/history?page=&per_page=` with `per_page` defaulting to 50 and capped at 100, ordered newest first. Each item exposes `field_name`, `previous_value`, `new_value`, `reason`, `created_at`, and `user`. The panel must preserve backend values and never infer changes client-side.

Delivery preparation uses `GET` and `PUT /api/records/{record}/delivery`. `GET` returns `{ "data": null }` or the delivery with `prepared_return_amount`, `current_return_amount`, `is_stale`, and ordered denominations; both roles may read. `PUT` requires `delivery_date`, `delivered_by_name`, optional `received_by_name`, and `denominations`, and is `EDITOR`-only for `CONFIRMED` records. The backend derives `prepared_return_amount`, ignores zero quantities, requires official denominations, accepts gross cash up to `$1.00` below the official return or any excess, and returns `409 DELIVERY_PREPARATION_BLOCKED` for `INVALID_RECORD_STATE` or a larger cash shortfall. Delivery events appear in the record history.

Word generation uses `POST /api/deliveries/{delivery}/document` with the transient `document_return_amount` and `customer_will_give_change` fields. The document amount must be one of the three choices derived from the official return (`.00`, `.50`, or next whole peso `.00`). The Word endpoint compares gross cash with the selected amount: differences within `±$1.00` need no change, shortfalls greater than `$1.00` return `CASH_MISMATCH`, and excesses greater than `$1.00` require the change declaration or return `CHANGE_REQUIRED`. Laravel calculates change and does not persist the adjustment or declaration.

Mutation errors are handled from the backend response: `RECORD_UPDATE_BLOCKED`, `RECORD_CONFIRMATION_BLOCKED`, and `RECORD_CANCELLATION_BLOCKED` for invalid states or failed business validation; `RECORD_UPDATE_NO_CHANGES` for a no-change `CONFIRMED` edit; `IMAGE_UPLOAD_BLOCKED`/`IMAGE_ACCESS_BLOCKED` for image rules; and the existing AI retry errors. On `409`, refresh the Record so the UI reflects the current backend state. On `422`, preserve and display `code` and `details` when present.

## Acceptance Criteria

- List search and filters are reflected in requests and results, with distinct loading, empty, filtered-empty, and error states.
- Manual creation does not trigger AI processing, returns `CONFIRMED`, and clears the form after success while remaining on `/records/new`.
- Manual forms collect all required general fields and ordered movements, and map Laravel validation errors to relevant fields.
- Commission, return, totals, and state displayed by the UI come from the backend resource; they are not client-authoritative calculations.
- Only an Editor can create, upload, review, correct, confirm, cancel, or retry; a Viewer can consult records only.
- Replacement requires a reason and replaces the full aggregate for `MANUAL` or `IMAGE` records in `NEEDS_REVIEW` or `CONFIRMED`.
- Image upload sends the expected file types and size limits through the BFF, shows a `202`, redirects to the Record, and handles validation/authorization errors safely.
- Processing states poll at a bounded interval and stop on final states.
- The review screen shows the authorized image, current status, safe AI issues, and backend data; no private paths or raw AI response are exposed.
- Correction preserves source/status and never re-runs AI or replaces the image/AI evidence.
- Editing a `CONFIRMED` record is available to `EDITOR` only, reopens it to `NEEDS_REVIEW`, and offers confirmation afterward; `VIEWER` never sees the action.
- A `422 RECORD_UPDATE_NO_CHANGES` keeps the record `CONFIRMED` and displays the backend message without reopening it.
- The `Historial de cambios` panel is available to `EDITOR` and `VIEWER`, shows loading/error/empty/pagination states, and displays date, user, translated field, previous value, new value, and reason.
- The history panel refreshes after correction, reopening, confirmation, cancellation, or delivery preparation.
- A `CONFIRMED` record shows the delivery preparation link to `EDITOR` and `VIEWER`; the route remains directly accessible for read-only states and explains when preparation requires confirmation.
- The delivery screen displays the official return, gross cash, the three document amount choices, stale warning, and backend cash-reconciliation details.
- Word generation sends the selected document amount and customer-change declaration only with the document request; neither value is persisted.
- Word generation is blocked until unsaved cash denomination changes are saved.
- Delivery preparation is editable by `EDITOR` and read-only for `VIEWER`; the UI never sends `prepared_return_amount`.
- Delivery history entries use readable labels, including `denominations[1000.00]`-style entries, and appear in the existing history panel.
- Confirmation is available only for `NEEDS_REVIEW`; it revalidates on the backend and shows the resulting state. Unresolved AI issues do not block a complete, valid aggregate.
- Cancellation requires a reason, is available only for `NEEDS_REVIEW` or `ERROR`, and results in `CANCELED` with image access no longer available.
- `ERROR` records offer the existing retry action and cancellation, with safe diagnostics.
- A `409` from a concurrent state change refreshes the Record and shows the backend message.
- The form never shows a record as confirmed or canceled before Laravel responds successfully.
- Invalid financial data, forbidden actions, and invalid transitions are handled using backend responses.
- The flow supports keyboard navigation and accessible validation/status messaging.

## Phase 8.6 Verification

- Backend feature suite, Vitest, lint, TypeScript, production build, and changed-file Pint checks pass.
- Browser acceptance with `EDITOR` and `VIEWER` is documented in [`../06_testing.md`](../06_testing.md).
- The implementation checkpoint is recorded in [`../../11_technical_roadmap.md`](../../11_technical_roadmap.md).

## Phase 8.10 Verification

Verified on 2026-10-22:

- Backend feature suite (133 tests, 778 assertions), Vitest, lint, TypeScript, production build, and changed-file Pint checks pass.
- Browser acceptance with `EDITOR` and `VIEWER` covers upload, processing, review, correction, confirmation, cancellation, retry, and stale-state `409` handling as documented in [`../06_testing.md`](../06_testing.md).
- The live AI provider was Ollama `qwen2.5vl:3b`; its documented structural limitation produced an `ERROR` proposal, so the review/confirmation path used a controlled IMAGE Record in `NEEDS_REVIEW`. The same checkpoint covered the real upload, retry, and cancellation flow.
- The implementation checkpoint is recorded in [`../../11_technical_roadmap.md`](../../11_technical_roadmap.md).

## Phase 8.11 Verification

Verified on 2026-10-26:

- History contract tests cover pagination, ordering, role access, and response shape.
- `CONFIRMED` editing tests cover the reopen flow, the no-change `422`, and role visibility.
- The browser checkpoint demonstrates manual and image creation, correction with diff, confirmation, reopening with preserved source/Image/AI evidence, reconfirmation, cancellation, history display and pagination, loading/error/empty states, and `VIEWER` read access. The evidence is recorded in [`../06_testing.md`](../06_testing.md).
- Backend suite (149 tests, 922 assertions), Pint, Vitest (36 tests), ESLint, TypeScript, and production build pass.
- The implementation checkpoint is recorded in [`../../11_technical_roadmap.md`](../../11_technical_roadmap.md).

## Related Documents

- `../02_routing_and_rendering.md`
- `../03_api_bff_and_sessions.md`
- `../04_data_fetching_and_forms.md`
- `../05_ui_and_accessibility.md`
- `../06_testing.md`
- [`../../02_business_rules_and_states.md`](../../02_business_rules_and_states.md)
- [`../../03_data_model.md`](../../03_data_model.md)
- [`deliveries.md`](deliveries.md)
- [`../../../specs/11-delivery-preparation.md`](../../../specs/11-delivery-preparation.md)
- [`../../09_manual_capture.md`](../../09_manual_capture.md)
- [`../../10_ui_ux.md`](../../10_ui_ux.md) (Records and Review)
- [`../../backend/03_api_contract.md`](../../backend/03_api_contract.md)
- [`../../backend/05_validation_authorization.md`](../../backend/05_validation_authorization.md)
- [`../../11_technical_roadmap.md`](../../11_technical_roadmap.md) (phases 8.6–8.10)
