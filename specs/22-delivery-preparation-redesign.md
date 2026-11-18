# SPEC 22 — Rediseño de la preparación de entrega

> **Status:** Approved
> **Depends on:** SPEC 05, SPEC 11, SPEC 12, SPEC 14, SPEC 15, SPEC 16, SPEC 20, SPEC 21
> **Date:** 2026-11-18
> **Objective:** Rediseñar `/records/{id}/delivery` con la plantilla `DeliveryPrepare` y los tokens de shadcn, unificando guardar y generar el Word en una sola barra de acciones, sin cambiar el backend ni las reglas de negocio.

## Why this spec exists

La preparación de entrega funciona desde SPEC 11, SPEC 12 y SPEC 14, pero su pantalla es la UI mínima de SPEC 11. Usa tarjetas genéricas, una tabla de 11 inputs, una sección «Documento Word» aparte y mensajes dispersos. SPEC 11 dejó el diseño oficial para después.

Las plantillas nuevas en `docs/design/templates/` cubren esta pantalla:

- `DeliveryPrepare.dc.html`: escritorio, entrega nueva.
- `DeliveryPrepareMobile.dc.html`: el mismo marcado a 390 px.
- `DeliveryUpdate.dc.html`: el mismo marcado con `modo="actualizar"` (entrega obsoleta).

Las tres son un solo componente con tres props: `modo` (`nueva` / `actualizar`), `imagen` (`con` / `sin`) y `escenario` (`coincide` / `cambio` / `faltante`). Esta spec implementa ese componente una vez.

El criterio de esta spec es **la menor complejidad posible**: no hay endpoints nuevos y se reutilizan los servicios, hooks, esquema y utilidades de `features/deliveries`. La lógica nueva vive en funciones puras con pruebas.

## Scope

**In:**

- `/records/{id}/delivery` con el layout de `DeliveryPrepare`: encabezado, banners, hero de montos, «Datos de la entrega», «Monto del documento», «Desglose de efectivo», «Documento asociado», «Para generar el recibo» y barra de acciones.
- Cantidades por denominación con stepper `−` / `+` y un input numérico editable a mano, agrupadas en **Billetes** y **Monedas** con subtotales.
- Barra de acciones única: texto de estado, «Cancelar», «Guardar entrega» / «Actualizar entrega» y «Generar recibo (Word)». Reemplaza la sección «Documento Word» (`delivery-document-action.tsx`).
- Checklist «Para generar el recibo» y texto de la barra, ambos derivados de una sola función pura (`getDeliveryReadiness`).
- «Adjuntar imagen» en registros sin imagen, con el endpoint existente `POST /api/records/{id}/image` (SPEC 05).
- Precarga de «Entrega» con el nombre del usuario en sesión cuando no existe entrega.
- Banner de entrega obsoleta (`is_stale`) con «Retorno actual» y «Entrega preparada».
- Vista de solo lectura con el mismo layout para `VIEWER` y para un `EDITOR` con registro no `CONFIRMED`.
- Utilidades puras con pruebas unitarias.
- Documentar en `docs/frontend/features/deliveries.md`, `docs/frontend/05_ui_and_accessibility.md` y `docs/10_ui_ux.md` §8 y §9.

**Out of scope (for future specs):**

- Cambios de backend, API, migraciones o reglas de negocio. Los endpoints de SPEC 05, SPEC 11, SPEC 12 y SPEC 14 no cambian.
- Reemplazar una imagen existente desde la entrega.
- Persistir el monto del documento o la declaración de cambio (siguen siendo efímeros, SPEC 14).
- Aviso de cambios sin guardar al salir de la página.
- Vista previa del Word antes de descargarlo.
- Cambios a la tarjeta «Entrega» del detalle del registro (SPEC 20), al shell o a otras pantallas.

## Data model

Esta spec no agrega tablas, migraciones ni contratos de API. Reutiliza `Delivery`, `UpsertDeliveryInput`, `GenerateDeliveryDocumentInput` y `deliveryFormSchema` tal como están.

Convenciones que no cambian:

- Importes en centavos con `toCents` / `centsToAmount` (`features/deliveries/utils.ts`).
- Tolerancia de `$1.00` = `100` centavos (SPEC 14).
- Opciones del monto del documento: `documentReturnOptions(retornoOficial)` → `[.00, .50, siguiente peso .00]`. Por defecto, la primera.
- El monto del documento y la declaración de cambio son estado local de la pantalla. La declaración vuelve a `false` cuando cambian las cantidades o el monto del documento (como hoy).

