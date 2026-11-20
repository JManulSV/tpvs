# SPEC 25 — Refactor del backend

> **Status:** Approved
> **Depends on:** SPEC 04, SPEC 05, SPEC 08, SPEC 09, SPEC 10, SPEC 11, SPEC 12, SPEC 13, SPEC 14
> **Date:** 2026-11-20
> **Objective:** Reorganizar `backend/app` en casos de uso por dominio, dinero y errores compartidos y clases más pequeñas, sin cambiar el comportamiento ni el contrato HTTP.

## Why this spec exists

Los módulos del MVP ya están completos. Al crecer, el backend acumuló duplicación y controladores con lógica de negocio:

- La aritmética de dinero en centavos (`toCents`, `fromCents`, `toHundredths`) está copiada en `BusinessValidator`, `DeliveryValidator` y `ExtractionFinancialCalculator`. La fórmula de comisión (`intdiv(ingreso × porcentaje + 5000, 10000)`) aparece dos veces.
- Unas 15 respuestas de error `response()->json(['message', 'code', 'details'], 409)` se construyen a mano dentro de transacciones. Por eso los controladores devuelven `Record|JsonResponse` y comprueban `instanceof JsonResponse`.
- `RecordController` (361 líneas) contiene los casos de uso de crear, actualizar, confirmar y cancelar. `DeliveryController::upsert`, `RecordImageController`, `RecordFromImageController` y `RetryAiProcessingController` repiten el patrón.
- La carga del agregado del registro (`load([...])` más tres `loadSum`) se repite seis veces y el bloqueo `lockForUpdate` del registro, en todos los controladores.
- `DeliveryValidator` (361 líneas) mezcla normalización de entrada, cálculo de efectivo y reglas de documento. `Documents` y `Reports` dependen de él.
- `ProcessImageJob` (279 líneas) contiene todo el ciclo de IA. `ExtractionValidator` (390 líneas) parsea números y fechas a mano. El constructor `issue()` está copiado en tres clases de `Domain/AI`.
- `tests/Feature/RecordsApiTest.php` tiene 1480 líneas, y helpers como `pngUpload`, `user` y `record` están copiados en varios archivos.

Es un solo SPEC global por decisión del usuario, aunque toque varias áreas. Para no sobrecargar el contexto, cada fase del plan se puede ejecutar en una sesión nueva leyendo solo este SPEC y los archivos de esa fase.

## Scope

**In:**

- `App\Domain\Shared\Money`, un value object inmutable que reemplaza toda la aritmética de dinero duplicada.
- `App\Domain\Shared\Exceptions\DomainRuleException`, una base para errores de reglas de negocio con un solo punto de renderizado en `bootstrap/app.php`. Subclases por dominio con constructores con nombre.
- Casos de uso en `app/Domain/<Dominio>/Actions/`, una clase por caso de uso con un método `handle()`.
- Helpers del modelo `Record` para la carga del agregado del recurso y para el bloqueo.
- `RecordFilters` y `RecordQuery` para el listado de registros, como ya hacen `ReportFilters` y `ReportQuery`.
- División de `DeliveryValidator` en tres clases de responsabilidad única.
- Helpers compartidos de historial: comparación de snapshots y canonicalización de valores. Cada servicio de historial mantiene su forma de snapshot y sus textos.
- `ProcessImageJob` reducido a un orquestador mínimo. Su lógica pasa a un servicio de `Domain/AI`.
- División de `ExtractionValidator` y un DTO `ExtractionIssue` compartido.
- Reorganización de los tests sin cambiar qué se prueba.
- Actualización de `docs/backend/conventions.md`, `docs/backend/domain-workflows.md`, `docs/roadmap.md` y `docs/frontend/verification-log.md`.

**Out of scope (for future specs):**

