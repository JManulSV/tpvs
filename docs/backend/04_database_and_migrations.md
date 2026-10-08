# Database and Migrations

## Current Foundation

The current Laravel migrations provide:

- `users`
- `password_reset_tokens`
- `sessions`
- cache storage
- queue jobs storage

The TPVS domain schema is defined in `docs/03_data_model.md` and is not implemented yet.

## Migration Rules

- Use a Laravel migration for every schema change.
- Do not edit an already-applied migration in a shared or deployed environment.
- Add a new migration for changes to existing schema.
- Define foreign keys and indexes explicitly.
- Use database constraints for invariants that must hold regardless of application code.
- Keep migrations reversible where practical.

## Persistence Rules

- PostgreSQL is the source of truth.
- Use decimal database types for money; never use floating-point money arithmetic.
- Use Eloquent relationships for aggregate navigation.
- Use Query Builder for complex reporting queries when it improves clarity or performance.
- Use raw SQL only when necessary and document why.
- Do not use Excel as an input source or official total source.
- Do not persist calculated totals as authoritative values.

## Transactions

Use transactions for record creation with details, state transitions with history, delivery updates with denominations, and any operation that changes multiple related records.

## Verification

Schema changes require tests for foreign keys, uniqueness, nullable behavior, precision, check constraints, and the related workflow behavior.