### Utilidades puras nuevas — `frontend/src/features/deliveries/utils.ts`

```ts
export const MAX_DENOMINATION_QUANTITY = 999;

export function stepQuantity(current: string, delta: 1 | -1): string; // limita a 0..999
export function parseQuantityInput(raw: string): string;              // solo dígitos; vacío → "0"; máximo 999

export type CashCheck = {
  cashCents: number;
  shortOfficialCents: number;  // retorno oficial - efectivo, si > 100; si no, 0
  shortDocumentCents: number;  // monto del documento - efectivo, si > 100; si no, 0
  excessCents: number;         // efectivo - monto del documento, si > 100; si no, 0
  tone: "ok" | "short" | "excess";
  label: string;
};

export function getCashCheck(input: {
  officialAmount: string;
  documentAmount: string;
  denominations: Array<{ value: string; quantity: number | string }>;
}): CashCheck;

export function isDeliveryClean(
  delivery: Delivery | null,
  values: DeliveryFormValues,
): boolean;

export type ReadinessCheck = {
  id: "image" | "official" | "document" | "change" | "saved";
  label: string;
  ok: boolean;
};

export function getDeliveryReadiness(input: {
  hasImage: boolean;
  cash: CashCheck;
  changeDeclared: boolean;
  clean: boolean;
  missingData: boolean;      // fecha o «Entrega» vacíos
  generated: boolean;        // Word descargado y sin cambios desde entonces
}): {
  checks: ReadinessCheck[];
  hint: string;
  hintTone: "ok" | "neutral";
  canSave: boolean;
  canGenerate: boolean;
};

export function getDeliveryHeading(delivery: Delivery | null): {
  title: "Preparar entrega" | "Preparar nueva entrega";
  saveLabel: "Guardar entrega" | "Actualizar entrega";
};
```

Reglas:

- `getCashCheck.tone` y `label`:
  - `shortDocumentCents > 0` → `short`, «Faltan {monto} frente al monto del documento»;
  - `excessCents > 0` → `excess`, «Excede por {monto} · requiere declarar cambio»;
  - otro caso → `ok`, «Coincide con el monto del documento».
- `isDeliveryClean`: `true` solo si existe entrega y coinciden fecha, «Entrega» y «Recibe» recortados, y `denominationBreakdownKey` del formulario y de la entrega.
- `getDeliveryReadiness.checks`, en este orden:
  1. `image` — «Imagen asociada»;
  2. `official` — «Efectivo conciliado con el retorno oficial» (`shortOfficialCents === 0`);
  3. `document` — «Efectivo conciliado con el monto del documento» (`shortDocumentCents === 0`);
  4. `change` — «Cambio al cliente declarado», solo si `excessCents > 0`;
  5. `saved` — «Entrega guardada» (`clean`).
- `canSave = !missingData && shortOfficialCents === 0 && !clean`.
- `canGenerate = clean && hasImage && shortDocumentCents === 0 && (excessCents === 0 || changeDeclared)`.
- `hint`, el primero que aplique:
  1. `generated && canGenerate` → «Recibo generado.» (`ok`);
  2. `missingData` → «Completa la fecha de entrega y quién entrega.»;
  3. `shortOfficialCents > 0` → «Ajusta el efectivo para poder guardar la entrega.»;
  4. `!clean` → «Guarda la entrega para poder generar el recibo.»;
  5. `!hasImage` → «Falta la imagen para generar el Word.»;
  6. `shortDocumentCents > 0` → «El efectivo no coincide con el monto del documento.»;
  7. excedente sin declarar → «Indica que el cliente dará cambio.»;
  8. otro caso → «Todo listo para generar el recibo.» (`ok`).
- `getDeliveryHeading`: sin entrega → «Preparar entrega» / «Guardar entrega»; con entrega obsoleta → «Preparar nueva entrega» / «Actualizar entrega»; con entrega vigente → «Preparar entrega» / «Actualizar entrega».
- `documentBlockedReason` se elimina: `getDeliveryReadiness` lo reemplaza. `documentBlockedMessages` se conserva para los `409` del Word.

### Servicio y hooks

