# Validation and Authorization

## Three Validation Boundaries

```text
HTTP validation
  -> Is the request shape and input acceptable?

Business validation
  -> Does the data obey TPVS rules?

Authorization
  -> Is this user allowed to perform this action?
```

Do not replace one boundary with another.

## HTTP Validation

Use Laravel Form Requests for endpoint payloads. Validate types, required fields, formats, limits, file properties, and relationships before calling an application workflow.

## Business Validation

Business validators must enforce the rules in `docs/02_business_rules_and_states.md`, including financial consistency, state transitions, confirmed-only reporting, delivery cash reconciliation, and human confirmation.

Backend calculations are authoritative. AI values are input for review, never official financial truth.

## Authentication and Authorization Status

Authentication is implemented with Laravel Sanctum stateful SPA authentication backed by the Laravel database session. Authenticated users may read permitted resources; only `EDITOR` may mutate. The `auth:sanctum` and `editor` middleware are available for route groups.

| Action | Editor | Viewer |
| --- | --- | --- |
| View records and reports | Yes | Yes |
| Create or upload | Yes | No |
| Edit, confirm, or cancel | Yes | No |
| Prepare delivery | Yes | No |
| Generate reports | Yes | Yes |
| View a Record image | Yes | Yes |

Implement resource-specific permissions with Laravel Policies as each resource is introduced. Feature tests currently verify the central allowed and forbidden role behavior.

Protect state-changing requests with Laravel CSRF validation. The Next.js BFF is the browser-facing same-origin boundary and must forward the session cookie and `X-XSRF-TOKEN`; do not exempt API mutations from CSRF to simplify the proxy.

## Records — Phase 8.6 Contract Decisions

The Records API contract is documented in [`03_api_contract.md`](03_api_contract.md). Endpoints are implemented incrementally under SPEC 04; keep this validation guidance aligned with the verified implementation.

### Authorization and Allowed Operations

| Operation | `EDITOR` | `VIEWER` |
| --- | --- | --- |
| List and view any record | Yes | Yes |
| Create a manual record | Yes | No (`403`) |
| Replace a manual record in `NEEDS_REVIEW` | Yes | No (`403`) |
| Confirm a manual record in `NEEDS_REVIEW` | Yes | No (`403`) |
| Replace or confirm a record with another source or state | No (`409`) | No (`403`) |

Every mutation must authorize on the backend, including requests made directly to Laravel or through the BFF. Client-hidden controls are only presentation.

## Record Images — Phase 8.7 Contract Decisions

The target image endpoint contract is documented in [`03_api_contract.md`](03_api_contract.md) and remains planned until implemented and tested.

| Operation | `EDITOR` | `VIEWER` |
| --- | --- | --- |
| Upload or replace a Record image | Yes, except a canceled Record or an image with AI attempts | No (`403`) |
| View an image on a non-canceled Record | Yes | Yes |
| View or mutate an image on a canceled Record | No (`409`) | No (`409` for read, `403` for mutation authorization if applicable) |

Require `auth:sanctum` on both routes. The upload is state-changing and retains stateful CSRF validation. Authorize image access through the parent Record Policy; do not expose an independent public image URL or rely on frontend visibility.

Validate the uploaded file as non-empty JPEG, PNG, or WebP no larger than 10 MiB. Determine MIME from the content and do not trust the supplied content type or extension alone. Sanitize the client filename before persisting it as metadata and generate the storage key on the server. Reject unsupported content, mismatches, invalid filenames, and oversize files with the standard `422` response.

Only `EDITOR` may upload or replace. `VIEWER` mutation attempts return `403`. An attempt to upload or view an image on a canceled Record returns a stable `409` domain response; no image for a non-canceled Record returns `404` on GET. Replacing an image that has associated `AIProcessing` rows returns `409 IMAGE_REPLACEMENT_BLOCKED`, preserving processing provenance.

The upload must not change source/status, create History or AIProcessing, or enqueue work. Keep file and database consistency with a generated new file, transactionally updated association under a Record lock, compensation on database failure, and post-commit deletion of the old file. Filesystem cleanup failure after commit is diagnostic/operational; it must not undo or misreport the committed database association.

### HTTP and Period Validation

Use Form Requests for list, create, replace, and confirmation inputs. Validate the company relationship, required general fields, detail array shape, decimal precision, mutually exclusive commission inputs, and all query filter types. Return the standard Laravel `422` response for malformed or invalid user input.

`year` and `week` are business period identifiers: `year` is the calendar year and `week` is an integer from 1 to 53. Do not validate against ISO or derive dates from these values. Require valid `week_start_date` and `week_end_date` dates with start not after end. Do not impose weekdays or a fixed range length. When a list query supplies `week`, require `year` as well.

### Business Validation and Financial Arithmetic

For every manual detail, exactly one of `commission_percentage` or fixed-amount `commission` is required. `income` and fixed commission are non-negative decimal values with at most two decimal places. Percentage is from 0 through 100 and has at most two decimal places. Fixed commission may not exceed income. At least one detail is required.

Use decimal arithmetic, not binary floating-point arithmetic, for financial calculations. When a percentage is supplied, calculate commission with `ROUND_HALF_UP` to two decimal places. When a fixed commission is supplied, persist it and leave `commission_percentage` null. In both modes calculate `return_amount = income - commission`; do not accept return or Record-level totals from the client. The resulting return must be non-negative. All values are persisted at their schema's decimal precision.

A valid new manual aggregate is created with `source=MANUAL` and status `CONFIRMED`, because the Editor enters and reviews the data in one operation. Create a History status event with null previous value, new value `CONFIRMED`, authenticated actor, and fixed reason `Registro manual confirmado`. Do not create an Image, AIProcessing, or AI job. A Record, its details, and History are persisted in one transaction.

Replacement is full-aggregate rather than partial: validate all fields and details, require a non-empty reason, and allow only `MANUAL` + `NEEDS_REVIEW`. Update the Record, replace its details in submitted order, and create one generic History event (`manual_record_updated`, null before/after values, authenticated actor, supplied reason) in one transaction. Do not allow this endpoint to edit `CONFIRMED` records or change source/status.

The separate confirmation endpoint is retained for future review-required workflows. Revalidate the complete business aggregate, allow only `NEEDS_REVIEW`, and transactionally persist status `CONFIRMED` with a History status transition and fixed reason `Registro manual confirmado`. Manual creation confirms in its creation transaction instead. Do not accept a client-provided target state or confirmation reason. Invalid source/state transitions return `409` with a stable business error code; no partial writes are allowed.

The list's `month` filter uses `week_end_date`, consistent with the product reporting convention. Search is a case-insensitive substring over company name, operation number, affiliation, and branch. All calculated totals are derived from details and are never persisted as the financial source of truth.
