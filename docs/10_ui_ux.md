# UI/UX Phase 7

## 1. Objective

Design the user experience before implementation.

## 2. General Principle

The interface shows first the action the user needs to perform and then the information the user needs to consult.

The system has two technical roles:

- `EDITOR` - operations user
- `VIEWER` - manager

Both roles use the same application. Available actions change by role.

## 3. Navigation

```text
System
- Dashboard
- Records
- Companies
- Reports
```

No more modules are added in the MVP.

## 4. Dashboard

The dashboard works as a work center, not as an analytics dashboard.

### Editor

```text
Good morning, Maria                    + New

Needs review      4
Errors            1
Pending           3
Processing        2

Confirmed        128
Canceled           5

Recent activity
- Company A · Week 38 · Confirmed
- Company B · Week 38 · Needs review
```

### Viewer

```text
Summary

Confirmed records     128
Accumulated return $147,000

Recent activity
```

No charts are required for the MVP dashboard.

## 5. Records

This is the main operational screen.

```text
Records                                  + New record

Search company, operation, affiliation...

Company    Year    Month    Week    Status    Source
[All]      [2026]   [Sep]    [38]    [All]     [All]
---------------------------------------------------
Company A    38    15/09/26    $9,800      Needs review
Company B    38    15/09/26    $7,200      Confirmed
Company C    37    08/09/26    $5,500      Processing
```

Search supports:

- Company
- Operation number
- Affiliation
- Branch

Filters support:

- Company
- Year
- Month
- Week
- Status
- Source

The full row is clickable.

## 6. Review Record

This is the most important screen in the system.

### Image-based record

```text
Image                         NEEDS REVIEW

Company                       [ Company ABC ]
Year                          [ 2026 ]
Week                          [ 38 ]
Start                         [ 28/08/26 ]
End                           [ 15/09/26 ]
Affiliation                   [ 123456 ]
Branch                        [ Guadalajara ]

Movements
- Op. 10231
  Income     $10,000
  Commission   2%
  Return     $9,800

Document: $9,800
System:    $9,800  ✓

Confirm
```

### Manual record

```text
Review record

Company       [ Company ABC ]
Year          [ 2026 ]
Week          [ 38 ]
Start date    [ 28/08/26 ]
End date      [ 15/09/26 ]

Movements
- Operation   Income   Commission
  10231      $10,000   2%

Confirm
```

### AI field states

Numeric confidence values are not shown.

Shown states:

- High confidence
- Review
- Missing information
- Inconsistency

Always show:

`Information extracted automatically. Review before confirming.`

## 7. Core AI Rule

Even if AI extracts everything correctly:

- Company
- Week
- Operation
- Income
- Commission
- Return

It is never auto-confirmed.

Flow:

```text
Image
  -> AI
  -> Backend validation
  -> NEEDS_REVIEW
  -> Human review
  -> Confirm
  -> CONFIRMED
```

The AI interprets. The backend validates. The human confirms.

## 8. Prepare Delivery

Only available after `CONFIRMED`.

```text
Prepare delivery

Company ABC · Week 38

Official return         $9,800.00

Delivery data
Date       [18/09/2026]
Deliverer  [Maria Lopez]
Receiver   [Juan Perez]

Associated document
- Preview

Cash breakdown
Denomination    Quantity       Total
$1,000             5          $5,000
$500               5          $2,500
$200              10          $2,000
$100               3            $300

Total cash                 $9,800
                          Coincides

[ Generate receipt ]
```

## 9. Record Image

New decision for the MVP:

- There is no `DeliveryImage` entity.
- `Record` has `Image (0..1)`.
- The image may exist from the start or be added later.
- A manually created record can exist without an image.
- The image is required to generate the Word delivery document.
- Delivery date, deliverer, receiver, prepared amount, and cash breakdown are stored together in one `Delivery` associated with the `Record`.
- A `Record` can have at most one editable `Delivery` in the MVP. Preparing a new delivery updates the existing preparation; no versions or delivery states are introduced.
- Delivery cash denominations belong to `Delivery`, not directly to `Record`.
- If the Record return changes after preparation, the UI indicates that the Delivery is stale and allows the Editor to update it.

If a manual record has no image, the UI must block Word generation and ask the user to attach one first.

## 10. Outdated Delivery

If a record changes after a delivery was prepared:

```text
Original return     $9,800
Prepared delivery   $9,800

change

Current return      $10,000
Prepared delivery   $9,800
```

The interface shows:

`The delivery was prepared with an older amount.`

And:

`Prepare new delivery`

No delivery versioning or delivery states are added for the MVP. The stale preparation is updated in the same `Delivery`.

## 11. Companies

Simple catalog.

```text
Companies                            + New

Search company...
---------------------------------------------
Company                 Records
Company ABC             24
Company XYZ             18
Company North            7
```

Create:

```text
New company

Name
[________________________]

Cancel       Create
```

Decisions:

- Do not delete companies in the MVP.
- `EDITOR` creates; company names cannot be changed after creation.
- `VIEWER` only consults.
- AI does not create companies automatically.
- Laravel tries to associate the extracted name with an existing company.
- Company names are not modified after creation in the MVP.

## 12. Reports

With the new month decision:

```text
Reports

Year       Month          Week       Company
[2026]     [September]    [All]      [All]

[ Generate Excel ]
-----------------------------------------------
Week 38 · 28 August - 15 September

INCOME      COMMISSION      RETURN
$150,000    $3,000          $147,000

By company
Company       Records   Income   Commission   Return
Company A        8      $50,000    $1,000    $49,000
Company B        5      $40,000      $800    $39,200
Company C        3      $60,000    $1,200    $58,800

Included records
- Company A · Week 38 · $9,800 · Confirmed
```

Reports only use `CONFIRMED` records.

Never include:

- `PENDING`
- `PROCESSING`
- `NEEDS_REVIEW`
- `ERROR`
- `CANCELED`

## 13. Excel

The generated report contains:

- `Reporte_Semana_38_2026.xlsx`
- Sheet 1 - Summary
- Sheet 2 - Records
- Sheet 3 - Details
- Sheet 4 - Cash

There is no AI extraction sheet.

## 14. Role Differences

| Functionality | EDITOR | VIEWER |
| --- | --- | --- |
| Consult | Yes | Yes |
| Search/filter | Yes | Yes |
| Create record | Yes | No |
| Upload image | Yes | No |
| Review | Yes | No |
| Edit | Yes | No |
| Confirm | Yes | No |
| Cancel | Yes | No |
| Prepare delivery | Yes | No |
| Generate Word | Yes | No |
| Generate Excel | Yes | Yes |
| Create company | Yes | No |
| Change company name | No | No |

## 15. Full UX Flow

```text
SUPPLIER
  -> WhatsApp
  -> Image
  -> New record
  -> Image or Manual
  -> AI or Capture
  -> Validation
  -> Review
  -> Human
  -> CONFIRMED
  -> Prepare delivery
  -> Cash
  -> Word
  -> Reports
  -> Excel
```

## 16. Confirmed Business Decisions

These decisions are part of the MVP rules:

1. Week to month

When a week crosses two months, the report month is the month containing `week_end_date`.

2. Historical company name

Company names remain fixed in the MVP, so historical naming does not change.

3. Image for Word

The image is mandatory to generate the Word receipt.
