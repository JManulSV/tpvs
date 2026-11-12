# SPEC 20 — Rediseño del detalle de registro

> **Status:** Approved
> **Depends on:** SPEC 05, SPEC 09, SPEC 10, SPEC 11, SPEC 15, SPEC 16, SPEC 19
> **Date:** 2026-11-11
> **Objective:** Reemplazar la pantalla `/records/{id}` por la interfaz de las plantillas `RecordDetailEditor{,Mobile}.dc.html` (encabezado con acciones, hero de totales, tarjetas de periodo, movimientos, imagen, entrega e historial), con tokens de shadcn y dentro del shell existente, sin cambiar el backend ni los flujos de edición, confirmación y cancelación.

## Why this spec exists

Hoy `RecordDetailScreen` (372 líneas) mezcla todo en una sola columna con bordes literales (`rounded-md border`), una tabla de movimientos con `min-w-[64rem]` que obliga a hacer scroll horizontal y un historial en otra tabla de `min-w-[64rem]`. Los botones de acción quedan al final de la página, lejos del estado del registro.

`docs/10_ui_ux.md` §6 define esta pantalla como la más importante del sistema para los registros que vienen de una imagen: el Editor revisa y confirma ahí. Las plantillas de `docs/design/templates/` aportan el diseño visual: acciones arriba, retorno oficial destacado y una columna lateral con imagen y entrega.

Las plantillas pintan dentro de la página el sidebar, la barra superior móvil y la barra inferior. Todo eso ya existe en el shell (SPEC 16), así que no se repite. Esta spec toma de las plantillas solo el **contenido** de la página, como SPEC 18 y SPEC 19.

Las plantillas también muestran cosas que el sistema no tiene: «Creado por», la lista de intentos de IA, «Reemplazar imagen» y la edición en línea con barra de guardado. Esta spec no las inventa; las deja fuera (ver Scope y Decisions).

## Scope

**In:**

- Rediseño de `RecordDetailScreen` y de sus piezas en `frontend/src/features/records/components/`, con `RecordDetailEditor.dc.html` (escritorio) y `RecordDetailEditorMobile.dc.html` (móvil) como referencia visual. Las dos plantillas tienen el mismo marcado; solo cambia el tamaño de la vista previa.
- Traducción de los colores literales de las plantillas a los tokens globales de SPEC 15 (`bg-card`, `bg-muted`, `bg-hero`, `text-on-hero`, `text-muted-foreground`, `bg-accent`, `text-accent-foreground`, `shadow-card`, `bg-status-*`, etc.). Esta spec no agrega tokens.
- Encabezado: enlace «Registros», `<h1>` con la empresa, insignias de estado y fuente, línea de periodo y botones de acción por rol.
- Hero «Retorno oficial» con ingreso y comisión, tomados de los totales que ya entrega el backend.
- Banner de contexto según estado y fuente.
- Tarjeta «Empresa y periodo» (solo lectura) con el mes del reporte.
- Tarjeta «Movimientos»: tabla en contenedores anchos y tarjetas en contenedores angostos (solo lectura).
- Tarjeta «Imagen recibida» con la imagen autorizada y sus datos de archivo.
- Tarjeta «Entrega»: estado, aviso de entrega desactualizada, resumen de solo lectura y un enlace a `/records/{id}/delivery`.
- «Historial de cambios» con el aspecto de línea de tiempo de la plantilla, con la misma consulta y paginación de hoy.
- Diseño de dos columnas cuando el contenedor es ancho y una columna cuando es angosto, con el orden de tarjetas de la plantilla.
- Estados de carga (skeleton), error con reintento y registro sin datos (importes y periodo vacíos).
- Se conservan los diálogos `RecordActionDialog` para confirmar y cancelar, y `ManualRecordForm` para editar y reabrir.
- Utilidades puras con pruebas unitarias.
- Documentar en `docs/frontend/features/records.md` y `docs/frontend/05_ui_and_accessibility.md`.
- Manejo de errores de sesión del shell cuando el backend no responde: `error.tsx` en `frontend/src/app/(app)/` con una tarjeta dentro de `AppShell` y «Reintentar» (ver paso 10).

**Out of scope (for future specs):**

- Tarjeta «Procesamiento automático» (estado, modelo, fechas y observaciones de la IA). Se considera información que hoy no aporta. El detalle deja de mostrar el diagnóstico de IA y sus observaciones. «Reintentar procesamiento» se conserva en el encabezado.
- Cambios de backend o de `RecordResource`. No hay `created_at`, `created_by` ni lista de intentos de IA, así que «Creado el… por…» e «Intento N» no se muestran.
- Edición en línea con barra fija «Guardar corrección». La edición sigue siendo `ManualRecordForm`.
- Panel de motivo dentro de la página. Confirmar y cancelar siguen en diálogos.
- «Reemplazar imagen» y «Adjuntar imagen» en el detalle.
- Chips por campo («Alta confianza», «Revisar», «Falta información», «Inconsistencia») y la franja «Documento vs Sistema» de `source_totals`.
- «Generar recibo (Word)» en el detalle. Sigue en `/records/{id}/delivery`.
- Agrupar el historial por evento. Se muestra una fila por cambio de campo, como hoy.
- Rediseño de `/records`, `/records/new`, `/records/{id}/delivery`, el formulario de edición, Empresas y Reportes.
- Cambios al sidebar, al topbar, a `AppMobileTopbar` y a `AppBottomNav`.

## Data model

