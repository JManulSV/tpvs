# SPEC 12 — Documento Word de entrega

> **Status:** Approved
> **Depends on:** SPEC 05, SPEC 10, SPEC 11
> **Date:** 2026-10-29
> **Objective:** Generar y descargar bajo demanda el documento Word de una entrega válida, con el formato de constancia de la oficina, bloqueándolo cuando falte la imagen o la entrega no sea válida.

## Why this spec exists

La fase 8.12 dejó persistida la `Delivery` y su desglose, pero la oficina sigue transcribiendo a mano la constancia de entrega en Word. Esta spec cierra ese hueco con `POST /api/deliveries/{delivery}/document`, sin almacenar documentos ni versionarlos.

## Scope

**In:**

- Agregar `phpoffice/phpword` y las extensiones PHP `zip` y `gd` a la imagen Docker del backend.
- Crear `App\Domain\Documents\MoneyToWordsService` determinista (formato fiscal MX).
- Crear `App\Domain\Documents\DeliveryDocumentBuilder` que arma el `.docx` por código replicando el formato de la oficina.
- Crear `App\Domain\Documents\DeliveryDocumentEligibility` (o equivalente) que valida estado, imagen, entrega obsoleta y cuadre de efectivo.
- Exponer `POST /api/deliveries/{delivery}/document`, solo `EDITOR`, que devuelve el binario como descarga.
- Convertir WebP a PNG temporalmente para insertarlo; JPEG/PNG se insertan tal cual. El original privado no se modifica.
- Registrar un evento `document_generated` en `History` por cada generación exitosa.
- Botón `Generar documento Word` en `/records/[recordId]/delivery` para `EDITOR`, con motivos de bloqueo y errores del backend.
- Etiqueta del evento en el panel `Historial de cambios`.
- Agregar el mockup del formato a `docs/08_document_generation.md` y actualizar la documentación relacionada y el checkpoint 8.13.

**Out of scope (for future specs):**

- Almacenar, versionar o listar documentos generados.
- Plantilla `.docx` editable con placeholders (`TemplateProcessor`).
- Exportar a PDF.
- Excel y reportes; corresponde a 8.14.
- Diseño oficial de UI/UX; corresponde a 8.15.
- Generación por `VIEWER`.
- Cambios a las reglas de preparación de entrega, imágenes o registros.
- Subir o reemplazar la imagen desde la pantalla de entrega.

## Data model

No se agregan tablas ni migraciones. Se reutilizan `Delivery`, `DeliveryCashDenomination`, `Record`, `RecordDetail`, `Image` y `History`.

### Formato del documento (referencia de la oficina)

```text
                 MIÉRCOLES 08 DE JULIO DE 2026, GUADALAJARA, JALISCO.

    Mediante la presente hago constar que recibí por parte del C. SOL QUEZAL
    FLORES GÓMEZ, la cantidad de $920.00 (NOVECIENTOS VEINTE PESOS 00/100
    M.N.) por concepto del uso de la terminal bancaria, correspondiente a la semana
    del 07 DE SEPTIEMBRE AL 13 DE SEPTIEMBRE DE 2026

                  +------------------------------------------+
                  |                 IMAGEN                   |
                  +------------------------------------------+

    Mismos que se entregan de la siguiente manera:

           +--------------------+------------+----------------+
           |    DENOMINACION    |  CANTIDAD  |     TOTAL      |
           +--------------------+------------+----------------+
           |               1000 |      0     |         $0.00  |
           |                500 |      1     |       $500.00  |
           |                200 |      2     |       $400.00  |
           |                ... |    ...     |           ...  |
           |                0.5 |      0     |         $0.00  |
           +--------------------+------------+----------------+
                                             $920.00
                                             $920.00

             ENTREGA                                  RECIBE

        ____________________                    ____________________
          SOL QUEZAL FLORES GÓMEZ                 (received_by_name)
```

Mapeo de datos:

