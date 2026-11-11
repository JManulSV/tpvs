# SPEC 19 — Rediseño de la pantalla de Registros

> **Status:** Approved
> **Depends on:** SPEC 04, SPEC 09, SPEC 15, SPEC 16, SPEC 18
> **Date:** 2026-11-10
> **Objective:** Reemplazar la tabla plana de `/records` por la interfaz de las plantillas `RecordsEditor{,Mobile}.dc.html` (tarjeta de filtros, tabla en pantallas anchas, tarjetas en pantallas angostas y acción de fila por rol), con tokens de shadcn y dentro del shell existente, sin cambiar los datos ni el backend.

## Why this spec exists

SPEC 18 dejó `/records` leyendo filtros iniciales desde la URL, pero su aspecto sigue siendo el de la Fase 8.6: una rejilla de filtros con bordes literales, una tabla con `min-w-[58rem]` que obliga a hacer scroll horizontal y un botón «+ Nuevo registro» propio que, en escritorio, ya está duplicado en el topbar del shell.

`docs/10_ui_ux.md` §5 define Registros como la pantalla operativa principal: búsqueda por empresa, afiliación y sucursal, seis filtros y fila completa clicable. Las plantillas de `docs/design/templates/` aportan el diseño visual de esos requisitos.

Las plantillas pintan dentro de la página el sidebar, el buscador del encabezado, «Nuevo registro», las notificaciones, la barra inferior y el FAB. Todo eso ya existe en el shell (SPEC 16), así que no se repite. Esta spec toma de las plantillas solo el **contenido** de la página.

## Scope

**In:**

- Rediseño de `RecordsScreen` y de sus piezas en `frontend/src/features/records/components/`, con las plantillas `RecordsEditor.dc.html` (escritorio) y `RecordsEditorMobile.dc.html` (móvil) como referencia visual.
- Traducción de los colores literales de las plantillas a los tokens globales de SPEC 15 (`bg-card`, `bg-muted`, `text-muted-foreground`, `bg-accent`, `text-accent-foreground`, `shadow-card`, `bg-status-*`, etc.). Esta spec no agrega tokens.
- Tarjeta de filtros con buscador, Empresa, Estado, Año, Mes, Semana y Fuente.
- Los selects son el `Select` de shadcn (registro `base-nova`, agregado con el CLI y ajustado a los tokens).
- Lista de registros:
  - tabla en contenedores anchos, con las columnas `Empresa · Semana · Fin de semana · Fuente · Retorno · Estado · Acción`;
  - lista de tarjetas en contenedores angostos;
  - fila completa clicable hacia `/records/{id}`.
- Acción de fila por rol: «Revisar» (`NEEDS_REVIEW`) y «Ver error» (`ERROR`) solo para `EDITOR`. El `VIEWER` ve solo el chevrón.
- Botón «Nuevo registro» (solo `EDITOR`):
  - en escritorio sigue siendo el del topbar;
  - en móvil, el FAB de `AppBottomNav` también aparece en `/records`.
- Se elimina el botón «+ Nuevo registro» que hoy pinta `RecordsScreen`.
- Estados de carga (skeleton), error con reintento, vacío sin registros y vacío por filtros con «Limpiar filtros».
- Pie con el conteo y la paginación existente.
- Utilidades puras con pruebas unitarias.
- Documentar en `docs/frontend/features/records.md` y `docs/frontend/05_ui_and_accessibility.md`.

**Out of scope (for future specs):**

- Cambios de backend o del contrato `GET /api/records`.
- «Retorno confirmado en esta lista» del pie de la plantilla (ver Decisions).
- Buscador del topbar, notificaciones y atajo `⌘K`. Siguen deshabilitados.
- Ordenar por columna o cambiar el orden `updated_at desc`.
- Sincronizar en la URL los filtros que el usuario cambia después. Solo se leen al entrar (SPEC 18).
- Leer `week`, `company_id`, `source` o `search` de la URL.
- Rediseño de `/records/new`, `/records/{id}`, la entrega, Empresas y Reportes.
- Cambios al sidebar, al topbar y a `AppMobileTopbar`.
- Acciones en lote o selección múltiple de filas.

