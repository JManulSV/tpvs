# Dashboard Feature

## Status

**Planned.** The current home page is a foundation health screen, not the approved product dashboard.

## Goal and Scope

Provide a role-aware operational starting point. The dashboard is a work center, not an analytics dashboard. Its approved content is in [`../../10_ui_ux.md`](../../10_ui_ux.md).

- `EDITOR`: actionable counts for records needing review, errors, pending/processing work, confirmed/canceled counts, recent activity, and a new-record action.
- `VIEWER`: confirmed-record summary, accumulated return, and recent activity.
- No charts are required for the MVP.

## Route and Composition

Target route: `/` within the authenticated application shell. The home page should compose dashboard-specific widgets from `features/dashboard/`; reusable navigation belongs to shared layout components.

## Data and Permissions

Dashboard endpoint shape is not finalized in the current API contract. Prefer a purpose-built backend read endpoint if the data cannot be obtained efficiently and consistently from existing resources. Do not issue many redundant requests or compute official totals in the browser. Laravel must return role-authorized data and compute official values.

## Interface States

Define loading, unavailable data, partial failure if requests are composed, and empty recent activity. Do not display zero as a confirmed result when the request failed.

## Acceptance Criteria

- The correct dashboard content and actions are shown for the authenticated role.
- Counts and monetary values come from the backend contract and respect official inclusion rules.
- The dashboard is useful without charts and adapts to the approved responsive layouts.
- Failed requests are distinguishable from legitimately empty results.
- Any action remains subject to Laravel authorization.

## Related Documents

- `../02_routing_and_rendering.md`
- `../04_data_fetching_and_forms.md`
- `../05_ui_and_accessibility.md`
- [`../../10_ui_ux.md`](../../10_ui_ux.md) (dashboard section)
- [`../../02_business_rules_and_states.md`](../../02_business_rules_and_states.md)
- [`../../11_technical_roadmap.md`](../../11_technical_roadmap.md) (phase 8.15)