- `recordsService.attachImage(id: number, file: File): Promise<RecordImage>`: `POST records/{id}/image` con `FormData` (`image`). Errores con `toRecordsApiError`.
- `useAttachRecordImage()` en `features/records/hooks/use-records.ts`: al terminar, invalida el detalle del registro y su historial.
- `useDelivery`, `useUpsertDelivery` y `useGenerateDeliveryDocument` no cambian.

### Props

- `DeliveryScreen` recibe `canEdit`, `recordId` y `userName: string` (nuevo). `page.tsx` lo toma de `getAuthenticatedUser()`.
- `RecordImageCard` recibe dos props opcionales: `title` (por defecto «Imagen recibida») y `emptyContent: ReactNode` (reemplaza el texto «Este registro no tiene imagen asociada.»). El detalle del registro no cambia.

## UI contract

### Fusión con el layout existente

El contenido se renderiza dentro de `AppShell` (`max-w-6xl`, `px-4 pt-4`). La página no agrega margen exterior ni fondo propio. Los colores salen de los tokens de SPEC 15 (`bg-card`, `bg-hero`, `text-on-hero`, `text-muted-foreground`, `bg-status-*`, `border-input`, `ring`), nunca de colores literales ni de la paleta de Tailwind. El texto atenuado sobre el hero usa `text-on-hero/70`. La plantilla trae sidebar, topbar y barra inferior; ya existen en el shell (SPEC 16) y no se repiten.

### Encabezado

- Enlace «Registro» con `ChevronLeft` hacia `/records/{id}`, en `text-accent-foreground`; 32 px de alto desde `md`, 44 px en móvil.
- `<h1 id="delivery-heading">` con `getDeliveryHeading().title`: 28/36 px desde `md`, 24/32 px en móvil, 700, `tracking-[-0.01em]`.
- Línea de metadatos: `StatusBadge`, insignia de fuente («Manual» o «Imagen») y `getRecordPeriodLine` (SPEC 20), con `tabular-nums`.

### Banners (bajo el encabezado)

- **Entrega obsoleta** (`delivery.is_stale`): `role="status"`, `bg-status-needs-review-bg text-status-needs-review`. «La entrega se preparó con un monto anterior.», una lista `<dl>` con «Retorno actual» (`current_return_amount`) y «Entrega preparada» (`prepared_return_amount`), y «Ajusta el efectivo al retorno actual y guarda: se actualiza la misma entrega.».
- **Bloqueos** (`role="alert"`, `bg-status-error-bg text-status-error`), una lista con los que apliquen:
  - `shortOfficialCents > 0`: «Faltan {monto} en efectivo frente al retorno oficial. Ajusta las denominaciones.» con el código `CASH_MISMATCH`;
  - si no, `shortDocumentCents > 0`: «Faltan {monto} en efectivo frente al monto del documento. Ajusta las denominaciones o elige otro monto del documento.» con `CASH_MISMATCH`;
  - sin imagen: «Adjunta la imagen del registro para generar el Word.»;
  - errores del servidor (ver «Errores»).
- Los códigos se muestran en `<code>` con `font-mono`, como la plantilla.

### Columnas

`@container`, como SPEC 21. Dos columnas desde 60 rem (`grid-cols-[minmax(0,1fr)_340px]`, 24 px de separación). Por debajo, una columna en este orden: hero, datos, monto del documento, desglose, documento asociado, checklist.

Columna principal:

1. **Hero «Montos de la entrega»** (`bg-hero text-on-hero`, `rounded-xl`):
   - «Retorno oficial» con el retorno actual del registro (figura grande) y «Es el monto con el que se verifica el efectivo al guardar.»;
   - al lado, «Documento» (monto elegido) y «Efectivo» (total del formulario);
   - abajo, `role="status"` con `getCashCheck().label`: `ok` → `bg-status-confirmed-bg text-status-confirmed`; `excess` → `bg-status-needs-review-bg text-status-needs-review`; `short` → `bg-status-error-bg text-status-error`.
2. **«Datos de la entrega»** (subtítulo «Quién entrega el efectivo y quién lo recibe.»): rejilla `grid-cols-[repeat(auto-fit,minmax(180px,1fr))]` con:
   - «Fecha de entrega» (`<input type="date">`, por defecto hoy);
   - «Entrega» (por defecto la entrega guardada o, si no hay, `userName`);
   - «Recibe» con la insignia «Opcional».
