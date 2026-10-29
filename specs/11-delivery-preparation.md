# SPEC 11 — Preparación de entrega

> **Status:** Approved
> **Depends on:** SPEC 04, SPEC 09, SPEC 10
> **Date:** 2026-10-26
> **Objective:** Implementar la preparación editable de una entrega por registro confirmado, validar que el desglose de efectivo cuadre con el retorno actual y auditar sus cambios.

## Why this spec exists

La fase 8.11 dejó explícitamente pendiente el historial de `Delivery` para 8.12. La entidad existe desde 8.3, pero no hay API ni flujo para preparar la entrega. Esta spec cierra ese hueco sin adelantar la generación de Word de 8.13 ni el diseño oficial de 8.15.

## Scope

**In:**

- Exponer `GET /api/records/{record}/delivery` para `EDITOR` y `VIEWER`.
- Exponer `PUT /api/records/{record}/delivery` para `EDITOR`, creando o actualizando la única entrega del registro.
- Permitir la preparación solo para registros `CONFIRMED`.
- Validar fecha, nombres y desglose de denominaciones oficiales.
- Calcular `prepared_return_amount` en backend desde el retorno actual del registro; no aceptarlo del cliente.
- Detectar preparación obsoleta comparando `prepared_return_amount` con el retorno actual.
- Actualizar la misma fila `Delivery` cuando el retorno cambió; sin versiones ni estados de entrega.
- Registrar historial campo a campo de la entrega con `delivery_id`.
- Añadir UI mínima en `/records/[recordId]/delivery`, enlazada desde el detalle del registro confirmado.
- Actualizar pruebas backend/frontend, documentación y checkpoint de 8.12.

**Out of scope (for future specs):**

- Generación de documentos Word y descarga; corresponde a 8.13.
- Reportes, Excel y hojas de cálculo; corresponde a 8.14.
- Versiones de entrega, estados de entrega, borrado o endpoints de eliminación.
- Entidad `DeliveryImage` o carga de imágenes desde la pantalla de entrega.
- Diseño oficial de UI/UX y navegación final; corresponde a 8.15.
- Cambios a las reglas de registros, pipeline AI o imágenes.
- Múltiples entregas por registro.
- Backfill de entregas o de historial anterior.
- Requerir imagen para preparar la entrega; la imagen solo será obligatoria para Word en 8.13.

## Data model

No se agregan tablas ni migraciones. Se reutilizan `Delivery`, `DeliveryCashDenomination` y `History`.

```text
Delivery
  id
  record_id                 único; una entrega por Record
  delivery_date             date, requerida
  delivered_by_name         string, requerido
  received_by_name          string, nullable
  prepared_return_amount    decimal(12,2), derivado del retorno actual al guardar
  created_at
  updated_at

DeliveryCashDenomination
  delivery_id               requerido
  value                     decimal(10,2), denominación oficial
  quantity                  integer, solo se persisten cantidades > 0
```

Denominaciones oficiales del MVP:

```text
1000, 500, 200, 100, 50, 20, 10, 5, 2, 1, 0.50
```

Reglas de persistencia:

- `prepared_return_amount` siempre se recalcula en backend con el retorno actual del `Record` al crear o actualizar la entrega.
- El retorno actual se calcula sumando `RecordDetail.return_amount`; no se persiste como fuente de verdad del `Record`.
- Solo se persisten filas con `quantity > 0`.
- `UNIQUE(delivery_id, value)` se mantiene.
- Un arreglo vacío de denominaciones es válido solo cuando el retorno actual es `0.00`.
- `received_by_name` vacío o solo espacios se normaliza a `null`.
- La preparación no modifica `Record.status`, sus campos ni sus movimientos.
- No hay estados, versiones ni borrado de `Delivery`.

### Historial de entrega

`History` ya tiene `delivery_id`; en esta spec deja de ser `null` para los eventos de entrega. Cada fila mantiene `record_id`, `delivery_id`, `user_id`, `field_name`, `previous_value`, `new_value`, `reason` y `created_at`. `record_detail_id` permanece `null`.

