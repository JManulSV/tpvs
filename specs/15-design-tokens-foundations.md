# SPEC 15 — Tokens de diseño y fundamentos de UI

> **Status:** Approved
> **Depends on:** SPEC 02, SPEC 04, SPEC 09, SPEC 10, SPEC 11, SPEC 13
> **Date:** 2026-11-05
> **Objective:** Sustituir el tema neutro de shadcn por los tokens de marca INNOVA con tema claro/oscuro, tipografía, primitivos ajustados y utilidades únicas de estado y formato, sin rediseñar las pantallas.

## Why this spec exists

El frontend ya implementa los flujos de SPEC 02 a SPEC 14 con el tema por defecto de shadcn (`base-nova`, escala neutra, Geist). Las etiquetas de estado se repiten en cuatro componentes con un texto distinto al aprobado («Necesita revisión» en lugar de «Por revisar»). `formatMoney` y `formatDate` están duplicados entre features.

Antes de rediseñar cada pantalla hace falta una base común: tokens, fuentes, tema oscuro, primitivos con la forma de la marca y un único mapa de estados. Esta spec construye esa base y migra solo los usos que ya existen. El rediseño de layout y pantallas queda para specs posteriores.

## Scope

**In:**

- Reemplazar los bloques `:root`, `.dark` y `@theme inline` de `frontend/src/app/globals.css` por los tokens INNOVA (colores, estados, sombras, radios, fuentes).
- Agregar el token `--overlay` para el velo de diálogos.
- Guardar el tema como referencia en `docs/design/shadcn-theme.css`.
- Cargar Plus Jakarta Sans y JetBrains Mono con `next/font/google` en lugar de Geist.
- Instalar `next-themes` con tema por defecto `system` y un botón para cambiar entre claro y oscuro en el header autenticado y en `/login`.
- Ajustar `components/ui/button.tsx` a la forma de marca: píldora, alto 40 px y foco de 2 px con offset de 2 px.
- Agregar con el CLI de shadcn y ajustar a los tokens: `badge`, `input`, `card`, `dialog` y `table`.
- Crear `lib/status.ts` como mapa único de estados del registro (etiqueta, icono Lucide, clases de tokens).
- Crear `components/status-badge.tsx`, que muestra color, icono y palabra.
- Crear `lib/format.ts` con el formato es-MX de dinero, fecha, fecha-hora y tamaño de archivo.
- Migrar los usos existentes de etiquetas de estado y formato a `lib/status.ts` y `lib/format.ts`.
- Mostrar `StatusBadge` donde hoy se muestra el estado como texto en tabla o encabezado.
- Sustituir `bg-black/40` por `bg-overlay` en los dos diálogos existentes.
- Actualizar `docs/frontend/05_ui_and_accessibility.md` con las reglas del sistema de diseño.
- Pruebas unitarias de `lib/format.ts` y `lib/status.ts`.

**Out of scope (for future specs):**

- Nuevo layout autenticado (sidebar de 24 px, hero, navegación «Dashboard · Registros · Empresas · Reportes»).
- Rediseño de las pantallas de registros, detalle, entregas, empresas, reportes y login.
- Migrar los `<button>`, `<input>`, `<select>`, `<table>` y diálogos nativos de las features a los primitivos nuevos.
- Estados de campo extraído por IA (Alta confianza, Revisar, Falta información, Inconsistencia). Requieren un contrato de backend.
- Aviso de expiración de sesión.
- `tokens.json`, `docs/IMPLEMENTATION.md` y el `CLAUDE.md` del frontend.
- Gráficas y tokens `--chart-3` a `--chart-5`.

## Data model

Esta spec no agrega datos persistidos ni cambia contratos con Laravel. Solo introduce estas estructuras de frontend.

### Tokens CSS

Los valores son los del tema INNOVA entregado en la conversación de diseño, copiados sin cambios a `docs/design/shadcn-theme.css` y a `globals.css`. Se agrega un único token propio:

```css
:root { --overlay: rgba(23, 32, 58, 0.40); }
.dark { --overlay: rgba(0, 0, 0, 0.60); }
@theme inline { --color-overlay: var(--overlay); }
```

Se conservan de la configuración actual:

