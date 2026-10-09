# Routing and Rendering

## Status

The current frontend uses the Next.js App Router. The conventions here guide future screens; routes beyond the foundation and health proxy are planned.

## Route Ownership

- Use `src/app/` for URL structure, layouts, loading/error boundaries, and page composition.
- Use route groups such as `(auth)` and `(app)` to separate layouts without adding URL segments.
- Keep route parameters aligned to resource identity, for example `records/[recordId]`.
- Keep feature workflows and reusable screen components in `src/features/<feature>/`.
- Keep `src/app/api/` for browser-facing Route Handlers and the BFF, not for a parallel business API.
- Use `not-found.tsx`, `loading.tsx`, and `error.tsx` where a route benefits from explicit navigation or failure behavior.

## Server and Client Components

Use Server Components by default for static composition, initial server-side data needs, and layouts that do not require browser state. Add the `"use client"` boundary only to components that need React state/effects, browser APIs, event-driven interaction, or client-side libraries such as TanStack Query and React Hook Form.

Keep Client Component boundaries as low in the tree as practical. A page can compose server-rendered content around focused interactive pieces such as a filter bar, form, or table controls.

Do not pass secrets or privileged backend configuration into Client Components. Treat props crossing a server/client boundary as serialized public data.

## Data Loading Choice

- Use server-side fetching when it simplifies initial rendering and works with the chosen session/BFF contract.
- Use TanStack Query for client-owned server state that needs refetching, mutation coordination, cache invalidation, or interactive refresh.
- Do not fetch the same resource independently in a Server Component and a client hook without a deliberate hydration/cache strategy.
- Keep loading, empty, and error experiences explicit regardless of where the request runs.

The authentication/session guide in `03_api_bff_and_sessions.md` must be followed when choosing how a server-rendered request reaches Laravel. Do not read or forward browser credentials through undocumented shortcuts.

## Route Handlers

The current `src/app/api/[...path]/route.ts` is a generic proxy to Laravel. Preserve pass-through behavior for normal API traffic. Add a specific Route Handler only when Next.js needs to own a browser-facing concern, such as response adaptation, a composed operation, or a dedicated health response.

Route Handlers must:

- validate path segments and any data they transform;
- explicitly forward only required request and response headers;
- preserve upstream status and relevant content headers;
- handle JSON, multipart, range, and binary responses deliberately when those formats are involved;
- return stable error responses without leaking internal service URLs, stack traces, or secrets;
- avoid business decisions that belong to Laravel.

## Navigation and Access

Protect authenticated navigation in the server/BFF boundary once authentication exists. Hiding a link or disabling an action based on role improves usability but is not authorization; Laravel Policies and endpoint checks remain authoritative. See the auth feature guide and backend security/API documentation.

## Related Documents

- `01_architecture_and_structure.md`
- `03_api_bff_and_sessions.md`
- [`../10_ui_ux.md`](../10_ui_ux.md)
- [`../backend/03_api_contract.md`](../backend/03_api_contract.md)
- [`../backend/11_security.md`](../backend/11_security.md)
