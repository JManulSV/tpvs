# Reports Feature

## Status

**Planned.** Report and Excel business endpoints are not implemented.

## Goal and Scope

Let users review official confirmed-record summaries and request an Excel export. The report month rule, period filters, and workbook contents are defined in [`../../02_business_rules_and_states.md`](../../02_business_rules_and_states.md), [`../../08_document_generation.md`](../../08_document_generation.md), and [`../../10_ui_ux.md`](../../10_ui_ux.md).

## Route and Screen

Target route: `/reports`.

Filters include year, month, week, and company. The screen presents official totals, company breakdowns, and included records as returned by Laravel, plus an Excel-generation/download action.

## Business and API Contract

- Only `CONFIRMED` records are included in official reports.
- Month filtering uses the month containing `Record.week_end_date` when a week spans months.
- Week and year must be treated as a combined period.
- Do not calculate official report totals in the browser.
- The target Excel endpoint is `GET /api/documents/excel` with optional documented filters; the reports read endpoint and exact response schema must be confirmed in the implemented API contract.
- Excel is a binary on-demand output. The BFF must preserve download response headers and bytes.

## Acceptance Criteria

- Filters are represented consistently in the URL/request and results.
- The selected filters and resulting period are clear to the user.
- Totals and company breakdowns match backend data; empty results differ from failed requests.
- Only users authorized by the backend can access each report or export.
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
- [`../../backend/03_api_contract.md`](../../backend/03_api_contract.md)
- [`../../11_technical_roadmap.md`](../../11_technical_roadmap.md) (phase 8.14)
