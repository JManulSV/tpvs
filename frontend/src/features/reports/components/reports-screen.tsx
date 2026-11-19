"use client";

import { usePathname, useRouter, useSearchParams } from "next/navigation";
import { useMemo } from "react";
import { Button } from "@/components/ui/button";
import { useCompanies } from "@/features/companies/hooks/use-companies";
import { useDownloadReportExcel, useReport } from "../hooks/use-reports";
import { ReportsApiError, type ReportFilters } from "../types";
import { clearedReportFilters, parseReportSearch, toReportSearchParams } from "../utils";
import { ReportExcelAction } from "./report-excel-action";
import { ReportFiltersForm } from "./report-filters";
import { CompanyBreakdown, IncludedRecords, ReportPeriod, ReportTotalsCards } from "./report-tables";

export function ReportsScreen() {
  const router = useRouter();
  const pathname = usePathname();
  const searchParams = useSearchParams();
  const search = useMemo(() => parseReportSearch(searchParams), [searchParams]);
  const filters: ReportFilters = {
    year: search.year,
    ...(search.month !== undefined && { month: search.month }),
    ...(search.week !== undefined && { week: search.week }),
    ...(search.company_id !== undefined && { company_id: search.company_id }),
  };

  const companiesQuery = useCompanies({ per_page: 100 });
  const query = useReport(search);
  const excel = useDownloadReportExcel();

  const report = query.data?.data;
  const meta = query.data?.meta;
  const error = query.error instanceof ReportsApiError ? query.error : null;
  const fieldErrors = Object.values(error?.validationErrors ?? {}).flat();

  function navigate(nextFilters: ReportFilters, nextPage = 1) {
    excel.reset();
    router.replace(`${pathname}?${toReportSearchParams(nextFilters, nextPage)}`, { scroll: false });
  }

  return (
    <section aria-labelledby="reports-heading" className="space-y-6">
      <header className="space-y-1">
        <h1 className="text-2xl font-semibold tracking-tight" id="reports-heading">Reportes</h1>
        <p className="text-sm text-muted-foreground">Totales oficiales de los registros confirmados</p>
      </header>

      <div className="space-y-2">
        <ReportFiltersForm
          companies={companiesQuery.data?.data ?? []}
          filters={filters}
          onChange={(next) => navigate(next)}
        />
        <p className="px-1 text-xs text-muted-foreground">
          El mes se toma de la fecha de fin de la semana. Solo se incluyen registros confirmados.
        </p>
      </div>

      {query.isPending && <p aria-live="polite" className="py-8 text-center text-sm text-muted-foreground">Cargando reporte…</p>}

      {query.isError && (
        <div className="space-y-3 rounded-md border p-5 text-center" role="alert">
          <p>{error?.message ?? "No se pudo cargar el reporte."}</p>
          {fieldErrors.length > 0 && (
            <ul className="text-sm text-muted-foreground">
              {fieldErrors.map((message) => <li key={message}>{message}</li>)}
            </ul>
          )}
          <Button onClick={() => void query.refetch()} type="button" variant="outline">Reintentar</Button>
        </div>
      )}

      {report && meta && !query.isError && (
        <>
          <div className="flex flex-wrap items-start justify-between gap-4">
            <ReportPeriod period={report.period} week={report.filters.week} />
            <ReportExcelAction
              filters={filters}
              isSuccess={query.isSuccess}
              mutation={excel}
              recordsCount={report.totals.records_count}
            />
          </div>

          {report.totals.records_count === 0 ? (
            <section
              aria-labelledby="report-empty-heading"
              className="flex flex-col items-center gap-3 rounded-lg border bg-card px-6 py-12 text-center shadow-card"
            >
              <h2 className="text-base font-semibold" id="report-empty-heading">
                No hay registros confirmados en este periodo
              </h2>
              <p className="max-w-md text-sm text-muted-foreground">
                Prueba con otra semana, otro mes u otra empresa. Los registros pendientes o por revisar no cuentan en los reportes.
              </p>
              <Button onClick={() => navigate(clearedReportFilters())} type="button" variant="outline">
                Limpiar filtros
              </Button>
            </section>
          ) : (
            <>
              <ReportTotalsCards totals={report.totals} />
              <CompanyBreakdown companies={report.companies} totals={report.totals} />
              <IncludedRecords
                isFetching={query.isFetching}
                meta={meta}
                onPageChange={(nextPage) => navigate(filters, nextPage)}
                records={report.records}
              />
            </>
          )}
        </>
      )}
    </section>
  );
}
