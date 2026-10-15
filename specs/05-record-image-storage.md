# SPEC 05 — Almacenamiento de imágenes de registros

> **Status:** Approved
> **Depends on:** SPEC 04
> **Date:** 2026-10-15
> **Objective:** Implementar en Laravel la carga, reemplazo, almacenamiento privado y consulta autorizada de la imagen asociada a un registro.

## Scope

**In:**

- Implementar `POST /api/records/{record}/image` para que `EDITOR` adjunte la primera imagen o reemplace la imagen activa.
- Implementar `GET /api/records/{record}/image` para que `EDITOR` y `VIEWER` autenticados consulten los bytes de la imagen asociada.
- Agregar al recurso de Record la metadata segura de la imagen: `id`, `original_file_name`, `mime_type`, `file_size` y `created_at`; devolver `null` cuando el registro no tenga imagen.
- Aceptar imágenes JPEG, PNG y WebP de hasta 10 MiB. Validar el contenido detectado y no confiar únicamente en el MIME ni en la extensión declarados por el cliente.
- Guardar imágenes en un disco Laravel local privado dedicado, separado del disco público y de la selección general de `FILESYSTEM_DISK`.
- Generar una clave de almacenamiento UUID con extensión derivada del MIME detectado. Sanitizar el nombre original y conservarlo solo como metadata, sin usarlo como ruta.
- Permitir adjuntar y reemplazar imágenes en cualquier Record existente excepto `CANCELED`.
- Bloquear reemplazos cuando la Image activa tenga uno o más `AIProcessing`, respondiendo `409 IMAGE_REPLACEMENT_BLOCKED`, para preservar la trazabilidad de procesamiento.
- Hacer que el archivo nuevo quede activo solo después de guardar correctamente su metadata. Si falla la base de datos, eliminar el archivo nuevo. Eliminar el archivo anterior después del commit; si falla esa limpieza, conservar la imagen nueva activa y registrar el fallo para diagnóstico/limpieza operativa.
- Alinear los límites PHP del runtime con el límite de aplicación: `upload_max_filesize=10M` y `post_max_size=12M`.
- Proteger las rutas con sesión Sanctum, CSRF en la mutación, y autorización de Record: solo `EDITOR` muta; ambos roles autenticados pueden leer.
- Mantener esta fase independiente de AI: el upload no crea `AIProcessing`, no crea jobs y no inicia procesamiento.
- Documentar el contrato, configuración y manejo privado de archivos; cubrir éxito, validación, autorización, persistencia, fallos de almacenamiento y regresiones con pruebas backend.

**Out of scope (for future specs):**

- Formularios, visualización, carga de archivos o cambios BFF en Next.js; la interfaz de usuario corresponde a la fase 8.15.
- Crear Records a partir de una imagen, procesar imágenes con AI, crear `AIProcessing`, encolar jobs o reintentar procesamiento; corresponde a las fases 8.8–8.9.
- Reemplazar una imagen que ya tenga intentos `AIProcessing`; la estrategia que preserve la trazabilidad se definirá con el pipeline AI.
- Versionar, archivar o conservar imágenes previas como historial de versiones. Solo existe una imagen activa por Record.
- Guardar `History` detallado por campo para carga o reemplazo; la auditoría ampliada corresponde a 8.11.
- Almacenamiento público, URLs firmadas, enlaces anónimos, S3 u otros proveedores remotos.
- Transformación, recorte, normalización, miniaturas, OCR, antivirus externo o modificación de los bytes originales.
- Permitir consulta o modificación de imágenes asociadas a Records `CANCELED`.

## Data model

Esta spec no introduce entidades ni columnas. Reutiliza `Image`, `Record`, la relación uno-a-uno existente y el vínculo `Image 1:N AIProcessing`. No se requiere una migración para el contrato acordado.

El objeto de imagen expuesto por el recurso Record es:

```json
{
  "id": 12,
  "original_file_name": "documento.jpg",
  "mime_type": "image/jpeg",
  "file_size": 184320,
  "created_at": "2026-10-15T12:00:00.000000Z"
}
```

La respuesta de Record incluye `image: null` cuando no hay imagen. Nunca incluye `file_path`, clave física, raíz del disco ni URL pública.

Los bytes se almacenan en un disco dedicado con raíz dentro del almacenamiento privado administrado por Laravel, por ejemplo `storage/app/private/record-images`. `Image.file_path` contiene una clave relativa generada por el servidor. El nombre recibido se reduce a un nombre base, se eliminan separadores de ruta y caracteres de control, se limita a 255 caracteres y se valida que no quede vacío. La clave física usa UUID y extensión determinada por el MIME detectado.

## API contract

Todas las rutas requieren sesión Sanctum válida. La ruta POST requiere CSRF cuando se llama mediante la sesión SPA.

