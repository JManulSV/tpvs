# Domain Workflows

The workflows below are target MVP workflows. They are planned until implemented in Laravel and covered by tests.

## Workflow Documentation Template

Every workflow should document:

```text
Status
Purpose
Entry point
Preconditions
Steps
Database changes
Transaction boundary
Authorization
Failure behavior
Jobs or events
Tests
```

## Manual Record

**Status:** Implemented for phase 8.6 (2026-10-15); automated tests and browser acceptance passed.

**Entry points:** `POST /api/records`, `PUT /api/records/{id}`, and `POST /api/records/{id}/confirm`.

**Preconditions:** The user is authenticated. Mutations require `EDITOR`. Creation and replacement reference an existing Company and supply required record fields and at least one valid detail.

**Workflow:**

1. An Editor creates a manual Record with company, business year/week, period dates, and one or more ordered movement inputs. Each movement includes its own affiliation, branch, income, and one commission input.
2. The backend validates that dates exist and that the start date is not after the end date. It does not impose ISO week rules or weekday boundaries.
3. For each detail, the backend accepts exactly one commission input: percentage or fixed amount. It calculates the commission using decimal arithmetic and `ROUND_HALF_UP` for percentages, then calculates return as income minus commission.
4. A transaction persists the Record as `source=MANUAL`, `status=CONFIRMED`, its RecordDetails, and a single History event (`record_created`, null to `CONFIRMED`, authenticated user, reason `Registro creado manualmente`). No Image, AIProcessing, or AI job is created.
5. An Editor may replace the full aggregate while it is `MANUAL` in `NEEDS_REVIEW` or `CONFIRMED`. Replacement requires a reason and writes one field-level History row per changed field; a no-change `NEEDS_REVIEW` replacement writes `record_updated` with null values.
6. A `CONFIRMED` replacement with changes additionally writes `status` `CONFIRMED -> NEEDS_REVIEW` and leaves the record in `NEEDS_REVIEW`; a no-change `CONFIRMED` replacement returns `422 RECORD_UPDATE_NO_CHANGES` and writes nothing. The Record, details, status event, and field diff are committed together under a row lock.
7. The explicit confirmation endpoint remains for records that start in `NEEDS_REVIEW`, such as AI-assisted capture or a reopened confirmed record. It is not an extra step for valid first-time manual capture.

**Failure behavior:** Invalid request or business input returns `422`; an unsupported source/state transition returns `409`; a Viewer mutation returns `403`. Create, replace, and confirmation leave no partial writes on failure.

**Tests:** Verify Editor/Viewer authorization, business week values without ISO interpretation, custom valid date ranges, financial rounding and fixed commission, invalid input, no Image/AIProcessing/job, atomic creation/history and replacement/history, and disallowed transitions.

## Image Record

**Storage and association — phase 8.7:**

1. An authenticated Editor uploads one JPEG, PNG, or WebP image of at most 10 MiB to `POST /api/records/{record}/image`.
2. Laravel validates file content and Record state, stores the file under a generated key on a private local disk, and transactionally associates the Image metadata with the Record.
3. A manual Record may receive its image later. Upload alone does not change Record source/status, create `AIProcessing` or History, or dispatch a job.
4. An authenticated Editor or Viewer retrieves the image bytes through `GET /api/records/{record}/image`; no public file URL is exposed.
5. An Editor may replace an image only if it has no AI processing attempts. The old file is deleted after the new association commits.
6. Canceled Records do not allow image upload, replacement, or viewing. A non-canceled Record without an image returns `404` on GET.

**AI extraction — phases 8.8–8.9:**

1. A later AI workflow creates a processing attempt for the stored Image.
2. AI output is normalized and validated.
3. The Record enters `NEEDS_REVIEW` or `ERROR`.
4. An Editor reviews and confirms or cancels it.

## Image Review, Correction, Confirmation, and Cancellation

**Status:** SPEC 09 / phase 8.10 implemented and verified (2026-10-22); checkpoint recorded in the roadmap.

**Purpose:** Let an authorized Editor resolve the output of image extraction or a failed processing attempt without bypassing backend validation or the human-confirmation rule.

**Entry points:** `GET /api/records/{id}`, `GET /api/records/{record}/image`, `PUT /api/records/{record}`, `POST /api/records/{record}/confirm`, `POST /api/records/{record}/cancel`, and the existing `POST /api/records/{record}/ai-processing/retry`.

