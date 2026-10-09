# Companies Feature

## Status

**Planned.** The company database model exists, but business API endpoints and frontend screens are not implemented.

## Goal and Scope

Provide a simple catalog for consulting companies and creating entries. Company names are immutable in the MVP; there is no rename or delete action. Laravel does not create companies automatically from AI extraction.

## Routes and Screens

Target route: `/companies`.

- List and search companies.
- Show the record count or other summary only when the API contract supplies a consistent definition.
- Provide creation to `EDITOR` only.
- Provide consultation to `VIEWER`.

## API and Data

The roadmap plans:

```text
GET  /api/companies
POST /api/companies
GET  /api/companies/{id}
```

These are target endpoints. Confirm pagination/search parameters, response shape, and validation errors in the implemented contract before building services. Do not add update/delete UI because those operations are out of MVP scope.

## Acceptance Criteria

- Users can search/list companies according to the implemented API.
- An authorized `EDITOR` can create a company and receives backend validation feedback.
- A `VIEWER` can consult but cannot create; Laravel independently enforces this.
- The UI does not offer rename or delete actions.
- Empty, no-search-results, pending, and error states are distinguishable.

## Related Documents

- `../01_architecture_and_structure.md`
- `../04_data_fetching_and_forms.md`
- `../05_ui_and_accessibility.md`
- [`../../03_data_model.md`](../../03_data_model.md) (Company)
- [`../../10_ui_ux.md`](../../10_ui_ux.md) (Companies)
- [`../../backend/03_api_contract.md`](../../backend/03_api_contract.md)
- [`../../backend/05_validation_authorization.md`](../../backend/05_validation_authorization.md)
- [`../../11_technical_roadmap.md`](../../11_technical_roadmap.md) (phase 8.5)
