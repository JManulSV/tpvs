# SPEC 27 — Nuevo formato de imagen con comisión global

> **Status:** Implemented
> **Depends on:** SPEC 06, SPEC 07, SPEC 08, SPEC 09, SPEC 10, SPEC 13, SPEC 25
> **Date:** 2026-11-25
> **Objective:** Adaptar la base de datos, la extracción por IA, la API, la interfaz y el Excel al nuevo formato de imagen, con un porcentaje de comisión global por registro y el banco en lugar de la sucursal en cada movimiento.

## Why this spec exists

El proveedor cambió el formato de la imagen de pagos. La referencia es `test-images/nuevo-formato.png`:

```text
18006 | Villaseñor | 23/11/2026                    ← número, empresa, fecha de realización
SEM 46 del 16 al 22 de Noviembre de 2026           ← periodo
Afiliacion | Banco | Ingreso Terminal              ← encabezado de movimientos
8889067    | LUUM  | $ 236,741.30                  ← un renglón por movimiento
Ingreso LUUM          $ 236,741.30                 ← total de ingresos
Comision 6.5%         $  15,388.18                 ← porcentaje global y comisión total
Egreso Retorno LUUM   $ 221,353.12                 ← retorno total
```

Diferencias con el formato anterior (`test-images/prueba1.jpeg` … `prueba5.jpeg`):

- La empresa ya no aparece como `EMPRESA: X`. Es el texto de la cabecera, junto al número (`Villaseñor`).
- La columna `SUCURSAL` se sustituye por `Banco`. Desaparecen `NUM` y la columna de comisión por renglón.
- La comisión es un solo porcentaje para todo el documento. Se calcula una vez sobre el ingreso total.
- La cabecera trae un número (`18006`) y una fecha de realización (`23/11/2026`).

Hoy el modelo guarda `commission_percentage`, `commission` y `return_amount` por movimiento. Con varios movimientos, redondear renglón por renglón puede diferir unos centavos del total impreso. Por eso la comisión pasa al `Record`.

Es un solo SPEC porque el cambio de esquema rompe a la vez el backend, la IA, la API y la interfaz. El plan está por fases: el backend queda verde al terminar la fase 2 y el frontend al terminar la fase 4.

## Scope

**In:**

- Esquema: `records` gana `commission_percentage`, `commission` y `return_amount`. `record_details` pierde `branch`, `commission_percentage`, `commission` y `return_amount`, y gana `bank`. Se editan las migraciones base y se reconstruye la base, sin migrar datos.
- Cálculo oficial en Laravel: comisión = ingreso total × porcentaje global, redondeada una vez con `ROUND_HALF_UP` a dos decimales; retorno = ingreso total − comisión. Ambos se guardan en `Record` en la misma transacción que los movimientos.
- Solo comisión por porcentaje. Se elimina la comisión fija.
- Contrato de extracción IA nuevo y prompt `backend/resources/ai/prompt-v6.txt`. Incluye `document_number` y `completion_date`, que solo se guardan en `AIProcessing.result`.
- API de registros: `commission_percentage` en la raíz de la petición y de la respuesta; los movimientos llevan `affiliation`, `bank` e `income`. La búsqueda usa afiliación o banco.
- Historial: `commission_percentage` como campo del registro y `details[N].bank` en lugar de `details[N].branch`.
- Reportes, dashboard y entregas leen la comisión y el retorno de `records`, no de `record_details`.
- Excel: hoja «Registros» con columna `% Comisión`; hoja «Detalles» con `ID registro`, `Orden`, `Afiliación`, `Banco`, `Ingreso`.
- Frontend: captura manual, edición/corrección, detalle, listado e historial con porcentaje global y banco.
- Actualización de `docs/ai-extraction.md`, `docs/data-model.md`, `docs/business-rules.md`, `docs/backend/api-contract.md`, `docs/backend/domain-workflows.md`, `docs/frontend/features/records.md`, `docs/frontend/features/reports.md`, `docs/roadmap.md` y `docs/frontend/verification-log.md`.

