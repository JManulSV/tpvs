# Frontend Architecture and Structure

## Status

These are conventions for the Next.js frontend as features are implemented. The current repository is still a foundation; only create directories when a real feature needs them.

## Responsibilities

```text
Browser
  -> Next.js UI
  -> same-origin Next.js BFF
  -> Laravel JSON API
  -> PostgreSQL / private storage / queue / AI integrations
```

- **Next.js UI:** renders screens and provides user interaction.
- **Next.js BFF:** provides the browser-facing HTTP boundary and forwards or deliberately adapts requests to Laravel.
- **Laravel:** owns business rules, persistence, state transitions, authorization, file access, jobs, and document generation.
- **PostgreSQL and private storage:** hold the authoritative application data and private files through backend-managed access.

The frontend must not implement a second source of truth for financial calculations, record states, role authorization, or report inclusion.

## Recommended Structure

Grow into this structure as modules are implemented; avoid creating empty feature directories up front.

```text
frontend/src/
├── app/
│   ├── (auth)/                 # Public/authentication routes when needed
│   ├── (app)/                  # Authenticated application shell and screens
│   ├── api/                    # Browser-facing Route Handlers / BFF
│   ├── layout.tsx
│   └── globals.css
├── features/
│   ├── auth/
│   ├── dashboard/
│   ├── companies/
│   ├── records/
│   ├── deliveries/
│   └── reports/
├── components/
│   ├── layout/                 # Shared navigation and application shell
│   ├── providers/              # React context providers
│   └── ui/                     # Reusable design-system primitives
├── lib/
│   ├── api-client.ts          # Existing shared HTTP client; expand as needed
│   └── utils.ts
└── types/                      # Only genuinely cross-feature types
```

Example routes under the authenticated route group:

```text
app/(app)/page.tsx
app/(app)/records/page.tsx
app/(app)/records/new/page.tsx
app/(app)/records/[recordId]/page.tsx
app/(app)/companies/page.tsx
app/(app)/reports/page.tsx
```

Route groups organize layouts without changing the URL. Keep URL paths aligned to the product navigation in [`../10_ui_ux.md`](../10_ui_ux.md).

## Feature Ownership

A feature may contain only the layers it actually needs:

```text
features/records/
├── components/
├── hooks/
├── schemas/
├── services/
├── types.ts
└── index.ts                   # Optional public exports
```

- `components/`: UI specific to that feature.
- `hooks/`: client-side composition and TanStack Query hooks.
- `services/`: typed calls to the same-origin API/BFF.
- `schemas/`: Zod schemas for frontend form/input feedback.
- `types.ts`: feature-local API and UI types where useful.
- `index.ts`: use only if a stable feature public API improves imports.

Put components in `src/components/` only when they are genuinely shared across features. Keep API response types close to their owning service/feature unless several modules share the same stable contract. Do not mirror Laravel models mechanically in frontend types; model the data the UI needs.

## Dependency Direction

```text
app routes and layouts
  -> feature components / screens
      -> feature hooks
          -> feature services
              -> shared API client
                  -> Next.js BFF
```

Shared UI primitives and utilities should not import feature code. Feature code should not reach into another feature's private implementation; promote a truly shared contract to a documented shared boundary instead.

## Naming and General Rules

- Use descriptive, domain-oriented names: `RecordReviewForm`, `useRecords`, `recordsService`.
- Keep modules cohesive and small; avoid catch-all `services/`, `helpers/`, or `types/` that accumulate unrelated logic.
- Do not put multi-step business workflows in pages or React components.
- Keep secrets and backend-only configuration out of `NEXT_PUBLIC_*` variables and client bundles.
- Follow the repository TypeScript strictness and existing formatting/lint conventions.
- Read `frontend/AGENTS.md` and the installed Next.js documentation before changing Next.js APIs or conventions; the project pins a specific Next.js version.

## Related Documents

- `02_routing_and_rendering.md`
- `03_api_bff_and_sessions.md`
- `04_data_fetching_and_forms.md`
- [`../04_technical_architecture.md`](../04_technical_architecture.md)
- [`../11_technical_roadmap.md`](../11_technical_roadmap.md)
