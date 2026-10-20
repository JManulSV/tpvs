# SPEC 07 — Campos de negocio por movimiento

> **Status:** Approved
> **Depends on:** SPEC 04, SPEC 05, SPEC 06
> **Date:** 2026-10-20
> **Objective:** Realinear el agregado de Record para guardar afiliación y sucursal por movimiento y permitir datos provisionales de imagen sin debilitar la captura manual.

## Why this spec exists

El contrato de extracción coloca afiliación y sucursal en cada movimiento, y no extrae número de operación. El esquema actual los ubica en `Record`, exige todos los campos desde el inicio y obliga a `operation_number`; por eso no puede conservar una extracción parcial ni reflejar fielmente varios movimientos.

## Scope

**In:**

- Mover `affiliation` y `branch` de `records` a `record_details` mediante una nueva migración reversible donde sea seguro.
- Hacer nullable en base de datos los campos que un `Record` de origen `IMAGE` puede no tener durante `PENDING`, `PROCESSING`, `NEEDS_REVIEW` o `ERROR`, incluido `company_id`, periodo y valores de detalle.
- Hacer nullable `record_details.operation_number` para movimientos IMAGE hasta que el Editor lo complete.
- Mantener requeridos los datos completos en las validaciones de captura/edición manual y las reglas para confirmar.
- Actualizar los payloads, recursos, búsqueda y captura manual existente para enviar afiliación y sucursal dentro de cada movimiento.
- Copiar durante la migración los valores raíz actuales a los movimientos existentes antes de eliminar las columnas de `records`.
- Documentar explícitamente los límites de la reversión destructiva y la limpieza de filas de ejemplo no representables.

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

La migración agrega `affiliation` y `branch` nullable a `record_details`, copia los valores de `records` a cada detalle existente, y después elimina los campos del padre. `company_id`, `year`, `week`, `week_start_date`, `week_end_date`, `operation_number`, `income`, `commission` y `return_amount` permiten null en el esquema para representar extracción IMAGE parcial. Las Form Requests y `BusinessValidator` conservan requisitos estrictos para MANUAL y para confirmación.

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

## Migration and data policy

- `up()` copia las columnas raíz a cada detalle existente y solo luego las elimina. Una migración nueva implementa el cambio; no se edita historial de migraciones aplicado.
- Los registros actuales sin movimientos son datos de ejemplo no representables en el modelo por movimiento. En desarrollo/pruebas podrán eliminarse junto con sus dependencias; en `production`, la migración se detiene antes de borrar datos y reporta IDs para resolución operativa.
- La reversión no puede reconstruir valores distintos por movimiento. En `local`/`testing`, `down()` puede eliminar los datos de negocio —Records, detalles, imágenes, AIProcessing, History, Deliveries y compañías— y restaurar el esquema anterior. Los usuarios de autenticación se conservan.
- En `production`, `down()` debe abortar antes de limpiar datos o revertir el esquema. Una operación destructiva de producción requiere un proceso de recuperación independiente y no es parte de esta migración.

## Implementation plan

1. Actualizar las guías de datos, captura manual y API con campos por movimiento, nulabilidad IMAGE y validación estricta para MANUAL/confirmación.
2. Añadir migración que examine registros sin detalles, limpie solo las filas de ejemplo permitidas en entornos no productivos, copie afiliación/sucursal a cada detalle y ajuste nulabilidad/columnas dentro de una secuencia segura.
3. Implementar la protección del `down()` contra producción y la limpieza explícita de las tablas de negocio en entornos `local`/`testing`; probar up/down en una base temporal.
4. Actualizar modelos, factories, resources, Requests, `RecordController`, búsquedas, filtros y cálculo de totales para campos por detalle y nulls IMAGE.
5. Actualizar pruebas backend para payload manual por detalle, validación requerida, orden, lectura/listado, búsqueda y rollback; mantener inalterado el resultado financiero manual.
6. Actualizar tipos, Zod schemas, servicios/componentes de captura manual y vistas de detalles para que cada movimiento capture y presente afiliación/sucursal; no agregar UI AI.
7. Actualizar el contrato API y el modelo de datos; demostrar captura manual completa después de la migración y documentar las condiciones operativas de reversión.

## Acceptance criteria

