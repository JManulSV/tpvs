# TPVS

Internal platform that turns supplier images into validated financial records, delivery documents (Word), and reports (Excel). Laravel 12 API (`backend/`), Next.js 16 frontend with a same-origin BFF (`frontend/`), PostgreSQL, and Ollama for image extraction.

## Rules that must not be broken

- **Backend tests run only against `tpvs_test`:**
  `docker compose exec -T -e DB_DATABASE=tpvs_test tpvs-backend php artisan test`
  Without that variable the suite wipes the development `tpvs` database.
- AI never confirms a record. Only a human (`EDITOR`) confirms, and only `CONFIRMED` records enter reports.
- Laravel is the authority for business rules, totals, states, and authorization. The frontend does not recalculate or decide.
- Before changing Next.js code, read `frontend/AGENTS.md`.

## Documentation

- `docs/README.md` — map of all documentation.
- `docs/roadmap.md` — **the only place for module status**. Do not copy status into other documents.
- `docs/BACKLOG.md` — pending items that have no SPEC yet.
- `docs/business-rules.md`, `docs/data-model.md` — business rules and data model.
- `docs/backend/conventions.md`, `docs/frontend/conventions.md` — code conventions per layer.
- `docs/backend/api-contract.md` — HTTP contract.
- `docs/design/README.md` — design templates mapped to screens.

When docs and code disagree, the code wins for implemented behavior.

## Workflow

1. A new idea goes into `docs/BACKLOG.md`.
2. `/spec` turns it into `specs/NN-slug.md` (Draft → the user approves it).
3. `/spec-impl NN-slug` implements it on its own branch.
4. When finished: mark the SPEC `Implemented`, update its row in `docs/roadmap.md`, and update the affected docs (API contract, feature guide).

## Checks

- Backend: the test command above, plus `vendor/bin/pint` on changed files.
- Frontend (in `frontend/`): `npm test`, `npm run lint`, `npx tsc --noEmit`, `npm run build`.