**Preconditions:** The user is authenticated. Mutations require `EDITOR`, CSRF where applicable, and a Record in the state accepted by the operation. The Editor can see the authorized image and the safe `ai_processing` diagnostics.

**Workflow:**

1. An IMAGE Record reaches `NEEDS_REVIEW` after a usable extraction, or `ERROR` after a definitive failure.
2. The Editor opens the Record, sees its status, authorized image, safe AI issues, and the currently persisted business data.
3. To correct a `NEEDS_REVIEW` Record, the Editor submits the complete aggregate (`company_id`, business period, ordered movements, and a reason) through the existing replacement endpoint.
4. Laravel rechecks state, validates the complete aggregate, recalculates financial values, replaces the details, and writes one field-level History row per changed field with the reason. A no-change replacement in `NEEDS_REVIEW` writes a single `record_updated` event with null values. `source`, `status`, Image, and `AIProcessing` remain unchanged.
5. The Editor confirms a `NEEDS_REVIEW` Record only after backend validation succeeds. Confirmation transactionally sets `CONFIRMED` and writes the source-appropriate `status` History event. AI issues are review signals and do not block a complete, valid aggregate.
6. The Editor may cancel a `NEEDS_REVIEW` or `ERROR` Record with a reason. Cancellation transactionally sets `CANCELED` and writes a `status` History event with the prior state and the reason. Image and AI evidence are preserved.
7. For `ERROR`, the Editor may instead use the existing manual retry, which performs one provider call per request. Retry remains unavailable for other states.
8. A `CONFIRMED` Record is not edited in place. The Editor replaces the aggregate with a reason; Laravel writes the field diff and `status` `CONFIRMED -> NEEDS_REVIEW` and leaves the Record in `NEEDS_REVIEW`. The Editor then confirms it again in a separate action. A no-change replacement is rejected with `422 RECORD_UPDATE_NO_CHANGES` and does not reopen the record.
9. The UI never auto-confirms, never treats AI output as financial authority, and disables actions that are not valid for the current role or state.

**Database changes:** Record status, replaced `record_details`, and History rows for creation, correction, reopening, confirmation, or cancellation. Reopening and correction preserve `Image`, private files, and `AIProcessing`. No tables or migrations are added for history.

**Transaction boundary:** Each creation, correction, reopening, confirmation, or cancellation locks/rechecks the Record where applicable and commits its Record/detail/status changes and History rows atomically. Any failure leaves the prior state intact.

**Authorization:** `EDITOR` only for correction, confirmation, cancellation, and retry. `VIEWER` may read the Record, its authorized image, and safe AI diagnostics.

**Failure behavior:** Invalid input returns `422`; invalid state returns `409` with `RECORD_UPDATE_BLOCKED`, `RECORD_CONFIRMATION_BLOCKED`, or `RECORD_CANCELLATION_BLOCKED`; forbidden roles return `403`; missing Records return `404`. No partial writes are allowed.

**Jobs or events:** No new AI job is dispatched by correction or confirmation. The existing retry endpoint remains the only way to start a new single-call attempt from `ERROR`.

**Tests:** Cover both sources, Editor/Viewer authorization, correction with preserved Image/AI, field-level diff and no-op behavior, reopening of `CONFIRMED`, confirmation of complete and incomplete IMAGE records, unresolved AI issues, cancellation from `NEEDS_REVIEW` and `ERROR`, rejection for other states, transactional rollback, and image access after cancellation.

## Record Change History

**Status:** SPEC 10 / phase 8.11 implemented and verified (2026-10-26).

**Purpose:** Expose the human audit trail of a Record with user, reason, previous/new values, and timestamp.

**Entry point:** `GET /api/records/{record}/history`.

**Preconditions:** The user is authenticated as `EDITOR` or `VIEWER`; the Record exists.

**Workflow:**

1. Laravel returns the Record's History rows ordered by `created_at` descending and `id` descending.
2. `page` defaults to 1 and `per_page` defaults to 50 with a maximum of 100.
3. Each item exposes `field_name`, canonical `previous_value`/`new_value`, `reason`, `created_at`, and the acting user's public identity.
4. Creation events use `record_created`; corrections and reopenings use field-level names such as `company`, `week_end_date`, or `details[1].income`; confirmation and cancellation use `status`.

**Database changes:** None; the endpoint only reads existing `History` rows.

**Transaction boundary:** Read-only.

**Authorization:** `EDITOR` and `VIEWER` may read. Unauthenticated requests return `401`; missing Records return `404`; invalid pagination returns `422`.

