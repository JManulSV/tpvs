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

**Status:** Contract defined for phase 8.6; planned until implemented and tested.

**Entry points:** `POST /api/records`, `PUT /api/records/{id}`, and `POST /api/records/{id}/confirm`.

**Preconditions:** The user is authenticated. Mutations require `EDITOR`. Creation and replacement reference an existing Company and supply required record fields and at least one valid detail.

**Workflow:**

1. An Editor creates a manual Record with company, ISO period, affiliation, branch, and ordered movement inputs.
2. The backend validates that the dates are exactly Monday and Sunday for the supplied ISO week-year/week.
3. For each detail, the backend accepts exactly one commission input: percentage or fixed amount. It calculates the commission using decimal arithmetic and `ROUND_HALF_UP` for percentages, then calculates return as income minus commission.
4. A transaction persists the Record as `source=MANUAL`, `status=NEEDS_REVIEW`, and its RecordDetails. No Image, AIProcessing, or AI job is created.
5. An Editor may replace the full aggregate only while it is `MANUAL` and `NEEDS_REVIEW`. Replacement requires a reason; the Record, details, and one generic `manual_record_updated` History event are committed together.
6. An Editor explicitly confirms a valid manual record in `NEEDS_REVIEW`. The status transition and its History event, with fixed reason `Registro manual confirmado`, are committed together.

**Failure behavior:** Invalid request or business input returns `422`; an unsupported source/state transition returns `409`; a Viewer mutation returns `403`. Create, replace, and confirmation leave no partial writes on failure.

**Tests:** Verify Editor/Viewer authorization, ISO-year boundary weeks, financial rounding and fixed commission, invalid input, no Image/AIProcessing/job, atomic replacement/history, confirmation history, and disallowed transitions.

## Image Record

1. Editor uploads an image.
2. Laravel stores private file metadata and creates the record context.
3. A processing attempt is created.
4. AI output is normalized and validated.
5. The Record enters `NEEDS_REVIEW` or `ERROR`.
6. An Editor reviews and confirms or cancels it.

## Confirmation

Confirmation requires an authorized human, valid business data, and the `NEEDS_REVIEW` state. Confirmation must be transactional with its history entry.

## Confirmed Record Changes

```text
CONFIRMED -> NEEDS_REVIEW -> CONFIRMED
```

The reason, user, and before/after values must be recorded.

## Delivery

Delivery preparation is allowed only for a confirmed Record. Cash denomination totals must equal the current calculated return amount. Stale preparation must be detected before document generation.
