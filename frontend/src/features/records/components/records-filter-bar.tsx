"use client";

import { Search, X } from "lucide-react";
import { useId } from "react";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { cn } from "@/lib/utils";
import { recordStatusMeta } from "@/lib/status";
import { ALL_FILTER_VALUE, selectValueToFilter } from "../record-list-utils";
import {
  recordSources,
  recordStatuses,
  type RecordListParams,
  type RecordSource,
  type RecordStatus,
} from "../types";

export type RecordListFilters = Omit<RecordListParams, "search" | "page" | "per_page">;

type Option = { value: string; label: string };
type Variant = "card" | "chips";

type RecordsFilterBarProps = {
  searchInput: string;
  onSearchChange: (value: string) => void;
  filters: RecordListFilters;
  onFiltersChange: (patch: Partial<RecordListFilters>) => void;
  companies: Array<{ id: number; name: string }>;
  hasActiveFilters: boolean;
  onClear: () => void;
  /** Total of the current list; shown in the mobile summary line once it is known. */
  total?: number;
};

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

type FilterDefinition = {
  key: "company_id" | "status" | "year" | "month" | "week" | "source";
  label: string;
  options: Option[];
  /** Chip width on mobile, in rem. */
  chipWidth: string;
};

function buildFilterDefinitions(
  variant: Variant,
  companies: RecordsFilterBarProps["companies"],
  selectedYear: number | undefined,
): FilterDefinition[] {
  const chips = variant === "chips";
  const all = (card: string, chip: string): Option => ({ value: ALL_FILTER_VALUE, label: chips ? chip : card });

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
      chipWidth: "9.25rem",
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
      chipWidth: "10rem",
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
      chipWidth: "10rem",
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
function toPatch(key: FilterDefinition["key"], raw: string | null): Partial<RecordListFilters> {
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

function currentValue(filters: RecordListFilters, key: FilterDefinition["key"]): string {
  const value = filters[key];

  return value === undefined ? ALL_FILTER_VALUE : String(value);
}

function FilterSelect({
  definition,
  filters,
  onFiltersChange,
  variant,
}: {
  definition: FilterDefinition;
  filters: RecordListFilters;
  onFiltersChange: RecordsFilterBarProps["onFiltersChange"];
  variant: Variant;
}) {
  const id = useId();
  const value = currentValue(filters, definition.key);
  const active = value !== ALL_FILTER_VALUE;
  const select = (
    <Select
      items={definition.options}
      onValueChange={(next) => onFiltersChange(toPatch(definition.key, next))}
      value={value}
    >
      {variant === "chips" ? (
        <SelectTrigger
          aria-label={definition.label}
          className={cn(
            "shrink-0 font-semibold",
            active && "border-accent-foreground bg-accent text-accent-foreground",
          )}
          size="lg"
          style={{ width: definition.chipWidth }}
        >
          <SelectValue />
        </SelectTrigger>
      ) : (
        <SelectTrigger className="w-full" id={id}>
          <SelectValue />
        </SelectTrigger>
      )}
      <SelectContent alignItemWithTrigger={false} className="max-h-72">
        {definition.options.map((option) => (
          <SelectItem key={option.value} value={option.value}>
            {option.label}
          </SelectItem>
        ))}
      </SelectContent>
    </Select>
  );

  if (variant === "chips") return select;

  return (
    <div className="flex flex-col gap-1.5">
      <label className="text-xs font-semibold text-muted-foreground" htmlFor={id}>
        {definition.label}
      </label>
      {select}
    </div>
  );
}

function SearchField({
  className,
  onChange,
  value,
  ...rest
}: {
  className?: string;
  onChange: (value: string) => void;
  value: string;
  id?: string;
  "aria-label"?: string;
}) {
  return (
    <span
      className={cn(
        "flex items-center gap-2 rounded-full border border-input bg-card px-4 text-muted-foreground focus-within:ring-2 focus-within:ring-ring focus-within:ring-offset-2 focus-within:ring-offset-card",
        className,
      )}
    >
      <Search aria-hidden="true" className="size-4 shrink-0" />
      <input
        className="h-full min-w-0 flex-1 border-0 bg-transparent text-sm text-foreground outline-none placeholder:text-muted-foreground"
        maxLength={255}
        onChange={(event) => onChange(event.target.value)}
        placeholder="Empresa, afiliación o sucursal…"
        type="search"
        value={value}
        {...rest}
      />
    </span>
  );
}

export function RecordsFilterBar({
  companies,
  filters,
  hasActiveFilters,
  onClear,
  onFiltersChange,
  onSearchChange,
  searchInput,
  total,
}: RecordsFilterBarProps) {
  const searchId = useId();
  const cardFilters = buildFilterDefinitions("card", companies, filters.year);
  const chipFilters = buildFilterDefinitions("chips", companies, filters.year);
  const byKey = (list: FilterDefinition[], keys: FilterDefinition["key"][]) =>
    keys.map((key) => list.find((definition) => definition.key === key)!);

  return (
    <>
      {/* Desde md: tarjeta de filtros. La rejilla depende del ancho del contenedor, no de la ventana. */}
      <section
        aria-label="Filtros"
        className="@container hidden rounded-lg border border-border bg-card p-5 shadow-card md:flex md:flex-col md:gap-4 md:px-6"
      >
        <div className="grid grid-cols-2 gap-x-4 gap-y-3 @3xl:grid-cols-4">
          <div className="col-span-2 flex flex-col gap-1.5">
            <label className="text-xs font-semibold text-muted-foreground" htmlFor={searchId}>
              Buscar
            </label>
            <SearchField className="h-10" id={searchId} onChange={onSearchChange} value={searchInput} />
          </div>
          {byKey(cardFilters, ["company_id", "status", "year", "month", "week", "source"]).map((definition) => (
            <FilterSelect
              definition={definition}
              filters={filters}
              key={definition.key}
              onFiltersChange={onFiltersChange}
              variant="card"
            />
          ))}
        </div>
        <div className="flex min-h-8 flex-wrap items-center justify-between gap-x-4 gap-y-2">
          <span className="text-xs text-muted-foreground">El mes se toma de la fecha de fin de la semana.</span>
          {hasActiveFilters && (
            <button
              className="inline-flex h-8 items-center gap-1.5 rounded-full px-3 text-[13px] font-semibold text-accent-foreground transition-colors hover:bg-accent focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-card"
              onClick={onClear}
              type="button"
            >
              <X aria-hidden="true" className="size-4" />
              Limpiar filtros
            </button>
          )}
        </div>
      </section>

      {/* Por debajo de md: buscador y chips con scroll horizontal. */}
      <div className="flex flex-col gap-4 md:hidden">
        <SearchField
          aria-label="Buscar registros"
          className="h-12"
          onChange={onSearchChange}
          value={searchInput}
        />
        <div aria-label="Filtros" className="-mx-4 flex gap-2 overflow-x-auto px-4 pb-1" role="group">
          {byKey(chipFilters, ["company_id", "status", "year", "month", "week", "source"]).map((definition) => (
            <FilterSelect
              definition={definition}
              filters={filters}
              key={definition.key}
              onFiltersChange={onFiltersChange}
              variant="chips"
            />
          ))}
        </div>
        <div className="-my-1 flex items-center justify-between gap-3">
          <span className="text-[13px] text-muted-foreground">
            {total !== undefined && (
              <>
                <strong className="font-bold text-foreground tabular-nums">{total}</strong>{" "}
                {total === 1 ? "registro" : "registros"}
              </>
            )}
          </span>
          {hasActiveFilters && (
            <button
              className="inline-flex min-h-11 items-center px-1 text-[13px] font-semibold text-accent-foreground focus-visible:rounded-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
              onClick={onClear}
              type="button"
            >
              Limpiar
            </button>
          )}
        </div>
      </div>
    </>
  );
}
