"use client";

import type { Company } from "@/features/companies/types";
import type { ReportFilters } from "../types";
import { monthName, yearOptions } from "../utils";

const selectClass = "h-10 w-full rounded-md border bg-background px-3 text-sm";

export function ReportFiltersForm({
  companies,
  filters,
  onChange,
}: {
  companies: Company[];
  filters: ReportFilters;
  onChange: (filters: ReportFilters) => void;
}) {
  function update(patch: Partial<Record<keyof ReportFilters, number | undefined>>) {
    onChange({ ...filters, ...patch } as ReportFilters);
  }

  return (
    <form
      aria-label="Filtros del reporte"
      className="grid gap-4 rounded-md border p-4 sm:grid-cols-2 lg:grid-cols-4"
      onSubmit={(event) => event.preventDefault()}
    >
      <div className="space-y-2">
        <label className="text-sm font-medium" htmlFor="report-year">Año</label>
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

      <div className="space-y-2">
        <label className="text-sm font-medium" htmlFor="report-month">Mes (fecha final)</label>
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

      <div className="space-y-2">
        <label className="text-sm font-medium" htmlFor="report-week">Semana del negocio</label>
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

      <div className="space-y-2">
        <label className="text-sm font-medium" htmlFor="report-company">Empresa</label>
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
    </form>
  );
}
