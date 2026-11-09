import { recordStatuses, type RecordListParams, type RecordStatus } from "./types";

export type InitialRecordFilters = Pick<RecordListParams, "status" | "year" | "month">;

type SearchParamsReader = { get(name: string): string | null };

function positiveInteger(value: string | null): number | undefined {
  if (value === null || !/^\d+$/.test(value)) return undefined;

  const number = Number(value);

  return Number.isSafeInteger(number) && number > 0 ? number : undefined;
}

/**
 * Initial `/records` filters read once from the URL (e.g. links from the dashboard).
 * Invalid values are dropped instead of failing the screen.
 */
export function parseInitialRecordFilters(params: SearchParamsReader | null): InitialRecordFilters {
  if (!params) return {};

  const filters: InitialRecordFilters = {};
  const status = params.get("status");
  const year = positiveInteger(params.get("year"));
  const month = positiveInteger(params.get("month"));

  if (status !== null && (recordStatuses as readonly string[]).includes(status)) {
    filters.status = status as RecordStatus;
  }
  if (year !== undefined && year <= 9999) filters.year = year;
  if (month !== undefined && month <= 12) filters.month = month;

  return filters;
}
