# Manual Record Capture

## 1. Objective

Allow the Editor to create a `Record` manually without requiring an image or AI processing.

Manual capture is an alternative to the automated image flow.

## 2. Core Rule

The AI flow is optional for record creation.

The business must continue to operate even when:
- the image is unreadable;
- the supplier did not send an image;
- the AI provider is unavailable;
- an extraordinary operational case occurs.

Manual capture ensures the office can keep working.

## 3. Record Source

`Record.source` identifies how the record was created:

- `IMAGE`
- `MANUAL`

### IMAGE

The record was created from an uploaded image.

May include:
- `Image`
- `AIProcessing`

### MANUAL

The record was created manually by the Editor.

At creation, a manual record does not create:
- `Image`
- `AIProcessing`

An `Image` may be attached to the Record later without running AI. If the Editor wants to generate the Word delivery document, an image must be attached first.

## 4. Manual Flow

```text
MANUAL
  -> data capture
  -> BusinessValidator
  -> financial and period validation
  -> CONFIRMED
```

The Editor's manual data entry is also the human review. A valid manual record is created as `CONFIRMED`; it does not pass through a redundant `NEEDS_REVIEW` state. The same create transaction writes a History status event with `previous_value=null`, `new_value=CONFIRMED`, the Editor, and fixed reason `Registro manual confirmado`.

The business week and year follow the office's operational calendar. The week is an integer from 1 to 53 and is not validated as ISO. Period dates must be valid, and the start date cannot be later than the end date. The dates need not be Monday and Sunday and are not calculated from the week number. Company and all period fields are required for MANUAL creation and confirmation, even though they may be null on provisional IMAGE records.

Each movement requires its own `operation_number`, `affiliation`, `branch`, `income`, and exactly one commission input (`commission_percentage` or fixed `commission`). Affiliation and branch belong to the movement, not to the Record. Every movement must have a positive order assigned from its position in the submitted array. MANUAL capture never accepts missing movement values; schema nullability exists only to persist incomplete IMAGE extraction until review.

## 5. Shared Validation

Manual records use the same business rules as image-based records:
- commission validation;
- return validation;
- report inclusion only after `CONFIRMED`.

Cash breakdown validation is performed later as part of the associated `Delivery` preparation.

## 6. Domain Behavior

Manual records are normal records.

Once confirmed, they behave exactly like any other record:
- they appear in reports;
- they appear in Excel;
- they can appear in Word documents after an Image is attached and a valid Delivery is prepared;
- they are included in totals.

## 7. No New Entity

Manual record capture does not create a separate record entity.

The same `Record` model is used for both origins.

Delivery preparation happens later in the shared `Delivery` entity and stores its cash breakdown in `DeliveryCashDenomination`.

## 8. Final Principle

AI automates capture.

It does not control the business.