- Cualquier cambio en el contrato HTTP: rutas, métodos, códigos de estado, forma del JSON, códigos de error y textos de `message`. `docs/backend/api-contract.md` no cambia.
- Migraciones o cambios de esquema de la base de datos.
- Cambios en `frontend/`.
- Casts de Eloquent a `Money`. Los modelos siguen exponiendo los importes como strings decimales.
- Un value object de porcentaje.
- Dividir `ReportWorkbookBuilder` o `DeliveryDocumentBuilder` más allá de usar `Money` y el helper de archivos temporales.
- Cambios en el prompt de Ollama, en la política de reintentos o en los timeouts de la cola.
- Optimizaciones de rendimiento o caché.
- Análisis estático (PHPStan o Larastan) y dependencias nuevas de Composer.

## Data model

Esta spec no introduce tablas, columnas ni migraciones. Las estructuras nuevas son clases PHP.

### `app/Domain/Shared/Money.php`

```php
final readonly class Money
{
    private function __construct(public int $cents) {}

    public static function zero(): self;
    public static function fromCents(int $cents): self;
    public static function fromDecimal(string|int|null $amount): self; // null|'' → zero
    public function toDecimal(): string;          // "1234.50", "-0.50"
    public function add(self $other): self;
    public function subtract(self $other): self;
    public function compare(self $other): int;    // -1, 0, 1
    public function isNegative(): bool;
    public function applyPercentage(string $percentage): self; // intdiv(c × p₁₀₀ + 5000, 10000)
    public function floorToWholePeso(): self;
}
```

Reglas que no cambian:

- `fromDecimal` **trunca** a dos decimales igual que el `toCents` actual (`substr($fraction, 0, 2)`). No redondea.
- `applyPercentage` recibe el porcentaje como string decimal ("12.5") y conserva el redondeo actual half-up en centésimas.
- No se usa `float` en ningún método.
- El formato de presentación (`$1,234.50` en Word y celdas numéricas en Excel) se queda en `Domain/Documents` y parte de `Money::toDecimal()`.
- Solo se agregan métodos que use algún llamador migrado.

### `app/Domain/Shared/Exceptions/DomainRuleException.php`

```php
abstract class DomainRuleException extends RuntimeException
{
    public function __construct(
        string $message,                 // texto actual de 'message', idéntico
        public readonly string $errorCode,   // 'RECORD_CONFIRMATION_BLOCKED'
        public readonly string $reason,      // 'INVALID_STATE'
        public readonly int $status,         // 409 | 422 | ...
        public readonly array $details = [], // campos extra junto a 'reason'
    );
}
```

Se renderiza en `bootstrap/app.php` → `withExceptions` como:

```json
{ "message": "...", "code": "...", "details": { "reason": "...", "...": "..." } }
```

Subclases y constructores con nombre (`final`, cada una en el `Exceptions/` de su dominio):

| Clase | Constructores | Código | Estado |
| --- | --- | --- | --- |
| `Records\Exceptions\RecordUpdateBlocked` | `invalidState()`, `noChanges()` | `RECORD_UPDATE_BLOCKED`, `RECORD_UPDATE_NO_CHANGES` | 409, 422 |
| `Records\Exceptions\RecordConfirmationBlocked` | `invalidState()`, `businessValidationFailed()`, `imageBusinessValidationFailed()` | `RECORD_CONFIRMATION_BLOCKED` | 409 |
| `Records\Exceptions\RecordCancellationBlocked` | `invalidState()` | `RECORD_CANCELLATION_BLOCKED` | 409 |
| `Deliveries\Exceptions\DeliveryPreparationBlocked` | `invalidRecordState()`, `cashMismatch(expected, cash, difference)` | `DELIVERY_PREPARATION_BLOCKED` | 409 |
| `Documents\Exceptions\DocumentGenerationBlockedException` | se conserva la clase y sus constantes; pasa a extender la base | `DOCUMENT_GENERATION_BLOCKED` | el actual |
| `AI\Exceptions\AiProcessingRetryBlocked` | según los motivos actuales de `RetryAiProcessingController` | `AI_PROCESSING_RETRY_BLOCKED` | el actual |
| `AI\Exceptions\AiJobDispatchFailed` | `afterCommit()` | `AI_JOB_DISPATCH_FAILED` | el actual |
| `Images\Exceptions\RecordImageBlocked` | uno por cada llamada actual a `imageError()` | los actuales | los actuales |