3. **«Monto del documento»** (subtítulo «Es el monto que imprime el recibo de Word.»): `role="radiogroup"` con las tres opciones como `role="radio"` y `aria-checked`, con flechas. Seleccionada: `bg-primary text-primary-foreground`. Nota: «Es un valor de esta solicitud: no se guarda y el retorno oficial no cambia.».
4. **«Desglose de efectivo»** (subtítulo «Efectivo bruto. Solo se guardan las denominaciones con cantidad mayor a cero.»):
   - Grupo «Billetes» ($1,000 a $20) y grupo «Monedas» ($10 a $0.50), cada uno con su subtotal en el encabezado.
   - Cada fila: denominación, stepper y subtotal. `−` con `aria-label="Quitar uno de {denominación}"` (deshabilitado en 0), input con `inputmode="numeric"` y `aria-label="Cantidad de {denominación}"`, `+` con `aria-label="Agregar uno de {denominación}"`.
   - El input acepta escritura manual; `parseQuantityInput` deja solo dígitos y limita a 999. `−` y `+` usan `stepQuantity`.
   - Filas en 0 con texto `text-muted-foreground` y peso 500; filas con cantidad en `bg-muted` y peso 700.
   - Fila «Efectivo total» (`bg-hero text-on-hero`) con el número de piezas («1 pieza», «N piezas») y el total.
   - Si `excessCents > 0`: banner `bg-status-needs-review-bg` «El efectivo excede el documento por {monto}. Indica que el cliente dará cambio para continuar.» con `CHANGE_REQUIRED`, y la casilla «El cliente dará cambio · {monto} (calculado)».

Columna lateral:

5. **«Documento asociado»**: `RecordImageCard` con `title="Documento asociado"`. Sin imagen, `emptyContent` muestra «Este registro se capturó de forma manual y no tiene imagen. Puedes guardar la entrega, pero el Word requiere la imagen.» y, para `EDITOR` con registro `CONFIRMED`, «Adjuntar imagen» (ver «Adjuntar imagen»).
6. **«Para generar el recibo»** (subtítulo «Debe estar todo listo.»): lista de `getDeliveryReadiness().checks`. Cada ítem tiene un icono (`CircleCheck` en `text-status-confirmed` si `ok`, `Minus` en `text-muted-foreground` si no) y la insignia «Listo» (`bg-status-confirmed-bg`) o «Pendiente» (`bg-status-pending-bg`).

### Barra de acciones

`role="region"` con `aria-label="Acciones de la entrega"`, `rounded-lg border bg-card shadow-popover`.

- Posición: `fixed inset-x-4 bottom-[calc(5.25rem+env(safe-area-inset-bottom))] z-40` en móvil, sobre `AppBottomNav`, como SPEC 21. Desde `md`: `sticky bottom-4`. La página agrega padding inferior para que la barra no tape el último contenido.
- Texto de estado `role="status"` con `getDeliveryReadiness().hint`; `text-status-confirmed` si `hintTone="ok"`.
- Botones:
  - «Cancelar» (`ghost`, enlace a `/records/{id}`);
  - «Guardar entrega» / «Actualizar entrega» (primario), visible solo si `!clean`, deshabilitado si `!canSave` o mientras guarda («Guardando…»);
  - «Generar recibo (Word)» con icono `FileText`: primario si `clean`, `outline` si no; deshabilitado si `!canGenerate` o mientras genera («Generando…»).
- En móvil los botones ocupan el ancho (`flex-1`, 44 px).

### Guardado y generación

- Guardar envía `PUT /api/records/{id}/delivery` con el mismo cuerpo de hoy (nombres recortados, «Recibe» vacío → `null`, 11 denominaciones con cantidad entera). Al terminar, el formulario se reinicia con la entrega devuelta, así que queda `clean`.
- Generar envía `POST /api/deliveries/{id}/document` con `document_return_amount` y `customer_will_give_change`, y descarga el `.docx` (SPEC 12 y SPEC 14). Tras la descarga, `generated = true` hasta el siguiente cambio en el formulario, el monto o la casilla.

### Adjuntar imagen

- Botón «Adjuntar imagen» (`variant="secondary"`) con un `<input type="file" accept="image/jpeg,image/png,image/webp">` oculto.
- Valida con `validateImageFile` (SPEC 21) antes de enviar. Un error se muestra bajo el botón en `role="alert"` sin petición de red.
- Durante la subida, el botón muestra «Subiendo imagen…» y queda deshabilitado.
- Al terminar, el registro se refresca y la tarjeta muestra la imagen. El ítem «Imagen asociada» pasa a «Listo».
- Un `422` o `409` muestra el mensaje del backend en `role="alert"`.