- `@import "tw-animate-css"` y `@import "shadcn/tailwind.css"`.
- `@custom-variant dark (&:is(.dark *))`.
- `--font-heading: var(--font-sans)`, que usan los componentes `base-nova`.

Escala de radios. El CSS es la fuente de verdad:

| Clase | Valor | Uso |
| --- | --- | --- |
| `rounded-sm` | 8 px | inputs |
| `rounded-md` | 12 px | iconos de métrica, navegación, alertas |
| `rounded-lg` | 16 px | tarjetas |
| `rounded-xl` | 24 px | hero, sidebar, diálogos |
| `rounded-full` | píldora | botones, badges, buscador, controles segmentados |

### Fuentes

```ts
// frontend/src/app/layout.tsx
Plus_Jakarta_Sans({ subsets: ["latin"], variable: "--font-plus-jakarta" });
JetBrains_Mono({ subsets: ["latin"], variable: "--font-jetbrains-mono" });
```

### Mapa de estados — `frontend/src/lib/status.ts`

`lib/` no puede importar código de features (`docs/frontend/01_architecture_and_structure.md`). Por eso `lib/status.ts` pasa a ser el dueño de `recordStatuses` y `RecordStatus`. `features/records/types.ts` los reexporta para no romper los imports actuales.

```ts
export const recordStatuses = ["PENDING", "PROCESSING", "NEEDS_REVIEW", "ERROR", "CONFIRMED", "CANCELED"] as const;
export type RecordStatus = (typeof recordStatuses)[number];

type RecordStatusMeta = {
  label: string;        // "Por revisar"
  icon: LucideIcon;     // CircleAlert
  className: string;    // "bg-status-needs-review-bg text-status-needs-review"
  spin?: boolean;       // solo PROCESSING
};

export const recordStatusMeta: Record<RecordStatus, RecordStatusMeta>;
```

| Valor | `label` | `icon` | `className` |
| --- | --- | --- | --- |
| `PENDING` | Pendiente | `Clock` | `bg-status-pending-bg text-status-pending` |
| `PROCESSING` | Procesando | `LoaderCircle` (`spin: true`) | `bg-status-processing-bg text-status-processing` |
| `NEEDS_REVIEW` | Por revisar | `CircleAlert` | `bg-status-needs-review-bg text-status-needs-review` |
| `ERROR` | Error | `CircleX` | `bg-status-error-bg text-status-error` |
| `CONFIRMED` | Confirmado | `CircleCheck` | `bg-status-confirmed-bg text-status-confirmed` |
| `CANCELED` | Cancelado | `Ban` | `bg-status-canceled-bg text-status-canceled` |

Las clases se escriben como cadenas literales completas para que Tailwind las detecte. No se construyen por interpolación.

### `StatusBadge` — `frontend/src/components/status-badge.tsx`

```tsx
<StatusBadge status="NEEDS_REVIEW" />
```

- Usa el primitivo `badge`, en píldora, con icono de 16 px y la etiqueta como texto visible.
- El icono es `aria-hidden`.
- Para `PROCESSING` aplica `animate-spin motion-reduce:animate-none`.

### Formato — `frontend/src/lib/format.ts`

| Función | Entrada | Salida |
| --- | --- | --- |
| `formatMoney(amount)` | `string \| number \| null \| undefined` | `"$9,800.00"` (es-MX, MXN, 2 decimales); `"—"` si vacío |
| `formatDate(value)` | `"Y-m-d" \| null` | `"1 sep 2026"` sin pasar por la zona horaria local; `"—"` si `null` |
| `formatDateTime(value)` | ISO 8601 | `dateStyle: "medium"`, `timeStyle: "short"`; devuelve el valor original si no es fecha válida |
| `formatFileSize(bytes)` | `number` | mismo resultado que la implementación actual en `image-record-form.tsx` |

`monthName` y `yearOptions` siguen en `features/reports/utils.ts` porque son de dominio de reportes. `toCents`, `centsToAmount` y las funciones de denominaciones siguen en `features/deliveries/utils.ts`.

## UI contract

