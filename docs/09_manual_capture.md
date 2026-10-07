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
  -> NEEDS_REVIEW
  -> human review
  -> validation
  -> CONFIRMED
```

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
