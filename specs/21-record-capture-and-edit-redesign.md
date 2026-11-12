# SPEC 21 — Rediseño de captura, revisión y edición de registros

> **Status:** Approved
> **Depends on:** SPEC 03, SPEC 04, SPEC 05, SPEC 06, SPEC 08, SPEC 09, SPEC 15, SPEC 16, SPEC 19, SPEC 20
> **Supersedes (parcial):** SPEC 09 en la redirección tras el `202` y en la edición; SPEC 20 en la edición en línea, el panel de cancelar, los chips por campo, «Procesamiento automático» y «Documento vs Sistema» (ver Decisions)
> **Date:** 2026-11-12
> **Objective:** Rediseñar `/records/new` y el detalle editable de `/records/{id}` con las plantillas `RecordNewManual`, `RecordNewImageSplit` y `RecordEdit`, con tokens de shadcn, y con estados visibles para la subida de la imagen y para la lectura por IA, sin cambiar el backend ni las reglas de negocio.

## Why this spec exists

Hoy `/records/new` (`image-record-form.tsx`) sube la imagen, recibe el `202` y redirige de inmediato a `/records/{id}`, así que el Editor no ve la subida ni la lectura. El formulario manual (`manual-record-form.tsx`) no tiene tarjetas ni jerarquía visual. La edición vive en un diálogo y un formulario separado (SPEC 20).

Las plantillas nuevas en `docs/design/templates/` cubren esas pantallas:

- `RecordNewManual.dc.html` (y `RecordNewManualMobile`): alta manual con validación, resumen tras guardar y «Crear otro registro».
- `RecordNewImageSplit.dc.html` (y `RecordNewImageSplitMobile`): alta desde imagen con imagen a la izquierda y datos extraídos editables a la derecha, con estados de subida, cola, lectura, listo y error.
- `RecordEdit.dc.html` (y `RecordEditMobile`): revisión y corrección en línea con chips por campo, barra «Guardar corrección», cancelar con motivo y la tarjeta de procesamiento automático.

Las versiones móviles son envoltorios que importan el componente de escritorio con una vista (`errores`, `inconsistencia`, `paso="listo"`) y un viewport de 390 px. Usan el mismo marcado.

Esta spec sigue las plantillas, con los cambios que impone el sistema (ver Decisions). Las plantillas simulan tiempos y estados de IA con temporizadores; el sistema no los tiene, así que no se reproducen.

## Scope

**In:**

- `/records/new` con selector **Captura manual** / **Desde imagen** y el layout de `RecordNewManual` y `RecordNewImageSplit`.
- Alta manual: tarjetas «Empresa y periodo» y «Movimientos», columna lateral «Qué valida el servidor», y resumen tras guardar con «Ver registro», «Preparar entrega» y «Crear otro registro».
- Alta desde imagen: panel de imagen (izquierda) y datos extraídos (derecha), con estados de subida, cola, lectura, listo y error (ver «Estados de la carga y lectura de imagen»).
- Progreso real de la subida con `onUploadProgress` de axios, y cancelación de la subida con `AbortController`.
- Revisión lado a lado en `/records/new` de un registro `IMAGE` en `NEEDS_REVIEW`: confirmar y corregir ahí mismo.
- `/records/{id}` con el layout de `RecordEdit`:
  - edición en línea para `NEEDS_REVIEW` (manual e imagen) y para `CONFIRMED` con reapertura al guardar;
  - barra «Guardar corrección» con motivo obligatorio, visible solo con cambios;
  - cancelar con panel y motivo obligatorio (`NEEDS_REVIEW` y `ERROR`);
  - confirmar con diálogo (`RecordActionDialog`, como SPEC 20).
- Chips por campo (`Alta confianza`, `Revisar`, `Falta información`, `Inconsistencia`) solo en registros `IMAGE` en `NEEDS_REVIEW`, derivados de `ai_processing.issues` (ver Data model).
- Tarjeta «Procesamiento automático»: estado, proveedor, modelo, inicio, fin e issues de la última extracción. Sin lista de intentos.
- Franja «Documento vs Sistema»: **fuera de esta spec** (ver Decisions). No se implementa.
- Reintentar lectura en `ERROR` (`POST /api/records/{id}/ai-processing/retry`).
- Utilidades puras con pruebas unitarias.
- Documentar en `docs/frontend/features/records.md`, `docs/frontend/05_ui_and_accessibility.md` y `docs/10_ui_ux.md` §6 y §9.

**Out of scope (for future specs):**

- Cambios de backend, API, migraciones o reglas de negocio. Los endpoints de SPEC 04, 05, 08 y 09 no cambian.
- Adjuntar imagen a un registro manual, y reemplazar la imagen de cualquier registro (SPEC 05 y SPEC 09).
- Lista de intentos de IA y «Creado por» (el backend no los entrega; SPEC 20).
- Totales o comisiones calculados en el cliente, incluida la vista previa de la plantilla manual.
- Pasos de progreso con tiempos simulados («Detectando empresa…», «Extrayendo movimientos…»).
- Valores numéricos de confianza.
- Entrega, Word, reportes, Empresas y cambios al shell.
- Preferencia guardada del origen de captura.

