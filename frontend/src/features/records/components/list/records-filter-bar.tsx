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
import {
  buildFilterDefinitions,
  currentFilterValue,
  filterPatch,
  type FilterCompany,
  type FilterDefinition,
  type FilterVariant,
} from "../../utils/filter-options";
import type { RecordListFilters } from "../../utils/filters";
import { ALL_FILTER_VALUE } from "../../utils/list";

type RecordsFilterBarProps = {
  searchInput: string;
  onSearchChange: (value: string) => void;
  filters: RecordListFilters;
  onFiltersChange: (patch: Partial<RecordListFilters>) => void;
  companies: FilterCompany[];
  hasActiveFilters: boolean;
  onClear: () => void;
  /** Total of the current list; shown in the mobile summary line once it is known. */
  total?: number;
};

function FilterSelect({
  definition,
  filters,
  onFiltersChange,
  variant,
}: {
  definition: FilterDefinition;
  filters: RecordListFilters;
  onFiltersChange: RecordsFilterBarProps["onFiltersChange"];
  variant: FilterVariant;
}) {
  const id = useId();
  const value = currentFilterValue(filters, definition.key);
  const active = value !== ALL_FILTER_VALUE;
  const select = (
    <Select
      items={definition.options}
      onValueChange={(next) => onFiltersChange(filterPatch(definition.key, next))}
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

  return (
    <>
      {/* From md: filter card whose grid follows the container width. */}
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
          {cardFilters.map((definition) => (
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

      {/* Below md: search and horizontally scrolling chips. */}
      <div className="flex flex-col gap-4 md:hidden">
        <SearchField
          aria-label="Buscar registros"
          className="h-12"
          onChange={onSearchChange}
          value={searchInput}
        />
        <div aria-label="Filtros" className="-mx-4 flex gap-2 overflow-x-auto px-4 pb-1" role="group">
          {chipFilters.map((definition) => (
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
