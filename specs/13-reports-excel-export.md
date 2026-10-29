# SPEC 13 — Reportes y exportación Excel

> **Status:** Draft
> **Depends on:** SPEC 03, SPEC 04, SPEC 11
> **Date:** 2026-10-29
> **Objective:** Consultar los totales oficiales de registros `CONFIRMED` filtrados por año, mes, semana y empresa, y exportarlos bajo demanda a un Excel con las hojas Resumen, Registros, Detalles y Efectivo.

## Why this spec exists

El roadmap 8.14 es el último módulo de salidas antes del diseño oficial (8.15). Hoy la oficina sigue armando sus totales en Excel a mano; el PRD exige que los reportes oficiales salgan directamente de PostgreSQL y solo con registros confirmados. Esta spec agrega la consulta de reportes, el `.xlsx` generado al vuelo y una pantalla mínima `/reports`, sin almacenar archivos ni agregar tablas.

## Scope

**In:**

- Agregar `phpoffice/phpspreadsheet` al backend (las extensiones `zip` y `gd` ya existen desde SPEC 12).
- Crear un servicio de consulta de reportes (`App\Domain\Reports\ReportQuery` o equivalente) que aplique los filtros y la regla de solo `CONFIRMED`, reutilizado por la pantalla y por el Excel.
- Exponer `GET /api/reports` con filtros aplicados, periodo, totales globales, desglose por empresa y registros incluidos paginados.
- Exponer `GET /api/documents/excel` con los mismos filtros, que devuelve el `.xlsx` como descarga.
- Crear `App\Domain\Documents\ReportWorkbookBuilder` que arma las hojas `Resumen`, `Registros`, `Detalles` y `Efectivo`.
- `EDITOR` y `VIEWER` pueden consultar y exportar.
- Pantalla mínima `/reports` con filtros en la URL, totales, tabla por empresa, lista paginada, botón `Generar Excel` y enlace en la navegación.
- Actualizar la documentación relacionada y el checkpoint 8.14.

**Out of scope (for future specs):**

- Diseño oficial de UI/UX de reportes; corresponde a 8.15.
- Almacenar, versionar o listar archivos Excel generados.
- Generación asíncrona o por cola del Excel.
- Hoja de extracción AI o datos de `AIProcessing`.
- Incluir registros en estados distintos de `CONFIRMED`.
- Gráficas, dashboards analíticos o comparativas entre periodos.
- Eventos de `History` por consultar o exportar.
- Exportar a CSV o PDF.
- Cambios a las reglas de registros, entregas o documentos Word.
- Tablas de totales precalculados.

## Data model

No se agregan tablas ni migraciones. Se leen `Record`, `RecordDetail`, `Company`, `Delivery` y `DeliveryCashDenomination`. Los totales se calculan en cada petición sumando `RecordDetail` con aritmética decimal; no se persisten.

### Filtros

| Parámetro | Regla | Aplica sobre |
| --- | --- | --- |
| `year` | Requerido, entero `1..9999` | `Record.year` |
| `month` | Opcional, entero `1..12` | Mes de `Record.week_end_date` |
| `week` | Opcional, entero `1..53` | `Record.week` |
| `company_id` | Opcional, entero existente en `companies` | `Record.company_id` |
| `page` | Opcional, entero `>= 1`, por defecto `1` | Solo lista de registros en pantalla |
| `per_page` | Opcional, `1..100`, por defecto `15` | Solo lista de registros en pantalla |

- Siempre se aplica `Record.status = CONFIRMED`.
- Los filtros se combinan con `AND`; `month` y `week` pueden enviarse juntos.
- Filtros inválidos devuelven `422` estándar de Laravel.
- `GET /api/documents/excel` ignora `page` y `per_page`: siempre exporta el conjunto completo filtrado.

### Orden

- Empresas: por nombre ascendente.
- Registros: empresa por nombre, `year`, `week`, `week_start_date` e `id` ascendentes.
- Movimientos: mismo orden de registro, luego `RecordDetail.order`.

### Hojas del Excel

Montos como celdas numéricas con formato moneda `$#,##0.00`; fechas como celdas de fecha `dd/mm/yyyy`. Encabezados en la primera fila de cada hoja.

**1. `Resumen`** — tabla por empresa y fila final de total general:

| Empresa | Ingreso | Comisión | Retorno |
| --- | ---: | ---: | ---: |
| … | … | … | … |
| TOTAL | Σ | Σ | Σ |

**2. `Registros`** — una fila por `Record` incluido:

| ID registro | Empresa | Año | Semana | Inicio | Fin | Movimientos | Ingreso | Comisión | Retorno | Fecha de entrega |
| --- | --- | ---: | ---: | --- | --- | ---: | ---: | ---: | ---: | --- |