## Data model

Esta spec no agrega tablas, migraciones ni contratos de API.

### Estado de la carga de imagen (solo cliente)

```ts
// frontend/src/features/records/types.ts
type ImageCaptureState =
  | { kind: "empty" }
  | { kind: "selected"; file: File }
  | { kind: "uploading"; file: File; progress: number | null } // 0-100; null hasta el primer evento
  | { kind: "error"; stage: "validation" | "upload"; message: string; file: File | null };

// Tras el 202, la lectura se deriva de GET /api/records/{id}.
type AiReadingState = {
  recordId: number;
  startedAtMs: number; // reloj del cliente; solo para el tiempo transcurrido
};

type FieldChip = "HIGH_CONFIDENCE" | "REVIEW" | "MISSING" | "INCONSISTENT";
```

- `file` se conserva en la subida con error para reintentar sin volver a elegir.
- `startedAtMs` no se guarda en el navegador. Si se recarga la página, la lectura se reanuda desde `GET /api/records/{id}` y el tiempo se mide desde la recarga.

### Utilidades puras — `frontend/src/features/records/record-capture-utils.ts`

```ts
export const SLOW_READING_MS: number; // 10 * 60 * 1000

export function validateImageFile(file: Pick<File, "type" | "size">): string | null;
export function getUploadProgressLabel(progress: number | null): string;
export function getAiReadingView(status: RecordStatus, elapsedMs: number): {
  step: "queued" | "reading" | "ready" | "error" | "canceled";
  elapsedLabel: string | null;
  slow: boolean;
};
export function formatElapsed(ms: number): string; // "m:ss"
export function getFieldChip(input: {
  value: unknown;
  issueCodes: ReadonlyArray<string>;
  isImageRecord: boolean;
  status: RecordStatus;
}): FieldChip | null;
export function getDirtyCount(initial: unknown, current: unknown): number;
export function getSaveBarState(input: { dirtyCount: number; reason: string; canEdit: boolean }): {
  visible: boolean;
  canSave: boolean;
};
```

- `validateImageFile`: JPEG, PNG o WebP; máximo 10 MiB. Devuelve «Selecciona una imagen JPEG, PNG o WebP.» o «La imagen supera el límite de 10 MiB.». Reemplaza las constantes de `image-record-form.tsx`.
- `getUploadProgressLabel`: `null` → «Subiendo imagen…»; `n` → «Subiendo imagen… {n}%».
- `getAiReadingView`:
  - `PENDING` → `queued`;
  - `PROCESSING` → `reading`, con `elapsedLabel = formatElapsed(elapsedMs)`;
  - `NEEDS_REVIEW` → `ready`;
  - `ERROR` → `error`;
  - `CANCELED` → `canceled`;
  - `slow = true` si el estado es `queued` o `reading` y `elapsedMs >= SLOW_READING_MS`.
- `formatElapsed`: `0:07`, `1:05`, `12:03`.
- `getFieldChip`: solo para `isImageRecord` y `status = NEEDS_REVIEW`. Devuelve `null` en los demás casos.
  - `value` nulo o vacío → `MISSING`;
  - `issueCodes` que contengan un código de discrepancia (`*_mismatch`, `*_discrepancy`) → `INCONSISTENT`;
  - `issueCodes` con confianza baja (`low_confidence`) → `REVIEW`;
  - en otro caso → `HIGH_CONFIDENCE`.
  - Los códigos exactos se toman del contrato de issues de SPEC 06 y se verifican en el paso 0.
- `getDirtyCount`: número de campos cuyo valor actual difiere del inicial, incluidos los movimientos agregados, quitados o editados.
- `getSaveBarState`: visible si `dirtyCount > 0`; `canSave` si `canEdit`, `dirtyCount > 0` y `reason.trim()` no está vacío.

### Servicio y hooks

- `recordsService.createFromImage(file, { onProgress, signal })`: `onUploadProgress` de axios (XHR en el navegador) calcula `Math.round((loaded * 100) / (total ?? file.size))`. `signal` cancela la petición.
- `useCreateRecordFromImage` acepta `{ file, onProgress, signal }`.
- `useRecord(id)` conserva su sondeo mientras el registro está `PENDING` o `PROCESSING` (SPEC 20).
- `recordsService.update` (`PUT`) y `recordsService.cancel` se usan con el `reason` requerido. El `PUT` de un `CONFIRMED` lo reabre (`RecordController::update`).

### Props

- `NewRecordScreen` recibe `role: "EDITOR" | "VIEWER"`. `VIEWER` se redirige a `/records`.
- `RecordEditor` (nuevo, compartido) recibe `record`, `canEdit`, `mode: "review" | "edit"`, y `onSaved`.
- `RecordDetailScreen` conserva `canEdit` y `recordId`.

## UI contract

### Fusión con el layout existente

