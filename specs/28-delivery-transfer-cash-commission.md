# SPEC 28 — Retorno por transferencia y efectivo con comisión de efectivo

> **Status:** Approved
> **Depends on:** SPEC 11, SPEC 12, SPEC 14, SPEC 22, SPEC 27
> **Date:** 2026-11-26
> **Objective:** Permitir que la entrega divida el retorno oficial entre transferencia y efectivo, y aplicar una comisión adicional, capturada por el Editor, solo a la parte en efectivo.

## Why this spec exists

Hasta ahora todo el retorno oficial (`Record.return_amount`, SPEC 27) se entregaba en efectivo. El desglose de denominaciones se cuadraba contra ese monto. La operación cambió: el cliente puede recibir una parte por transferencia y otra en efectivo. La transferencia solo lleva la comisión base, que ya está descontada en el retorno oficial. El efectivo que pide el cliente paga una comisión extra.

Ejemplo de referencia:

```text
Retorno oficial (después de la comisión base)   200.00
Efectivo que pide el cliente                    100.00
Por transferencia = 200.00 − 100.00             100.00
Comisión extra 10% = 100.00 × 10%                10.00
Efectivo a entregar = 100.00 − 10.00             90.00
```

El cambio es solo para la entrega. El retorno oficial del registro, los reportes, el Excel y el dashboard no cambian.

Las plantillas de diseño ya existen: `docs/design/templates/DeliveryPrepare.dc.html`, `DeliveryPrepareMobile.dc.html` y `DeliveryUpdate.dc.html` (entrega obsoleta).

## Scope

**In:**

- Cuatro columnas nuevas en `deliveries`: `requested_cash_amount`, `cash_commission_percentage`, `cash_commission` y `cash_amount`. Se edita la migración base `000009` y se reconstruye la base, sin migrar datos.
- `PUT /api/records/{record}/delivery` recibe `requested_cash_amount` y `cash_commission_percentage`. Laravel calcula la transferencia, la comisión extra y el efectivo a entregar. El cliente nunca envía los derivados.
- El desglose de denominaciones se cuadra contra el efectivo a entregar (`cash_amount`), con la tolerancia de SPEC 14 (hasta `$1.00` abajo o cualquier excedente al guardar).
- El ajuste documental de SPEC 14 (`.00`, `.50`, siguiente peso) y el cambio del cliente se derivan de `cash_amount`, no del retorno oficial.
- `prepared_return_amount` sigue siendo el retorno oficial y sigue sirviendo para detectar una entrega obsoleta.
- Historial campo a campo de `requested_cash_amount` y `cash_commission_percentage`.
- `GET /api/records/{record}/delivery` expone los campos nuevos, incluida la transferencia derivada.
- Word: una tabla nueva `TIPO | COMISIÓN | RETORNO` con las filas Transferencia y Efectivo. La frase inicial usa transferencia + importe documental del efectivo. Si todo va por transferencia, se omite la tabla de denominaciones.
- Frontend de `/records/[recordId]/delivery` según las plantillas: sección «Forma de retorno» con los dos campos enlazados, atajos, barra de proporción y resumen; guía de efectivo contra «Efectivo a entregar»; estado sin efectivo; estado obsoleto. La vista de solo lectura (`VIEWER`) muestra el reparto.
- Actualizar `docs/business-rules.md`, `docs/data-model.md`, `docs/backend/api-contract.md`, `docs/document-generation.md`, `docs/frontend/features/deliveries.md`, `docs/design/README.md`, `docs/roadmap.md` y `docs/frontend/verification-log.md`.

**Out of scope (for future specs):**

- Reflejar la transferencia o la comisión extra en reportes, Excel o dashboard. Siguen usando `Record.return_amount`.
- Datos de la transferencia: banco, CLABE, referencia o fecha. Solo se guarda el monto.
- Porcentaje de efectivo por empresa, global o tomado del registro. Lo captura el Editor en cada entrega.
- Otros cambios al Word: encabezado, imagen, firmas, fechas o formato del importe en palabras.
- Cambios a `Record`, `RecordDetail`, la extracción por IA o la comisión base.
- Varias transferencias por entrega o varias entregas por registro.
- Migrar entregas existentes.
- Cambios a `record-delivery-card.tsx` en el detalle del registro.