`Fecha de entrega` viene de `Delivery.delivery_date`; vacía si no hay entrega. No se incluye columna de estado (todos son `CONFIRMED`).

**3. `Detalles`** — una fila por `RecordDetail` de los registros incluidos:

| ID registro | Orden | Afiliación | Sucursal | Ingreso | % Comisión | Comisión | Retorno |
| --- | ---: | --- | --- | ---: | ---: | ---: | ---: |

`% Comisión` vacío cuando la comisión se capturó como monto fijo.

**4. `Efectivo`** — una fila por `Delivery` de los registros incluidos; registros sin entrega no aparecen:

| ID registro | Empresa | Semana | Fecha de entrega | Entrega | Recibe | Importe preparado | 1000 | 500 | 200 | 100 | 50 | 20 | 10 | 5 | 2 | 1 | 0.50 | Total efectivo | Desactualizada |
| --- | --- | ---: | --- | --- | --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: | --- |

- Cada columna de denominación muestra la cantidad; `0` si no hay fila persistida.
- `Total efectivo` = Σ(valor × cantidad).
- `Desactualizada` = `Sí` cuando `prepared_return_amount` difiere del retorno actual del registro; `No` en otro caso.

Con resultado vacío se generan las cuatro hojas con encabezados; `Resumen` muestra solo la fila `TOTAL` en `0.00`.

### Nombre del archivo

| Filtros | Nombre |
| --- | --- |
| `year` | `Reporte_2026.xlsx` |
| `year` + `month` | `Reporte_2026_Mes_09.xlsx` |
| `year` + `week` (con o sin `month`) | `Reporte_Semana_38_2026.xlsx` |
| Cualquiera + `company_id` | se añade `_Empresa_{id}` antes de `.xlsx` |

No se usan nombres de empresa en el archivo.

## API contract

Ambas rutas requieren sesión Sanctum. Son de solo lectura (`GET`), no requieren CSRF y se autorizan para `EDITOR` y `VIEWER` mediante policy.

| Método | Ruta | Comportamiento | Autorización |
| --- | --- | --- | --- |
| `GET` | `/api/reports` | Totales, desglose por empresa y registros incluidos paginados. | `EDITOR` o `VIEWER` |
| `GET` | `/api/documents/excel` | Genera y devuelve el `.xlsx` con los mismos filtros. | `EDITOR` o `VIEWER` |

### `GET /api/reports?year=2026&month=9&week=38&company_id=7&page=1&per_page=15`

Respuesta `200`:

```json
{
  "data": {
    "filters": { "year": 2026, "month": 9, "week": 38, "company_id": 7 },
    "period": { "week_start_date": "2026-08-28", "week_end_date": "2026-09-15" },
    "totals": {
      "records_count": 16,
      "income_total": "150000.00",
      "commission_total": "3000.00",
      "return_total": "147000.00"
    },
    "companies": [
      {
        "company": { "id": 7, "name": "Empresa A" },
        "records_count": 8,
        "income_total": "50000.00",
        "commission_total": "1000.00",
        "return_total": "49000.00"
      }
    ],
    "records": [
      {
        "id": 42,
        "company": { "id": 7, "name": "Empresa A" },
        "year": 2026,
        "week": 38,
        "week_start_date": "2026-09-07",
        "week_end_date": "2026-09-13",
        "details_count": 2,
        "income_total": "10000.00",
        "commission_total": "200.00",
        "return_total": "9800.00",
        "delivery_date": "2026-09-15"
      }
    ]
  },
  "meta": { "current_page": 1, "per_page": 15, "total": 16, "last_page": 2 }
}
```

- Filtros no enviados aparecen como `null` en `filters`.
- `period` es la fecha mínima de inicio y máxima de fin de los registros incluidos; `null` si no hay registros.
- `totals` y `companies` siempre cubren el conjunto completo filtrado, no solo la página.
- Montos como string decimal con dos decimales; sin resultados, totales en `"0.00"`, `companies: []`, `records: []`.
- `delivery_date` es `null` cuando no hay entrega.

### `GET /api/documents/excel?year=2026&week=38`

Respuesta `200`:

- `Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet`
- `Content-Disposition: attachment; filename="Reporte_Semana_38_2026.xlsx"`
- `Cache-Control: private, no-store`
- Se escribe en un temporal y se elimina tras enviarse o ante cualquier fallo.

Errores (ambas rutas):

- `401` sin sesión; `422` filtros inválidos (incluye `year` ausente o `company_id` inexistente).
- `500` genérico y seguro si PhpSpreadsheet falla.

El BFF ya reenvía `content-type`, `content-disposition` y el cuerpo binario; no requiere cambios salvo que las pruebas demuestren lo contrario.

## UI contract

