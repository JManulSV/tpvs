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

1. An Editor creates a manual Record with company, business year/week, period dates, affiliation, branch, and ordered movement inputs.
2. The backend validates that dates exist and that the start date is not after the end date. It does not impose ISO week rules or weekday boundaries.
3. For each detail, the backend accepts exactly one commission input: percentage or fixed amount. It calculates the commission using decimal arithmetic and `ROUND_HALF_UP` for percentages, then calculates return as income minus commission.
4. A transaction persists the Record as `source=MANUAL`, `status=CONFIRMED`, its RecordDetails, and a History event (`status`, null to `CONFIRMED`, authenticated user, reason `Registro manual confirmado`). No Image, AIProcessing, or AI job is created.
5. An Editor may replace the full aggregate only while it is `MANUAL` and `NEEDS_REVIEW`. Replacement requires a reason; the Record, details, and one generic `manual_record_updated` History event are committed together.
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

## Confirmation

Confirmation requires an authorized human, valid business data, and the `NEEDS_REVIEW` state. Confirmation must be transactional with its history entry.

## Confirmed Record Changes

```text
CONFIRMED -> NEEDS_REVIEW -> CONFIRMED
```

The reason, user, and before/after values must be recorded.

## Delivery

Delivery preparation is allowed only for a confirmed Record. Cash denomination totals must equal the current calculated return amount. Stale preparation must be detected before document generation.
