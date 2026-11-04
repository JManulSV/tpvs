# Document Generation

## 1. Objective

Generate operational documents on demand from the database, not from spreadsheets and not from the AI output.

Document generation is a derived output. PostgreSQL remains the source of truth.

## 2. Excel Report

The Excel file is generated on demand from the same query that feeds the reports screen. It is implemented under SPEC 13 / phase 8.14 with `phpoffice/phpspreadsheet`; the sheet and file names are in Spanish.

Endpoint:

```text
GET /api/documents/excel?year=2026&month=9&week=38&company_id=7
```

Rules:
- Only include `Record` rows with status `CONFIRMED`.
- `year` is required. `month`, `week`, and `company_id` are optional and combine with `AND`.
- For month filtering, use the month containing `Record.week_end_date`. A week-53 record whose `week_end_date` falls in January of the next year is still reported under its business `year`, and under month `1`.
- When no company filter is provided, include all matching companies.
- The export always contains the full filtered set; `page` and `per_page` do not apply.
- `EDITOR` and `VIEWER` may export.
- PostgreSQL is the source of truth. Totals use decimal arithmetic and are converted to numbers only when a cell is written.
- The Excel is generated when requested, written to a temporary file, and deleted after it is sent. It is not stored permanently as a document.
- Exporting does not write `History`.
- An empty result still returns a workbook with the four sheets and headers; `Resumen` shows only the `TOTAL` row at `0.00`.

Order: companies by name; records by company name, `year`, `week`, `week_start_date`, and `id`; movements follow their record, then `RecordDetail.order`.

Amounts are numeric cells formatted `$#,##0.00`; dates are date cells formatted `dd/mm/yyyy`. Headers are in the first row of every sheet.

### Excel Sheets

#### 1. `Resumen`

Grouped totals by company, with a final `TOTAL` row:

| Empresa | Ingreso | Comisión | Retorno |
| --- | ---: | ---: | ---: |

The `TOTAL` row equals the `totals` returned by `GET /api/reports` for the same filters.

#### 2. `Registros`

One row per included `Record`:

| ID registro | Empresa | Año | Semana | Inicio | Fin | Movimientos | Ingreso | Comisión | Retorno | Fecha de entrega |
| --- | --- | ---: | ---: | --- | --- | ---: | ---: | ---: | ---: | --- |

`Fecha de entrega` comes from `Delivery.delivery_date` and is empty when no Delivery exists. There is no status column because every row is `CONFIRMED`. Affiliation and branch belong to movements and appear only in `Detalles`.

#### 3. `Detalles`

One row per movement of the included records:

| ID registro | Orden | Afiliación | Sucursal | Ingreso | % Comisión | Comisión | Retorno |
| --- | ---: | --- | --- | ---: | ---: | ---: | ---: |

`% Comisión` is empty when the commission was captured as a fixed amount.

#### 4. `Efectivo`

One row per `Delivery` of the included records; records without a Delivery do not appear:

| ID registro | Empresa | Semana | Fecha de entrega | Entrega | Recibe | Importe preparado | 1000 | 500 | 200 | 100 | 50 | 20 | 10 | 5 | 2 | 1 | 0.50 | Total efectivo | Desactualizada |
| --- | --- | ---: | --- | --- | --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: | --- |

Official MVP denominations: `1000`, `500`, `200`, `100`, `50`, `20`, `10`, `5`, `2`, `1`, and `0.50`.

- Each denomination column shows the quantity, and `0` when no row is persisted. Only rows with quantity greater than `0` are stored.
- `Total efectivo` is `Σ(denomination × quantity)`.
- `Desactualizada` is `Sí` when `prepared_return_amount` differs from the current Record return total, and `No` otherwise.

Backend validation when saving the Delivery preparation:

```text
Σ(denomination × quantity) >= current Record return total - $1.00
```

Any excess is accepted at preparation time. The exact reconciliation and customer-change declaration are validated against the selected document amount during Word generation.

There is no AI extraction sheet.

### File Name

| Filters | File name |
| --- | --- |
| `year` | `Reporte_2026.xlsx` |
| `year` + `month` | `Reporte_2026_Mes_09.xlsx` |
| `year` + `week` (with or without `month`) | `Reporte_Semana_38_2026.xlsx` |
| Any of the above + `company_id` | `_Empresa_{id}` is added before `.xlsx` |

Company names are never used in the file name. The response carries `Content-Disposition: attachment` and `Cache-Control: private, no-store`.

## 3. Word Delivery Document

A Word document is generated from a `Delivery` and its associated `Record`.

Endpoint:

```text
POST /api/deliveries/{delivery}/document
```

Delivery information and names are read from the persisted `Delivery`. The request also supplies the ephemeral document amount and whether the customer will give change; neither value is stored.

