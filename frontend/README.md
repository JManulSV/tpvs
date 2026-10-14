# TPVS Frontend

Next.js 16 and React 19 application for the TPVS internal operations platform. The frontend provides the role-aware UI and same-origin BFF boundary; Laravel owns persistence, business rules, and authorization.

## Current Status

- Foundation includes the App Router, TypeScript, Tailwind CSS, shared UI primitives, Axios, TanStack Query, React Hook Form, and Zod.
- `src/app/api/[...path]/route.ts` proxies browser API requests to Laravel through the server-only `src/lib/server/laravel-api.ts` helper and `LARAVEL_API_URL`.
- Laravel Sanctum authentication is integrated with the BFF: CSRF bridge, cookie/header forwarding, login/logout, protected `/dashboard`, and session-aware redirects are implemented. BFF tests are available with `npm test`; the browser acceptance flow is documented in `../docs/frontend/06_testing.md`.
- Business screens and resource API workflows remain planned.

## Development

Install dependencies and start the development server:

```bash
npm ci
npm run dev
```

Open `http://localhost:3000`. Browser API requests use same-origin `/api/*`; set server-only `LARAVEL_API_URL` and `FRONTEND_ORIGIN` when running outside Docker. For LAN development, configure `FRONTEND_ORIGIN` (comma-separated origins), `SANCTUM_STATEFUL_DOMAINS` (host:port entries), and `NEXT_ALLOWED_DEV_ORIGINS` (hostnames). Compose defaults are documented in the root `.env.example`; replace the example LAN IP with the frontend host's address as needed.

## Checks

```bash
npm run lint
npm test
npx tsc --noEmit
npm run build
```

## Project Conventions

- Start with [`../docs/frontend/README.md`](../docs/frontend/README.md) for the architecture, current status, feature guides, and implementation rules.
- Use [`../docs/10_ui_ux.md`](../docs/10_ui_ux.md) for approved screen flows and [`../docs/11_technical_roadmap.md`](../docs/11_technical_roadmap.md) for implementation order.
- Read `AGENTS.md` and the installed Next.js documentation under `node_modules/next/dist/docs/` before changing Next.js APIs; this repository pins Next.js 16.4.0.
