# Backend Documentation

How the TPVS Laravel backend is built and operated. Product scope, business rules, and the data model live in the global documents under `docs/`; module status lives in [`../roadmap.md`](../roadmap.md).

## Authority Rules

- Code under `backend/` is authoritative for implemented behavior; verify routes and tests instead of assuming a documented endpoint exists.
- A feature is implemented only when it exists in code and has passing tests.
- Do not change a business rule here without updating [`../business-rules.md`](../business-rules.md).

## Documents

| Document | Purpose |
| --- | --- |
| [`conventions.md`](conventions.md) | Structure, Laravel conventions, database, queues/AI, security, rules for AI assistants |
| [`api-contract.md`](api-contract.md) | HTTP routes, responses, errors, and API rules |
| [`validation-authorization.md`](validation-authorization.md) | Request validation, business validation, and policies |
| [`domain-workflows.md`](domain-workflows.md) | Backend workflows and their boundaries |
| [`files-and-documents.md`](files-and-documents.md) | Private files and generated documents |
| [`testing.md`](testing.md) | Test strategy and required scenarios (always against `tpvs_test`) |
| [`configuration-operations.md`](configuration-operations.md) | Setup, environment, health, logs, and operations |

## Reading Order

1. [`conventions.md`](conventions.md) for code placement and baseline rules.
2. The guide for the change you are making.
3. The relevant global business, data, or AI document.