## Data model

### Migración base `2026_10_08_000009_create_deliveries_table.php` (se edita; la base se reconstruye)

Se agrega después de `prepared_return_amount`:

```php
$table->decimal('requested_cash_amount', 12, 2);      // CHECK >= 0
$table->decimal('cash_commission_percentage', 5, 2);  // CHECK 0 <= x <= 100
$table->decimal('cash_commission', 12, 2);            // CHECK >= 0
$table->decimal('cash_amount', 12, 2);                // CHECK >= 0
```

Las cuatro columnas son `NOT NULL`. Más un `CHECK (requested_cash_amount <= prepared_return_amount)`.

### Cálculo

Todo en Laravel, con `Money` (aritmética en centavos, `ROUND_HALF_UP` de SPEC 25):

```text
transfer_amount = prepared_return_amount − requested_cash_amount
cash_commission = Money::applyPercentage(requested_cash_amount, cash_commission_percentage)   // redondeo a centavos
cash_amount     = requested_cash_amount − cash_commission
```

- La entrada guardada es `requested_cash_amount`. `transfer_amount` no se guarda: es una resta exacta.
- `cash_commission` y `cash_amount` se guardan y se recalculan en cada `PUT`.
- Una clase nueva `App\Domain\Deliveries\DeliverySplit` recibe el retorno oficial, el efectivo solicitado y el porcentaje. Devuelve `transfer_amount`, `cash_commission` y `cash_amount`. La usan `PrepareDelivery`, `DeliveryResource` y la generación de Word.

Casos:

| Retorno | Efectivo solicitado | % | Transferencia | Comisión | Neto |
| --- | --- | --- | --- | --- | --- |
| `200.00` | `100.00` | `10.00` | `100.00` | `10.00` | `90.00` |
| `200.00` | `200.00` | `10.00` | `0.00` | `20.00` | `180.00` |
| `200.00` | `0.00` | `10.00` | `200.00` | `0.00` | `0.00` |
| `200.00` | `100.00` | `0.00` | `100.00` | `0.00` | `100.00` |
| `100.05` | `100.05` | `6.50` | `0.00` | `6.50` (`6.50325`) | `93.55` |

### Reglas de efectivo (sustituyen a las de SPEC 11/14 donde se refieren al retorno)

- Al guardar, la suma de denominaciones puede quedar hasta `$1.00` por debajo de `cash_amount` o cualquier monto arriba. Un faltante mayor bloquea el guardado con el `CASH_MISMATCH` actual.
- Un desglose vacío es válido solo cuando `cash_amount` es `0.00`.
- Con `cash_amount > 0`, las tres opciones documentales de SPEC 14 se calculan desde `cash_amount`.
- Con `cash_amount = 0.00`, el único importe documental válido es `0.00` y no hay cambio del cliente.
- El cambio del cliente es efectivo bruto menos importe documental, igual que en SPEC 14.

### Entrega obsoleta

Si el retorno del registro cambia, `transfer_amount` se sigue leyendo contra el `prepared_return_amount` guardado. Al volver a preparar, el formulario conserva el efectivo solicitado y la transferencia pasa a `retorno actual − efectivo solicitado` (nota de `DeliveryUpdate.dc.html`). Si el efectivo solicitado supera el retorno actual, el guardado se bloquea con `CASH_REQUEST_EXCEEDS_RETURN`.

### Historial

Campos auditados nuevos, con dos decimales y las razones existentes (`Preparación de entrega creada` / `Preparación de entrega actualizada`):

- `requested_cash_amount`
- `cash_commission_percentage`

`transfer_amount`, `cash_commission` y `cash_amount` no generan eventos propios porque son derivados. Es el mismo criterio que SPEC 27 aplicó a la comisión del registro.

## API contract

### `PUT /api/records/{record}/delivery`

```json
{
  "delivery_date": "2026-11-26",
  "delivered_by_name": "Maria Lopez",
  "received_by_name": "Juan Perez",
  "requested_cash_amount": "100.00",
  "cash_commission_percentage": "10.00",
  "denominations": [
    { "value": "50.00", "quantity": 1 },
    { "value": "20.00", "quantity": 2 }
  ]
}
```

