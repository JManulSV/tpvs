# Data Model Specification

## 0. Implementation Status

This document defines the approved MVP data model. The TPVS domain schema and Eloquent relationships were initially implemented on 2026-10-08 (roadmap phase 8.3); SPEC 07 updates the base Record and RecordDetail schema before production deployment.

Currently present in Laravel migrations:

- `users`
- `password_reset_tokens`
- `sessions`
- Laravel cache and jobs tables

The TPVS entities below have migrations, Eloquent models, relationships, constraints, and aggregate/constraint tests. Local databases must be rebuilt after the SPEC 07 base schema change; development data is not carried forward. Business API behavior must not be inferred from the presence of database tables.

## 1. Entity Overview

The MVP contains 9 core entities:
- `User`
- `Company`
- `Record`
- `RecordDetail`
- `Image`
- `AIProcessing`
- `History`
- `Delivery`
- `DeliveryCashDenomination`

## 2. User

Represents a person who uses the platform.

| Field | Type | Required | Notes |
| --- | --- | --- | --- |
| id | UUID / bigint | Yes | Primary key |
| name | string | Yes | Display name |
| email | string | Yes | Unique |
| password_hash | string | Yes | Stored as a hash |
| role | enum | Yes | `EDITOR` or `VIEWER` |
| created_at | datetime | Yes | Audit timestamp |
| updated_at | datetime | Yes | Audit timestamp |

Relationship:
- `User 1:N History`

## 3. Company

Catalog of companies.

| Field | Type | Required | Notes |
| --- | --- | --- | --- |
| id | UUID / bigint | Yes | Primary key |
| name | string | Yes | Business name |
| created_at | datetime | Yes | Audit timestamp |
| updated_at | datetime | Yes | Audit timestamp |

Relationship:
- `Company 0:N Record`; a provisional IMAGE Record may not yet match a catalog company.

Important rule:
- Company names are fixed after creation in the MVP.

## 4. Record

Represents one received document/image and the business record attached to it.

| Field | Type | Required | Notes |
| --- | --- | --- | --- |
| id | UUID / bigint | Yes | Primary key |
| company_id | FK nullable | No for provisional IMAGE | References `Company`; required for MANUAL creation and confirmation |
| source | enum | Yes | `IMAGE` or `MANUAL` |
| status | enum | Yes | `PENDING`, `PROCESSING`, `NEEDS_REVIEW`, `ERROR`, `CONFIRMED`, `CANCELED` |
| week | integer nullable | No for provisional IMAGE | Week identifier within the year; required for MANUAL creation and confirmation |
| year | integer nullable | No for provisional IMAGE | Calendar year for the record period; required for MANUAL creation and confirmation |
| week_start_date | date nullable | No for provisional IMAGE | Start date for the business period; required for MANUAL creation and confirmation |
| week_end_date | date nullable | No for provisional IMAGE | End date for the business period; determines the month used by month-based reports; required for MANUAL creation and confirmation |
| created_at | datetime | Yes | Audit timestamp |
| updated_at | datetime | Yes | Audit timestamp |

Relationships:
- `Company 0:N Record` while IMAGE data is provisional; MANUAL creation and confirmation require a company.
- `Record 0:1 Image`
- `Record 1:N RecordDetail`
- `Record 1:N History`
- `Record 0:1 Delivery`

Important rule:
- An image-based record has one image.
- A manual record does not require an image.
- A manual record must have an image before a Word delivery document can be generated.
- A record can contain zero or many movement details while IMAGE extraction is provisional; MANUAL creation and confirmation require at least one complete detail.
- Totals are not stored as source-of-truth fields.
- The record period is defined by `week` plus `year`.

Reporting note:
- If a week spans two months, month-based reports use the month containing `week_end_date`.

## 5. RecordDetail

Represents each movement inside a record.

| Field | Type | Required | Notes |
| --- | --- | --- | --- |
| id | UUID / bigint | Yes | Primary key |
| record_id | FK | Yes | References `Record` |
| affiliation | string nullable | No for provisional IMAGE | Movement affiliation; required for MANUAL creation and confirmation |
| branch | string nullable | No for provisional IMAGE | Movement branch; required for MANUAL creation and confirmation |
| income | decimal(12,2) nullable | No for provisional IMAGE | Gross amount; required for MANUAL creation and confirmation |
| commission_percentage | decimal(5,2) nullable | No | Percentage points, for example `5.00` for 5 percent |
| commission | decimal(12,2) nullable | No for provisional IMAGE | Commission amount; required for MANUAL creation and confirmation |
| return_amount | decimal(12,2) nullable | No for provisional IMAGE | Return amount; required for MANUAL creation and confirmation |
| order | integer | Yes | Positive display/order sequence |
| created_at | datetime | Yes | Audit timestamp |
| updated_at | datetime | Yes | Audit timestamp |