**Failure behavior:** No mutations and no partial reads. AIProcessing internals, raw provider responses, and private paths are never exposed.

**Jobs or events:** None.

**Tests:** Cover role access, pagination defaults and limits, ordering, response shape, 404/401/422, and absence of AI or private data.

## Confirmation

Confirmation requires an authorized human, valid business data, and the `NEEDS_REVIEW` state. The aggregate is revalidated before the state change. Confirmation must be transactional with its history entry. Both `MANUAL` and `IMAGE` records follow this rule; IMAGE records additionally require an active Image and complete business data. AI issues are review signals and do not block confirmation once backend validation passes. The History reason is source-appropriate: `Registro manual confirmado` for MANUAL and `Registro de imagen confirmado` for IMAGE.

## Confirmed Record Changes

```text
CONFIRMED -> NEEDS_REVIEW -> CONFIRMED
```

A replacement of a `CONFIRMED` Record requires a different aggregate and a reason. Laravel writes one History row per modified field plus a `status` `CONFIRMED -> NEEDS_REVIEW` row in the same transaction, preserving `source`, Image, and `AIProcessing`. The Record remains in `NEEDS_REVIEW` until the Editor confirms it again. A no-change replacement returns `422 RECORD_UPDATE_NO_CHANGES`, keeps `CONFIRMED`, and writes no History.

## Delivery

**Status:** SPEC 11 / phase 8.12 approved (2026-10-26); implementation in progress.

**Purpose:** Persist the single editable delivery preparation for a confirmed Record, reconcile its cash breakdown against the current return, and audit changes without creating delivery versions or states.

**Entry points:** `GET /api/records/{record}/delivery` and `PUT /api/records/{record}/delivery`.

**Preconditions:** The user is authenticated. Reads require `EDITOR` or `VIEWER`; mutations require `EDITOR`, CSRF where applicable, and a Record in `CONFIRMED`.

**Workflow:**

1. The Editor opens the delivery preparation for a confirmed Record. The backend returns the existing Delivery or `data: null`, the current Record return total, and whether the preparation is stale.
2. The Editor submits the complete preparation: delivery date, delivering person, optional receiving person, and the cash denominations with quantities.
3. Laravel validates the request shape, official denominations, positive quantities, and prohibited client fields. Zero-quantity rows are ignored; an empty breakdown remains valid only for a `0.00` return.
4. In one transaction, Laravel locks the Record and any existing Delivery, rechecks that the Record is `CONFIRMED`, and derives the current return total from the Record details.
5. Laravel compares gross cash with the current return in integer cents. It accepts a shortfall up to `$1.00` and any excess. A larger shortfall returns `409 DELIVERY_PREPARATION_BLOCKED` with `details.reason=CASH_MISMATCH` and the expected, cash, and difference amounts.
6. On a valid preparation, Laravel creates the Delivery or updates the existing row in place, replaces its positive denominations, sets `prepared_return_amount` to the current return total, and writes field-level History with `delivery_id` and a fixed creation/update reason.
7. If the resulting preparation is identical to the stored one, Laravel returns `200` without writing History or changing `updated_at`.
8. If the Record return later changes, the existing Delivery remains associated with the Record. Reads report `is_stale=true`; the Editor updates the same Delivery once the Record is confirmed again, with gross cash within the save-time tolerance.
9. Delivery preparation never changes the Record status, fields, movements, Image, or AIProcessing evidence. Word generation is a separate workflow (see Delivery Document) and requires an associated Image.

**Database changes:** Creates or updates one `Delivery` row and its positive `DeliveryCashDenomination` rows; appends field-level `History` rows with `delivery_id`.

**Transaction boundary:** The Delivery, denomination replacement, and History rows commit atomically under a Record row lock. Any failure leaves the previous preparation intact.

**Authorization:** `EDITOR` and `VIEWER` may read; only `EDITOR` may create or update, and only for a `CONFIRMED` Record.

**Failure behavior:** Invalid input returns `422`; an unsupported Record state or cash shortfall greater than `$1.00` returns `409` with `DELIVERY_PREPARATION_BLOCKED`; a Viewer mutation returns `403`; missing Records return `404`. No partial writes are allowed.

**Jobs or events:** No jobs. Delivery changes produce History events only.

**Tests:** Cover read access and null delivery, Editor/Viewer authorization, confirmed-only preparation, official denominations, zero quantities, empty breakdown for a zero return, duplicates, cash mismatch, stale updates, idempotent no-op, same-row updates, fixed-reason History with `delivery_id`, transactional rollback, and the absence of Record status changes.

