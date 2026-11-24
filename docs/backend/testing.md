# Testing Strategy

## Current Status

The backend has feature coverage for health, authentication/authorization, Companies and Records API behavior, database relationships, schema constraints, and AI extraction/retry behavior. Tests run only against the dedicated `tpvs_test` PostgreSQL database; never point a run at a database whose data must be retained.

Under Docker, run the suite with:

```bash
make test                         # extra arguments: make test ARGS="--filter=Login"
```

`make test` first runs `docker/postgres/init/01-create-test-db.sh` (idempotent) so `tpvs_test` exists, then runs the suite with the database set explicitly. Without `make`, use the equivalent command:

```bash
docker compose exec -T -e DB_DATABASE=tpvs_test tpvs-backend php artisan test
```

The variable is needed because the backend container exports `DB_DATABASE=tpvs`, and the `phpunit.xml` `<env>` entry does not replace it.

### Test Database Guard (SPEC 26)

`Tests\TestCase::createApplication()` builds the application and throws a `RuntimeException` when `config('database.connections.pgsql.database')` is not `tpvs_test`. The check lives in `createApplication()` and not in `setUp()`, because `RefreshDatabase` runs inside `parent::setUp()` and would already have wiped the database. An unscoped `php artisan test` therefore fails every test that boots Laravel (pure PHPUnit unit tests still pass) and leaves `tpvs` untouched. A stale `bootstrap/cache/config.php` triggers the same error; clear it with `php artisan optimize:clear`.

A new PostgreSQL volume gets `tpvs_test` from the init script on its first start. The guard was added after unscoped runs emptied the shared `tpvs` database on 2026-10-29 and 2026-11-09.

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

The Records feature tests cover authenticated reads and Editor-only mutations, search and filters, business week/date ranges, pagination, manual capture with backend-calculated amounts and initial confirmed History, absence of Image/AIProcessing rows, atomic replacement and confirmation, rollback on History persistence failure, and invalid states and inputs.

SPEC 08 tests use HTTP fakes rather than requiring Ollama. They cover provider payload/schema, raw response preservation, sanitized provider failures, extraction contract and normalization, the 4/5 numeric threshold, Company resolution, financial proposals, upload/retry authorization, durable job outcomes, safe resource serialization, idempotency, and confirmation blocking. Tests that exercise migrations, model persistence, queues, or transactions require PostgreSQL; unit-only success does not replace the full backend feature suite.

SPEC 09 tests cover Editor-only correction, confirmation, and cancellation; `MANUAL` and `IMAGE` review states; preservation of Image/AI evidence; required reasons and generic/status History; source-appropriate confirmation reasons; cancellation from `NEEDS_REVIEW` and `ERROR`; rejection for other states; transactional rollback; and image access after cancellation. They use HTTP fakes for AI and do not require a live Ollama host.
