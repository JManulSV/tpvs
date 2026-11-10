# Dashboard Feature

## Status

**Implemented (SPEC 18, 2026-11-10).** `/dashboard` replaces the foundation placeholder with the role-aware work center. It reads `GET /api/dashboard` through the BFF. The Dashboard shows no charts. Polling is not used: the data refreshes on mount, on focus after the stale time, and when a record mutation invalidates the record list.

## Goal and Scope

Provide a role-aware operational starting point. The dashboard is a work center, not an analytics dashboard. Its approved content is in [`../../10_ui_ux.md`](../../10_ui_ux.md) and the implementation contract is [`../../../specs/18-role-aware-dashboard.md`](../../../specs/18-role-aware-dashboard.md).

- `EDITOR`: hero with the number of records to review and a link to them, a six-cell status strip, and recent activity. The status strip covers records needing review, errors, pending, processing, and this month's confirmed and canceled counts.
- `VIEWER`: hero with this month's accumulated return and confirmed count, and recent activity. There is no status strip and no mutation action.
- Both roles see the same recent activity: the five most recently updated records.

The shell (sidebar, topbar, bottom navigation, and the mobile «Nuevo registro» button) comes from SPEC 16 and is not redefined by the Dashboard. The page renders a single `<h1>` «Dashboard».

## Route and Composition

- Route: `/dashboard`. The root `/` redirects authenticated users here.
- Server page: `frontend/src/app/(app)/dashboard/page.tsx`. It reads the user through `getAuthenticatedUser()`, which is wrapped in React `cache()` so the layout and the page share one `/api/auth/me` call per request. It computes the greeting on the server in `America/Mexico_City` and passes it to the client screen as text.
- Feature: `frontend/src/features/dashboard/`.
  - `components/dashboard-screen.tsx`: query state and role composition.
  - `components/dashboard-hero.tsx`, `components/status-strip.tsx`, `components/recent-activity.tsx`.
  - `hooks/use-dashboard.ts`, `services/dashboard.service.ts`, `types.ts`, `utils.ts`.
- Shared time helpers: `frontend/src/lib/business-time.ts` (`BUSINESS_TIME_ZONE`, which must match `config('tpvs.business_timezone')` in Laravel).
- Navigation from the Dashboard uses URL filters on `/records`. See [`records.md`](records.md#initial-filters-from-the-url).

## Data and Permissions

- Single request: `GET /api/dashboard`. Laravel returns the official counts, the month return, and the recent activity filtered by role. The browser does not sum records or compute official totals.
- Period: the current month in `America/Mexico_City`, using the same rule as Reports. See [`../../backend/03_api_contract.md`](../../backend/03_api_contract.md#dashboard-contract--spec-18-implemented).
- Query key: `[...recordKeys.lists(), "dashboard"]`. Create, update, confirm, cancel, retry, and image-upload mutations invalidate it through the existing record-list invalidation.
- Refresh: `refetchOnWindowFocus: true` applies only to this query. The global `QueryClient` default stays `false`. Because the global `staleTime` is 30 s, returning to the tab fetches only when the data is older than that.
- Relative times («hace 12 min», «ayer») are computed in the client and use the business calendar day.

## Interface States

- **Loading:** the hero keeps the real greeting and shows skeletons for numbers, the status strip shows skeleton cells without links, and the activity shows skeleton rows. `aria-busy` is set on each section. No `0` or `$0.00` appears while loading.
- **Error without data:** a single alert with `role="alert"`: «No se pudo cargar el resumen del Dashboard.» and a «Reintentar» button. No zero counts are shown. Status strip and hero numbers are not rendered.
- **Error with cached data:** the last values stay visible.
- **Empty activity:** «Todavía no hay actividad.» Counts that are zero are shown as zero because the request succeeded.
- **Editor with nothing to review:** the hero says «No tienes registros por revisar» and «Ir a revisión» is hidden.
- **Viewer:** no status strip and no review link.

Failed requests and legitimately empty results are distinguishable: a failure never renders zeros.

## Responsive Layout

- From `md` (768 px): the hero shows the figures side by side, the status strip uses an auto-fit grid, and recent activity is a table.
- Below `md`: the status strip is two columns, recent activity is a list, and the hero figures stack.
- The table keeps a 460 px minimum width (520 px from `lg`) inside a horizontal scroll container. At 768 px the content area is narrower because the desktop sidebar is open; cell padding is reduced there so the table fits without scrolling.
- Each activity row links to `/records/{id}`. In the table the link covers the whole row.

## Acceptance Criteria

- `EDITOR` sees the hero, the six-cell status strip, and recent activity. `VIEWER` sees the hero and recent activity only.
- Counts and monetary values come from `GET /api/dashboard`. The month return equals `GET /api/reports` for the same period.
- Each status cell links to `/records` with the matching filters. «Ir a revisión» links to `/records?status=NEEDS_REVIEW` and appears only when there are records to review.
- Failed requests show the error state with a retry action. Empty results show empty states, not errors.
- Loading shows skeletons, never zero values.
- The page has one `<h1>`, visible from `md` and screen-reader only below it.
- The layout works at 1440, 1024, 768, and 375 px in light and dark themes without horizontal page scrolling.

## Related Documents

- [`../02_routing_and_rendering.md`](../02_routing_and_rendering.md)
- [`../04_data_fetching_and_forms.md`](../04_data_fetching_and_forms.md)
- [`../05_ui_and_accessibility.md`](../05_ui_and_accessibility.md)
- [`records.md`](records.md)
- [`../../10_ui_ux.md`](../../10_ui_ux.md) (dashboard section)
- [`../../02_business_rules_and_states.md`](../../02_business_rules_and_states.md)
- [`../../11_technical_roadmap.md`](../../11_technical_roadmap.md) (phase 8.15)