El contenido se renderiza dentro de `AppShell` (`max-w-6xl`, `px-4 pt-4`). La página no agrega margen exterior ni fondo propio. Los estados usan tokens de SPEC 15, nunca colores literales. Las plantillas traen sidebar, topbar, barra inferior y FAB; esos elementos ya existen en el shell (SPEC 16) y no se repiten.

### Encabezado de `/records/new`

- Enlace «Registros» con `ChevronLeft`, hacia `/records`, en `text-accent-foreground`. 32 px de alto desde `md`, 44 px en móvil.
- `<h1 id="record-capture-heading">Nuevo registro</h1>`: 28/36 px desde `md`, 24/32 px en móvil, 700, `tracking-[-0.01em]`.
- Subtítulo de la plantilla: «Captura los datos tú mismo. Al guardar, el registro queda confirmado.» (manual) o «Compara la imagen con los datos extraídos, lado a lado.» (imagen).
- Selector de origen: `role="radiogroup"` con dos opciones **Captura manual** y **Desde imagen**, con flechas. Seleccionada: `bg-accent text-accent-foreground`. No seleccionada: `bg-card text-muted-foreground border-border`. Por defecto: **Captura manual**. Deshabilitado mientras hay una lectura en curso.
- Columnas: `@container`. Dos columnas desde 60 rem (`grid-cols-[minmax(0,1fr)_340px]`, 24 px de separación); una columna por debajo.

### Alta manual (`RecordNewManual`)

Columna principal:

- **Tarjeta «Empresa y periodo»** (`rounded-lg border bg-card shadow-card`, `<h2>` 16/24 px 600, subtítulo «La semana se identifica por semana y año.»):
  - Empresa (`Select` con las empresas de SPEC 03, placeholder «Selecciona…»), Año, Semana (1–53), Inicio y Fin (fechas reales, sin restricción de día de la semana).
  - Rejilla `grid-cols-[repeat(auto-fit,minmax(150px,1fr))]`.
  - Pie: «Mes del reporte: {mes}. Cuando una semana cruza dos meses se usa el mes de la fecha de fin.»
- **Tarjeta «Movimientos»** (subtítulo «Captura el ingreso y el porcentaje; la comisión y el retorno los calcula el servidor.»):
  - Una tarjeta por movimiento, con «Movimiento N» en 700 y «Quitar movimiento» (`text-status-error`; deshabilitado si solo hay uno).
  - Campos: Afiliación, Sucursal, Ingreso y un selector de comisión con dos opciones, **Porcentaje** o **Importe fijo**. Solo un campo de comisión está activo a la vez (SPEC 04).
  - Rejilla de 2 columnas desde 45 rem del contenedor de la tarjeta.
  - Las columnas Comisión y Retorno muestran «—» hasta guardar.
  - «Agregar movimiento» (secundario) al final.
- **Errores de validación:** se muestran después del primer intento de guardar, junto al campo, con el mensaje del esquema. Un `422` del servidor también se muestra junto al campo.

Columna lateral (≥ 60 rem):

- **«Qué valida el servidor»**: lista con empresa, semana, año y fechas completos; fecha de fin no anterior a la de inicio; al menos un movimiento completo; comisión y retorno calculados por el servidor.

Pie: «Cancelar» (vuelve a `/records`) y **«Guardar y confirmar»** (primario). Tras guardar con `201`, el formulario se reemplaza por el **resumen**:

- «Registro confirmado» con empresa, periodo, número de movimientos, mes del reporte e importes devueltos por el servidor (Ingreso, Comisión, Retorno).
- Acciones: «Ver registro» (`/records/{id}`), «Preparar entrega» (`/records/{id}/delivery`, solo `EDITOR`) y «Crear otro registro» (limpia el formulario y vuelve a la captura vacía).
- Ni el resumen ni la pantalla muestran la imagen. El alta manual no tiene imagen.

### Estados de la carga y lectura de imagen (`RecordNewImageSplit`)

Columnas: izquierda, **«Imagen del proveedor»** (panel de carga y de imagen); derecha, **«Datos extraídos»** (formulario de revisión). Desde 60 rem son dos columnas; por debajo, la imagen va primero y los datos después.