- `requested_cash_amount` es obligatorio, mayor o igual a `0` y con máximo dos decimales (`422`).
- `cash_commission_percentage` es obligatorio, entre `0` y `100`, con máximo dos decimales. `0` es válido (`422`).
- Se rechazan con `422` (`prohibited`): `transfer_amount`, `cash_commission`, `cash_amount` y `prepared_return_amount`.
- Un `requested_cash_amount` mayor que el retorno oficial actual responde `409 DELIVERY_PREPARATION_BLOCKED` con `reason=CASH_REQUEST_EXCEEDS_RETURN`. Se valida bajo el bloqueo existente de `Record` y `Delivery`, como `CASH_MISMATCH`.
- Un faltante mayor a `$1.00` contra `cash_amount` responde el `CASH_MISMATCH` actual. `details.expected_amount` es `cash_amount`.

### `GET /api/records/{record}/delivery`

`DeliveryResource` agrega:

```json
{
  "prepared_return_amount": "200.00",
  "current_return_amount": "200.00",
  "is_stale": false,
  "requested_cash_amount": "100.00",
  "transfer_amount": "100.00",
  "cash_commission_percentage": "10.00",
  "cash_commission": "10.00",
  "cash_amount": "90.00"
}
```

### `POST /api/deliveries/{delivery}/document`

El contrato de la petición no cambia (`document_return_amount`, `customer_will_give_change`). Cambia la referencia:

- Con `cash_amount > 0`, `document_return_amount` debe ser una de las tres opciones calculadas desde `cash_amount`.
- Con `cash_amount = 0.00`, `document_return_amount` debe ser `0.00`.
- La conciliación del efectivo bruto se hace contra ese importe documental, igual que en SPEC 14.
- Se conservan las razones de bloqueo actuales, incluida la entrega obsoleta respecto al retorno oficial.

## UI contract

Referencia visual: `DeliveryPrepare.dc.html`, `DeliveryPrepareMobile.dc.html` y `DeliveryUpdate.dc.html`.

- **Cabecera de montos:** retorno oficial, «Transferencia» y «Efectivo a entregar» (sustituyen a «Documento» y «Efectivo»).
- **Sección «Forma de retorno»:**
  - Atajos «Todo por transferencia» y «Todo en efectivo» (`aria-pressed`). «Todo por transferencia» vacía el desglose.
  - Campos enlazados «Por transferencia» y «En efectivo, lo que pide el cliente». Editar uno ajusta el otro para que sumen el retorno oficial. El payload envía solo `requested_cash_amount`.
  - Campo «Comisión extra por efectivo» (%), obligatorio.
  - Barra de proporción transferencia/efectivo y resumen: retorno oficial, − transferencia, efectivo que pide el cliente, − comisión extra con su %, efectivo a entregar.
  - Errores en línea con los códigos de la plantilla: `TRANSFER_EXCEEDS_RETURN`, `CASH_REQUEST_EXCEEDS_RETURN` y `EXTRA_COMMISSION_INVALID`. Cualquiera bloquea guardar y generar Word.
- **Vista previa:** el frontend calcula la transferencia, la comisión extra y el neto mientras el Editor escribe. Es solo una vista previa, como hoy `getCashCheck` y `documentReturnOptions` en `features/deliveries/utils.ts`. Usa centavos enteros y el mismo redondeo half-up. Laravel recalcula, valida y guarda. Después de guardar, la pantalla muestra los montos de la respuesta.
- **Desglose de efectivo:** la guía fija («Debes entregar», «Llevas», diferencia) y los avisos de faltante se refieren a «Efectivo a entregar».
- **Sin efectivo:** con efectivo a entregar `0.00` se oculta el desglose y se muestra el aviso «No hay entrega en efectivo…». El selector de monto del documento muestra «No aplica…».
- **Obsoleta:** `DeliveryUpdate` muestra la nota de reparto: se conserva el efectivo solicitado y la transferencia toma la diferencia.
- **Valores iniciales al crear:** «Todo en efectivo» (efectivo solicitado = retorno oficial, igual que el comportamiento anterior) y comisión extra vacía.
- `VIEWER` ve el reparto y el resumen en la vista de solo lectura, sin controles.

## Word