| Método | Ruta | Autorización | Éxito |
| --- | --- | --- | --- |
| `POST` | `/api/records/{record}/image` | `EDITOR` | `201` al adjuntar por primera vez; `200` al reemplazar. Devuelve `{ "data": { ...image metadata... } }`. |
| `GET` | `/api/records/{record}/image` | `EDITOR` o `VIEWER` | `200` con bytes, MIME detectado, `Content-Disposition: inline` y `Cache-Control: private, no-store`. |

El POST recibe `multipart/form-data` con un único campo requerido `image`. Los límites aceptados son JPEG, PNG o WebP detectados en el contenido, con tamaño entre 1 byte y 10 MiB inclusive. No se aceptan campos que permitan indicar `record_id`, `file_path`, `mime_type` o `file_size`; esos valores se derivan del recurso, archivo recibido y almacenamiento.

El GET verifica la existencia del Record, estado no cancelado, asociación y existencia/legibilidad del archivo. No devuelve una ruta física ni redirige a almacenamiento público. El `Content-Type` procede del contenido detectado y la respuesta evita caché compartida. La conducta de rangos HTTP no forma parte de este contrato.

### Errores

- `401` si no hay sesión autenticada.
- `403` si `VIEWER` intenta cargar o reemplazar una imagen.
- `404` si el Record no existe, o si un Record no cancelado no tiene una imagen activa para consultar.
- `409` con `code=IMAGE_UPLOAD_BLOCKED` y `details.reason=RECORD_CANCELED` si se intenta cargar o reemplazar en un Record cancelado.
- `409` con `code=IMAGE_ACCESS_BLOCKED` y `details.reason=RECORD_CANCELED` si se intenta consultar una imagen de un Record cancelado.
- `409` con `code=IMAGE_REPLACEMENT_BLOCKED` y una razón estable cuando la imagen activa ya tenga `AIProcessing` asociados.
- `422` con la estructura estándar Laravel para archivo ausente, MIME/contenido no admitido, archivo vacío, tamaño excedido o metadata de nombre no válida.
- `500` con respuesta genérica y sin rutas internas si una operación de almacenamiento falla antes de completar la asociación. Los errores no deben exponer excepciones, claves ni rutas físicas.

El recurso Record expone metadata `image` segura tanto en consulta individual como en la respuesta de creación manual, donde será `null`; no requiere que el cliente acceda a la tabla `images`.

## Consistencia y reemplazo

El filesystem y PostgreSQL no comparten una transacción distribuida. El flujo debe preservar el archivo activo ante fallos parciales:

1. Autorizar y validar el Record y el upload.
2. Guardar el nuevo archivo bajo una clave aleatoria privada.
3. Iniciar transacción, bloquear el Record para serializar adjuntos concurrentes y comprobar nuevamente su estado y la existencia de intentos `AIProcessing` en la Image activa.
4. Crear o actualizar la metadata `Image` para que apunte a la nueva clave. El cambio y la asociación del Record se confirman juntos en PostgreSQL.
5. Si la escritura en base de datos falla, eliminar el nuevo archivo. La Image anterior permanece activa.
6. Después del commit, borrar el archivo previo cuando exista y el reemplazo haya sido permitido. Si la eliminación falla, mantener la asociación nueva y registrar el fallo sin devolver una respuesta que sugiera que el commit de base de datos se revirtió.

La primera carga no cambia `Record.source` ni `Record.status`, no agrega History y no crea `AIProcessing`. La imagen no puede reemplazarse tras un intento AI porque los intentos históricos están asociados a la fila `Image`; reusar o eliminar esa fila podría atribuir resultados previos a otros bytes o borrar evidencia mediante la FK existente.

## Implementation plan

1. Actualizar la guía de archivos privados, contrato API, autorización, seguridad y operaciones para fijar almacenamiento dedicado, límites, rutas, errores, persistencia y comportamiento del runtime PHP. Mantener 8.7 como backend-only y no adelantar el checkpoint.
2. Configurar el disco local dedicado y sus directorios privados, verificando que no tenga URL pública ni enlace bajo `public/storage`. Añadir la configuración PHP de upload al runtime de Docker y documentar el equivalente requerido para ejecución directa en host.
3. Implementar validación del upload multipart, detección de MIME/contenido, tamaño de 10 MiB, sanitización de nombre y nombre aleatorio de almacenamiento. Cubrir los formatos permitidos y rechazados con pruebas sin depender de servicios externos.
4. Implementar autorización específica de imagen basada en `RecordPolicy`: lectura autenticada para ambos roles y mutación exclusiva de `EDITOR`. Añadir las rutas POST/GET bajo middleware Sanctum.
5. Implementar el flujo de carga y reemplazo con almacenamiento previo, bloqueo transaccional del Record, protección frente a intentos `AIProcessing`, cleanup compensatorio y eliminación post-commit del archivo anterior. Cubrir concurrencia relevante, rollback de metadata y fallos del filesystem.
6. Implementar la respuesta protegida de bytes y agregar metadata segura `image` a `RecordResource`. Cubrir ausencia, MIME y headers, no exposición de rutas y respuestas de Record con/sin imagen.
7. Actualizar documentación operativa para persistencia/backup del volumen privado, límites reales de PHP y verificación API. Ejecutar la suite backend y demostrar el checkpoint por HTTP con `EDITOR` y `VIEWER`; marcar 8.7 completo solo cuando pase.