| Estado | Imagen (izquierda) | Datos (derecha) | Acciones |
| --- | --- | --- | --- |
| `vacio` | Zona de arrastre con borde `border-input`, icono `Image`, «Selecciona la imagen del proveedor» y «Imagen recibida por WhatsApp (JPG, PNG o WebP, hasta 10 MiB). También puedes arrastrarla aquí.» | «Sube una imagen para ver aquí los datos.» en `text-muted-foreground` | «Seleccionar imagen» |
| `seleccionada` | Miniatura, nombre y tamaño (`formatFileSize`) | Igual que `vacio` | «Quitar imagen» (secundario), «Subir y procesar» (primario) |
| `subiendo` | Barra `role="progressbar"` con `aria-valuenow` y «Subiendo imagen… {n}%» (`getUploadProgressLabel`). Texto: «Aún no se crea el registro. No cierres esta pantalla hasta que termine.» | Igual que `vacio` | «Cancelar subida» (aborta con `AbortController` y vuelve a `seleccionada`) |
| `encola` (`PENDING`) | Icono de reloj y «Imagen subida: el registro está en cola.» | Esqueleto de tres líneas | «Seguir en segundo plano» (va a `/records`) |
| `procesando` (`PROCESSING`) | Icono de carga animado y «La IA está leyendo la imagen.» con `elapsedLabel` | Esqueleto de tres líneas | «Seguir en segundo plano» |
| `listo` (`NEEDS_REVIEW`) | Imagen con zoom (`ZoomIn` / `ZoomOut`, 5 niveles) | Formulario de revisión con chips por campo (ver «Revisión y corrección») | «Guardar corrección», «Confirmar registro» (diálogo) |
| `error` (`ERROR`) | Imagen y, encima, `role="alert"` con `bg-status-error-bg text-status-error`: «No se pudo leer la imagen.» y «El intento quedó registrado. Puedes reintentar la lectura o cancelar el registro.» | Igual que `vacio` | «Reintentar lectura» (`EDITOR`; `409` refresca) y «Cancelar registro» (panel con motivo obligatorio) |
| `canceled` (`CANCELED`) | Alerta `role="alert"`: «El registro fue cancelado.» con «Ver en registros». | — | — |

- Nota de lectura lenta (`slow = true`): bajo el panel, `bg-accent text-accent-foreground`: «Tarda más de lo normal. Puedes salir: el registro sigue procesándose y aparece en Registros.»
- Los pasos de la plantilla («Leyendo la imagen», «Detectando empresa…», etc.) no se muestran: el sistema no reporta esas etapas.
- Al confirmar desde `listo`, el panel muestra «Registro confirmado» con «Ver registro» y «Subir otra imagen» (vuelve a `vacio`).
- Desde `listo` el selector de origen queda deshabilitado hasta que se confirme o cancele.

### Revisión y corrección (formulario compartido `RecordEditor`)

Lo usan `/records/new` en `listo` y `/records/{id}`. Lo que muestra depende del estado y del origen.

- **Banner** según `getRecordBanner` de SPEC 20. Para `IMAGE` en `NEEDS_REVIEW` y `EDITOR`: «Información extraída automáticamente. Revisa antes de confirmar.».
- **Chips por campo**: solo en `IMAGE` en `NEEDS_REVIEW`. Cada chip sale de `getFieldChip` con `aria-label` que lo describe (`Alta confianza`, `Revisar`, `Falta información`, `Inconsistencia`). El color usa `bg-status-confirmed-bg` (alta), `bg-status-needs-review-bg` (revisar), `bg-status-error-bg` (falta o inconsistencia).
- **Empresa y periodo**: mismo contenido que la alta manual, con chips. Si la empresa no se resolvió y hay `company_name` extraído, la nota «Empresa detectada en la imagen: {nombre}. Selecciónala si corresponde.» (`bg-accent`).
- **Movimientos**: tarjetas editables con los campos de la alta. Comisión y Retorno: valores del servidor (de `RecordDetail`), no recalculados en el cliente.
- **Hero «Retorno oficial»** (`bg-hero text-on-hero`): `formatMoney(return_total)`, con Ingreso y Comisión de `income_total` y `commission_total`. Muestra «—» si son `null`.
- **Franja «Documento vs Sistema»**: solo si la respuesta trae `source_totals`. Compara el retorno documentado con el del sistema y marca «✓ Coincide» o «Inconsistencia · diferencia {monto}».
- **Barra «Guardar corrección»** (fija sobre la barra inferior en móvil, `bottom-[calc(5.25rem+env(safe-area-inset-bottom))]`): visible si `getSaveBarState().visible`. Contiene el texto «{n} cambios sin guardar», el campo **Motivo de la corrección** (obligatorio, máximo 2000 caracteres), «Descartar» y «Guardar corrección». Sin cambios, la barra no aparece.
- **Confirmar**: disabled con el texto «Guarda o descarta los cambios antes de confirmar.» si hay cambios sin guardar. Abre `RecordActionDialog` (SPEC 20).

### Detalle editable (`RecordEdit` en `/records/{id}`)

Encabezado:

- Enlace «Volver a registros» y `<h1>` con el nombre de la empresa (o «Registro sin empresa»).
- `StatusBadge`, insignia de fuente y línea de periodo (`getRecordPeriodLine`).
- Acciones según estado y rol (mismos permisos que SPEC 20):
  - «Reintentar lectura» (`ERROR`, `IMAGE`, `EDITOR`);
  - «Cancelar registro» (`NEEDS_REVIEW` y `ERROR`, `EDITOR`): abre el panel de cancelación;
  - «Confirmar registro» (`NEEDS_REVIEW`, `EDITOR`): diálogo;
  - «Reabrir para corregir» (`CONFIRMED`, `EDITOR`): activa la edición en línea. Guardar envía `PUT` con motivo y el servidor pasa el registro a `NEEDS_REVIEW`.