- Toda la app usa los colores INNOVA. En claro, `primary` es azul marino `#324168` con texto blanco. En oscuro, `primary` es azul cielo `#64B3DD` con texto `#0D1426`. Nunca aparece texto blanco sobre azul cielo.
- El tema inicial sigue la preferencia del sistema operativo. El botón de tema alterna entre claro y oscuro, y la elección persiste entre visitas en el mismo navegador.
- El botón de tema es de solo icono (`Sun` / `Moon`, 20 px) y lleva un `aria-label` en español que describe la acción («Cambiar a tema oscuro» o «Cambiar a tema claro»).
- No hay destello del tema incorrecto al cargar ni advertencias de hidratación.
- `Button` mide 40 px de alto en tamaño `default`, es `rounded-full` y muestra un anillo `ring` de 2 px con offset de 2 px al recibir foco con teclado. Los demás tamaños conservan su proporción y también son píldora.
- El estado del registro se muestra con `StatusBadge` en:
  - la tabla de `records-screen.tsx`;
  - el encabezado de `record-detail-screen.tsx`;
  - el encabezado de `delivery-screen.tsx`.
- El filtro de estado en registros y los valores del historial (`record-history-panel.tsx`) usan las etiquetas del mapa como texto.
- `NEEDS_REVIEW` se lee «Por revisar» en todas las pantallas.
- Montos, fechas y tamaños de archivo se ven igual que hoy, ahora desde `lib/format.ts`.
- Los diálogos de cancelar/reabrir registro y crear empresa usan `bg-overlay` como velo.
- Las pantallas existentes conservan su estructura y su funcionamiento. Solo cambian colores, tipografía, forma de los botones `Button` y la presentación del estado.

## Implementation plan

1. Copiar el tema INNOVA a `docs/design/shadcn-theme.css`, incluyendo `--overlay` y la nota de la escala de radios.
2. Reemplazar `:root`, `.dark` y `@theme inline` en `frontend/src/app/globals.css` por los tokens INNOVA. Conservar los imports, la variante `dark` y `--font-heading`. Verificar con `build` que las clases existentes compilan.
3. Cambiar Geist por Plus Jakarta Sans y JetBrains Mono en `frontend/src/app/layout.tsx` con las variables `--font-plus-jakarta` y `--font-jetbrains-mono`.
4. Instalar `next-themes`. Crear `frontend/src/components/providers/theme-provider.tsx` (`attribute="class"`, `defaultTheme="system"`, `enableSystem`). Envolver la app en `layout.tsx` y agregar `suppressHydrationWarning` en `<html>`.
5. Crear `frontend/src/components/theme-toggle.tsx`. Colocarlo en el header de `frontend/src/app/(app)/layout.tsx` y en `frontend/src/app/(auth)/login/page.tsx`.
6. Ajustar `frontend/src/components/ui/button.tsx`: `rounded-full`, alto 40 px en `default` y `icon`, y foco `ring-2 ring-ring ring-offset-2 ring-offset-background`. Revisar en el navegador las pantallas que ya usan `Button`.
7. Agregar con `npx shadcn@latest add` los componentes `badge`, `input`, `card`, `dialog` y `table` (registro `base-nova`). Ajustar:
   - `input`: `rounded-sm`, alto 40 px, borde `border-input` y el mismo foco que `button`.
   - `card`: `rounded-lg`, `shadow-card`.
   - `dialog`: `rounded-xl`, `shadow-popover`, velo `bg-overlay`.
   - `badge`: `rounded-full`, iconos de 16 px.
   - `table`: contenedor con scroll horizontal.
8. Crear `frontend/src/lib/status.ts` con `recordStatuses`, `RecordStatus` y `recordStatusMeta`. Hacer que `features/records/types.ts` los reexporte. Agregar `frontend/tests/status.test.ts`.
9. Crear `frontend/src/components/status-badge.tsx`.
10. Migrar las etiquetas de estado:
    - eliminar los cuatro `statusLabels` locales de `records-screen.tsx`, `record-detail-screen.tsx`, `record-history-panel.tsx` y `delivery-screen.tsx`;
    - usar `StatusBadge` o `recordStatusMeta[status].label` según el UI contract;
    - actualizar las pruebas que esperen «Necesita revisión».