Esta spec no agrega tablas, migraciones ni contratos de API. `GET /api/records/{id}`, `GET /api/records/{id}/history` y `GET /api/records/{id}/delivery` no cambian. Las acciones (`confirm`, `cancel`, `update`, reintento de IA) y el sondeo mientras el registro está `PENDING` o `PROCESSING` se conservan tal cual.

### Props

- `RecordDetailScreen` conserva `canEdit: boolean` y `recordId: number`.
- `app/(app)/records/[recordId]/page.tsx` no cambia (conserva `export const instant = false`, el `redirect` sin sesión y la validación del id).

### Utilidades puras — `frontend/src/features/records/record-detail-utils.ts`

```ts
export function getRecordTitle(record: Pick<RecordListItem, "company">): string;
export function getRecordPeriodLine(record: RecordListItem): string;
export function getReportMonthName(weekEndDate: string | null): string | null;
export function getHeroNote(status: RecordStatus): string;
export function getRecordBanner(record: Pick<RecordListItem, "status" | "source">, canEdit: boolean): RecordBanner | null;
export function getDeliveryCardState(
  status: RecordStatus,
  delivery: Delivery | null | undefined,
  canEdit: boolean,
): { chip: string; tone: "ready" | "stale" | "none"; actionLabel: string | null };
export function getHistoryDotClass(fieldName: string, newValue: string | null): string;

type RecordBanner = { tone: "info" | "review" | "cancel" | "error"; title: string; text: string | null };
```

- `getRecordTitle`: el nombre de la empresa o «Registro sin empresa».
- `getRecordPeriodLine`:
  - con semana, «Semana {N} · {formatDate(week_start_date)} – {formatDate(week_end_date)}»;
  - sin semana, «Sin periodo todavía».
- `getReportMonthName`: el nombre del mes de `week_end_date` en español («septiembre»), tomado de los dígitos de la cadena `YYYY-MM-DD` sin crear un `Date`. Devuelve `null` si es `null` o no es válida. Reutiliza `getMonthName` de `features/dashboard/utils.ts`.
- `getHeroNote` por estado:
  - `CONFIRMED`: «Registro confirmado. Se incluye en los totales y reportes oficiales.»;
  - `NEEDS_REVIEW`: «Pendiente de revisión. Todavía no cuenta en los totales.»;
  - `CANCELED`: «Registro cancelado. No se incluye en los reportes.»;
  - `PENDING` y `PROCESSING`: «Se mostrará cuando termine el procesamiento.»;
  - `ERROR`: «El procesamiento falló y no hay importes.».
- `getRecordBanner`:

| Estado, fuente y rol | Tono | Título | Texto |
| --- | --- | --- | --- |
| `NEEDS_REVIEW` + `IMAGE` + `EDITOR` | `info` | Información extraída automáticamente. Revisa antes de confirmar. | `null` |
| `NEEDS_REVIEW` + `MANUAL` + `EDITOR` | `review` | Pendiente de revisión. | Este registro todavía no está confirmado y no cuenta en los totales. |
| `NEEDS_REVIEW` + `VIEWER` (cualquier fuente) | `review` | Pendiente de revisión. | Este registro todavía no está confirmado y no cuenta en los totales. |
| `PENDING` / `PROCESSING` | `info` | El registro se está procesando. | Esta pantalla se actualiza automáticamente hasta que termine. |
| `ERROR` | `error` | El procesamiento falló. | Un Editor puede reintentar el procesamiento o cancelar el registro. |
| `CANCELED` | `cancel` | Registro cancelado. | No afecta los totales ni los reportes. La imagen ya no está disponible, pero los datos se conservan. |
| `CONFIRMED` | — | `null` | — |

- `getDeliveryCardState`:
  - estado distinto de `CONFIRMED`: chip «Bloqueada», tono `none`, sin acción;
  - `CONFIRMED` sin entrega: chip «Sin entrega», tono `none`; acción «Preparar entrega» para `EDITOR` y ninguna para `VIEWER`;
  - `CONFIRMED` con `is_stale`: chip «Desactualizada», tono `stale`; acción «Preparar nueva entrega» (`EDITOR`) o «Ver entrega» (`VIEWER`);
  - `CONFIRMED` con entrega vigente: chip «Preparada», tono `ready`; acción «Editar entrega» (`EDITOR`) o «Ver entrega» (`VIEWER`).
- `getHistoryDotClass`: color del punto de la línea de tiempo con tokens:
  - `record_created`: `bg-status-pending`;
  - `status` con valor nuevo `CONFIRMED`: `bg-status-confirmed`;
  - `status` con valor nuevo `NEEDS_REVIEW`: `bg-status-needs-review`;
  - `status` con valor nuevo `CANCELED`: `bg-status-canceled`;
  - `document_generated`, `delivery_date`, `delivered_by_name`, `received_by_name`, `prepared_return_amount` y `denominations[...]`: `bg-status-processing`;
  - cualquier otro: `bg-accent-foreground`.
- Los importes y fechas usan `formatMoney`, `formatDate` y `formatDateTime`. No se agrega ningún cálculo de comisión, retorno ni totales. El total de efectivo de la tarjeta de entrega usa `denominationsTotalCents` y `centsToAmount` de `features/deliveries/utils.ts`, los mismos de la pantalla de entrega.

## UI contract

### Fusión con el layout existente

El contenido se renderiza dentro del contenedor de `AppShell` (`max-w-6xl`, `px-4 pt-4` y el espacio inferior para la barra en móvil). La página no agrega margen exterior ni fondo propio.

