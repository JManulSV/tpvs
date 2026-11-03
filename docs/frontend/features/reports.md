# Reports Feature

## Status

**SPEC 13 / phase 8.14 complete (2026-11-03).** The reports read endpoint, the Excel endpoint, and the minimum `/reports` screen are defined below.

## Goal and Scope

Let `EDITOR` and `VIEWER` review official confirmed-record summaries and request an Excel export. The report month rule, period filters, and workbook contents are defined in [`../../02_business_rules_and_states.md`](../../02_business_rules_and_states.md), [`../../08_document_generation.md`](../../08_document_generation.md), and [`../../10_ui_ux.md`](../../10_ui_ux.md).

Phase 8.14 delivers the minimum working screen. The official UI/UX design belongs to phase 8.15. Out of scope: stored or asynchronous Excel files, CSV or PDF export, charts, period comparisons, and non-confirmed records.

## Route and Screen

Route: `/reports`, inside the authenticated `(app)` area, with a `Reportes` link in the main navigation visible to both roles.

Filters:

- `Año`: required, defaults to the current year;
- `Mes`: all or January through December;
- `Semana`: all or 1 through 53;
- `Empresa`: all or any company from the catalog.

Filters and the page number live in the URL so a report can be reloaded and shared.

The screen shows:

- the resulting period;
- `Ingreso`, `Comisión`, and `Retorno` totals;
- the `Por empresa` table (Empresa, Registros, Ingreso, Comisión, Retorno);
- the paginated `Registros incluidos` list, each linking to the record detail;
- the `Generar Excel` action.

## Business and API Contract

- Only `CONFIRMED` records are included in official reports.
- `year` is required. Month filtering uses the month containing `Record.week_end_date` when a week spans months.
- Week and year must be treated as a combined period.
- Do not calculate official report totals in the browser; show the values returned by Laravel.
- The reads use `GET /api/reports` with `year`, `month`, `week`, `company_id`, `page`, and `per_page` (default `15`, maximum `100`). Totals and the company breakdown always cover the full filtered set, not only the page.
- The export uses `GET /api/documents/excel` with the same filters. It ignores pagination and always exports the full filtered set.
- Excel is a binary on-demand output. The service requests it with `responseType: "blob"`, reads the file name from `Content-Disposition`, and triggers the browser download. The BFF already preserves `content-type`, `content-disposition`, and the binary body.
- Because the error body of a Blob request also arrives as a `Blob`, the service parses it as JSON before mapping the error.
- Both routes are read-only: no CSRF token and no `History` are involved.

## UI Behavior

- Loading, error with retry, and empty states are distinct. An empty result shows `No hay registros confirmados para estos filtros`; a failed request is shown as an error.
- Changing a filter resets the page to `1`.
- `Generar Excel` is available to both roles, prevents duplicate submission while generating, and is disabled when the result is empty.
- A successful export downloads the file with the name chosen by the server. A failed export shows the API error as a message, never as a file.
- The `422` field messages for invalid filters are shown to the user.
- The layout works for narrow viewports and keyboard navigation.

Code lives in `src/features/reports/` (`services`, `hooks`, `components`, `types.ts`), following `UI/hooks -> services -> BFF -> Laravel`.

## Acceptance Criteria

- Filters are represented consistently in the URL/request and results.
- The selected filters and resulting period are clear to the user.
- Totals and company breakdowns match backend data; empty results differ from failed requests.
- Both roles can read reports and export; unauthenticated users are redirected to sign in.
- Successful Excel responses download using server content headers; API errors are rendered as errors rather than files.
- The screen works for narrow viewports and keyboard navigation.

## Related Documents

- `../03_api_bff_and_sessions.md`
- `../04_data_fetching_and_forms.md`
- `../05_ui_and_accessibility.md`
- `../06_testing.md`
- [`../../02_business_rules_and_states.md`](../../02_business_rules_and_states.md) (report inclusion and period rules)
- [`../../08_document_generation.md`](../../08_document_generation.md)
- [`../../10_ui_ux.md`](../../10_ui_ux.md) (Reports and Excel)
- [`../../backend/03_api_contract.md`](../../backend/03_api_contract.md) (Reports and Excel Contract)
- [`../../11_technical_roadmap.md`](../../11_technical_roadmap.md) (phase 8.14)
- [`../../../specs/13-reports-excel-export.md`](../../../specs/13-reports-excel-export.md)
