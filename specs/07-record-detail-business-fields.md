# SPEC 07 — Campos de negocio por movimiento

> **Status:** Implemented
> **Depends on:** SPEC 04, SPEC 05, SPEC 06
> **Date:** 2026-10-20
> **Objective:** Realinear el agregado de Record para guardar afiliación y sucursal por movimiento y permitir datos provisionales de imagen sin debilitar la captura manual.

## Why this spec exists

El contrato de extracción coloca afiliación y sucursal en cada movimiento, y no extrae número de operación. El esquema actual los ubica en `Record`, exige todos los campos desde el inicio y obliga a `operation_number`; por eso no puede conservar una extracción parcial ni reflejar fielmente varios movimientos.

## Scope

**In:**

- Definir `affiliation` y `branch` en `record_details` y retirarlos de `records` en el esquema base.
- Hacer nullable en base de datos los campos que un `Record` de origen `IMAGE` puede no tener durante `PENDING`, `PROCESSING`, `NEEDS_REVIEW` o `ERROR`, incluido `company_id`, periodo y valores de detalle.
- Hacer nullable `record_details.operation_number` para movimientos IMAGE hasta que el Editor lo complete.
- Mantener requeridos los datos completos en las validaciones de captura/edición manual y las reglas para confirmar.
- Actualizar los payloads, recursos, búsqueda y captura manual existente para enviar afiliación y sucursal dentro de cada movimiento.
- No conservar ni migrar datos de bases locales existentes; se reconstruyen con el nuevo esquema.

**Out of scope (for future specs):**

- Crear y procesar registros IMAGE, llamada Ollama, normalización de extracción o persistencia de resultados AI; corresponde a SPEC 08.
- Cambiar el diseño oficial, agregar carga AI o implementar la pantalla de revisión completa; corresponde a 8.10/8.15.
- Cambiar la captura manual para aceptar datos incompletos; la validación manual continúa completa.
- Cambios de auditoría campo-a-campo o eventos History para AI; corresponden a 8.11.
- Retener columnas raíz legacy o soportar indefinidamente ambos payloads.

## Data model

```text
Record
  company_id: nullable para IMAGE provisional; requerido al crear MANUAL y confirmar
  source: IMAGE | MANUAL
  status: PENDING | PROCESSING | NEEDS_REVIEW | ERROR | CONFIRMED | CANCELED
  year, week, week_start_date, week_end_date: nullable durante extracción IMAGE
  image: 0..1
  details: 0..N durante extracción; al menos uno completo para captura manual/confirmación

RecordDetail
  record_id: requerido
  operation_number: nullable hasta revisión IMAGE; requerido para MANUAL/confirmación
  affiliation: nullable durante extracción IMAGE; requerido para MANUAL/confirmación
  branch: nullable durante extracción IMAGE; requerido para MANUAL/confirmación
  income, commission, return_amount: nullable cuando la extracción no los proporciona
  commission_percentage: nullable
  order: requerido y positivo; asignado según orden visual/manual
```

El esquema base define `affiliation` y `branch` nullable en `record_details` y no las define en `records`. `company_id`, `year`, `week`, `week_start_date`, `week_end_date`, `operation_number`, `income`, `commission` y `return_amount` permiten null para representar extracción IMAGE parcial. Las Form Requests y `BusinessValidator` conservan requisitos estrictos para MANUAL y para confirmación. Los datos actuales de desarrollo/pruebas no se trasladan; se eliminan al reconstruir la base con el esquema nuevo.

Los valores de compañía cuyo nombre extraído no tenga coincidencia en catálogo se representarán con `company_id = null`; el texto fuente se conserva en `AIProcessing.result` en SPEC 08.

## API and manual payload contract

`POST /api/records` y `PUT /api/records/{id}` mantienen sus campos raíz de compañía y periodo, pero cada elemento `details[]` incluye `operation_number`, `affiliation`, `branch`, `income` y exactamente una forma de comisión. `affiliation` y `branch` se eliminan del nivel raíz.

```json
{
  "company_id": 1,
  "year": 2026,
  "week": 38,
  "week_start_date": "2026-09-14",
  "week_end_date": "2026-09-20",
  "details": [
    {
      "operation_number": "10231",
      "affiliation": "AF-123",
      "branch": "Guadalajara",
      "income": "10000.00",
      "commission_percentage": "2.00"
    }
  ]
}
```

La respuesta de cada `RecordDetail` expone afiliación y sucursal. El recurso raíz de Record deja de exponer esos dos campos. Listado y búsqueda encuentran afiliación/sucursal a través de los movimientos; empresa/periodo pueden ser null en registros IMAGE incompletos. El listado mantiene filtros y paginación actuales.

## Schema and data policy

