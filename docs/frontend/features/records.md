# Records Feature

## Status

**Contract defined for phase 8.6; implementation planned.** The domain schema exists. Records endpoints and frontend workflows are not implemented until their routes, services, and tests are delivered.

## Goal and Scope

Support record consultation and manual capture. Phase 8.6 includes a general list and detail for all record sources/statuses. A valid manual record is confirmed by Laravel during save; later edits to confirmed records are deferred to a future phase. Product screen details are in [`../../10_ui_ux.md`](../../10_ui_ux.md); authoritative state and business rules are in [`../../02_business_rules_and_states.md`](../../02_business_rules_and_states.md) and the manual capture contract is in [`../../09_manual_capture.md`](../../09_manual_capture.md).

## Target Routes and Screens

```text
/records
/records/new
/records/[recordId]
```

The list supports case-insensitive substring search by company, operation number, affiliation, and branch; filters include company, business year, month, week, status, and source. Pagination uses `page` and `per_page` (default 15, maximum 100), sorted by `updated_at` descending. Month filtering uses the month containing `week_end_date`. The detail/review screen presents period, company, movement details, backend-calculated amounts, and current backend status. The phase 8.6 manual flow does not implement image viewing or upload.

## Capture and Review Flows

- **Manual capture:** require company, business year/week, valid date range (start <= end), affiliation, branch, and one or more ordered movements. Week numbers are not ISO validated and dates need not be Monday/Sunday. Each movement submits income and exactly one commission input: percentage or fixed amount. Do not submit source, status, order, commission result, return, or totals as authoritative values.
- **Manual save:** Laravel validates and confirms a valid manual record in the create request. It returns calculated commission, return, and aggregate totals. After success, show a confirmation message, clear the form, and remain on `/records/new` for the next capture.
- **Manual detail:** show the backend's `CONFIRMED` status. Editing confirmed records is deferred to a future phase.
- **Role and state:** `EDITOR` may create, edit eligible manual records, and confirm them. `VIEWER` may list and view only. Do not show mutation actions for `VIEWER`, records from another source, or records in another state; the backend remains authoritative.
- **Image-based capture, AI review, confirmed-record editing/reopening, and cancellation:** outside phase 8.6; do not expose these actions as implemented workflows in this phase.

Do not calculate official commission, return, totals, or state transitions as client authority. The UI may display backend-provided calculated results and validation explanations.

## API Contract

The phase 8.6 target contract is documented in [`../../backend/03_api_contract.md`](../../backend/03_api_contract.md): `GET /api/records`, `POST /api/records`, `GET /api/records/{id}`, full-replacement `PUT /api/records/{id}`, and `POST /api/records/{id}/confirm` for separate review-required flows. All browser requests use same-origin services through the Next.js BFF. Standard validation errors are `422`; unauthorized, forbidden, missing-resource, and disallowed transition responses retain `401`, `403`, `404`, and `409` respectively.

The list query uses `search`, `company_id`, `year`, `month`, `week`, `status`, `source`, `page`, and `per_page`. Search spans company name, operation number, affiliation, and branch. A week filter is scoped by business `year`, not ISO; month filters by `week_end_date`. The create payload requires company, business period identifiers and valid date range, affiliation, branch, and non-empty ordered `details`. Each detail includes `operation_number`, `income`, and exactly one of `commission_percentage` or fixed `commission`. Laravel calculates `commission` and `return_amount`. Replacement submits the full aggregate plus required `reason`; future review-required confirmation accepts no client state or reason.

## Acceptance Criteria

- List search and filters are reflected in requests and results, with distinct loading, empty, filtered-empty, and error states.
- Manual creation does not trigger AI processing, returns `CONFIRMED`, and clears the form after success while remaining on `/records/new`.
- Manual forms collect all required general fields and ordered movements, and map Laravel validation errors to relevant fields.
- Commission, return, totals, and state displayed by the UI come from the backend resource; they are not client-authoritative calculations.
- Only an Editor can create manual records; a Viewer can consult records only. Confirmed-record editing is deferred.
- Replacement requires a reason and replaces the full aggregate only for manual records in `NEEDS_REVIEW`.
- The form never shows a record as confirmed before Laravel responds successfully.
- Invalid financial data, forbidden actions, and invalid transitions are handled using backend responses.
- The flow supports keyboard navigation and accessible validation/status messaging.

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
