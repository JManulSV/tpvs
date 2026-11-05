# SPEC 14 — Ajuste de efectivo y cambio en la entrega

> **Status:** Implemented
> **Depends on:** SPEC 11, SPEC 12
> **Date:** 2026-11-03
> **Objective:** Permitir ajustar el importe de la constancia de entrega a pesos o medios pesos y documentar el cambio que devolverá el cliente sin alterar el retorno oficial persistido.

## Why this spec exists

El retorno oficial puede incluir centavos que no se entregan exactamente con las denominaciones disponibles. En la operación, el encargado ajusta únicamente esos centavos a `.00`, `.50` o al siguiente peso entero, y puede preparar efectivo adicional para que el cliente devuelva el excedente. La constancia debe dejar clara la conciliación sin reemplazar el retorno oficial almacenado.

## Scope

**In:**

- Actualizar el guardado de la preparación para aceptar efectivo hasta `$1.00` por debajo del retorno oficial o por encima de este, manteniendo el retorno oficial como `prepared_return_amount`.
- Permitir que el Editor seleccione en la pantalla de desglose uno de tres importes documentales derivados del retorno oficial: el mismo peso con centavos `.00`, el mismo peso con centavos `.50` o el siguiente peso entero `.00`.
- Añadir a la petición de generación Word el importe documental elegido y la declaración de si el cliente dará cambio; validar ambos en Laravel sin persistirlos.
- Al generar Word, permitir una diferencia de hasta `$1.00` por encima o debajo del importe documental sin cambio; exigir la declaración de cambio para excedentes mayores a `$1.00`, calcular el cambio como efectivo bruto menos importe documental y bloquear faltantes mayores a `$1.00`.
- Mostrar en Word el total bruto del desglose, el importe documental ajustado y, si aplica, el cambio que dará el cliente; convertir el importe documental ajustado a palabras.
- Actualizar pruebas backend/frontend y los contratos de negocio, API, Word y pantalla de entregas afectados.

**Out of scope (for future specs):**

- Persistir o auditar el importe ajustado, la opción de cambio o el cambio calculado en `Delivery`, `History` u otro almacenamiento.
- Agregar tablas, columnas o migraciones.
- Modificar los montos oficiales de `Record`, `RecordDetail` o los reportes.
- Capturar manualmente el importe de cambio; se deriva del efectivo bruto y el importe documental.
- Permitir un faltante superior a `$1.00` en el documento.
- Cambiar imagen, firmas, fechas, denominaciones oficiales, roles o versiones de documentos.

## Data model

Esta spec no agrega estructuras persistidas. `Delivery.prepared_return_amount` sigue representando el retorno oficial con dos decimales y se reutilizan `DeliveryCashDenomination` y `History` existentes.

La generación de Word recibe una selección efímera. Los nombres concretos del contrato son:

```json
{
  "document_return_amount": "1298.50",
  "customer_will_give_change": true
}
```

- `document_return_amount` es uno de los tres importes válidos derivados del retorno actual.
- `customer_will_give_change` indica que el cliente devolverá un excedente mayor a `$1.00`. Laravel calcula el monto; el cliente no lo envía.
- Estos valores se usan únicamente durante esa petición y no se guardan ni generan filas de History.

## API contract

La ruta sigue siendo `POST /api/deliveries/{delivery}/document`, con autorización `EDITOR` y respuesta binaria `.docx`. A diferencia del contrato anterior, la petición incluye el JSON mostrado en Data model.

Laravel valida que:

- `document_return_amount` corresponda a una de las tres opciones válidas para el retorno oficial actual, calculadas en centavos y sin números de punto flotante.
- `customer_will_give_change` sea booleano.
- La entrega no esté obsoleta: `prepared_return_amount` debe seguir coincidiendo con el retorno oficial actual. La suma de denominaciones se valida contra el importe documental, no con igualdad exacta al retorno oficial.
- Si efectivo bruto menos importe documental supera `$1.00`, `customer_will_give_change` sea `true`; si el excedente es de `$1.00` o menos, no se imprime cambio aunque se envíe `true`.
- Si el efectivo bruto está más de `$1.00` por debajo del importe documental, la generación se bloquea.