## Acceptance criteria

- [ ] `POST /api/records/{record}/image` acepta un archivo multipart en `image`; primera asociación devuelve `201` con metadata y reemplazo permitido devuelve `200`.
- [ ] Solo `EDITOR` puede adjuntar o reemplazar; `VIEWER` recibe `403` aun cuando invoque directamente la API.
- [ ] `EDITOR` y `VIEWER` autenticados pueden consultar los bytes de una imagen de un Record no cancelado.
- [ ] Una petición anónima recibe `401`; las mutaciones stateful con CSRF inválido conservan la protección Sanctum y reciben `419`.
- [ ] El contenido detectado debe ser JPEG, PNG o WebP; una extensión o `Content-Type` declarados que no correspondan al contenido no permiten eludir la validación.
- [ ] Se acepta un archivo no vacío de hasta 10 MiB inclusive; archivos vacíos, mayores o de formatos no permitidos reciben `422` y no crean filas ni archivos activos.
- [ ] El límite efectivo de PHP permite recibir el archivo acordado: `upload_max_filesize=10M`, `post_max_size=12M`; un exceso rechazado antes de Laravel no deja persistencia parcial.
- [ ] El nombre original persistido es un nombre base sanitizado de hasta 255 caracteres; traversal, separadores y caracteres de control no afectan la ubicación de almacenamiento.
- [ ] La clave guardada por el servidor es aleatoria/UUID, con extensión derivada del MIME detectado, y reside únicamente en el disco local privado dedicado.
- [ ] La imagen no puede recuperarse mediante una ruta pública ni por un endpoint sin autenticación; el GET no expone rutas ni claves internas.
- [ ] La respuesta GET tiene `Content-Type` basado en el contenido, `Content-Disposition: inline` y `Cache-Control: private, no-store`.
- [ ] `RecordResource` contiene `image: null` cuando no hay imagen y metadata `id`, `original_file_name`, `mime_type`, `file_size`, `created_at` cuando sí la hay; nunca contiene `file_path`.
- [ ] GET sin imagen en un Record no cancelado devuelve `404`.
- [ ] Upload/reemplazo en `CANCELED` devuelve `409 IMAGE_UPLOAD_BLOCKED`; GET de imagen en `CANCELED` devuelve `409 IMAGE_ACCESS_BLOCKED`; ambos incluyen `details.reason=RECORD_CANCELED`.
- [ ] El reemplazo sin intentos AI mantiene como máximo una fila `Image` por Record, activa el archivo nuevo tras commit y elimina el archivo anterior después del commit.
- [ ] Si falla la transacción al asociar el archivo nuevo, la metadata anterior permanece intacta y el archivo nuevo se elimina; ningún fallo revela rutas internas.
- [ ] Si falla la eliminación post-commit del archivo anterior, la referencia nueva sigue activa, la operación queda diagnosticada para limpieza y no se simula un rollback de PostgreSQL.
- [ ] Si la imagen tiene al menos un `AIProcessing`, el reemplazo devuelve `409 IMAGE_REPLACEMENT_BLOCKED`, conserva la imagen y sus intentos y no altera archivos ni metadata.
- [ ] Adjuntar una imagen no cambia `Record.source` o `Record.status`, no crea filas `AIProcessing`, no despacha jobs y no agrega History.
- [ ] La configuración y los directorios de almacenamiento sobreviven al reinicio de contenedores usando el volumen persistente ya montado para `backend/storage`.
- [ ] Las pruebas feature cubren éxito, ambos roles, anónimo, CSRF, validación, ausencia, registros cancelados, reemplazo, intentos AI, integridad DB/filesystem y no exposición de rutas.
- [ ] La suite backend pasa y el checkpoint documentado demuestra que `EDITOR` adjunta una imagen a un Record y ambos roles autorizados pueden verla mediante Laravel.
- [ ] Los documentos de backend y el roadmap reflejan la implementación verificada; la fase 8.7 no se marca completa antes del checkpoint.

## Decisions