**Out of scope (for future specs):**

- Guardar en una columna o mostrar en la interfaz el número de cabecera (`document_number`) o la fecha de realización (`completion_date`). Por ahora solo viven en `AIProcessing.result`.
- Soportar el formato anterior: no hay detección de formato ni prompt doble.
- Migrar datos existentes. Las bases locales y de pruebas se reconstruyen con `migrate:fresh`.
- Comisión fija o mixta (porcentaje en unos registros y monto fijo en otros).
- Cambios en el documento Word de entrega, en las reglas de efectivo o en las denominaciones.
- Cambiar de modelo de Ollama, la política de reintentos o los timeouts.
- Un catálogo de bancos. `bank` es texto libre.

## Data model

### Migraciones base (se editan; la base se reconstruye)

`2026_10_08_000005_create_records_table.php` agrega:

```php
$table->decimal('commission_percentage', 5, 2)->nullable(); // CHECK 0 <= x <= 100
$table->decimal('commission', 12, 2)->nullable();            // CHECK >= 0
$table->decimal('return_amount', 12, 2)->nullable();         // CHECK >= 0
```

`2026_10_08_000006_create_record_details_table.php` queda así:

```php
$table->id();
$table->foreignId('record_id')->constrained()->cascadeOnDelete();
$table->string('affiliation')->nullable();
$table->string('bank')->nullable();
$table->decimal('income', 12, 2)->nullable();   // CHECK >= 0
$table->unsignedInteger('order');               // CHECK > 0, UNIQUE(record_id, order)
$table->timestamps();
```

Se eliminan de `record_details`: `branch`, `commission_percentage`, `commission`, `return_amount` y sus checks.

Reglas:

- `records.commission` y `records.return_amount` son valores calculados por Laravel. El cliente nunca los envía.
- Si falta el porcentaje o el ingreso de algún movimiento, `commission` y `return_amount` quedan `null`. Solo puede pasar en un registro IMAGE provisional.
- Al confirmar y en la captura manual, `commission_percentage` es obligatorio y los tres campos quedan con valor.
- El ingreso total no se guarda. Sigue siendo `SUM(record_details.income)`.

### Cálculo

Una clase nueva `App\Domain\Records\RecordFinancials` recibe los ingresos de los movimientos y el porcentaje. Devuelve `commission` y `return_amount` como decimales, o `null` si no se pueden calcular. Usa `Money::applyPercentage`, así que hereda el redondeo `ROUND_HALF_UP` de SPEC 25. La usan `CreateManualRecord`, `UpdateRecord`, `ConfirmRecord` (para revalidar) y `RecordExtractionService`.

Ejemplo de la imagen de referencia: `236741.30 × 6.50 / 100 = 15388.1845 → 15388.18`; retorno `221353.12`.

### Contrato de extracción IA (v6)

```json
{
  "record": {
    "company_name": { "value": "Villaseñor", "confidence": 0.95 },
    "document_number": { "value": "18006", "confidence": 0.95 },
    "completion_date": { "value": "2026-11-23", "confidence": 0.90 },
    "year": { "value": 2026, "confidence": 0.95 },
    "week": { "value": 46, "confidence": 0.95 },
    "week_start_date": { "value": "2026-11-16", "confidence": 0.90 },
    "week_end_date": { "value": "2026-11-22", "confidence": 0.90 },
    "commission_percentage": { "value": "6.5%", "confidence": 0.90 }
  },
  "details": [
    {
      "affiliation": { "value": "8889067", "confidence": 0.95 },
      "bank": { "value": "LUUM", "confidence": 0.95 },
      "income": { "value": "$ 236,741.30", "confidence": 0.95 }
    }
  ],
  "source_totals": {
    "income": { "value": "$ 236,741.30", "confidence": 0.95 },
    "commission": { "value": "$ 15,388.18", "confidence": 0.95 },
    "return_amount": { "value": "$ 221,353.12", "confidence": 0.95 }
  }
}
```