### Errores

- `422` al guardar: mensaje junto al campo correspondiente.
- `409 CASH_MISMATCH` al guardar: en el banner de bloqueos, «El servidor rechazó el efectivo: esperado {expected_amount}, contado {cash_amount}, diferencia {difference}.».
- `409 INVALID_RECORD_STATE` al guardar o generar: refresca registro y entrega. La pantalla pasa a solo lectura.
- `409` del Word: en el banner, `documentBlockedMessages[reason]` o el mensaje del backend. Refresca registro y entrega.
- Error de red: «No se pudo guardar la entrega. Inténtalo de nuevo.» o «No se pudo generar el recibo. Inténtalo de nuevo.».

### Estados de la pantalla

- **Cargando:** esqueletos del encabezado, del hero y de dos tarjetas (`Skeleton`). Sin importes.
- **Error al cargar el registro o la entrega:** tarjeta `role="alert"` con el título, el mensaje y «Reintentar» (como hoy).

### Solo lectura (`VIEWER`, o `EDITOR` con registro no `CONFIRMED`)

Mismo encabezado, banner de entrega obsoleta y columnas.

- Hero: «Retorno oficial» y «Efectivo» (efectivo guardado), sin «Documento» ni la franja de verificación.
- «Datos de la entrega» como `<dl>`: fecha (`formatDate`), «Entrega» y «Recibe» («—» si no hay).
- «Desglose de efectivo»: solo filas con cantidad mayor a cero, con cantidad y subtotal, sin stepper, y la fila «Efectivo total».
- «Documento asociado» sin «Adjuntar imagen».
- Sin «Monto del documento», sin checklist y sin barra de acciones.
- Nota bajo el encabezado (`role="note"`, `bg-accent text-accent-foreground`):
  - `VIEWER`: «Tienes acceso de solo lectura. Solo un Editor puede preparar o actualizar la entrega.»;
  - `EDITOR` con registro no `CONFIRMED`: «El registro no está confirmado. La entrega podrá actualizarse cuando vuelva a estar confirmado.».
- Sin entrega guardada, en lugar de las tarjetas: «Aún no existe una preparación de entrega para este registro.» (registro `CONFIRMED`) o «La entrega solo puede prepararse después de confirmar el registro.».

### Diferencias por rol

| Elemento | EDITOR (registro `CONFIRMED`) | VIEWER / registro no `CONFIRMED` |
| --- | --- | --- |
| Datos, monto del documento, stepper | Editables | Solo lectura (sin monto del documento) |
| Checklist y barra de acciones | Sí | No |
| Adjuntar imagen | Sí, sin imagen | No |
| Banner de obsoleta, documento asociado | Sí | Sí |

El backend sigue siendo la autoridad. El rol solo decide qué se muestra.

### Versión móvil

- Una columna en el orden de «Columnas».
- Campos de 48 px. Botones del stepper e input de 44 px. Opciones del monto del documento de 44 px.
- Cada fila de denominación pasa a dos líneas: denominación y subtotal a la izquierda, stepper a la derecha.
- Sin scroll horizontal a 375 px.

## Implementation plan

1. **Utilidades puras y pruebas.**
   - Agregar a `frontend/src/features/deliveries/utils.ts` las funciones de «Data model» y eliminar `documentBlockedReason`.
   - Ampliar `frontend/tests/delivery-utils.test.ts`:
     - `stepQuantity` en 0 con `-1` y en 999 con `+1`;
     - `parseQuantityInput` con letras, vacío y `1500`;
     - `getCashCheck` en los escenarios `coincide`, `cambio` y `faltante` de la plantilla (retorno `9800.00`), y con diferencias de exactamente `$1.00`;
     - `isDeliveryClean` sin entrega, igual y con un nombre con espacios extra;
     - `getDeliveryReadiness`: cada uno de los ocho textos de `hint`, el ítem `change` solo con excedente, y `canSave` / `canGenerate`;
     - `getDeliveryHeading` en los tres casos.
   - Retirar el uso de `documentBlockedReason` en `delivery-document-action.tsx` con la misma lógica de `getDeliveryReadiness`. `npm test` pasa sin cambio visible.
