# SPEC 24 — Rediseño de la pantalla de Reportes

> **Status:** Approved
> **Depends on:** SPEC 13, SPEC 15, SPEC 16
> **Date:** 2026-11-19
> **Objective:** Rediseñar `/reports` con la plantilla `Reports` y los tokens de shadcn dentro del shell existente, sin cambiar el backend, el contrato de API ni las reglas de reportes.

## Why this spec exists

SPEC 13 dejó `/reports` con una UI mínima a propósito: filtros en una fila sin estilo, totales en tarjetas genéricas, tablas básicas y un botón de Excel sin contexto. El diseño oficial quedó para después (8.15 en el roadmap).

La plantilla `docs/design/templates/Reports.dc.html` cubre esta pantalla. `ReportsMobile.dc.html` es el mismo marcado a móvil. Esta spec sigue el mismo criterio de SPEC 22 y SPEC 23: un solo componente responsivo, reutilizando servicios, hooks y tipos existentes. El backend de SPEC 13 ya entrega todo lo que la pantalla necesita.

## Scope

**In:**

- `/reports` con el layout de `Reports.dc.html` en escritorio y en móvil, dentro de un solo componente responsivo.
- Encabezado «Reportes» con el subtítulo «Totales oficiales de los registros confirmados».
- Barra de filtros con el estilo de la plantilla: `Año`, `Mes`, `Semana`, `Empresa` y botón «Limpiar filtros».
- Nota bajo los filtros: «El mes se toma de la fecha de fin de la semana. Solo se incluyen registros confirmados.»
- Periodo resultante y tarjetas `Ingreso`, `Comisión` y `Retorno`. La tarjeta `Retorno` usa el estilo *hero* (`--hero` / `--on-hero`), como en la plantilla.
- Tabla «Por empresa» con fila «Total», tomada de `totals` y de `companies` de la respuesta.
- Lista «Registros incluidos» paginada con empresa, semana, fin de semana, retorno y enlace «Abrir» al detalle del registro. Sin columna de estado.
- Botón «Generar Excel» en la barra de acciones de la pantalla. Al terminar la descarga muestra «Se generó y descargó {archivo}», con el nombre que devuelve el servidor.
- Estados: cargando, sin registros (con «Limpiar filtros»), error con «Reintentar».
- Pruebas Vitest de las utilidades puras nuevas.
- Actualizar `docs/frontend/features/reports.md` y el checkpoint de navegador en `docs/frontend/06_testing.md`.

**Out of scope (for future specs):**

- Cambios de backend, API, validaciones o nombres de archivo. `GET /api/reports` y `GET /api/documents/excel` no cambian (SPEC 13).
- Permitir «Año: Todos». El año sigue siendo obligatorio.
- Columna o badge de estado «Confirmado» en la lista.
- Vista previa del nombre del archivo antes de descargar.
- Componente móvil separado (`ReportsMobile`).
- Exportar a CSV o PDF, historial de exportaciones y gráficas.
- Persistir filtros entre sesiones o recordar la última consulta.
- Cambios al shell, a la navegación o a otras pantallas.

## Data model

Esta spec no introduce estructuras de datos ni migraciones. Reutiliza `ReportFilters`, `ReportSearch`, `ReportResponse`, `ReportTotals`, `ReportCompanyRow` y `ReportRecordRow` de `frontend/src/features/reports/types.ts`, y el resultado de `reportsService.downloadExcel` (`{ blob, fileName }`).

Convenciones que no cambian:

- Los filtros viven en la URL (`parseReportSearch` / `toReportSearchParams`).
- Los importes se formatean con `formatMoney`; las fechas con `formatDate` (`src/lib/format`).
- La pantalla no calcula totales en el navegador: muestra lo que devuelve Laravel.
- Opciones de año con `yearOptions` (sin «Todos»); el año por defecto es el actual.

## Implementation plan

1. **Filtros.** Rediseñar `report-filters.tsx` con el layout de la plantilla: cuatro campos con etiqueta visible y botón «Limpiar filtros». Quitar «Todos» de `Año`. «Limpiar filtros» restablece mes, semana y empresa, y deja el año actual. Pruebas de los filtros se reflejan en la URL. Funciona con la pantalla actual.
2. **Encabezado y nota.** Ajustar el encabezado de `reports-screen.tsx` al de la plantilla y agregar la nota de «mes por fecha de fin» y «solo confirmados». Sin cambios de datos.
3. **Tarjetas de totales.** Rediseñar `ReportTotalsCards` en `report-tables.tsx` con tres tarjetas, icono y etiqueta, y `Retorno` en estilo hero. Mantiene `dl`/`dt`/`dd` para accesibilidad. Pruebas de la tarjeta hero y de los importes formateados.
4. **Tabla por empresa.** Rediseñar `CompanyBreakdown` con encabezados de la plantilla y una fila «Total» tomada de `totals`. Con una sola empresa, la fila «Total» sigue apareciendo.
5. **Lista de registros incluidos.** Rediseñar `IncludedRecords` con las columnas Empresa, Semana, Fin de semana, Retorno y «Abrir» (enlace a `/records/{id}`). Se conserva la paginación actual (15 por página). Sin columna de estado.
6. **Excel y mensaje de descarga.** Rediseñar `report-excel-action.tsx` con el botón «Generar Excel» en la barra de acciones y el mensaje «Se generó y descargó {archivo}» con `fileName` del resultado de la mutación. Se conservan la deshabilitación con resultado vacío y el manejo de errores.
7. **Estados.** Estado vacío con «No hay registros confirmados en este periodo», la línea «Prueba con otra semana, otro mes u otra empresa. Los registros pendientes o por revisar no cuentan en los reportes.» y botón «Limpiar filtros». Error con «Reintentar».
8. **Pruebas.** Vitest en `frontend/tests/reports.test.ts` para las utilidades nuevas (ver Decisions). Ejecutar `npm test`.
9. **Verificación.** Ejecutar ESLint, TypeScript y `npm run build` desde `frontend/`.
10. **Documentación y checkpoint.** Actualizar `docs/frontend/features/reports.md` con la nueva UI y registrar en `docs/frontend/06_testing.md` la verificación en navegador como `EDITOR` y `VIEWER`: pantalla contra la plantilla, Excel descargado y nombre correcto, y estado vacío.