| Acción | `field_name` | `previous_value` | `new_value` | `reason` |
| --- | --- | --- | --- | --- |
| Crear entrega | una fila por campo persistido | `null` | valor canónico | `Preparación de entrega creada` |
| Actualizar con cambios | una fila por campo modificado | valor anterior | valor nuevo | `Preparación de entrega actualizada` |
| Actualizar sin cambios | ninguna | — | — | — |

Campos auditados:

- `delivery_date`
- `delivered_by_name`
- `received_by_name`
- `prepared_return_amount`
- `denominations[1000.00]`, `denominations[500.00]`, …, `denominations[0.50]`

Convenciones de valores:

- Fechas: `Y-m-d`.
- Importes: texto decimal con dos decimales, por ejemplo `9800.00`.
- Cantidades: texto entero, por ejemplo `5`.
- Nombres: texto recortado.
- Las denominaciones se identifican por su valor canónico con dos decimales.
- Los valores se comparan después de canonicalizarlos; diferencias solo de formato no generan eventos.
- Una denominación agregada genera `null -> cantidad`; una eliminada genera `cantidad -> null`.
- Los eventos de entrega aparecen en el historial existente del registro, ordenados por fecha.

## API contract

Todas las rutas requieren sesión Sanctum. Las mutaciones requieren CSRF y rol `EDITOR`.

| Método | Ruta | Comportamiento | Autorización |
| --- | --- | --- | --- |
| `GET` | `/api/records/{record}/delivery` | Devuelve la entrega del registro o `data: null`. | `EDITOR` o `VIEWER` |
| `PUT` | `/api/records/{record}/delivery` | Crea o reemplaza la única entrega del registro confirmado. | `EDITOR` |

### Consulta

`GET /api/records/{record}/delivery` responde `200` con:

```json
{
  "data": {
    "id": 3,
    "record_id": 12,
    "delivery_date": "2026-09-18",
    "delivered_by_name": "Maria Lopez",
    "received_by_name": "Juan Perez",
    "prepared_return_amount": "9800.00",
    "current_return_amount": "10000.00",
    "is_stale": true,
    "denominations": [
      { "value": "1000.00", "quantity": 5 },
      { "value": "500.00", "quantity": 5 },
      { "value": "200.00", "quantity": 10 },
      { "value": "100.00", "quantity": 3 }
    ],
    "created_at": "2026-09-18T12:00:00.000000Z",
    "updated_at": "2026-09-18T12:00:00.000000Z"
  }
}
```

Si el registro no tiene entrega:

```json
{ "data": null }
```

Reglas:

- `current_return_amount` se calcula desde los movimientos actuales del registro.
- `is_stale` es `true` cuando `prepared_return_amount` no coincide con `current_return_amount`.
- Las denominaciones se ordenan de mayor a menor valor.
- No se exponen rutas privadas, imágenes, tokens ni datos de AIProcessing.
- `401` sin sesión; `404` registro inexistente.
- Un registro en cualquier estado puede consultarse; la edición sigue restringida a `CONFIRMED`.

### Creación y actualización

`PUT /api/records/{record}/delivery` reemplaza la preparación completa:

```json
{
  "delivery_date": "2026-09-18",
  "delivered_by_name": "Maria Lopez",
  "received_by_name": "Juan Perez",
  "denominations": [
    { "value": "1000.00", "quantity": 5 },
    { "value": "500.00", "quantity": 5 },
    { "value": "200.00", "quantity": 10 },
    { "value": "100.00", "quantity": 3 }
  ]
}
```

Validación HTTP:

- `delivery_date`: requerido, fecha válida.
- `delivered_by_name`: requerido, recortado, no vacío, máximo 255.
- `received_by_name`: opcional, nullable, recortado, máximo 255; vacío se normaliza a `null`.
- `denominations`: requerido, arreglo; puede ser vacío solo si el retorno es `0.00`.
- `denominations.*.value`: requerido, decimal con hasta dos decimales, dentro de la lista oficial.
- `denominations.*.quantity`: requerido, entero, mínimo `0`.
- Se descartan las filas con `quantity = 0` antes de validar duplicados.
- Dos filas positivas con el mismo valor normalizado devuelven `422`.
- Valores negativos, no oficiales o cantidades no enteras devuelven `422`.
- `prepared_return_amount`, `record_id`, `status`, `source`, `reason` y totales enviados por el cliente quedan prohibidos.

