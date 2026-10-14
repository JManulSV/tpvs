# SPEC 04 — Flujo manual de registros

> **Status:** Approved
> **Depends on:** SPEC 03
> **Date:** 2026-10-14
> **Objective:** Implementar de extremo a extremo la consulta, captura, revisión y confirmación de registros manuales con validación autoritativa en Laravel.

## Scope

**In:**

- Implementar el listado autenticado de registros con búsqueda por empresa, número de operación, afiliación y sucursal, filtros por empresa, año, mes, semana, estado y origen, y paginación.
- Implementar consulta individual de registros con sus movimientos ordenados y valores financieros calculados desde los movimientos.
- Permitir que `EDITOR` cree un registro con `source=MANUAL`, sin imagen ni intento `AIProcessing`, y con estado inicial `NEEDS_REVIEW`.
- Capturar empresa, año ISO, semana ISO, fechas exactas de inicio y fin de la semana, afiliación, sucursal y uno o más movimientos.
- Capturar cada movimiento mediante ingreso y una comisión elegida como porcentaje o importe fijo. Laravel calcula la comisión y el retorno con precisión de centavos.
- Rechazar con `422` los campos inválidos, importes imposibles y períodos cuyas fechas no correspondan exactamente a su semana ISO.
- Permitir que `EDITOR` edite el agregado completo de un registro `MANUAL` en `NEEDS_REVIEW`, reemplazando sus campos generales y movimientos en una única transacción. Exigir un motivo y persistir un evento genérico de `History` por edición.
- Permitir que `EDITOR` confirme un registro manual válido en `NEEDS_REVIEW`. Guardar la transición y su evento de historial en la misma transacción.
- Permitir que `EDITOR` y `VIEWER` consulten los registros. La interfaz solo muestra las acciones que corresponden al rol, origen y estado; Laravel aplica la autorización efectiva.
- Implementar `/records`, `/records/new` y `/records/[recordId]` para el listado, captura y revisión/detalle, con estados de carga, error, vacío, sin resultados y validación.
- Mantener el flujo por el límite existente `UI/hooks -> services -> Next.js BFF -> Laravel API`.
- Documentar el contrato implementado y el recorrido reproducible de aceptación; actualizar el checkpoint 8.6 solo después de verificarlo.

**Out of scope (para fases posteriores):**

- Carga, reemplazo, visualización protegida o almacenamiento de imágenes.
- Creación de registros desde imagen, procesamiento AI, jobs y reintentos.
- Cancelación de registros.
- Edición de registros `CONFIRMED` y el flujo de retorno a revisión para cambios confirmados.
- Auditoría detallada campo-a-campo con snapshots antes/después. En esta fase se registra un evento genérico de edición; la auditoría ampliada corresponde a 8.11.
- Historial consultable en una pantalla.
- Filtros o reportes adicionales a los descritos en este contrato.
- Diseño visual oficial y pulido global, previstos para la fase 8.15.
- Preparación de entregas, documentos Word, reportes oficiales y exportación Excel.

## Data model

No se introducen entidades ni columnas nuevas. Se reutilizan `Record`, `RecordDetail`, `Company` y `History` existentes.

La creación asigna en el backend `source=MANUAL` y `status=NEEDS_REVIEW`; esos campos no son controlables por el cliente. No se crean filas `Image` ni `AIProcessing`.

Los campos generales requeridos son `company_id`, `week`, `year`, `week_start_date`, `week_end_date`, `affiliation` y `branch`. `week` y `year` identifican una semana ISO. `week_start_date` y `week_end_date` deben ser exactamente el lunes y domingo de esa semana ISO; `year` es el año ISO, que puede diferir del año calendario de una fecha en los límites de año.

Cada movimiento contiene `operation_number`, `income`, una de las dos entradas de comisión y un orden asignado por el backend:

