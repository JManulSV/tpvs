# Companies Feature

## Status

**Phase 8.5 implemented and verified (2026-10-14).** Laravel list/detail/create endpoints, the same-origin BFF integration, and the basic `/companies` screen are implemented.

**Redesign implemented and verified (SPEC 23, 2026-11-18 to 2026-11-19).** The screen follows the `Companies`, `CompaniesNew` and `CompaniesMobile` templates. Backend `CompaniesApiTest` (371 tests in the suite) and frontend tests (255) pass, and `lint`, `tsc --noEmit` and `build` pass. Browser checks for `EDITOR` and `VIEWER` ran at desktop and 390 px, in light and dark themes. The acceptance list is in [`../../specs/23-companies-screen-redesign.md`](../../specs/23-companies-screen-redesign.md).

## Goal and Scope

Provide a simple catalog for consulting companies and creating entries. Company names are immutable in the MVP; there is no rename or delete action. Laravel does not create companies automatically from AI extraction.

Phase 8.5 delivers a basic, functional interface for the company workflows. The official UI/UX design and visual polish are deferred to phase 8.15.

## Routes and Screens

Target route: `/companies`.

- List and search companies.
- Show the count of associated `CONFIRMED` records returned by the API, labelled «Confirmados».
- Provide creation to `EDITOR` only.
- Provide consultation to `VIEWER`.
- Table from a 40 rem container, cards below it. The whole row links to `/records?company_id={id}`.
- Add a link to Companies in the authenticated application shell.

Feature files (`frontend/src/features/companies/`):

- `components/companies-screen.tsx`: state, role split, and container selection. Receives `role`, not `canCreate`.
- `components/companies-header.tsx`, `companies-table.tsx`, `companies-card-list.tsx`, `company-created-notice.tsx`, `company-mark.tsx`, `companies-skeleton.tsx`, `companies-empty-state.tsx`, `companies-pagination.tsx`.
- `components/create-company-form.tsx`: the form without its container. The `Dialog` or `Sheet` wraps it in `companies-screen.tsx`, depending on `useIsMobile()`.
- `company-list-utils.ts`: pure text and link helpers, with tests in `frontend/tests/companies.test.ts`.
- `hooks/use-companies.ts`: `useCompanies` keeps the previous data while a new page or search loads (`keepPreviousData`).

Removed: `components/create-company-dialog.tsx`, the hand-made modal with its own focus trap.

## API and Data

The phase 8.5 API contract is:

```text
GET  /api/companies?search={text}&page={page}&per_page={page_size}
GET  /api/companies/{id}
POST /api/companies { "name": "Acme" }
```

All endpoints require authentication. Both roles can read; only `EDITOR` can create. The list supports case-insensitive substring search, trimmed to a maximum of 255 characters, and Laravel pagination ordered by company name ascending. `page` defaults to `1`; `per_page` defaults to `15` and accepts values from `1` to `100`; invalid parameters return `422`. List items have `id`, `name`, and `records_count`, where the count includes only `CONFIRMED` records. The paginated response uses Laravel's `data`, `links`, and `meta` shape.

The detail response is `{ "data": { "id": 1, "name": "Acme", "created_at": "..." } }`, without a count; an unknown ID returns `404`. Creation accepts a required name of at most 255 characters and returns `201` with the same shape.

`created_at` is an ISO 8601 instant in UTC. It is present on list, detail and create responses (SPEC 23). The frontend shows it as `dd/mm/aa` in the browser's time zone with `formatShortDate`, not `formatDate`, because `formatDate` expects a `Y-m-d` calendar date. Laravel trims surrounding whitespace. Names duplicated after trimming and case-insensitive comparison return `422`; PostgreSQL enforces the same uniqueness rule. The BFF's existing same-origin proxy forwards these calls without a Companies-specific route handler.

Do not add update or delete UI; those operations are out of MVP scope.

## Interaction and Visual Scope

- Debounce search input and return to page 1 when the search term changes.
- After successful creation, close and reset the dialog, clear search and pagination, refresh the list, and show success feedback.
- Keep the dialog open on validation failure and associate the backend name error with the input.
- Translate the backend duplicate-name message in the frontend (`getCompanyNameErrorMessage`). The backend text is pinned by `CompaniesApiTest`, so a change to it breaks a test on purpose.
- Keep the previous list visible while a page or search loads. Show the skeleton instead of an empty state when the only data is a stale empty placeholder.
- Pagination: 15 per page, with «Página X de Y» only when there is more than one page.
- Distinguish loading, empty catalog, no search results, request error, and mutation-pending states.
- Keep the interface basic and functional in Spanish. The official UI/UX and visual refinement belong to phase 8.15.

## Acceptance Criteria

- Users can search/list companies according to the implemented API.
- An authorized `EDITOR` can create a company and receives backend validation feedback.
- A `VIEWER` can consult but cannot create; Laravel independently enforces this.
- The UI does not offer rename or delete actions.
- Search resets pagination and list results reflect the approved paginated API contract.
- The record count shows only associated `CONFIRMED` records as supplied by Laravel.
- Empty, no-search-results, pending, mutation-pending, and error states are distinguishable.
- The basic Spanish interface supports the phase 8.5 workflows; official UI/UX alignment is deferred to phase 8.15.

## Related Documents

- `../01_architecture_and_structure.md`
- `../04_data_fetching_and_forms.md`
- `../05_ui_and_accessibility.md`
- [`../../03_data_model.md`](../../03_data_model.md) (Company)
- [`../../10_ui_ux.md`](../../10_ui_ux.md) (Companies)
- [`../../backend/03_api_contract.md`](../../backend/03_api_contract.md)
- [`../../backend/05_validation_authorization.md`](../../backend/05_validation_authorization.md)
- [`../../11_technical_roadmap.md`](../../11_technical_roadmap.md) (phase 8.5)
