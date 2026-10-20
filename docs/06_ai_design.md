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
- identifying multiple movements within one image;
- extracting `affiliation` and `branch` for each movement;
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

The AI returns one JSON object using the following extraction shape for the experimental spike and the SPEC 08 candidate. `record` contains general document fields, each item in `details` contains movement-specific fields in visual order, and `source_totals` contains totals transcribed from the document when present. Production pipeline and persistence behavior are specified separately in SPEC 08.

```json
{
  "record": {
    "company_name": { "value": "Compañía Ejemplo", "confidence": 0.98 },
    "year": { "value": 2026, "confidence": 0.99 },
    "week": { "value": 38, "confidence": 0.95 },
    "week_start_date": { "value": "2026-09-14", "confidence": 0.90 },
    "week_end_date": { "value": "2026-09-20", "confidence": 0.90 }
  },
  "details": [
    {
      "affiliation": { "value": "AF-123", "confidence": 0.92 },
      "branch": { "value": "Guadalajara", "confidence": 0.96 },
      "income": { "value": "10000.00", "confidence": 0.98 },
      "commission_percentage": { "value": "2.00", "confidence": 0.88 },
      "source_commission": { "value": "200.00", "confidence": 0.96 },
      "source_return_amount": { "value": "9800.00", "confidence": 0.96 }
    }
  ],
  "source_totals": {
    "income": { "value": "10000.00", "confidence": 0.95 },
    "commission": { "value": "200.00", "confidence": 0.95 },
    "return_amount": { "value": "9800.00", "confidence": 0.95 }
  }
}
```

All properties shown in the contract are required, including when a value is not visible. Each extracted field is an object containing `value` and `confidence`.

### Value types and normalization

- `company_name`, `affiliation`, and `branch` use strings or `null`.
- `year` and `week` use integers or `null`. A business week is not interpreted as an ISO week.
- `week_start_date` and `week_end_date` use `YYYY-MM-DD` strings or `null`. Do not derive dates from the year/week; only transcribe dates supported by the image.
- The model transcribes monetary amounts and `commission_percentage` faithfully as strings, retaining visible currency symbols, grouping/decimal separators, and percent signs. The experimental normalizer accepts only unambiguous patterns and converts accepted values to decimal strings with a period and exactly two fractional digits (for example, `"$1,234.56"` -> `"1234.56"`, `"1.234,56"` -> `"1234.56"`, `"5%"` -> `"5.00"`). Ambiguous forms such as `"1,200"`, `"1.200"`, and malformed values such as `"55.963.03"` remain unconvertible issues; they are not guessed.
- `details` is an array with at least one movement. Array order is the visual order in the document; the backend assigns the persisted `order` value.
- `affiliation` and `branch` are movement-level fields and belong inside each `details` item.
- `operation_number` is omitted from AI extraction for this spike. Movement order comes from each item's array position.
- `source_totals` always contains `income`, `commission`, and `return_amount`. If a total is not shown or cannot be read, its value is `null`.

The `source_commission`, `source_return_amount`, and `source_totals` values are evidence transcribed from the document only. They are not official values and must not be calculated or treated as the values stored in `RecordDetail`.

## 5. Required JSON Shape and Missing Values

Every expected property must exist. When a field is not visible, is unreadable, or cannot be identified without guessing, return:

```json
{ "value": null, "confidence": 0 }
```

Every expected property must remain present, even if its value is unknown. `confidence` is a number from `0.0` to `1.0`; it is an expression of model confidence, not a statistical probability or business validation.

## 6. Confidence Rules

Each field contains:
- `value`
- `confidence`

Confidence is between `0.0` and `1.0`.
A `null` value requires confidence `0`.

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

The AI extraction contract does not include `operation_number`; Laravel assigns movement order from array position.

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

The SPEC 06 controlled comparison tested three models on five representative images, with two serial runs per image. The recommended initial candidate for SPEC 08 is `qwen2.5vl:3b`, selected by the specified priority order because it had the highest field-level agreement (138/154). Its ten outputs failed the structural contract due to null values with non-zero confidence, and its movement counts were correct in four of ten runs. It remains a candidate for human-reviewed extraction, not a claim of production accuracy. See [`ai-spike/benchmark-v4.md`](ai-spike/benchmark-v4.md) for metrics and alternatives.

The benchmark does not establish a minimum semantic accuracy gate or block SPEC 07/SPEC 08.

Measure:
- processing time;
- RAM usage;
- accuracy;
- JSON quality;
- the model's ability to interpret real images.

## 19. Prompt

The MVP uses a single prompt for the current document format.

The experimental prompt v4 is versioned in [`ai-spike/prompt-v4.txt`](ai-spike/prompt-v4.txt). It instructs the model to transcribe visible values, preserve movement order, separate movements from totals, use `null` with confidence `0` for missing or unreadable data, and return only the expected JSON. Dates are requested in ISO form without deriving them from the period. Monetary values and percentages are transcribed faithfully; deterministic numeric normalization happens after extraction. Preserve raw output and report issues instead of guessing or hiding questionable values.

## 20. Final Principle

AI proposes.

The backend validates and calculates.

The person reviews and confirms.

Only `CONFIRMED` is official information.
