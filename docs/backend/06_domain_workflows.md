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

1. An Editor creates a manual Record with company, business year/week, period dates, and one or more ordered movement inputs. Each movement includes its own operation number, affiliation, branch, income, and one commission input.
2. The backend validates that dates exist and that the start date is not after the end date. It does not impose ISO week rules or weekday boundaries.
3. For each detail, the backend accepts exactly one commission input: percentage or fixed amount. It calculates the commission using decimal arithmetic and `ROUND_HALF_UP` for percentages, then calculates return as income minus commission.
4. A transaction persists the Record as `source=MANUAL`, `status=CONFIRMED`, its RecordDetails, and a History event (`status`, null to `CONFIRMED`, authenticated user, reason `Registro manual confirmado`). No Image, AIProcessing, or AI job is created.
5. An Editor may replace the full aggregate only while it is `MANUAL` and `NEEDS_REVIEW`. Replacement requires a reason; the Record, details, and one generic `record_updated` History event are committed together.
6. The explicit confirmation endpoint remains for records from future workflows that start in `NEEDS_REVIEW`, such as AI-assisted capture. It is not an extra step for valid manual capture.

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
4. Laravel rechecks state, validates the complete aggregate, recalculates financial values, replaces the details, and writes a generic `record_updated` History event. `source`, `status`, Image, and `AIProcessing` remain unchanged.
5. The Editor confirms a `NEEDS_REVIEW` Record only after backend validation succeeds. Confirmation transactionally sets `CONFIRMED` and writes the source-appropriate `status` History event. AI issues are review signals and do not block a complete, valid aggregate.
6. The Editor may cancel a `NEEDS_REVIEW` or `ERROR` Record with a reason. Cancellation transactionally sets `CANCELED` and writes a `status` History event with the prior state and the reason. Image and AI evidence are preserved.
7. For `ERROR`, the Editor may instead use the existing manual retry, which performs one provider call per request. Retry remains unavailable for other states.
8. The UI never auto-confirms, never treats AI output as financial authority, and disables actions that are not valid for the current role or state.

**Database changes:** Record status, replaced `record_details`, and a `History` row for correction, confirmation, or cancellation. Cancellation does not remove `Image`, private files, or `AIProcessing`.

**Transaction boundary:** Each correction, confirmation, or cancellation locks/rechecks the Record and commits its Record/detail/status changes and History row atomically. Any failure leaves the prior state intact.

**Authorization:** `EDITOR` only for correction, confirmation, cancellation, and retry. `VIEWER` may read the Record, its authorized image, and safe AI diagnostics.

**Failure behavior:** Invalid input returns `422`; invalid state returns `409` with `RECORD_UPDATE_BLOCKED`, `RECORD_CONFIRMATION_BLOCKED`, or `RECORD_CANCELLATION_BLOCKED`; forbidden roles return `403`; missing Records return `404`. No partial writes are allowed.

**Jobs or events:** No new AI job is dispatched by correction or confirmation. The existing retry endpoint remains the only way to start a new single-call attempt from `ERROR`.

**Tests:** Cover both sources, Editor/Viewer authorization, correction with preserved Image/AI, confirmation of complete and incomplete IMAGE records, unresolved AI issues, cancellation from `NEEDS_REVIEW` and `ERROR`, rejection for other states, transactional rollback, and image access after cancellation.

## Confirmation

Confirmation requires an authorized human, valid business data, and the `NEEDS_REVIEW` state. The aggregate is revalidated before the state change. Confirmation must be transactional with its history entry. Both `MANUAL` and `IMAGE` records follow this rule; IMAGE records additionally require an active Image and complete business data. AI issues are review signals and do not block confirmation once backend validation passes. The History reason is source-appropriate: `Registro manual confirmado` for MANUAL and `Registro de imagen confirmado` for IMAGE.

## Confirmed Record Changes

```text
CONFIRMED -> NEEDS_REVIEW -> CONFIRMED
```

The reason, user, and before/after values must be recorded.

## Delivery

Delivery preparation is allowed only for a confirmed Record. Cash denomination totals must equal the current calculated return amount. Stale preparation must be detected before document generation.
