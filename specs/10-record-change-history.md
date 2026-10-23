# SPEC 10 — Historial de cambios de registros

> **Status:** Approved
> **Depends on:** SPEC 04, SPEC 07, SPEC 08, SPEC 09
> **Date:** 2026-10-23
> **Objective:** Auditar y consultar, con usuario, motivo y valores anteriores/nuevos, cada cambio humano relevante de un registro, incluida la reapertura auditada de registros confirmados.

## Why this spec exists

SPEC 09 deja trazabilidad de estado y un evento genérico `record_updated`, pero no permite saber qué campo cambió, quién lo cambió ni su valor anterior. La fase 8.11 cierra esa brecha y habilita el flujo `CONFIRMED -> NEEDS_REVIEW -> CONFIRMED` exigido por las reglas de negocio. El historial de `Delivery` se difiere a 8.12 porque la entidad aún no existe.

## Scope

**In:**

- Registrar un único evento `record_created` al crear un registro manual o desde imagen.
- Calcular y persistir el diff campo a campo en correcciones de `NEEDS_REVIEW`.
- Permitir que `PUT /api/records/{record}` acepte un registro `CONFIRMED`, lo reabra a `NEEDS_REVIEW` con motivo y registre estado y diff.
- Mantener los eventos `status` de confirmación y cancelación existentes.
- Exponer `GET /api/records/{record}/history` paginado para `EDITOR` y `VIEWER`.
- Añadir un panel mínimo de historial y la acción de edición de `CONFIRMED` en el detalle actual.
- Actualizar pruebas backend/frontend, documentación y checkpoint de 8.11.
- No crear tablas ni migraciones.

**Out of scope (for future specs):**

- Historial de `Delivery` y denominaciones; corresponde a 8.12.
- Transiciones AI (`PENDING`, `PROCESSING`, `NEEDS_REVIEW`, `ERROR`, retry); se conservan en `AIProcessing`.
- Editar o borrar eventos de historial, exportarlo o depurarlo.
- Backfill de eventos históricos anteriores a esta spec.
- Diseño oficial de UI/UX, dashboard o pantallas finales; corresponde a 8.15.
- Delivery, Word, Excel, reportes y cualquier documento generado.
- Cambios a validación de negocio, pipeline AI o reglas de imágenes.

## Data model

No se agregan tablas ni migraciones. Se reutiliza `History` con sus columnas actuales:

```text
History
  record_id           required
  record_detail_id    null en esta spec
  delivery_id         null en esta spec (8.12)
  user_id             required, usuario autenticado que ejecuta la acción
  field_name          nombre canónico del campo o evento
  previous_value      texto canónico o null
  new_value           texto canónico o null
  reason              motivo obligatorio o fijo
  created_at          timestamp de la acción
```

Convenciones de valores:

- Fechas: `Y-m-d`.
- Importes y porcentajes: texto decimal con dos decimales, por ejemplo `100.00`.
- `year`, `week`, `order`: texto entero.
- `status`, `source`: valor del enum.
- `company`: nombre de la empresa, no el id.
- `details[N]`: `N` es 1-based y coincide con `order` del movimiento.
- Los valores se comparan después de canonicalizarlos; diferencias solo de formato no generan eventos.
- `record_detail_id` queda null porque la corrección reemplaza filas de `record_details`.
- `delivery_id` queda null hasta 8.12.

Eventos:

| Acción | `field_name` | `previous_value` | `new_value` | `reason` |
| --- | --- | --- | --- | --- |
| Crear manual | `record_created` | null | `CONFIRMED` | `Registro creado manualmente` |
| Crear desde imagen | `record_created` | null | `PENDING` | `Registro creado desde imagen` |
| Corregir `NEEDS_REVIEW` | una fila por campo | valor anterior | valor nuevo | motivo del Editor |
| Corregir `NEEDS_REVIEW` sin cambios | `record_updated` | null | null | motivo del Editor |
| Reabrir `CONFIRMED` | `status` | `CONFIRMED` | `NEEDS_REVIEW` | motivo del Editor |
| Reabrir `CONFIRMED` | una fila por campo | valor anterior | valor nuevo | motivo del Editor |
| Confirmar | `status` | `NEEDS_REVIEW` | `CONFIRMED` | `Registro manual confirmado` o `Registro de imagen confirmado` |
| Cancelar | `status` | `NEEDS_REVIEW` o `ERROR` | `CANCELED` | motivo del Editor |

Campos auditados:

- Record: `company`, `year`, `week`, `week_start_date`, `week_end_date`, `status`.
- Detail: `order`, `affiliation`, `branch`, `income`, `commission_percentage`, `commission`, `return_amount`.
- Un detalle agregado genera filas null -> valores; uno eliminado genera valores -> null.
- El estado se audita en eventos separados según la tabla anterior; no se duplica como campo del diff.