| Elemento de la plantilla | Qué se hace |
| --- | --- |
| Sidebar azul, marca, usuario y «Cerrar sesión» | Se descarta. Se usa `AppSidebar`. |
| Contador junto a «Registros» | Se descarta. Se usa `NeedsReviewNavBadge`. |
| Topbar móvil y barra inferior | Se descartan. Se usan `AppMobileTopbar` y `AppBottomNav`. |
| Enlace «Registros», `<h1>`, insignias y botones de acción | Se implementan en el encabezado de la página. |
| «Creado el… por…» | Se descarta (sin dato). |
| Panel de motivo (cancelar y reabrir) | Se descarta. Se usan los diálogos actuales. |
| Leyenda de estados de campo y chips por campo | Se descartan (fuera de alcance). |
| Barra fija «Cambios sin guardar» | Se descarta. La edición usa `ManualRecordForm`. |
| Tarjeta «Procesamiento automático» y lista de «Intentos» | Se descartan (fuera de alcance). |
| Botón «Reprocesar imagen» de esa tarjeta | Pasa al encabezado como «Reintentar procesamiento». |
| «Reemplazar imagen» | Se descarta. |
| Hero, tarjetas de periodo, movimientos, imagen, entrega e historial | Se implementan aquí. |

### Estructura y orden

- `<section aria-labelledby="record-detail-heading">` con 24 px de separación entre bloques (16 px en móvil).
- El orden es: encabezado, banner, y luego el contenido en tarjetas.
- El contenedor del contenido usa `@container`. El diseño depende del ancho del contenedor y no del de la ventana, porque el sidebar cambia el espacio disponible.
- **Contenedor ≥ 60 rem:** dos columnas, `grid-cols-[minmax(0,1fr)_340px]` con 24 px de separación.
  - Columna principal: hero, «Empresa y periodo», «Movimientos» e «Historial de cambios», cada uno en su fila.
  - Columna lateral: «Imagen recibida» y «Entrega», que ocupa las cuatro filas y empieza junto al hero. Va en el mismo orden que en una columna.
- **Contenedor < 60 rem:** una sola columna. El orden del DOM es también el orden visual y el de Tab, así que no se usan clases `order-*`:

| Orden | Tarjeta |
| --- | --- |
| 1 | Hero |
| 2 | Empresa y periodo |
| 3 | Movimientos |
| 4 | Imagen (si el estado es `NEEDS_REVIEW`) o Entrega (en los demás) |
| 5 | Entrega (si es `NEEDS_REVIEW`) o Imagen (en los demás) |
| 6 | Historial de cambios |

- Las clases de rejilla (`col-start-*`, `row-start-*`, `row-span-4`) se escriben completas en el código, sin construirlas con texto.

### Encabezado

- Enlace «Registros» con `ChevronLeft`, hacia `/records`, en `text-accent-foreground`. Alto mínimo de 32 px desde `md` y de 44 px en móvil.
- `<h1 id="record-detail-heading">`: `getRecordTitle`, 28/36 px desde `md` y 24/32 px en móvil, 700, `tracking-[-0.01em]`. Es el único `<h1>`.
- Debajo, una fila que envuelve (`flex-wrap`) con:
  - `StatusBadge`;
  - insignia de fuente con icono `Image` o `Pencil` (`aria-hidden`) y «Imagen» o «Manual», en `border bg-card text-muted-foreground`;
  - `getRecordPeriodLine` con `tabular-nums` en `text-muted-foreground`.
- Acciones a la derecha desde `md`; debajo del título y a todo el ancho en móvil (alto de 44 px, `flex-1`). Todas son píldoras de 40 px.
  - «Reintentar procesamiento» (`canRetryAiProcessing`, solo `ERROR` de imagen): contorneado, icono `RefreshCw`. Muestra «Reintentando…» y se deshabilita mientras `retryAiProcessing.isPending`. Va primero.
  - «Cancelar registro» (`canCancel`): borde y texto `text-status-error`, hover `bg-status-error-bg`, icono `Ban`. Abre el diálogo de cancelar.
  - «Confirmar registro» (`canConfirm`): primario, icono `Check`. Abre el diálogo de confirmar.
  - «Editar registro» (`canCorrect`, estado `NEEDS_REVIEW`) o «Reabrir para corregir» (`canCorrect`, estado `CONFIRMED`): `bg-accent text-accent-foreground`, icono `Pencil` o `RotateCcw`. Abre `ManualRecordForm` como hoy.
- Los permisos salen de `getRecordActionPermissions` sin cambios. El `VIEWER` no ve ninguna acción.

### Banner

- Solo si `getRecordBanner` no devuelve `null`.
- `role="status"` para `info`, `review` y `cancel`; `role="alert"` para `error`.
- Fila con icono (`Info` o `CircleAlert`, `aria-hidden`), título en 700 y texto en 500, 13/20 px, `rounded-lg`, padding de 14/16 px.
- Colores: `info` con `bg-accent text-accent-foreground`; `review` con `bg-status-needs-review-bg text-status-needs-review`; `cancel` con `bg-status-canceled-bg text-status-canceled`; `error` con `bg-status-error-bg text-status-error`.
- El mensaje de éxito de una acción (`successMessage`) y el error de reintento se muestran justo debajo del banner con el mismo estilo (`info` y `error`).
- La nota «Puedes consultar este registro. Solo un Editor…» para el `VIEWER` se sustituye por el texto del banner de `ERROR`. La nota de `CONFIRMED` para el Editor se sustituye por el diálogo.