- Nueva ruta `/reports` dentro de `(app)`, con enlace `Reportes` en la navegación de `src/app/(app)/layout.tsx`, visible para ambos roles.
- Filtros `Año` (requerido, por defecto el año actual), `Mes` (Todos / enero…diciembre), `Semana` (Todas / 1…53) y `Empresa` (Todas / catálogo). Los filtros y la página viven en la URL.
- Muestra el periodo resultante, tarjetas `Ingreso`, `Comisión`, `Retorno`, la tabla `Por empresa` (Empresa, Registros, Ingreso, Comisión, Retorno) y la lista `Registros incluidos` paginada con enlace al detalle del registro.
- No calcula totales en el navegador; muestra los valores de Laravel.
- Distingue estado vacío (`No hay registros confirmados para estos filtros`) de error de carga.
- Botón `Generar Excel` para ambos roles: pide el binario (`responseType: "blob"`), toma el nombre de `Content-Disposition`, dispara la descarga y evita doble envío. Se deshabilita cuando el resultado está vacío. Un error de la API se muestra como mensaje, no como archivo.
- Código en `src/features/reports/` (`services`, `hooks`, `components`, `types.ts`), siguiendo `UI/hooks -> services -> BFF -> Laravel`.

## Implementation plan

1. Documentación: actualizar `docs/08_document_generation.md` (hojas en español, columnas, nombre de archivo, `year` obligatorio), `docs/backend/03_api_contract.md` (`GET /api/reports`, `GET /api/documents/excel`), `docs/backend/06_domain_workflows.md`, `docs/backend/08_files_and_documents.md`, `docs/04_technical_architecture.md` (reemplazar `/api/reports/weekly` por `/api/reports`) y `docs/frontend/features/reports.md`.
2. Dependencia: `composer require phpoffice/phpspreadsheet`; verificar que carga en el contenedor.
3. `IndexReportRequest` (Form Request compartido para ambas rutas) y `ReportPolicy` o habilidad equivalente para `EDITOR`/`VIEWER`.
4. `ReportQuery`: consulta base de `CONFIRMED` con filtros, totales globales, agregados por empresa, lista paginada y conjunto completo con detalles, entrega y denominaciones para el Excel. Pruebas feature de inclusión por estado, regla de mes por `week_end_date`, combinación de filtros, orden y precisión decimal.
5. `ReportController@index` y la ruta `GET /api/reports` con la forma de respuesta definida. Pruebas de `200` para ambos roles, `401`, `422`, paginación y totales del conjunto completo.
6. `ReportWorkbookBuilder`: arma las cuatro hojas con formatos numéricos y de fecha, total general, 11 columnas de denominación y bandera `Desactualizada`; devuelve la ruta temporal. Pruebas que abren el `.xlsx` y verifican hojas, encabezados, filas y valores.
7. `ReportExcelController` y la ruta `GET /api/documents/excel`: nombre de archivo según filtros, cabeceras, borrado del temporal. Pruebas feature de cabeceras, nombres, Excel vacío, ambos roles, `401`, `422`, exclusión de registros no confirmados y que no se escribe `History`.
8. Frontend: `src/features/reports/` (tipos, servicio JSON, servicio de descarga binaria, hooks con TanStack Query), página `/reports`, componentes de filtros/totales/tablas y enlace en la navegación. Pruebas Vitest del servicio, construcción de query, nombre de archivo, estado vacío y manejo de errores.
9. Ejecutar suite backend contra `tpvs_test`, Pint, Vitest, ESLint, TypeScript y build.
10. Checkpoint en navegador: con datos de prueba en varios estados, empresas y una semana que cruza meses, comparar pantalla y Excel con consultas SQL; verificar descarga como `EDITOR` y `VIEWER`; documentar en `docs/frontend/06_testing.md` y marcar 8.14 completo solo entonces.

## Acceptance criteria

