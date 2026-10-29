# Business Rules, Validations, and State Machine

## 1. Core Business Principle

AI interprets the image, the backend validates the financial data, the person reviews the result, and the person confirms the record.

## 2. Record States

### States
- `PENDING`
- `PROCESSING`
- `NEEDS_REVIEW`
- `ERROR`
- `CONFIRMED`
- `CANCELED`

### State Meaning

| State | Meaning | Included in official reports |
| --- | --- | --- |
| `PENDING` | Record exists but has not started processing | No |
| `PROCESSING` | AI or backend processing is currently running | No |
| `NEEDS_REVIEW` | AI finished, but human review is required | No |
| `ERROR` | A technical failure occurred | No |
| `CONFIRMED` | Record was reviewed, validated, and approved | Yes |
| `CANCELED` | Record is invalid and should be ignored in totals | No |

## 3. Allowed Transitions

| From | To | Trigger |
| --- | --- | --- |
| `PENDING` | `PROCESSING` | Processing starts |
| `PROCESSING` | `ERROR` | AI failure, timeout, invalid response, or technical exception |
| `PROCESSING` | `NEEDS_REVIEW` | AI extracted data that needs review |
| `ERROR` | `PROCESSING` | Retry processing |
| `NEEDS_REVIEW` | `CONFIRMED` | User validates and confirms |
| `NEEDS_REVIEW` | `CANCELED` | User rejects the record |
| `ERROR` | `CANCELED` | User discards a record that cannot be processed |
| `CONFIRMED` | `NEEDS_REVIEW` | A confirmed record is modified |
| `NEEDS_REVIEW` | `CONFIRMED` | Modified record is revalidated and approved |

## 4. Modification Rule for Confirmed Records

A confirmed record cannot be edited in place while remaining confirmed.

Required workflow:
`CONFIRMED` -> modify -> `NEEDS_REVIEW` -> validate -> `CONFIRMED`

Every modification must include a reason and must be written to `History`.

## 5. Financial Validation Rules

For each `RecordDetail`:

- Expected commission is derived from `income × commission_percentage / 100` when percentage is available.
- Expected return is `income - commission`.
- The backend compares the persisted values with the expected values.

### Recommended Handling for `commission_percentage`

Use:
- type: `DECIMAL(5,2)`
- nullable: yes
- business meaning: percentage points
- example: `5.00` means 5 percent

Reason:
- It keeps the field flexible because the source image may not always contain a percentage.
- It preserves the field in the model without making it a hard operational dependency.

### Validation Result

A detail is valid only when:
- `commission` matches the expected value within the defined decimal precision; and
- `return_amount` matches `income - commission` within the defined decimal precision.

If any comparison fails, the record must move to `NEEDS_REVIEW`.

## 6. Report Inclusion Rule

Only `CONFIRMED` records are included in official reports, Excel, and Word documents.

Excluded:
- `PENDING`
- `PROCESSING`
- `NEEDS_REVIEW`
- `ERROR`
- `CANCELED`

## 7. Operational Rules

- A record can contain multiple movement details.
- A record can be created from an image or manually.
- Image-based records may have one image and one AI processing flow.
- Manual records are created without an image and do not create AI processing attempts; an image may be attached to the Record later.
- A Word delivery document requires an image even for manually created records.
- A manual Record may receive an Image later without creating an AI processing attempt for that attachment.
- A confirmed Record may have zero or one Delivery preparation.
- A Delivery centralizes delivery date, delivering/receiving names, the prepared return amount, and cash denominations.
- Delivery preparation does not introduce separate delivery states or versions in the MVP.
- If the Record's current return total differs from the Delivery's prepared return amount, the preparation is stale and must be updated before Word generation.
- Every image processing attempt must be preserved.
- The final image replaces the active one; old images are not part of the active record flow.
- History is the only required audit mechanism for the MVP.

## 8. Business Week and Year Rule

The business period must be stored as a combination of:
- `week`: the week identifier within the year
- `year`: the calendar year that owns that week number

This means the week is not a standalone label. It must always be interpreted together with the year.

Examples:
- Week 1 of 2026
- Week 32 of 2026

Validation expectations:
- `week` is the business's week identifier and is stored as an integer from 1 to 53.
- `year` is the calendar/business year selected by the Editor and is stored with `week` in `Record`.
- Do not interpret the business week as an ISO week or derive its dates from the number.
- `week_start_date` and `week_end_date` are valid dates chosen according to the operational calendar; they need not be Monday and Sunday.
- `week_start_date` must not be later than `week_end_date`.
- Reports and filters treat week and year as a combined business-period key.
- If a period crosses two months, month-based reports use the month containing `week_end_date`.

## 9. Company Name Rule

Company names are fixed in the MVP.

Validation expectations:
- The `name` field is set on creation.
- The `name` field is not edited after creation in the MVP.
- Historical records always reference the same stored company name.

## 10. Record Source Rule

Every record has a source:

- `IMAGE`
- `MANUAL`

### IMAGE

The record was created from an uploaded image.

It may include:
- `Image`
- `AIProcessing`

### MANUAL

The record was created manually by the Editor.

It does not require:
- `Image`
- `AIProcessing`

A valid manual record is confirmed when the Editor saves it, because the Editor enters and reviews the data in the same workflow. The creation transaction records a single `record_created` event with `new_value=CONFIRMED` in `History`, replacing the former creation `status` event. The audited `CONFIRMED -> NEEDS_REVIEW -> CONFIRMED` editing workflow is available through the record replacement endpoint.