11. Crear `frontend/src/lib/format.ts` y `frontend/tests/format.test.ts`. Mover las pruebas de `formatMoney` y `formatDate` desde `tests/reports.test.ts`.
12. Migrar los formatos:
    - eliminar `formatMoney` y `formatDate` de `features/reports/utils.ts`;
    - eliminar `formatMoney` de `features/deliveries/utils.ts`;
    - eliminar `formatDate` local de `record-history-panel.tsx` (pasa a `formatDateTime`);
    - eliminar los `formatFileSize` locales de `image-record-form.tsx` y `record-detail-screen.tsx`;
    - actualizar los imports en `report-tables.tsx`, `delivery-form.tsx`, `delivery-screen.tsx` y los demás consumidores.
13. Sustituir `bg-black/40` por `bg-overlay` en `record-action-dialog.tsx` y `create-company-dialog.tsx`.
14. Actualizar `docs/frontend/05_ui_and_accessibility.md` con una sección «Design system». Debe cubrir: tokens y ruta del tema, regla de no usar colores literales, par `primary`/`primary-foreground`, escala de radios, foco, iconos, `tabular-nums` en cifras, `lib/status.ts` y `lib/format.ts`.

## Acceptance criteria

- [ ] `frontend/src/app/globals.css` contiene exactamente los valores de color de `docs/design/shadcn-theme.css` para `:root` y `.dark`.
- [ ] `grep` de `oklch(` en `globals.css` no devuelve resultados.
- [ ] En las features no queda ninguna clase de color de la paleta de Tailwind (`bg-black`, `text-gray-*`, `bg-red-*`, etc.) ni un color hexadecimal.
- [ ] El `<body>` se renderiza con Plus Jakarta Sans. Un elemento con `font-mono` se renderiza con JetBrains Mono.
- [ ] Con el sistema operativo en modo oscuro y sin preferencia guardada, la app carga con la clase `dark` en `<html>`.
- [ ] Al pulsar el botón de tema, la app cambia de claro a oscuro y viceversa, y al recargar conserva la elección.
- [ ] El botón de tema existe en `/login` y en el header autenticado, y tiene un `aria-label` que cambia según el tema.
- [ ] La consola del navegador no muestra advertencias de hidratación en `/login`, `/dashboard` ni `/records`.
- [ ] `Button` en tamaño `default` mide 40 px de alto y tiene `border-radius` de píldora.
- [ ] Al navegar con Tab, el foco de `Button` muestra un anillo de 2 px separado 2 px del borde.
- [ ] Existen `badge.tsx`, `input.tsx`, `card.tsx`, `dialog.tsx` y `table.tsx` en `frontend/src/components/ui/`, y solo usan clases de tokens.
- [ ] `rounded-sm` resuelve a 8 px, `rounded-md` a 12 px, `rounded-lg` a 16 px y `rounded-xl` a 24 px.
- [ ] `recordStatusMeta` tiene exactamente las seis entradas de la tabla del Data model, con esas etiquetas e iconos.
- [ ] La tabla de registros, el detalle y la pantalla de entrega muestran el estado con icono y palabra, no solo con color.
- [ ] El texto «Necesita revisión» no aparece en `frontend/src` ni en `frontend/tests`.
- [ ] Con `prefers-reduced-motion: reduce`, el icono de `PROCESSING` no gira.
- [ ] No existen definiciones locales de `statusLabels`, `formatMoney`, `formatDate` ni `formatFileSize` fuera de `lib/`.
- [ ] `formatMoney("9800")` devuelve `"$9,800.00"` y `formatMoney(null)` devuelve `"—"`.
- [ ] `formatDate("2026-09-01")` devuelve `1 sep 2026` (con o sin punto) en cualquier zona horaria.
- [ ] Los diálogos de acción de registro y de crear empresa usan `bg-overlay`, y en oscuro el velo es más oscuro que el fondo.
- [ ] `docs/frontend/05_ui_and_accessibility.md` tiene la sección «Design system» con las reglas del paso 14.
- [ ] `npm run lint`, `npx tsc --noEmit`, `npm test` y `npm run build` en `frontend/` pasan sin errores.
- [ ] Dashboard, registros, detalle de registro, entrega, empresas, reportes y login se revisaron en el navegador en tema claro y oscuro, a ancho de escritorio y de tablet, sin texto ilegible ni controles invisibles.

## Decisions