Relationships:
 - `Record 1:N RecordDetail`
 - `RecordDetail 1:N History`

Important rule:
- Do not persist derived totals like `total`, `calculated_return`, or `calculated_commission` as the source of truth.
- `RecordDetail` owns affiliation and branch; they are not stored on `Record`.
- Database nullability permits incomplete IMAGE data in `PENDING`, `PROCESSING`, `NEEDS_REVIEW`, or `ERROR`. Application validation still requires complete company, period, and movement fields for MANUAL creation and confirmation.

## 6. Image

Represents the original file attached to the record.

| Field | Type | Required | Notes |
| --- | --- | --- | --- |
| id | UUID / bigint | Yes | Primary key |
| record_id | FK | Yes | References `Record` |
| original_file_name | string | Yes | Original filename |
| file_path | string | Yes | Local file path or storage key |
| mime_type | string | Yes | Example: `image/jpeg` |
| file_size | bigint | Yes | File size in bytes |
| created_at | datetime | Yes | Audit timestamp |
| updated_at | datetime | Yes | Audit timestamp |

Relationship:
- `Record 0:1 Image`
- `Image 1:N AIProcessing`

## 7. AIProcessing

Represents one AI processing attempt.

| Field | Type | Required | Notes |
| --- | --- | --- | --- |
| id | UUID / bigint | Yes | Primary key |
| image_id | FK | Yes | References `Image` |
| provider | string | No | AI provider name |
| model | string | No | Model name or version |
| status | enum | Yes | Processing status such as `PENDING`, `PROCESSING`, `NEEDS_REVIEW`, `ERROR`, `COMPLETED` |
| result | JSON / text | No | Raw or normalized AI output |
| error_message | text | No | Failure details |
| started_at | datetime | No | Processing start timestamp |
| completed_at | datetime | No | Processing end timestamp |
| created_at | datetime | Yes | Audit timestamp |
| updated_at | datetime | Yes | Audit timestamp |

Relationship:
- `Image 1:N AIProcessing`

Important rule:
- Do not overwrite previous attempts silently.

## 8. Manual Capture Rule

A manual record uses the same `Record` entity.

It does not create `Image` or `AIProcessing` rows.

At manual creation, no `Image` or `AIProcessing` row is created. An `Image` may be attached to the same Record later without invoking AI.

Manual records still use:
- `RecordDetail`
- `History`

and they follow the same validation and confirmation flow as image-based records. Manual creation writes one `record_created` History event with `new_value=CONFIRMED` and the fixed reason `Registro creado manualmente`. A `Delivery` and its cash denominations are created later during delivery preparation.

## 9. Delivery

Represents the single editable delivery preparation associated with a confirmed `Record`. It centralizes delivery details and the amount against which cash denominations are reconciled. It is not a generated document or a versioned delivery history.

| Field | Type | Required | Notes |
| --- | --- | --- | --- |
| id | UUID / bigint | Yes | Primary key |
| record_id | FK | Yes | References `Record`; unique to enforce at most one Delivery per Record |
| delivery_date | date | Yes | Planned or actual date of delivery |
| delivered_by_name | string | Yes | Person who delivers the cash |
| received_by_name | string | No | Person who receives the cash |
| prepared_return_amount | decimal(12,2) | Yes | Return amount used when the cash breakdown was prepared |
| created_at | datetime | Yes | Audit timestamp |
| updated_at | datetime | Yes | Audit timestamp |

Relationships:
- `Record 0:1 Delivery`
- `Delivery 1:N DeliveryCashDenomination`
- `Delivery 1:N History` (when delivery-preparation fields change)

Important rules:
- A Delivery can only be prepared for a `CONFIRMED` Record.
- The current Record return total is calculated from its details and is not duplicated as the source of truth.
- `prepared_return_amount` stores the amount the cash preparation was checked against. Compare it with the current Record return total to detect stale preparation.
- Updating a stale preparation updates this same Delivery row; no delivery versions or delivery status enum are added in the MVP.
- A valid associated Image is required to generate Word, but it is not required to create a manual Record or prepare its Delivery.
- The generated Word file is ephemeral and is not stored in this entity.

## 10. History

Stores the human change history for the MVP. It uses the existing schema: this phase adds no tables and no migrations.

