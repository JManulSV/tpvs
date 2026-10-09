# Records Feature

## Status

**Planned.** The domain schema exists, but record business endpoints and frontend workflows are not implemented.

## Goal and Scope

Support the primary operational workflow: find records, create manual or image-based records, review extracted/captured data, and perform permitted state actions. Product screen details are in [`../../10_ui_ux.md`](../../10_ui_ux.md); authoritative state and business rules are in [`../../02_business_rules_and_states.md`](../../02_business_rules_and_states.md).

## Target Routes and Screens

```text
/records
/records/new
/records/[recordId]
```

The list supports search by company, operation number, affiliation, and branch; filters include company, year, month, week, status, and source. The record detail/review screen presents period, company, image when present, movement details, validation findings, and current backend status.

## Capture and Review Flows

- **Manual capture:** create the record and movement data without creating an AI processing attempt; show backend validation and review state.
- **Image-based capture:** upload an image through the backend contract; present processing status and later reviewable extraction. Browser code never calls Ollama.
- **Review:** allow only the actions supported by the current backend state and role. The backend is authoritative for validation and transitions.
- **Confirmed changes:** show the backend's required return-to-review workflow; never edit a confirmed record as if it remained confirmed.
- **Cancellation:** present only when the state and permissions allow it, and handle server rejection.

Do not calculate official commission, return, totals, or state transitions as client authority. The UI may display backend-provided calculated results and validation explanations.

## API Contract

Record endpoints are planned and must be verified against [`../../backend/03_api_contract.md`](../../backend/03_api_contract.md) and implemented Laravel routes/tests. Agree on list pagination, filters, image upload content type, processing polling/refresh strategy, request DTOs, state-transition endpoints, history, and errors before relying on a contract.

## Acceptance Criteria

- List search and filters are reflected in requests and results, with distinct loading, empty, filtered-empty, and error states.
- Manual creation does not trigger AI processing.
- Image uploads display pending and failure states and do not expose private paths.
- Review shows the record's current server state and backend validation result.
- AI results are clearly presented as provisional; only an authorized human can confirm through Laravel.
- Invalid financial data, forbidden actions, and invalid transitions are handled using backend responses.
- User actions do not make records appear confirmed before the server responds successfully.
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
