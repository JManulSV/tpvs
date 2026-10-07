# AI Design

## 1. Objective

Use a vision model to interpret supplier images and extract the information required to build a `Record` and its `RecordDetail` rows.

Core principle:

> AI proposes. The backend validates. The person confirms.

AI is never the final authority for financial data.

## 2. AI Responsibilities

The AI is responsible for:
- reading the image;
- identifying the company;
- extracting `year` and `week`;
- extracting `week_start_date` and `week_end_date` when visible;
- extracting `affiliation`;
- extracting `branch`;
- identifying multiple movements within one image;
- extracting `operation_number`;
- extracting `income`;
- extracting `commission_percentage` when visible;
- extracting `source_commission` as shown in the document;
- extracting `source_return_amount` as shown in the document;
- extracting source totals when visible;
- indicating missing or doubtful information;
- preserving the visual order of the movements;
- returning structured JSON.

The AI does not:
- confirm records;
- calculate official values;
- decide whether a commission is correct;
- decide what enters reports;
- modify the database directly;
- generate the official Excel;
- generate the official Word document.

## 3. Input Contract

The AI receives:
- one image;
- one prompt with instructions.

The AI does not receive direct access to PostgreSQL or the rest of the application.

The company name is interpreted by the AI and later matched by Laravel against the `Company` table.

Example:

```text
"Company X" -> Laravel -> company_id = 7
```

MCP is not required for this MVP because the system is performing information extraction from an image, not agentic tool use.

## 4. Output Contract

The AI returns structured JSON.

The structure contains:
- `record`
- `company_name`
- `year`
- `week`
- `week_start_date`
- `week_end_date`
- `affiliation`
- `branch`
- `details`
- `source_totals`

Each movement contains:
- `operation_number`
- `income`
- `commission_percentage`
- `source_commission`
- `source_return_amount`

`source_totals` may contain:
- `income`
- `commission`
- `return_amount`

The `source_*` values represent what appears in the document and are evidence only.

They are not the official values stored in `RecordDetail`.

## 5. Required JSON Shape

Every expected property must exist.

If a field cannot be read:

```json
{
  "value": null,
  "confidence": 0
}
```

The property must still exist. Only the value becomes `null`.

If an entire property disappears from the JSON, that is a contract violation.

## 6. Confidence Rules

Each field contains:
- `value`
- `confidence`

Confidence is between `0.0` and `1.0`.

Initial threshold:
- `0.70`

Below `0.70`:
- `LOW_CONFIDENCE`

The application must not present low-confidence values as authoritative.

Suggested UI labels:
- green: high confidence
- yellow: review
- red: missing information
- red: inconsistency

Confidence is not a statistical probability and does not automatically validate a value.

## 7. Multiple Movements

One image always represents one `Record`.

If the image contains several movements:

```text
Image
  -> 1 Record
  -> 3 RecordDetails
```

The system does not create multiple records for one image.

The movements preserve their visual order and Laravel assigns the `order` field.

If an `operation_number` cannot be identified, it must not be invented.

## 8. Validation Layers

Validation is divided into layers.

### ExtractionValidator

Checks the technical AI contract:
- required properties;
- data types;
- formats;
- confidence;
- `details` structure;
- presence of at least one movement.

### BusinessValidator

Checks business rules:
- commission;
- return;
- sums;
- totals;
- consistency;
- missing data;
- confidence;
- discrepancies with source values.

Example:

```text
Income - Commission = ReturnAmount
```

The AI may show a commission or return value observed in the document, but the backend calculates the official values.

## 9. ERROR vs NEEDS_REVIEW

### ERROR

Means the extraction could not be processed technically.

Examples:
- Ollama is unavailable;
- timeout;
- missing model;
- JSON cannot be parsed;
- required property is completely absent;
- the response structure cannot be interpreted.

### NEEDS_REVIEW

Means the system has usable information, but a person must review it.

Examples:
- missing field;
- low confidence;
- type is wrong but still identifiable;
- inconsistent calculation;
- different totals;
- doubtful information.

The number of issues is only a UX signal.

Example:

```text
4 elements require review
```

## 10. State Flow

```text
PENDING
  -> PROCESSING
  -> NEEDS_REVIEW
  -> CONFIRMED
```