`DeliveryDocumentBuilder` cambia tres cosas. Lo demás queda igual.

1. **Frase inicial:** «la cantidad de $X (…)» usa `transfer_amount + document_return_amount`. En el ejemplo: `100.00 + 90.00 = $190.00`. Todo por transferencia: `$200.00`. El importe en palabras corresponde a esa suma.
2. **Tabla nueva**, después de «Mismos que se entregan de la siguiente manera:» y antes de la tabla de denominaciones. Siempre con las dos filas:

   ```text
   TIPO            COMISIÓN          RETORNO
   Transferencia   $0.00             $100.00
   Efectivo        10.00% · $10.00   $90.00
   ```

   - La columna «COMISIÓN» muestra solo la comisión extra. La comisión base ya está descontada en el retorno oficial.
   - «RETORNO» de Efectivo es `cash_amount`, el neto calculado. El importe documental ajustado sigue en la línea «Importe de entrega».
3. **Sin efectivo:** con `cash_amount = 0.00` se omiten la tabla de denominaciones y las líneas «Efectivo bruto», «Importe de entrega» y «Cambio que dará el cliente».

## Implementation plan

### Fase 1 — Esquema y cálculo

1. Editar la migración base `000009` con las cuatro columnas y sus checks. Actualizar `Delivery` (`$fillable`, casts) y su factory.
2. Crear `App\Domain\Deliveries\DeliverySplit` con tests unitarios de los cinco casos de la tabla, más porcentaje `100` y medio centavo.

### Fase 2 — Backend de entrega

3. `UpsertDeliveryRequest`: `requested_cash_amount` y `cash_commission_percentage` obligatorios y los derivados prohibidos. `DeliveryPreparationBlocked::cashRequestExceedsReturn()`.
4. `PrepareDelivery`: valida el efectivo solicitado contra el retorno actual bajo el bloqueo, persiste el resultado de `DeliverySplit` y cuadra el desglose contra `cash_amount`. Mantiene el desglose vacío solo con `cash_amount = 0.00`.
5. `DeliveryHistoryService` con los dos campos nuevos. `DeliveryResource` con los campos nuevos.
6. `DeliveryReturnPolicy`, `DeliveryDocumentEligibility` y `GenerateDeliveryDocumentRequest`: opciones documentales y conciliación desde `cash_amount`, y `0.00` como única opción sin efectivo. La detección de obsolescencia sigue con `prepared_return_amount`.
7. `DeliveryDocumentBuilder`: frase con transferencia + importe documental, tabla `TIPO | COMISIÓN | RETORNO` y omisión del bloque de efectivo cuando `cash_amount = 0.00`. Al terminar la fase, `make test` y `make pint ARGS="<archivos>"` deben pasar.

### Fase 3 — Frontend (leer antes `frontend/AGENTS.md`)

8. `features/deliveries/types.ts`, `schemas/delivery.schema.ts`, `services/deliveries.service.ts` y `utils.ts`: campos nuevos, vista previa del reparto en centavos (`deliverySplitPreview`) con tests, y `documentReturnOptions`/`getCashCheck` contra el efectivo a entregar.
9. Componente nuevo `delivery-return-split.tsx` (sección «Forma de retorno»). Integrarlo en `delivery-form.tsx` y actualizar `delivery-amounts-hero.tsx`.
10. `delivery-cash-breakdown.tsx` (guía y estado sin efectivo), `document-amount-group.tsx` («No aplica»), `delivery-blocks-banner.tsx`, `delivery-action-bar.tsx` y `delivery-read-only.tsx`. Nota de reparto en el estado obsoleto. Versión móvil según `DeliveryPrepareMobile.dc.html`.
11. `npm test`, `npm run lint`, `npx tsc --noEmit` y `npm run build`. Checkpoint en el navegador (escritorio y móvil) registrado en `docs/frontend/verification-log.md`, con `.docx` generados para el caso mixto `200 / 100 / 10%` y para todo por transferencia.

### Fase 4 — Documentación

12. Actualizar los documentos listados en el alcance. Marcar este SPEC como `Implemented` y actualizar su fila en `docs/roadmap.md`.

## Acceptance criteria