## Data model

Esta spec no agrega tablas, migraciones ni contratos de API. `GET /api/records` y `RecordResource` no cambian. Los filtros (`company_id`, `year`, `month`, `week`, `status`, `source`, `search`, `page`, `per_page`), el *debounce* de 300 ms del buscador y el estado inicial desde la URL (`parseInitialRecordFilters`) se conservan.

### Props

- `RecordsScreen` deja de recibir `canCreate: boolean` y recibe `role: "EDITOR" | "VIEWER"`.
- `app/(app)/records/page.tsx` pasa `user.role`. Conserva `export const instant = false` y el `redirect` sin sesión.

### Utilidades puras — `frontend/src/features/records/record-list-utils.ts`

```ts
export function getRecordRowAction(record: Pick<RecordItem, "status">, role: UserRole): "Revisar" | "Ver error" | null;
export function getRecordSubtitle(record: RecordItem): string;          // afiliación · sucursal · +N movimientos, o el texto de espera
export function getRecordMetaLine(record: RecordItem): string;          // móvil: "Imagen · Semana 38 · fin 15 sep 2026"
export function getRecordRowLabel(record: RecordItem): string;          // aria-label del enlace de la fila
export function hasActiveRecordFilters(search: string, filters: object): boolean;
```

- `getRecordRowAction`:
  - `EDITOR` con `NEEDS_REVIEW` devuelve «Revisar»;
  - `EDITOR` con `ERROR` devuelve «Ver error»;
  - cualquier otro caso devuelve `null`, incluido el `VIEWER` en todos los estados.
- `getRecordSubtitle`:
  - con movimientos que tengan afiliación, usa el primero: «Afil. {afiliación} · {sucursal}», más « · +N movimiento(s)» si hay N movimientos adicionales (singular con 1);
  - sin eso, y según el estado: `PENDING` «En cola para procesar», `PROCESSING` «Extrayendo datos de la imagen», `ERROR` «No se pudo leer la imagen»;
  - en cualquier otro caso, cadena vacía.
- `getRecordMetaLine`:
  - con semana, «{Imagen|Manual} · Semana {N} · fin {formatDate(week_end_date)}»;
  - sin semana, «{Imagen|Manual} · Sin periodo todavía».
- `getRecordRowLabel`: «Abrir registro de {empresa o "Sin empresa asignada"}[, semana N], {estado}».
- `hasActiveRecordFilters`: `true` si hay búsqueda no vacía o cualquier filtro con valor.
- Las cadenas de estado salen de `recordStatusMeta`, y los importes y fechas de `formatMoney` y `formatDate`. No se agrega ningún cálculo de totales.

### Navegación — `frontend/src/components/app-shell/nav-items.ts`

```ts
export function shouldShowNewRecordFab(pathname: string, role: UserRole): boolean;
```

- Devuelve `true` solo para `EDITOR` cuando `pathname` es exactamente `/dashboard` o `/records`.
- `/records/new`, `/records/{id}` y las demás rutas devuelven `false`.
- `AppBottomNav` usa esta función en lugar de su comparación actual con `/dashboard`.

## UI contract

### Fusión con el layout existente

El contenido se renderiza dentro del contenedor de `AppShell` (`max-w-6xl`, `px-4 pt-4` y el espacio inferior para la barra en móvil). La página no agrega margen exterior ni fondo propio.

| Elemento de la plantilla | Qué se hace |
| --- | --- |
| Sidebar azul, marca, usuario y «Cerrar sesión» | Se descarta. Se usa `AppSidebar`. |
| Contador «4» junto a «Registros» | Se descarta. Se usa `NeedsReviewNavBadge`. |
| Encabezado con buscador, «Nuevo registro» y notificaciones | Solo el `<h1>` pasa a la página. Lo demás sigue en `AppTopbar`. |
| Topbar móvil, barra inferior | Se descartan. Se usan `AppMobileTopbar` y `AppBottomNav`. |
| FAB «Nuevo registro» móvil | Se usa el de `AppBottomNav`, ahora también en `/records`. |
| Buscador de la tarjeta de filtros (escritorio) y buscador móvil | Se implementan en la página. Son el buscador funcional. |
| Tarjeta de filtros, tabla, tarjetas, estados vacíos y pie | Se implementan aquí. |