Panel de cancelación (`EDITOR`, `NEEDS_REVIEW` o `ERROR`): `role="region"` con el título «Cancelar este registro» y el texto «Un registro cancelado no cuenta en reportes ni totales. Esta acción queda en el historial.» Campo **Motivo** obligatorio y «Confirmar cancelación» (`bg-status-error` / `text-status-error`). Enviar llama a `POST /api/records/{id}/cancel`.

Layout (≥ 60 rem):

- Columna principal: banner, hero, «Empresa y periodo», «Movimientos», «Historial de cambios» (SPEC 20).
- Columna lateral (340 px): «Imagen recibida» (sin «Reemplazar imagen»), «Procesamiento automático» (solo `IMAGE`) y «Entrega» (SPEC 20).
- Por debajo de 60 rem: una columna en este orden: hero, periodo, movimientos, imagen, procesamiento automático, entrega, historial.

«Procesamiento automático» (solo `IMAGE`):

- Estado de la última extracción (`ai_processing.status`), proveedor y modelo, inicio y fin (`formatDateTime`), y la lista de `issues` como diagnóstico seguro.
- Sin lista de intentos ni «Creado por» (no hay datos; ver Decisions).
- «Reintentar lectura» se muestra en el encabezado y aquí no se repite.

Edición en registros `CONFIRMED`: al pulsar «Reabrir para corregir», la página muestra el aviso de la plantilla: «Este registro se reabrió para corregirlo. Mientras esté Por revisar no cuenta en los reportes. Al confirmarlo vuelve a incluirse.» Ese aviso aparece solo tras el primer cambio guardado.

### Estados de la pantalla de detalle

- **Cargando:** esqueletos del encabezado, hero y dos tarjetas. Sin importes.
- **Error:** tarjeta `role="alert"` con «No se pudo cargar el registro» y «Reintentar» (SPEC 20).
- **Registro sin datos todavía** (`PENDING`, `PROCESSING`, `ERROR`): hero «—», tarjetas con «—» (SPEC 20).

### Diferencias por rol

| Elemento | EDITOR | VIEWER |
| --- | --- | --- |
| Alta manual y desde imagen | Sí | No (redirige a `/records`) |
| Chips por campo, banner de revisión | Sí, en `IMAGE` `NEEDS_REVIEW` | Solo lectura: chips visibles, banner «Pendiente de revisión.» |
| Edición en línea y barra «Guardar corrección» | Según estado | No |
| Confirmar, cancelar, reintentar | Según estado | No |
| Procesamiento automático, imagen, historial, entrega | Sí | Sí |

El backend sigue siendo la autoridad. El rol solo decide qué se muestra.

### Versión móvil

- Una columna. Las plantillas móviles usan el mismo componente con viewport de 390 px.
- Campos de 48 px de alto. Botones de acción de 44 px. Sin scroll horizontal en la página; solo en la fila de chips de selección de movimientos, si hiciera falta.
- La barra «Guardar corrección» se fija por encima de `AppBottomNav`.

## Implementation plan

0. **Verificar el contrato de datos antes de codificar.**
   - Revisar en `RecordResource` y en SPEC 06 la forma de `ai_processing.issues` (códigos, campo y confianza), y si `GET /api/records/{id}` incluye `source_totals`.
   - Si no hay códigos por campo, usar el tipo de issue disponible y dejar los chips como `HIGH_CONFIDENCE`/`MISSING` por valor nulo, anotando el ajuste en Decisions.
   - Si no hay `source_totals`, omitir la franja «Documento vs Sistema» y anotarlo.
   - Esta verificación no cambia código.
1. **Utilidades puras y pruebas.**
   - Crear `frontend/src/features/records/record-capture-utils.ts` con las funciones de «Data model».
   - Crear `frontend/tests/record-capture-utils.test.ts`: tipo y tamaño (límite exacto de 10 MiB), etiqueta de progreso con `null` y con porcentaje, `getAiReadingView` para los seis estados y para `elapsedMs` debajo y encima de `SLOW_READING_MS`, `formatElapsed` (0, 7 s, 65 s, 12 min), `getFieldChip` para cada chip y para registros manuales o `CONFIRMED` (siempre `null`), `getDirtyCount` con campos y movimientos agregados o quitados, y `getSaveBarState` sin motivo.
   - `npm test` sin cambio visible.
2. **Progreso real y cancelación de la subida.**
   - Agregar `onProgress` y `signal` a `recordsService.createFromImage` y a `useCreateRecordFromImage`.
   - Pruebas con el cliente axios simulado: el callback recibe valores de 0 a 100, funciona sin `total`, y `signal` aborta la petición.
3. **Panel de imagen (izquierda) en `/records/new`.**
   - Crear `components/image-capture-panel.tsx` con los estados `vacio`, `seleccionada`, `subiendo` y `error` (validación y subida).
   - Montarlo en `new-record-screen.tsx` en lugar del formulario actual. Por ahora, tras el `202` se mantiene la redirección a `/records/{id}`, para aislar el cambio.
   - Verificar a mano: selección, rechazo de tipo y tamaño, progreso visible, cancelación y error de red simulado.