```json
{
  "document_return_amount": "1298.50",
  "customer_will_give_change": true
}
```

`document_return_amount` must be one of the three options derived from the current official Record return: the same whole-peso amount ending in `.00`, the same whole-peso amount ending in `.50`, or the next whole-peso amount ending in `.00`. For an official return of `$1,298.57`, the options are `$1,298.00`, `$1,298.50`, and `$1,299.00`.

### Delivery and Record Requirements

The associated `Record` must:
- exist;
- be `CONFIRMED`;
- have its details;
- have valid financial calculations;
- have an associated image.

The `Delivery` must:
- exist and be associated with the requested Record;
- have valid delivery data;
- have a cash breakdown that reconciles with the selected document amount under the rules below;
- have `prepared_return_amount` equal to the current Record return total.

Manual records without an image cannot generate Word until an image is attached. A Record may have at most one editable Delivery preparation in the MVP; preparing again updates it rather than creating a version.

Generation is restricted to `EDITOR`. Blocked generation returns `409 DOCUMENT_GENERATION_BLOCKED` with `details.reason`:

| Reason | Condition |
| --- | --- |
| `INVALID_RECORD_STATE` | The Record is not `CONFIRMED`. |
| `MISSING_IMAGE` | The Record has no associated Image. |
| `STALE_DELIVERY` | `prepared_return_amount` differs from the current Record return total. |
| `CASH_MISMATCH` | Gross cash is more than `$1.00` below the selected document amount. |
| `CHANGE_REQUIRED` | Gross cash exceeds the selected document amount by more than `$1.00`, but the request did not declare customer change. |
| `IMAGE_UNAVAILABLE` | The Image metadata exists but its private file cannot be read. |

Checks run in that order, inside a transaction that locks the Record and Delivery. Compare amounts in integer cents. Gross cash from `$1.00` below through `$1.00` above the selected document amount is accepted without a change line. If gross cash exceeds the selected document amount by more than `$1.00`, `customer_will_give_change=true` is required. Laravel calculates the change as gross cash minus the selected amount; the client never sends a change amount. A blocked or failed generation writes no History.

### Document Content

The document reproduces the current office format. It is built in code with PHPWord rather than from a `.docx` template.

The document includes:
- delivery date;
- fixed location text: `GUADALAJARA JALISCO`;
- delivering person's name;
- receiving person's name, when provided;
- selected document amount;
- selected document amount in words;
- gross cash total;
- calculated customer change when the excess exceeds `$1.00` and was declared;
- week start date;
- week end date;
- year;
- original received image;
- Delivery cash breakdown table;
- editor signature;
- receiver signature.

### Denomination Table

The table contains:

| Denomination | Quantity | Total |
| --- | ---: | ---: |
| $1,000 |  |  |
| $500 |  |  |
| $200 |  |  |
| $100 |  |  |
| $50 |  |  |
| $20 |  |  |
| $10 |  |  |
| $5 |  |  |
| $2 |  |  |
| $1 |  |  |
| $0.50 |  |  |
| Total |  |  |

`$0.50` is officially included in the model and in the document.

### Office Format (Reference Mockup)

```text
                 MIÉRCOLES 08 DE JULIO DE 2026, GUADALAJARA, JALISCO.


    Mediante la presente hago constar que recibí por parte del C. SOL QUEZAL
    FLORES GÓMEZ, la cantidad de $920.00 (NOVECIENTOS VEINTE PESOS 00/100
    M.N.) por concepto del uso de la terminal bancaria, correspondiente a la semana
    del 07 DE SEPTIEMBRE AL 13 DE SEPTIEMBRE DE 2026


                  +------------------------------------------+
                  |                                          |
                  |                 IMAGEN                   |
                  |                                          |
                  +------------------------------------------+


    Mismos que se entregan de la siguiente manera:


           +--------------------+------------+----------------+
           |    DENOMINACION    |  CANTIDAD  |     TOTAL      |
           +--------------------+------------+----------------+
           |               1000 |      0     |         $0.00  |
           |                500 |      1     |       $500.00  |
           |                200 |      2     |       $400.00  |
           |                100 |      0     |         $0.00  |
           |                 50 |      0     |         $0.00  |
           |                 20 |      0     |         $0.00  |
           |                 10 |      2     |        $20.00  |
           |                  5 |      0     |         $0.00  |
           |                  2 |      0     |         $0.00  |
           |                  1 |      0     |         $0.00  |
           |                0.5 |      0     |         $0.00  |
           +--------------------+------------+----------------+
                                      Efectivo bruto: $920.00
                                      Importe de entrega: $920.00


             ENTREGA                                  RECIBE


        ____________________                    ____________________
        SOL QUEZAL FLORES GÓMEZ                   (received_by_name)
```

### Data Mapping