### Encabezado

- Un único `<h1 id="records-heading">Registros</h1>`: 24/32 px, 700, `tracking-[-0.01em]`, visible en todos los anchos.
- Se elimina el subtítulo «Consulta y revisa los registros del sistema.» y el botón «+ Nuevo registro» de la página.
- 24 px de separación con el contenido desde `md`, 16 px en móvil.

### Filtros

Los controles tienen forma de píldora, con `border-input`, `bg-card`, foco con anillo `ring` de 2 px y `Select` de shadcn.

**Desde `md`** (tarjeta `rounded-lg border bg-card shadow-card`, `aria-label="Filtros"`, padding 20/24 px):

- Rejilla de 4 columnas cuando el contenedor mide al menos 48 rem y de 2 columnas por debajo. Se usa `@container`, no el ancho de la ventana, porque el sidebar cambia el espacio disponible.
- «Buscar» ocupa 2 columnas y va en la primera fila, seguido de Empresa y Estado. Año, Mes, Semana y Fuente van después.
- Etiquetas de 12/16 px, 600, `text-muted-foreground`, asociadas a su control.
- Pie de la tarjeta:
  - a la izquierda, «El mes se toma de la fecha de fin de la semana.» en 12/16 px;
  - a la derecha, el botón de texto «Limpiar filtros» con icono `X`, visible solo si `hasActiveRecordFilters`.

**Por debajo de `md`:**

- Buscador de 48 px de alto en una sola fila.
- Debajo, una fila de «chips» con scroll horizontal (`overflow-x-auto`, sin que la página haga scroll horizontal): Empresa, Estado, Año, Mes, Semana y Fuente.
  - Los chips miden 44 px de alto.
  - Un chip con valor distinto de «Todos» usa `bg-accent text-accent-foreground` y `border-accent-foreground`.
  - Los textos de las opciones llevan prefijo para ser legibles sin etiqueta: «Todas las empresas», «Año 2026», «Mes: enero», «Semana 38», «Fuente: Imagen».
- Línea de resumen «**N** registros» y, si hay filtros activos, «Limpiar» con al menos 44 px de alto.

**Comportamiento común:**

- «Limpiar filtros» restablece buscador, filtros y página 1.
- La opción vacía de cada select usa el valor interno `"all"`. Nunca se envía `"all"` al backend.
- Las opciones y sus valores son los de hoy: años (últimos ocho más el de la URL), meses 1–12 en español, semanas 1–53, estados de `recordStatusMeta` y fuentes `IMAGE`/`MANUAL`.
- Cada control conserva su etiqueta accesible. En móvil es `aria-label`.

### Lista — escritorio (contenedor ≥ 60 rem)

- Tarjeta `rounded-lg border bg-card shadow-card`, `aria-label="Lista de registros"`, `overflow-hidden`.
- Tabla semántica con el primitivo `table` de shadcn:
  - sin `min-w` ni scroll horizontal;
  - `<caption class="sr-only">`;
  - encabezados de 11/16 px, 600, mayúsculas, `tracking-[0.08em]`, sobre `bg-muted`.
- Columnas:

| Columna | Contenido |
| --- | --- |
| Empresa | Nombre en 600 (enlace) y debajo `getRecordSubtitle` en 12/16 px `text-muted-foreground`. Sin empresa: «Sin empresa asignada» en cursiva `text-muted-foreground`. |
| Semana | Número en 600 y debajo el año. Sin semana: «—». |
| Fin de semana | `formatDate(week_end_date)` con `tabular-nums`, `text-muted-foreground`. |
| Fuente | Icono (`Image` o `Pencil`, `aria-hidden`) y «Imagen» o «Manual». |
| Retorno | `formatMoney(return_total)` alineado a la derecha, 600, `tabular-nums`. En `text-muted-foreground` y peso 500 si es `null` o el estado es `CANCELED`. |
| Estado | `StatusBadge`. |
| Acción | `getRecordRowAction` en 13/16 px 600 `text-accent-foreground` con `ChevronRight`, o solo el chevrón. |

