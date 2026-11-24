import { recordStatusMeta } from "@/lib/status";
import { recordSources, recordStatuses, type RecordSource, type RecordStatus } from "../types";
import type { RecordListFilters } from "./filters";
import { ALL_FILTER_VALUE, selectValueToFilter } from "./list";

export type FilterVariant = "card" | "chips";
export type FilterCompany = { id: number; name: string };
type FilterOption = { value: string; label: string };

/** Last eight years, plus a URL-provided year outside that range so the select can show it. */
export function yearOptions(selected: number | undefined): number[] {
  const years = Array.from({ length: 8 }, (_, index) => new Date().getFullYear() - index);
  if (selected !== undefined && !years.includes(selected)) years.push(selected);

  return years.sort((a, b) => b - a);
}

const monthFormatter = new Intl.DateTimeFormat("es-MX", { month: "long", timeZone: "UTC" });

function monthName(month: number): string {
  return monthFormatter.format(new Date(Date.UTC(2026, month - 1, 1)));
}

function capitalize(text: string): string {
  return text.charAt(0).toUpperCase() + text.slice(1);
}

const sourceLabels: Record<RecordSource, string> = { IMAGE: "Imagen", MANUAL: "Manual" };

export type FilterDefinition = {
  key: "company_id" | "status" | "year" | "month" | "week" | "source";
  label: string;
  options: FilterOption[];
  /** Chip width on mobile, in rem. */
  chipWidth: string;
};

export function buildFilterDefinitions(
  variant: FilterVariant,
  companies: FilterCompany[],
  selectedYear: number | undefined,
): FilterDefinition[] {
  const chips = variant === "chips";
  const all = (card: string, chip: string): FilterOption => ({ value: ALL_FILTER_VALUE, label: chips ? chip : card });

  return [
    {
      key: "company_id",
      label: "Empresa",
      chipWidth: "12.25rem",
      options: [
        all("Todas", "Todas las empresas"),
        ...companies.map((company) => ({ value: String(company.id), label: company.name })),
      ],
    },
    {
      key: "status",
      label: "Estado",
      chipWidth: "11rem",
      options: [
        all("Todos", "Todos los estados"),
        ...recordStatuses.map((status) => ({ value: status, label: recordStatusMeta[status].label })),
      ],
    },
    {
      key: "year",
      label: "Año",
      chipWidth: "10rem",
      options: [
        all("Todos", "Todos los años"),
        ...yearOptions(selectedYear).map((year) => ({
          value: String(year),
          label: chips ? `Año ${year}` : String(year),
        })),
      ],
    },
    {
      key: "month",
      label: "Mes",
      chipWidth: "11rem",
      options: [
        all("Todos", "Todos los meses"),
        ...Array.from({ length: 12 }, (_, index) => index + 1).map((month) => ({
          value: String(month),
          label: chips ? `Mes: ${monthName(month)}` : capitalize(monthName(month)),
        })),
      ],
    },
    {
      key: "week",
      label: "Semana",
      chipWidth: "11.5rem",
      options: [
        all("Todas", "Todas las semanas"),
        ...Array.from({ length: 53 }, (_, index) => index + 1).map((week) => ({
          value: String(week),
          label: chips ? `Semana ${week}` : String(week),
        })),
      ],
    },
    {
      key: "source",
      label: "Fuente",
      chipWidth: "11.5rem",
      options: [
        all("Todas", "Todas las fuentes"),
        ...recordSources.map((source) => ({
          value: source,
          label: chips ? `Fuente: ${sourceLabels[source]}` : sourceLabels[source],
        })),
      ],
    },
  ];
}

/** Translates a select value into the typed filter patch. "all" clears the filter. */
export function filterPatch(key: FilterDefinition["key"], raw: string | null): Partial<RecordListFilters> {
  const value = selectValueToFilter(raw);

  switch (key) {
    case "company_id":
      return { company_id: value ? Number(value) : undefined };
    case "year":
      return { year: value ? Number(value) : undefined };
    case "month":
      return { month: value ? Number(value) : undefined };
    case "week":
      return { week: value ? Number(value) : undefined };
    case "status":
      return { status: value as RecordStatus | undefined };
    case "source":
      return { source: value as RecordSource | undefined };
  }
}

export function currentFilterValue(filters: RecordListFilters, key: FilterDefinition["key"]): string {
  const value = filters[key];

  return value === undefined ? ALL_FILTER_VALUE : String(value);
}