Cuando el cliente declara cambio, el documento muestra el cambio calculado como efectivo bruto menos importe documental. No se acepta un monto de cambio enviado por el cliente.

Errores de validación de forma o de ajuste no permitido responden `422`. Los bloqueos de negocio de la generación responden `409 DOCUMENT_GENERATION_BLOCKED` con una razón estable, incluyendo una razón para efectivo insuficiente y otra para excedente sin declaración de cambio. Se conservan las razones existentes de estado inválido, imagen faltante/no disponible y entrega obsoleta.

`PUT /api/records/{record}/delivery` continúa sin aceptar ni recibir `document_return_amount` o `customer_will_give_change`. Su `prepared_return_amount` se deriva del retorno oficial original.

## UI contract

- En el formulario de preparación, el Editor elige uno de los tres importes documentales disponibles, calculados desde el retorno oficial actual. La pantalla muestra claramente el importe oficial y el importe elegido para el documento.
- La pantalla muestra el total bruto de efectivo y la diferencia contra el retorno oficial. Un excedente no bloquea el guardado de la preparación; un faltante máximo de `$1.00` tampoco. Faltantes mayores que ese margen no permiten guardar.
- La elección de ajuste y el control `El cliente dará cambio` se conservan solo en estado de pantalla y se envían al pulsar `Generar documento Word`; no cambian el payload ni los datos persistidos del `PUT` de preparación.
- Si el efectivo bruto excede en más de `$1.00` el importe documental, el control `El cliente dará cambio` es obligatorio para generar. El control solo se muestra cuando se necesita; el monto de cambio se presenta como valor calculado, no como campo editable.
- El Word muestra el total bruto del efectivo, el importe ajustado y la línea de cambio cuando aplique. La conversión a palabras corresponde al importe ajustado.
- Un `409` nuevo se traduce a un mensaje específico y el usuario puede corregir la selección o el desglose. La descarga evita doble envío como en el flujo actual.
- `VIEWER` conserva el comportamiento de solo lectura y no recibe acciones de guardar o generar Word.

## Implementation plan

1. Actualizar reglas y contratos para distinguir el retorno oficial persistido, el importe documental elegido y la conciliación del efectivo bruto.
2. Implementar y probar el cálculo backend en centavos para generar las tres opciones de importe documental y determinar diferencia, cambio y razones de bloqueo.
3. Ajustar el guardado de preparación para aceptar hasta `$1.00` por debajo del retorno oficial o cualquier excedente, manteniendo el retorno derivado original; cubrir límites, estados, persistencia e historial existente.
4. Añadir y validar el payload JSON de generación Word; bajo los bloqueos existentes, comprobar retorno oficial vigente, importe documental permitido, diferencia, declaración de cambio y reglas de elegibilidad.
5. Actualizar `DeliveryDocumentBuilder` para imprimir el importe ajustado en número y palabras, el total bruto del desglose y el cambio calculado cuando corresponda; verificar el contenido del `.docx`.
6. Actualizar el formulario frontend con las tres opciones, feedback de efectivo y control condicional de cambio; actualizar el servicio de descarga para enviar los valores efímeros y presentar errores del backend.
7. Integrar los cambios con el flujo de preparación y generación, actualizar guías/checkpoint y ejecutar la aceptación funcional: casos de tolerancia y cambio, estado/imagen bloqueados, `VIEWER` y descarga Word.

## Acceptance criteria

