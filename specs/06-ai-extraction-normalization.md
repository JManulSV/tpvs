# SPEC 06 — Contrato y normalización de extracción AI

> **Status:** Implemented
> **Depends on:** SPEC 05
> **Date:** 2026-10-20
> **Objective:** Ajustar el contrato experimental de extracción y normalizar formatos numéricos inequívocos sin convertir la calidad semántica del modelo en un bloqueo para la integración.

## Why this spec exists

El benchmark v3 mostró que `qwen2.5vl:3b` suele devolver JSON con información aprovechable, pero falla con frecuencia en la serialización de importes y porcentajes. El hardware disponible limita la posibilidad de cambiar a un modelo más capaz. La aplicación debe exigir una estructura interpretable, convertir únicamente formatos inequívocos y dejar la revisión de valores al Editor.

## Scope

**In:**

- Refinar el prompt y el contrato experimental del runner `tools/ai-spike/run_ollama_spike.py`.
- Aplicar normalización determinista de valores monetarios y porcentajes antes de validar sus formatos canónicos.
- Separar errores estructurales del JSON de valores individuales no convertibles.
- Contar los valores numéricos no convertibles y clasificar como no utilizable una extracción con cinco o más.
- Comparar `gemma3:4b`, `qwen2.5vl:3b` y `minicpm-v:8b` con las mismas cinco imágenes representativas, dos corridas por imagen y ejecución estrictamente secuencial.
- Elegir y documentar el modelo candidato de SPEC 08 a partir de la lectura observada, movimientos, validez estructural y latencia, sin exigir un puntaje mínimo.
- Guardar un resumen comparativo de métricas y decisión; no añadir al repositorio las imágenes ni las respuestas crudas nuevas.
- Mantener las respuestas crudas de los benchmarks y las versiones previas de prompt como evidencia histórica.

**Out of scope (for future specs):**

- Implementación del normalizador de producción en Laravel, proveedor HTTP o jobs; se definen en SPEC 08.
- Cambios en `Record`, `RecordDetail`, captura manual o migraciones; se definen en SPEC 07.
- Exigir un porcentaje mínimo de exactitud o bloquear el pipeline por resultados semánticos.
- Añadir al repositorio las imágenes de proveedores o las respuestas crudas de las 30 corridas nuevas.
- UI de revisión, corrección, confirmación o carga AI.
- Interpretación de fechas no ISO o normalización por heurísticas/locales no inequívocos.

## Data model

Esta spec no modifica datos persistidos ni introduce estructuras de base de datos. El runner experimental reportará la respuesta cruda durante la ejecución, la versión normalizada, los issues y el total de valores numéricos no convertibles. El resumen comparativo versionado contendrá métricas agregadas por modelo y la decisión, no imágenes ni respuestas crudas nuevas. El pipeline de producción conservará la evidencia del procesamiento en `AIProcessing.result` según SPEC 08.

El contrato conserva los campos actuales en `docs/06_ai_design.md`: raíz `record`, `details` y `source_totals`; cada campo extraído tiene `value` y `confidence`.

## Normalization and classification contract

- El cuerpo debe ser JSON parseable, con las tres propiedades raíz obligatorias, todas las claves definidas por el contrato y un objeto `{ "value": ..., "confidence": ... }` por campo.
- `confidence` debe ser numérico y estar entre `0` y `1`. Un valor `null` requiere `confidence: 0`.
- JSON no parseable, propiedad requerida ausente, wrapper incompleto, propiedad inesperada que rompa el contrato o confidence inválido produce `ERROR` estructural, sin contar valores numéricos.
- Un `value` que sea `null` es un faltante válido. Un tipo inesperado dentro de `value` genera un issue del campo y no incrementa el contador numérico.
- Los números que vengan como texto se convierten únicamente mediante patrones inequívocos. Se aceptan decimales canónicos, importes con agrupación de miles coherente como `$1,234.56` o `1.234,56`, y porcentajes explícitos como `5%`. El símbolo monetario opcional se elimina solo cuando el resto del patrón es válido. El porcentaje conserva su unidad y se normaliza a `5.00`.
- Valores como `1,200` o `1.200`, donde el separador puede significar miles o decimales, y valores malformados como `55.963.03` no se adivinan. El campo normalizado queda nulo, se genera un issue y se conserva el valor crudo.
- El contador incluye `year`, `week`, porcentajes, importes de cada movimiento y `source_totals`. Un valor numérico no nulo que no pueda convertirse suma uno; un `null` o un tipo inesperado no suma.
- Con cinco o más valores numéricos no convertibles, la extracción se clasifica como `ERROR` no utilizable. Con cero a cuatro, se conservan los demás campos, se reportan issues y el resultado queda disponible para revisión humana.
- Las fechas solo se aceptan en formato ISO `YYYY-MM-DD` y deben ser fechas reales. Una fecha textual no ISO o imposible se conserva como issue y se normaliza a null; por sí sola no invalida la extracción.
- Confianza baja, campos textuales nulos y diferencias semánticas se reportan como issues informativos/de revisión; no se corrigen ni se ocultan.
- La cantidad y orden de movimientos deben respetar lo que el modelo devuelva y serán comparados visualmente durante la medición. La forma estructural requiere `details` con al menos un elemento.