Technical problem:

```text
PROCESSING
  -> ERROR
  -> RETRY
  -> PROCESSING
```

Incorrect document:

```text
NEEDS_REVIEW
  -> CANCELED
```

A confirmed record can be modified:

```text
CONFIRMED
  -> NEEDS_REVIEW
  -> validation
  -> CONFIRMED
```

Every modification requires history.

## 11. Record Creation From the Start

There is no `Draft` entity.

The `Record` is created from the beginning.

The AI may partially extract:

```text
Record
├── company = Company X
├── week = 38
├── branch = null
└── details = ...
```

and the record may remain in `NEEDS_REVIEW` until the Editor completes the missing data.

Only `CONFIRMED` is official and enters reports/documents.

## 12. AIService

An abstraction exists:

`AIService`

Its responsibility:
- receive an image and return a structured extraction.

It does not know about:
- `Record`;
- `RecordDetail`;
- business states;
- calculations;
- confirmation;
- report rules.

Initial implementation:

`OllamaAIService`

This allows Ollama to be replaced later without changing the whole application.

## 13. DTOs

The AI result is represented using DTOs:

```text
AiExtractionResult
├── AiRecordData
├── AiRecordDetail[]
└── AiSourceTotals
```

Each field conceptually uses:

```text
AiField
├── value
└── confidence
```

The DTO represents what the AI returned.

The validator decides whether it can be used.

Wrong types are not silently corrected.

Example:

```text
year = "2026"
```

Even if it looks convertible to an integer, it is not transformed automatically.

It is preserved for review.

## 14. Transformation to Domain

Once an extraction is structurally valid:

```text
AiExtractionResult
  -> RecordExtractionService
  -> Record + RecordDetails
```

This service transforms the AI extraction into domain data.

Example:

```text
company_name
  -> Company lookup
  -> company_id
```

Financial logic remains in the backend.

## 15. AIProcessing

Final relation:

```text
Record 1 -> 1 Image -> 1 AIProcessing
```

`Record.ai_processing_id` does not exist.

`AIProcessing` contains:
- `image_id`
- `provider`
- `model`
- `status`
- `result`
- `error`
- `started_at`
- `completed_at`
- timestamps

The original AI result is preserved.

## 16. Retry

In the MVP there is only one logical processing attempt per image.

If a technical error occurs:

```text
AIProcessing
ERROR
  -> RETRY
  -> PROCESSING
```

The same `AIProcessing` record is reused.

No multiple AI proposals are created for the same logical attempt.

If the AI finished correctly but the result only needs review:
- the AI is not called again;
- the Editor corrects the record manually.

If the image is wrong:
- the record is canceled;
- a new record is created if needed.

## 17. Jobs and Architecture

Processing is asynchronous:

```text
Next.js
  -> Laravel
  -> Image + Record
  -> Queue
  -> ProcessImageJob
  -> AIService
  -> Ollama
```

The job coordinates the process, but it does not contain all business logic.

Architectural flow:

```text
Frontend
  -> Laravel API
  -> Queue
  -> Worker
  -> AIService
  -> Ollama
```

## 18. AI Infrastructure

Ollama is used initially as a local model server.

The application does not depend on Ollama being on a specific machine.

Provisional architecture:

```text
Laravel Worker
  -> AIService
  -> HTTP
  -> Ollama on secondary PC
```

Before deciding on final hardware or model choice, test approximately 5 to 10 real or anonymized images.

Measure:
- processing time;
- RAM usage;
- accuracy;
- JSON quality;
- the model's ability to interpret real images.

## 19. Prompt

The MVP uses a single prompt for the current document format.

The prompt must instruct the model to:
- describe what the image contains;
- specify which fields to extract;
- handle multiple movements;
- not invent information;
- use null when a value is not visible or clear;
- provide confidence per field;
- extract source values when they appear;
- not calculate financial values that are not explicitly present;
- not decide if the record is valid;
- return only the expected JSON;
- normalize dates as `YYYY-MM-DD`;
- preserve the visual order of the movements.

The final prompt will be built during implementation and refined with real-image testing.

## 20. Final Principle

AI proposes.

The backend validates and calculates.

The person reviews and confirms.

Only `CONFIRMED` is official information.
