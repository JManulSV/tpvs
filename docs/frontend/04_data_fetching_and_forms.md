# Data Fetching and Forms

## Status

The frontend foundation includes Axios, TanStack Query, React Hook Form, and Zod. Business services, hooks, and forms are planned until implemented.

## Services and Hooks

Use feature-owned services to describe API operations and feature hooks to connect those operations to React and TanStack Query:

```text
features/companies/services/companies.service.ts
features/companies/hooks/use-companies.ts
features/records/services/records.service.ts
features/records/hooks/use-records.ts
```

- Services call relative same-origin API paths through the shared client.
- Services should be small, typed, and focused on transport; they do not own business rules.
- Hooks own query/mutation composition and cache coordination, not raw UI rendering.
- Components own presentation and user interaction, not duplicated request construction.
- Keep DTOs aligned to the actual API contract; do not claim types for planned responses are implemented contracts.

## Axios and Error Handling

Use the shared Axios client in `src/lib/api-client.ts` (or its future location documented by the architecture guide). Keep common response/error handling centralized, but do not swallow error details that forms need to display validation feedback.

Represent transport, validation, authorization, and domain failures distinctly where the API contract provides enough information. Display concise, actionable user messages; do not expose stack traces or backend internals.

## TanStack Query

- Use stable query keys organized by resource and relevant filters.
- Centralize or colocate query-key conventions so list/detail/report invalidation is predictable.
- Include parameters that change results, such as company, period, status, and pagination, in the key.
- Invalidate or update affected queries after successful mutations.
- Avoid optimistic updates for consequential financial workflows unless rollback and server reconciliation are explicit and tested.
- Do not automatically retry mutations that may create or alter records; the existing Query Provider already disables mutation retries.
- Clear user-scoped cached data on logout or identity changes.
- Treat cached values as display state, never as authority for confirmation, totals, or permissions.

The current provider sets a 30-second default query `staleTime`, one query retry, and no window-focus refetch. Tune per query only when the feature's freshness and operational needs justify it.

## Forms and Validation

- Use React Hook Form for interactive forms and Zod schemas for client-side input shape and immediate feedback where appropriate.
- Keep schemas near the feature that owns the form.
- Keep financial values in a decimal-safe representation suitable for the API; do not use floating-point UI calculations as authoritative results.
- Use the same units and formatting expectations as the API contract.
- Map Laravel validation errors to fields when possible and retain a form-level message for non-field errors.
- Revalidate on the backend after submit; successful client validation does not imply that Laravel will accept the operation.
- Require an explicit user action for consequential transitions such as confirm, cancel, and delivery preparation.

## UX States

Every data-driven screen should define:

- initial/pending state;
- empty state, including filtered-empty results where relevant;
- successful state;
- recoverable request error and retry behavior;
- validation and authorization failures;
- mutation pending state and duplicate-submit prevention.

For record workflows, display the backend state and validation outcome returned by Laravel. Do not independently advance a record state in the client.

## Related Documents

- `03_api_bff_and_sessions.md`
- `05_ui_and_accessibility.md`
- `06_testing.md`
- [`../02_business_rules_and_states.md`](../02_business_rules_and_states.md)
- [`../backend/03_api_contract.md`](../backend/03_api_contract.md)
- [`../backend/05_validation_authorization.md`](../backend/05_validation_authorization.md)