Validación de negocio y persistencia:

- Solo se acepta un registro `CONFIRMED`. Cualquier otro estado devuelve `409 DELIVERY_PREPARATION_BLOCKED` con `details.reason=INVALID_RECORD_STATE`.
- El backend bloquea el `Record` y la `Delivery` existente, recalcula el retorno actual y valida que la suma de denominaciones coincida exactamente, en centavos.
- Si la suma no coincide, devuelve `409 DELIVERY_PREPARATION_BLOCKED` con `details.reason=CASH_MISMATCH`, `expected_amount`, `cash_amount` y `difference`.
- Si la entrega no existe, se crea.
- Si existe, se actualiza la misma fila y se reemplazan sus denominaciones.
- `prepared_return_amount` se fija al retorno actual en cada guardado exitoso.
- Si el retorno actual cambió desde la preparación anterior, la actualización es válida siempre que el nuevo desglose cuadre; no se crea una nueva entrega.
- Si la entrega resultante es idéntica a la existente, responde `200` sin escribir `History` y sin cambiar `updated_at`.
- La entrega, sus denominaciones y su historial se escriben en una sola transacción.
- Un fallo de persistencia revierte la entrega, las denominaciones y el historial.
- La respuesta usa el mismo recurso que `GET`, con `200` tanto para creación como para actualización.

Errores:

- `401` sin sesión.
- `403` si `VIEWER` intenta `PUT`.
- `404` registro inexistente.
- `419` CSRF inválido.
- `422` por forma, fecha, nombres, denominación no oficial, duplicado o cantidad inválida.
- `409 DELIVERY_PREPARATION_BLOCKED` con `details.reason=INVALID_RECORD_STATE` o `CASH_MISMATCH`.

## UI contract

- Nueva ruta `/records/[recordId]/delivery`, protegida por sesión; `EDITOR` y `VIEWER` pueden acceder.
- El detalle `/records/[id]` muestra un enlace `Preparación de entrega` para registros `CONFIRMED`:
  - `EDITOR`: `Preparar entrega` o `Actualizar entrega`.
  - `VIEWER`: `Ver entrega`.
- La pantalla carga el registro y la entrega. Muestra empresa, semana/año, retorno oficial actual, disponibilidad de imagen y fecha/nombres.
- El formulario muestra las 11 denominaciones oficiales con cantidad; calcula el total de efectivo para retroalimentación visual, sin ser autoridad.
- Si la entrega está obsoleta (`is_stale=true`), muestra una advertencia clara y permite actualizarla cuando el registro vuelve a estar `CONFIRMED`.
- Si el total no coincide, muestra `expected_amount`, `cash_amount` y `difference` devueltos por el backend.
- `EDITOR` puede guardar solo cuando el registro está `CONFIRMED`.
- `VIEWER` ve la preparación en modo lectura y no ve acciones de guardado.
- Si el registro no está `CONFIRMED` y no existe entrega, muestra que la entrega solo puede prepararse tras la confirmación.
- Tras guardar, invalida la consulta de entrega y el historial del registro.
- Un `409 INVALID_RECORD_STATE` refresca el registro y deshabilita la edición.
- No se incluye botón ni generación de Word; corresponde a 8.13.
- El diseño visual definitivo queda para 8.15.

El panel `Historial de cambios` del detalle debe reconocer y formatear los campos de entrega:

- `delivery_date` → `Fecha de entrega`.
- `delivered_by_name` → `Entrega realizada por`.
- `received_by_name` → `Recibido por`.
- `prepared_return_amount` → `Importe preparado`.
- `denominations[1000.00]` → `Denominación $1,000.00`.

## Implementation plan

