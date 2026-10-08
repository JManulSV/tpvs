# Testing Strategy

## Current Status

The backend contains Laravel example tests only. A dedicated `/api/health` feature test should be added before the health endpoint is treated as verified behavior.

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
