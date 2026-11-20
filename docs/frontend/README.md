# Frontend Documentation

How the TPVS Next.js 16 / React 19 frontend is built. Module status lives in [`../roadmap.md`](../roadmap.md).

## Authority

- `frontend/` is authoritative for implemented frontend behavior; when documents disagree with it, inspect the code.
- [`../ui-ux.md`](../ui-ux.md) is authoritative for screens and user experience; [`../business-rules.md`](../business-rules.md) for rules and states; [`../backend/api-contract.md`](../backend/api-contract.md) for the API.
- Do not change a product or business rule to make the frontend easier; update the authoritative document first.

## Documents

| Document | Purpose |
| --- | --- |
| [`conventions.md`](conventions.md) | Architecture, structure, routing/rendering, BFF and sessions, data fetching, forms |
| [`ui-and-accessibility.md`](ui-and-accessibility.md) | Design system, shadcn/ui, interaction states, responsiveness, accessibility |
| [`testing.md`](testing.md) | Verification strategy and browser checkpoint evidence |
| `features/auth.md` | Login, logout, session, protected navigation |
| `features/dashboard.md` | Role-aware home screen |
| `features/companies.md` | Company list, search, creation |
| `features/records.md` | Record list, manual/image capture, review, detail, state actions |
| `features/deliveries.md` | Delivery preparation, cash breakdown, Word download |
| `features/reports.md` | Confirmed-record reports, filters, Excel download |

Also read `frontend/AGENTS.md` (Next.js version notes and `cacheComponents` state rules) before changing frontend code.

## Feature Guide Template

Each feature guide covers: user goal and scope; routes and screens; role-visible actions; API endpoints (linked to the contract); components, hooks, services, and schemas; loading, empty, error, success, and blocked states; client validation; acceptance checks. Link to shared rules instead of restating them.
