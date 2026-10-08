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

1. Editor creates a manual Record.
2. The backend stores details without Image or AIProcessing rows.
3. Business validation runs.
4. The Record enters `NEEDS_REVIEW`.
5. An authorized Editor confirms or cancels it.

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