- [ ] `migrate:fresh` crea `deliveries.requested_cash_amount`, `cash_commission_percentage`, `cash_commission` y `cash_amount`, todas `NOT NULL` con sus checks.
- [ ] Con retorno oficial `200.00`, `requested_cash_amount=100.00` y `cash_commission_percentage=10.00`, el `PUT` guarda `cash_commission=10.00` y `cash_amount=90.00`. El `GET` devuelve `transfer_amount=100.00`.
- [ ] Con `requested_cash_amount=200.00` y `10.00%`, se guarda `cash_amount=180.00` y `transfer_amount=0.00`.
- [ ] Con `requested_cash_amount=0.00`, `cash_amount=0.00` y un desglose vacío se guarda.
- [ ] `requested_cash_amount` mayor que el retorno oficial responde `409` con `reason=CASH_REQUEST_EXCEEDS_RETURN` y no escribe `Delivery` ni `History`.
- [ ] Un `PUT` sin `requested_cash_amount` o sin `cash_commission_percentage` responde `422`. Un porcentaje fuera de `0–100` o con tres decimales responde `422`.
- [ ] Un `PUT` con `transfer_amount`, `cash_commission`, `cash_amount` o `prepared_return_amount` responde `422`.
- [ ] Con `cash_amount=90.00`, un desglose de `89.00` se guarda y uno de `88.99` responde `CASH_MISMATCH` con `expected_amount=90.00`.
- [ ] `prepared_return_amount` sigue siendo el retorno oficial (`200.00` en el ejemplo).
- [ ] Cambiar el retorno del registro marca la entrega como obsoleta y bloquea el Word, igual que hoy.
- [ ] Cambiar el efectivo solicitado o el porcentaje escribe un evento `requested_cash_amount` o `cash_commission_percentage`. No hay eventos `transfer_amount`, `cash_commission` ni `cash_amount`.
- [ ] Para `cash_amount=93.55`, las opciones documentales son `93.00`, `93.50` y `94.00`. Una opción calculada desde el retorno oficial se rechaza.
- [ ] Con `cash_amount=0.00`, `document_return_amount=0.00` genera el Word y cualquier otro valor se rechaza.
- [ ] El Word del caso `200 / 100 / 10%` con importe documental `90.00` dice «la cantidad de $190.00» con su importe en palabras. Trae la tabla `TIPO | COMISIÓN | RETORNO` con `Transferencia | $0.00 | $100.00` y `Efectivo | 10.00% · $10.00 | $90.00`, seguida de la tabla de denominaciones.
- [ ] El Word de todo por transferencia (`200.00`) dice «la cantidad de $200.00». La tabla nueva trae las dos filas (`Efectivo` en `$0.00`). No hay tabla de denominaciones ni las líneas de efectivo bruto, importe de entrega o cambio.
- [ ] `GET /api/reports`, el Excel y el dashboard devuelven los mismos montos antes y después de preparar una entrega con transferencia.
- [ ] `VIEWER` ve el reparto en solo lectura y recibe `403` en el `PUT`.
- [ ] En la pantalla, editar «Por transferencia» ajusta «En efectivo» y viceversa. Los atajos ponen todo en un lado. El payload envía solo `requested_cash_amount`.
- [ ] Con efectivo a entregar `0.00`, la pantalla oculta el desglose y el selector de monto del documento muestra «No aplica».
- [ ] La vista previa del frontend coincide al centavo con la respuesta de Laravel en los cinco casos de la tabla (tests de `deliverySplitPreview`).
- [ ] La suite backend pasa con `make test` y `pint` no reporta cambios en los archivos tocados.
- [ ] `npm test`, `npm run lint`, `npx tsc --noEmit` y `npm run build` pasan.
- [ ] `docs/frontend/verification-log.md` registra el checkpoint en el navegador y los dos `.docx`.

## Decisions

