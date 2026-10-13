# TPVS Frontend

Next.js 16 and React 19 application for the TPVS internal operations platform. The frontend provides the role-aware UI and same-origin BFF boundary; Laravel owns persistence, business rules, and authorization.

## Current Status

- Foundation includes the App Router, TypeScript, Tailwind CSS, shared UI primitives, Axios, TanStack Query, React Hook Form, and Zod.
- `src/app/api/[...path]/route.ts` proxies browser API requests to Laravel using server-only `LARAVEL_API_URL`.
- Business screens and resource API workflows are planned. Authentication's approved contract is Laravel Sanctum stateful session via the BFF, but its UI, CSRF bridge, and verified cookie forwarding remain unimplemented; see the frontend documentation for details.

## Development

Install dependencies and start the development server:

```bash
npm ci
npm run dev
```

Open `http://localhost:3000`. Browser API requests use same-origin `/api/*`; set `LARAVEL_API_URL` for the server-side BFF when running outside Docker. In Compose it is configured as `http://tpvs-backend:8000`.

## Checks

```bash
npm run lint
npx tsc --noEmit
npm run build
```

## Project Conventions

- Start with [`../docs/frontend/README.md`](../docs/frontend/README.md) for the architecture, current status, feature guides, and implementation rules.
- Use [`../docs/10_ui_ux.md`](../docs/10_ui_ux.md) for approved screen flows and [`../docs/11_technical_roadmap.md`](../docs/11_technical_roadmap.md) for implementation order.
- Read `AGENTS.md` and the installed Next.js documentation under `node_modules/next/dist/docs/` before changing Next.js APIs; this repository pins Next.js 16.4.0.