- `document_number` es string o `null`. Se transcribe tal cual, sin convertirlo a número.
- `completion_date` usa `YYYY-MM-DD` o `null`. La imagen la imprime como `dd/mm/aaaa`.
- `commission_percentage` se transcribe como string y lo normaliza `ExtractedValueParser`, igual que en v5.
- `details[]` ya no contiene `branch`, `commission_percentage` ni `source_commission`.
- `document_number` y `completion_date` pasan por `ExtractionValidator` (tipo y formato). Solo se guardan en el resultado normalizado de `AIProcessing.result`. `RecordExtractionService` no los copia a `Record`.
- Si falta el porcentaje, no hay fallback a la comisión impresa: `commission` y `return_amount` quedan `null` y se emite el issue de campo faltante.
- Si la comisión o el retorno calculados difieren de `source_totals`, se emite el mismo tipo de issue de discrepancia que hoy.

DTOs afectados en `app/Domain/AI/DTO/`: `AiExtractionResult` (campos nuevos del registro) y `ExtractedDetailFinancialValues`, que se sustituye por el resultado de `RecordFinancials`. El esquema JSON enviado a Ollama en `OllamaAIService` refleja exactamente este contrato.

### API

Petición `POST /api/records` y `PUT /api/records/{id}`:

```json
{
  "company_id": 7,
  "year": 2026,
  "week": 46,
  "week_start_date": "2026-11-16",
  "week_end_date": "2026-11-22",
  "commission_percentage": "6.50",
  "details": [
    { "affiliation": "8889067", "bank": "LUUM", "income": "236741.30" }
  ]
}
```

- `commission_percentage` es obligatorio, entre 0 y 100 con máximo dos decimales.
- `PUT` sigue exigiendo `reason`.
- Se rechazan con `422` (`prohibited`): `branch`, `details.*.branch`, `details.*.commission_percentage`, `details.*.commission`, `details.*.return_amount` y `commission`/`return_amount` en la raíz.
- Cada detalle requiere `affiliation`, `bank` e `income`.

Respuesta (`RecordResource`): agrega `commission_percentage` en la raíz. Conserva las claves `income_total`, `commission_total` y `return_total`; `commission_total` y `return_total` ahora salen de `records.commission` y `records.return_amount`. Cada detalle expone `id`, `affiliation`, `bank`, `income` y `order`.

### Historial

- `commission_percentage`: campo del registro, con dos decimales.
- `details[N].bank` sustituye a `details[N].branch`.
- Desaparecen `details[N].commission_percentage`, `details[N].commission` y `details[N].return_amount`.
- `commission` y `return_amount` del registro no generan eventos propios porque son derivados. El cambio queda registrado por el ingreso o el porcentaje que lo provocó.

## Implementation plan

### Fase 1 — Esquema y cálculo

1. Editar las migraciones base `000005` y `000006` como indica el modelo de datos. Actualizar `Record` y `RecordDetail` (`$fillable`, casts) y sus factories. En `Record::RESOURCE_SUMS` queda solo `income_total`.
2. Crear `App\Domain\Records\RecordFinancials` con tests unitarios: el ejemplo de la imagen, varios movimientos, porcentaje `null`, ingreso `null`, porcentaje `0` y `100`, y redondeo de medio centavo.

### Fase 2 — Backend de registros, reportes y entregas