## Controlled model comparison

- Ejecutar cinco imágenes locales en `test-images/prueba1.jpeg` … `test-images/prueba5.jpeg` con `gemma3:4b`, `qwen2.5vl:3b` y `minicpm-v:8b`.
- Realizar dos vueltas, para un total de 30 inferencias. No iniciar una inferencia hasta que termine la anterior; no ejecutar modelos o imágenes en paralelo.
- Usar el mismo prompt, esquema JSON, parámetros de generación, normalizador y versión de runtime para los tres modelos. No ajustar prompt ni configuración durante las corridas.
- Vuelta 1: procesar las cinco imágenes con `gemma3:4b`, luego las cinco con `qwen2.5vl:3b`, después las cinco con `minicpm-v:8b`.
- Vuelta 2: procesar las cinco imágenes con `minicpm-v:8b`, luego las cinco con `qwen2.5vl:3b`, después las cinco con `gemma3:4b`.
- Dentro de cada grupo de modelo, usar el mismo orden de imágenes (`prueba1` a `prueba5`) en ambas vueltas.
- Comparar en este orden: (1) cantidad de campos visibles correctamente transcritos contra las referencias corregidas; (2) cantidad y orden de movimientos; (3) validez estructural JSON después de la normalización permitida; (4) latencia.
- No se fija puntaje mínimo ni gate de exactitud. El resultado determina y documenta el candidato recomendado para SPEC 08, incluso si los tres modelos presentan errores semánticos.
- Guardar solo un resumen de resultados, métricas por modelo/imagen y la decisión. Mantener las imágenes y las respuestas crudas nuevas en el entorno local; no versionarlas. Los archivos crudos históricos v1–v3 no se sobrescriben.

## Implementation plan

1. Versionar el prompt experimental y alinear sus instrucciones con el contrato vigente: transcribir, no inferir, mantener el orden visual, usar null/confidence 0 para faltantes y separar movimientos de totales.
2. Implementar en el runner normalización de importes y porcentajes con patrones explícitos; devolver el valor normalizado y el issue sin reemplazar ni perder el texto crudo.
3. Separar la validación estructural de la validación de valores: fallar ante envoltorio JSON incompatible y aplicar el umbral de cinco valores numéricos no convertibles a nivel de toda la extracción.
4. Actualizar la salida del runner para reportar versión de prompt, validez estructural, valores normalizados, issues, contador numérico y tiempo de la solicitud; conservar códigos de salida distintos para fallo de transporte, JSON/estructura inválidos y extracción no utilizable.
5. Ejecutar casos controlados para formatos aceptados/ambiguos y fallas estructurales; verificar que el runner informe métricas comparables para cada modelo.
6. Ejecutar secuencialmente las 30 inferencias con las cinco imágenes locales, en el orden de dos vueltas definido en esta spec, y comparar las salidas con las referencias corregidas.
7. Guardar solo el resumen de métricas, observaciones de lectura/movimientos, validez estructural, latencias y modelo seleccionado; actualizar `docs/06_ai_design.md`, los documentos del spike y `docs/README.md` para que SPEC 08 use el candidato seleccionado.

## Acceptance criteria

