# Laravel Conventions

## General Rules

- Follow PSR-12 and the repository's Laravel Pint configuration.
- Use strict, explicit names for classes, methods, states, and fields.
- Prefer dependency injection over service location in application code.
- Read configuration through `config()`; do not call `env()` outside configuration files.
- Keep changes small and verifiable.

## Layer Responsibilities

| Layer | Rule |
| --- | --- |
| Routes | Declare endpoints and middleware; no business workflows |
| Controllers | Coordinate HTTP input, use cases, and responses |
| Form Requests | Validate payloads and request-level authorization |
| Resources | Define stable JSON response representations |
| Models | Define persistence, casts, relationships, and simple scopes |
| Policies | Enforce resource authorization |
| Actions/services | Coordinate multi-step application use cases |
| Jobs | Run asynchronous or retryable work |
| Migrations | Define all schema changes |

## Avoid

- Business logic in route closures.
- Large controllers with database workflows.
- External API calls from Eloquent models.
- Unvalidated request data.
- Hidden writes during read operations.
- Generic abstractions without a current use case.
- Direct `env()` calls in controllers, models, jobs, or services.

## Transactions

Use a database transaction when one use case changes multiple related records or must write an audit entry with the change. The operation must either complete as a unit or roll back as a unit.