3. `BusinessValidator`, `StoreRecordRequest` y `UpdateRecordRequest`: porcentaje global obligatorio, `bank` por movimiento y campos antiguos prohibidos. `CreateManualRecord` y `UpdateRecord` persisten los totales con `RecordFinancials`. `ConfirmRecord` revalida que los totales guardados coincidan con el cálculo.
4. `RecordHistoryService`: snapshot con `commission_percentage` en el registro y `bank` en los movimientos.
5. `RecordResource`, `RecordDetailResource`, `RecordQuery` (búsqueda por afiliación o banco) y `RecordFilters`.
6. `ReportQuery`, `DashboardQuery` y `DeliveryReturnPolicy` leen `records.commission` y `records.return_amount`. Las consultas con `join` a `record_details` no deben multiplicar los totales del registro por el número de movimientos.
7. `ReportWorkbookBuilder`: columna `% Comisión` en «Registros» y las columnas nuevas de «Detalles». Al terminar la fase, ejecutar la suite completa contra `tpvs_test` más `pint` en los archivos cambiados; ambos deben pasar.

### Fase 3 — IA

8. Crear `backend/resources/ai/prompt-v6.txt` y borrar `prompt-v5.txt` (queda en el historial de git). El prompt explica:
   - la cabecera (número, empresa, fecha de realización);
   - que `Banco` es un campo del movimiento;
   - que `Ingreso <banco>`, `Comision N%` y `Egreso Retorno <banco>` son totales, no movimientos;
   - que el porcentaje se toma de la etiqueta `Comision N%`.
9. Actualizar el esquema de `OllamaAIService`, los DTO, `ExtractionValidator`, `ExtractionFinancialCalculator` (o reemplazarlo por `RecordFinancials`) y `RecordExtractionService`. Actualizar los fixtures de tests de IA al contrato v6.
10. Verificación manual con Ollama: procesar `test-images/nuevo-formato.png` (`make up-ai` y `make ai-pull`). Anotar en `docs/frontend/verification-log.md` los valores extraídos frente a los esperados.

### Fase 4 — Frontend (leer antes `frontend/AGENTS.md`)

11. `types.ts`, `schemas/record.schema.ts` y `utils/manual-form.ts`: porcentaje en la raíz, `bank` en los movimientos y sin modo de comisión fija (se elimina `commissionModesFor`).
12. `record-editor.tsx`, `manual-record-form.tsx` y `movement-fieldset.tsx`: un campo «Comisión %» en la sección del registro. Cada movimiento lleva «Afiliación», «Banco» e «Ingreso». `manual-record-summary.tsx` sigue mostrando los totales que devuelve Laravel, sin calcular nada.
13. `record-movements-card.tsx` (columnas Afiliación, Banco, Ingreso, más el porcentaje y los totales del registro), `utils/list.ts` (`Afil. X · Banco`) y `utils/history.ts` (etiquetas de `commission_percentage` y `details[N].bank`).
14. Ejecutar `npm test`, `npm run lint`, `npx tsc --noEmit` y `npm run build`. Hacer el checkpoint en el navegador y registrarlo en `docs/frontend/verification-log.md`.

### Fase 5 — Documentación

15. Actualizar los documentos listados en el alcance. Marcar este SPEC como `Implemented` y actualizar su fila en `docs/roadmap.md`.

## Acceptance criteria