- **Sí:** solo fundamentos en esta spec. El rediseño de pantallas va después. Así un cambio que toca todo el frontend se revisa en dos partes más pequeñas.
- **Sí:** la escala de radios del CSS manda (`sm` 8, `md` 12, `lg` 16, `xl` 24). Respeta la convención de shadcn. Las guías que decían inputs `rounded-lg` y tarjetas `rounded-2xl` se corrigen.
- **No:** reescribir la escala para que `rounded-lg` valga 8 px. Rompería la expectativa de los componentes de shadcn.
- **Sí:** guardar solo `docs/design/shadcn-theme.css` como referencia. Las reglas de uso van en `docs/frontend/05_ui_and_accessibility.md`, que ya es la guía de UI del repo.
- **No:** agregar `CLAUDE.md`, `tokens.json` o `IMPLEMENTATION.md` en esta spec. Los dos últimos no están disponibles, y el primero contenía reglas que ya no aplican (`lib/mock`).
- **Sí:** tema por defecto `system`, con un botón que alterna entre claro y oscuro y persistencia estándar de `next-themes` (localStorage). No se ofrece una tercera opción «sistema» en el botón, para mantener un solo control de icono.
- **No:** guardar la preferencia de tema en el backend. No es dato de negocio.
- **Sí:** agregar ahora el set completo de primitivos (`badge`, `input`, `card`, `dialog`, `table`) ajustados a los tokens, aunque las pantallas los adopten después. Así las specs de rediseño parten de primitivos ya validados.
- **Sí:** `lib/status.ts` es el dueño de `RecordStatus` y `features/records/types.ts` lo reexporta. Cumple la regla de que `lib/` no importa de features y conserva los imports actuales.
- **Sí:** migrar ya las cuatro copias de etiquetas de estado al mapa, con el cambio visible a «Por revisar».
- **No:** incluir los estados de campo extraído por IA. El backend no expone hoy un estado por campo, así que hace falta un contrato.
- **Sí:** nuevo token `--overlay` en lugar de `bg-black/40` o `bg-foreground/40`. `foreground` en oscuro produciría un velo claro.
- **Sí:** `formatFileSize` en `lib/format.ts`. Hoy está duplicado en dos componentes de registros.
- **No:** mover `monthName`, `yearOptions` ni la aritmética en centavos a `lib/format.ts`. Son de dominio de reportes y entregas.

## Risks

| Risk | Mitigation |
| --- | --- |
| Botones de 40 px y en píldora pueden desacomodar formularios y barras de acciones que se diseñaron para 32 px. | El paso 6 incluye revisar en el navegador cada pantalla que usa `Button`. Los ajustes de espaciado necesarios se hacen en la feature sin cambiar su estructura. |
| El tema oscuro puede mostrar fondos o textos fijos de las features que no siguen tokens. | La auditoría inicial solo encontró `bg-black/40`. El criterio de aceptación de `grep` y la revisión en ambos temas lo cubren. |
| `next-themes` puede producir un destello o advertencias de hidratación con el App Router de Next 16. | Usar `suppressHydrationWarning` en `<html>` y `attribute="class"`, revisar la guía de Next en `node_modules/next/dist/docs/` y comprobar la consola como criterio de aceptación. |
| El CLI de shadcn puede generar componentes con radios o anillos que no siguen la escala INNOVA (`ring-3`, `ring-ring/50`). | El paso 7 ajusta cada primitivo, y un criterio exige que solo usen clases de tokens. |
| Mover `RecordStatus` a `lib/` puede romper imports. | `features/records/types.ts` reexporta el tipo y la constante, y `tsc` está en los criterios de aceptación. |
| Las clases de estado construidas por interpolación no se generarían en el CSS. | El mapa usa cadenas literales completas, y una prueba verifica las seis clases. |

## What is **not** in this spec

- Layout con sidebar, hero y navegación renombrada.
- Rediseño de las pantallas de registros, detalle, entregas, empresas, reportes y login.
- Migración de controles nativos de las features a los primitivos nuevos.
- Estados de campo extraído por IA.
- Aviso de expiración de sesión.
- `tokens.json`, `docs/IMPLEMENTATION.md` y `CLAUDE.md`.
- Tokens de gráficas adicionales.
