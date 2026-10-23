# SPEC 08 — Pipeline asíncrono de extracción AI

> **Status:** Implemented
> **Depends on:** SPEC 06, SPEC 07
> **Date:** 2026-10-20
> **Objective:** Crear Records IMAGE desde una carga autorizada, procesar su imagen mediante Ollama en cola y persistir una propuesta validada para revisión humana o un error diagnosticable.

## Why this spec exists

La fase 8.8 confirmó latencia compatible con el flujo de oficina, pero la salida requiere normalización y revisión. El pipeline debe conservar evidencia cruda y normalizada, no permitir que AI confirme datos y manejar la inferencia sin bloquear una solicitud HTTP.

## Scope

**In:**

- Implementar `AIService`, `OllamaAIService`, DTO `AiExtractionResult`, `ExtractionValidator`, servicio de persistencia de extracción y `ProcessImageJob`.
- Implementar creación IMAGE mediante `POST /api/records/from-image`, transacción de Record/Image/AIProcessing y despacho del job después del commit.
- Aplicar el contrato, normalización numérica, reglas de fechas y umbral definidos en SPEC 06.
- Persistir una propuesta parcial utilizable y terminar en `Record.NEEDS_REVIEW` + `AIProcessing.COMPLETED`; fallos definitivos terminan en `ERROR`.
- Calcular valores financieros en Laravel con `BusinessValidator`; conservar como evidencia las comisiones, retornos y totales impresos por el documento.
- Resolver Company por igualdad exacta después de `trim` y comparación sin distinguir mayúsculas; dejar null ante ausencia, ambigüedad o diferencias de acento/puntuación.
- Implementar retry automático acotado para fallos transitorios del primer ciclo y retry manual de una sola ejecución para `EDITOR`.
- Incluir en el recurso Record el estado y resultado normalizado seguro de AI; nunca exponer respuesta cruda del proveedor.
- Extender el gate backend de confirmación para rechazar Records IMAGE incompletos o con invariantes de negocio inválidas.
- Configurar Ollama por variables de entorno y coordinar timeout HTTP, worker y visibilidad/retry de cola.
- Documentar API, operación, fallos y aceptación del checkpoint backend; mantener UI de revisión fuera de alcance.

**Out of scope (for future specs):**

- Formularios de upload, progreso AI, revisión/corrección de valores o UI completa para `EDITOR`; corresponde a 8.15.
- Flujo humano completo de corrección, confirmación y cancelación; corresponde a 8.10.
- Auditoría de transiciones/cambios AI en `History`; corresponde a 8.11.
- Cambiar el modelo candidato o exigir una puntuación mínima de exactitud del benchmark.
- Reemplazar imágenes con AIProcessing asociado, versionar imágenes o guardar múltiples propuestas históricas por imagen.
- Informes, Word, Excel, WhatsApp o llamadas desde navegador a Ollama.

## Data model

No se agrega una entidad de intento por ejecución. Se reutilizan `Record`, `RecordDetail`, `Image` y una única fila `AIProcessing` por imagen. SPEC 08 agrega una restricción única para `ai_processings.image_id` si no existe otra que imponga ese invariante.

```text
Record (source=IMAGE)
  PENDING -> PROCESSING -> NEEDS_REVIEW | ERROR
  company_id/year/week/dates: pueden ser null hasta revisión
  0..N RecordDetails provisionales; valores de negocio pueden ser null
  1 Image privada

AIProcessing (una fila lógica por Image)
  status: PENDING | PROCESSING | COMPLETED | ERROR
  provider, model, started_at, completed_at
  result: {
    prompt_version,
    raw_response,             // privado; solo persistencia backend
    normalized_extraction,    // respuesta canonicalizada
    issues,                   // contrato, confianza y negocio
    unconvertible_numeric_count
  }
  error_message: diagnóstico del último resultado/error
```

Una ejecución nueva reemplaza el resultado/error de la ejecución lógica anterior; no hay historial de llamadas Ollama ni filas múltiples de AIProcessing. Al iniciar un retry manual se limpia el resultado vigente para no mostrar una extracción anterior como si perteneciera al procesamiento actual. El texto crudo más reciente se guarda en la base de datos, pero no se serializa en respuestas API.

Cuando `company_name` tiene coincidencia única normalizada, se asigna su `company_id`. Si no existe coincidencia o hay más de una, el registro conserva `company_id=null` y la extracción normalizada retiene el nombre para revisión.

Para cada movimiento, Laravel persiste los valores convertibles que cumplen límites del esquema. Si existe porcentaje válido e ingreso válido, calcula comisión y retorno con la lógica decimal de backend. Si no hay porcentaje utilizable y existe una comisión fuente válida, la usa como comisión fija provisional y calcula el retorno. No acepta el retorno ni totales de origen como valores oficiales. Discrepancias, confianza baja y valores que no se puedan persistir por constraints se registran como issues y permanecen para revisión.

