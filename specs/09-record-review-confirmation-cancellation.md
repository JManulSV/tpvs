# SPEC 09 — Revisión, corrección, confirmación y cancelación de registros

> **Status:** Draft
> **Depends on:** SPEC 05, SPEC 07, SPEC 08
> **Date:** 2026-10-22
> **Objective:** Completar el flujo humano que revisa, corrige, confirma o cancela registros `IMAGE`/`MANUAL` en `NEEDS_REVIEW` y registros en `ERROR`, con validación backend y auditoría básica.

## Why this spec exists

La fase 8.9 deja propuestas utilizables en `NEEDS_REVIEW` y fallos definitivos en `ERROR`, pero no existe una forma autorizada de resolverlos. Sin esta fase, un registro IMAGE nunca puede confirmarse y un error definitivo queda acumulado indefinidamente. SPEC 09 cierra el ciclo humano sin adelantar la auditoría campo a campo de 8.11 ni el diseño oficial de 8.15.

## Scope

**In:**

- Extender `PUT /api/records/{record}` para aceptar `MANUAL` e `IMAGE` en `NEEDS_REVIEW` con reemplazo completo del agregado y motivo obligatorio.
- Mantener `source`, `status`, `Image` y `AIProcessing` intactos durante la corrección; no volver a ejecutar AI ni modificar su resultado.
- Implementar `POST /api/records/{record}/cancel` para `NEEDS_REVIEW` y `ERROR`, con motivo obligatorio y transición atómica a `CANCELED`.
- Ajustar la confirmación de IMAGE para usar un motivo de History acorde a la fuente, sin bloquear por issues de AI cuando el agregado es válido.
- Añadir autorización `EDITOR` para corregir, confirmar y cancelar; `VIEWER` mantiene lectura.
- Implementar UI funcional mínima: carga de imagen, progreso, revisión con imagen e issues, corrección, confirmación, cancelación y retry de `ERROR`.
- Actualizar servicios, hooks, tipos, esquemas y pruebas del frontend.
- Actualizar la máquina de estados, el contrato API y las guías backend/frontend.
- Documentar el checkpoint de 8.10 con verificación backend, frontend y navegador.

**Out of scope (for future specs):**

- History campo a campo con valores anteriores/nuevos; corresponde a 8.11.
- Delivery, Word, Excel, reportes y documentos; corresponden a 8.12–8.14.
- Diseño oficial de UI/UX, dashboard, pantallas finales y pulido visual; corresponde a 8.15.
- Cancelar registros `CONFIRMED`, `PENDING` o `PROCESSING`.
- Borrar físicamente registros, imágenes o filas de `AIProcessing`.
- Reemplazar la imagen cuando ya tiene intentos AI; la regla actual de SPEC 05/08 se mantiene.
- Volver a ejecutar AI desde la pantalla de revisión; solo se reutiliza el retry manual existente para `ERROR`.
- Versionar imágenes, crear múltiples filas de `AIProcessing` o guardar propuestas históricas.
- Acciones masivas, edición parcial campo a campo o cancelación en lote.

## Data model

No se agregan tablas ni migraciones. Se reutilizan `Record`, `RecordDetail`, `Image`, `AIProcessing` y `History`.

Se amplía la máquina de estados documentada:

```text
NEEDS_REVIEW -> CONFIRMED
NEEDS_REVIEW -> CANCELED
ERROR        -> CANCELED      (nueva transición)
ERROR        -> PROCESSING    (retry existente)
```

Eventos de `History` producidos por esta spec:

| Acción | `field_name` | `previous_value` | `new_value` | `reason` |
| --- | --- | --- | --- | --- |
| Corregir MANUAL o IMAGE | `record_updated` | `null` | `null` | Motivo ingresado por el Editor |
| Confirmar MANUAL | `status` | `NEEDS_REVIEW` | `CONFIRMED` | `Registro manual confirmado` |
| Confirmar IMAGE | `status` | `NEEDS_REVIEW` | `CONFIRMED` | `Registro de imagen confirmado` |
| Cancelar | `status` | `NEEDS_REVIEW` o `ERROR` | `CANCELED` | Motivo ingresado por el Editor |

Reglas de persistencia:

- La corrección reemplaza empresa, periodo y todos los movimientos, igual que el reemplazo manual actual.
- La corrección de IMAGE conserva la misma `Image`, la misma fila `AIProcessing` y su resultado normalizado.
- La cancelación conserva `Image` y `AIProcessing`; no borra archivos ni evidencia.
- El detalle reemplazado puede recrear filas `record_details`; el diff campo a campo queda para 8.11.
- No se acepta `source`, `status`, `order`, `return_amount` ni totales desde el cliente.

## API contract

Todas las rutas requieren sesión Sanctum. Las mutaciones requieren CSRF y rol `EDITOR`.

| Método | Ruta | Comportamiento | Autorización |
| --- | --- | --- | --- |
| `PUT` | `/api/records/{record}` | Reemplaza el agregado completo de un registro `MANUAL` o `IMAGE` en `NEEDS_REVIEW`. | `EDITOR` |
| `POST` | `/api/records/{record}/confirm` | Revalida y confirma un registro en `NEEDS_REVIEW`. | `EDITOR` |
| `POST` | `/api/records/{record}/cancel` | Cancela un registro en `NEEDS_REVIEW` o `ERROR` con motivo. | `EDITOR` |
| `POST` | `/api/records/{record}/ai-processing/retry` | Retry manual existente para `ERROR`; se reutiliza desde la UI. | `EDITOR` |

### Corrección