- Fila de 64 px mínimo, borde inferior, hover `bg-muted/50`.
- Fila clicable: el nombre de la empresa es un `Link` a `/records/{id}` con un pseudo-elemento `after:absolute after:inset-0` y `relative` en la `<tr>`.
  - El foco muestra un anillo de 2 px hacia dentro de la fila.
  - El enlace lleva `aria-label` con `getRecordRowLabel`.

### Lista — móvil (contenedor < 60 rem)

- `<ul aria-label="Lista de registros">` con 12 px de separación. Cada `<li>` es una tarjeta `rounded-lg border bg-card p-4 shadow-card`, hover `bg-muted/50`.
- Contenido de cada tarjeta:
  - primera fila: nombre de la empresa (enlace de 15/24 px, 600) y `StatusBadge` a la derecha;
  - `getRecordMetaLine` y `getRecordSubtitle` en 12/16 px `text-muted-foreground`;
  - última fila: importe en 18/24 px, 700, `tabular-nums`, y a la derecha la acción de fila o el chevrón.
- La tarjeta completa es clicable, con el mismo pseudo-elemento que la tabla.
- Se renderizan la tabla y la lista en el DOM y CSS oculta una de las dos con `display: none`. La que está oculta no aparece en el árbol de accesibilidad ni en el orden de Tab.

### Estados

- **Cargando:** el encabezado y los filtros se muestran, y en lugar de la lista aparecen cinco filas o tarjetas `Skeleton`. `aria-live="polite"` con «Cargando registros…» (`sr-only`). Nunca se muestra «0 registros».
- **Error:** tarjeta con `role="alert"`, el mensaje del error (o «No se pudieron cargar los registros.») y un `Button` «Reintentar» que llama `refetch()`.
- **Sin registros** (sin filtros ni búsqueda): «Todavía no hay registros.» en `text-muted-foreground`, dentro de la tarjeta.
- **Sin resultados por filtros:** insignia de 48 px con icono de documento, título «No hay registros con estos filtros» (16/24 px, 600), texto «Prueba con otra semana o quita algún filtro.» y botón «Limpiar filtros».
- **Datos en caché con error al refrescar:** se mantienen los datos y no se muestra la alerta, como en SPEC 18.

### Pie y paginación

- En escritorio, barra `bg-muted` con borde superior dentro de la tarjeta; en móvil, debajo de la lista.
- A la izquierda, «**N** registros» (`meta.total`, singular con 1).
- A la derecha, «Página X de Y» con los botones «Anterior» y «Siguiente», que conservan sus reglas de deshabilitado.
  - En móvil los botones miden al menos 44 px de alto.
  - Mientras `isFetching`, la lista conserva los datos anteriores.
- El resto de la paginación (`PAGE_SIZE = 15`, reinicio a página 1 al cambiar filtros) no cambia.

### FAB móvil

- `AppBottomNav` muestra el FAB «Nuevo registro» en `/dashboard` y `/records` para `EDITOR`, solo por debajo de `md`, según `shouldShowNewRecordFab`.
- No cambia su aspecto ni su posición (`bottom-[calc(5.25rem+env(safe-area-inset-bottom))]`). El espacio inferior de `AppShell` (`9rem`) evita que tape la última tarjeta o la paginación.
- `VIEWER` nunca lo ve.

### Diferencias por rol

| Elemento | EDITOR | VIEWER |
| --- | --- | --- |
| Lista, búsqueda y filtros | Sí | Sí |
| «Nuevo registro» (topbar, escritorio) | Sí | No |
| FAB «Nuevo registro» (móvil) | Sí | No |
| Acción de fila «Revisar» / «Ver error» | Sí | No, solo chevrón |

El backend sigue siendo la autoridad. El rol solo decide qué se muestra.

## Implementation plan