## API contract

Todas las rutas requieren sesión Sanctum; la creación y el retry requieren CSRF y rol `EDITOR`. El navegador accede por el BFF cuando exista UI; Laravel es el único componente que contacta Ollama.

| Método | Ruta | Comportamiento | Autorización |
| --- | --- | --- | --- |
| `POST` | `/api/records/from-image` | Recibe multipart `image`, crea Record IMAGE provisional, Image y AIProcessing PENDING, encola y responde `202 Accepted`. | `EDITOR` |
| `POST` | `/api/records/{record}/ai-processing/retry` | Reinicia un Record/AIProcessing ERROR para una ejecución manual y responde `202 Accepted`. | `EDITOR` |
| `GET` | `/api/records/{record}` | Incluye `ai_processing` seguro con status, provider, model, timestamps, normalización e issues. | `EDITOR` o `VIEWER` |

La carga acepta los tipos, tamaño, validación de contenido, disco privado y metadata segura definidos en SPEC 05. El endpoint no recibe `company_id`, estado, campos financieros ni datos extraídos; todos los valores proceden del servidor/proveedor. La respuesta 202 incluye el recurso con Record `PENDING` y AIProcessing `PENDING`. El worker actualiza estado a `PROCESSING` cuando comienza.

El recurso AI expuesto puede incluir `status`, `provider`, `model`, `started_at`, `completed_at`, `normalized_extraction`, `issues` y el contador de valores no convertibles. Nunca incluye `raw_response`, rutas/keys privadas, configuración de host, stack traces ni excepciones sin sanear. Los errores públicos usan códigos y razones estables.

`POST /api/records/{record}/ai-processing/retry` solo acepta un Record IMAGE con imagen activa y AIProcessing ERROR. Una solicitud duplicada mientras el registro no está en ERROR responde `409`. El Editor puede iniciar retries manuales repetidos; cada dispatch manual permite una sola llamada al proveedor.

Errores HTTP síncronos: `401` no autenticado, `403` rol inválido, `419` CSRF inválido, `404` Record inexistente, `409` retry no permitido, `422` upload inválido. Los errores asíncronos de Ollama se reflejan en estados/resultados persistidos, no como error de la petición original.

## Extraction outcome rules

- JSON no parseable, raíz/clave/wrapper requerido ausente o confianza estructural inválida lleva a `AIProcessing.ERROR` y `Record.ERROR`.
- Cinco o más valores numéricos no nulos que no pasen normalización inequívoca llevan a `AIProcessing.ERROR` y `Record.ERROR`. Se persiste la respuesta cruda, los problemas y el conteo; no se aplican campos parciales a Record/RecordDetail.
- De cero a cuatro valores numéricos no convertibles permiten guardar los otros campos y movimientos convertibles; los campos no convertibles se dejan null con issues y el Record termina `NEEDS_REVIEW`.
- `null` con confidence 0 y datos textuales faltantes/desconocidos no causan por sí solos `ERROR`. Compañía no resuelta, confianza baja, fecha no ISO/imposible, discrepancias o datos financieros incompletos quedan como issues y requieren revisión.
- Datos monetarios y porcentajes canónicos o convertibles pueden poblar propuestas; cálculos oficiales se hacen exclusivamente en Laravel. `source_commission` y `source_totals` son evidencia, nunca valores de autoridad. El retorno por movimiento no se extrae; Laravel lo deriva de ingreso y comisión.
- Un resultado utilizable marca `AIProcessing.COMPLETED` y `Record.NEEDS_REVIEW`, aunque contenga issues o nulos. No existe transición AI a `CONFIRMED`.
- Errores transitorios de conexión, timeout y HTTP 5xx se reintentan dentro del primer ciclo hasta tres ejecuciones totales (una inicial y dos retries). JSON inválido, extracción no utilizable y respuestas HTTP 4xx no transitorias no se reintentan automáticamente.
- Al agotarse el retry automático, el job deja ambos modelos en `ERROR` con código técnico y marca temporal. Un retry manual de Editor realiza exactamente una ejecución; si vuelve a fallar, queda `ERROR` sin retry automático.
- No se crean filas de `History` para estados AI; esa auditoría se implementa en 8.11.

## Queue and Ollama configuration