- [x] El runner exige JSON parseable con raíz, claves y wrappers obligatorios; respuesta rota o incompleta se reporta como error estructural.
- [x] Un `null` con confianza 0 se acepta como faltante y no incrementa el contador numérico.
- [x] `5%`, `$1,234.56` y `1.234,56` se normalizan a valores decimales canónicos sin alterar la respuesta cruda.
- [x] `1,200`, `1.200` y `55.963.03` no se interpretan por heurística; se informan como numéricos no convertibles.
- [x] Fechas no ISO o imposibles se informan como faltantes con issue y no se reinterpretan.
- [x] Cuatro valores numéricos no convertibles dejan el resultado como utilizable para revisión; cinco lo clasifican como no utilizable.
- [x] Confianza fuera de rango, wrapper incompleto o propiedad requerida ausente se clasifica como error estructural con independencia del contador.
- [x] La comparación ejecuta exactamente 30 inferencias: tres modelos por cinco imágenes por dos vueltas.
- [x] Las inferencias se realizan una por una, sin concurrencia, usando prompt, esquema, parámetros, normalizador y runtime iguales para los tres candidatos.
- [x] La vuelta 1 ejecuta los modelos en orden `gemma3:4b`, `qwen2.5vl:3b`, `minicpm-v:8b`; la vuelta 2 invierte el orden a `minicpm-v:8b`, `qwen2.5vl:3b`, `gemma3:4b`.
- [x] Cada grupo de modelo procesa las imágenes en orden `prueba1.jpeg` a `prueba5.jpeg`.
- [x] El resumen compara campos visibles correctos, cantidad/orden de movimientos, validez estructural tras normalización y latencia, en ese orden de prioridad.
- [x] La decisión de modelo candidato para SPEC 08 queda documentada aun si ningún modelo es perfecto; no existe umbral de exactitud que bloquee la integración.
- [x] Solo se versiona el resumen comparativo; las imágenes y respuestas crudas nuevas no se agregan al repositorio.
- [x] La medición no bloquea el inicio de SPEC 07 ni la integración de SPEC 08 una vez elegido y documentado el candidato.
- [x] Los resultados crudos existentes de las versiones v1–v3 no se sobrescriben.

## Decisions

- **Sí:** comparar `gemma3:4b`, `qwen2.5vl:3b` y `minicpm-v:8b` bajo condiciones iguales y elegir por resultados el candidato que se usará en SPEC 08.
- **Sí:** priorizar en la selección campos legibles correctos, luego cantidad/orden de movimientos, después validez estructural tras normalización y finalmente latencia.
- **Sí:** ejecutar 30 inferencias secuenciales en dos vueltas; invertir el orden de los modelos en la segunda vuelta para reducir el sesgo de calentamiento/orden.
- **Sí:** guardar solo métricas resumidas y la decisión; no añadir nuevas imágenes o raw outputs al repositorio.
- **Sí:** normalización numérica determinista y explícita; nunca inferir el significado de separadores ambiguos.
- **Sí:** JSON y su envoltorio son obligatorios; los campos individuales pueden ser null o tener valores que requieran revisión.
- **Sí:** cinco valores numéricos no convertibles en toda la extracción determinan que el resultado no es utilizable; de cero a cuatro se conserva lo rescatable.
- **Sí:** fechas solo ISO; fecha no válida pasa a faltante/revisión, no se repara automáticamente.
- **Sí:** la comparación sirve para escoger el candidato con mejor desempeño relativo, pero no requiere un mínimo de exactitud para continuar.
- **Sí:** primero implementar y observar las reglas en el runner experimental; su equivalente de producción se define en SPEC 08.
- **No:** corregir semánticamente movimientos duplicados, totales tratados como movimientos o lecturas equivocadas mediante heurísticas.
- **No:** exigir una mejora absoluta respecto a todos los candidatos ni una mejora de hardware como precondición.

## Risks

| Risk | Mitigation |
| --- | --- |
| Un patrón de separadores puede interpretarse mal como miles o decimales. | Aceptar únicamente formas explícitas con agrupación consistente; mantener valores ambiguos como issue y conservar el raw. |
| JSON estructural válido puede contener lecturas incorrectas o filas de totales. | Reportar la comparación semántica por separado y exigir siempre revisión humana en el pipeline. |
| Las imágenes de referencia no están versionadas en el checkout. | El usuario las aportará en `test-images/prueba1.jpeg` … `prueba5.jpeg`; registrar cualquier corrida omitida si falta una muestra. |
| El orden de ejecución y calentamiento del modelo puede sesgar latencia o resultados. | Mantener ejecución serial y orden fijo por vuelta, invirtiendo el orden de los modelos en la segunda vuelta. |
| Dos modelos pueden diferir en recursos y duración, aunque la ejecución sea serial. | Registrar latencia por corrida y no iniciar la siguiente inferencia hasta que termine la actual. |

## What is **not** in this spec

- Pipeline de producción Laravel, `AIService`, Ollama HTTP, colas, persistencia, estados o retry.
- Cambios a tablas o al contrato manual de Records.
- UI, revisión o confirmación humana.
- Corrección automática de fechas, contenido, movimientos, discrepancias o errores de lectura.
- Inferencias concurrentes, prompt específico por modelo o ajustes durante la comparación.
- Guardar imágenes de proveedores o respuestas crudas de la nueva comparación en el repositorio.
- Un umbral mínimo de precisión semántica o requisito de que el candidato seleccionado sea perfecto.