### Hero

- `<section aria-label="Totales del registro">` con `bg-hero text-on-hero rounded-xl`. Padding de 28/32 px desde `md` y de 24/20 px en móvil.
- Desde `md`, rejilla de dos columnas (`minmax(0,1fr) auto`) con `items-end`. En móvil, una columna.
- Izquierda:
  - overline «Retorno oficial» (11/16 px, 600, mayúsculas, `tracking-[0.08em]`, opacidad 85 %);
  - monto `formatMoney(return_total)` en 40/48 px desde `md` y 32/40 px en móvil, 700, `tabular-nums`; «—» si es `null`;
  - `getHeroNote(status)` en 13/20 px, opacidad 85 %.
- Derecha: `<dl>` con «Ingreso» y «Comisión» (`income_total`, `commission_total`), con el `dt` como overline y el `dd` en 20/28 px, 700, `tabular-nums`. En móvil se alinea a la izquierda con 24 px de separación.
- Sin patrón de puntos. Los totales son los del backend; no se suma nada en el cliente.

### Tarjeta «Empresa y periodo»

- Tarjeta `rounded-lg border bg-card shadow-card`, `<h2>` «Empresa y periodo» (16/24 px, 600) y subtítulo «La semana se identifica por semana y año.» en 12/16 px.
- Rejilla `grid-cols-[repeat(auto-fit,minmax(150px,1fr))]` con una lista de definiciones (`<dl>`): Empresa, Año, Semana, Inicio y Fin.
  - La etiqueta es de 12/16 px, 600, `text-muted-foreground`.
  - El valor es de 15/24 px, 600, con `tabular-nums` en las cifras.
  - Los valores ausentes se muestran como «—».
  - Fechas con `formatDate`.
- Pie en 12/16 px: «Mes del reporte: **{mes}**. Cuando una semana cruza dos meses se usa el mes de la fecha de fin.» con `getReportMonthName`. Si es `null`, solo la segunda frase.
- Si hay `companyHint` (empresa detectada en la imagen y registro sin empresa), una nota `bg-accent text-accent-foreground` bajo la rejilla: «Empresa detectada en la imagen: **{nombre}**. Selecciónala al corregir el registro si corresponde.»

### Tarjeta «Movimientos»

- Tarjeta con `@container`, `overflow-hidden`, `<h2>` «Movimientos» y subtítulo «La comisión y el retorno los calcula el servidor.».
- **Contenedor ≥ 45 rem:** tabla semántica con el primitivo `table` de shadcn.
  - `<caption class="sr-only">`, sin `min-w` ni scroll horizontal.
  - Encabezados de 11/16 px, 600, mayúsculas, `tracking-[0.08em]`, sobre `bg-muted`.
  - Columnas: `#` (círculo de 28 px con el orden), Afiliación, Sucursal, Ingreso, Com. %, Comisión, Retorno. Las cifras van a la derecha con `tabular-nums` y el Retorno en 700.
- **Contenedor < 45 rem:** `<ul>` de tarjetas, una por movimiento.
  - Encabezado «Movimiento N» en 700.
  - Rejilla de dos columnas con la etiqueta de 11/16 px en mayúsculas sobre cada valor: Afiliación, Sucursal, Ingreso, Comisión %, Comisión y Retorno.
- Se renderizan la tabla y la lista en el DOM y CSS oculta una con `display: none`, como en SPEC 19.
- Sin movimientos: «Todavía no hay movimientos.» en `text-muted-foreground`, dentro de la tarjeta.
- Valores `null`: «—». El porcentaje se muestra como `{n}%`.
- Sin botones «Agregar movimiento» ni «Quitar movimiento» (la edición usa `ManualRecordForm`).

### Tarjeta «Imagen recibida»

- `<h2>` «Imagen recibida».
- Con imagen y estado distinto de `CANCELED`: la imagen actual con `next/image` (`unoptimized`, `recordsService.imageUrl`), `rounded-md border`, `max-h-[32rem]` y `object-contain` sobre `bg-muted`. Debajo, una lista de definiciones con Archivo (`wide`), Tipo y Tamaño (`formatFileSize`).
- «Ver imagen completa»: enlace `target="_blank"` con `rel="noopener noreferrer"` a `recordsService.imageUrl(id)`, estilo de botón contorneado de 36 px.
- `CANCELED`: texto «La imagen ya no está disponible.» en `text-muted-foreground`.
- Sin imagen (registro manual): texto «Este registro no tiene imagen asociada.» en `text-muted-foreground`.

### Tarjeta «Entrega»