- [x] `migrate:fresh` crea `records.commission_percentage`, `records.commission`, `records.return_amount` y `record_details.bank`. `record_details` ya no tiene `branch`, `commission_percentage`, `commission` ni `return_amount`.
- [x] Un registro manual con un movimiento de `236741.30` y `commission_percentage=6.50` se guarda `CONFIRMED` con `commission_total="15388.18"` y `return_total="221353.12"`.
- [x] Con dos movimientos, la comisión se calcula una sola vez sobre la suma de ingresos, no sumando comisiones redondeadas por renglón. Lo cubre un test con montos donde ambos métodos difieren.
- [x] `POST` y `PUT` sin `commission_percentage` responden `422`.
- [x] `POST` y `PUT` con `branch`, `details.*.commission`, `details.*.commission_percentage` o `details.*.return_amount` responden `422`.
- [x] Cambiar el porcentaje o un ingreso con `PUT` recalcula y guarda `commission` y `return_amount` en la misma transacción. El historial registra `commission_percentage` o `details[N].income`, no la comisión derivada.
- [x] Cambiar el banco de un movimiento escribe un evento `details[N].bank`.
- [x] `GET /api/records?search=LUUM` encuentra registros por banco.
- [x] `GET /api/reports`, el Excel, el dashboard (`month_summary.return_total`) y `current_return_amount` de la entrega usan `records.return_amount`. Un registro con tres movimientos no triplica su comisión ni su retorno en los totales.
- [x] El Excel tiene `% Comisión` en «Registros» y las columnas `ID registro`, `Orden`, `Afiliación`, `Banco`, `Ingreso` en «Detalles».
- [x] El prompt cargado es `prompt-v6.txt` y `prompt-v5.txt` ya no existe en `backend/resources/ai/`.
- [x] Una extracción simulada v6 completa produce un registro `NEEDS_REVIEW` con empresa, periodo, porcentaje, movimientos con banco, y comisión y retorno calculados.
- [x] `document_number` y `completion_date` aparecen en `AIProcessing.result` normalizado, pero no en `records` ni en `RecordResource`.
- [x] Una extracción sin porcentaje deja `commission` y `return_amount` en `null`, emite un issue y no usa la comisión impresa como fallback.
- [x] Una comisión impresa distinta de la calculada emite un issue de discrepancia.
- [x] Confirmar un registro IMAGE sin porcentaje responde `409 RECORD_CONFIRMATION_BLOCKED` con `details.reason=BUSINESS_VALIDATION_FAILED`.
- [x] La suite backend pasa con `make test` y `pint` no reporta cambios en los archivos tocados.
- [x] En el frontend, la captura manual y la corrección muestran un solo campo «Comisión %» y los movimientos piden «Afiliación», «Banco» e «Ingreso». No existe la opción de comisión fija.
- [x] El detalle del registro muestra el banco por movimiento, el porcentaje global y los totales que devuelve la API.
- [x] `npm test`, `npm run lint`, `npx tsc --noEmit` y `npm run build` pasan.
- [x] `docs/frontend/verification-log.md` registra el checkpoint en el navegador y el resultado de procesar `test-images/nuevo-formato.png` con Ollama.

## Decisions

