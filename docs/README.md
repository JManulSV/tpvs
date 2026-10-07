# Documentation Set

This repository contains the MVP documentation for the automation platform in English, organized by role so AI tools and developers can work from the right level of detail.

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

## Intended Roles

- Product and operations stakeholders should start with `01_product_prd.md`.
- Backend and architecture work should start with `02_business_rules_and_states.md`, `03_data_model.md`, and `04_technical_architecture.md`.
- Cursor, Copilot, Claude Engineer, and similar tools should use `05_ai_development_blueprint.md`.
- Implementation sequencing and checkpoints are defined in `11_technical_roadmap.md`.

## Core MVP Principles

- AI interprets the image.
- Backend validates financial consistency.
- A human reviews and confirms the record.
- Only confirmed records enter official reports.
- Cash preparation is centralized in `Delivery` and `DeliveryCashDenomination`.
- Excel and Word are outputs, not sources of truth.
- History is preserved in `History`.