- `<h2>` «Entrega» e insignia con `getDeliveryCardState().chip`: `ready` con `bg-status-confirmed-bg text-status-confirmed`, `stale` con `bg-status-needs-review-bg text-status-needs-review` y `none` con `bg-status-pending-bg text-status-pending`.
- El componente que llama a `useDelivery(recordId)` se monta solo cuando el estado es `CONFIRMED`, para no pedir la entrega de registros que no la admiten (mismo criterio que el `DeliveryLink` actual).
- **Estado distinto de `CONFIRMED`:** fila con icono `Lock` y el texto «La entrega se prepara cuando el registro está confirmado.»
- **`CONFIRMED`, cargando:** tres líneas `Skeleton`.
- **`CONFIRMED`, error de la consulta:** `role="alert"` con el mensaje y «Reintentar».
- **`CONFIRMED` sin entrega:** «Todavía no hay una entrega preparada para este registro.» y la acción (solo `EDITOR`).
- **`CONFIRMED` con entrega:**
  - si `is_stale`, bloque `role="alert"` con `bg-status-needs-review-bg text-status-needs-review`, el texto «La entrega se preparó con un monto anterior.» y la comparación «Retorno actual» (`current_return_amount`) contra «Entrega preparada» (`prepared_return_amount`);
  - lista de definiciones: Fecha de entrega (`formatDate`), Monto preparado, Entrega (`delivered_by_name`) y Recibe (`received_by_name` o «—»);
  - tabla «Desglose de efectivo» con Denominación, Cant. y Total por fila, y «Efectivo total» al final. El total de cada fila es la cantidad por el valor de la denominación;
  - fila con icono `CircleCheck` «Imagen asociada: lista para generar el recibo» solo si el registro tiene imagen. Sin imagen: «Falta una imagen asociada para generar el recibo.» en `text-muted-foreground`.
- La acción es un `Link` a `/records/{id}/delivery`, con el estilo de botón primario de 40 px a todo el ancho de la tarjeta. Si `actionLabel` es `null`, no se pinta.
- Las clases de color usan solo tokens, nunca `bg-red-*` ni `#hex`.

### Historial de cambios

- Tarjeta con `<h2 id="record-history-heading">` «Historial de cambios» y subtítulo «Quién cambió qué y por qué. No se puede editar.».
- Lista ordenada `<ol>` (línea de tiempo): una fila por entrada, con un punto de 12 px (`aria-hidden`, color de `getHistoryDotClass`) y una línea vertical `bg-border` entre filas.
  - Primera línea: `fieldLabel(field_name)` en 600 y, a la derecha, «{usuario} · {formatDateTime}» en 12/16 px `text-muted-foreground`.
  - Segunda línea: «{anterior} → {nuevo}» con `displayValue` y `tabular-nums`.
  - Tercera línea: «Motivo: {motivo}» en `text-muted-foreground`.
- Se conservan `fieldLabel`, `displayValue`, la consulta `useRecordHistory`, `PAGE_SIZE = 20` y la paginación «Anterior / Página X de Y / Siguiente». Los botones miden al menos 44 px de alto en móvil.
- Estados: «Cargando historial…» (`role="status"`), error con `role="alert"` y «Reintentar», y «Sin cambios registrados.».
- Se elimina la tabla de `min-w-[64rem]`. Ya no se muestra el correo del usuario.

### Estados de la pantalla

- **Cargando:** `aria-live="polite"` con «Cargando registro…» (`sr-only`) y esqueletos del encabezado, el hero y dos tarjetas. No se pinta ningún importe.
- **Error:** una tarjeta `role="alert"` con «No se pudo cargar el registro», el mensaje del error (o «El registro no está disponible.»), `Button` «Reintentar» y enlace «Volver a registros».
- **Datos en caché con error al refrescar:** se mantienen los datos y no se muestra la alerta, como en SPEC 18 y SPEC 19.
- **Registro de imagen sin datos todavía** (`PENDING`, `PROCESSING` o `ERROR`): el hero muestra «—» y las tarjetas de periodo y movimientos muestran «—» y «Todavía no hay movimientos.».

### Diferencias por rol

| Elemento | EDITOR | VIEWER |
| --- | --- | --- |
| Hero, periodo, movimientos, imagen, entrega e historial | Sí | Sí |
| Cancelar, Confirmar, Editar / Reabrir | Según estado | No |
| Reintentar procesamiento | Según estado | No |
| Entrega: acción | Preparar / Preparar nueva / Editar | Ver entrega (si existe) |

El backend sigue siendo la autoridad. El rol solo decide qué se muestra.

## Implementation plan

1. **Utilidades puras.**
   - Crear `features/records/record-detail-utils.ts`.
   - Crear `frontend/tests/record-detail-utils.test.ts` con:
     - `getRecordTitle` con y sin empresa;
     - `getRecordPeriodLine` con y sin semana;
     - `getReportMonthName` con una semana que cruza meses, con `null` y con una cadena inválida;
     - `getHeroNote` para los seis estados;
     - `getRecordBanner` para las seis combinaciones de estado y fuente de la tabla;
     - `getDeliveryCardState` para los cuatro casos y los dos roles;
     - `getHistoryDotClass` para los casos de la lista.
   - Verificar con `npm test`. No hay cambio visible todavía.
2. **Encabezado, banner y hero.**
   - Crear `components/record-detail-header.tsx`, `components/record-detail-banner.tsx` y `components/record-detail-hero.tsx`.
   - Montarlos en `RecordDetailScreen` en lugar del `<header>`, los avisos sueltos y la rejilla de `Info`. El resto de la pantalla sigue igual y funcional.
   - Los botones de acción pasan al encabezado y usan los mismos manejadores `openConfirmDialog`, `openCancelDialog` y `setIsEditing`. Se elimina el bloque de botones del final.
3. **Periodo y movimientos.**
   - Crear `components/record-period-card.tsx` y `components/record-movements-card.tsx` (tabla y lista).
   - Montarlos en lugar de la rejilla de `Info` y de la tabla de `min-w-[64rem]`.
   - Verificar a mano con un registro de varios movimientos y con uno de imagen sin datos.
