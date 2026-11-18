import {
  recordSources,
  recordStatuses,
  type RecordListParams,
  type RecordSource,
  type RecordStatus,
} from "./types";

export type RecordListFilters = Omit<RecordListParams, "search" | "page" | "per_page">;

/** State of the `/records` list, kept in the URL so links and navigation always reflect it. */
export type RecordListQuery = {
  filters: RecordListFilters;
  search: string;
  page: number;
};

type SearchParamsReader = { get(name: string): string | null };

const SEARCH_MAX_LENGTH = 255;

function positiveInteger(value: string | null, max: number): number | undefined {
  if (value === null || !/^\d+$/.test(value)) return undefined;

  const number = Number(value);

  return Number.isSafeInteger(number) && number > 0 && number <= max ? number : undefined;
}

function oneOf<T extends string>(value: string | null, allowed: readonly T[]): T | undefined {
  return value !== null && (allowed as readonly string[]).includes(value) ? (value as T) : undefined;
}

/**
 * Reads the `/records` list state from the URL (filters, search and page).
 * Invalid values are dropped instead of failing the screen.
 */
export function parseRecordListQuery(params: SearchParamsReader | null): RecordListQuery {
  if (!params) return { filters: {}, search: "", page: 1 };

  const candidates: RecordListFilters = {
    company_id: positiveInteger(params.get("company_id"), Number.MAX_SAFE_INTEGER),
    status: oneOf<RecordStatus>(params.get("status"), recordStatuses),
    year: positiveInteger(params.get("year"), 9999),
    month: positiveInteger(params.get("month"), 12),
    week: positiveInteger(params.get("week"), 53),
    source: oneOf<RecordSource>(params.get("source"), recordSources),
  };
  const filters = Object.fromEntries(
    Object.entries(candidates).filter(([, value]) => value !== undefined),
  ) as RecordListFilters;

  return {
    filters,
    search: (params.get("search") ?? "").trim().slice(0, SEARCH_MAX_LENGTH),
    page: positiveInteger(params.get("page"), Number.MAX_SAFE_INTEGER) ?? 1,
  };
}

const filterKeys = ["company_id", "status", "year", "month", "week", "source"] as const;

/** Builds the `/records` URL for a list state; empty values and page 1 are omitted. */
export function recordListHref({ filters, search, page }: RecordListQuery): string {
  const params = new URLSearchParams();

  for (const key of filterKeys) {
    const value = filters[key];
    if (value !== undefined) params.set(key, String(value));
  }
  if (search.trim() !== "") params.set("search", search.trim());
  if (page > 1) params.set("page", String(page));

  const query = params.toString();

  return query ? `/records?${query}` : "/records";
}