- El cambio se aplica a las migraciones base porque la aplicación aún no está en producción. No se agrega una migración de copia o compatibilidad.
- Las bases locales y de pruebas existentes deben reconstruirse (`migrate:fresh`); sus datos no se preservan.
- La estructura mantiene los checks de rango de semana/año, fechas, importes no negativos y orden positivo.

## Implementation plan

1. Actualizar las guías de datos, captura manual y API con campos por movimiento, nulabilidad IMAGE y validación estricta para MANUAL/confirmación.
2. Actualizar las migraciones base para alojar afiliación/sucursal por movimiento y permitir nulls IMAGE, conservando checks válidos; documentar que las bases locales se reconstruyen sin migrar datos.
3. Actualizar modelos, factories, resources, Requests, `RecordController`, búsquedas, filtros y cálculo de totales para campos por detalle y nulls IMAGE.
4. Actualizar pruebas backend para payload manual por detalle, validación requerida, orden, lectura/listado, búsqueda y rollback; mantener inalterado el resultado financiero manual.
5. Actualizar tipos, Zod schemas, servicios/componentes de captura manual y vistas de detalles para que cada movimiento capture y presente afiliación/sucursal; no agregar UI AI.
6. Actualizar el contrato API y el modelo de datos, y verificar las comprobaciones frontend/backend disponibles.

## Acceptance criteria

- [x] Las migraciones base almacenan afiliación/sucursal por movimiento y no las exponen en `records`.
- [x] El esquema base acepta valores nulos de campos IMAGE provisionales sin eliminar checks válidos, como límites de semana, dinero no negativo cuando presente y orden positivo.
- [x] Una captura MANUAL sigue requiriendo empresa, periodo, al menos un movimiento, número de operación, afiliación, sucursal e importes/comisión válidos.
- [x] La creación manual sigue confirmando en la misma transacción y sus cálculos financieros mantienen precisión decimal y redondeo actuales.
- [x] Cada `details[]` manual requiere afiliación y sucursal; el API rechaza esos campos en el nivel raíz y no mantiene compatibilidad dual.
- [x] La respuesta `RecordDetail` expone afiliación y sucursal y `RecordResource` ya no las expone en el nivel raíz.
- [x] La búsqueda parcial de registros encuentra afiliación y sucursal almacenadas en cualquier movimiento del agregado.
- [x] Un registro IMAGE puede persistir temporalmente sin compañía, periodo, movimiento completo o número de operación; el flujo manual no puede hacerlo.
- [x] El frontend manual actualizado captura ambos campos por movimiento y crea/consulta agregados válidos a través del BFF.
- [x] Las pruebas backend/frontend relevantes, lint, TypeScript, build y checks PHP pasan; las guías describen el nuevo contrato.
- [x] Las bases locales/pruebas se pueden reconstruir con el esquema base actualizado; no se incluye una migración de copia de datos.

## Decisions

- **Sí:** afiliación y sucursal pertenecen a cada movimiento, como indica el contrato de extracción.
- **Sí:** `operation_number` es nullable en detalle IMAGE porque el modelo no lo extrae; será obligatorio antes de confirmar.
- **Sí:** hacer nullable en esquema los datos IMAGE que pueden faltar; Requests y validadores siguen exigiendo la captura manual completa.
- **Sí:** cambiar el payload manual y adaptar lo mínimo del frontend existente para que el flujo no quede roto.
- **Sí:** la coincidencia de compañía en SPEC 08 puede dejar `company_id` null hasta revisión; el nombre crudo se conserva en AIProcessing.
- **Sí:** las migraciones base se actualizan porque la aplicación aún no está en producción; no se migra información de desarrollo.
- **No:** mantener afiliación/sucursal duplicadas en Record y RecordDetail o soportar ambos payloads.
- **No:** relajar requisitos manuales, implementar UI AI, revisión, Ollama o confirmación de datos incompletos.

## Risks

| Risk | Mitigation |
| --- | --- |
| Nulabilidad a nivel de base podría permitir datos manuales incompletos si una ruta omite validación. | Mantener Form Requests, `BusinessValidator` y tests de MANUAL/confirmación como límites obligatorios del dominio. |
| Mover campos raíz cambia búsqueda, respuesta y captura del cliente. | Actualizar backend y formulario existente en el mismo spec; verificar el flujo manual completo y ambos roles. |

## What is **not** in this spec

- Crear o procesar registros desde imagen, servicio AI, normalización productiva, jobs o retries.
- Cambiar la política de imagen privada o permitir reemplazo tras un intento AI.
- Completar campos de extracción en una pantalla de revisión.
- History detallado de cambios o transiciones.
- Rediseño UI/UX oficial de 8.15.