| Elemento | Origen | Formato |
| --- | --- | --- |
| Encabezado | `Delivery.delivery_date` + texto fijo `GUADALAJARA, JALISCO.` | Día de la semana y mes en español, mayúsculas con acentos: `MIÉRCOLES 08 DE JULIO DE 2026` |
| `C. {nombre}` | `Delivery.delivered_by_name` | Mayúsculas |
| Importe | Retorno actual del `Record` (= `prepared_return_amount`) | `$9,800.00` |
| Importe en letra | `MoneyToWordsService` | `NUEVE MIL OCHOCIENTOS PESOS 00/100 M.N.` entre paréntesis |
| Concepto | Texto fijo | `uso de la terminal bancaria` |
| Semana | `Record.week_start_date`, `week_end_date`, `year` | `07 DE SEPTIEMBRE AL 13 DE SEPTIEMBRE DE 2026`; si los años de inicio y fin difieren: `29 DE DICIEMBRE DE 2025 AL 04 DE ENERO DE 2026` |
| Imagen | `Image` del `Record` | Centrada, escalada proporcionalmente al ancho útil de la página |
| Tabla | Las 11 denominaciones oficiales, de mayor a menor | Denominación sin `$` (`1000` … `0.5`); cantidad entera, `0` si no hay fila; total `$0.00` |
| Totales bajo la tabla | 1º suma del efectivo; 2º retorno del registro | `$920.00`, sin etiqueta |
| Firmas | `ENTREGA` / `RECIBE` con línea | Bajo ENTREGA `delivered_by_name`; bajo RECIBE `received_by_name` o vacío |

### `MoneyToWordsService`

- Entrada: importe decimal en string con dos decimales (aritmética en centavos, sin float).
- Salida: `{ENTEROS EN LETRA} PESOS {CC}/100 M.N.` en mayúsculas con acentos (`UN PESO 00/100 M.N.` para 1; `CERO PESOS 00/100 M.N.` para 0; `MIL` para 1000, nunca `UN MIL`; `CIENTO`, `VEINTIÚN`, `MILLÓN`/`MILLONES DE PESOS`).
- Rango: `0.00` a `9,999,999,999.99` (`decimal(12,2)`). Negativos lanzan excepción.
- No usa AI ni librerías externas.

### Evento de historial

| `field_name` | `previous_value` | `new_value` | `reason` | `delivery_id` |
| --- | --- | --- | --- | --- |
| `document_generated` | `null` | `null` | `Documento Word generado` | id de la entrega |

Se escribe una fila por generación exitosa con `record_id`, `user_id` y `created_at`. Una generación bloqueada o fallida no escribe historial.

## API contract

| Método | Ruta | Comportamiento | Autorización |
| --- | --- | --- | --- |
| `POST` | `/api/deliveries/{delivery}/document` | Valida, genera y devuelve el `.docx`. Sin cuerpo de petición. | `EDITOR` |

Respuesta exitosa `200`:

- `Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document`
- `Content-Disposition: attachment; filename="entrega-registro-{record_id}-semana-{week}-{year}.docx"`
- `Cache-Control: private, no-store`
- El archivo se genera en un temporal y se elimina tras enviarse.

Orden de validación (dentro de una transacción con bloqueo de `Record` y `Delivery`):

1. `Record.status` es `CONFIRMED`; si no, `409 DOCUMENT_GENERATION_BLOCKED` con `details.reason=INVALID_RECORD_STATE`.
2. El registro tiene `Image`; si no, `reason=MISSING_IMAGE`.
3. `prepared_return_amount` coincide con el retorno actual; si no, `reason=STALE_DELIVERY` con `prepared_amount` y `current_amount`.
4. La suma de denominaciones coincide con el retorno actual en centavos; si no, `reason=CASH_MISMATCH` con `expected_amount`, `cash_amount` y `difference`.
5. El archivo de imagen existe y es legible en el disco privado; si no, `reason=IMAGE_UNAVAILABLE` (sin rutas en la respuesta; se registra en log).

Errores:

- `401` sin sesión; `403` `VIEWER`; `404` entrega inexistente; `419` CSRF inválido.
- `409 DOCUMENT_GENERATION_BLOCKED` con las razones anteriores.
- `500` genérico y seguro si PHPWord falla; no se escribe historial.