1. Actualizar `docs/02_business_rules_and_states.md`, `docs/03_data_model.md`, `docs/backend/03_api_contract.md`, `docs/backend/05_validation_authorization.md`, `docs/backend/06_domain_workflows.md`, `docs/frontend/features/deliveries.md`, `docs/frontend/features/records.md` y `docs/frontend/06_testing.md` con el contrato de entrega.
2. Crear `App\Domain\Deliveries\DeliveryValidator` con denominaciones oficiales, aritmética en centavos, cálculo del retorno actual, validación de suma y canonicalización; cubrirlo con pruebas unitarias.
3. Crear `App\Domain\Deliveries\DeliveryHistoryService` para snapshot, diff y escritura de eventos con `delivery_id`; cubrirlo con pruebas unitarias.
4. Añadir `UpsertDeliveryRequest`, `DeliveryResource`, `DeliveryController` (`show`, `upsert`) y las rutas `GET`/`PUT` en `routes/api.php`.
5. Extender `RecordPolicy` con `viewDelivery` y `prepareDelivery`; aplicar autorización en request y controlador.
6. Implementar `show`: cargar la entrega con denominaciones, calcular retorno actual y `is_stale`, y responder `data: null` cuando no exista.
7. Implementar `upsert`: transacción con bloqueo de `Record` y `Delivery`, validación de estado y suma, reemplazo de denominaciones, historial y respuesta idempotente.
8. Añadir pruebas backend de autorización, consulta nula, creación, actualización, stale, no-op, ceros, denominaciones inválidas/duplicadas, mismatch, estados bloqueados, rollback e historial.
9. Añadir tipos, servicio, hooks, esquema Zod y componentes del feature `deliveries`; crear `app/(app)/records/[recordId]/delivery/page.tsx`.
10. Enlazar la pantalla desde `RecordDetailScreen` para registros `CONFIRMED` y ampliar `RecordHistoryPanel` con las etiquetas y formatos de entrega.
11. Añadir pruebas Vitest de contrato, esquema, errores `409` y cálculo de totales; ejecutar backend, Pint, Vitest, ESLint, TypeScript y build.
12. Verificar el checkpoint en navegador: crear entrega válida, rechazar mismatch, actualizar entrega obsoleta, comprobar historial y lectura `VIEWER`; documentar el resultado y actualizar 8.12 solo si todo pasa.

## Acceptance criteria

- [ ] No se agregan tablas ni migraciones.
- [ ] `GET /api/records/{record}/delivery` responde `data: null` o la entrega, para `EDITOR` y `VIEWER`.
- [ ] `PUT /api/records/{record}/delivery` solo permite `EDITOR`; `VIEWER` recibe `403`.
- [ ] `PUT` solo acepta registros `CONFIRMED`; otros estados reciben `409 DELIVERY_PREPARATION_BLOCKED` con `INVALID_RECORD_STATE`.
- [ ] El backend fija `prepared_return_amount` desde el retorno actual y el cliente no puede enviarlo.
- [ ] Una entrega válida se guarda y la suma de denominaciones coincide con el retorno actual.
- [ ] Un desglose que no coincide recibe `409 CASH_MISMATCH` con importe esperado, total de efectivo y diferencia, sin escrituras parciales.
- [ ] Solo se persisten denominaciones oficiales con cantidad positiva.
- [ ] Las cantidades cero se ignoran; un arreglo vacío es válido solo con retorno `0.00`.
- [ ] Valores no oficiales, negativos o duplicados positivos reciben `422`.
- [ ] Una segunda preparación actualiza la misma fila; no se crean versiones ni duplicados.
- [ ] Si el retorno cambia, `GET` devuelve `is_stale=true` y `PUT` permite actualizar la misma entrega con el nuevo importe.
- [ ] Un `PUT` idéntico responde `200` sin historial y sin cambiar `updated_at`.
- [ ] Crear y actualizar escriben eventos de `History` con `delivery_id`, `record_id`, usuario, motivo fijo y valores canónicos.
- [ ] El historial existente del registro incluye y muestra correctamente los eventos de entrega.
- [ ] Un fallo de persistencia revierte entrega, denominaciones e historial.
- [ ] `/records/[recordId]/delivery` funciona para `EDITOR` y `VIEWER`; `VIEWER` solo lectura.
- [ ] El detalle enlaza la preparación para registros `CONFIRMED`.
- [ ] La UI muestra retorno actual, total de efectivo, advertencia de entrega obsoleta, mismatch y estados de carga/error/éxito.
- [ ] La UI invalida entrega e historial tras guardar.
- [ ] No se implementa generación de Word en esta spec.
- [ ] Suite backend, Pint, Vitest, ESLint, TypeScript y build pasan.
- [ ] El checkpoint de navegador se documenta y 8.12 se marca completo solo entonces.

