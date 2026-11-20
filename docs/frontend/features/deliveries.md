# Deliveries Feature

## Status

**SPEC 11 / phase 8.12 complete (2026-10-29). SPEC 12 / phase 8.13 complete (2026-10-29). SPEC 14 implemented; focused backend/frontend checks pass.** The delivery flow includes the approved document-only cash adjustment and customer-change behavior.

**SPEC 22 implemented in code (frontend only); manual browser verification is pending.** The screen uses the single-action-bar layout described in [Delivery preparation screen (SPEC 22)](#delivery-preparation-screen-spec-22). The backend, API, and business rules are unchanged.

## Goal and Scope

Allow an authorized Editor to prepare or update the single editable Delivery associated with a confirmed Record and reconcile its cash denominations. Product behavior is defined in [`../../document-generation.md`](../../document-generation.md), [`../../ui-ux.md`](../../ui-ux.md), and [`../../business-rules.md`](../../business-rules.md).

Phase 8.12 covers delivery preparation. Phase 8.13 adds the Word document download for `EDITOR`. Reports, delivery versions, delivery states, stored documents, and the official UI/UX design remain outside these phases.

## Route and Screen

Dedicated route: `/records/[recordId]/delivery`, reachable from the record detail view when the Record is `CONFIRMED`.

The interface includes:

- delivery date;
- delivering person;
- optional receiving person;
- current official return amount from the backend;
- the 11 official cash denominations with quantities;
- a visual gross-cash total and reconciliation feedback;
- three document-only return choices derived from the official return (`.00`, `.50`, or next whole peso `.00`);
- a conditional `El cliente dará cambio` declaration for a gross excess greater than `$1.00` against the selected document amount;
- a stale-preparation warning when the prepared amount differs from the current return;
- associated image availability, without requiring it for preparation;
- save action for `EDITOR`;
- read-only view for `VIEWER`.

- a `Generar documento Word` action for `EDITOR` (phase 8.13). Since SPEC 22 it lives in the action bar as `Generar recibo (Word)`, next to `Guardar entrega` / `Actualizar entrega`.

## Delivery preparation screen (SPEC 22)

Layout of `/records/[recordId]/delivery`. Components live in `frontend/src/features/deliveries/components/`. The screen reuses the services, hooks, schema, and utilities of this feature; there are no new endpoints.

- **Header:** a «Registro» link to the record, one `<h1>` (`Preparar entrega`, or `Preparar nueva entrega` when the preparation is stale), the status badge, the source badge, and the period line from `getRecordPeriodLine`.
- **Banners:** a stale preparation shows `role="status"` with «Retorno actual» and «Entrega preparada». Blocking issues show `role="alert"`, each with its backend code in `<code>`: `CASH_MISMATCH` for a shortfall (official return first, then document amount) and a missing image. Server errors (`409 CASH_MISMATCH` with its expected, cash, and difference amounts, and save or Word errors) also go there. A `422` field error that has no visible field goes to this banner too.
- **Layout:** `@container`, two columns from 60 rem (`grid-cols-[minmax(0,1fr)_340px]`), one column below in this order: amounts hero, «Datos de la entrega», «Monto del documento», «Desglose de efectivo», «Documento asociado», «Para generar el recibo».
- **Amounts hero:** «Retorno oficial» (the record's current return), «Documento», «Efectivo», and a status strip from `getCashCheck().label`.
- **Delivery data:** «Entrega» is prefilled with the session user's name (`userName`) when there is no saved delivery. «Fecha de entrega» defaults to today's local date.
- **Document amount:** a `radiogroup` with three `role="radio"` options derived from the official return. Arrow keys change the option and move focus. Changing it clears the change declaration.
- **Cash breakdown (`delivery-cash-breakdown.tsx`):** denominations are grouped into «Billetes» and «Monedas», each with its subtotal. Every row has `−`, a numeric input, and `+`. Input goes through `parseQuantityInput` (digits only, empty becomes 0, capped at 999). `−` and `+` go through `stepQuantity`, which also caps at 0 and 999. Rows at 0 are muted. The «Efectivo total» row shows the piece count. Above $1.00 of excess it shows `CHANGE_REQUIRED` and the «El cliente dará cambio · {monto} (calculado)» checkbox.
- **Document image (`delivery-image-card.tsx`):** uses `RecordImageCard` with the title «Documento asociado». Without an image, an Editor on a `CONFIRMED` record can attach one (see below).
- **Readiness checklist (`delivery-readiness-card.tsx`):** «Imagen asociada», «Efectivo conciliado con el retorno oficial», «Efectivo conciliado con el monto del documento», «Cambio al cliente declarado» (only with excess), and «Entrega guardada». Each item shows «Listo» or «Pendiente».
- **Action bar (`delivery-action-bar.tsx`):** `role="region"` named «Acciones de la entrega». It holds the hint text (`role="status"`), «Cancelar», «Guardar entrega» / «Actualizar entrega» (shown only when the form differs from the saved delivery), and «Generar recibo (Word)». On mobile it is fixed above `AppBottomNav` (`bottom-[calc(5.25rem+env(safe-area-inset-bottom))]`), and from `md` it is `sticky bottom-4`. On mobile a spacer keeps the bar from covering the last content.
- **Read-only (`delivery-read-only.tsx`):** for `VIEWER`, or for an `EDITOR` when the record is not `CONFIRMED`. It uses the same layout without inputs, the document amount, the checklist, the action bar, or the attach button. It shows «Retorno oficial» and «Efectivo», the saved data as a `<dl>`, and only denominations with a quantity above zero.

### Pure rules (`features/deliveries/utils.ts`)

These functions hold all the decisions above; the components only display them.

- `getCashCheck`: compares cash with the official return and the document amount, using the $1.00 tolerance, and returns the tone and label.
- `isDeliveryClean`: true only when a saved delivery exists, is **not** stale, and the date, names (trimmed), and denomination key all match. A stale delivery is never clean, so «Actualizar entrega» stays available and Word is not offered until the delivery is saved again.
- `getDeliveryReadiness`: returns the checklist, the bar hint (the first matching rule in the order of SPEC 22), and `canSave` / `canGenerate`.
- `getDeliveryHeading`: the title and the save label.

### Attaching an image from the delivery

- Shown only to `EDITOR` on a `CONFIRMED` record without an image. The file is checked with `validateImageFile` (JPEG, PNG, or WebP; 10 MiB or less) before any request.
- Sends `POST /api/records/{id}/image` through `recordsService.attachImage` and `useAttachRecordImage`, which refreshes the record detail and its history.
- A `422` or `409` shows the backend message in `role="alert"`. Replacing an existing image is not supported here.

### Out of scope for SPEC 22

- Backend, API, migrations, and business rules.
- Replacing an existing image from the delivery.
- Persisting the document amount or the change declaration.
- A warning for unsaved changes when leaving the page.
- A Word preview.
- Changes to the delivery card on the record detail, the shell, or other screens.

## Business Contract

- Delivery is available only for a confirmed Record.
- Preparing again updates the same Delivery; do not represent versions or delivery states.
- The backend derives `prepared_return_amount` from the current Record return total; the client must not send it.
- On save, Laravel keeps `prepared_return_amount` equal to the official current Record return and accepts gross cash up to `$1.00` below it or any excess; a larger shortfall is rejected.
- Word generation uses the selected document-only amount. It is one of three options derived from the official return: same whole pesos `.00`, same whole pesos `.50`, or next whole peso `.00`.
- Word reconciliation compares gross cash with that selected amount in integer cents. Differences within `±$1.00` require no change; shortfalls greater than `$1.00` are blocked; excesses greater than `$1.00` require the Editor to declare that the customer will give change.
- The backend calculates any change as gross cash minus the selected document amount. The selected amount and change declaration are sent only when generating Word and are not persisted or added to history.
- Only positive denomination quantities are persisted. Zero-quantity rows are ignored; an empty breakdown remains valid only when the return is `0.00`.
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

`PUT` requires `delivery_date`, `delivered_by_name`, optional `received_by_name`, and `denominations`. It creates or replaces the single Delivery and returns the same resource with `200`. Only `EDITOR` may mutate, and only for a `CONFIRMED` Record. A shortfall greater than `$1.00` from the official return returns `409 CASH_MISMATCH`; any excess is accepted at preparation time.

The frontend must preserve and display backend errors:

- `422` for invalid shape, names, unsupported denominations, duplicates, or invalid quantities;
- `409 DELIVERY_PREPARATION_BLOCKED` with `details.reason=INVALID_RECORD_STATE` or `CASH_MISMATCH`;
- `403` for a Viewer mutation;
- `401` for an expired session;
- `404` for a missing Record.

The cash mismatch response includes `expected_amount`, `cash_amount`, and `difference`. On `409 INVALID_RECORD_STATE`, refresh the Record and disable editing.

### Word Document (SPEC 12, extended by SPEC 14)

```text
POST /api/deliveries/{delivery}/document
```

Only `EDITOR` may call it. The request sends `document_return_amount` and `customer_will_give_change`; these are transient values and are not saved. The response is a binary `.docx`, not JSON. The service requests it with `responseType: "blob"`, reads the file name from `Content-Disposition`, and triggers the browser download. The BFF already preserves `content-type`, `content-disposition`, and the binary body.

Blocked generation returns `409 DOCUMENT_GENERATION_BLOCKED` with `details.reason`:

| Reason | Message shown |
| --- | --- |
| `INVALID_RECORD_STATE` | El registro debe estar confirmado para generar el documento. |
| `MISSING_IMAGE` | Adjunta una imagen al registro para generar el documento. |
| `STALE_DELIVERY` | La entrega usa un importe anterior; actualízala antes de generar el documento. |
| `CASH_MISMATCH` | El efectivo contado está más de $1.00 por debajo del importe de referencia. |
| `CHANGE_REQUIRED` | Indica que el cliente dará cambio para generar el documento. |
| `IMAGE_UNAVAILABLE` | No se pudo leer la imagen del registro. |

For `CASH_MISMATCH` and `CHANGE_REQUIRED`, the backend includes `expected_amount`, `cash_amount`, and `difference`. Because the error body arrives as a `Blob`, the service parses it as JSON before mapping the reason.

## UI Behavior

- The record detail shows `Preparación de entrega` for `CONFIRMED` records.
- `EDITOR` sees `Preparar entrega` or `Actualizar entrega`; `VIEWER` sees `Ver entrega`.
- The delivery screen loads the Record and Delivery queries.
- The gross cash total and three document amount options are calculated for immediate feedback; Laravel remains authoritative.
- The change declaration is shown only when gross cash exceeds the selected document amount by more than `$1.00`; the amount of change is calculated and not editable.
- Word generation is disabled if the denomination form differs from the last saved Delivery; the Editor must save the cash breakdown first.
- A stale warning is shown when `is_stale=true`.
- A preparation cash shortfall greater than `$1.00` displays backend expected/cash/difference values; a Word-generation `CASH_MISMATCH` or `CHANGE_REQUIRED` preserves those values and explains the correction needed.
- Word generation sends the selected document amount and change declaration without adding either to the preparation payload.
- The Word document shows the gross denomination total, selected amount in digits and words, and calculated customer change when the excess is greater than `$1.00`.
- Saving invalidates the delivery query and the Record history query.
- `VIEWER` sees read-only values and no save action.
- If the Record is not `CONFIRMED` and no Delivery exists, the screen explains that preparation requires confirmation.
- The history panel must label and format delivery fields and `denominations[1000.00]`-style entries.
- `Generar documento Word` is shown only to `EDITOR`. It is disabled, with a visible reason, when no Delivery exists, the Delivery is stale, the Record is not `CONFIRMED`, or the Record has no image.
- The action prevents duplicate submission while generating.
- A `409` shows the translated reason; `STALE_DELIVERY` and `INVALID_RECORD_STATE` refresh the Record and Delivery queries.
- If the cash denomination breakdown differs from the last saved preparation, Word generation is disabled until the Editor saves it.
- A successful download invalidates the Record history query. The history panel labels `document_generated` as `Documento Word generado`.

## Acceptance Criteria

- An Editor can load, create, and update the single Delivery for an eligible confirmed Record.
- A Viewer can read the Delivery but cannot mutate it.
- Cash mismatch, stale amount, and unsupported Record state are explained from backend responses.
- The interface prevents duplicate submission while saving.
- The interface shows the current return, cash total, stale warning, and reconciliation result.
- The Editor can choose the three document amounts derived from the official return; the chosen amount and customer-change declaration are sent only with Word generation.
- A gross excess over the document amount greater than `$1.00` requires customer-change declaration; a shortfall greater than `$1.00` is blocked, and differences within `±$1.00` need no change line.
- The generated Word shows the selected amount and words, gross cash total, and calculated customer change when applicable, without persisting the temporary choices.
- Word generation is disabled while the denomination breakdown differs from the last successfully saved preparation.
- The interface invalidates delivery and history queries after a successful save.
- A successful save reflects the backend resource; the client never treats its own total as authoritative.
- A shortfall over `$1.00` at preparation is explained from backend values; gross cash excess is allowed and does not change `prepared_return_amount`.
- Document amount choices and customer-change declaration are sent only to Word generation and are never persisted.
- The Word output shows the selected amount in digits and words, gross cash, and calculated customer change when applicable.
- An Editor can download the Word document for a valid Delivery with an image; blocked cases show their reason and a Viewer sees no generation action.
- `document_return_amount` and `customer_will_give_change` are sent only in the Word request; the saved delivery keeps the official return and denominations unchanged.
- The Word request is blocked while cash denominations have unsaved edits.

## Related Documents

- `../conventions.md`
- `../conventions.md`
- `../ui-and-accessibility.md`
- `../testing.md`
- [`../../business-rules.md`](../../business-rules.md) (Delivery rules)
- [`../../data-model.md`](../../data-model.md) (Delivery entities)
- [`../../document-generation.md`](../../document-generation.md)
- [`../../ui-ux.md`](../../ui-ux.md) (Prepare Delivery and outdated delivery)
- [`../../backend/api-contract.md`](../../backend/api-contract.md) (Delivery Preparation Contract)
- [`../../backend/files-and-documents.md`](../../backend/files-and-documents.md)
- [`../../roadmap.md`](../../roadmap.md) (phases 8.12–8.13)
- [`../../../specs/11-delivery-preparation.md`](../../../specs/11-delivery-preparation.md)
- [`../../../specs/12-delivery-word-document.md`](../../../specs/12-delivery-word-document.md)
- [`../../../specs/14-delivery-cash-adjustment.md`](../../../specs/14-delivery-cash-adjustment.md)
