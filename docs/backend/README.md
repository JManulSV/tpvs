# Backend Documentation

This directory documents how the TPVS backend is implemented and operated with Laravel. Product scope, business rules, and the target data model remain in the global documents under `docs/`.

## Current Status

Status: Laravel foundation, authentication, Companies, manual Records, private Record images, and SPEC 08 backend implementation are present and verified against PostgreSQL. History, deliveries, reports, and frontend AI review remain planned.

Implemented:

- Laravel 12 application bootstrap.
- PHP 8.3 runtime and PostgreSQL configuration.
- Initial Laravel migrations for users, sessions, cache, and jobs.
- `GET /api/health` application endpoint.
- `GET /up` Laravel framework health endpoint.
- Laravel Sanctum stateful session authentication endpoints and central `EDITOR` mutation middleware.
- Company list, detail, and create endpoints with resource policy, request validation, normalized name uniqueness, and feature tests.
- Manual Records list, create, detail, replacement, confirmation, validation, authorization, and feature tests.
- Private Record image upload, safe replacement, authorized viewing, metadata serialization, and feature/unit tests (phase 8.7).
- Asynchronous IMAGE Record creation, Ollama provider, extraction validation/persistence, bounded retries, safe AI resource, and confirmation gate (SPEC 08).
- Basic Laravel test scaffolding.
- Docker development runtime.

Not implemented:

- History, deliveries, and reports.
- Business API endpoints beyond health, authentication, Companies, manual Records, Record images, and AI extraction/retry.
- Frontend AI upload/review UI, document generation, and production AI-host operations.

## Authority Rules

- Code under `backend/` is authoritative for implemented behavior.
- Global documents describe product requirements, business rules, and planned MVP behavior.
- A feature is implemented only when it exists in code and has passing tests.
- A documented endpoint must not be assumed to exist; verify routes and tests.
- Do not change business rules in this directory without updating the corresponding global document.

## Reading Order

1. `docs/README.md` for repository-wide status and principles.
2. `docs/04_technical_architecture.md` for target architecture.
3. This file for backend status and rules.
4. `01_application_structure.md` for code placement.
5. The guide for the specific change being implemented.
6. The relevant global business, data, or AI document.

## Documents

| Document | Purpose |
| --- | --- |
| `01_application_structure.md` | Laravel directories and code ownership |
| `02_laravel_conventions.md` | PHP and Laravel implementation conventions |
| `03_api_contract.md` | HTTP routes, responses, errors, and API rules |
| `04_database_and_migrations.md` | PostgreSQL, migrations, Eloquent, and transactions |
| `05_validation_authorization.md` | Request validation, business validation, and policies |
| `06_domain_workflows.md` | Target backend workflows and their boundaries |
| `07_queues_jobs_and_integrations.md` | Queues, jobs, AI, retries, and integrations |
| `08_files_and_documents.md` | Private files and generated documents |
| `09_testing.md` | Laravel test strategy and required scenarios |
| `10_configuration_operations.md` | Setup, environment, health, logs, and operations |
| `11_security.md` | Backend security baseline |
| `12_ai_development_rules.md` | Rules and task format for AI coding assistants |

## Change Rule

Every backend change should identify its status, module, affected files, database impact, authorization requirements, failure cases, and tests before implementation.