| Field | Type | Required | Notes |
| --- | --- | --- | --- |
| id | UUID / bigint | Yes | Primary key |
| record_id | FK | Yes | References `Record` |
| record_detail_id | FK nullable | No | References `RecordDetail` when relevant; null in the current record-history phase |
| delivery_id | FK nullable | No | References `Delivery` when a delivery-preparation field changed; null until delivery history is implemented |
| user_id | FK | Yes | Authenticated user who performed the action |
| field_name | string | Yes | Canonical field name or event name |
| previous_value | text / JSON | No | Canonical previous value |
| new_value | text / JSON | No | Canonical new value |
| reason | text | Yes | Editor's reason or a fixed creation/confirmation reason |
| created_at | datetime | Yes | Action timestamp; History has no `updated_at` |

Relationships:
- `User 1:N History`
- `Record 1:N History`
- `RecordDetail 1:N History`
- `Delivery 1:N History` (optional delivery reference)

Event conventions:

| Action | `field_name` | `previous_value` | `new_value` | `reason` |
| --- | --- | --- | --- | --- |
| Manual creation | `record_created` | null | `CONFIRMED` | `Registro creado manualmente` |
| Image creation | `record_created` | null | `PENDING` | `Registro creado desde imagen` |
| Review correction/reopening | one row per field | previous value | new value | Editor's reason |
| Review correction without differences | `record_updated` | null | null | Editor's reason |
| Reopen `CONFIRMED` | `status` | `CONFIRMED` | `NEEDS_REVIEW` | Editor's reason |
| Confirmation | `status` | `NEEDS_REVIEW` | `CONFIRMED` | Fixed reason by source |
| Cancellation | `status` | `NEEDS_REVIEW` or `ERROR` | `CANCELED` | Editor's reason |

Value conventions:
- Dates use `Y-m-d`; amounts and percentages use decimal text with two decimals.
- `year`, `week`, and detail `order` use integer text; `status` and `source` use enum values.
- Company changes store the company name, not its id.
- Detail fields use `details[N].campo`, with `N` 1-based and equal to the movement `order`. Added details produce null-to-values rows; removed details produce values-to-null rows.
- Values are canonicalized before comparison, so formatting-only differences do not create events.
- AI transitions and retries are not written to `History`; `AIProcessing` remains their technical evidence.
- The history is append-only: no update or delete endpoint exists, and event rows cannot be edited by clients.

## 11. DeliveryCashDenomination

Represents each cash denomination and quantity in a `Delivery` preparation.

| Field | Type | Required | Notes |
| --- | --- | --- | --- |
| id | UUID / bigint | Yes | Primary key |
| delivery_id | FK | Yes | References `Delivery` |
| value | decimal(10,2) | Yes | Denomination value such as 1000, 500, 200, or 0.50 |
| quantity | integer | Yes | Quantity of that denomination |
| created_at | datetime | Yes | Audit timestamp |
| updated_at | datetime | Yes | Audit timestamp |

Relationships:
- `Delivery 1:N DeliveryCashDenomination`

Important rules:
- Only rows with `quantity > 0` are stored.
- `UNIQUE(delivery_id, value)` must be enforced.
- Database constraints should reject non-positive quantities and denominations outside the official MVP list.
- `SUM(value × quantity)` must equal the current total `RecordDetail.return_amount` for the associated Record when preparing or updating a Delivery.
- `0.50` is an official denomination in the MVP.

## 12. ERD Description

Textual ERD:

```text
Company 1 ─── N Record 0..1 Image 0..N AIProcessing
                    │
                    ├── 1 ─── N RecordDetail
                    ├── 0..1 ─── 1 Delivery ─── N DeliveryCashDenomination
                    │
                    └── 1 ─── N History
User 1 ─────────────┘
RecordDetail 1 ─── N History
Delivery 0..1 ─── N History
```

## 13. Recommended Types

- Money fields: `DECIMAL(12,2)`
- Percentage field: `DECIMAL(5,2)` nullable
- Status fields: enums
- File references: string path or object key
- Audit timestamps: UTC datetimes

## 14. Notes for Implementation

- Use strict decimal handling for financial values.
- Keep the schema minimal and aligned to MVP needs.
- Do not add extra tables for reports or totals unless a concrete need appears later.
- Implement every schema change through a Laravel migration.
- Do not modify an already-applied migration to change shared or deployed schema; create a new migration instead.
- Use Eloquent relationships for aggregate navigation and database transactions for multi-entity business operations.
- Add feature tests for constraints and workflows that cannot be expressed reliably through model unit tests alone.