- `AI_OLLAMA_URL` configura el host accesible desde Laravel/worker; no se publica Ollama a navegadores ni se añade al Compose normal. El perfil experimental opt-in de 8.8 puede seguir utilizándose en desarrollo.
- `AI_OLLAMA_MODEL` configura el modelo y usa como valor inicial el candidato seleccionado y documentado por SPEC 06 (`gemma3:4b`, `qwen2.5vl:3b` o `minicpm-v:8b`). La spec de pipeline no vuelve a seleccionar el modelo ni asume de antemano cuál gana la comparación.
- `AI_OLLAMA_TIMEOUT` permite configurar el request y usa 300 segundos como máximo inicial basado en el spike.
- El timeout del worker supera el timeout HTTP; `retry_after` de la conexión database supera el timeout del worker. La configuración inicial documentada usa worker de 330 segundos y `retry_after` de 360 segundos.
- El job define explícitamente el máximo de ejecuciones del primer ciclo; el worker de Compose deja de imponer un límite global de un intento que lo contradiga. El modo de retry manual ejecuta una sola llamada.
- Fallos de dispatch posteriores al commit actualizan Record y AIProcessing a ERROR y dejan diagnóstico, evitando Records PENDING sin job conocido.

## Implementation plan

1. Documentar el contrato, estados, errores, normalización productiva, límites de retries y variables Ollama en guías backend, operaciones, seguridad, AI y API.
2. Añadir configuración de AI, timeout/provider/model y binding de `AIService`; probar configuración sin requerir servidor Ollama.
3. Implementar cliente Ollama HTTP y DTO de resultado que conserven respuesta cruda privada, versión de prompt, JSON decodificado y errores sanitizados; usar HTTP fakes para respuestas y fallos.
4. Implementar `ExtractionValidator` con las reglas de SPEC 06 y contador global; cubrir estructura estricta, formatos inequívocos, nulos, fechas, límite 4/5 y JSON inválido.
5. Añadir migración para imponer una fila AIProcessing por Image y revisar `AiProcessing`/`Image` para operaciones de estado y bloqueo idempotentes.
6. Implementar `RecordExtractionService` que resuelva Company sin fuzzy match, conserve movimientos en orden, calcule importes con aritmética decimal de negocio y guarde issues/evidencia en una transacción.
7. Implementar Form Request, ruta y endpoint `POST /records/from-image`: validar Editor/CSRF/upload, guardar imagen privada, crear agregado provisional y limpiar archivo si falla persistencia; dispatch del job únicamente después del commit.
8. Implementar `ProcessImageJob` con locks/idempotencia, estados, política inicial de tres ejecuciones transitorias, persistencia atómica de extracción y fallo durable de Record/AIProcessing.
9. Implementar retry manual de una sola ejecución para Editor, recurso seguro `ai_processing` en consultas y gate de confirmación que rechaza datos IMAGE incompletos o inválidos.
10. Configurar worker/`retry_after`, variables en servicios backend y queue-worker, documentación operativa y pruebas feature/integración; demostrar creación HTTP, procesamiento fake y estados persistidos con Editor/Viewer.

## Acceptance criteria

- [x] Solo `EDITOR` autenticado con CSRF válido puede crear desde imagen o solicitar retry; `VIEWER` recibe `403`, anónimo `401` y CSRF inválido `419`.
- [x] Un upload inválido responde `422` sin Record, Image, AIProcessing, archivo activo ni job.
- [x] La creación válida persiste un Record IMAGE PENDING, Image privada y exactamente un AIProcessing PENDING dentro de la misma operación y retorna `202`.
- [x] El job se despacha después del commit; un fallo de persistencia limpia el archivo nuevo y un fallo de dispatch queda persistido como ERROR diagnosticable.
- [x] La URL de Ollama no aparece en código cliente, APIs públicas ni configuración del navegador; solo Laravel worker llama al proveedor.
- [x] La respuesta cruda, incluso JSON inválido, se conserva en base de datos backend y nunca aparece en el RecordResource ni en logs públicos; el recurso expone status, normalizado e issues sin claves privadas.
- [x] Ollama desconectado, timeout y HTTP 5xx generan hasta tres ejecuciones en el primer ciclo; al agotarse, Record y AIProcessing quedan ERROR.
- [x] Fallos estructurales, HTTP 4xx no transitorios y cinco o más valores numéricos no convertibles producen ERROR sin aplicar valores parciales al agregado.
- [x] De cero a cuatro valores numéricos no convertibles permiten persistir campos restantes y terminan Record NEEDS_REVIEW / AIProcessing COMPLETED.
- [x] Nulos válidos, compañía sin match, fecha no ISO, afiliación/sucursal/operación faltante, baja confianza y discrepancias nunca confirman el registro y se conservan como issues/revisión.
- [x] Company solo se asigna mediante coincidencia única exacta después de trim y comparación case-insensitive; no se ignoran acentos/puntuación ni se usa fuzzy matching.
- [x] Las comisiones y retornos oficiales se calculan con lógica de backend; comisión fuente fija solo es provisional cuando es válida, y diferencias con importes/totales impresos quedan documentadas.
- [x] Un resultado utilizable marca `AIProcessing.COMPLETED` y `Record.NEEDS_REVIEW`; ningún job/transición AI pone Record CONFIRMED.
- [x] Un retry manual solo se admite en ERROR, requiere Editor, reutiliza la misma fila y realiza una sola ejecución; otro estado responde `409`.
- [x] Jobs duplicados o ejecuciones ya completadas no realizan llamadas duplicadas ni sobrescriben una extracción utilizable.
- [x] El endpoint de confirmación rechaza IMAGE con campos obligatorios ausentes o cálculos/reglas inválidas y no cambia estado ni escribe History ante rechazo.
- [x] El primer ciclo permite tres llamadas totales ante fallos transitorios; cada retry manual permite una llamada y puede repetirse por Editor.
- [x] El worker supera los 300 segundos del cliente AI y `retry_after` supera el timeout del worker; Compose ya no fuerza una sola ejecución incompatible.
- [x] No se crean entradas History para transiciones AI dentro de este spec.
- [x] Las pruebas cubren Editor/Viewer, HTTP fake, validación, estados, persistencia, retry, idempotencia, transacciones, privacidad y confirmación bloqueada; el checkpoint documenta procesamiento sin confirmación automática.

