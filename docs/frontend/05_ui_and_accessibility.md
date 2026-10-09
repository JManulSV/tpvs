# UI and Accessibility

## Status

The project includes Tailwind CSS, shadcn/ui tooling, and initial UI primitives. The approved screen designs and flows are in [`../10_ui_ux.md`](../10_ui_ux.md); feature screens remain planned until implemented.

## Design and Component Use

- Treat [`../10_ui_ux.md`](../10_ui_ux.md) as the source for MVP navigation, screen content, and role-specific experience.
- Use existing primitives in `src/components/ui/` and add shadcn components only when a feature needs them.
- Keep domain-specific compositions inside their feature; promote a component to shared UI only after more than one feature needs a consistent primitive.
- Prefer semantic HTML and native controls where suitable.
- Keep visual consistency for spacing, typography, status indicators, forms, tables, dialogs, and feedback.
- Do not add MVP navigation modules beyond those approved in the UX document without a product decision.

## Interaction States

Interactive controls must communicate:

- loading or pending work;
- success or completion;
- validation errors and how to correct them;
- unavailable/forbidden actions;
- empty results and no-results-after-filtering;
- destructive or consequential actions that need a deliberate user choice.

Disable duplicate submission while a mutation is pending. Do not use color alone to communicate record status or validation; pair color with readable text or an accessible label.

## Accessibility

- Use keyboard-accessible controls and visible focus indicators.
- Associate labels, instructions, and validation messages with their inputs.
- Ensure dialogs manage focus and have an accessible name.
- Use headings in a logical hierarchy and landmarks for navigation/main content.
- Provide meaningful alternative text for informative images; mark decorative images appropriately.
- Make status and asynchronous feedback available to assistive technology, using suitable live regions when necessary.
- Preserve usable contrast and avoid relying only on color, hover, or pointer gestures.
- Ensure tables and filters remain navigable at narrow viewport widths.

## Role-Aware Interface

Use the authenticated user's role to improve the experience by showing relevant actions. The server must still enforce each permission. Handle `401` and `403` responses even if the action was hidden in the UI.

## Related Documents

- `02_routing_and_rendering.md`
- `04_data_fetching_and_forms.md`
- `06_testing.md`
- [`../10_ui_ux.md`](../10_ui_ux.md)
- [`../backend/05_validation_authorization.md`](../backend/05_validation_authorization.md)