Los textos de `message` y los valores de `details` se copian literalmente del código actual. Los códigos de error que el job persiste en `AiProcessing` no son respuestas HTTP y no usan esta jerarquía.

### Casos de uso (`Actions/`)

| Clase | Sale de |
| --- | --- |
| `Domain/Records/Actions/CreateManualRecord` | `RecordController::store` |
| `Domain/Records/Actions/UpdateRecord` | `RecordController::update` |
| `Domain/Records/Actions/ConfirmRecord` | `RecordController::confirm` y `hasCompleteImageAggregate` (método privado) |
| `Domain/Records/Actions/CancelRecord` | `RecordController::cancel` |
| `Domain/Deliveries/Actions/PrepareDelivery` | `DeliveryController::upsert` |
| `Domain/Documents/Actions/GenerateDeliveryDocument` | `DeliveryDocumentController::store` |
| `Domain/AI/Actions/CreateRecordFromImage` | `RecordFromImageController::store` |
| `Domain/AI/Actions/RetryAiProcessing` | `RetryAiProcessingController::store` |
| `Domain/Images/Actions/ReplaceRecordImage` | `RecordImageController::store` |

Cada acción abre su propia transacción, bloquea lo que bloquea hoy y lanza `DomainRuleException` en lugar de devolver `JsonResponse`. El controlador autoriza, llama a `handle()` y envuelve el resultado en el Resource.

### Otras piezas

- `Record::findLockedOrFail(int $id): self` reemplaza `Record::query()->whereKey(...)->lockForUpdate()->firstOrFail()`.
- `Record::scopeWithResourceAggregates()` y `Record::loadResourceAggregates(): static` cargan `company`, `details` e `image.aiProcessing`, más `income_total`, `commission_total` y `return_total`.
- `Domain/Records/RecordFilters` y `Domain/Records/RecordQuery` contienen los filtros y la búsqueda de `RecordController::index`.
- `Domain/AI/AiJobDispatcher` despacha `ProcessImageJob` después del commit. Si el despacho falla, marca `Record.ERROR` y `AIProcessing.ERROR` y lanza `AiJobDispatchFailed`. Lo usan `CreateRecordFromImage` y `RetryAiProcessing`.
- `RecordImageStorage::deleteQuietly(string $path)` reemplaza los dos `deleteStagedFile` duplicados.
- `Domain/Documents/TemporaryFile` reemplaza los dos `temporaryPath` duplicados.
- `DeliveryValidator` se elimina y sus métodos se reparten así:
  - `Domain/Deliveries/DeliveryInputNormalizer`: `canonicalDate`, `canonicalName`, `canonicalAmount`, `canonicalQuantity`, `canonicalDenomination`, `canonicalizeDenominations`.
  - `Domain/Deliveries/CashBreakdown`: `DENOMINATIONS`, `DOCUMENT_CASH_TOLERANCE_CENTS`, `isOfficialDenomination`, `cashTotal`, `cashMismatch`, `preparationCashShortfall`, `documentCashReconciliation`, `validateDocumentCashReconciliation`.
  - `Domain/Deliveries/DeliveryReturnPolicy`: `currentReturnAmount`, `isStale`, `documentReturnOptions`, `isDocumentReturnOption`.
