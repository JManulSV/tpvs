# Documentation

TPVS MVP documentation. Module status lives only in [`roadmap.md`](roadmap.md); per-feature scope and decisions live in the SPECs under `specs/`.

When documentation conflicts with the code, the code is authoritative for implemented behavior. These documents remain authoritative for business rules and planned behavior.

## Map

| Document | Contents |
| --- | --- |
| [`product.md`](product.md) | Product vision, personas, scope, and requirements |
| [`business-rules.md`](business-rules.md) | States, validations, manual capture, delivery, reporting, and history rules |
| [`data-model.md`](data-model.md) | Entities, fields, relationships, and constraints |
| [`architecture.md`](architecture.md) | Stack, modules, data flow, and API direction |
| [`ai-extraction.md`](ai-extraction.md) | AI extraction contract, validation layers, and retries |
| [`document-generation.md`](document-generation.md) | Word and Excel generation rules |
| [`ui-ux.md`](ui-ux.md) | Screens, navigation, and role behavior |
| [`roadmap.md`](roadmap.md) | Module status by SPEC, pending phases, definition of done |
| [`BACKLOG.md`](BACKLOG.md) | Pending ideas that do not have a SPEC yet |
| [`backend/README.md`](backend/README.md) | Laravel conventions, API contract, workflows, testing, operations |
| [`frontend/README.md`](frontend/README.md) | Next.js conventions, UI, testing, and feature guides |
| [`design/README.md`](design/README.md) | Design tokens and screen templates, mapped to routes |
| `ai-spike/` | Vision-model benchmark that chose the production model, and its prompt |

## Where to Start

- **Product or business question:** `product.md`, then `business-rules.md`.
- **Backend change:** `backend/README.md`, then the relevant global document.
- **Frontend change:** `frontend/README.md`, then `ui-ux.md` and the feature guide.
- **New feature:** add it to `BACKLOG.md`, then write a SPEC in `specs/` when you are ready to build it.

## Writing Rule

Create a new document only if a SPEC or the regular workflow will read it. Otherwise add a section to an existing document. Do not copy module status into other files; link to `roadmap.md`.

## Core MVP Principles

- AI interprets the image.
- Backend validates financial consistency.
- A human reviews and confirms the record.
- Only confirmed records enter official reports.
- Cash preparation is centralized in `Delivery` and `DeliveryCashDenomination`.
- Excel and Word are outputs, not sources of truth.
- History is preserved in `History`.