No se registran eventos para transiciones AI ni para cambios internos del pipeline. `AIProcessing` sigue siendo la evidencia técnica de esos estados.

## API contract

Todas las rutas requieren sesión Sanctum. Las mutaciones requieren CSRF y rol `EDITOR`; la consulta de historial admite `EDITOR` y `VIEWER`.

| Método | Ruta | Comportamiento | Autorización |
| --- | --- | --- | --- |
| `PUT` | `/api/records/{record}` | Reemplaza el agregado de `MANUAL` o `IMAGE` en `NEEDS_REVIEW`; ahora también acepta `CONFIRMED` y lo reabre. | `EDITOR` |
| `GET` | `/api/records/{record}/history` | Lista paginada del historial, más reciente primero. | `EDITOR` o `VIEWER` |

### Creación

- `POST /api/records` escribe exactamente un `record_created` con `new_value=CONFIRMED` en la misma transacción del Record y sus detalles. Sustituye el evento `status` de creación anterior.
- `POST /api/records/from-image` escribe exactamente un `record_created` con `new_value=PENDING` en la misma transacción de Record, Image y AIProcessing.
- Ambos eventos usan el `user_id` del Editor autenticado.
- Un fallo de persistencia revierte Record, detalles, Image, AIProcessing e History.

### Corrección y reapertura

`PUT /api/records/{record}` mantiene el payload completo actual y `reason` obligatorio. Comportamiento por estado:

- `NEEDS_REVIEW`: valida y reemplaza el agregado. Persiste una fila por campo modificado. Si no hay diferencias, persiste un único `record_updated` con el motivo y responde `200`.
- `CONFIRMED`: valida y reemplaza el agregado, persiste una fila por campo modificado y un evento `status` `CONFIRMED -> NEEDS_REVIEW` con el motivo. Responde `200` con el registro en `NEEDS_REVIEW`; la confirmación se hace aparte con `POST /api/records/{record}/confirm`.
- `CONFIRMED` sin diferencias: responde `422` con `code=RECORD_UPDATE_NO_CHANGES`, `details.reason=NO_CHANGES`, sin escribir History y sin cambiar el estado.
- `PENDING`, `PROCESSING`, `ERROR`, `CANCELED`: `409 RECORD_UPDATE_BLOCKED` con `details.reason=INVALID_STATE`.
- La reapertura conserva `source`, `Image` y `AIProcessing`; no vuelve a ejecutar AI ni modifica la evidencia.
- Todos los cambios de agregado, eventos de estado y diff comparten una transacción con bloqueo de la fila `Record`.

### Confirmación y cancelación

- `POST /api/records/{record}/confirm` mantiene su contrato: solo `NEEDS_REVIEW`, revalida y escribe `status` `NEEDS_REVIEW -> CONFIRMED` con el motivo fijo según la fuente.
- `POST /api/records/{record}/cancel` mantiene su contrato: solo `NEEDS_REVIEW` o `ERROR`, exige motivo y escribe `status` al estado anterior -> `CANCELED`.
- No se aceptan `status`, `source` ni eventos de History desde el cliente.

### Consulta de historial

`GET /api/records/{record}/history` acepta `page` (entero, mínimo 1) y `per_page` (entero, 1–100, por defecto 50). Ordena por `created_at` descendente y `id` descendente.

Respuesta:

```json
{
  "data": [
    {
      "id": 12,
      "field_name": "details[1].income",
      "previous_value": "100.00",
      "new_value": "120.00",
      "reason": "Se corrigió el ingreso",
      "created_at": "2026-10-23T18:00:00.000000Z",
      "user": {
        "id": 1,
        "name": "Editor",
        "email": "editor@example.com"
      }
    }
  ],
  "links": {
    "first": "…",
    "last": "…",
    "prev": null,
    "next": null
  },
  "meta": {
    "current_page": 1,
    "from": 1,
    "last_page": 1,
    "per_page": 50,
    "to": 1,
    "total": 1
  }
}
```

- No expone `raw_response`, rutas privadas, tokens, stack traces ni datos de AIProcessing.
- Errores: `401` no autenticado, `403` sin permiso, `404` registro inexistente, `422` paginación inválida.
- El historial es de solo lectura: no hay endpoints de actualización o borrado.

## UI contract