### Shared Rule

Both sources use the same financial business validation rules. Image-derived records require later human review before confirmation. A valid manual record is confirmed during creation because the Editor reviews it while entering the data.

## 11. Delivery Rules

- A Delivery can only be prepared for a `CONFIRMED` Record.
- There is at most one Delivery row per Record in the MVP. Preparing again updates that row; delivery versions are not stored.
- The official return total is calculated from the Record's details. The backend derives `prepared_return_amount` from that current total on every successful preparation; clients cannot set it.
- `prepared_return_amount` stores the amount the cash preparation was checked against. Compare it with the current Record return total to detect stale preparation.
- Updating a stale preparation updates this same Delivery row; no delivery versions or delivery status enum are added in the MVP.
- Cash denominations belong to Delivery, not directly to Record.
- A delivery is valid when `SUM(denomination.value × denomination.quantity)` equals the current Record return total, compared in integer cents.
- Only denomination rows with `quantity > 0` are stored. Zero-quantity rows are ignored.
- An empty denomination breakdown is valid only when the current Record return total is `0.00`.
- The official MVP denominations are `1000`, `500`, `200`, `100`, `50`, `20`, `10`, `5`, `2`, `1`, and `0.50`.
- A mismatch blocks successful preparation and Word generation.
- Preparing or updating a Delivery does not change the Record status, fields, or movements.
- Delivery preparation changes are audited in `History` with `delivery_id`, the authenticated user, canonical previous/new values, and the fixed reasons `Preparación de entrega creada` or `Preparación de entrega actualizada`.
- Word generation additionally requires the Record's associated Image.
- A manual Record may be created and confirmed without an Image; an Image must be attached before Word generation.

## 12. Review, Correction, Confirmation, and Cancellation Rules

- Only an authenticated `EDITOR` may correct, reopen, confirm, cancel, or retry a record. `VIEWER` may read records, images, AI diagnostics, and change history but cannot mutate them.
- A record in `NEEDS_REVIEW` may be corrected by replacing its complete business aggregate: company, business period, and ordered movements. Correction preserves `source`, `status`, the associated Image, and the existing `AIProcessing` result.
- A `CONFIRMED` record may be reopened by submitting the same complete aggregate replacement. The record moves to `NEEDS_REVIEW`; the Editor must confirm it again through the separate confirmation step. Reopening preserves `source`, the associated Image, and the existing `AIProcessing` result.
- Correction and reopening require a reason. They must not re-run AI, replace the image after AI attempts, or alter the normalized/raw AI result.
- A replacement `NEEDS_REVIEW` aggregate writes one `History` row per modified field with the previous value, new value, authenticated user, reason, and timestamp. A replacement without differences writes a single `record_updated` event with null values. Reopening additionally writes a `status` `CONFIRMED -> NEEDS_REVIEW` event. A no-change replacement of a `CONFIRMED` record is rejected with `422 RECORD_UPDATE_NO_CHANGES`, keeps the record `CONFIRMED`, and writes no history.
- A record may be confirmed only from `NEEDS_REVIEW`, after backend revalidation of the complete aggregate and financial rules.
- AI issues are review signals, not a permanent confirmation block. Once the Editor has corrected the aggregate so it is complete and valid, unresolved AI issues do not prevent confirmation.
- A record in `NEEDS_REVIEW` or `ERROR` may be canceled with a required reason. Cancellation changes the record to `CANCELED` and preserves its Image and `AIProcessing` evidence.
- A `CONFIRMED`, `PENDING`, or `PROCESSING` record cannot be canceled in this workflow. `PENDING` and `PROCESSING` may still have work in flight; `CONFIRMED` records follow the audited reopening workflow.
- Creation, correction, reopening, confirmation, and cancellation are transactional with their `History` events. A failure must leave no partial record, detail, state, or audit changes.
- History events for this workflow use:
  - `record_created` with null previous value and `CONFIRMED` or `PENDING` for manual or image creation;
  - one row per changed field with canonical previous/new values for correction or reopening;
  - `record_updated` with null previous/new values when a `NEEDS_REVIEW` correction has no differences;
  - a `status` transition to `NEEDS_REVIEW` when a `CONFIRMED` record is reopened;
  - a `status` transition to `CONFIRMED` with the source-appropriate fixed reason for confirmation;
  - a `status` transition to `CANCELED` with the Editor's reason for cancellation.

### History Value Conventions

- Dates use `Y-m-d`; amounts and percentages use decimal text with two decimals (for example `100.00`).
- `year`, `week`, and detail `order` use integer text. `status` and `source` use the enum value. A company change uses the company name, not its id.
- Detail fields use `details[N].campo`, where `N` is 1-based and matches the movement's `order`. Adding or removing a movement writes null to values or values to null.
- `record_detail_id` remains null because replacement recreates detail rows.
- `delivery_id` is set for delivery-preparation events. Delivery fields use `delivery_date`, `delivered_by_name`, `received_by_name`, `prepared_return_amount`, and `denominations[1000.00]`-style entries using the canonical denomination with two decimals. Denomination values store the quantity as integer text.
- Values are canonicalized before comparison, so changes in formatting alone do not create events.
- AI transitions, retries, and internal pipeline changes are not written to `History`; `AIProcessing` remains their technical evidence.