- [x] La preparación conserva `prepared_return_amount` igual al retorno oficial, aunque el total de efectivo sea diferente.
- [x] El guardado acepta efectivo exactamente `$1.00` por debajo del retorno y cualquier excedente; rechaza faltantes mayores a `$1.00`.
- [x] El guardado y la generación comparan importes con aritmética entera en centavos.
- [x] Para un retorno oficial de `$1,298.57`, la UI ofrece exactamente `$1,298.00`, `$1,298.50` y `$1,299.00`.
- [x] El backend rechaza un `document_return_amount` que no sea una de las tres opciones calculadas para el retorno actual.
- [x] Un importe documental y efectivo bruto con diferencia de exactamente `$1.00` puede generar Word sin cambio.
- [x] Una diferencia positiva mayor a `$1.00` requiere `customer_will_give_change=true`; el Word muestra el excedente calculado.
- [x] Si el excedente es de `$1.00` o menos, el documento no muestra una línea de cambio.
- [x] Una diferencia negativa mayor a `$1.00` bloquea la generación con error de negocio estable y no produce documento ni History.
- [x] El Word muestra el importe documental ajustado en número y palabras, el total bruto de las denominaciones y el cambio cuando aplica.
- [x] Un cambio declarado no se persiste en `Delivery`, `DeliveryCashDenomination` ni `History`; la generación exitosa solo escribe el evento existente `document_generated`.
- [x] La generación mantiene las validaciones existentes de autorización, estado confirmado, imagen disponible y entrega vigente respecto al retorno oficial.
- [x] `EDITOR` puede preparar y generar según las reglas; `VIEWER` solo consulta y recibe `403` ante mutaciones directas.
- [x] Las pruebas backend, frontend, lint, TypeScript y build pasan, y se verifica el `.docx` generado con un caso de cambio.

## Decisions

- **Sí:** mantener el retorno oficial como dato persistido y fuente de vigencia. El ajuste es solo para la constancia.
- **Sí:** ofrecer exactamente tres alternativas derivadas del retorno: mismo peso `.00`, mismo peso `.50` y siguiente peso `.00`.
- **Sí:** aceptar un margen de `$1.00` arriba o abajo del objetivo documental sin cambio; un faltante mayor bloquea.
- **Sí:** cuando el excedente supera `$1.00`, el Editor declara que el cliente dará cambio y Laravel calcula el excedente.
- **Sí:** el documento muestra efectivo bruto, importe ajustado y cambio cuando corresponda para explicar la conciliación.
- **Sí:** enviar el ajuste y la declaración de cambio solo al generar Word. Evita alterar la preparación oficial y no agrega almacenamiento.
- **No:** mantener la igualdad exacta del desglose con el retorno al guardar. Impide preparar el efectivo adicional que operacionalmente se devuelve como cambio.
- **No:** aceptar el importe de cambio como entrada del cliente. Se deriva para evitar inconsistencias aritméticas.
- **No:** guardar el ajuste o el cambio en `Delivery` o `History`. Ambos son datos transitorios de generación.
- **No:** usar una tolerancia de `$5.00`; el margen acordado es `±$1.00`.

## Risks

| Risk | Mitigation |
| --- | --- |
| La preparación puede contener efectivo que difiere del retorno oficial, lo que podría confundirse con un cambio ya autorizado. | La pantalla separa total bruto e importe oficial; la autorización/importe de cambio se decide al generar Word y no se persiste como hecho histórico. |
| El retorno del registro puede cambiar entre la carga de pantalla y la generación. | Validar el retorno bajo el bloqueo existente de `Record` y `Delivery`; conservar `STALE_DELIVERY` respecto al retorno oficial. |
| Las opciones de peso y medio peso pueden producir errores de redondeo o acarreo. | Derivar opciones con centavos enteros y probar valores con centavos `.00`, `.01`, `.49`, `.50`, `.99`, incluyendo el cambio de peso. |
| El monto de cambio podría ser mayor al importe esperado si el usuario selecciona una opción distinta. | Calcular siempre efectivo bruto menos importe documental en Laravel y mostrar explícitamente ambos importes en el Word. |

## What is **not** in this spec

- Persistencia, auditoría o historial de importe ajustado y cambio del cliente.
- Modificaciones al retorno oficial, cálculos de Record o reportes.
- Cambios a denominaciones oficiales, firmas, imágenes, fechas, roles o versiones Word.
- Faltantes de más de `$1.00` o captura manual del monto de cambio.
- Tablas, columnas o migraciones nuevas.