4. **Imagen.**
   - Crear `components/record-image-card.tsx` y montarla en lugar de la sección de imagen.
   - Eliminar el bloque «Diagnóstico de IA» de la pantalla.
5. **Entrega.**
   - Crear `components/record-delivery-card.tsx`, con un componente interno que llama a `useDelivery`, y montarlo en lugar de `DeliveryLink`.
   - Verificar los cuatro casos: sin entrega, vigente, desactualizada y registro no confirmado.
6. **Historial.**
   - Reescribir el marcado de `record-history-panel.tsx` como línea de tiempo con `getHistoryDotClass`, conservando `fieldLabel`, `displayValue`, la consulta y la paginación.
7. **Composición, estados y orden.**
   - Reescribir el retorno de `RecordDetailScreen`: contenedor `@container`, columnas y posiciones de rejilla según la sección «Estructura y orden».
   - Reescribir los estados de carga y error con los tokens.
   - Eliminar `Info`, `DeliveryLink`, `aiStatusLabels` y el bloque de diagnóstico de IA.
   - Verificar `npx tsc --noEmit` y `npm run lint`.
8. **Revisión visual.**
   - Revisar `/records/{id}` como `EDITOR` y `VIEWER`, en claro y oscuro, a 1440, 1024, 768 y 375 px, con el sidebar expandido y colapsado.
   - Probar un registro en cada estado (`PENDING`, `PROCESSING`, `NEEDS_REVIEW` de imagen, `NEEDS_REVIEW` manual, `ERROR`, `CONFIRMED` con y sin entrega, entrega desactualizada y `CANCELED`).
   - Comparar con las dos plantillas.
   - Probar la carga con red lenta y el error deteniendo el backend.
   - Ajustar solo espaciados y tamaños.
9. **Documentación.**
   - Actualizar `docs/frontend/features/records.md`: estructura del detalle, orden de tarjetas, acciones por rol y qué queda fuera.
   - Actualizar `docs/frontend/05_ui_and_accessibility.md`: el detalle usa el ancho del contenedor para elegir columnas y tabla o tarjetas, y el historial es una línea de tiempo.

10. **Errores de sesión con el backend caído.**
   - Crear `frontend/src/app/(app)/error.tsx` como componente cliente (`"use client"`), con las props `error` y `reset`. No se toca `page.tsx` ni `app/(app)/layout.tsx`.
   - Mostrar una tarjeta dentro de `AppShell` con el contenido de «UI contract» de esta sección: título «No se pudo cargar esta página.», texto «Hubo un problema al validar tu sesión o al cargar los datos. Inténtalo de nuevo.», botón «Reintentar» (`reset()`) y enlace «Ir al dashboard».
   - Registrar el error con `console.error` y no mostrar `error.message`.
   - Verificar con el backend detenido: navegar dentro de la app a `/records/{id}` debe mostrar la tarjeta y no la página «This page couldn't load» de Next. Iniciar el backend, pulsar «Reintentar» y confirmar que la página carga. Una carga completa con el backend detenido debe seguir mostrando el mensaje de `app/(app)/layout.tsx` sin cambios.

## Acceptance criteria