| Element | Source | Format |
| --- | --- | --- |
| Heading | `Delivery.delivery_date` and the fixed text `GUADALAJARA, JALISCO.` | Spanish weekday and month, uppercase with accents: `MIÉRCOLES 08 DE JULIO DE 2026` |
| `C. {name}` | `Delivery.delivered_by_name` | Uppercase |
| Official return used for staleness | Current Record return total (equal to `prepared_return_amount`) | Persisted and not adjusted |
| Document amount | Valid `document_return_amount` selected by Editor | `$1,298.50` |
| Amount in words | `MoneyToWordsService` applied to `document_return_amount` | `MIL DOSCIENTOS NOVENTA Y OCHO PESOS 50/100 M.N.`, in parentheses |
| Concept | Fixed text | `uso de la terminal bancaria` |
| Week | `Record.week_start_date`, `week_end_date`, `year` | `07 DE SEPTIEMBRE AL 13 DE SEPTIEMBRE DE 2026`; when start and end years differ: `29 DE DICIEMBRE DE 2025 AL 04 DE ENERO DE 2026` |
| Image | The Record's `Image` | Centered and scaled proportionally to the usable page width; WebP is converted to a temporary PNG |
| Table | All 11 official denominations, highest first | Denomination without `$` (`1000` … `0.5`); integer quantity, `0` when absent; row total `$0.00` |
| Totals below the table | Gross denomination sum and selected document amount; customer change line when applicable | `$150.00` gross, `$120.00` document amount, `Cambio que dará el cliente: $30.00` |
| Signatures | `ENTREGA` / `RECIBE` with a signature line | `delivered_by_name` under ENTREGA; `received_by_name` or blank under RECIBE |

The document does not print the original official return separately. That amount remains in the database and is used to detect a stale Delivery. The table total is always gross cash; customer change is shown as a separate calculated line only when gross cash exceeds the selected document amount by more than `$1.00` and the Editor declared it. The selected document amount and gross cash should be labeled distinctly in the document rather than shown as two unlabeled totals.

## 4. Generation Rules

### Delivering Person

Required on Delivery preparation.

It is the operational name displayed in the document and is not necessarily the authenticated user's name.

### Receiver Name

Optional.

It may remain empty because it is not always known or needed.

### Image

The original image stored in `Image` is used. It is mandatory for Word generation even when the Record was created manually.

The image is not sent back to the AI during document generation.

### Return in Words

The backend generates it through a deterministic service:

`MoneyToWordsService`

AI is not used to convert money into words.

The format is `{INTEGER IN WORDS} PESOS {CC}/100 M.N.` in uppercase Spanish with accents, using integer-cent arithmetic on `document_return_amount`. Examples: `UN PESO 00/100 M.N.`, `CERO PESOS 00/100 M.N.`, `MIL PESOS 00/100 M.N.`, `VEINTIÚN PESOS 00/100 M.N.`, `UN MILLÓN DE PESOS 00/100 M.N.`. The supported range is `0.00` to `9,999,999,999.99`.

### History

Each successful generation writes one `History` row with `field_name=document_generated`, `previous_value=null`, `new_value=null`, the reason `Documento Word generado`, `record_id`, `delivery_id`, and the authenticated user.

## 5. Layout Rules

The office document is the visual reference. The layout is implemented in code by `DeliveryDocumentBuilder` with PHPWord; there is no `.docx` template in the MVP.

The dynamic data belongs to the system. A future editable template may replace the builder without changing the endpoint.

## 6. Storage Rules

The generated `.docx` file is not stored. Delivery preparation data is persisted in the `Delivery` entity; generated Word files are ephemeral outputs. The file is written to a temporary location, returned as an attachment named `entrega-registro-{record_id}-semana-{week}-{year}.docx` with `Cache-Control: private, no-store`, and deleted after it is sent.

Flow:

```text
Record CONFIRMED
  -> prepare or update Delivery
  -> POST /deliveries/{delivery}/document
  -> validations
  -> generate Word
  -> download
  -> not retained
```

Not included in the MVP:
- document versioning;
- `.docx` history;
- stored copies;
- automatic synchronization.

`Delivery` is a persisted preparation entity, not a stored generated document or a document-version entity.

## 7. Regeneration Behavior

The document represents the `Record` state at the moment of generation.

Example:

```text
Record CONFIRMED
  -> Delivery prepared
  -> generate Word from current Delivery
```

If the record is later modified:

```text
Record
  -> NEEDS_REVIEW
  -> CONFIRMED again
  -> existing Delivery is compared with current return
  -> Editor updates the Delivery if stale
  -> generate Word from current Delivery
```

The user updates the single Delivery preparation if necessary and generates the document again.

Versions are not managed in the MVP.

## 8. Final Principle

Documents are outputs.

The database remains the source of truth.
