# UI and Accessibility

## Design system

- The INNOVA design tokens are the source of truth in `frontend/src/app/globals.css`; the reference theme is `docs/design/shadcn-theme.css`. Keep their `:root` and `.dark` color values synchronized.
- Use semantic theme and status utilities such as `bg-background`, `text-foreground`, `bg-primary`, and `text-status-confirmed`. Do not add literal color values or Tailwind palette colors in feature UI; add or reuse a named token instead.
- Always use `primary` with its paired `primary-foreground`. In dark mode, the sky-blue primary (`#64B3DD`) uses dark foreground text (`#0D1426`), never white text.
- Follow the CSS radius scale: `rounded-sm` 8 px for inputs, `rounded-md` 12 px for metric icons/navigation/alerts, `rounded-lg` 16 px for cards, `rounded-xl` 24 px for hero/sidebar/dialogs, and `rounded-full` for pill controls, buttons, and badges.
- Buttons and inputs use a visible keyboard focus ring of 2 px with a 2 px offset. Preserve `focus-visible` styling when composing or extending controls.
- Pair status color with readable text and an icon; keep decorative icons `aria-hidden`. Use Lucide icons consistently, with 16 px icons in status badges. Animated status icons must honor reduced-motion preferences.
- Apply `tabular-nums` to money, totals, and other columns of figures so digits align consistently.
- Use `frontend/src/lib/status.ts` as the single source for record status labels, icons, and token classes. Render status with `StatusBadge` where the UI contract calls for a status indicator, and use the shared label for filters and history values.
- Use `frontend/src/lib/format.ts` for money, calendar dates, date-times, and file sizes. Keep domain-specific date and amount calculations in their feature utilities.

## Status

The project includes Tailwind CSS, shadcn/ui tooling, and initial UI primitives. The approved screen designs and flows are in [`../10_ui_ux.md`](../10_ui_ux.md); feature screens remain planned until implemented.

## Application shell

- The authenticated shared shell lives in `frontend/src/components/app-shell/`. It composes the collapsible sidebar, topbar, and each screen's existing content; screens keep their own `<h1>`.
- The shell does not import feature code. Feature-specific pieces, including the `NEEDS_REVIEW` records badge and logout actions, are passed through slots by `frontend/src/app/(app)/layout.tsx`.
- The desktop sidebar uses shadcn's floating variant and collapses to icons. Its expanded/collapsed state is stored in the `sidebar_state` cookie and read by the server layout to avoid an incorrect initial state. At viewports below 768 px the same navigation is shown in a drawer; selecting a route closes it.
- Navigation links use `aria-current="page"` for the exact route or nested routes. In collapsed mode, each icon has a tooltip with the link label.
- On mobile, the floating «Nuevo registro» button in `AppBottomNav` is shown to the `EDITOR` on `/dashboard` and `/records` only, decided by `shouldShowNewRecordFab` in `nav-items.ts`. On desktop the same action is the topbar button, so screens must not add their own «Nuevo registro» button.
- The sidebar's Cuenta section and topbar show the authenticated user's name, initials, and role. Role-specific actions remain controlled by the app layout and feature slots.

## Design and Component Use

- Treat [`../10_ui_ux.md`](../10_ui_ux.md) as the source for MVP navigation, screen content, and role-specific experience.
- Use existing primitives in `src/components/ui/` and add shadcn components only when a feature needs them.
- Keep domain-specific compositions inside their feature; promote a component to shared UI only after more than one feature needs a consistent primitive.
- Prefer semantic HTML and native controls where suitable.
- Choose between a table and a card list with a container query (`@container` and `@[60rem]:`), not the window width, so the result is correct whether the sidebar is expanded, collapsed, or a drawer. Render both and hide one with `display: none` so it leaves the accessibility tree and the Tab order.
- Filter controls use the shadcn `Select` with a pill shape. Because it has no empty value, represent «all» with an internal sentinel and translate it to "no filter" before calling the API.
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
