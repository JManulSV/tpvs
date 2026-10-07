# AI Development Blueprint

## 1. Purpose

This document is designed to help AI coding assistants implement the platform safely and consistently.

## 2. Implementation Principles

- Build one module at a time.
- Keep state transitions explicit.
- Use the database as the source of truth.
- Preserve all processing attempts.
- Preserve edit history.
- Do not let AI bypass human confirmation.
- Use exact enum names and field names from the data model.

## 3. Build Order

The authoritative implementation sequence and module checkpoints are in `11_technical_roadmap.md`. This blueprint provides implementation prompts and supporting guidance; follow the roadmap, including the early AI feasibility spike, and keep prompts consistent with the data model.

## 4. Suggested Module Prompts for AI Coding Tools

### Prompt: Database Schema
```text
Create a PostgreSQL schema for the MVP with these entities: User, Company, Record, RecordDetail, Image, AIProcessing, History, Delivery, and DeliveryCashDenomination.
Use enums for roles and Record states, decimal fields for money, a nullable DECIMAL(5,2) field for commission_percentage, and explicit week, year, week_start_date, and week_end_date fields on Record.
Company name is immutable after creation. A Record has zero or one Image and zero or one Delivery. Delivery has many DeliveryCashDenomination rows. Do not add delivery versions or delivery status states.
Include foreign keys and indexes for reporting and audit queries.
```

### Prompt: State Machine
```text
Implement a record state machine for Record with states PENDING, PROCESSING, NEEDS_REVIEW, ERROR, CONFIRMED, and CANCELED.
Reject invalid transitions.
Support the special flow where a CONFIRMED record can only be modified by moving it back to NEEDS_REVIEW first.
```

### Prompt: Financial Validation
```text
Implement validation for RecordDetail using decimal-safe arithmetic.
Validate that return_amount = income - commission.
If commission_percentage is present, validate commission as income * commission_percentage / 100.
Use a consistent 2-decimal rounding strategy.
Return a structured result that indicates whether the detail is valid or requires review.
```

### Prompt: AI Processing Workflow
```text
Implement an AI processing workflow that creates a new AIProcessing attempt for every run.
The workflow must:
1. Receive an image reference.
2. Call the AI provider.
3. Store the raw and normalized result.
4. Store errors when the call fails.
5. Never overwrite older attempts silently.
Design the provider as an interface so the model vendor can be replaced later.
```

### Prompt: AI Extraction Contract
```text
Define the AI output contract for the MVP.
The AI must return structured JSON containing record, company_name, year, week, week_start_date, week_end_date, affiliation, branch, details, and source_totals.
Each field must contain value and confidence.
If a field cannot be read, keep the property and return value = null and confidence = 0.
Never omit required properties.
Use null for unreadable values instead of inventing data.
Preserve the visual order of multiple movement details.
```

### Prompt: Extraction Validator
```text
Implement an ExtractionValidator that checks the AI JSON contract.
Validate required properties, types, confidence, detail structure, and the existence of at least one movement.
Return a structured result that lists contract violations separately from business rule violations.
```

### Prompt: Business Validator
```text
Implement a BusinessValidator that checks financial consistency for extracted data.
Validate commission, return amount, totals, missing values, and discrepancies with source fields.
Do not trust AI values as official.
Calculate official values in the backend.
```

### Prompt: Record Extraction Service
```text
Implement a RecordExtractionService that transforms a valid AiExtractionResult into domain data.
Map company_name to Company via lookup, convert extracted details into RecordDetail rows, and keep AI source fields for review.
The service must not confirm records or write official totals.
```

### Prompt: Manual Record Creation
```text
Implement manual record creation for the MVP.
Allow the Editor to create a Record with source = MANUAL without an Image or AIProcessing row.
Apply the same business validation rules used for image-based records.
Route the manual record through NEEDS_REVIEW before confirmation.
An Image is still required later to generate Word.
```

### Prompt: Review Screen
```text
Create a review screen for the Editor that shows the original image, extracted fields, movement details, validation issues, and the current record state.
Allow editing of fields, validation of calculations, confirmation, and cancellation.
```

### Prompt: Audit Log
```text
Implement a history system that records every edit with record id, optional record detail id, user id, field name, old value, new value, reason, and timestamp.
Expose queries to retrieve history by record and by detail.
```

### Prompt: Reports
```text
Implement report queries that only use CONFIRMED records.
Support filtering by company, week plus year, and month derived from week_end_date.
Return totals for income, commission, and return_amount.
Do not use stored totals as the source of truth.
```

### Prompt: Excel and Word Output
```text
Generate Excel and Word documents from database query results.
Excel is for reporting and Word is for the delivery document.
The outputs must not depend on spreadsheets as input.
```

### Prompt: Document Generation
```text
Implement on-demand document generation for the MVP.
Create an Excel report endpoint at GET /api/documents/excel with optional year, month, week, and company_id filters. Month filtering uses Record.week_end_date. Include summary, records, details, and cash sheets.
Generate a Word document from a confirmed Record and its associated Delivery using POST /api/deliveries/{delivery}/document.
The Delivery stores delivery_date, delivered_by_name, received_by_name, prepared_return_amount, and DeliveryCashDenomination rows. It is the single editable preparation per Record, without versions or status states.
The Word document must use the stored Image, Delivery cash breakdown, and a deterministic MoneyToWordsService. An Image is mandatory for Word generation.
Do not store generated documents permanently.
```

### Prompt: Cash Breakdown Validation
```text
Implement DeliveryCashDenomination handling for official denominations including 0.50.
Store only rows with quantity > 0.
Validate that the sum of denomination times quantity equals the current Record return amount before completing Delivery preparation or generating Word.
```

### Prompt: Money To Words
```text
Implement a deterministic MoneyToWordsService that converts money amounts to words for the delivery document.
Do not use AI for the conversion.
```

## 5. Role-Specific AI Collaboration Guidance

### Product Role Prompting
Use when defining scope, flows, and acceptance criteria.

Focus on:
- user value;
- operational simplicity;
- business constraints;
- what is in or out of MVP.

### Architect Role Prompting
Use when defining boundaries, persistence, and runtime behavior.

Focus on:
- module boundaries;
- state transitions;
- database structure;
- file storage rules;
- reliability and extensibility.

### Engineer Role Prompting
Use when writing code and tests.

Focus on:
- exact models and types;
- deterministic validation;
- clean services and controllers;
- error handling;
- test cases for edge conditions.

### AI Assistant Prompting Rules
- Always provide the exact entity names.
- Always provide the state machine before asking for implementation.
- Always mention that confirmed records are the only official records.
- Always preserve history and processing attempts.
- Always keep AI output as input to backend validation, not as final truth.

## 6. Suggested Testing Strategy

- Unit tests for state transitions.
- Unit tests for financial validation.
- Integration tests for upload and processing flow.
- Integration tests for report queries.
- Integration tests for history logging.
- Integration tests for Delivery cash validation and stale preparation detection.

## 7. Definition of Done for an AI-Generated Module

A module is complete only when it has:
- implemented behavior;
- type-safe data handling;
- validation;
- tests;
- clear error responses;
- no hidden state mutations;
- no violation of the confirmation workflow.