- **Sí:** el spec es solo backend Laravel. La UI de captura/visualización pertenece a la fase 8.15; el BFF existente se integra posteriormente al consumir el contrato.
- **Sí:** reusar `Image`, `Record`, la restricción única por `record_id` y relaciones existentes; no introducir tablas ni columnas para 8.7.
- **Sí:** rutas anidadas en Record: `POST /api/records/{record}/image` y `GET /api/records/{record}/image`. La asociación y autorización se resuelven desde el Record.
- **Sí:** `EDITOR` escribe; `EDITOR` y `VIEWER` leen imágenes de Records no cancelados, de acuerdo con las reglas de rol ya establecidas.
- **Sí:** el recurso Record informa metadata segura de imagen o `null`; jamás expone `file_path`.
- **Sí:** JPEG, PNG y WebP; máximo de 10 MiB y validación del contenido real además de las cabeceras/extensión del cliente.
- **Sí:** disco local privado dedicado independiente de `FILESYSTEM_DISK` y del disco público; almacenamiento bajo raíz privada de Laravel.
- **Sí:** usar UUID y extensión derivada del contenido para la clave almacenada; conservar el nombre del usuario solo tras sanitizarlo como metadata.
- **Sí:** códigos HTTP `201` para primera asociación, `200` para reemplazo, `404` si no existe imagen y `409` para estados/condiciones que bloquean la acción.
- **Sí:** Records `CANCELED` no permiten carga, reemplazo ni lectura de imagen; responder con códigos estables de dominio.
- **Sí:** bloquear reemplazo cuando la Image tenga intentos AI. `AIProcessing.image_id` y su FK hacen que borrar o mutar la Image pueda destruir trazabilidad o cambiar a qué contenido parecen corresponder intentos anteriores.
- **Sí:** escritura del archivo antes de la transacción, cambio de metadata con bloqueo del Record, cleanup compensatorio si falla la DB y limpieza del archivo antiguo solo después del commit.
- **Sí:** si falla la limpieza post-commit, la nueva imagen continúa activa y el incidente se registra; el filesystem no permite prometer rollback atómico conjunto con PostgreSQL.
- **No:** generar AI, crear intentos, jobs, estados o History en el endpoint de imagen. El procesamiento se implementa en 8.9 y la auditoría ampliada en 8.11.
- **No:** conservar versiones anteriores. Un reemplazo permitido mantiene una sola imagen activa y elimina el archivo anterior tras el commit.
- **No:** almacenamiento público, acceso anónimo, URLs externas, S3, edición de bytes o UI/BFF dentro de este spec.
- **Sí:** alinear los límites PHP a `10M`/`12M` para que el máximo de aplicación también sea alcanzable en el runtime.

## Risks

| Riesgo | Mitigación |
| --- | --- |
| El MIME o la extensión pueden falsificarse. | Detectar el MIME desde los bytes y comprobar que pertenezca a los tipos permitidos; derivar de esa detección la extensión y el `Content-Type` servido. |
| Fallos parciales entre filesystem y PostgreSQL pueden dejar archivos huérfanos o romper la referencia activa. | Guardar primero con clave aleatoria, asociar dentro de una transacción, eliminar el nuevo archivo ante rollback y eliminar el anterior solo tras commit; diagnosticar fallos de cleanup. |
| La configuración pública o una respuesta cacheada puede filtrar imágenes privadas. | Usar un disco privado dedicado, no crear symlinks públicos, servir solo mediante Laravel autorizado y aplicar `private, no-store`. |
| Reemplazar una imagen con intentos AI puede invalidar la relación histórica entre el intento y los bytes analizados. | Rechazar reemplazo si existen `AIProcessing`; definir una política compatible con trazabilidad en la fase 8.9. |
| PHP puede rechazar la carga antes de que se ejecute la validación Laravel. | Configurar y verificar los límites runtime `upload_max_filesize=10M` y `post_max_size=12M`; verificar también la respuesta cuando el transporte rechace un cuerpo excesivo. |
| Dos cargas concurrentes pueden dejar metadata y archivos incoherentes. | Bloquear la fila Record al decidir y persistir la asociación, repetir bajo lock las comprobaciones de estado/AI y limpiar cualquier archivo nuevo que pierda la carrera o falle. |

## What is **not** in this spec

- Pantallas, formularios, preview o carga desde Next.js; implementación o modificaciones del BFF.
- Creación de Records mediante imagen, OCR, extracción AI, `AIProcessing`, jobs, reintentos o revisión humana.
- Reemplazo de imágenes que ya tengan intentos `AIProcessing`.
- Historial de versiones, retención de originales reemplazados o auditoría detallada en `History`.
- Acceso público, URLs compartibles, URLs firmadas, almacenamiento en S3 u otros proveedores remotos.
- Imágenes para Records cancelados.
- Transformación, compresión, miniaturas, OCR o análisis de malware externo.
- Cambios de estado o cálculos de negocio como consecuencia del upload.