- `Domain/Shared/History/SnapshotDiff` contiene el `diff()` común. `Domain/Shared/History/CanonicalValue` contiene `string`, `integer`, `date` y `decimal`. `RecordHistoryService` y `DeliveryHistoryService` los usan. `DeliveryInputNormalizer` solo delega en ellos cuando la semántica es idéntica.
- `Domain/AI/AiProcessingRunner` contiene la lógica de `ProcessImageJob`: reclamar, bloquear el agregado, llamar al proveedor, persistir el resultado y registrar los fallos. El job conserva `$tries`, `$timeout`, `backoff`, el constructor, `handle()` (que delega) y `failed()`.
- `Domain/AI/Normalization/ExtractedValueParser` contiene `normalizeInteger`, `normalizeDate`, `normalizeNumber`, `parseNumber`, `isNumericCandidate` y `formatDecimal`, que salen de `ExtractionValidator`.
- `Domain/AI/DTO/ExtractionIssue` (`path`, `code`, `severity`, `message`, `value`) tiene un `toArray()` que da exactamente la forma actual. Reemplaza los tres `issue()`.

## Implementation plan

Cada fase es **un commit** en la rama `spec-25-backend-refactor`. Al cerrar cada fase se cumple esto:

- `docker compose exec -T -e DB_DATABASE=tpvs_test tpvs-backend php artisan test` pasa.
- `vendor/bin/pint` no deja cambios en los archivos tocados.
- El número de tests no baja de la línea base.

Ninguna fase modifica tests de Feature salvo para importar clases renombradas. Si un test de Feature necesita cambiar una aserción, el comportamiento cambió y hay que revertir.

0. **Línea base (sin commit).** Correr la suite en `tpvs_test` y anotar tests, aserciones y duración. Correr `pint --test`. Si algo falla, se detiene el SPEC y se reporta.
1. **Money.** Crear `Domain/Shared/Money` con tests unitarios: truncado a dos decimales, negativos, `null` y `''`, `applyPercentage` half-up y `floorToWholePeso`. Migrar a `Money` `BusinessValidator`, `ExtractionFinancialCalculator`, `DeliveryValidator` y los `money()` de `DeliveryDocumentBuilder` y `ReportWorkbookBuilder`. Borrar los `toCents`, `fromCents`, `toHundredths` y `fromHundredths` privados.
2. **Errores de dominio.** Crear `DomainRuleException` y su render en `bootstrap/app.php`, con un test unitario del render. Crear las subclases de la tabla. Reemplazar cada `response()->json([... 'code' ...])` de los controladores por un `throw`. Hacer que `DocumentGenerationBlockedException` extienda la base y quitar su manejo manual en el controlador. Quitar los tipos `|JsonResponse` y los `instanceof JsonResponse`.
3. **Modelo Record y listado.** Agregar `findLockedOrFail`, `scopeWithResourceAggregates` y `loadResourceAggregates`, y usarlos en todos los controladores. Crear `RecordFilters` y `RecordQuery` y llevar ahí `RecordController::index`.
4. **Acciones de Records.** Crear `CreateManualRecord`, `UpdateRecord`, `ConfirmRecord` y `CancelRecord`, cada una con un test unitario de su camino feliz y de un bloqueo. Dejar `RecordController` solo con autorización, la llamada a la acción y el Resource.
5. **Historial compartido.** Crear `SnapshotDiff` y `CanonicalValue` con tests unitarios. Migrar `RecordHistoryService` y `DeliveryHistoryService`. `RecordHistoryServiceTest` y `DeliveryHistoryServiceTest` siguen pasando sin cambios.
6. **Deliveries y Documents.** Dividir `DeliveryValidator` en `DeliveryInputNormalizer`, `CashBreakdown` y `DeliveryReturnPolicy`, y dividir `DeliveryValidatorTest` igual. Crear `PrepareDelivery` y `GenerateDeliveryDocument`, y adelgazar los dos controladores. Crear `Documents/TemporaryFile`. Migrar `ReportWorkbookBuilder`, `DeliveryDocumentBuilder` y `DeliveryDocumentEligibility` a las clases nuevas y borrar `DeliveryValidator`.
7. **Imágenes y la IA del lado HTTP.** Crear `AiJobDispatcher`, `CreateRecordFromImage`, `RetryAiProcessing`, `ReplaceRecordImage` y `RecordImageStorage::deleteQuietly`. Adelgazar `RecordFromImageController`, `RetryAiProcessingController` y `RecordImageController`.
8. **Pipeline de IA.** Crear `ExtractionIssue` y usarlo en las tres clases. Extraer `ExtractedValueParser` de `ExtractionValidator`, con sus tests unitarios. Mover la lógica de `ProcessImageJob` a `AiProcessingRunner` sin cambiar el orden de los locks, las transacciones ni las llamadas al proveedor. `ProcessImageJobTest`, `ProcessImageJobPolicyTest` y `AiProcessingRetryTest` pasan sin cambios.
9. **Tests.**
   - Partir `RecordsApiTest` en `tests/Feature/Records/` por caso de uso: `CreateManualRecordTest`, `ListRecordsTest`, `ShowRecordTest`, `UpdateRecordTest`, `ConfirmRecordTest`, `CancelRecordTest` y `RecordHistoryApiTest`.
   - Mover los helpers repetidos (`user`, `record`, `delivery`, `pngUpload`) a traits en `tests/Support/Concerns/`.
   - El número de tests y de aserciones queda igual al de la fase 8.
