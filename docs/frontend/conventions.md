# Frontend Conventions

Next.js structure, routing and rendering, the BFF and session contract, data fetching, and forms. UI and accessibility rules are in [`ui-and-accessibility.md`](ui-and-accessibility.md); verification is in [`testing.md`](testing.md). Read `frontend/AGENTS.md` and the installed Next.js docs before changing Next.js APIs — the project pins a specific version.

## 1. Architecture

```text
Browser -> Next.js UI -> same-origin Next.js BFF (/api/...) -> Laravel JSON API
        -> PostgreSQL / private storage / queue / Ollama
```

- **Next.js UI** renders screens and handles interaction.
- **BFF** is the browser-facing HTTP boundary; it forwards or deliberately adapts requests to Laravel.
- **Laravel** owns business rules, persistence, state transitions, authorization, files, jobs, and document generation.

The frontend never becomes a second source of truth for financial calculations, record states, role authorization, or report inclusion.

## 2. Structure

```text
frontend/src/
├── app/
│   ├── (auth)/login/           # Public routes
│   ├── (app)/                  # Authenticated shell: dashboard, records, companies, reports; error.tsx
│   ├── api/[...path]/route.ts  # BFF proxy to Laravel
│   ├── layout.tsx
│   └── globals.css
├── features/<feature>/         # auth, dashboard, companies, records, deliveries, reports
│   ├── components/             # Grouped in subfolders when large (records: list/, detail/, capture/)
│   ├── hooks/  services/  schemas/
│   ├── utils.ts | utils/       # Pure helpers, unit-tested in frontend/tests/ (a folder once there are several)
│   └── types.ts
├── components/                 # Only genuinely shared UI: app-shell/, providers/, ui/ (shadcn), form-field, load-error
├── hooks/                      # Shared hooks (e.g. use-reset-on-hide)
└── lib/                        # api-client, formatting, status, styles, file-download, server/ (server-only helpers)
```

- `app/` holds routes, layouts, and page composition — no business workflows.
- Feature code stays in its feature. Promote to `components/` or `lib/` only when several features share it; shared code never imports feature code.
- Dependency direction: route → feature screen → feature hook → feature service → shared API client → BFF.
- Use domain names (`RecordReviewForm`, `useRecords`, `recordsService`). Model the data the UI needs; do not mirror Laravel models mechanically.
- Keep components small: one exported component per file, pure logic (payload mapping, message building, option lists) in `utils`, and split a file once it mixes several sections or passes roughly 250 lines.
- Comments are written in English and explain *why*, not what the code already says. Do not reference SPEC numbers in code; the SPEC history lives in `specs/`.
- Secrets and backend configuration never go in `NEXT_PUBLIC_*` variables or client bundles.

## 3. Routing and Rendering

- Route groups `(auth)` and `(app)` separate layouts without URL segments; route params follow resource identity (`records/[recordId]`).
- Server Components by default. Add `"use client"` only for state/effects, browser APIs, or client libraries (TanStack Query, React Hook Form), as low in the tree as practical. Props crossing the boundary are public serialized data.
- Do not fetch the same resource in a Server Component and a client hook without a deliberate hydration strategy.
- Hiding a link or disabling an action by role is usability, not authorization — Laravel policies remain authoritative.

**Error boundary** — `app/(app)/error.tsx` covers every authenticated page and renders inside the shell:

- `app/(app)/layout.tsx` catches a failed `getAuthenticatedUser` on full loads («No se pudo validar la sesión con el servidor…»); on client navigation the page's call throws and `error.tsx` shows its card.
- The card is generic («No pudimos cargar la información») because production hides server messages; type and digest are behind «Detalles técnicos». It logs with `console.error`.
- «Reintentar» calls `router.refresh()` then `reset()` (`reset()` alone re-renders the failed payload).
- When `navigator.onLine` is false it shows the connection variant. It does not count attempts, because Next remounts the boundary on each retry.

## 4. BFF and Sessions

`src/app/api/[...path]/route.ts` proxies to Laravel through the server-only helper `src/lib/server/laravel-api.ts`. The Axios client targets `/api`.

- Browser code calls only same-origin `/api/...`.
- `LARAVEL_API_URL` is server-only. `FRONTEND_ORIGIN` is a required server-only, comma-separated allowlist used to validate `Origin`/`Referer` and for SSR requests without an incoming Origin; never derive it from the Host header. `NEXT_ALLOWED_DEV_ORIGINS` (hostnames only) is for LAN development.
- Auth paths: `POST /api/auth/login`, `POST /api/auth/logout`, `GET /api/auth/me`, and the bridge `GET /api/auth/csrf-cookie` → Sanctum `/sanctum/csrf-cookie`. The browser sends the decoded `XSRF-TOKEN` cookie as `X-XSRF-TOKEN`.
- Laravel owns the session (`tpvs_session`, see [`../backend/conventions.md`](../backend/conventions.md#5-security)). The BFF preserves every `Set-Cookie` and forwards `Cookie`, trusted `Origin`/`Referer`, and `X-XSRF-TOKEN`. SSR forwards the incoming cookie through the same helper.
- Preserve upstream statuses: `401`, `403`, `419` (CSRF), `422` (validation), and others.
- Never keep credentials in local storage, URLs, or client-visible variables. No JWT or second session strategy.

When extending the proxy: encode path segments and keep the query string; forward only required methods and headers; use `cache: "no-store"` for authenticated data; handle multipart, binary, ranges, and `Content-Disposition` deliberately; return a stable gateway error without the upstream URL; never let a path or query choose the upstream host; never log cookies or payloads. Covered by `frontend/tests/bff.test.ts`.

## 5. Data Fetching and Forms

- Feature **services** make typed calls through the shared client (`src/lib/api-client.ts`); they own transport, not business rules.
- Feature **hooks** compose TanStack Query queries/mutations and cache invalidation; components own presentation.
- Query keys are stable and include every parameter that changes results (company, period, status, page). Invalidate affected queries after mutations.
- The provider defaults: 30 s `staleTime`, one query retry, no window-focus refetch, no mutation retries. Avoid optimistic updates on financial workflows.
- Clear user-scoped cache on logout or identity change. Cached values are display state, never authority for confirmation, totals, or permissions.
- Filters, search, and pagination are read from the URL on every render (see `frontend/AGENTS.md`, `cacheComponents`).

**Forms:** React Hook Form + Zod schemas colocated with the feature. Keep money decimal-safe; client totals are never authoritative. Map Laravel `422` errors to fields and keep a form-level message for the rest. Consequential transitions (confirm, cancel, prepare delivery) require an explicit user action, and the client never advances a record state on its own.

**Every data-driven screen defines:** pending, empty (and filtered-empty), success, recoverable error with retry, validation/authorization failure, and mutation-pending with duplicate-submit prevention.
