import type { ManualRecordFormValues } from "../schemas/record.schema";
import type { RecordDetailInput, RecordDetailView } from "../types";

export type CommissionMode = "percentage" | "fixed";

type MovementValues = ManualRecordFormValues["details"][number];

export function emptyMovement(): MovementValues {
  return { affiliation: "", branch: "", income: "", commission_percentage: "" };
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
    details: record?.details.length
      ? record.details.map((detail) => ({
          affiliation: detail.affiliation ?? "",
          branch: detail.branch ?? "",
          income: detail.income ?? "",
          ...(detail.commission_percentage !== null
            ? { commission_percentage: detail.commission_percentage }
            : { commission: detail.commission ?? "" }),
        }))
      : [emptyMovement()],
  };
}

export function commissionModesFor(record: RecordDetailView): CommissionMode[] {
  return record.details.map((detail) => (detail.commission_percentage === null ? "fixed" : "percentage"));
}

/** Sends only the commission field that matches each movement's mode. */
export function toRecordDetailInputs(
  details: ManualRecordFormValues["details"],
  modes: CommissionMode[],
): RecordDetailInput[] {
  return details.map((detail, index) => {
    const base = { affiliation: detail.affiliation.trim(), branch: detail.branch.trim(), income: detail.income };

    return (modes[index] ?? "percentage") === "percentage"
      ? { ...base, commission_percentage: detail.commission_percentage ?? "" }
      : { ...base, commission: detail.commission ?? "" };
  });
}
