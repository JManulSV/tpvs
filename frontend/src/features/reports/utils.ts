import type { ReportFilters, ReportSearch } from "./types";

type ParamReader = { get(name: string): string | null };

export const REPORT_PAGE_SIZE = 15;

function integerParam(value: string | null, min: number, max: number): number | undefined {
  if (value === null || !/^\d+$/.test(value)) return undefined;

  const parsed = Number(value);

  return parsed >= min && parsed <= max ? parsed : undefined;
}

/** Reads the report filters and page from the URL; invalid values fall back to the defaults. */
export function parseReportSearch(params: ParamReader, now: Date = new Date()): ReportSearch {
  const search: ReportSearch = {
    year: integerParam(params.get("year"), 1, 9999) ?? now.getFullYear(),
    page: integerParam(params.get("page"), 1, Number.MAX_SAFE_INTEGER) ?? 1,
  };

  const month = integerParam(params.get("month"), 1, 12);
  const week = integerParam(params.get("week"), 1, 53);
  const companyId = integerParam(params.get("company_id"), 1, Number.MAX_SAFE_INTEGER);

  if (month !== undefined) search.month = month;
  if (week !== undefined) search.week = week;
  if (companyId !== undefined) search.company_id = companyId;

  return search;
}

/** Builds the URL query: the year is always present, empty filters are omitted, and page 1 is implicit. */
export function toReportSearchParams(filters: ReportFilters, page = 1): URLSearchParams {
  const params = new URLSearchParams({ year: String(filters.year) });

  if (filters.month !== undefined) params.set("month", String(filters.month));
  if (filters.week !== undefined) params.set("week", String(filters.week));
  if (filters.company_id !== undefined) params.set("company_id", String(filters.company_id));
  if (page > 1) params.set("page", String(page));

  return params;
}

export function formatMoney(value: string): string {
  return new Intl.NumberFormat("es-MX", { style: "currency", currency: "MXN" }).format(Number(value));
}

/** Formats a `Y-m-d` date without going through the local time zone. */
export function formatDate(value: string | null): string {
  if (value === null) return "—";

  const [year, month, day] = value.split("-").map(Number);

  if (!year || !month || !day) return value;

  return new Intl.DateTimeFormat("es-MX", { day: "numeric", month: "short", year: "numeric", timeZone: "UTC" }).format(
    new Date(Date.UTC(year, month - 1, day)),
  );
}

export function monthName(month: number): string {
  return new Intl.DateTimeFormat("es-MX", { month: "long", timeZone: "UTC" }).format(new Date(Date.UTC(2026, month - 1, 1)));
}

/** Years offered by the filter: the next year back to eight years ago, plus the selected one if outside that range. */
export function yearOptions(selected: number, now: Date = new Date()): number[] {
  const current = now.getFullYear();
  const years = Array.from({ length: 10 }, (_, index) => current + 1 - index);

  return years.includes(selected) ? years : [...years, selected].sort((a, b) => b - a);
}

/** Why the Excel action is unavailable, or `null` when it can be requested. */
export function excelBlockedReason({
  isSuccess,
  recordsCount,
}: {
  isSuccess: boolean;
  recordsCount: number | null;
}): string | null {
  if (!isSuccess || recordsCount === null) return "Espera a que se cargue el reporte para generar el Excel.";
  if (recordsCount === 0) return "No hay registros confirmados para exportar con estos filtros.";

  return null;
}
