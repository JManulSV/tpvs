"use client";

import { Download, LoaderCircle } from "lucide-react";
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
  // The server names the file; the screen only shows it.
  const downloadedFileName = mutation.data?.fileName;

  function handleGenerate() {
    if (blockedReason !== null || mutation.isPending) return;

    mutation.mutate(filters);
  }

  return (
    <div className="flex flex-col items-end gap-2">
      <Button
        aria-describedby={blockedReason !== null ? "report-excel-blocked" : undefined}
        disabled={blockedReason !== null || mutation.isPending}
        onClick={handleGenerate}
        size="lg"
        type="button"
      >
        {mutation.isPending ? (
          <LoaderCircle aria-hidden="true" className="animate-spin" />
        ) : (
          <Download aria-hidden="true" />
        )}
        {mutation.isPending ? "Generando Excel…" : "Generar Excel"}
      </Button>

      {blockedReason !== null && (
        <p className="text-sm text-muted-foreground" id="report-excel-blocked" role="note">{blockedReason}</p>
      )}

      {mutation.isSuccess && downloadedFileName && (
        <p className="text-sm text-muted-foreground" role="status">
          Se generó y descargó <strong className="font-semibold text-foreground">{downloadedFileName}</strong>
        </p>
      )}

      {mutation.isError && (
        <p className="rounded-md border border-destructive/40 px-4 py-3 text-sm text-destructive" role="alert">
          {error?.message ?? "No se pudo generar el Excel. Inténtalo nuevamente."}
        </p>
      )}
    </div>
  );
}