- [ ] `/records/{id}` tiene exactamente un `<h1>` con el nombre de la empresa (o «Registro sin empresa»), visible a 1440 px y a 375 px.
- [ ] El encabezado muestra el enlace «Registros», el `StatusBadge`, la insignia de fuente y la línea de periodo («Semana N · inicio – fin» o «Sin periodo todavía»).
- [ ] Como `EDITOR` con un registro `NEEDS_REVIEW`, el encabezado muestra «Cancelar registro», «Confirmar registro» y «Editar registro». Con `CONFIRMED` muestra solo «Reabrir para corregir».
- [ ] Como `EDITOR` con un registro `ERROR`, el encabezado muestra «Cancelar registro» y, si la fuente es `IMAGE`, también «Reintentar procesamiento».
- [ ] Como `VIEWER`, no aparece ningún botón de acción en el encabezado ni «Reintentar procesamiento».
- [ ] Confirmar, cancelar (con motivo obligatorio), reabrir para corregir y reintentar siguen funcionando con los mismos diálogos y formulario. Tras un `409` el registro se refresca.
- [ ] El hero muestra «Retorno oficial» con `formatMoney(return_total)`, y los importes de Ingreso y Comisión salen de `income_total` y `commission_total`. Con totales `null` muestra «—». La página no suma ningún importe en el cliente.
- [ ] Un registro `NEEDS_REVIEW` de imagen muestra al `EDITOR` el banner «Información extraída automáticamente. Revisa antes de confirmar.» y al `VIEWER` el banner «Pendiente de revisión.».
- [ ] El banner de cada estado coincide con la tabla de `getRecordBanner`, y un `CONFIRMED` no muestra banner.
- [ ] La tarjeta «Empresa y periodo» muestra el mes del reporte («septiembre» para una semana que termina el 15/09) y «—» en los valores ausentes.
- [ ] Con el sidebar expandido a 1280 px, la tabla de movimientos no genera scroll horizontal. Si el contenedor de la tarjeta mide menos de 45 rem, se muestran tarjetas.
- [ ] A 375 px, la página no tiene scroll horizontal.
- [ ] Con el contenedor de 60 rem o más hay dos columnas (principal y lateral de 340 px). Por debajo hay una columna con el orden: hero, periodo, movimientos, imagen y entrega (en `NEEDS_REVIEW`) o entrega e imagen (en los demás estados) e historial.
- [ ] La tarjeta de imagen muestra la imagen autorizada y su archivo, tipo y tamaño; para `CANCELED` dice «La imagen ya no está disponible.» y para un registro manual sin imagen dice «Este registro no tiene imagen asociada.».
- [ ] La tarjeta «Entrega» muestra «Bloqueada» y el candado en registros no confirmados, y no llama a `GET /api/records/{id}/delivery` en ese caso.
- [ ] En un registro `CONFIRMED` sin entrega, el `EDITOR` ve «Preparar entrega» y el `VIEWER` no ve acción.
- [ ] En un registro con entrega desactualizada se muestra «La entrega se preparó con un monto anterior.», el retorno actual contra la entrega preparada y, para el `EDITOR`, «Preparar nueva entrega».
- [ ] Con entrega vigente se muestran fecha, monto preparado, entrega, recibe, el desglose de efectivo y el total, y el enlace lleva a `/records/{id}/delivery` («Editar entrega» o «Ver entrega» según el rol).
- [ ] El detalle no muestra ninguna tarjeta ni bloque de «Procesamiento automático» ni «Diagnóstico de IA».
- [ ] El historial es una línea de tiempo con campo, usuario, fecha, valor anterior → nuevo y motivo. Mantiene carga, error, vacío y paginación, y recarga tras confirmar, corregir o cancelar.
- [ ] Mientras carga, aparecen esqueletos y nunca un importe. Con el backend detenido aparece una alerta con `role="alert"` y «Reintentar» vuelve a hacer la petición.
- [ ] Un registro `PENDING` o `PROCESSING` sigue actualizándose solo y deja de hacerlo al llegar a un estado final.
- [ ] Todos los controles, enlaces y botones se alcanzan con Tab y muestran un anillo de foco de 2 px.
- [ ] Los archivos nuevos o modificados de `features/records/` solo usan clases de tokens, sin colores literales ni de la paleta de Tailwind.
- [ ] La consola del navegador no muestra advertencias de hidratación en `/records/{id}`.
- [ ] `npm run lint`, `npx tsc --noEmit`, `npm test` y `npm run build` en `frontend/` pasan sin errores.
- [ ] La revisión visual del paso 8 se hizo en claro y oscuro, a 1440, 1024, 768 y 375 px, sin texto cortado ni solapamientos.
- [ ] `docs/frontend/features/records.md` y `docs/frontend/05_ui_and_accessibility.md` describen el nuevo comportamiento.
- [ ] Con el backend detenido, navegar dentro de la app a `/records/{id}` muestra la tarjeta «No se pudo cargar esta página.» con `role="alert"` y no la página de error de Next.
- [ ] La tarjeta de error ofrece «Reintentar», que vuelve a cargar la página cuando el backend está disponible, y «Ir al dashboard».
- [ ] Una carga completa con el backend detenido sigue mostrando «No se pudo validar la sesión con el servidor…» sin cambios.
- [ ] La tarjeta de error aparece dentro de `AppShell`: el sidebar y la barra superior siguen visibles.
- [ ] El botón «Reintentar» de la tarjeta de error recibe foco con Tab y muestra el anillo de foco de 2 px.
- [ ] No se modifican archivos `page.tsx` ni `app/(app)/layout.tsx`.

## Decisions

- **Sí:** el detalle es de solo lectura y la edición sigue siendo `ManualRecordForm`, por decisión del usuario. Es el cambio mínimo, no hay lógica de «cambios sin guardar» y se conservan la validación y el motivo obligatorio que ya funcionan.
- **No:** edición en línea con barra fija «Guardar corrección», como la plantilla. Es una reescritura grande del formulario y de su validación.
- **Sí:** confirmar y cancelar siguen en `RecordActionDialog`, por decisión del usuario. Ya cubren motivo, errores y `409`, y solo cambia dónde están los botones.
- **No:** panel de motivo dentro de la página. Habría que reescribir los dos flujos.
- **Sí:** «Reabrir para corregir» y «Editar registro» abren el mismo formulario. El texto depende del estado, como en la plantilla, y el formulario ya pide el motivo y explica que guardar reabre el registro.
- **Sí:** se omiten «Creado por», los intentos de IA, «Reemplazar imagen», los chips por campo y «Documento vs Sistema», por decisión del usuario. No hay datos en el backend o requieren más lógica en el cliente.
- **No:** ampliar `RecordResource`. Cambiaría el contrato de la API y se sale del objetivo.
- **No:** tarjeta «Procesamiento automático», por decisión del usuario. Estado, modelo, fechas y observaciones de la IA no aportan hoy al Editor, que revisa contra la imagen y los datos.
- **Sí:** «Reintentar procesamiento» pasa al encabezado, junto a «Cancelar registro», para no perder la única acción de recuperación de un registro `ERROR` de imagen.
- **Sí:** la tarjeta de entrega es un resumen de solo lectura con enlace, por decisión del usuario. Evita duplicar la lógica de importe y cambio de `delivery-document-action`.
- **No:** «Generar recibo (Word)» en el detalle. Duplicaría las reglas de `CASH_MISMATCH` y `CHANGE_REQUIRED`.
- **Sí:** el componente que llama a `useDelivery` solo se monta con `CONFIRMED`. Evita una petición inútil y conserva el comportamiento del `DeliveryLink` actual.
- **Sí:** el total de efectivo de la entrega usa `denominationsTotalCents` y `centsToAmount`, los mismos helpers de la pantalla de entrega. No es un total oficial: es un resumen de lo que el backend ya guardó.
- **Sí:** el cambio entre una y dos columnas, y entre tabla y tarjetas de movimientos, usa el ancho del contenedor (`@container`, 60 rem y 45 rem). El sidebar expandido, el colapsado y el drawer cambian el espacio real.
- **Sí:** el orden de las tarjetas en una columna sigue a la plantilla, con imagen antes que entrega solo en `NEEDS_REVIEW`. Es donde el Editor necesita la imagen junto a los datos. El orden está en el DOM y no con `order-*`, para que Tab siga el orden visual. La columna lateral de dos columnas usa el mismo orden.
- **Sí:** se renderizan la tabla y la lista de movimientos a la vez y CSS oculta una, como en SPEC 19. Evita un hook de medidas y los desajustes de hidratación.
- **Sí:** el historial es una línea de tiempo con una fila por cambio de campo, sin agrupar por evento. El backend entrega filas por campo y agrupar sería inferir eventos en el cliente.
- **No:** mostrar el correo del usuario en el historial. La plantilla solo muestra el nombre.
- **Sí:** el banner «Información extraída automáticamente. Revisa antes de confirmar.» es solo para el `EDITOR` en imágenes `NEEDS_REVIEW`, como en la plantilla. Es la pantalla donde revisa. El `VIEWER` solo consulta y ve «Pendiente de revisión.».
- **Sí:** el detalle de cada rol es el mismo componente con distinto contenido. Las plantillas solo traen la versión del Editor y su variante `rol: viewer` (solo lectura, sin acciones). No hay una plantilla propia del Viewer para esta pantalla.
- **Sí:** los estados se pintan con `StatusBadge` no con las clases `.s-*` ni `.pill` de la plantilla.
- **Sí:** los importes y fechas usan `formatMoney` y `formatDate` (dos decimales y `dd/mm/aaaa`), aunque la plantilla muestre «$9,800» y «15/09/26». Es el formato oficial del resto del sistema.
- **Sí:** no se repiten en la página el sidebar, la barra móvil ni la barra inferior de la plantilla. Ya existen en el shell.