```text
Movimiento recibido:
  operation_number: string requerido
  income: decimal(12,2), >= 0
  commission_percentage: decimal(5,2), 0..100, opcional
  commission: decimal(12,2), importe fijo opcional
  order: entero positivo asignado según la posición recibida

Regla de entrada:
  exactamente uno de commission_percentage o commission debe estar presente

Valores autoritativos calculados por Laravel:
  commission = ROUND_HALF_UP(income * commission_percentage / 100, 2)
                cuando se proporciona commission_percentage
  commission = importe recibido cuando se proporciona comisión fija
  return_amount = income - commission
```

La comisión fija no puede superar el ingreso. La comisión porcentual y la comisión fija se almacenan en los campos existentes; cuando se elige comisión fija, `commission_percentage` es `null`. El retorno no se acepta como entrada del cliente. Los importes se calculan y comparan como decimales exactos a dos posiciones, no mediante aritmética binaria de punto flotante. El agregado requiere al menos un movimiento y los movimientos se guardan en el orden enviado.

La suma de `income`, `commission` y `return_amount` es derivada de `RecordDetail`; no se persiste como fuente de verdad. El listado y el detalle pueden devolver estos totales calculados para presentación.

Para el historial mínimo:

- Una edición crea una fila `History` con `field_name=manual_record_updated`, `previous_value=null`, `new_value=null`, `user_id` autenticado y el motivo obligatorio recibido.
- Una confirmación crea una fila `History` para el campo `status`, con `previous_value=NEEDS_REVIEW`, `new_value=CONFIRMED`, `user_id` autenticado y `reason=Registro manual confirmado`.
- Escritura del registro/movimientos y su evento `History` es atómica.

## API contract

Todos los endpoints requieren sesión autenticada. Las consultas permiten `EDITOR` y `VIEWER`; creación, edición y confirmación requieren `EDITOR`.

| Método | Ruta | Comportamiento |
| --- | --- | --- |
| `GET` | `/api/records` | Lista paginada, búsqueda y filtros. |
| `POST` | `/api/records` | Crea un registro manual en `NEEDS_REVIEW`; responde `201`. |
| `GET` | `/api/records/{id}` | Devuelve el registro y movimientos ordenados; inexistente responde `404`. |
| `PUT` | `/api/records/{id}` | Reemplaza atómicamente los campos editables y todos los movimientos de un registro manual en `NEEDS_REVIEW`. Requiere `reason`. |
| `POST` | `/api/records/{id}/confirm` | Valida y confirma un registro manual en `NEEDS_REVIEW`. |

El listado acepta `search`, `company_id`, `year`, `month`, `week`, `status`, `source`, `page` y `per_page`. `search` coincide parcialmente y sin distinguir mayúsculas con nombre de empresa, número de operación, afiliación o sucursal. `month` filtra por el mes de `week_end_date`. Los resultados se ordenan por `updated_at` descendente. `page` inicia en 1; `per_page` es 15 por defecto y su máximo es 100. Parámetros inválidos responden `422`. La respuesta usa la paginación estándar de Laravel (`data`, `links`, `meta`).

Los datos de creación y reemplazo usan los campos generales y el arreglo `details` descritos en Data model. `PUT` requiere el agregado completo y `reason`; no es una actualización parcial. La respuesta del recurso incluye identidad, empresa, origen, estado, período, afiliación, sucursal, movimientos ordenados y totales calculados. Las respuestas de validación usan la estructura `422` estándar de Laravel. Errores de transición o reglas de negocio exponen una respuesta estable con código de error.

## Implementation plan

