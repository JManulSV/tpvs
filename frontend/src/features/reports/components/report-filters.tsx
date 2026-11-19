"use client";

import { X } from "lucide-react";
import type { Company } from "@/features/companies/types";
import type { ReportFilters } from "../types";
import { clearedReportFilters, hasActiveReportFilters, monthName, yearOptions } from "../utils";

const selectClass =
  "h-10 w-full appearance-none rounded-md border border-input bg-background px-3 text-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background";

const labelClass = "text-sm font-medium text-muted-foreground";

export function ReportFiltersForm({
  companies,
  filters,
  onChange,
}: {
  companies: Company[];
  filters: ReportFilters;
  onChange: (filters: ReportFilters) => void;
}) {
  const hasActiveFilters = hasActiveReportFilters(filters);

  function update(patch: Partial<Record<keyof ReportFilters, number | undefined>>) {
    onChange({ ...filters, ...patch } as ReportFilters);
  }

  function clear() {
    onChange(clearedReportFilters());
  }

  return (
    <form
      aria-label="Filtros del reporte"
      className="space-y-3 rounded-lg border bg-card p-4"
      onSubmit={(event) => event.preventDefault()}
    >
      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <div className="space-y-1.5">
          <label className={labelClass} htmlFor="report-year">Año</label>
          <select
            className={selectClass}
            id="report-year"
            onChange={(event) => update({ year: Number(event.target.value) })}
            required
            value={filters.year}
          >
            {yearOptions(filters.year).map((year) => <option key={year} value={year}>{year}</option>)}
          </select>
        </div>

        <div className="space-y-1.5">
          <label className={labelClass} htmlFor="report-month">Mes</label>
          <select
            className={selectClass}
            id="report-month"
            onChange={(event) => update({ month: event.target.value ? Number(event.target.value) : undefined })}
            value={filters.month?.toString() ?? ""}
          >
            <option value="">Todos</option>
            {Array.from({ length: 12 }, (_, index) => index + 1).map((month) => (
              <option key={month} value={month}>{monthName(month)}</option>
            ))}
          </select>
        </div>

        <div className="space-y-1.5">
          <label className={labelClass} htmlFor="report-week">Semana</label>
          <select
            className={selectClass}
            id="report-week"
            onChange={(event) => update({ week: event.target.value ? Number(event.target.value) : undefined })}
            value={filters.week?.toString() ?? ""}
          >
            <option value="">Todas</option>
            {Array.from({ length: 53 }, (_, index) => index + 1).map((week) => <option key={week} value={week}>{week}</option>)}
          </select>
        </div>

        <div className="space-y-1.5">
          <label className={labelClass} htmlFor="report-company">Empresa</label>
          <select
            className={selectClass}
            id="report-company"
            onChange={(event) => update({ company_id: event.target.value ? Number(event.target.value) : undefined })}
            value={filters.company_id?.toString() ?? ""}
          >
            <option value="">Todas</option>
            {companies.map((company) => <option key={company.id} value={company.id}>{company.name}</option>)}
          </select>
        </div>
      </div>

      {hasActiveFilters && (
        <div className="flex justify-end">
          <button
            className="inline-flex h-8 items-center gap-1.5 rounded-full px-3 text-[13px] font-semibold text-accent-foreground transition-colors hover:bg-accent focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background"
            onClick={clear}
            type="button"
          >
            <X aria-hidden="true" className="size-4" />
            Limpiar filtros
          </button>
        </div>
      )}
    </form>
  );
}