- **Sí:** el porcentaje de efectivo lo captura el Editor en cada entrega. Decisión del usuario.
- **No:** reutilizar `Record.commission_percentage`, un valor global o un porcentaje por empresa. Coinciden en el ejemplo, pero son reglas distintas.
- **Sí:** el dato de entrada guardado es el efectivo solicitado. La transferencia se deriva. Así lo pide la plantilla `DeliveryUpdate`: al cambiar el retorno se conserva el efectivo y la transferencia absorbe la diferencia. Decisión del usuario (sustituye la primera versión del SPEC, que guardaba la transferencia).
- **Sí:** dos campos enlazados en pantalla, pero un solo dato en la API. Evita estados inconsistentes entre ambos.
- **Sí:** el desglose y el ajuste de SPEC 14 se cuadran contra el efectivo a entregar. Decisión del usuario.
- **Sí:** columnas en `deliveries` auditadas en `History`, editando la migración base. Mismo criterio que SPEC 27. Decisión del usuario.
- **No:** dejar el reparto efímero como el ajuste de SPEC 14. Es un hecho de la entrega y debe quedar auditado.
- **Sí:** guardar `cash_commission` y `cash_amount` calculados. Evita repetir el redondeo en el Word, la API y la validación.
- **Sí:** vista previa del reparto en el frontend, solo para mostrar. Laravel recalcula y decide. Sigue el patrón existente de `getCashCheck` y `documentReturnOptions`. Decisión del usuario.
- **No:** un endpoint de vista previa. Agrega latencia y un endpoint para un cálculo de tres pasos.
- **Sí:** `CASH_REQUEST_EXCEEDS_RETURN` como `409`, no `422`. Depende del retorno actual y se valida bajo el bloqueo, igual que `CASH_MISMATCH`.
- **Sí:** porcentaje obligatorio de `0` a `100` con dos decimales, aunque todo vaya por transferencia. Evita olvidarlo y entregar de más. Decisión del usuario.
- **Sí:** tabla `TIPO | COMISIÓN | RETORNO` en el Word, con las dos filas siempre y solo la comisión extra. Decisión del usuario.
- **No:** prorratear la comisión base entre transferencia y efectivo. Ya está descontada en el retorno oficial.
- **Sí:** la frase del Word usa transferencia + importe documental. Es lo que recibe el cliente en total, y con todo por transferencia no dice `$0.00`. Decisión del usuario.
- **Sí:** sin efectivo, se omite la tabla de denominaciones del Word. Decisión del usuario.
- **No:** otros cambios al Word. Decisión del usuario: solo la tabla extra.
- **No:** afectar reportes, Excel o dashboard. El usuario lo limitó a la entrega «por el momento».
- **No:** datos bancarios de la transferencia. Decisión del usuario: solo el monto.

## Risks

| Risk | Mitigation |
| --- | --- |
| La vista previa del frontend difiere de Laravel por redondeo y el Editor ve un neto que luego cambia. | Centavos enteros y half-up en `deliverySplitPreview`. Tests con los mismos casos que `DeliverySplit`, incluido `6.50325`. Después de guardar se muestra la respuesta de Laravel. |
| El retorno baja por debajo del efectivo solicitado guardado. | La entrega queda obsoleta y el Word se bloquea. El nuevo `PUT` responde `CASH_REQUEST_EXCEEDS_RETURN` y la pantalla lo muestra en «Forma de retorno». |
| Alguien cuadra el efectivo contra el retorno oficial por inercia (código o UI de SPEC 14). | Las opciones documentales y la conciliación toman `cash_amount` desde `DeliverySplit`. Hay un criterio que rechaza una opción calculada desde el retorno. |
| La frase del Word y la tabla no cuadran porque una usa el importe ajustado y la otra el neto. | La tabla muestra el neto y la línea «Importe de entrega» el ajustado, como en SPEC 14. Lo verifica el `.docx` del checkpoint. |
| Reconstruir la base de desarrollo por error durante los tests. | Usar solo `make test` (`DB_DATABASE=tpvs_test`). La reconstrucción de desarrollo es un paso manual y explícito. |

## What is **not** in this spec

- Transferencia o comisión extra en reportes, Excel o dashboard.
- Banco, CLABE, referencia o fecha de la transferencia.
- Porcentaje de efectivo por empresa, global o heredado del registro.
- Otros cambios al Word fuera de la frase inicial, la tabla nueva y la omisión del efectivo.
- Cambios a `Record`, `RecordDetail`, la IA o la comisión base.
- Migración de entregas existentes.
- Cambios a la tarjeta de entrega en el detalle del registro.

Cada uno de estos, si llega, va en su propio SPEC.
