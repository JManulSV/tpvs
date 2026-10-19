# Documentation Set

This repository contains the MVP documentation for the automation platform in English, organized by role so AI tools and developers can work from the right level of detail.

The documentation describes both the current repository implementation and the target MVP. These are not the same thing. Documents must mark planned modules and endpoints as planned until they exist in the codebase.

## Documents

- `docs/01_product_prd.md` - Product requirements, user goals, and functional scope.
- `docs/02_business_rules_and_states.md` - Business rules, validations, and state machine logic.
- `docs/03_data_model.md` - Complete MVP data model and relationships.
- `docs/04_technical_architecture.md` - Architecture and implementation recommendations.
- `docs/05_ai_development_blueprint.md` - AI-assisted implementation roadmap and coding prompts.
- `docs/06_ai_design.md` - AI vision extraction contract, validation layers, and retry behavior.
- `docs/07_ai_design_overview.md` - Short implementation summary for the AI design phase.
- `docs/08_document_generation.md` - On-demand Excel and Word document generation rules.
- `docs/09_manual_capture.md` - Manual record capture rules and hybrid workflow.
- `docs/10_ui_ux.md` - UI/UX phase 7 screens, flows, and role-based behavior.
- `docs/11_technical_roadmap.md` - Phase 8 implementation sequence, checkpoints, and module acceptance criteria.
- `docs/ai-spike/benchmark-v1.md` - Initial Ollama vision-model spike results; v2 runner is `tools/ai-spike/run_ollama_spike.py`.
- `docs/frontend/README.md` - Next.js frontend status, architecture, conventions, and feature guides.
- `docs/backend/README.md` - Laravel backend status, structure, conventions, API, operations, security, and AI development rules.

## Implementation Status

The current backend is a Laravel 12 foundation with authentication and the Companies catalog implemented; it is not the complete MVP backend.

Implemented now:

- Laravel 12 application bootstrap with PHP 8.3 support.
- PostgreSQL configuration and initial Laravel migrations.
- `GET /api/health` application endpoint.
- `GET /up` Laravel framework health endpoint.
- Laravel Sanctum authentication and role authorization.
- Companies list, detail, and create API with normalized unique names.
- Basic Laravel test scaffolding and Docker runtime.

Planned and not yet implemented:

- Record, image, AI processing, history, delivery, and report modules.
- The business API described in `04_technical_architecture.md`.
- Queue-based AI processing, document generation, and production operations.

When documentation conflicts with the code, current behavior in the repository is authoritative for implemented features. Product, business, and target architecture documents remain authoritative for planned behavior.

Laravel Sanctum stateful authentication, Next.js BFF session integration, and the phase 8.5 Companies workflow are implemented. Browser verification for authentication and Companies is documented in the respective frontend testing guide.

## Intended Roles

- Product and operations stakeholders should start with `01_product_prd.md`.
- Backend and architecture work should start with `02_business_rules_and_states.md`, `03_data_model.md`, and `04_technical_architecture.md`.
- Cursor, Copilot, Claude Engineer, and similar tools should use `05_ai_development_blueprint.md`.
- Implementation sequencing and checkpoints are defined in `11_technical_roadmap.md`.
- Next.js developers and AI coding assistants should start with `frontend/README.md` after reading this document.
- Laravel developers and AI coding assistants should start with `backend/README.md` after reading this document.

## Core MVP Principles

- AI interprets the image.
- Backend validates financial consistency.
- A human reviews and confirms the record.
- Only confirmed records enter official reports.
- Cash preparation is centralized in `Delivery` and `DeliveryCashDenomination`.
- Excel and Word are outputs, not sources of truth.
- History is preserved in `History`.