El BFF ya reenvía `content-type`, `content-disposition` y el cuerpo binario; no requiere cambios salvo que las pruebas demuestren lo contrario.

## UI contract

- La pantalla `/records/[recordId]/delivery` muestra `Generar documento Word` solo para `EDITOR`.
- El botón se deshabilita con un motivo visible cuando no existe entrega, `is_stale=true`, el registro no está `CONFIRMED` o el registro no tiene imagen (`Adjunta una imagen al registro para generar el documento`).
- Al pulsarlo, el servicio pide el binario (`responseType: "blob"`), toma el nombre de `Content-Disposition` y dispara la descarga.
- Evita doble envío mientras genera.
- Un `409` muestra el motivo traducido; `STALE_DELIVERY`/`INVALID_RECORD_STATE` refrescan registro y entrega.
- Tras éxito, invalida el historial del registro.
- `RecordHistoryPanel` muestra `document_generated` como `Documento Word generado`.

## Implementation plan

1. Documentación: agregar el mockup y el mapeo a `docs/08_document_generation.md`; actualizar `docs/backend/03_api_contract.md`, `docs/backend/08_files_and_documents.md`, `docs/backend/06_domain_workflows.md`, `docs/02_business_rules_and_states.md`, `docs/frontend/features/deliveries.md` y `docs/backend/10_configuration_operations.md` (extensiones PHP).
2. Infraestructura: añadir `zip` y `gd` (con soporte WebP/JPEG/PNG) al `backend/Dockerfile`; `composer require phpoffice/phpword`; reconstruir y verificar `php -m`.
3. `MoneyToWordsService` con pruebas unitarias (0, 1, 21, 100, 101, 1000, 1001, 21,000, 1,000,000, 2,500,000.50, 920.17, máximo, negativo).
4. Formateador de fechas en español (día de semana, mes, rango de semana con años distintos) con pruebas unitarias.
5. `DeliveryDocumentBuilder`: arma el documento (encabezado, párrafo, imagen, tabla de 11 filas, dos totales, firmas) y devuelve la ruta temporal; conversión WebP→PNG temporal. Pruebas que abren el `.docx` y verifican texto, filas e imagen.
6. Validación de elegibilidad con las cinco razones; pruebas unitarias.
7. `RecordPolicy::generateDeliveryDocument` (solo `EDITOR`), `DeliveryDocumentController` y la ruta `POST /api/deliveries/{delivery}/document`.
8. Evento `document_generated` en `History` dentro de la transacción, solo tras construir el archivo con éxito.
9. Pruebas feature: éxito con JPEG/PNG/WebP, cabeceras, borrado del temporal, `403`/`401`/`404`, cada razón `409`, sin historial en fallos, historial en éxito, registro sin modificar.
10. Frontend: servicio de descarga binaria, hook de mutación, botón con motivos de bloqueo en `delivery-screen.tsx`, etiqueta en `record-history-panel.tsx`; pruebas Vitest del servicio, nombre de archivo y mensajes `409`.
11. Ejecutar suite backend contra `tpvs_test`, Pint, Vitest, ESLint, TypeScript y build.
12. Checkpoint en navegador: descargar el Word de una entrega válida y abrirlo; comprobar bloqueo sin imagen, con entrega obsoleta y para `VIEWER`; documentar en `docs/frontend/06_testing.md` y marcar 8.13 completo solo entonces.

## Acceptance criteria