2. **Servicio para adjuntar imagen.**
   - Agregar `recordsService.attachImage` y `useAttachRecordImage`.
   - Prueba con el cliente axios simulado: envía `FormData` con `image` a `records/{id}/image` y convierte un `422` en `RecordsApiError`.
3. **Props de `RecordImageCard` y `userName`.**
   - Agregar `title` y `emptyContent` a `RecordImageCard` sin cambiar su uso en el detalle.
   - Pasar `userName` desde `app/(app)/records/[recordId]/delivery/page.tsx` a `DeliveryScreen`.
   - Verificar a mano que el detalle del registro se ve igual.
4. **Stepper de denominaciones.**
   - Crear `components/delivery-cash-breakdown.tsx` con los grupos «Billetes» y «Monedas», el stepper, subtotales, la fila «Efectivo total» y el aviso de cambio con la casilla.
   - Montarlo en `delivery-form.tsx` en lugar de la tabla de inputs, con el mismo `react-hook-form` (`setValue` en `denominations.{i}.quantity`).
   - Verificar a mano `−`, `+`, escritura manual y el límite de 999.
5. **Layout de edición.**
   - Reescribir la presentación de `delivery-screen.tsx` y `delivery-form.tsx` con el encabezado, los banners, el hero, «Datos de la entrega», «Monto del documento» (`radiogroup`) y las columnas.
   - Crear `components/delivery-readiness-card.tsx` (checklist) y `components/delivery-action-bar.tsx` (barra), alimentados por `getDeliveryReadiness`.
   - Mover la generación del Word a la barra y eliminar `delivery-document-action.tsx`.
   - Precargar «Entrega» con `userName` cuando no hay entrega.
   - Verificar los escenarios `coincide`, `cambio` y `faltante`, el guardado, la generación y el texto de la barra en cada paso.
6. **Documento asociado y adjuntar imagen.**
   - Crear `components/delivery-image-card.tsx`: usa `RecordImageCard` con `title="Documento asociado"` y, sin imagen, el texto y «Adjuntar imagen» con `useAttachRecordImage`.
   - Verificar con un registro manual sin imagen: rechazo de tipo y tamaño, subida, refresco de la tarjeta y desbloqueo de «Generar recibo (Word)».
7. **Solo lectura y entrega obsoleta.**
   - Crear `components/delivery-read-only.tsx` con el layout de «Solo lectura» y reemplazar el `DeliveryReadOnly` actual.
   - Montar el banner de entrega obsoleta y el título «Preparar nueva entrega».
   - Verificar como `VIEWER`, como `EDITOR` con un registro reabierto (`NEEDS_REVIEW`) y con una entrega obsoleta tras reconfirmar con otro retorno.
8. **Errores y estados de carga.**
   - Esqueletos de carga, `422` junto al campo, `409 CASH_MISMATCH`, `409 INVALID_RECORD_STATE` y `409` del Word en el banner.
   - Verificar forzando un `CASH_MISMATCH` con el registro modificado en otra pestaña.
9. **Revisión visual.**
   - Revisar `/records/{id}/delivery` como `EDITOR` y `VIEWER`, en claro y oscuro, a 1440, 1024, 768 y 375 px.
   - Comparar con `DeliveryPrepare`, `DeliveryPrepareMobile` y `DeliveryUpdate` con `imagen` en `con` y `sin`.
   - Ajustar solo espaciados y tamaños.
10. **Documentación.**
    - Actualizar `docs/frontend/features/deliveries.md`: layout, barra única, checklist, adjuntar imagen, precarga de «Entrega» y lo que queda fuera.
    - Actualizar `docs/frontend/05_ui_and_accessibility.md`: `radiogroup` del monto del documento, `aria-label` del stepper y la barra fija sobre `AppBottomNav`.
    - Actualizar `docs/10_ui_ux.md` §8 (nueva pantalla) y §9 (adjuntar imagen a un registro manual desde la entrega).

## Acceptance criteria