4. **Formulario compartido `RecordEditor` (modo `review`).**
   - Crear `components/record-editor.tsx` con banner, empresa y periodo, movimientos en tarjetas, chips por campo y hero con totales del servidor.
   - Sin barra de cambios ni cancelación todavía. Montarlo solo en `/records/{id}` para `NEEDS_REVIEW` de `IMAGE`, en modo lectura de los datos actuales.
   - Verificar chips con un registro `IMAGE` `NEEDS_REVIEW` real y con uno `MANUAL`.
5. **Revisión lado a lado en `/records/new` (`listo`).**
   - Crear `components/image-reading-status.tsx`, que consulta `useRecord(recordId)` y usa `getAiReadingView`.
   - Tras el `202`, mostrar `encola` y `procesando` en el panel de imagen en lugar de redirigir. Redirigir solo a `/records/{id}` cuando se confirme o cancele, o por «Ver registro».
   - Mostrar `listo` con `RecordEditor` en modo `review` a la derecha y zoom sobre la imagen.
   - Implementar «Guardar corrección» (`PUT` con motivo, `getSaveBarState`), «Confirmar registro» (diálogo) y «Subir otra imagen».
   - Implementar `error`: «Reintentar lectura» y «Cancelar registro» (panel con motivo). Eliminar `image-record-form.tsx` cuando esto esté montado.
   - Verificar los estados `encola`, `procesando`, `listo`, `error` y `canceled` con registros reales.
6. **Alta manual (`RecordNewManual`).**
   - Reescribir la presentación de `manual-record-form.tsx` con las tarjetas «Empresa y periodo» y «Movimientos», conservando el esquema, los campos y la mutación `POST /api/records`.
   - Columnas Comisión y Retorno en «—» hasta guardar. Sin hero en la alta.
   - Resumen tras `201` con «Crear otro registro» (reinicia el formulario).
   - Verificar la comisión exclusiva por movimiento, el botón «Quitar» deshabilitado con un solo movimiento y el resumen tras guardar.
7. **Detalle editable (`RecordEdit`).**
   - En `record-detail-screen.tsx`, sustituir la presentación de solo lectura de `NEEDS_REVIEW` y `CONFIRMED` editable por `RecordEditor` en modo `edit`, con la columna lateral de imagen, procesamiento automático y entrega.
   - Montar la tarjeta de procesamiento automático solo para `IMAGE`.
   - Verificar «Editar registro» en `NEEDS_REVIEW` (manual e imagen) y «Reabrir para corregir» en `CONFIRMED`, con el motivo y el aviso de reapertura.
8. **Cancelación, barra de cambios y reapertura.**
   - Implementar el panel de cancelación con motivo, la barra «Guardar corrección» con contador, «Descartar» y el bloqueo de «Confirmar registro» con cambios pendientes.
   - Verificar `409` con refresco del registro, y la reapertura de `CONFIRMED` que deja el registro en `NEEDS_REVIEW`.
   - Sustituir `ManualRecordForm` y su diálogo de edición por `RecordEditor`, y eliminar el código muerto.
9. **Revisión visual.**
   - Revisar `/records/new` (manual, imagen en cada estado) y `/records/{id}` como `EDITOR` y `VIEWER`, en claro y oscuro, a 1440, 1024, 768 y 375 px.
   - Comparar con `RecordNewManual`, `RecordNewImageSplit` y `RecordEdit` (escritorio y móvil).
   - Probar la subida con red lenta, la cancelación de subida, la lectura en `PENDING` y `PROCESSING`, y el paso a `NEEDS_REVIEW` con revisión lado a lado.
   - Ajustar solo espaciados y tamaños.
10. **Documentación.**
    - Actualizar `docs/frontend/features/records.md`: `/records/new` y sus estados, `/records/{id}` editable, chips, procesamiento automático y lo que queda fuera.
    - Actualizar `docs/frontend/05_ui_and_accessibility.md`: `role="progressbar"`, `aria-live` de los estados de lectura, `radiogroup` del selector de origen y la barra fija con su posición sobre la barra inferior.
    - Actualizar `docs/10_ui_ux.md` §6 y §9 con el nuevo comportamiento.

## Acceptance criteria

