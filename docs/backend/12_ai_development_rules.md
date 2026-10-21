# AI Development Rules

## Required Reading

AI coding assistants must read, in order:

1. `docs/README.md`.
2. `docs/backend/README.md`.
3. The relevant global business, data, architecture, or AI document.
4. The relevant backend guide.
5. The actual files under `backend/` before editing.

## Required Task Context

Every backend implementation task should define:

```text
Status:
Module:
Goal:
Relevant global documents:
Files to inspect:
Files allowed to change:
Business rules:
Database impact:
Authorization:
Failure cases:
Required tests:
Out of scope:
```

## Rules

- Verify implementation status in code; never infer it from documentation alone.
- Do not invent routes, models, migrations, or dependencies.
- Keep routes declarative and controllers thin.
- Use Laravel validation, policies, transactions, jobs, and tests where appropriate.
- Preserve existing user changes and unrelated worktree changes.
- Add or update tests with behavior changes.
- Keep planned behavior separate from implemented behavior.
- Never allow AI extraction to confirm a financial record.
- For SPEC 08, retain one reusable AIProcessing row per Image; expose normalized extraction/issues only, and keep raw provider response backend-private.
- Keep automatic provider retries bounded to three total calls for transient failures in the initial cycle; manual Editor retry is one call per dispatch.
- Update documentation status only after code and tests are verified.
