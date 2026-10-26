# Records Feature

## Status

**Phase 8.6 and SPEC 07 are implemented (2026-10-20).** Manual capture, record list/detail, backend validation and confirmation, role-aware actions, and movement-level affiliation/branch are implemented. **Phase 8.10 / SPEC 09 is implemented and verified (2026-10-22).** Image upload, processing progress, human review, correction, confirmation, cancellation, and retry are available with role-aware actions. **Phase 8.11 / SPEC 10 is implemented and verified (2026-10-26):** record change history with user, reason, and before/after values, plus audited editing and reopening of `CONFIRMED` records. The checkpoint is recorded in the roadmap.

## Goal and Scope

Support record consultation, manual capture, and the human review workflow. Phase 8.6 includes a general list and detail for all record sources/statuses. A valid manual record is confirmed by Laravel during save. Phase 8.10 adds the minimum functional flow that resolves IMAGE proposals in `NEEDS_REVIEW` and failed records in `ERROR`; the official UI/UX design remains in phase 8.15. Product screen details are in [`../../10_ui_ux.md`](../../10_ui_ux.md); authoritative state and business rules are in [`../../02_business_rules_and_states.md`](../../02_business_rules_and_states.md), and the manual capture contract is in [`../../09_manual_capture.md`](../../09_manual_capture.md).

## Target Routes and Screens

```text
/records
/records/new
/records/[recordId]
```

The list supports case-insensitive substring search by company, affiliation, and branch (searching every movement); filters include company, business year, month, week, status, and source. Provisional IMAGE records may lack company, period, movements, or movement values; the list and detail screens render these as unavailable. Pagination uses `page` and `per_page` (default 15, maximum 100), sorted by `updated_at` descending. Month filtering uses the month containing `week_end_date`. The detail/review screen presents period, company, each movement's affiliation/branch, backend-calculated amounts, current backend status, the authorized image, and safe AI diagnostics. `/records/new` allows choosing manual capture or image upload. The same `/records/{id}` screen acts as detail and as the review workspace for `NEEDS_REVIEW` and `ERROR` records.

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
- **Role and state:** `EDITOR` may create, upload, review, correct, reopen, confirm, cancel, and retry where the state allows, and may read history. `VIEWER` may list, view, and read history only. Do not show mutation actions for `VIEWER` or for states that do not allow the operation; the backend remains authoritative.

Do not calculate official commission, return, totals, or state transitions as client authority. The UI may display backend-provided calculated results, validation explanations, and safe AI diagnostics.

## API Contract

The implemented phase 8.6, SPEC 09, and SPEC 10 contracts are documented in [`../../backend/03_api_contract.md`](../../backend/03_api_contract.md): `GET /api/records`, `POST /api/records`, `GET /api/records/{id}`, full-replacement `PUT /api/records/{id}`, `POST /api/records/{id}/confirm`, `POST /api/records/{id}/cancel`, `GET /api/records/{id}/history`, `POST /api/records/from-image`, `POST /api/records/{record}/ai-processing/retry`, and authorized `GET /api/records/{record}/image`. All browser requests use same-origin services through the Next.js BFF. Standard validation errors are `422`; unauthorized, forbidden, missing-resource, and disallowed transition responses retain `401`, `403`, `404`, and `409` respectively.

The list query uses `search`, `company_id`, `year`, `month`, `week`, `status`, `source`, `page`, and `per_page`. Search spans company name, affiliation, and branch across any movement. A week filter is scoped by business `year`, not ISO; month filters by `week_end_date`. The create payload requires company, business period identifiers and valid date range, and non-empty ordered `details`. Each detail includes `affiliation`, `branch`, `income`, and exactly one of `commission_percentage` or fixed `commission`. Affiliation and branch are not accepted at the Record root. Laravel calculates `commission` and `return_amount`. Replacement submits the full aggregate plus required `reason` and accepts `MANUAL` or `IMAGE` records in `NEEDS_REVIEW` or `CONFIRMED`; a changed `CONFIRMED` replacement returns `NEEDS_REVIEW`, while a no-change replacement returns `422 RECORD_UPDATE_NO_CHANGES`. Confirmation accepts no client state or reason. Cancellation requires `reason` and accepts `NEEDS_REVIEW` or `ERROR`. IMAGE records may expose nullable company, period, and provisional detail fields; this does not relax MANUAL capture or confirmation validation.

History uses `GET /api/records/{id}/history?page=&per_page=` with `per_page` defaulting to 50 and capped at 100, ordered newest first. Each item exposes `field_name`, `previous_value`, `new_value`, `reason`, `created_at`, and `user`. The panel must preserve backend values and never infer changes client-side.

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
- The history panel refreshes after correction, reopening, confirmation, or cancellation.
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
- [`../../09_manual_capture.md`](../../09_manual_capture.md)
- [`../../10_ui_ux.md`](../../10_ui_ux.md) (Records and Review)
- [`../../backend/03_api_contract.md`](../../backend/03_api_contract.md)
- [`../../backend/05_validation_authorization.md`](../../backend/05_validation_authorization.md)
- [`../../11_technical_roadmap.md`](../../11_technical_roadmap.md) (phases 8.6–8.10)
