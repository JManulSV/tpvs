# Design

- `shadcn-theme.css` — design tokens (colors, spacing, radii, typography); see SPEC 15.
- `templates/*.dc.html` — screen templates exported from the design tool. Keep them in one flat folder: they load `./support.js` and `./vendor/*` by relative path, and the short mobile files import their desktop template by name (`<dc-import name="…">`).

## Templates by Screen

| Screen | Route | Desktop | Mobile | Other variants | SPEC |
| --- | --- | --- | --- | --- | --- |
| App shell (sidebar, top bar) | all authenticated routes | `Shell` | `Shell-mobile` | — | 16 |
| Error del servidor | `app/(app)/error.tsx` | `ErrorBackend` | — | — | — |
| Dashboard | `/dashboard` | `DashboardEditor`, `DashboardViewer` | `DashboardEditorMobile`, `DashboardViewerMobile` | one per role | 18 |
| Registros | `/records` | `RecordsEditor` | `RecordsEditorMobile` | — | 19 |
| Nuevo registro manual | `/records/new` | `RecordNewManual` | `RecordNewManualMobile` ¹ | — | 21 |
| Nuevo registro desde imagen | `/records/new` | `RecordNewImageSplit` | `RecordNewImageSplitMobile` ¹ | — | 21 |
| Detalle de registro | `/records/{id}` | `RecordDetailEditor` | `RecordDetailEditorMobile` | — | 20 |
| Revisar y corregir registro | `/records/{id}` (edit) | `RecordEdit` | `RecordEditMobile` ¹ | — | 21 |
| Preparar entrega | `/records/{id}/delivery` | `DeliveryPrepare` | `DeliveryPrepareMobile` | `DeliveryUpdate` (stale delivery) | 22 |
| Empresas | `/companies` | `Companies` | `CompaniesMobile` | `CompaniesNew` (create form) | 23 |
| Reportes | `/reports` | `Reports` | `ReportsMobile` | — | 24 |

¹ Wrapper that renders the desktop template at 390 px through `<dc-import>`.

`/login` (SPEC 17) has no template here; its design is described in the SPEC.

When you add a template, add its row here.
