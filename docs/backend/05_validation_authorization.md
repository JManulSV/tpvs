# Validation and Authorization

## Three Validation Boundaries

```text
HTTP validation
  -> Is the request shape and input acceptable?

Business validation
  -> Does the data obey TPVS rules?

Authorization
  -> Is this user allowed to perform this action?
```

Do not replace one boundary with another.

## HTTP Validation

Use Laravel Form Requests for endpoint payloads. Validate types, required fields, formats, limits, file properties, and relationships before calling an application workflow.

## Business Validation

Business validators must enforce the rules in `docs/02_business_rules_and_states.md`, including financial consistency, state transitions, confirmed-only reporting, delivery cash reconciliation, and human confirmation.

Backend calculations are authoritative. AI values are input for review, never official financial truth.

## Authentication and Authorization Status

Authentication is implemented with Laravel Sanctum stateful SPA authentication backed by the Laravel database session. Authenticated users may read permitted resources; only `EDITOR` may mutate. The `auth:sanctum` and `editor` middleware are available for route groups.

| Action | Editor | Viewer |
| --- | --- | --- |
| View records and reports | Yes | Yes |
| Create or upload | Yes | No |
| Edit, confirm, or cancel | Yes | No |
| Prepare delivery | Yes | No |
| Generate reports | Yes | Yes |

Implement resource-specific permissions with Laravel Policies as each resource is introduced. Feature tests currently verify the central allowed and forbidden role behavior.

Protect state-changing requests with Laravel CSRF validation. The Next.js BFF is the browser-facing same-origin boundary and must forward the session cookie and `X-XSRF-TOKEN`; do not exempt API mutations from CSRF to simplify the proxy.