1. **Primitivo `select`.**
   - Agregar `frontend/src/components/ui/select.tsx` con `npx shadcn add select` (registro `base-nova`) y ajustarlo a los tokens (`rounded-full`, `border-input`, `ring`, altura de 40/44 px).
   - Verificar que compila con `npx tsc --noEmit` y `npm run lint`. No hay cambio visible todavía.
2. **Utilidades puras.**
   - Crear `features/records/record-list-utils.ts`.
   - Crear `frontend/tests/record-list-utils.test.ts` con los casos de cada función: los dos roles contra los seis estados, subtítulo con 0, 1 y N movimientos adicionales, subtítulo de espera, registro sin empresa y sin semana, y filtros activos e inactivos.
3. **FAB en `/records`.**
   - Agregar `shouldShowNewRecordFab` a `nav-items.ts`, usarla en `AppBottomNav` y cubrirla en `frontend/tests/app-shell.test.ts` (`/dashboard`, `/records`, `/records/new`, `/records/7`, `/companies`, y los dos roles).
   - Verificar a mano, a 375 px como `EDITOR`: el FAB aparece en `/dashboard` y `/records`, y no en `/records/new`.
4. **Barra de filtros.**
   - Crear `components/records-filter-bar.tsx` con las dos presentaciones (tarjeta desde `md`, buscador y chips en móvil) y «Limpiar filtros».
   - Se monta en `RecordsScreen` sustituyendo la rejilla actual, sin cambiar el estado ni los parámetros.
5. **Lista.**
   - Crear `components/records-table.tsx` y `components/records-card-list.tsx`, y `components/records-skeleton.tsx` para la carga.
   - Se montan en `RecordsScreen` en lugar de la tabla actual.
6. **Estados, pie y encabezado.**
   - Reescribir el resto de `RecordsScreen`:
     - `<h1>` sin subtítulo y sin botón;
     - estados vacío, filtrado y error;
     - pie con conteo y paginación;
     - nueva prop `role`.
   - Actualizar `app/(app)/records/page.tsx`.
7. **Revisión visual.**
   - Revisar `/records` como `EDITOR` y `VIEWER`, en claro y oscuro, a 1440, 1024, 768 y 375 px, con el sidebar expandido y colapsado.
   - Comparar con las dos plantillas.
   - Probar la carga con red lenta, el error deteniendo el backend, el vacío con filtros y el vacío sin registros.
   - Ajustar solo espaciados y tamaños.
8. **Documentación.**
   - Actualizar `docs/frontend/features/records.md`: estructura de la lista, acción por rol y FAB.
   - Actualizar `docs/frontend/05_ui_and_accessibility.md`: el FAB aparece en `/dashboard` y `/records`, y el cambio entre tabla y tarjetas se decide por el ancho del contenedor.

## Acceptance criteria

- [x] `/records` tiene exactamente un `<h1>` con el texto «Registros», visible a 1440 px y a 375 px.
- [x] `/records` ya no contiene el botón «+ Nuevo registro» dentro de la página, ni el subtítulo «Consulta y revisa los registros del sistema.».
- [x] Como `EDITOR` a 1440 px, «Nuevo registro» aparece una sola vez (topbar). A 375 px aparece una sola vez (FAB).
- [x] Como `VIEWER`, «Nuevo registro» no aparece en ningún ancho.
- [x] `shouldShowNewRecordFab` devuelve `true` solo para `EDITOR` en `/dashboard` y `/records`, y `false` en `/records/new`, `/records/7`, `/companies`, `/reports` y para `VIEWER`.
- [x] Como `EDITOR`, una fila `NEEDS_REVIEW` muestra «Revisar» y una fila `ERROR` muestra «Ver error». El resto de estados muestra solo el chevrón.
- [x] Como `VIEWER`, ninguna fila muestra «Revisar» ni «Ver error».
- [x] Pulsar cualquier punto de una fila de la tabla o de una tarjeta abre `/records/{id}`.
- [x] Con el sidebar expandido a 1280 px, la tabla no genera scroll horizontal. Si el contenedor mide menos de 60 rem, se muestran tarjetas en su lugar.
- [x] A 375 px, la página no tiene scroll horizontal. Solo la fila de chips lo tiene.
- [x] Los seis filtros y el buscador envían los mismos parámetros que antes (`company_id`, `year`, `month`, `week`, `status`, `source`, `search`) y no envían `"all"`.
- [ ] Cambiar cualquier filtro o la búsqueda regresa a la página 1.
  > Pendiente: la base de desarrollo tiene 9 registros, todos en una página (`PAGE_SIZE = 15`). Falta comprobarlo con más de 15 registros.