- `/records/{id}` incorpora una sección `Historial de cambios`, visible para `EDITOR` y `VIEWER`, con estados de carga, error, vacío y paginación.
- Cada fila muestra fecha, usuario, campo, valor anterior, valor nuevo y motivo.
- Los campos se traducen a etiquetas legibles; `details[N].campo` se muestra como `Movimiento N · Campo`.
- `record_created` se muestra como `Registro creado`; los estados usan sus etiquetas en español.
- Importes y porcentajes se formatean para lectura; los valores en bruto siguen viniendo del backend.
- Un registro `CONFIRMED` muestra `Editar registro` a `EDITOR`. La edición reutiliza el formulario actual con motivo obligatorio; al guardar, el registro queda `NEEDS_REVIEW` y la pantalla ofrece `Confirmar registro`.
- `VIEWER` no ve acciones de mutación.
- Un `422 RECORD_UPDATE_NO_CHANGES` muestra el mensaje del backend sin reabrir el registro.
- Tras corregir, reabrir, confirmar o cancelar, se invalida la consulta de historial del registro.
- El diseño definitivo y la navegación completa quedan para 8.15.

## Implementation plan

1. Actualizar `docs/02_business_rules_and_states.md`, `docs/03_data_model.md`, `docs/backend/03_api_contract.md`, `docs/backend/05_validation_authorization.md`, `docs/backend/06_domain_workflows.md`, `docs/frontend/features/records.md` y `docs/frontend/06_testing.md` con creación, diff, reapertura e historial.
2. Crear `App\Domain\Records\RecordHistoryService` para canonicalizar snapshots, comparar Record/detalles por orden y escribir filas de `History`; cubrirlo con pruebas unitarias/feature.
3. Integrar `record_created` en `RecordController::store` y `RecordFromImageController::store`, reemplazando el `status` de creación manual y manteniendo la transacción.
4. Extender `RecordController::update` y `UpdateRecordRequest` para aceptar `CONFIRMED`, calcular el diff, escribir el evento de reapertura y rechazar el no-op con `422 RECORD_UPDATE_NO_CHANGES`; conservar el comportamiento de `NEEDS_REVIEW`.
5. Añadir `IndexRecordHistoryRequest`, `HistoryResource`, la ruta `GET /records/{record}/history` y la autorización de lectura; cubrir roles, paginación, orden y errores.
6. Actualizar pruebas backend de creación manual/imagen, diff de campos, detalles agregados/eliminados, no-op, reapertura, confirmación, cancelación, rollback y ausencia de eventos AI.
7. Extender tipos, servicio y hooks del frontend con el contrato de historial, la invalidación tras mutaciones y la conservación de `code`/`details` en errores `422`.
8. Añadir el panel `RecordHistoryPanel` al detalle, con etiquetas, formato, paginación y estados; integrarlo sin romper el polling existente.
9. Habilitar `Editar registro` para `CONFIRMED` en `record-actions.ts` y ajustar el formulario de edición para explicar la reapertura y el motivo obligatorio.
10. Añadir pruebas Vitest de contrato de historial, permisos de `CONFIRMED` y esquemas; ejecutar suite backend, Pint, Vitest, ESLint, TypeScript y build.
11. Verificar el checkpoint en navegador: creación manual e imagen, corrección con diff, confirmación, reapertura de `CONFIRMED`, nueva confirmación, consulta de historial con usuario/valores/motivo/fecha y lectura `VIEWER`; documentar el resultado y actualizar 8.11 solo si todo pasa.

## Acceptance criteria