- [ ] `/records/{id}/delivery` tiene exactamente un `<h1>`: «Preparar entrega» sin entrega o con entrega vigente, y «Preparar nueva entrega» con entrega obsoleta.
- [ ] El encabezado muestra el enlace «Registro» hacia `/records/{id}`, el estado, la fuente y la línea de periodo.
- [ ] El hero muestra «Retorno oficial», «Documento» y «Efectivo», y su franja cambia entre «Coincide con el monto del documento», «Excede por {monto} · requiere declarar cambio» y «Faltan {monto} frente al monto del documento».
- [ ] Con retorno `$9,800.00`, las opciones del monto del documento son `$9,800.00`, `$9,800.50` y `$9,801.00`; la primera viene seleccionada.
- [ ] El monto del documento es un `radiogroup` y las flechas cambian la opción.
- [ ] En una entrega nueva, «Fecha de entrega» trae la fecha de hoy y «Entrega» el nombre del usuario en sesión.
- [ ] Cada denominación tiene `−`, input y `+`; `−` está deshabilitado en 0; el input acepta escritura manual, ignora letras y no pasa de 999.
- [ ] Los subtotales de «Billetes» y «Monedas», el número de piezas y «Efectivo total» coinciden con las cantidades.
- [ ] Con efectivo más de `$1.00` por debajo del retorno oficial, aparece el banner con `CASH_MISMATCH` y «Guardar entrega» está deshabilitado.
- [ ] Con efectivo más de `$1.00` por encima del monto del documento, aparece el aviso con `CHANGE_REQUIRED` y la casilla «El cliente dará cambio · {monto} (calculado)».
- [ ] Cambiar una cantidad o el monto del documento desmarca la casilla de cambio.
- [ ] «Guardar entrega» no aparece cuando el formulario coincide con la entrega guardada.
- [ ] «Generar recibo (Word)» está deshabilitado con cambios sin guardar, sin imagen, con faltante frente al documento o con excedente sin declarar.
- [ ] La checklist muestra «Imagen asociada», «Efectivo conciliado con el retorno oficial», «Efectivo conciliado con el monto del documento», «Cambio al cliente declarado» (solo con excedente) y «Entrega guardada», con «Listo» o «Pendiente».
- [ ] El texto de la barra coincide con `getDeliveryReadiness().hint` en cada estado, y tras descargar el Word muestra «Recibo generado.».
- [ ] Guardar envía el mismo cuerpo de SPEC 11; tras el `200`, la barra deja de mostrar el botón de guardar.
- [ ] Generar descarga el `.docx` con el monto del documento y la declaración de cambio elegidos.
- [ ] Un `409 CASH_MISMATCH` al guardar muestra los importes esperado, contado y diferencia del backend.
- [ ] Un `409 INVALID_RECORD_STATE` refresca la pantalla y la deja en solo lectura.
- [ ] Con entrega obsoleta aparece el banner con «Retorno actual» y «Entrega preparada», y el botón dice «Actualizar entrega».
- [ ] En un registro sin imagen, «Documento asociado» muestra el texto de la plantilla y, para `EDITOR`, «Adjuntar imagen».
- [ ] «Adjuntar imagen» rechaza un `image/gif` o un archivo de 10 MiB + 1 byte sin petición de red.
- [ ] Una imagen válida se sube a `POST /api/records/{id}/image`, la tarjeta la muestra y «Imagen asociada» pasa a «Listo», sin recargar la página.
- [ ] Como `VIEWER` no hay inputs, monto del documento, checklist, barra ni «Adjuntar imagen», y aparece la nota de solo lectura.
- [ ] En solo lectura, el desglose muestra solo las denominaciones con cantidad mayor a cero.
- [ ] Con contenedor de 60 rem o más hay dos columnas; por debajo, una columna en el orden de «Columnas».
- [ ] A 375 px no hay scroll horizontal, la barra queda por encima de `AppBottomNav` y no tapa el último contenido.
- [ ] Todos los controles se alcanzan con Tab y muestran un anillo de foco de 2 px, incluidos el stepper, las opciones del monto y la casilla de cambio.
- [ ] Los archivos nuevos o modificados de `features/deliveries/` solo usan clases de tokens, sin colores literales ni de la paleta de Tailwind.
- [ ] `delivery-document-action.tsx` y `documentBlockedReason` ya no existen.
- [ ] El detalle del registro sigue mostrando «Imagen recibida» igual que antes.
- [ ] No hay cambios en `backend/`.
- [ ] La consola no muestra advertencias de hidratación en `/records/{id}/delivery`.
- [ ] `npm run lint`, `npx tsc --noEmit`, `npm test` y `npm run build` en `frontend/` pasan sin errores.
- [ ] La documentación describe el nuevo comportamiento.