10. **Documentación y checkpoint.**
    - Actualizar `docs/backend/conventions.md`: árbol de carpetas, `Actions/`, `Domain/Shared` (Money, DomainRuleException, History) y la regla «los controladores no devuelven errores de negocio a mano».
    - Actualizar `docs/backend/domain-workflows.md` con los nombres de las acciones.
    - Agregar la fila del SPEC 25 a `docs/roadmap.md`.
    - Hacer un checkpoint de humo en el navegador: login, registro manual, confirmar, preparar la entrega, Word, Excel y un intento de mutación como `VIEWER`. Registrar el resultado en `docs/frontend/verification-log.md`.
    - Marcar el SPEC `Implemented`.

## Acceptance criteria

- [ ] La suite pasa en `tpvs_test` y el número de tests es mayor o igual al de la línea base.
- [ ] `vendor/bin/pint --test` no reporta cambios.
- [ ] `grep -rnE "function (toCents|fromCents|toHundredths|fromHundredths)" backend/app` no devuelve resultados.
- [ ] `grep -rn "'code' =>" backend/app/Http/Controllers` no devuelve resultados.
- [ ] `grep -rn "instanceof JsonResponse" backend/app` no devuelve resultados.
- [ ] `grep -rn "lockForUpdate" backend/app/Http` no devuelve resultados.
- [ ] `backend/app/Domain/Deliveries/DeliveryValidator.php` no existe.
- [ ] Ningún archivo en `backend/app/Http/Controllers/Api/` supera 120 líneas.
- [ ] `backend/app/Jobs/ProcessImageJob.php` no supera 100 líneas y `ExtractionValidator.php` no supera 250.
- [ ] Ningún archivo en `backend/tests/` supera 600 líneas.
- [ ] `Money` tiene tests unitarios de truncado, negativos, valor vacío, `applyPercentage` y `floorToWholePeso`.
- [ ] `git diff main -- backend/routes backend/database/migrations docs/backend/api-contract.md frontend` está vacío.
- [ ] `composer.json` no tiene dependencias nuevas.
- [ ] La rama tiene un commit por fase (1–10) y la suite pasa en cada uno.
- [ ] El checkpoint de humo está registrado en `docs/frontend/verification-log.md`.
- [ ] `docs/backend/conventions.md` describe `Actions/`, `Domain/Shared` y `DomainRuleException`.

## Decisions