## Decisions

- **Sí:** una sola `Delivery` editable por `Record`; `PUT` crea o actualiza.
- **Sí:** `GET` devuelve `200` con `data: null` cuando no existe entrega.
- **Sí:** `EDITOR` y `VIEWER` pueden consultar; solo `EDITOR` puede preparar.
- **Sí:** el backend deriva `prepared_return_amount` del retorno actual; el cliente no lo controla.
- **Sí:** `is_stale` compara `prepared_return_amount` con el retorno actual.
- **Sí:** una entrega obsoleta se actualiza en la misma fila; sin versiones ni estados.
- **Sí:** historial campo a campo con `delivery_id` y motivos fijos de creación/actualización.
- **Sí:** las denominaciones se auditan por valor canónico con dos decimales.
- **Sí:** las cantidades cero se ignoran y un retorno `0.00` admite desglose vacío.
- **Sí:** el mismatch de efectivo es un error de negocio `409`, no un `422` de forma.
- **Sí:** un `PUT` sin cambios responde `200` sin historial.
- **Sí:** UI mínima en ruta dedicada `/records/[recordId]/delivery`; el diseño oficial queda para 8.15.
- **No:** versiones de entrega, estados de entrega o borrado.
- **No:** exigir imagen para preparar la entrega; solo será necesaria para Word.
- **No:** aceptar `prepared_return_amount`, `status`, `source`, `reason` o totales desde el cliente.
- **No:** generar Word, Excel o reportes en esta spec.
- **No:** modificar `Record.status` ni los movimientos al preparar la entrega.

## Risks

| Risk | Mitigation |
| --- | --- |
| Diferencias de redondeo entre PHP y JavaScript al sumar denominaciones. | Calcular y validar en centavos en backend; la UI solo muestra un total informativo. |
| El retorno del registro cambia entre la carga y el guardado. | Bloquear el `Record`, recalcular el retorno dentro de la transacción y devolver `409` si el desglose ya no coincide. |
| Una entrega queda obsoleta tras reabrir el registro. | `GET` expone `is_stale`; la UI advierte y permite actualizar cuando el registro vuelve a `CONFIRMED`. |
| El cliente envía las 11 denominaciones con ceros. | El backend descarta ceros antes de validar duplicados y persistir. |
| Historial de entrega demasiado verboso en la creación. | Una fila por campo persistido, con motivos fijos y paginación existente del historial. |
| Un fallo al escribir historial deja entrega o denominaciones parciales. | Transacción única con bloqueo y rollback completo. |
| Un `VIEWER` intenta mutar directamente la API. | Autorización backend independiente de la visibilidad de la UI. |
| La pantalla de entrega se usa con un registro no confirmado. | Ruta de solo lectura y `PUT` bloqueado con `409 INVALID_RECORD_STATE`. |

## What is **not** in this spec

- Generación de Word, descarga de documentos o `POST /api/deliveries/{delivery}/document`; corresponde a 8.13.
- Excel, reportes o exportaciones; corresponde a 8.14.
- Diseño oficial de UI/UX, navegación final o pulido visual; corresponde a 8.15.
- Versiones de entrega, estados de entrega, borrado o múltiples entregas por registro.
- `DeliveryImage`, carga o reemplazo de imágenes desde la pantalla de entrega.
- Requerir imagen para preparar la entrega.
- Cambios a la validación de registros, pipeline AI o reglas de imágenes.
- Backfill de entregas o historial anterior.
- Edición o borrado de eventos de historial.