## Decisions

- **Sí:** `DeliveryPrepare` es la base de la pantalla. `DeliveryPrepareMobile` y `DeliveryUpdate` son el mismo componente con otros props y no generan pantallas aparte.
- **Sí:** solo frontend. Todo lo que pide la plantilla ya existe en la API (SPEC 05, SPEC 11, SPEC 12, SPEC 14).
- **Sí:** stepper `−` / `+` con input editable a mano. La plantilla usa stepper; el input manual evita pulsar `+` decenas de veces.
- **Sí:** barra de acciones única con guardar y Word. Reemplaza la sección «Documento Word» y elimina `delivery-document-action.tsx`.
- **Sí:** checklist «Para generar el recibo». Comparte la función pura con el texto de la barra, así que su costo es solo de presentación.
- **Sí:** `getDeliveryReadiness` reemplaza `documentBlockedReason` y la lógica dispersa en `DeliveryDocumentAction`. Una sola fuente decide qué falta.
- **Sí:** «Adjuntar imagen» desde la entrega con `POST /api/records/{id}/image`. El endpoint existe (SPEC 05), no lanza IA y no cambia el estado del registro. SPEC 21 lo dejaba fuera; esta spec lo incluye solo en esta pantalla y solo para registros sin imagen.
- **No:** reemplazar una imagen existente. El backend lo bloquea tras procesar IA y no lo pide la plantilla.
- **Sí:** «Entrega» se precarga con el nombre del usuario en sesión en una entrega nueva. Lo pidió el usuario; la plantilla muestra el nombre del Editor.
- **Sí:** «Fecha de entrega» usa `<input type="date">`, como hoy. La plantilla usa texto `dd/mm/aaaa`; el input nativo evita parsear fechas.
- **Sí:** el guardado se bloquea en el cliente con efectivo más de `$1.00` por debajo del retorno oficial, igual que valida el backend (SPEC 14). El backend sigue siendo la autoridad.
- **Sí:** los códigos `CASH_MISMATCH` y `CHANGE_REQUIRED` se muestran como en la plantilla.
- **Sí:** solo lectura con el mismo layout y sin inputs, para `VIEWER` y para registros no `CONFIRMED`. Elegido sobre inputs deshabilitados por legibilidad y accesibilidad.
- **Sí:** dos columnas desde 60 rem del contenedor, como SPEC 21. La plantilla usa 1359 px de viewport porque incluye la sidebar.
- **Sí:** la barra se fija en móvil con `bottom-[calc(5.25rem+env(safe-area-inset-bottom))]`, como la barra «Guardar corrección» de SPEC 21.
- **No:** persistir el monto del documento o la declaración de cambio. Siguen siendo efímeros (SPEC 14).
- **No:** aviso de cambios sin guardar al salir. Agrega complejidad y la barra ya indica que falta guardar.

## Risks

| Risk | Mitigation |
| --- | --- |
| La lógica de `getDeliveryReadiness` difiere de la validación del backend y deja pasar un Word que el servidor rechaza. | Los `409` del Word se muestran con `documentBlockedMessages` y refrescan la pantalla. Las pruebas cubren los límites de `$1.00`. |
| El stepper con `setValue` no marca el formulario como modificado y la barra no detecta cambios. | `isDeliveryClean` compara los valores observados (`useWatch`), no `formState.isDirty`. |
| La barra fija tapa el último contenido o `AppBottomNav` en móvil. | Padding inferior en la página y la misma posición que SPEC 21. El paso 9 lo revisa a 375 px. |
| Cambiar `RecordImageCard` altera el detalle del registro. | Las props nuevas son opcionales con los valores de hoy. Un criterio de aceptación lo verifica. |
| Adjuntar imagen a un registro ya procesado por IA devuelve `409`. | Solo se ofrece sin imagen. Un `409` muestra el mensaje del backend. |
| El nombre del usuario en sesión no coincide con quien entrega. | El campo es editable y obligatorio. |

## What is **not** in this spec

- Cambios de backend, API o reglas de negocio.
- Reemplazar una imagen existente.
- Persistir el monto del documento o la declaración de cambio.
- Aviso de cambios sin guardar al salir.
- Vista previa del Word.
- Cambios a la tarjeta «Entrega» del detalle, al shell u otras pantallas.

Cada uno de esos puntos, si se hace, va en su propia spec.