- [x] «Limpiar filtros» restablece buscador y filtros y solo aparece si hay alguno activo.
- [x] `/records?status=NEEDS_REVIEW` carga con el filtro Estado en «Por revisar» y con «Limpiar filtros» visible.
- [x] `/records?status=FOO&month=13` carga sin filtros y sin errores.
- [x] Mientras carga, aparecen filas `Skeleton` y nunca «0 registros».
- [x] Con el backend detenido aparece una alerta con `role="alert"`, y «Reintentar» vuelve a hacer la petición.
- [ ] Sin registros ni filtros aparece «Todavía no hay registros.». Con filtros sin resultados aparece «No hay registros con estos filtros» y un botón «Limpiar filtros» que lo resuelve.
  > Pendiente: el estado con filtros sin resultados y «Limpiar filtros» se comprobó. «Todavía no hay registros.» no se comprobó porque la base tiene 9 registros y no se vació para probarlo.
- [x] Un registro `IMAGE` sin empresa ni semana muestra «Sin empresa asignada», «—» en Semana y el importe «—». Un `CANCELED` muestra el importe atenuado.
- [x] El pie muestra `meta.total` y «Página X de Y», y «Anterior» y «Siguiente» se deshabilitan en los extremos.
- [x] La página no muestra ningún importe sumado en el cliente.
- [ ] Todos los controles, enlaces de fila y botones se alcanzan con Tab y muestran un anillo de foco de 2 px.
  > Pendiente: el recorrido con Tab cubrió filtros, enlaces de fila y navegación. Faltan «Limpiar filtros» y los botones «Anterior»/«Siguiente».
- [x] Los archivos nuevos o modificados de `features/records/` y `components/ui/select.tsx` solo usan clases de tokens, sin colores literales ni de la paleta de Tailwind.
- [x] La consola del navegador no muestra advertencias de hidratación en `/records`.
- [x] `npm run lint`, `npx tsc --noEmit`, `npm test` y `npm run build` en `frontend/` pasan sin errores.
- [ ] La revisión visual del paso 7 se hizo en claro y oscuro, a 1440, 1024, 768 y 375 px, sin texto cortado ni solapamientos.
  > Pendiente: se revisaron capturas en claro a 1024 px y en oscuro a 768 px, y se comprobó el scroll en las 8 combinaciones. Faltan las capturas restantes y la revisión del FAB de 375 px contra la paginación al final de la lista. El sidebar colapsado no se revisó.
- [x] `docs/frontend/features/records.md` y `docs/frontend/05_ui_and_accessibility.md` describen el nuevo comportamiento.

## Decisions