`PUT /api/records/{record}` mantiene el payload completo actual y exige `reason`:

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
  ],
  "reason": "Se corrigió la sucursal y el número de operación"
}
```

- Solo acepta `MANUAL` o `IMAGE` con estado `NEEDS_REVIEW`.
- Conserva `source` y `status`; la respuesta sigue en `NEEDS_REVIEW`.
- Valida exactamente igual que la captura manual: empresa, periodo, al menos un movimiento, número de operación, afiliación, sucursal, ingreso y exactamente una forma de comisión.
- Calcula comisión y retorno en backend con aritmética decimal.
- `reason` se recorta, es obligatorio y tiene máximo 2000 caracteres.
- Escribe un evento `record_updated` en la misma transacción.
- No modifica `Image` ni `AIProcessing`.
- Errores: `401`, `403`, `404`, `419`, `422`, `409 RECORD_UPDATE_BLOCKED` con `details.reason=INVALID_STATE`.

### Confirmación

`POST /api/records/{record}/confirm` mantiene el contrato actual:

- Solo `NEEDS_REVIEW`.
- Revalida el agregado persistido y las reglas financieras.
- Para IMAGE exige imagen activa, empresa, periodo y movimientos completos.
- Los issues de AI no bloquean por sí solos si el agregado ya es válido; el humano es la autoridad final.
- No acepta `status`, `source` ni `reason` del cliente.
- Escribe el evento `status` con el motivo correspondiente a la fuente.
- Errores: `409 RECORD_CONFIRMATION_BLOCKED` con `INVALID_STATE` o `BUSINESS_VALIDATION_FAILED`.

### Cancelación

`POST /api/records/{record}/cancel` recibe:

```json
{ "reason": "La imagen no es legible y no se puede reutilizar" }
```

- Solo acepta `NEEDS_REVIEW` o `ERROR`.
- `reason` es obligatorio, se recorta y tiene máximo 2000 caracteres.
- No acepta `status` ni `source` del cliente.
- Bloquea la fila, cambia el estado a `CANCELED` y crea el evento `status` en la misma transacción.
- No borra la imagen, el archivo privado ni `AIProcessing`.
- Después de cancelar, `GET /api/records/{record}/image` sigue respondiendo `409` conforme a SPEC 05.
- Errores: `401`, `403`, `404`, `419`, `422`, `409 RECORD_CANCELLATION_BLOCKED` con `details.reason=INVALID_STATE`.

## Review UI contract

La UI de esta fase es funcional, no el diseño final de 8.15.

- `/records/new` incorpora selección de origen: captura manual o carga de imagen.
- La carga de imagen acepta JPEG, PNG y WebP de hasta 10 MiB, muestra errores de validación y usa `POST /api/records/from-image`.
- Tras un `202`, redirige a `/records/{id}` y consulta el registro mientras esté `PENDING` o `PROCESSING`, deteniendo el polling en estados finales.
- `/records/{id}` actúa como detalle y como pantalla de revisión cuando el estado es `NEEDS_REVIEW` o `ERROR`.
- Muestra la imagen autorizada mediante el BFF, el estado, el resumen de `ai_processing.issues` y, si la empresa no se resolvió, el `company_name` extraído como ayuda.
- El formulario de revisión se precarga con los datos persistidos; el Editor corrige el agregado completo y guarda con motivo.
- El Editor puede confirmar mediante diálogo de confirmación y cancelar mediante diálogo que exige motivo.
- En `ERROR`, el Editor puede reintentar con el endpoint existente o cancelar.
- `VIEWER` ve imagen, datos e issues, pero no acciones de mutación.
- Un `409` por estado desactualizado refresca el registro y muestra el mensaje del backend.
- Los valores financieros y el estado mostrados provienen del backend; el cliente no calcula valores oficiales.
- No se muestran valores numéricos de confianza; el detalle visual de estados AI queda para 8.15.

## Implementation plan

1. Actualizar `docs/02_business_rules_and_states.md`, `docs/backend/03_api_contract.md`, `docs/backend/05_validation_authorization.md`, `docs/backend/06_domain_workflows.md`, `docs/frontend/features/records.md` y `docs/frontend/06_testing.md` con revisión, corrección, cancelación y `ERROR -> CANCELED`.
2. Añadir `cancel` a `RecordPolicy`, crear `CancelRecordRequest`, registrar la ruta y extender `RecordController` con la cancelación transaccional.
3. Extender `RecordController::update` para aceptar `MANUAL` e `IMAGE` en `NEEDS_REVIEW`, conservar el agregado de imagen/AI y escribir `record_updated`.
4. Ajustar la confirmación para usar el motivo de History según la fuente y mantener el gate de agregado completo.
5. Cubrir con feature tests autorización, estados, validación, motivo, transacciones, conservación de imagen/AI, bloqueo de imagen tras cancelar y errores `409`.
6. Extender tipos, servicios y hooks del frontend: carga multipart, cancelación, retry, URL autorizada de imagen y polling de estados.
7. Implementar la selección manual/imagen en `/records/new`, el formulario de carga y la pantalla de revisión/acciones en `/records/{id}` reutilizando el formulario existente.
8. Añadir pruebas Vitest para contratos de servicio, esquemas, acciones por rol y estados.
9. Ejecutar suite backend, Vitest, lint, TypeScript, build y Pint de archivos modificados.
10. Verificar el checkpoint en navegador: carga -> procesamiento -> revisión -> corrección -> confirmación, además de cancelación desde `ERROR`, retry y lectura `VIEWER`; documentar el proveedor AI usado y actualizar el estado de 8.10 solo si todo pasa.

## Acceptance criteria

- [ ] No se añaden tablas ni migraciones; la spec se apoya en el esquema y `History` existentes.
- [ ] `PUT /api/records/{record}` acepta `MANUAL` e `IMAGE` en `NEEDS_REVIEW` y conserva `source`, `status`, `Image` y `AIProcessing`.
- [ ] La corrección exige motivo, valida el agregado completo, calcula importes en backend y escribe `record_updated` en la misma transacción.
- [ ] La corrección rechaza `CONFIRMED`, `CANCELED`, `PENDING`, `PROCESSING` y `ERROR` con `409 RECORD_UPDATE_BLOCKED`.
- [ ] `POST /api/records/{record}/confirm` confirma IMAGE válidos en `NEEDS_REVIEW` y mantiene el bloqueo para agregados incompletos o inválidos.
- [ ] Los issues de AI no bloquean la confirmación si el agregado es válido; el evento History usa el motivo correspondiente a la fuente.
- [ ] `POST /api/records/{record}/cancel` acepta `NEEDS_REVIEW` y `ERROR`, exige motivo y cambia a `CANCELED` atómicamente con evento `status`.
- [ ] La cancelación no borra imagen, archivo ni `AIProcessing`, y la imagen deja de ser accesible después de cancelar.
- [ ] Cancelar `CONFIRMED`, `PENDING`, `PROCESSING` o `CANCELED` devuelve `409 RECORD_CANCELLATION_BLOCKED`.
- [ ] `VIEWER` recibe `403` en corregir, confirmar, cancelar y retry; conserva lectura de registro, imagen e issues.
- [ ] Un fallo de persistencia o History revierte completamente corrección, confirmación o cancelación.
- [ ] La UI permite cargar una imagen, muestra procesamiento y llega a revisión sin exponer rutas privadas ni la respuesta cruda de AI.
- [ ] La pantalla de revisión muestra imagen, estado e issues, y permite corregir, confirmar y cancelar según rol y estado.
- [ ] En `ERROR` la UI muestra diagnóstico seguro y permite retry o cancelación.
- [ ] El polling se detiene en estados finales y un `409` refresca el registro.
- [ ] Suite backend, Vitest, lint, TypeScript, build y Pint pasan; las guías reflejan el nuevo contrato.
- [ ] El checkpoint de navegador demuestra el flujo completo y se documenta el proveedor AI usado.

## Decisions

- **Sí:** UI funcional mínima en 8.10, incluyendo carga de imagen, porque el checkpoint exige el flujo completo.
- **Sí:** cancelar desde `NEEDS_REVIEW` y `ERROR` con motivo; se amplía la máquina de estados con `ERROR -> CANCELED` para no acumular fallos definitivos.
- **No:** cancelar `CONFIRMED`, `PENDING` o `PROCESSING`; los dos últimos pueden tener trabajo en curso y el primero afecta reportes y entregas.
- **Sí:** extender el `PUT` existente para `MANUAL` e `IMAGE`; se evita duplicar validación, transacción y eventos.
- **Sí:** reemplazo completo del agregado, no `PATCH` campo a campo.
- **Sí:** confirmar aunque queden issues de AI si el agregado es válido; el humano es la autoridad final y los issues son señales de revisión.
- **Sí:** solo eventos History de estado/genéricos; el diff campo a campo se implementa en 8.11.
- **Sí:** `record_updated` como evento genérico de corrección para ambas fuentes.
- **Sí:** motivos de confirmación distintos para MANUAL e IMAGE.
- **Sí:** reutilizar `/records/{id}` como detalle y revisión; no se crea una ruta adicional en esta fase.
- **Sí:** reutilizar el retry manual existente desde la UI para `ERROR`; no se cambia la lógica AI.
- **No:** borrar imágenes, archivos o `AIProcessing` al cancelar.
- **No:** volver a llamar a Ollama desde la revisión, reemplazar imágenes con intentos AI o versionar propuestas.
- **No:** mostrar confianza numérica ni implementar el diseño visual definitivo; queda para 8.15.

## Risks

| Risk | Mitigation |
| --- | --- |
| El reemplazo completo recrea detalles y puede perder trazabilidad fina. | Mantener el evento genérico con motivo y usuario; implementar el diff campo a campo en 8.11. |
| Un job o request concurrente podría cambiar el estado durante la revisión. | Bloquear la fila en corrección, confirmación y cancelación; revalidar el estado dentro de la transacción. |
| Cancelar un `ERROR` podría dejar una imagen inaccesible que aún se necesita. | Exigir motivo, conservar archivo y AIProcessing, y permitir reintentar antes de cancelar. |
| Los issues de AI son técnicos y en inglés. | Mostrarlos como diagnóstico seguro en 8.10; la traducción y el diseño de estados quedan para 8.15. |
| El polling puede generar carga innecesaria. | Intervalo acotado y detención inmediata en estados finales. |
| Una UI desactualizada podría intentar confirmar o cancelar un estado ya cambiado. | Manejar `409` con refresco del registro y mensaje del backend. |

## What is **not** in this spec

- Diff campo a campo de `History`, auditoría de entregas o historial de intentos AI.
- Delivery, Word, reportes, Excel o cualquier documento generado.
- Diseño oficial de las pantallas, dashboard o componentes visuales definitivos.
- Cancelación de registros confirmados, acciones masivas o borrado físico.
- Reemplazo de imagen con intentos AI, nuevas ejecuciones AI desde revisión o cambios al pipeline de 8.9.