- [ ] No se agregan tablas ni migraciones.
- [ ] La imagen del backend incluye `zip` y `gd`, y `phpoffice/phpword` está en `composer.json`.
- [ ] `MoneyToWordsService` devuelve `NOVECIENTOS VEINTE PESOS 00/100 M.N.` para `920.00` y pasa todos los casos unitarios.
- [ ] `POST /api/deliveries/{delivery}/document` devuelve `200` con el tipo MIME de Word, `attachment` y el nombre `entrega-registro-{id}-semana-{week}-{year}.docx`.
- [ ] El `.docx` contiene encabezado con día y fecha en español, nombre del entregador, importe, importe en letra, concepto fijo, rango de semana, imagen, tabla de 11 filas, dos totales y nombres de firma.
- [ ] Una imagen WebP se inserta convertida a PNG y el archivo original no cambia.
- [ ] `VIEWER` recibe `403`; sin sesión `401`; entrega inexistente `404`.
- [ ] Registro no confirmado, sin imagen, entrega obsoleta, efectivo descuadrado e imagen ilegible devuelven `409 DOCUMENT_GENERATION_BLOCKED` con la razón correcta.
- [ ] Una generación exitosa escribe exactamente un evento `document_generated` con `delivery_id`; un bloqueo o fallo no escribe historial.
- [ ] Generar no modifica `Record`, `Delivery`, denominaciones ni `Image`.
- [ ] No queda archivo temporal tras la respuesta.
- [ ] El botón aparece solo para `EDITOR`, se deshabilita con motivo en los casos bloqueados y descarga el archivo a través del BFF.
- [ ] El panel de historial muestra `Documento Word generado`.
- [ ] `docs/08_document_generation.md` incluye el mockup del formato.
- [ ] Suite backend, Pint, Vitest, ESLint, TypeScript y build pasan.
- [ ] El checkpoint de navegador se documenta y 8.13 se marca completo solo entonces.

## Decisions

- **Sí:** documento construido por código con PHPWord. No hay `.docx` original; evita binarios no revisables y facilita pruebas.
- **No:** plantilla `.docx` con `TemplateProcessor`. Clonar filas e insertar imagen es más frágil; se puede migrar sin cambiar el endpoint.
- **Sí:** `phpoffice/phpword` con extensiones `zip` y `gd`.
- **Sí:** WebP convertido a PNG solo para el documento; JPEG/PNG sin cambios.
- **Sí:** importe en letra fiscal MX con centavos reales (`00/100 M.N.`). El `17/100` del formato de ejemplo se trató como error de captura.
- **Sí:** día de la semana y mes con acentos (`MIÉRCOLES`, `SÁBADO`).
- **Sí:** tabla con las 11 denominaciones, incluidas las de cantidad `0`, como el formato de la oficina.
- **Sí:** dos totales sin etiqueta bajo la tabla: efectivo y retorno.
- **Sí:** nombres bajo las líneas de firma; receptor vacío si no se capturó.
- **Sí:** solo `EDITOR` genera, según la matriz de roles.
- **Sí:** evento `document_generated` en `History` por generación exitosa.
- **Sí:** entrega obsoleta bloquea la generación (`STALE_DELIVERY`) en lugar de usar un importe antiguo.
- **Sí:** botón mínimo en la pantalla de entrega; diseño oficial en 8.15.
- **No:** almacenar, versionar o reenviar documentos generados.
- **No:** usar AI para el importe en letra.

## Risks

| Risk | Mitigation |
| --- | --- |
| Word antiguo no muestra WebP. | Conversión a PNG temporal con GD. |
| Imagen muy grande desborda la página o el documento pesa demasiado. | Escalar proporcionalmente al ancho útil; límite de 10 MiB ya existente. |
| Errores en la conversión a letra (`VEINTIÚN`, `CIENTO`, millones). | Pruebas unitarias con casos borde y aritmética en centavos. |
| El retorno cambia entre la consulta y la generación. | Bloqueo de `Record`/`Delivery` y revalidación dentro de la transacción. |
| Archivos temporales acumulados si falla el envío. | `deleteFileAfterSend` y limpieza en `finally` ante excepciones. |
| El BFF altera el binario o las cabeceras. | Prueba Vitest del proxy con respuesta binaria y verificación en navegador. |
| Falta de `zip`/`gd` en otro entorno. | Documentar las extensiones en `10_configuration_operations.md`. |

## What is **not** in this spec

- Almacenamiento, versiones o historial de archivos `.docx`.
- Plantilla editable en Word o exportación a PDF.
- Generación por `VIEWER`.
- Excel y reportes (8.14).
- Diseño oficial de UI/UX (8.15).
- Cambios a preparación de entrega, imágenes o registros.
