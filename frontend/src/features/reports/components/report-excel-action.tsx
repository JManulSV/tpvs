"use client";

import { Button } from "@/components/ui/button";
import { useDownloadReportExcel } from "../hooks/use-reports";
import { ReportsApiError, type ReportFilters } from "../types";
import { excelBlockedReason } from "../utils";

export function ReportExcelAction({
  filters,
  isSuccess,
  mutation,
  recordsCount,
}: {
  filters: ReportFilters;
  isSuccess: boolean;
  mutation: ReturnType<typeof useDownloadReportExcel>;
  recordsCount: number | null;
}) {
  const blockedReason = excelBlockedReason({ isSuccess, recordsCount });
  const error = mutation.error instanceof ReportsApiError ? mutation.error : null;

  function handleGenerate() {
    if (blockedReason !== null || mutation.isPending) return;

    mutation.mutate(filters);
  }

  return (
    <div className="space-y-2">
      <Button
        aria-describedby={blockedReason !== null ? "report-excel-blocked" : undefined}
        disabled={blockedReason !== null || mutation.isPending}
        onClick={handleGenerate}
        type="button"
      >
        {mutation.isPending ? "Generando Excel…" : "Generar Excel"}
      </Button>

      {blockedReason !== null && (
        <p className="text-sm text-muted-foreground" id="report-excel-blocked" role="note">{blockedReason}</p>
      )}

      {mutation.isSuccess && <p className="text-sm" role="status">Excel generado y descargado.</p>}

      {mutation.isError && (
        <p className="rounded-md border border-destructive/40 px-4 py-3 text-sm text-destructive" role="alert">
          {error?.message ?? "No se pudo generar el Excel. Inténtalo nuevamente."}
        </p>
      )}
    </div>
  );
}
