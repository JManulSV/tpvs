<!-- BEGIN:nextjs-agent-rules -->

## This is NOT the Next.js you know

This version has breaking changes — APIs, conventions, and file structure may all differ from your training data. Read the relevant guide in `node_modules/next/dist/docs/` (resolved from this file's directory; in monorepos the `next` package may not be visible from the repo root) before writing any code. Heed deprecation notices.

This block is written and re-added by `next dev` — verify at `node_modules/next/dist/server/lib/generate-agent-files.js`. Removing it from a diff only re-creates the uncommitted change; committing it with your work keeps the tree clean.

<!-- END:nextjs-agent-rules -->

## Estado de pantallas con `cacheComponents`

`cacheComponents: true` hace que Next no desmonte las rutas al navegar: las oculta con React `<Activity>` y conserva su estado (`useState`, formularios, DOM). Al volver, la pantalla aparece tal como se dejó. Ver `node_modules/next/dist/docs/01-app/02-guides/preserving-ui-state.md`.

- **Filtros, búsqueda y paginación se leen de la URL** en cada render (`useSearchParams`), nunca se copian a `useState(() => …searchParams)`: el inicializador corre solo al montar y la copia queda vieja. Para cambiarlos, `window.history.replaceState` con la URL nueva. Ejemplo: `features/records/record-filters.ts` y `records-screen.tsx`.
- **El estado de una sola visita se reinicia con `useResetOnHide`** (`src/hooks/use-reset-on-hide.ts`): diálogos abiertos, mensajes de éxito o error, flujos de alta ya terminados, credenciales. Los borradores a medio escribir se conservan.
- **Cerrar sesión hace recarga completa** (`window.location.replace("/login")`), no `router.replace`, para no dejar estado del usuario anterior en las rutas ocultas.