1. Documentar el contrato de Records, los DTOs, las reglas ISO y financieras, permisos, errores y estados de interfaz en las guías backend y frontend antes de consumir el API.
2. Implementar los requests, recurso, política, rutas y consulta paginada de registros. Cubrir autenticación, ambos roles, búsqueda, filtros, orden, paginación, relaciones y `404` con pruebas feature.
3. Implementar `BusinessValidator` y el flujo de captura manual. Validar fechas ISO exactas, compañía, campos requeridos, movimientos, selección de comisión, redondeo decimal y retorno no negativo. Crear Record y RecordDetails en una transacción con estado `NEEDS_REVIEW`; demostrar que no se crean Image ni AIProcessing.
4. Implementar el reemplazo atómico de un agregado manual `NEEDS_REVIEW`, incluyendo validación completa y evento genérico `History` con actor y motivo. Cubrir autorización, origen/estado no editable, rollback ante fallo y preservación del orden de movimientos.
5. Implementar confirmación transaccional de registros manuales válidos con evento de historial y motivo fijo. Cubrir transiciones inválidas, datos no válidos y rol `VIEWER`.
6. Implementar servicios, tipos, esquema de formularios y hooks frontend para listado, detalle, creación, reemplazo y confirmación a través del cliente API y BFF existentes.
7. Implementar `/records`, `/records/new` y `/records/[recordId]`. Mostrar búsqueda, filtros, paginación, información financiera enviada por backend, validación, motivo de edición y confirmación explícita; evitar acciones no permitidas según rol/origen/estado y no anticipar optimistamente una transición.
8. Ejecutar pruebas backend, Vitest, lint, TypeScript y build; realizar aceptación en navegador con `EDITOR` y `VIEWER` contra el stack integrado. Documentar resultados y actualizar las guías y el checkpoint de 8.6 solo después de que pasen.

## Acceptance criteria

- [ ] `EDITOR` y `VIEWER` autenticados pueden consultar el listado y el detalle; las solicitudes anónimas reciben `401`.
- [ ] El listado pagina con `page=1`, `per_page=15` por defecto y máximo 100, ordena por `updated_at` descendente y valida parámetros inválidos con `422`.
- [ ] La búsqueda parcial sin distinción de mayúsculas incluye empresa, número de operación, afiliación y sucursal.
- [ ] Los filtros por empresa, año, mes, semana, estado y origen afectan los resultados; el filtro mensual usa `week_end_date`.
- [ ] Un `EDITOR` crea un agregado manual válido con respuesta `201`, origen `MANUAL`, estado `NEEDS_REVIEW` y al menos un movimiento.
- [ ] La creación manual no crea `Image` ni `AIProcessing` y no inicia jobs AI.
- [ ] Empresa, año ISO, semana ISO, inicio, fin, afiliación y sucursal son requeridos; las fechas deben ser exactamente lunes y domingo de la semana ISO indicada.
- [ ] La comisión se captura como porcentaje o importe fijo, nunca ambos; porcentaje admite 0–100 y la comisión fija no supera el ingreso.
- [ ] Laravel calcula comisión porcentual con redondeo `ROUND_HALF_UP` a dos decimales y deriva siempre `return_amount = income - commission` usando precisión decimal exacta.
- [ ] Los valores imposibles, datos fuera de rango, ausencia de movimientos y payloads inválidos se rechazan con `422` y no dejan persistencia parcial.
- [ ] El detalle devuelve los movimientos en orden y los totales derivados del backend; no expone rutas privadas ni trata totales duplicados como fuente de verdad.
- [ ] Solo `EDITOR` puede crear; `VIEWER` recibe `403` al intentar mutar directamente el API.
- [ ] Solo un registro `MANUAL` en `NEEDS_REVIEW` puede editarse en 8.6; el reemplazo completo requiere motivo y es atómico para Record, movimientos e History.
- [ ] Cada edición genera exactamente el evento genérico `manual_record_updated` con actor y motivo, sin simular auditoría campo-a-campo.
- [ ] Un registro manual válido en `NEEDS_REVIEW` puede confirmarse mediante acción explícita; el estado y su evento History se guardan en una transacción con motivo fijo.
- [ ] Confirmar un registro inválido, editar un estado/origen no permitido o repetir una transición inválida no altera los datos y devuelve un error estable.
- [ ] `CONFIRMED` no puede editarse ni reabrirse mediante este contrato.
- [ ] La interfaz ofrece `/records`, `/records/new` y `/records/[recordId]` con estados de carga, error, vacío, sin coincidencias y validación accesibles en español.
- [ ] `VIEWER` puede consultar los registros, no ve acciones de mutación y no puede mutar llamando directamente al BFF/API.
- [ ] Los valores financieros y estados presentados en la interfaz provienen de Laravel; la interfaz no adelanta una confirmación ni constituye autoridad de negocio.
- [ ] Las pruebas backend y frontend, lint, comprobación TypeScript y build de producción pasan; el recorrido de navegador verifica creación, edición y confirmación de `EDITOR`, y consulta/rechazo de mutaciones de `VIEWER`.
- [ ] La documentación refleja el contrato verificado y 8.6 solo se marca completa tras pasar el checkpoint de registro manual confirmado sin intento AI.

