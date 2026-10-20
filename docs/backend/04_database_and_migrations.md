# Database and Migrations

## Current Foundation

The Laravel migrations provide the framework tables and the TPVS domain schema:

- `users`
- `password_reset_tokens`
- `sessions`
- cache storage
- queue jobs storage
- TPVS entities including `companies` and `records`, their relationships, and domain constraints

The approved TPVS data model is defined in `docs/03_data_model.md`. The Companies module additionally has a reversible migration that replaces exact company-name uniqueness with case-insensitive uniqueness after trimming surrounding whitespace. It checks for existing normalized collisions before creating the PostgreSQL expression index; conflicts must be resolved manually without merging records.

## Migration Rules

- Use a Laravel migration for every schema change.
- Do not edit an already-applied migration in a shared or deployed environment.
- Add a new migration for changes to existing schema.
- Define foreign keys and indexes explicitly.
- Use database constraints for invariants that must hold regardless of application code.
- Keep migrations reversible where practical.
- For this pre-production schema change, base migrations may be updated and local/test databases are rebuilt; do not introduce data-copy migrations or destructive rollback handling for data that is explicitly disposable.
- Updating the existing base migrations is specific to SPEC 07 before production deployment; once deployed, future schema changes use new migrations as usual.

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

Schema changes require tests for foreign keys, uniqueness, nullable behavior, precision, check constraints, and the related workflow behavior. Changes to base migrations require verifying a fresh schema build and the related workflow behavior.