- [ ] La migración nueva copia afiliación y sucursal existentes a todos los movimientos de cada Record antes de eliminar las columnas raíz.
- [ ] La migración acepta valores nulos de campos IMAGE provisionales sin eliminar checks válidos, como límites de semana, dinero no negativo cuando presente y orden positivo.
- [ ] Una captura MANUAL sigue requiriendo empresa, periodo, al menos un movimiento, número de operación, afiliación, sucursal e importes/comisión válidos.
- [ ] La creación manual sigue confirmando en la misma transacción y sus cálculos financieros mantienen precisión decimal y redondeo actuales.
- [ ] Cada `details[]` manual requiere afiliación y sucursal; el API rechaza esos campos en el nivel raíz y no mantiene compatibilidad dual.
- [ ] La respuesta `RecordDetail` expone afiliación y sucursal y `RecordResource` ya no las expone en el nivel raíz.
- [ ] La búsqueda parcial de registros encuentra afiliación y sucursal almacenadas en cualquier movimiento del agregado.
- [ ] Un registro IMAGE puede persistir temporalmente sin compañía, periodo, movimiento completo o número de operación; el flujo manual no puede hacerlo.
- [ ] El frontend manual actualizado captura ambos campos por movimiento y crea/consulta agregados válidos a través del BFF.
- [ ] `up()` en `production` no borra registros sin detalles y devuelve diagnóstico con sus IDs.
- [ ] `down()` aborta en `production` antes de cualquier operación destructiva.
- [ ] `down()` en `local`/`testing` limpia las tablas de negocio definidas y conserva los usuarios de autenticación antes de restaurar el esquema anterior.
- [ ] Las pruebas de migración verifican copia de datos, nulabilidad y up/down en una base temporal aislada.
- [ ] Las pruebas backend/frontend relevantes, lint, TypeScript, build y checks PHP pasan; las guías describen el nuevo contrato.

## Decisions

- **Sí:** afiliación y sucursal pertenecen a cada movimiento, como indica el contrato de extracción.
- **Sí:** `operation_number` es nullable en detalle IMAGE porque el modelo no lo extrae; será obligatorio antes de confirmar.
- **Sí:** hacer nullable en esquema los datos IMAGE que pueden faltar; Requests y validadores siguen exigiendo la captura manual completa.
- **Sí:** cambiar el payload manual y adaptar lo mínimo del frontend existente para que el flujo no quede roto.
- **Sí:** la coincidencia de compañía en SPEC 08 puede dejar `company_id` null hasta revisión; el nombre crudo se conserva en AIProcessing.
- **Sí:** migración forward copia campos raíz a todos los movimientos actuales. Los datos actuales se consideran de ejemplo y no justifican compatibilidad legacy permanente.
- **Sí:** permitir limpieza de registros sin movimientos solo en desarrollo/pruebas; producción se protege y reporta.
- **Sí:** la reversión destructiva de datos de negocio se permite solo en `local`/`testing`; conserva usuarios de autenticación y bloquea producción.
- **No:** mantener afiliación/sucursal duplicadas en Record y RecordDetail o soportar ambos payloads.
- **No:** relajar requisitos manuales, implementar UI AI, revisión, Ollama o confirmación de datos incompletos.

## Risks

| Risk | Mitigation |
| --- | --- |
| La migración puede destruir datos que no se pueden representar o reconstruir. | Limitar limpieza destructiva a `local`/`testing`, abortar en `production`, reportar IDs y probar rollback en base aislada. |
| Nulabilidad a nivel de base podría permitir datos manuales incompletos si una ruta omite validación. | Mantener Form Requests, `BusinessValidator` y tests de MANUAL/confirmación como límites obligatorios del dominio. |
| Mover campos raíz cambia búsqueda, respuesta y captura del cliente. | Actualizar backend y formulario existente en el mismo spec; verificar el flujo manual completo y ambos roles. |

## What is **not** in this spec

- Crear o procesar registros desde imagen, servicio AI, normalización productiva, jobs o retries.
- Cambiar la política de imagen privada o permitir reemplazo tras un intento AI.
- Completar campos de extracción en una pantalla de revisión.
- History detallado de cambios o transiciones.
- Rediseño UI/UX oficial de 8.15.
