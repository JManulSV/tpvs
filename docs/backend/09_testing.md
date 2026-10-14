# Testing Strategy

## Current Status

The backend has feature coverage for health, authentication/authorization, Companies API behavior, database relationships, and schema constraints. Run the full suite with `php artisan test` (or `docker compose exec -T tpvs-backend php artisan test`). Configure an isolated PostgreSQL test database before running `RefreshDatabase` tests; do not point test runs at a database whose data must be retained.

## Test Types

### Unit Tests

Use for isolated financial calculations, state transition rules, extraction validation, money conversion, and domain validators.

### Feature Tests

Use for routes, request validation, authorization, persistence, state workflows, uploads, deliveries, and reports.

### Integration Tests

Use for PostgreSQL behavior, queues, AI provider integration, private storage, and document generation.

## Required Scenarios

Every feature should cover its normal path, invalid input, unauthorized access, invalid state, persistence behavior, and regression cases.

The initial health test should assert:

```php
$this->getJson('/api/health')
    ->assertOk()
    ->assertJson([
        'status' => 'ok',
        'service' => 'backend',
    ]);
```

The Companies feature tests cover authenticated access for both roles, Editor-only creation, request validation, normalized duplicate rejection, case-insensitive search, pagination, confirmed-record counts, detail `404`, persistence, and association with a Record.