- [ ] `/records/new` tiene exactamente un `<h1>` con «Nuevo registro», visible a 1440 px y 375 px.
- [ ] El selector de origen es un `radiogroup`; «Captura manual» viene seleccionado; las flechas cambian la opción.
- [ ] Como `VIEWER`, `/records/new` redirige a `/records`.
- [ ] En «Desde imagen» sin archivo se muestra la zona de arrastre con «JPG, PNG o WebP, hasta 10 MiB».
- [ ] Un archivo `image/gif` o uno de 10 MiB + 1 byte se rechaza en el cliente con el mensaje de `validateImageFile`, sin petición de red.
- [ ] Un archivo válido muestra miniatura, nombre, tamaño, «Quitar imagen» y «Subir y procesar».
- [ ] Durante la subida, `role="progressbar"` avanza de 0 a 100 con porcentaje real, y «Cancelar subida» aborta la petición y vuelve a `seleccionada`.
- [ ] Un `422` de la subida muestra el mensaje del backend en `role="alert"` y conserva el archivo para reintentar.
- [ ] Un fallo de red muestra «No se pudo subir la imagen. Revisa tu conexión e inténtalo de nuevo.» y conserva el archivo.
- [ ] Tras el `202`, la página no redirige. Con `PENDING` aparece «Imagen subida: el registro está en cola.»; con `PROCESSING`, «La IA está leyendo la imagen.» con el tiempo `m:ss`.
- [ ] El sondeo consulta `GET /api/records/{id}` mientras el registro está `PENDING` o `PROCESSING`, y se detiene en un estado final.
- [ ] Con `NEEDS_REVIEW`, la imagen y los datos extraídos aparecen lado a lado en `/records/new`, sin redirigir.
- [ ] Con `ERROR`, aparecen «Reintentar lectura» y «Cancelar registro». «Reintentar lectura» vuelve a `PROCESSING`; un `409` refresca el registro.
- [ ] «Cancelar registro» exige motivo y, al confirmar, el registro pasa a `CANCELED`.
- [ ] Con `slow = true` aparece «Tarda más de lo normal…» y ningún mensaje de error.
- [ ] Los chips por campo aparecen solo en registros `IMAGE` `NEEDS_REVIEW` y coinciden con `getFieldChip`.
- [ ] La alta manual válida responde `201`, muestra el resumen «Registro confirmado» con los importes del servidor, y «Crear otro registro» deja el formulario vacío.
- [ ] La alta manual no calcula comisión ni retorno en el cliente; las columnas Comisión y Retorno muestran «—» antes de guardar.
- [ ] Solo un tipo de comisión por movimiento está activo a la vez; «Quitar movimiento» está deshabilitado con un único movimiento.
- [ ] Los errores de validación aparecen junto al campo después del primer intento de guardar.
- [ ] En `/records/{id}` con `NEEDS_REVIEW`, editar un campo muestra la barra «Guardar corrección» con «1 cambio sin guardar» (o el número correcto).
- [ ] «Guardar corrección» exige motivo; sin cambios la barra no aparece.
- [ ] «Confirmar registro» está deshabilitado con cambios sin guardar.
- [ ] Un `PUT` exitoso mantiene `source` y `status` en `NEEDS_REVIEW` y en `CONFIRMED` (reabierto) lleva el registro a `NEEDS_REVIEW`.
- [ ] «Cancelar registro» muestra el panel con el texto de la plantilla y exige motivo.
- [ ] La tarjeta «Procesamiento automático» aparece solo en registros `IMAGE` y muestra estado, proveedor, modelo, inicio, fin e issues, sin lista de intentos.
- [ ] La franja «Documento vs Sistema» aparece solo si la respuesta incluye `source_totals`.
- [ ] Como `VIEWER`, no hay campos editables, barra, chips de acción ni botones de mutación; los chips son informativos.
- [ ] En un registro manual sin imagen, la columna lateral de imagen no aparece.
- [ ] Con contenedor de 60 rem o más hay dos columnas; por debajo, una columna en el orden de la sección «Detalle editable».
- [ ] A 375 px, `/records/new` y `/records/{id}` no tienen scroll horizontal, y la barra «Guardar corrección» queda por encima de `AppBottomNav`.
- [ ] Todos los controles se alcanzan con Tab y muestran un anillo de foco de 2 px, incluidos el selector de origen, las opciones de comisión, zoom y «Cancelar subida».
- [ ] Los archivos nuevos o modificados de `features/records/` solo usan clases de tokens, sin colores literales ni de la paleta de Tailwind.
- [ ] `image-record-form.tsx` y `manual-record-form.tsx` ya no existen o no tienen imports activos.
- [ ] La consola no muestra advertencias de hidratación en `/records/new` ni en `/records/{id}`.
- [ ] `npm run lint`, `npx tsc --noEmit`, `npm test` y `npm run build` en `frontend/` pasan sin errores.
- [ ] La documentación describe el nuevo comportamiento y los cambios respecto de SPEC 09 y SPEC 20.

## Decisions

