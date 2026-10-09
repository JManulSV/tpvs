# Frontend Documentation

## Status

The frontend is a Next.js 16 and React 19 foundation. It currently includes the App Router shell, a Laravel health proxy, a shared Axios client, TanStack Query provider, and UI primitives. The business screens and authenticated workflows described here are planned until implemented and verified in `frontend/`.

This guide documents frontend responsibilities and implementation conventions. It does not replace the product requirements, business rules, backend API contract, or current code.

## Authority and Scope

- `frontend/` is authoritative for behavior that is implemented in the frontend.
- `docs/10_ui_ux.md` is authoritative for the approved MVP screens and user experience.
- `docs/02_business_rules_and_states.md` is authoritative for business rules and record states.
- `docs/03_data_model.md` is authoritative for the target data model.
- `docs/04_technical_architecture.md` and `docs/backend/` define the target system and Laravel API boundaries.
- `docs/11_technical_roadmap.md` defines implementation order and checkpoints.
- A planned route, API endpoint, screen, or feature must not be described as implemented until it exists in code and its relevant checks pass.

When documents disagree about implemented behavior, inspect the code. Do not change a product or business rule solely to make frontend behavior easier; update the authoritative product/backend document through the project decision process first.

## Reading Order

1. Read this file for status, authority, and the documentation map.
2. Read [`../10_ui_ux.md`](../10_ui_ux.md) for approved screens and user flows.
3. Read [`../02_business_rules_and_states.md`](../02_business_rules_and_states.md) and [`../03_data_model.md`](../03_data_model.md) for business constraints and domain data.
4. Read [`../04_technical_architecture.md`](../04_technical_architecture.md) and the relevant [`../backend/README.md`](../backend/README.md) API guide for service boundaries and endpoint status.
5. Read the applicable shared frontend guide and feature guide before implementation.
6. Check [`../11_technical_roadmap.md`](../11_technical_roadmap.md) for sequencing and acceptance checkpoints.

## Documents

### Shared conventions

| Document | Purpose |
| --- | --- |
| `01_architecture_and_structure.md` | Frontend boundaries, feature organization, and code ownership |
| `02_routing_and_rendering.md` | App Router, layouts, Server/Client Components, and Route Handlers |
| `03_api_bff_and_sessions.md` | Browser-to-BFF-to-Laravel requests, proxy behavior, and session/security requirements |
| `04_data_fetching_and_forms.md` | Axios, TanStack Query, services, cache keys, Zod, and React Hook Form |
| `05_ui_and_accessibility.md` | UI composition, shadcn/ui, interaction states, responsiveness, and accessibility |
| `06_testing.md` | Frontend verification strategy and feature acceptance checks |

### Feature guides

| Document | Purpose | Target status |
| --- | --- | --- |
| `features/auth.md` | Login, logout, session state, and protected navigation | Planned |
| `features/dashboard.md` | Role-aware operational home screen | Planned |
| `features/companies.md` | Company listing, search, and creation | Planned |
| `features/records.md` | Record list, manual/image capture, review, and state actions | Planned |
| `features/deliveries.md` | Delivery preparation, cash breakdown, and Word download | Planned |
| `features/reports.md` | Confirmed-record summaries, filters, and Excel download | Planned |

## Implementation Principles

- Keep Laravel as the source of truth for persistence, business validation, state transitions, and authorization.
- Keep browser requests on the same-origin Next.js BFF; do not expose Laravel credentials, private storage, or Ollama to browser code.
- Organize feature-specific UI, hooks, services, schemas, and types together when a feature is implemented.
- Keep `app/` focused on routes, layouts, and page composition; do not place business workflows in route files.
- Prefer Server Components by default and add Client Components only for browser interactivity or client-side libraries.
- Use frontend validation to improve feedback, while treating Laravel validation and authorization as authoritative.
- Do not add dependencies, generic abstractions, or speculative folders without a concrete feature need.
- Update feature status and acceptance evidence when implementation changes.

## Feature Guide Template

Each feature guide should identify:

1. Status: planned, partially implemented, or implemented, with evidence where applicable.
2. User goal and scope.
3. Routes and screens.
4. Role-visible actions and the backend authorization boundary.
5. API endpoints and links to their authoritative contract.
6. Feature components, hooks, services, schemas, and types as they are implemented.
7. Loading, empty, error, success, and blocked states.
8. Client-side validation and user-facing error behavior.
9. Acceptance criteria and appropriate tests/checks.
10. Related product, UX, business, data, and backend documentation.

Feature documents should link to shared rules rather than restating them. If a feature reveals an unresolved product or API decision, record the open question and resolve it in the authoritative document before relying on an assumption.