- **Sí:** la empresa es el texto de la cabecera (`Villaseñor`). `LUUM` es el banco del movimiento.
- **Sí:** `bank` sustituye a `branch`. El formato nuevo no trae sucursal, y conservar una columna siempre vacía confunde.
- **No:** guardar el banco a nivel de registro. La columna está en cada renglón y un documento podría traer varios bancos.
- **Sí:** el porcentaje vive en `Record` y la comisión se calcula una vez sobre el total. Coincide al centavo con el documento.
- **No:** el porcentaje global con comisión redondeada por movimiento. Con varios renglones puede diferir unos centavos del total impreso.
- **Sí:** persistir `commission` y `return_amount` calculados en `Record`. Reportes, dashboard y entregas leen columnas en lugar de replicar el redondeo en SQL. Es el mismo patrón que hoy sigue `RecordDetail`.
- **No:** calcular la comisión en cada lectura. Habría que duplicar la regla de redondeo en PHP y en SQL.
- **Sí:** se mantienen las claves `commission_total` y `return_total` en la API. Así el frontend cambia lo mínimo, aunque ya no sean sumas.
- **Sí:** solo comisión por porcentaje. El formato nuevo siempre la expresa así.
- **No:** comisión fija ni fallback a la comisión impresa cuando falta el porcentaje. El Editor captura el porcentaje en la revisión.
- **Sí:** reconstruir la base editando las migraciones base. Igual que en SPEC 07, la aplicación aún no tiene datos reales que conservar.
- **No:** migración de datos ni soporte del formato anterior. Duplicaría prompt, validación y modelo sin beneficio.
- **Sí:** `document_number` y `completion_date` se extraen, pero solo se guardan en `AIProcessing.result`. Decisión del usuario: son datos a tener en cuenta pero aún no tienen uso. Un SPEC futuro puede promoverlos a columnas sin volver a procesar el contrato.
- **Sí:** prompt v6 nuevo y borrar v5. Un solo prompt activo. El historial de v5 queda en git.
- **Sí:** validar que el ingreso total del registro quepa en `decimal(12,2)` (máximo `9999999999.99`). Cada movimiento cabe en su columna, pero la comisión y el retorno del registro se guardan en una sola. En captura y edición manual responde `422` en `details`; en la extracción por IA emite un issue y deja `commission` y `return_amount` en `null`. Decisión del usuario durante la implementación (2026-11-25).
- **No:** ampliar las columnas de `records` a `decimal(14,2)`. Se conserva el modelo de datos del SPEC.
- **Sí:** `RecordResource` quita `document_number` y `completion_date` de `ai_processing.normalized_extraction`. Siguen guardados en `AIProcessing.result`, pero la API no los expone. Decisión del usuario durante la implementación (2026-11-25).
- **Sí:** reglas 8–10 de `prompt-v6.txt` más explícitas: los movimientos terminan antes de la primera línea que empieza con `Ingreso`, una afiliación real es un número y un documento con un renglón produce un solo movimiento. Con la primera versión, `qwen2.5vl:3b` tomó las líneas de resumen como movimientos en 2 de 2 corridas; con el ajuste, 3 de 3 corridas fueron correctas. Decisión del usuario durante la verificación del paso 10 (2026-11-26); el detalle está en `docs/frontend/verification-log.md`.
- **Sí:** un solo SPEC aunque toque varias capas. El cambio de esquema rompe todas a la vez; las fases permiten ejecutarlo por sesiones.

## Risks

| Risk | Mitigation |
| --- | --- |
| Las consultas de reportes y dashboard hacen `join` con `record_details` y multiplican `records.commission` por el número de movimientos. | Sumar los totales del registro en una subconsulta o sin `join`. Hay un criterio de aceptación con tres movimientos. |
| La suma de varios movimientos válidos excede `decimal(12,2)` y la escritura en `records` falla con un error 500. | `BusinessValidator` rechaza el total con `422`, y la extracción por IA emite un issue y deja los totales en `null`. |
| Un movimiento se modifica sin recalcular los totales del registro. | Todas las escrituras pasan por `CreateManualRecord`, `UpdateRecord` o `RecordExtractionService`, que usan `RecordFinancials`. `ConfirmRecord` revalida. |
| El modelo confunde `Ingreso LUUM` o `Egreso Retorno LUUM` con movimientos porque llevan el nombre del banco. | El prompt v6 los nombra explícitamente como totales. La verificación manual con `nuevo-formato.png` lo comprueba. |
| El modelo lee `6.5%` desde la etiqueta y lo pierde o lo confunde con el monto. | `commission_percentage` está en `record` y se transcribe como string. Si falta, queda un issue y el Editor lo captura. |
| Se ejecuta `migrate:fresh` contra la base de desarrollo por error durante los tests. | Usar solo `make test` (`DB_DATABASE=tpvs_test`). La reconstrucción de la base de desarrollo es un paso manual y explícito. |
| Las imágenes `prueba1`…`prueba5` (formato anterior) se usan en tests o benchmarks y dejan de ser representativas. | Los tests de IA usan fixtures JSON v6. Las imágenes antiguas quedan como historia del benchmark de SPEC 06. |

## What is **not** in this spec

- Columnas o UI para el número de cabecera o la fecha de realización.
- Soporte del formato anterior de imagen o detección de formato.
- Migración de datos existentes.
- Comisión fija o mixta.
- Catálogo de bancos.
- Cambios en el Word de entrega, en el efectivo o en el modelo de Ollama.

Cada uno de estos, si llega, va en su propio SPEC.