- **Sí:** el manejo de errores de sesión va en `error.tsx` de `(app)`. Es el cambio más pequeño, cubre todas las páginas autenticadas y no toca `page.tsx`. Cambiar `getAuthenticatedUser` a un resultado tipado queda fuera de esta spec, porque obligaría a tocar cada página.
- **Sí:** el mensaje de la tarjeta de error es genérico. Next oculta el mensaje de los errores de servidor en producción, así que no se puede distinguir de forma fiable un fallo de sesión de otro error.
- **Sí:** «Reintentar» llama a `reset()` y no a `location.reload()`, para que Next vuelva a renderizar el segmento sin perder el estado del shell.

## Risks

| Risk | Mitigation |
| --- | --- |
| El posicionamiento de la rejilla puede alterar el orden de Tab o de lectura. | El DOM sigue el orden de una columna y las celdas de dos columnas se fijan con `col-start` y `row-start`. El paso 8 compara el orden del DOM con el visual en las dos presentaciones. |
| Las clases de rejilla pueden no generarse si se construyen con texto. | Se escriben completas en el código y se confirma en la compilación. |
| `@container` y `@[60rem]` de Tailwind 4 ya se usan en SPEC 19, pero la tarjeta de movimientos anida un segundo contenedor (`@[45rem]`). | El paso 3 lo verifica a mano a 1024 y 1280 px. Si falla, se usa el mismo ancho de contenedor para ambas decisiones. |
| `useDelivery` en un registro `CONFIRMED` con error de red deja la tarjeta sin datos. | La tarjeta muestra su propio error con «Reintentar» y no bloquea el resto de la pantalla. |
| La imagen es grande y empuja la columna lateral. | `max-h-[32rem]` con `object-contain`, como hoy. |
| El historial muestra muchas filas y alarga la página. | Se conserva la paginación de 20 y el historial va al final. |
| Quitar la tabla de historial pierde la columna de correo. | Es una decisión aceptada; el nombre del usuario basta para el MVP. |
| Un registro reabierto (`NEEDS_REVIEW` manual con entrega previa) muestra «Bloqueada». | Es coherente con `docs/10_ui_ux.md` §8: la entrega solo está disponible tras `CONFIRMED`. La entrega previa se conserva y se ve de nuevo al reconfirmar. |
| `error.tsx` no captura errores lanzados en `app/(app)/layout.tsx`. | Ese layout ya captura el error de sesión con su propio `try/catch`. El paso 10 lo verifica con el backend detenido en carga completa. |

## What is **not** in this spec

- Tarjeta «Procesamiento automático» o cualquier diagnóstico de IA en el detalle.
- Cambios de backend o de `RecordResource` (creado por, intentos de IA).
- Tipado de `getAuthenticatedUser` o páginas de «sesión no disponible».
- Reintento automático de la sesión.
- Edición en línea y barra «Guardar corrección».
- Panel de motivo dentro de la página.
- «Reemplazar imagen» y «Adjuntar imagen».
- Chips por campo y comparación «Documento vs Sistema».
- «Generar recibo (Word)» dentro del detalle.
- Historial agrupado por evento.
- Rediseño de `/records`, `/records/new`, `/records/{id}/delivery`, Empresas y Reportes.
- Cambios visuales al sidebar, al topbar o a la barra inferior.

Cada uno de esos puntos, si se hace, va en su propia spec.