## Decisions

- **Sí:** un spec vertical backend/frontend, como SPEC 03. El contrato y el flujo completo se pueden validar juntos.
- **Sí:** el listado incluye todos los orígenes y estados, aunque las acciones manuales de este spec solo aplican a `MANUAL` en `NEEDS_REVIEW`.
- **Sí:** búsqueda por empresa, número de operación, afiliación y sucursal; filtros de compañía, año, mes, semana, estado y origen.
- **Sí:** paginación `page`/`per_page`, 15 por defecto, máximo 100, orden por actualización más reciente y mes basado en `week_end_date`.
- **Sí:** semana ISO exacta lunes-domingo, con año ISO, incluyendo los casos donde el año ISO difiere del año calendario de una fecha.
- **Sí:** afiliación y sucursal son obligatorias para esta captura, junto con empresa y período.
- **Sí:** una persona captura ingreso y elige porcentaje o comisión fija. El porcentaje es opcional en el modelo cuando se usa comisión fija.
- **Sí:** Laravel es autoridad de commission y return. Usa `ROUND_HALF_UP` a centavos para porcentaje y deriva el retorno; no acepta el retorno como entrada.
- **Sí:** las entradas imposibles se rechazan con `422`; una captura válida permanece en `NEEDS_REVIEW` hasta confirmación humana explícita.
- **Sí:** `POST /api/records/{id}/confirm` expresa la transición de estado separada de la actualización del recurso.
- **Sí:** editar reemplaza el agregado entero de forma atómica y requiere motivo; se limita a registros manuales en `NEEDS_REVIEW`.
- **Sí:** usar un evento genérico de History para edición y una entrada de transición para confirmación. La auditoría campo-a-campo se difiere a 8.11.
- **No:** incluir cancelación, imágenes, IA, edición de confirmados, entregas, documentos o reportes dentro de 8.6.
- **No:** adelantar el diseño UI/UX oficial de 8.15.

## Risks

| Riesgo | Mitigación |
| --- | --- |
| Los límites de año ISO producen un año ISO diferente al calendario de algunas fechas. | Compartir la validación de calendario entre API y pruebas, y probar semanas que cruzan diciembre/enero. |
| La aritmética de dinero varía por coerción a punto flotante o redondeo implícito. | Usar operaciones decimales explícitas, definir `ROUND_HALF_UP` y cubrir casos de medio centavo en pruebas. |
| La búsqueda y filtros combinados pueden producir resultados incoherentes o consultas costosas. | Validar parámetros, construir filtros en Laravel, indexar según el patrón verificado y probar combinaciones relevantes. |
| El evento genérico de edición no permite reconstruir los valores anteriores y nuevos. | Limitarlo explícitamente a trazabilidad mínima en 8.6 y completar snapshots por campo en la fase 8.11. |
| Una actualización de movimientos parcialmente aplicada dejaría importes inconsistentes. | Reemplazar Record, detalles y evento History dentro de una única transacción y probar rollback. |

## What is **not** in this spec

- Carga, asociación, reemplazo o visualización de imágenes.
- Creación por imagen, procesamiento AI, jobs y reintentos.
- Cancelar registros.
- Editar o reabrir registros confirmados.
- Historial con diferencias antes/después por campo o una pantalla de historial.
- Preparar entregas, generar Word, consultar reportes o exportar Excel.
- Diseño visual oficial o pulido global de la aplicación.
