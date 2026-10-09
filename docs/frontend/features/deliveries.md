# Deliveries Feature

## Status

**Planned.** Delivery persistence is part of the domain schema, but delivery API endpoints and frontend workflows are not implemented.

## Goal and Scope

Allow an authorized Editor to prepare or update the single editable Delivery associated with a confirmed Record, reconcile cash denominations, and request an on-demand Word document. Product behavior is defined in [`../../08_document_generation.md`](../../08_document_generation.md), [`../../10_ui_ux.md`](../../10_ui_ux.md), and [`../../02_business_rules_and_states.md`](../../02_business_rules_and_states.md).

## Route and Screen

Target screen: delivery preparation for a confirmed record, reachable from that record's detail view. The interface includes delivery date, delivering/receiving names, official return amount, cash denominations and quantities, reconciliation feedback, associated image availability, and document-generation action.

## Business Contract

- Delivery is available only for a confirmed Record.
- Preparing again updates the same Delivery; do not represent versions or delivery states.
- The backend validates that denomination value × quantity equals the current calculated return amount.
- Only positive denomination quantities are persisted; the official denomination set includes `0.50`.
- Show a clear stale-preparation message when prepared return differs from the current record return.
- Word generation requires a valid, current preparation and an associated Image; the generated file is on-demand.
- Laravel remains authoritative for reconciliation and eligibility.

## API Contract

The documented target endpoints include:

```text
GET  /api/records/{record}/delivery
PUT  /api/records/{record}/delivery
POST /api/deliveries/{delivery}/document
```

Verify their implementation, authorization, payloads, validation errors, and download headers before using them. Word responses are binary downloads, not JSON; preserve content type and disposition through the BFF.

## Acceptance Criteria

- An Editor can load and update the single Delivery for an eligible confirmed Record.
- Cash mismatch, stale amount, and missing image are explained based on backend responses/data.
- The interface prevents duplicate submit while saving or generating a document.
- A Viewer cannot mutate preparation or generate Word; Laravel enforces the restriction.
- A successful document response downloads with the server-provided filename and content type.
- A failed document response is shown as an error rather than saved as a file.

## Related Documents

- `../03_api_bff_and_sessions.md`
- `../04_data_fetching_and_forms.md`
- `../05_ui_and_accessibility.md`
- `../06_testing.md`
- [`../../02_business_rules_and_states.md`](../../02_business_rules_and_states.md) (Delivery rules)
- [`../../03_data_model.md`](../../03_data_model.md) (Delivery entities)
- [`../../08_document_generation.md`](../../08_document_generation.md)
- [`../../10_ui_ux.md`](../../10_ui_ux.md) (Prepare Delivery and outdated delivery)
- [`../../backend/08_files_and_documents.md`](../../backend/08_files_and_documents.md)
- [`../../11_technical_roadmap.md`](../../11_technical_roadmap.md) (phases 8.12–8.13)
