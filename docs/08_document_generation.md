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
- Operation number
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

### Document Content

The document is based on the current office format as a reference.

The template may be adjusted if that makes automation easier.

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

## 5. Template Rules

The current document is used as a reference base.

The template may be modified if needed to simplify:
- field replacement;
- image insertion;
- table generation;
- proper formatting;
- signatures.

Conceptual placeholders:

```text
{{delivery_date}}
{{delivered_by_name}}
{{return_amount}}
{{return_amount_in_words}}
{{week_start_date}}
{{week_end_date}}
{{year}}
{{received_by_name}}
```

The visual design belongs to the template. The dynamic data belongs to the system.

## 6. Storage Rules

The generated `.docx` file is not stored. Delivery preparation data is persisted in the `Delivery` entity; generated Word files are ephemeral outputs.

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