- [ ] No se agregan tablas ni migraciones.
- [ ] `phpoffice/phpspreadsheet` está en `composer.json` y carga en el contenedor.
- [ ] `GET /api/reports` sin `year` devuelve `422`; con `company_id` inexistente devuelve `422`.
- [ ] Solo se incluyen registros `CONFIRMED`; registros `PENDING`, `PROCESSING`, `NEEDS_REVIEW`, `ERROR` y `CANCELED` no afectan totales, empresas, lista ni Excel.
- [ ] Un registro con `week_start_date` en agosto y `week_end_date` en septiembre aparece con `month=9` y no con `month=8`.
- [ ] `totals` y `companies` coinciden con la suma SQL de `RecordDetail` del conjunto filtrado completo, independientemente de la página.
- [ ] La lista paginada usa `per_page=15` por defecto, acepta hasta `100` y respeta el orden definido.
- [ ] `EDITOR` y `VIEWER` reciben `200` en ambas rutas; sin sesión `401`.
- [ ] `GET /api/documents/excel` devuelve el MIME de `.xlsx`, `attachment`, `Cache-Control: private, no-store` y el nombre según la tabla de nombres.
- [ ] El `.xlsx` contiene exactamente las hojas `Resumen`, `Registros`, `Detalles` y `Efectivo`, sin hoja de AI.
- [ ] `Resumen` tiene una fila por empresa y una fila `TOTAL` igual a los totales de `GET /api/reports` con los mismos filtros.
- [ ] `Registros` y `Detalles` tienen una fila por registro y por movimiento incluidos; afiliación y sucursal solo aparecen en `Detalles`.
- [ ] `Efectivo` tiene una fila por entrega, 11 columnas de denominación con `0` donde no hay fila, total de efectivo y `Desactualizada` correcta.
- [ ] Filtros sin resultados generan un Excel `200` con las cuatro hojas y totales en `0.00`.
- [ ] Montos del Excel son numéricos con formato moneda y fechas son celdas de fecha.
- [ ] No queda archivo temporal tras la respuesta y no se escribe `History`.
- [ ] `/reports` muestra filtros en la URL, periodo, totales, tabla por empresa y lista paginada para ambos roles, con estado vacío distinto del error.
- [ ] `Generar Excel` descarga el archivo a través del BFF con el nombre del servidor y se deshabilita con resultado vacío.
- [ ] La navegación incluye `Reportes`.
- [ ] Suite backend, Pint, Vitest, ESLint, TypeScript y build pasan.
- [ ] El checkpoint de navegador se documenta y 8.14 se marca completo solo entonces.

## Decisions

- **Sí:** `year` obligatorio en reportes y Excel. Evita mezclar años bajo un mismo mes y exportes de todo el histórico.
- **Sí:** `year` filtra `Record.year` y `month` filtra el mes de `week_end_date`, igual que `GET /api/records`.
- **Sí:** un solo `GET /api/reports` para pantalla; Excel separado en `GET /api/documents/excel` como indica la documentación. Se descarta `/api/reports/weekly` porque el reporte no es solo semanal.
- **No:** reutilizar `GET /api/records?status=CONFIRMED` para la lista; separaría totales y lista en dos contratos.
- **Sí:** lista paginada `15/100`; los totales siempre cubren el conjunto completo.
- **Sí:** afiliación y sucursal solo en `Detalles`, porque son campos de `RecordDetail`; la hoja `Registros` muestra totales y número de movimientos.
- **Sí:** hoja `Efectivo` en formato ancho, una fila por entrega con las 11 denominaciones, más la bandera `Desactualizada`.
- **Sí:** nombre de archivo según periodo y `_Empresa_{id}`, sin nombre de empresa.
- **Sí:** Excel vacío con `200` en lugar de bloquearlo.
- **Sí:** hojas y encabezados en español (`Resumen`, `Registros`, `Detalles`, `Efectivo`).
- **Sí:** `Resumen` solo con la tabla de la documentación y total general; sin bloque de filtros ni conteos.
- **Sí:** montos numéricos con formato moneda para que el Excel sea operable.
- **No:** eventos de `History` por exportar; el Excel no modifica datos ni pertenece a un solo registro.
- **Sí:** `phpoffice/phpspreadsheet` directo, misma familia que PHPWord. **No:** `maatwebsite/excel` (dependencia y convenciones extra) ni `openspout` (menos control de formato).
- **Sí:** generación síncrona; los volúmenes del MVP son pequeños.
- **Sí:** UI mínima en `/reports`; diseño oficial en 8.15.

## Risks

| Risk | Mitigation |
| --- | --- |
| Totales en pantalla y Excel divergen. | Ambos usan el mismo `ReportQuery`; prueba que compara `TOTAL` del Excel con `GET /api/reports`. |
| Errores de redondeo por float al sumar o escribir celdas. | Sumas en SQL `numeric` y strings decimales; conversión a número solo al escribir la celda, con pruebas de precisión. |
| Semana 53 que termina en enero del año siguiente queda fuera de `month=1` del nuevo año. | Comportamiento esperado: `year` es el año del periodo de negocio; documentarlo en `08_document_generation.md`. |
| Un año completo genera un Excel pesado o lento. | `year` obligatorio; carga anticipada de relaciones sin N+1; si crece, generación asíncrona en otra spec. |
| Archivos temporales acumulados si falla el envío. | `deleteFileAfterSend` y limpieza en `finally`. |
| El BFF altera el binario o las cabeceras. | Prueba Vitest del servicio con respuesta binaria y verificación en navegador. |

## What is **not** in this spec

- Diseño oficial de reportes (8.15).
- Almacenamiento, versiones o generación asíncrona de Excel.
- Hoja de AI, CSV, PDF o gráficas.
- Registros no confirmados en cualquier salida oficial.
- Auditoría de exportaciones en `History`.
- Cambios a registros, entregas o Word.
