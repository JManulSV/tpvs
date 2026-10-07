# Phase 8 - Technical Implementation Roadmap

## 1. Purpose

Turn the approved product, business, data, AI, and UI/UX designs into an implementation sequence with a verifiable checkpoint at the end of every module.

A module is complete only when its behavior works, its acceptance checks pass, and the team can safely continue to the next module.

## 2. Implementation Strategy

Build bottom-up:

```text
Infrastructure
  -> Backend and database
  -> Business rules
  -> AI
  -> Documents
  -> Frontend
  -> End-to-end integration
  -> Testing
  -> Deployment
```

The AI model is validated early with a technical spike. Do not build the complete production AI pipeline before confirming that the selected local vision model can process representative images within acceptable time and resource limits.

## 3. Module Roadmap

| Phase | Module | Outcome |
| --- | --- | --- |
| 8.1 | Roadmap | Approved technical plan and checkpoints |
| 8.2 | Preparation | Backend, frontend, and local infrastructure foundations |
| 8.3 | Database | Migrations, constraints, indexes, and Eloquent relationships |
| 8.4 | Authentication | Login, logout, current user, roles, and authorization |
| 8.5 | Companies | Read and create company catalog entries |
| 8.6 | Manual records | Create, validate, review, and confirm records without AI |
| 8.7 | Images | Private upload, storage, association, and authorized viewing |
| 8.8 | AI spike | Measure Ollama and vision-model feasibility using sample images |
| 8.9 | AI pipeline | Asynchronous extraction, validation, persistence, and error handling |
| 8.10 | Review | Correct, confirm, and cancel records |
| 8.11 | History | Audit record and delivery changes |
| 8.12 | Delivery | Prepare delivery information and validate cash breakdown |
| 8.13 | Word | Generate the delivery document |
| 8.14 | Reports and Excel | Confirmed-record reports and workbook export |
| 8.15 | Frontend | Complete role-aware UI/UX |
| 8.16 | Integration | Verify the complete business flow end to end |
| 8.17 | Testing | Run normal, failure, authorization, and regression scenarios |
| 8.18 | Deployment | Deploy on the office LAN and verify from another workstation |

## 4. Phase Details

### 8.2 - Preparation

**Goal:** Create correctly configured, runnable projects and local infrastructure.

Backend:
- Laravel API
- PostgreSQL
- environment configuration through `.env`
- Docker support
- API health endpoint
- queue configuration and worker entry point
- private local storage configuration

Frontend:
- Next.js
- TypeScript
- Tailwind CSS
- shadcn/ui
- Axios
- TanStack Query
- React Hook Form
- Zod

Infrastructure:
- Git repository and ignore rules
- Docker Compose for application dependencies
- documented environment variables
- local network configuration for development

**Checkpoint:** Backend and frontend start successfully and the frontend can communicate with the backend through the intended BFF/API path.

**Commit:** `chore: initialize project infrastructure`

### 8.3 - Database

Implement the approved model:

```text
User
Company
Record
RecordDetail
Image
AIProcessing
History
Delivery
DeliveryCashDenomination
```

Implement migrations, foreign keys, indexes, check constraints, unique constraints, and Eloquent relationships. Cash denomination constraints must enforce positive quantities, allowed denomination values, and unique denomination values per Delivery. In particular:

```text
Record
  ├── RecordDetails
  ├── Image (0..1)
  ├── History
  └── Delivery (0..1)
        └── DeliveryCashDenominations
```

`Delivery` is the single editable delivery-preparation record associated with a `Record` in the MVP. It is not a versioned document entity. Generated `.docx` files remain ephemeral.

**Checkpoint:** Create a complete `Record` and related details, image, history, delivery, and cash denominations through Laravel/Tinker; relationships and constraints behave as specified.

**Commit:** `feat: create core database schema`

### 8.4 - Authentication and Authorization

Implement:
- login;
- logout;
- current-user (`me`) endpoint;
- JWT authentication stored in an HttpOnly cookie;
- Next.js Backend-for-Frontend (BFF) handling of authenticated browser requests;
- `EDITOR` and `VIEWER` roles and authorization policies.

**Checkpoint:** `EDITOR` can perform authorized mutations; `VIEWER` can read permitted resources but receives a forbidden response for mutations.

**Commit:** `feat: implement authentication and authorization`

### 8.5 - Companies

Implement:

```text
GET  /api/companies
POST /api/companies
GET  /api/companies/{id}
```

Search may be included. Company names are immutable after creation in the MVP; no rename or delete operation is exposed.

**Checkpoint:** Create a company, retrieve it, and associate it with a `Record`.

**Commit:** `feat: implement company management`

### 8.6 - Manual Records

Implement the first complete business workflow without AI:

```text
Create Record
  -> Add RecordDetails
  -> Calculate and validate
  -> NEEDS_REVIEW
  -> Human review
  -> CONFIRMED
```

Build `BusinessValidator` for required fields, commission, return, totals, and applicable business rules. A manual `Record` can be created and confirmed without an image. Delivery can also be prepared without an image; an image must be attached before Word generation.

**Checkpoint:** Create and confirm a valid manual record without creating an `AIProcessing` attempt.

**Commit:** `feat: implement manual record workflow`

### 8.7 - Images

Implement upload, private storage, `Image` metadata, and association with `Record`. Validate MIME type, file size, filename handling, storage location, authorized access, and record association.

**Checkpoint:** An `EDITOR` can attach an image to a `Record` and view it through an authorized request.

**Commit:** `feat: implement record image storage`

### 8.8 - Technical AI Spike

This is a controlled experiment, not the production AI module. Use 5-10 real or anonymized representative images and test:

- image submission to Ollama and the candidate vision model;
- end-to-end processing time;
- memory and hardware usage;
- JSON contract adherence;
- extraction accuracy for required fields;
- detection and ordering of multiple movements;
- failure modes and repeatability.

Record measurements and sample outcomes. Decide whether to continue, optimize the model/runtime, or change the model before building the complete pipeline. Do not build production architecture solely on an unvalidated assumption.

**Checkpoint:** A documented go/optimize/change decision is supported by measurements from the sample set.

### 8.9 - AI Pipeline

Implement:

```text
ProcessImageJob
  -> AIService
  -> OllamaAIService
  -> AiExtractionResult
  -> ExtractionValidator
  -> RecordExtractionService
  -> BusinessValidator
  -> NEEDS_REVIEW or ERROR
```

Persist every `AIProcessing` attempt. Technical failures move the record to `ERROR`; usable extraction results always require human review and never auto-confirm.

**Checkpoint:** Uploading an image starts processing and persists the provisional record data and outcome without confirming the record.

**Commit:** `feat: implement AI extraction pipeline`

### 8.10 - Review and Confirmation

Implement Editor review, correction, validation, confirmation, and cancellation:

```text
NEEDS_REVIEW
  -> Editor corrects or accepts
  -> Backend validates
  -> CONFIRMED or remains NEEDS_REVIEW
```

**Checkpoint:** Complete image upload -> AI extraction -> review -> correction -> confirmation. A record is confirmed only by an authorized human after backend validation.

**Commit:** `feat: implement record review and confirmation`

### 8.11 - History

Record the field, old value, new value, user, reason, and timestamp for meaningful record and delivery-preparation changes. When a confirmed record is modified, preserve the required `CONFIRMED -> NEEDS_REVIEW -> CONFIRMED` workflow and its audit entries.

**Checkpoint:** Changes to record fields and delivery preparation can be traced to the user and before/after values.

**Commit:** `feat: implement record change history`

### 8.12 - Delivery Preparation

Implement the single `Delivery` associated with a confirmed `Record`. It centralizes delivery date, delivering person, receiving person, prepared return amount, and `DeliveryCashDenomination` rows.

Validate:

```text
SUM(denomination.value * denomination.quantity)
  = current Record return amount
```

Only positive quantities are persisted. A mismatch blocks successful preparation and document generation. If the record's current return changes after preparation, show that the delivery uses an older amount and allow the Editor to update the same delivery. Do not introduce delivery versions or delivery status states in the MVP.

**Checkpoint:** A valid delivery is saved and its cash total equals the confirmed record return; a mismatch is rejected.

**Commit:** `feat: implement delivery preparation`

### 8.13 - Word Document

Generate `.docx` from the current `Delivery`, its `Record`, the associated `Image`, and cash denominations. The image is mandatory. Use `MoneyToWordsService` for the amount in words. Generated files are returned for download and are not stored as delivery versions.

**Checkpoint:** A valid prepared delivery with an image generates a Word document; a missing image or invalid delivery blocks generation.

**Commit:** `feat: implement delivery document generation`

### 8.14 - Reports and Excel

Implement reports and Excel using `CONFIRMED` records only. Support filters for year, month, week, and company. The report month is the month containing `week_end_date`. Excel includes Summary, Records, Details, and Cash sheets; there is no AI extraction sheet.

**Checkpoint:** Filters, totals, company breakdowns, included records, and all four sheets agree with confirmed database data.

**Commit:** `feat: implement reports and Excel export`

### 8.15 - Frontend

Implement the approved UI/UX:

```text
Login
  -> Dashboard
  -> Records (new, list, review, detail)
  -> Companies
  -> Reports
  -> Prepare delivery
```

Respect role-based actions and the frontend boundary:

```text
UI/hooks -> services -> Next.js BFF -> Laravel API
```

Deliver in small module-based commits rather than one large frontend change.

**Checkpoint:** The defined role-specific screens and core actions work against the implemented APIs.

### 8.16 - End-to-End Integration

Verify the full business journey:

```text
Supplier sends image through WhatsApp
  -> Editor downloads image
  -> Upload
  -> AI
  -> Review
  -> Confirm
  -> Prepare delivery
  -> Word
  -> Reports
  -> Excel
```

WhatsApp remains a manual source in the MVP; no direct integration is added.

**Checkpoint:** The complete workflow succeeds with persisted data and correct role enforcement. This is the MVP end-to-end milestone.

### 8.17 - Testing

Cover normal flows:
- readable image;
- multiple movements;
- human review and confirmation;
- valid delivery;
- Word generation;
- Excel generation.

Cover failure and authorization flows:
- unreadable image;
- invalid AI JSON;
- unknown company;
- missing field;
- incorrect commission;
- incorrect return;
- mismatched cash;
- `VIEWER` attempting a mutation;
- canceled record excluded from official reports;
- confirmed record modified and returned to review;
- delivery prepared with an older amount;
- missing image blocking Word generation.

**Checkpoint:** Relevant automated tests and regression checks pass; unresolved failures block release.

### 8.18 - Deployment

Deploy on the office LAN. The application server hosts Next.js, Laravel, PostgreSQL, the queue worker, and private storage. The AI PC hosts Ollama and the vision model; Laravel communicates with it over the LAN.

**Checkpoint:** Complete a real workflow from another office workstation, including authorized access to the application and the AI service.

**Commit:** `chore: deploy MVP to office network`

## 5. Definition of Done

Every module must have:

- implemented scope matching the approved documentation;
- validated inputs and explicit error behavior;
- authorization where relevant;
- tests appropriate to its risk and behavior;
- a successful checkpoint demonstration;
- documented configuration or operational requirements where needed.

No module may bypass backend validation, role authorization, the human confirmation rule, or confirmed-only official reporting.