- **Sí:** un solo SPEC global con fases ejecutables por separado. Lo decidió el usuario, aunque el SPEC toque más de tres áreas. Cada fase se puede hacer en una sesión nueva para no sobrecargar el contexto.
- **Sí:** el contrato HTTP congelado por completo. Los tests de Feature actuales son la red de seguridad del refactor.
- **Sí:** una rama y un commit por fase, con un solo PR al final. **No:** un PR por fase, por el costo de gestión.
- **Sí:** `Money` como value object en centavos (`int`). **No:** un helper estático de strings, porque los importes seguirían circulando sin tipo.
- **Sí:** `fromDecimal` trunca igual que hoy. Cambiar a redondeo sería un cambio de comportamiento.
- **No:** un value object de porcentaje. Solo se necesita `Money::applyPercentage`.
- **No:** casts de Eloquent a `Money`, porque cambiarían la serialización de los modelos y los Resources.
- **Sí:** las acciones viven en `Domain/<Dominio>/Actions/`, una clase con `handle()` por caso de uso. **No:** servicios por dominio como `RecordService`, porque tienden a volverse una clase gorda.
- **Sí:** una base `DomainRuleException` y subclases con constructores con nombre. **No:** una clase genérica con códigos como strings sueltos. **No:** una clase por cada código y motivo, porque generan demasiados archivos.
- **Sí:** compartir solo los helpers de historial (`SnapshotDiff`, `CanonicalValue`). **No:** una clase base abstracta de historial, porque acoplaría dos snapshots distintos.
- **Sí:** el pipeline de IA va al final, cuando `Money`, `ExtractionIssue` y los errores ya existen.
- **Sí:** la reorganización de los tests es una fase propia, después del código. Así los tests de Feature validan las fases 1–8 sin haberse movido.
- **No:** un `Domain/Services` genérico, por la regla de `conventions.md`. Lo compartido va en `Domain/Shared` y solo existe donde ya había duplicación.

## Risks

| Riesgo | Mitigación |
| --- | --- |
| `Money` cambia la semántica de parseo (truncado frente a redondeo, signos, `null`) | Tests unitarios que fijan el comportamiento actual antes de migrar. Las suites de `BusinessValidator`, `DeliveryValidator` y `ExtractionFinancialCalculator` pasan sin cambios. |
| Un `throw` dentro de `DB::transaction` hace rollback donde antes había commit | Verificar en cada `throw` que no haya escrituras previas en la transacción. Si las hay, el `throw` va después del commit, igual que el caso de `AI_JOB_DISPATCH_FAILED`. |
| El render de `DomainRuleException` cambia la forma del JSON o el estado | Test unitario del render, más los tests de Feature existentes que comprueban `code`, `details` y el estado. |
| Las rutas de descarga (Word, Excel) reciben JSON donde antes no | El render aplica solo a `DomainRuleException`. Hoy esas rutas ya devuelven JSON en los errores de negocio, y sus tests de Feature lo cubren. |
| Mover `ProcessImageJob` altera el orden de locks, `$tries` o la detección de jobs duplicados u obsoletos | El job conserva sus propiedades de cola. `AiProcessingRunner` mueve el código sin reordenarlo. Las tres suites de IA pasan sin cambios. |
| El diff total es muy grande para revisar | Un commit por fase, y cada uno se puede revisar por separado. |
| Una fase supera el contexto de una sesión | Cada fase lista sus archivos. Se ejecuta en una sesión nueva con este SPEC como única entrada. |

## What is **not** in this spec

- Cambios en rutas, códigos de estado, forma del JSON o mensajes de la API.
- Migraciones o cambios de esquema.
- Cambios en el frontend.
- Casts de Eloquent a `Money` y un value object de porcentaje.
- Dividir los builders de Word y Excel.
- Cambios en el prompt de Ollama, los reintentos o los timeouts.
- Optimización de rendimiento, caché, PHPStan o Larastan.

Cada uno de estos temas, si llega, va en su propio SPEC.
