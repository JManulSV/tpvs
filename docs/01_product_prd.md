# Product Requirements Document

## 1. Executive Summary and Project Overview

### Product Vision
Build an internal platform that automates the current manual workflow where the operations owner receives supplier images via WhatsApp, transcribes them into Excel, and then recreates the same data in delivery documents.

### Problem Statement
The current process creates:
- repetitive manual work;
- duplicate data entry;
- a high risk of human error;
- weak historical traceability;
- dependency on Excel as the main source of operational truth.

### Proposed Solution
The MVP will provide a controlled workflow where the user manually uploads an image received from WhatsApp, the system stores the original file, AI extracts structured information, the backend validates the data, the Editor reviews and corrects it, and confirmed records are used to generate Excel reports and Word delivery documents.

The record period will be identified by `week` and `year` stored in the database. This makes the week an explicit year-scoped business identifier rather than an open-ended free text field. When a week crosses two months, month-based reports use the month containing `week_end_date`.

### Product Principles
- AI interprets, backend validates, human confirms.
- Excel and Word are outputs, not sources of truth.
- Confirmed records are the only records used in official totals.
- Every processing attempt and every meaningful data change must remain traceable.

### MVP Success Criteria
- Reduce manual transcription effort.
- Reduce duplicate data entry.
- Preserve a reliable record history.
- Generate official reports directly from the database.
- Support controlled correction and confirmation by the Editor.
- Keep company names fixed in the MVP.

## 2. User Personas and Core Objectives

### Editor
The primary operational user.

Goals:
- Upload images.
- Review AI-extracted data.
- Correct missing or wrong fields.
- Validate calculations.
- Confirm or cancel records.
- Generate reports and delivery documents.
- Consult history and prior processing attempts.

### Viewer
Business oversight and reporting user.

Goals:
- Review confirmed records.
- Consult totals and history.
- Filter totals by company and period.
- Export official reports.

### MVP Scope
Included:
- Authentication.
- Role-based access for `EDITOR` and `VIEWER`.
- Image upload and storage.
- AI extraction orchestration.
- Backend validation.
- Human review and confirmation.
- Cancellation of invalid records.
- Weekly and company totals.
- Excel export.
- Word document generation.
- Delivery preparation and cash denomination breakdown.
- Audit history.
- Reprocessing of failed AI attempts.

Excluded:
- Direct WhatsApp integration.
- Mobile app.
- Conversational AI assistant.
- Advanced analytics dashboards.
- Multiple customizable templates.
- Complex notifications.

## 3. Functional Requirements and User Stories

### Authentication
Requirements:
- Users must log in and log out.
- Sessions must persist while the browser remains open and expire when it closes or after 120 minutes of inactivity.
- Users must be differentiated by role.

User stories:
- As a user, I want to log in so I can access the system securely.
- As a user, I want my session to persist while I use the open browser so I do not need to log in repeatedly during work.
- As the system, I want to enforce role-based access so only allowed actions are available.

### Image Management
Requirements:
- Upload an image.
- Store the original file.
- Associate the image to one `Record`.
- Show processing status.
- Allow reprocessing.
- Allow replacing the final image when needed.

### Manual Capture
Requirements:
- Create a `Record` manually without an image.
- Skip AI processing for manual records.
- Apply the same business validations as AI-derived records.
- Route manual records through human review before confirmation.
- Require an image before generating the Word delivery document.

User stories:
- As the Editor, I want to upload the supplier image so the system can extract the data.
- As the Editor, I want to replace the image when the wrong file was uploaded.
- As the Editor, I want to retry processing when AI fails.

### AI Processing
Requirements:
- Send the image to AI.
- Receive structured extracted data.
- Persist each processing attempt.
- Preserve failed and successful attempts.

User stories:
- As the system, I want to call AI so it can extract structured information.
- As the Editor, I want to inspect the extracted content before confirmation.

### Manual Review
Requirements:
- Review extracted data.
- Correct fields.
- Add missing information.
- Validate calculations.
- Confirm or cancel the record.

User stories:
- As the Editor, I want to review the AI output so I can fix mistakes.
- As the Editor, I want to confirm the record only after validation passes.
- As the Editor, I want to cancel invalid records so they do not affect reports.

### Reporting
Requirements:
- Filter by week and year.
- Interpret month filters by the month containing `week_end_date` when a week spans two months.
- Filter by company.
- Consult totals.
- Consult totals by company.
- Export Excel.
- Generate Word output.

User stories:
- As the Viewer, I want to view total returned amounts by week so I can monitor the business.
- As the Editor, I want to generate a Word delivery document for a client.

### Audit History
Requirements:
- Keep track of changes.
- Record who changed the data.
- Record what changed and why.

User stories:
- As the system, I want to store the edit history so audits are possible.
- As the Viewer, I want to know what changed in a record and who changed it.

### Company Management
Requirements:
- Create companies.
- Keep the company name fixed in the MVP after creation.

### Delivery Preparation
Requirements:
- Prepare one editable `Delivery` for a confirmed `Record`.
- Capture delivery date, delivering person, receiving person, and cash breakdown.
- Validate the cash total against the current Record return total.
- Detect stale delivery preparation when the Record return changes.
- Require an associated image to generate the Word delivery document.

## 4. Non-Goals

- No automatic WhatsApp ingestion.
- No mobile application.
- No chatbot-like assistant.
- No advanced BI layer for MVP.
- No multi-template engine.
- No complex notification workflows.