## Acceptance criteria

- [ ] `/reports` no tiene la opción «Todos» en el filtro de año.
- [ ] Sin año seleccionado no es posible consultar; el año por defecto es el actual.
- [ ] Cambiar cualquier filtro actualiza la URL y la consulta, y mantiene los demás filtros.
- [ ] «Limpiar filtros» deja año actual, mes «Todos», semana «Todas» y empresa «Todas».
- [ ] La nota «El mes se toma de la fecha de fin de la semana. Solo se incluyen registros confirmados.» aparece en la pantalla.
- [ ] La tarjeta `Retorno` tiene estilo hero y las tres tarjetas muestran los valores de `totals`.
- [ ] «Por empresa» tiene una fila «Total» igual a `totals`.
- [ ] «Registros incluidos» no tiene columna de estado.
- [ ] «Abrir» de cada registro enlaza a `/records/{id}`.
- [ ] La lista pagina de 15 en 15 y conserva la página en la URL.
- [ ] El botón «Generar Excel» descarga el archivo y el mensaje muestra el nombre que devolvió el servidor.
- [ ] El botón «Generar Excel» está deshabilitado cuando no hay registros.
- [ ] Sin registros, la pantalla muestra «No hay registros confirmados en este periodo» y el botón «Limpiar filtros».
- [ ] Un error de carga muestra el mensaje de error y «Reintentar», y no muestra totales.
- [ ] La pantalla se usa en 390 px sin scroll horizontal de la página.
- [ ] `EDITOR` y `VIEWER` ven la misma pantalla y pueden generar el Excel.
- [ ] Las pruebas Vitest de las utilidades nuevas pasan.
- [ ] `npm test`, ESLint, TypeScript y `npm run build` pasan desde `frontend/`.
- [ ] No hay cambios en `backend/`.
- [ ] `docs/frontend/features/reports.md` describe la UI nueva.

## Decisions

- **Sí:** año obligatorio, sin «Todos». Así se mantiene el contrato de SPEC 13 (422 sin `year`) y no hay Excel de todo el histórico.
- **No:** permitir «Año: Todos» en el backend. Cambiaría la API de SPEC 13 y esta spec no toca el backend.
- **Sí:** lista sin columna de estado. Todos los registros son `CONFIRMED` y SPEC 13 no define esa columna. La plantilla la muestra, pero la omitimos a propósito.
- **No:** badge «Confirmado» por fila. Es información redundante, porque la pantalla solo muestra confirmados.
- **Sí:** un solo componente responsivo, como SPEC 22 y SPEC 23. Evita duplicar marcado y lógica entre escritorio y móvil.
- **No:** `ReportsMobile` como componente aparte. Sería más código que mantener sin beneficio.
- **Sí:** nombre del archivo del servidor, mostrado después de la descarga. Mantiene una sola fuente de verdad para la regla de nombres (SPEC 13).
- **No:** calcular el nombre en el cliente antes de descargar. Duplicaría la regla del backend solo para una vista previa.
- **Sí:** botón «Limpiar filtros». Está en la plantilla y es una acción simple.
- **Sí:** empresas del catálogo real con `useCompanies`, no los nombres de ejemplo de la plantilla.
- **Sí:** mantener la paginación de 15 y la forma de la respuesta de SPEC 13.

## Risks

| Risk | Mitigation |
| --- | --- |
| La tarjeta hero y las tres tarjetas se rompen en 390 px. | Una columna en móvil y prueba en navegador a 390 px. |
| El nombre del archivo del servidor no coincide con la plantilla («Reporte_2026.xlsx»). | El mensaje usa el nombre real del servidor. La regla de nombres no cambia. |
| Los textos de ejemplo de la plantilla se copian a producción. | La pantalla usa catálogo real y totales de Laravel. Revisión en el checkpoint. |
| Cambiar la lista al estilo de la plantilla cambia la paginación. | Se conserva la paginación de SPEC 13 y se prueba en navegador. |

## What is **not** in this spec

- Cambios de backend, API o nombres de archivo.
- «Año: Todos».
- Columna o badge de estado en la lista.
- Componente móvil separado.
- Vista previa del nombre del archivo antes de descargar.
- CSV, PDF, historial de exportaciones o gráficas.
- Persistencia de filtros entre sesiones.