- [ ] No se agregan tablas ni migraciones.
- [ ] La creación manual escribe un único `record_created` null -> `CONFIRMED`, con usuario y `Registro creado manualmente`, y no un evento `status` de creación.
- [ ] La creación desde imagen escribe un único `record_created` null -> `PENDING`, con usuario y `Registro creado desde imagen`, en la misma transacción que Record, Image y AIProcessing.
- [ ] Las transiciones AI y los retries no escriben filas en `History`.
- [ ] Corregir un `NEEDS_REVIEW` escribe una fila por campo modificado con anterior, nuevo, usuario, motivo y `created_at`.
- [ ] Los campos de detalle usan `details[N].campo` con `N` 1-based igual a `order`; un detalle agregado o eliminado produce null -> valores o valores -> null.
- [ ] Corregir un `NEEDS_REVIEW` sin cambios escribe un único `record_updated` con el motivo y responde `200`.
- [ ] `PUT` acepta `CONFIRMED`, exige motivo, escribe el diff y `status CONFIRMED -> NEEDS_REVIEW`, y deja el registro en `NEEDS_REVIEW`.
- [ ] `PUT` sobre `CONFIRMED` sin cambios responde `422 RECORD_UPDATE_NO_CHANGES`, mantiene `CONFIRMED` y no escribe `History`.
- [ ] La reapertura conserva `source`, `Image` y `AIProcessing`.
- [ ] Confirmar escribe `status NEEDS_REVIEW -> CONFIRMED` con el motivo fijo según la fuente y el usuario.
- [ ] Cancelar escribe `status` al estado anterior -> `CANCELED` con el motivo y el usuario.
- [ ] Un fallo de persistencia revierte agregado, estado e History de creación, corrección, reapertura, confirmación o cancelación.
- [ ] `GET /records/{id}/history` responde paginado, más reciente primero, con 50 por defecto y máximo 100, incluyendo usuario, campo, anterior, nuevo, motivo y fecha.
- [ ] `EDITOR` y `VIEWER` pueden consultar el historial; anónimo recibe `401`, inexistente `404` y paginación inválida `422`.
- [ ] El historial es solo de lectura; no existen endpoints de actualización o borrado.
- [ ] El detalle muestra el panel de historial con etiquetas, formato, paginación y estados de carga/error/vacío.
- [ ] `EDITOR` puede editar un `CONFIRMED` desde la UI; al guardar queda `NEEDS_REVIEW` y puede confirmarlo; `VIEWER` no ve la acción.
- [ ] El panel se actualiza después de corregir, reabrir, confirmar o cancelar.
- [ ] Suite backend, Vitest, ESLint, TypeScript, build y Pint pasan.
- [ ] El checkpoint de navegador demuestra la trazabilidad completa y se documenta; 8.11 se marca completo solo entonces.

## Decisions

- **Sí:** auditar solo acciones humanas; las transiciones AI se consultan en `AIProcessing` y no tienen usuario humano.
- **Sí:** un único `record_created` al crear, sin snapshot de todos los campos.
- **Sí:** `record_created` reemplaza el evento `status` de creación manual.
- **Sí:** motivos fijos `Registro creado manualmente` y `Registro creado desde imagen`.
- **Sí:** diff por campo; los detalles se identifican por `order` 1-based y no por `record_detail_id`, porque las filas se recrean.
- **Sí:** reemplazar el `record_updated` genérico por el diff; conservarlo solo cuando no hay cambios en `NEEDS_REVIEW`.
- **Sí:** `PUT` reabre `CONFIRMED` y la confirmación sigue siendo un paso humano separado.
- **Sí:** rechazar con `422` un `PUT` sin cambios sobre `CONFIRMED`.
- **Sí:** `GET` paginado y panel mínimo; el diseño oficial queda para 8.15.
- **Sí:** `EDITOR` y `VIEWER` pueden leer historial.
- **Sí:** valores canónicos en texto y empresa por nombre para legibilidad.
- **No:** historial de Delivery en esta spec; se implementa en 8.12.
- **No:** eventos de AI/retry en `History`.
- **No:** edición, borrado, exportación o backfill de historial.
- **No:** versiones de registro, snapshots completos por operación o cambios al pipeline AI.

## Risks

| Risk | Mitigation |
| --- | --- |
| El diff por índice puede atribuir mal los cambios si se inserta o reordena un movimiento. | Documentar la semántica de reemplazo completo, usar `order` 1-based y mostrar el número de movimiento; una reconciliación por identidad estable queda para otra fase. |
| Reabrir un `CONFIRMED` lo saca de reportes hasta reconfirmarlo. | Responder `NEEDS_REVIEW`, mostrar el estado y la acción de confirmar en la UI; los reportes solo leen `CONFIRMED`. |
| El historial crece sin límite. | Paginación, índice existente por `record_id` y orden estable; la depuración no está en el MVP. |
| Cambiar el evento de creación manual puede romper pruebas o consumidores internos. | Actualizar documentación, pruebas y servicios en la misma spec; no hay consumidores externos. |
| Un `PUT` concurrente podría reabrir o confirmar un estado inesperado. | Bloquear la fila `Record`, releer el estado y escribir agregado, estado e History en una transacción. |
| Mostrar valores crudos sin formato puede confundir al usuario. | El panel traduce campos y formatea importes/fechas; el backend sigue siendo la fuente de los valores. |

## What is **not** in this spec

- Historial de `Delivery` o de denominaciones; corresponde a 8.12.
- Historial de transiciones AI, retries o llamadas a Ollama.
- Edición, borrado, exportación, purga o backfill de `History`.
- Edición de registros `PENDING`, `PROCESSING`, `ERROR` o `CANCELED`.
- Delivery, Word, Excel, reportes o documentos generados.
- Diseño oficial de UI/UX, dashboard o pantallas finales; corresponde a 8.15.
- Cambios a la validación de negocio, al pipeline AI o a las reglas de imágenes.