- **Sí:** las plantillas nuevas son la base de `/records/new` y de la edición. Reemplazan el plan previo de tomar `RecordDetailEditor` y definir los estados aparte.
- **Sí (supersede SPEC 20):** la edición en línea, la barra «Guardar corrección», el panel de cancelar con motivo y los chips por campo se adoptan de `RecordEdit`. Por decisión del usuario, esta spec reemplaza las exclusiones de SPEC 20 en esos puntos.
- **Sí (supersede SPEC 20):** la tarjeta «Procesamiento automático» se adopta, sin la lista de intentos. El backend no entrega intentos (SPEC 20), y la lista se omite para no inventar datos.
- **No (supersede SPEC 20 en este punto):** «Documento vs Sistema» se omite en esta spec. El paso 0 verificó que `GET /api/records/{id}` no expone `source_totals` en la raíz; solo viene anidado en `ai_processing.normalized_extraction.source_totals`, que puede estar vacío. Por decisión del usuario no se usa esa ubicación ni se cambia el backend. La franja queda pendiente para una spec posterior.
- **Sí (supersede SPEC 09):** tras el `202` el Editor se queda en `/records/new`. La revisión de un registro `IMAGE` `NEEDS_REVIEW` ocurre lado a lado en esa pantalla (`RecordNewImageSplit`). `/records/{id}` queda para consulta y correcciones posteriores.
- **Sí:** «Confirmar registro» abre `RecordActionDialog` (decisión de SPEC 20). La plantilla pone el botón sin diálogo; se conserva el diálogo porque ya cubre el manejo de `409`.
- **Sí:** reabrir un `CONFIRMED` no tiene llamada propia. Guardar el `PUT` lo reabre, como hace hoy `RecordController::update`.
- **No:** totales ni comisiones calculados en el cliente, incluida la vista previa de la plantilla manual. Las reglas de negocio (SPEC 04 y SPEC 19) reservan el cálculo al servidor. La alta manual muestra «—» hasta guardar y luego los importes del servidor.
- **No:** pasos de progreso con tiempos simulados. La plantilla usa temporizadores; el sistema no reporta etapas y mostrarlas sería inventar datos. Se muestra solo el estado real y el tiempo transcurrido.
- **Sí:** progreso real de la subida con `onUploadProgress` de axios (XHR en el navegador), sin dependencias nuevas.
- **Sí:** tarjetas por movimiento en el formulario (también en la lectura lado a lado), aunque la plantilla usa tabla. Una tabla editable duplicaría campos en móvil; la tabla queda para la vista de solo lectura.
- **Sí:** el resumen tras guardar reemplaza el formulario hasta pulsar «Crear otro registro». Cumple la limpieza del formulario de SPEC 04 pero la posterga hasta que el Editor la pide; SPEC 04 queda superada en ese punto.
- **Sí:** el alta manual omite el bloque «Imagen: Opcional» de la plantilla. La frase «Podrás adjuntarla después desde su detalle» promete una función que esta spec deja fuera.
- **Sí (decisión tomada por el sistema, pregunta sin respuesta clara):** en imagen, solo se incluye «Reintentar lectura» en `ERROR`. Adjuntar imagen a un manual y reemplazar la imagen quedan fuera. La respuesta a esa pregunta fue ambigua; se tomó la opción recomendada. Si se quiere adjuntar o reemplazar, va en otra spec.
- **Sí:** el umbral de lectura lenta es de 10 minutos (`SLOW_READING_MS`). Es una decisión de implementación; la nota no cambia el estado.
- **Sí:** el origen por defecto es «Captura manual» y no se guarda la última elección.
- **Sí:** los chips por campo se derivan de `ai_processing.issues` con `getFieldChip`. Sin confianza numérica, como SPEC 09.
- **Sí:** la barra «Guardar corrección» contiene el motivo obligatorio, porque `PUT` lo exige (SPEC 09).
- **Sí:** los importes y fechas usan `formatMoney` y `formatDate`/`formatDateTime`, como en SPEC 19 y SPEC 20.

## Risks

| Risk | Mitigation |
| --- | --- |
| Los issues de IA no traen código por campo y los chips quedan imprecisos. | El paso 0 verifica el contrato antes de codificar. Si falta, los chips usan solo valor nulo y anotan el ajuste. |
| La barra fija tapa la barra inferior o el FAB en móvil. | Posición `bottom-[calc(5.25rem+env(safe-area-inset-bottom))]`, como el FAB de SPEC 19. El paso 9 lo revisa a 375 px. |
| La edición en línea con cambios sin guardar pierde datos al navegar. | El contador de cambios bloquea «Confirmar registro» y el paso 8 prueba la navegación con cambios pendientes. |
| Una lectura atascada en `PENDING` (worker caído) deja al Editor esperando. | Nota de lectura lenta a los 10 minutos y «Seguir en segundo plano». «Reintentar» y «Cancelar» solo aparecen en `ERROR`. |
| Recargar durante la lectura pierde `startedAtMs`. | La lectura se reanuda desde `GET /api/records/{id}` y el tiempo se mide desde la recarga. |
| Reescribir `ManualRecordForm` rompe la validación de comisión exclusiva. | El esquema y los campos no cambian. El paso 6 verifica la exclusividad a mano y con pruebas. |
| La vista previa de la imagen fuga memoria. | `URL.revokeObjectURL` al cambiar de archivo y al desmontar el panel. |

## What is **not** in this spec

- Cambios de backend, API o reglas de negocio.
- Adjuntar imagen a un registro manual y reemplazar la imagen de cualquier registro.
- Lista de intentos de IA y «Creado por».
- Totales o comisiones calculados en el cliente, incluida la vista previa.
- Pasos de progreso con tiempos simulados.
- Valores numéricos de confianza.
- Entrega, Word, reportes, Empresas y cambios al shell.
- Preferencia guardada del origen de captura.

Cada uno de esos puntos, si se hace, va en su propia spec.