- **Sí:** el botón de nuevo registro en móvil es el FAB que ya tiene el shell, ampliado a `/records` con `shouldShowNewRecordFab`. Es el cambio mínimo y evita un segundo botón con otro estilo. Se interpretó la indicación «solo agrega ese botón en esa pantalla móvil» como esto.
- **Sí:** se quita el botón «+ Nuevo registro» de la página. En escritorio duplicaba el del topbar y en móvil lo reemplaza el FAB.
- **No:** un botón propio de la pantalla solo para móvil. Rompería la consistencia con el FAB del Dashboard.
- **Sí:** esta spec toca `AppBottomNav` y `nav-items.ts`, a diferencia de SPEC 18. El cambio se limita a la condición de visibilidad del FAB y no altera el aspecto del shell.
- **Sí:** «Revisar» y «Ver error» son parte de la diferencia de roles, como en la plantilla. Es solo visual, porque la fila entera ya lleva a la pantalla de revisión.
- **Sí:** el pie muestra solo el conteo y la paginación. **No:** «Retorno confirmado en esta lista». La lista está paginada y los docs prohíben calcular totales oficiales en el navegador. Si se quiere, va en otra spec con un agregado en `GET /api/records`.
- **Sí:** el `Select` de shadcn en lugar de `<select>` nativos, por decisión del usuario, para que los controles se vean iguales al resto del sistema.
- **Sí:** la opción vacía usa el valor interno `"all"`, que se traduce a `undefined` antes de llamar al backend. Así el control siempre tiene un valor seleccionado.
- **Sí:** el cambio entre tabla y tarjetas usa el ancho del contenedor (`@container`, 60 rem), no el de la ventana. El sidebar expandido, el colapsado y el drawer cambian el espacio real. Con la ventana habría anchos intermedios con scroll horizontal.
- **No:** `min-w` con scroll horizontal en la tabla, como hoy y como la plantilla. Incomoda a 1024 px con el sidebar abierto.
- **Sí:** el `<h1>` «Registros» es visible en todos los anchos. La plantilla móvil lo muestra, y a diferencia del Dashboard aquí no hay hero que lo sustituya.
- **Sí:** el buscador funcional vive en la página, porque el del topbar sigue deshabilitado (SPEC 16).
- **Sí:** se renderizan la tabla y la lista a la vez y CSS oculta una. Mantiene la pantalla como Server/Client Component simple, sin un hook de medidas que cause desajustes de hidratación.
- **Sí:** las fechas usan `formatDate` y los importes `formatMoney` (dos decimales), aunque la plantilla muestre «15/09/26» y «$9,800». Es el formato oficial del resto del sistema.
- **Sí:** los estados se pintan con `StatusBadge`, no con las clases `.s-*` de la plantilla.
- **Sí:** se mantiene la paginación Anterior/Siguiente de la pantalla actual. La plantilla no trae paginación.
- **No:** ordenar por columna. Cambiaría el contrato de la lista.

## Risks

| Risk | Mitigation |
| --- | --- |
| El `Select` de Base UI se comporta distinto al `<select>` nativo en móvil (listas de 53 semanas, selector del sistema). | El paso 7 revisa 375 px con el selector abierto. Si no es usable, la lista del popup tiene alto máximo con scroll y se registra la desviación en la spec. |
| El `Select` no admite una opción con valor vacío. | Se usa el valor interno `"all"` y una función que lo traduce a `undefined`. Una prueba unitaria cubre ese mapeo. |
| Las pruebas automáticas y el navegador no operan igual un `Select` que no es nativo. | Las verificaciones manuales usan teclado y clic. Los criterios que dependen de los filtros se comprueban en el navegador, no con `selectOption`. |
| `@container` y `@[60rem]` de Tailwind 4 pueden no estar disponibles en el tema actual. | El paso 5 lo confirma en la compilación. Si falla, se usan `lg` y `xl` de la ventana y se acepta un rango estrecho con tarjetas. |
| Dos listas en el DOM duplican enlaces. | La oculta usa `display: none`, así que no entra al árbol de accesibilidad ni al orden de Tab. Un criterio comprueba el recorrido con Tab. |
| `position: relative` en `<tr>` no cubre la fila en algún navegador. | El enlace de la fila usa el pseudo-elemento sobre la `<tr>` y el paso 7 lo comprueba haciendo clic en celdas lejanas al nombre. Si falla, la tabla pasa a una rejilla con `role="row"`, como la plantilla. |
| El FAB tapa la paginación o la última tarjeta. | `AppShell` ya reserva `9rem` abajo y el paso 7 lo revisa con la lista completa. |

## What is **not** in this spec

- Cambios de backend o de API.
- Total de retorno en el pie de la lista.
- Buscador del topbar, notificaciones y `⌘K` funcionales.
- Ordenar por columna.
- Filtros sincronizados con la URL después de cargar la pantalla.
- Rediseño de nuevo registro, detalle, entrega, Empresas y Reportes.
- Cambios visuales al sidebar, al topbar o a la barra inferior, más allá de la condición del FAB.

Cada uno de esos puntos, si se hace, va en su propia spec.
