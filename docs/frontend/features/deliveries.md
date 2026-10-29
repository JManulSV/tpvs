# Deliveries Feature

## Status

**SPEC 11 / phase 8.12 complete (2026-10-29). SPEC 12 / phase 8.13 approved (2026-10-29); implementation in progress.** The delivery preparation API, the minimum frontend workflow, and the Word document download action are defined below.

## Goal and Scope

Allow an authorized Editor to prepare or update the single editable Delivery associated with a confirmed Record and reconcile its cash denominations. Product behavior is defined in [`../../08_document_generation.md`](../../08_document_generation.md), [`../../10_ui_ux.md`](../../10_ui_ux.md), and [`../../02_business_rules_and_states.md`](../../02_business_rules_and_states.md).

Phase 8.12 covers delivery preparation. Phase 8.13 adds the Word document download for `EDITOR`. Reports, delivery versions, delivery states, stored documents, and the official UI/UX design remain outside these phases.

## Route and Screen

Dedicated route: `/records/[recordId]/delivery`, reachable from the record detail view when the Record is `CONFIRMED`.

The interface includes:

- delivery date;
- delivering person;
- optional receiving person;
- current official return amount from the backend;
- the 11 official cash denominations with quantities;
- a visual cash total and reconciliation feedback;
- a stale-preparation warning when the prepared amount differs from the current return;
- associated image availability, without requiring it for preparation;
- save action for `EDITOR`;
- read-only view for `VIEWER`.

- a `Generar documento Word` action for `EDITOR` (phase 8.13).

## Business Contract

- Delivery is available only for a confirmed Record.
- Preparing again updates the same Delivery; do not represent versions or delivery states.
- The backend derives `prepared_return_amount` from the current Record return total; the client must not send it.
- The backend validates that the denomination sum equals the current calculated return amount in integer cents.
- Only positive denomination quantities are persisted. Zero-quantity rows are ignored; an empty breakdown is valid only when the return is `0.00`.
- The official denomination set is `1000`, `500`, `200`, `100`, `50`, `20`, `10`, `5`, `2`, `1`, and `0.50`.
- Show a clear stale-preparation message when `prepared_return_amount` differs from the current Record return.
- A stale preparation is updated in the same Delivery row after the Record is confirmed again.
- An identical preparation is an idempotent success and does not create history.
- Delivery preparation does not change the Record status, fields, movements, image, or AI evidence.
- Laravel remains authoritative for reconciliation, authorization, and eligibility.

## API Contract

The approved contract for this phase is:

```text
GET  /api/records/{record}/delivery
PUT  /api/records/{record}/delivery
```

`GET` returns `{ "data": null }` or the delivery resource with `prepared_return_amount`, `current_return_amount`, `is_stale`, and ordered `denominations`. Both `EDITOR` and `VIEWER` may read.

`PUT` requires `delivery_date`, `delivered_by_name`, optional `received_by_name`, and `denominations`. It creates or replaces the single Delivery and returns the same resource with `200`. Only `EDITOR` may mutate, and only for a `CONFIRMED` Record.

The frontend must preserve and display backend errors:

- `422` for invalid shape, names, unsupported denominations, duplicates, or invalid quantities;
- `409 DELIVERY_PREPARATION_BLOCKED` with `details.reason=INVALID_RECORD_STATE` or `CASH_MISMATCH`;
- `403` for a Viewer mutation;
- `401` for an expired session;
- `404` for a missing Record.

The cash mismatch response includes `expected_amount`, `cash_amount`, and `difference`. On `409 INVALID_RECORD_STATE`, refresh the Record and disable editing.

### Word Document (SPEC 12)

```text
POST /api/deliveries/{delivery}/document
```

Only `EDITOR` may call it. The response is a binary `.docx`, not JSON. The service requests it with `responseType: "blob"`, reads the file name from `Content-Disposition`, and triggers the browser download. The BFF already preserves `content-type`, `content-disposition`, and the binary body.

Blocked generation returns `409 DOCUMENT_GENERATION_BLOCKED` with `details.reason`:

| Reason | Message shown |
| --- | --- |
| `INVALID_RECORD_STATE` | El registro debe estar confirmado para generar el documento. |
| `MISSING_IMAGE` | Adjunta una imagen al registro para generar el documento. |
| `STALE_DELIVERY` | La entrega usa un importe anterior; actualízala antes de generar el documento. |
| `CASH_MISMATCH` | El desglose de efectivo no coincide con el retorno actual. |
| `IMAGE_UNAVAILABLE` | No se pudo leer la imagen del registro. |

Because the error body arrives as a `Blob`, the service parses it as JSON before mapping the reason.

## UI Behavior

- The record detail shows `Preparación de entrega` for `CONFIRMED` records.
- `EDITOR` sees `Preparar entrega` or `Actualizar entrega`; `VIEWER` sees `Ver entrega`.
- The delivery screen loads the Record and Delivery queries.
- The cash total is calculated for visual feedback only; the backend is authoritative.
- A stale warning is shown when `is_stale=true`.
- A cash mismatch displays the backend expected/cash/difference values.
- Saving invalidates the delivery query and the Record history query.
- `VIEWER` sees read-only values and no save action.
- If the Record is not `CONFIRMED` and no Delivery exists, the screen explains that preparation requires confirmation.
- The history panel must label and format delivery fields and `denominations[1000.00]`-style entries.
- `Generar documento Word` is shown only to `EDITOR`. It is disabled, with a visible reason, when no Delivery exists, the Delivery is stale, the Record is not `CONFIRMED`, or the Record has no image.
- The action prevents duplicate submission while generating.
- A `409` shows the translated reason; `STALE_DELIVERY` and `INVALID_RECORD_STATE` refresh the Record and Delivery queries.
- A successful download invalidates the Record history query. The history panel labels `document_generated` as `Documento Word generado`.

## Acceptance Criteria

- An Editor can load, create, and update the single Delivery for an eligible confirmed Record.
- A Viewer can read the Delivery but cannot mutate it.
- Cash mismatch, stale amount, and unsupported Record state are explained from backend responses.
- The interface prevents duplicate submission while saving.
- The interface shows the current return, cash total, stale warning, and reconciliation result.
- The interface invalidates delivery and history queries after a successful save.
- A successful save reflects the backend resource; the client never treats its own total as authoritative.
- An Editor can download the Word document for a valid Delivery with an image; blocked cases show their reason and a Viewer sees no generation action.

## Related Documents

- `../03_api_bff_and_sessions.md`
- `../04_data_fetching_and_forms.md`
- `../05_ui_and_accessibility.md`
- `../06_testing.md`
- [`../../02_business_rules_and_states.md`](../../02_business_rules_and_states.md) (Delivery rules)
- [`../../03_data_model.md`](../../03_data_model.md) (Delivery entities)
- [`../../08_document_generation.md`](../../08_document_generation.md)
- [`../../10_ui_ux.md`](../../10_ui_ux.md) (Prepare Delivery and outdated delivery)
- [`../../backend/03_api_contract.md`](../../backend/03_api_contract.md) (Delivery Preparation Contract)
- [`../../backend/08_files_and_documents.md`](../../backend/08_files_and_documents.md)
- [`../../11_technical_roadmap.md`](../../11_technical_roadmap.md) (phases 8.12–8.13)
- [`../../../specs/11-delivery-preparation.md`](../../../specs/11-delivery-preparation.md)
- [`../../../specs/12-delivery-word-document.md`](../../../specs/12-delivery-word-document.md)