## Delivery Document

**Status:** SPEC 12 / phase 8.13 complete (2026-10-29).

**Purpose:** Generate the Word delivery receipt on demand from the persisted Delivery, its Record, the Record Image, and the cash denominations, without storing the file.

**Entry point:** `POST /api/deliveries/{delivery}/document`.

**Preconditions:** The user is authenticated as `EDITOR` with valid CSRF. The Delivery exists.

**Workflow:**

1. The Editor requests the document from the delivery preparation screen, sending the selected ephemeral document amount and the customer-change declaration.
2. In one transaction, Laravel locks the Record and Delivery.
3. Laravel checks that the Record is `CONFIRMED`, has an Image, the Delivery is not stale against the official return, the document amount is one of the three allowed choices, and gross cash reconciles against that amount. A shortfall greater than `$1.00` returns `CASH_MISMATCH`; an excess greater than `$1.00` without declared change returns `CHANGE_REQUIRED`. The private image file must be readable.
4. `MoneyToWordsService` converts the selected document amount to `... PESOS CC/100 M.N.`.
5. `DeliveryDocumentBuilder` builds the office format with PHPWord into a temporary file. A WebP image is converted to a temporary PNG; the original private file is never modified.
6. Laravel writes one `document_generated` History row with `delivery_id` and commits.
7. Laravel streams the file as an attachment and deletes the temporary files.

**Database changes:** One `History` row per successful generation. No Record, Delivery, denomination, or Image changes.

**Transaction boundary:** Validation, construction, and the History row share one transaction. A blocked or failed generation writes nothing and removes temporary files.

**Authorization:** Only `EDITOR` may generate. Viewers receive `403`.

**Failure behavior:** `409 DOCUMENT_GENERATION_BLOCKED` with `INVALID_RECORD_STATE`, `MISSING_IMAGE`, `STALE_DELIVERY`, `CASH_MISMATCH`, `CHANGE_REQUIRED`, or `IMAGE_UNAVAILABLE`; `422` for malformed or unsupported document adjustments; `404` for a missing Delivery; a generic `500` if construction fails. No private paths are disclosed.

**Jobs or events:** None. Generation is synchronous.

**Tests:** Cover amount-in-words cases, Spanish date formatting, document content (text, 11 table rows, totals, signatures, image), JPEG/PNG/WebP insertion, response headers, temporary-file cleanup, Editor/Viewer/anonymous access, each blocking reason, History only on success, and the absence of data changes.

## Reports and Excel

**Status:** SPEC 13 / phase 8.14 complete (2026-11-03).

**Purpose:** Let users review official totals of confirmed Records and export the same data to an on-demand Excel file, without storing the file.

**Entry point:** `GET /api/reports` and `GET /api/documents/excel`.

**Preconditions:** The user is authenticated as `EDITOR` or `VIEWER`. The request has a valid `year`.

**Workflow:**

1. The user chooses year, optional month, week, and company on `/reports`.
2. `ReportQuery` selects only `CONFIRMED` Records that match the filters; the month is the month of `week_end_date`.
3. For the screen, Laravel returns the filters, period, global totals, the company breakdown, and one page of included Records. Totals and the breakdown cover the full filtered set.
4. For the export, the same query provides every included Record with its movements, Delivery, and cash denominations.
5. `ReportWorkbookBuilder` writes `Resumen`, `Registros`, `Detalles`, and `Efectivo` with PhpSpreadsheet into a temporary file.
6. Laravel streams the file as an attachment named from the filters and deletes the temporary file.

**Database changes:** None. Reports and exports are read-only; no `History` row is written.

**Transaction boundary:** None. Both operations are read-only.

**Authorization:** `EDITOR` and `VIEWER` may read reports and export Excel.

**Failure behavior:** `401` unauthenticated; `422` for invalid filters, including a missing `year` or a non-existent company; a generic `500` if workbook construction fails. The temporary file is removed on every failure path. An empty result is not an error: the report is empty and the workbook is generated with headers and zero totals.

**Jobs or events:** None. Generation is synchronous.

**Tests:** Cover inclusion by status, the month rule for weeks that cross months, filter combinations, ordering, decimal precision, totals independent of pagination, `422` validation, Editor/Viewer/anonymous access, workbook sheets, headers, rows, number and date formats, the `Resumen` total matching the API, the cash columns and `Desactualizada` flag, an empty workbook, response headers, file names, temporary-file cleanup, and the absence of `History`.
