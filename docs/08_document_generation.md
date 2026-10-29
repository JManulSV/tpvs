# Document Generation

## 1. Objective

Generate operational documents on demand from the database, not from spreadsheets and not from the AI output.

Document generation is a derived output. PostgreSQL remains the source of truth.

## 2. Excel Report

The Excel file is generated on demand.

Endpoint:

```text
GET /api/documents/excel?year=2026&week=32&month=9&company_id=7
```

Rules:
- Only include `Record` rows with status `CONFIRMED`.
- Support filters for `year`, `month`, `week`, and `company_id`.
- For month filtering, use the month containing `Record.week_end_date`.
- When no company filter is provided, include all matching companies.
- PostgreSQL is the source of truth.
- The Excel is generated when requested.
- The Excel is not stored permanently as a document.

### Excel Sheets

#### 1. Summary

Grouped totals by company:

| Company | Income | Commission | Return |
| --- | ---: | ---: | ---: |

Include a grand total as well.

#### 2. Records

General information for each `Record`:

- Company
- Year
- Week
- Start date
- End date
- Affiliation
- Branch
- Delivery date from `Delivery`, when a `Delivery` exists
- Status, if needed for context

#### 3. Details

All movements for the selected records:

- Record
- Income
- Commission percentage
- Commission
- Return
- Order

This sheet makes each individual movement visible.

#### 4. Cash

Cash breakdown by `Delivery` for each included `Record`, when a Delivery exists.

Official MVP denominations:

- `1000`
- `500`
- `200`
- `100`
- `50`
- `20`
- `10`
- `5`
- `2`
- `1`
- `0.50`

Only rows with quantity greater than `0` are stored or generated.

Backend validation:

```text
Σ(denomination × quantity) = current Record return total
```

## 3. Word Delivery Document

A Word document is generated from a `Delivery` and its associated `Record`.

Endpoint:

```text
POST /api/deliveries/{delivery}/document
```

Delivery information and names are read from the persisted `Delivery`; no delivery fields are submitted again during document generation.

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
- have a cash breakdown whose total equals the current Record return total;
- have `prepared_return_amount` equal to the current Record return total.

Manual records without an image cannot generate Word until an image is attached. A Record may have at most one editable Delivery preparation in the MVP; preparing again updates it rather than creating a version.

Generation is restricted to `EDITOR`. Blocked generation returns `409 DOCUMENT_GENERATION_BLOCKED` with `details.reason`:

| Reason | Condition |
| --- | --- |
| `INVALID_RECORD_STATE` | The Record is not `CONFIRMED`. |
| `MISSING_IMAGE` | The Record has no associated Image. |
| `STALE_DELIVERY` | `prepared_return_amount` differs from the current Record return total. |
| `CASH_MISMATCH` | The denomination sum differs from the current Record return total. |
| `IMAGE_UNAVAILABLE` | The Image metadata exists but its private file cannot be read. |

Checks run in that order, inside a transaction that locks the Record and Delivery. A blocked or failed generation writes no History.

### Document Content

The document reproduces the current office format. It is built in code with PHPWord rather than from a `.docx` template.

The document includes:
- delivery date;
- fixed location text: `GUADALAJARA JALISCO`;
- delivering person's name;
- receiving person's name, when provided;
- total return;
- total return in words;
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
                                             $920.00
                                             $920.00


             ENTREGA                                  RECIBE


        ____________________                    ____________________
        SOL QUEZAL FLORES GÓMEZ                   (received_by_name)
```

### Data Mapping

| Element | Source | Format |
| --- | --- | --- |
| Heading | `Delivery.delivery_date` and the fixed text `GUADALAJARA, JALISCO.` | Spanish weekday and month, uppercase with accents: `MIÉRCOLES 08 DE JULIO DE 2026` |
| `C. {name}` | `Delivery.delivered_by_name` | Uppercase |
| Amount | Current Record return total (equal to `prepared_return_amount`) | `$9,800.00` |
| Amount in words | `MoneyToWordsService` | `NUEVE MIL OCHOCIENTOS PESOS 00/100 M.N.`, in parentheses |
| Concept | Fixed text | `uso de la terminal bancaria` |
| Week | `Record.week_start_date`, `week_end_date`, `year` | `07 DE SEPTIEMBRE AL 13 DE SEPTIEMBRE DE 2026`; when start and end years differ: `29 DE DICIEMBRE DE 2025 AL 04 DE ENERO DE 2026` |
| Image | The Record's `Image` | Centered and scaled proportionally to the usable page width; WebP is converted to a temporary PNG |
| Table | All 11 official denominations, highest first | Denomination without `$` (`1000` … `0.5`); integer quantity, `0` when absent; row total `$0.00` |
| Totals below the table | First the cash sum, then the Record return | `$920.00`, unlabeled |
| Signatures | `ENTREGA` / `RECIBE` with a signature line | `delivered_by_name` under ENTREGA; `received_by_name` or blank under RECIBE |

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

The format is `{INTEGER IN WORDS} PESOS {CC}/100 M.N.` in uppercase Spanish with accents, using integer-cent arithmetic. Examples: `UN PESO 00/100 M.N.`, `CERO PESOS 00/100 M.N.`, `MIL PESOS 00/100 M.N.`, `VEINTIÚN PESOS 00/100 M.N.`, `UN MILLÓN DE PESOS 00/100 M.N.`. The supported range is `0.00` to `9,999,999,999.99`.

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
