import type { ManualRecordFormValues } from "../schemas/record.schema";
import type { RecordDetailInput, RecordDetailView } from "../types";

type MovementValues = ManualRecordFormValues["details"][number];

export function emptyMovement(): MovementValues {
  return { affiliation: "", bank: "", income: "" };
}

/** Form values for a record being edited, or for a new record with one empty movement. */
export function initialManualRecordValues(record?: RecordDetailView): ManualRecordFormValues {
  return {
    company_id: record?.company?.id ?? 0,
    year: record?.year ?? new Date().getFullYear(),
    // An empty input until the user types a week; the schema rejects it on submit.
    week: record?.week ?? ("" as unknown as number),
    week_start_date: record?.week_start_date ?? "",
    week_end_date: record?.week_end_date ?? "",
    // Empty on a provisional IMAGE record whose percentage was not read; the schema requires it.
    commission_percentage: record?.commission_percentage ?? "",
    details: record?.details.length
      ? record.details.map((detail) => ({
          affiliation: detail.affiliation ?? "",
          bank: detail.bank ?? "",
          income: detail.income ?? "",
        }))
      : [emptyMovement()],
  };
}

/** Movements as the API expects them, with trimmed text. */
export function toRecordDetailInputs(details: ManualRecordFormValues["details"]): RecordDetailInput[] {
  return details.map((detail) => ({
    affiliation: detail.affiliation.trim(),
    bank: detail.bank.trim(),
    income: detail.income,
  }));
}