## Decisions

- **Sí:** usar endpoint dedicado `POST /api/records/from-image`; el endpoint manual sigue capturando y confirmando agregados completos.
- **Sí:** crear Record provisional antes del procesamiento y contestar `202`; la inferencia corre exclusivamente en queue worker.
- **Sí:** una fila lógica `AIProcessing` por imagen, reutilizada en retries; guardar solo el resultado/error más reciente, sin historial de ejecuciones.
- **Sí:** el primer ciclo tiene máximo de tres llamadas ante errores transitorios; retry manual de Editor realiza una sola llamada por dispatch.
- **Sí:** resultado AI estructuralmente correcto pero parcial termina en NEEDS_REVIEW; cinco o más valores numéricos no convertibles o envelope inválido terminan en ERROR sin aplicar datos parciales.
- **Sí:** `AIProcessing.COMPLETED` significa que la extracción terminó; `Record.NEEDS_REVIEW` significa que requiere decisión humana.
- **Sí:** preservar raw y normalizado; raw queda privado en PostgreSQL, normalizado/issues se exponen a usuarios autenticados.
- **Sí:** Company se resuelve solo mediante igualdad normalizada trim/case; faltante o ambigua se deja null para revisión.
- **Sí:** porcentaje preferido para cálculos; comisión fuente puede actuar como importe fijo provisional si falta porcentaje y el dato es válido. Retornos y totales fuente nunca son oficiales.
- **Sí:** timeout HTTP 300 s, worker 330 s y `retry_after` 360 s como valores iniciales; configurar URL/modelo Ollama por entorno, usar el candidato elegido en SPEC 06 y excluir servicio del Compose normal.
- **Sí:** extender solo el gate de validación backend de confirmación; la corrección/UI corresponde a etapas posteriores.
- **No:** History para transiciones AI en 8.9; se difiere a 8.11.
- **No:** UI AI, retry desde navegador, corrección humana, confirmación/cancelación completa, versiones de imagen, extracción con fuzzy match o confirmación automática.

## Risks

| Risk | Mitigation |
| --- | --- |
| Timeouts inconsistentes pueden hacer que una inferencia siga activa cuando la cola redespacha el job. | Configurar timeout HTTP < timeout worker < `retry_after`, bloquear agregados durante procesamiento y probar el runtime Compose. |
| Normalización válida formalmente puede ocultar un error semántico del modelo. | Conservar raw, añadir issues y discrepancias, calcular con Laravel y dejar todos los resultados útiles en NEEDS_REVIEW. |
| La fila única reutilizada no conserva llamadas anteriores. | Documentar explícitamente que solo se retiene el resultado/error más reciente; la historia detallada de cambios queda para 8.11 si se requiere. |
| Filesystem, PostgreSQL y queue no comparten transacción distribuida. | Guardar archivo con clave aleatoria, compensar errores de DB, despachar después del commit y marcar ERROR si dispatch falla. |
| Coincidencia aproximada puede asignar registros a la empresa incorrecta. | Resolver solo coincidencia exacta normalizada; null/ambigüedad requiere Editor. |

## What is **not** in this spec

- Pantallas de carga, progreso, revisión, corrección, confirmación o retry en Next.js.
- Confirmación automática, aprobación financiera por AI o inclusión en reportes de registros no confirmados.
- Llamadas a Ollama desde navegador o servicios externos de AI.
- Historial de todas las ejecuciones ni historial detallado en `History`.
- Cambio de modelo, benchmark como gate, WhatsApp, documentos Word o reportes Excel.
