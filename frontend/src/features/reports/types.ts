import axios from "axios";

export type ReportFilters = {
  year: number;
  month?: number;
  week?: number;
  company_id?: number;
};

/** Filters plus the page of included records, as kept in the URL. */
export type ReportSearch = ReportFilters & { page: number };

export type ReportListParams = ReportFilters & { page?: number; per_page?: number };

export type ReportTotals = {
  records_count: number;
  income_total: string;
  commission_total: string;
  return_total: string;
};

export type ReportCompanyRow = ReportTotals & {
  company: { id: number; name: string };
};

export type ReportRecordRow = {
  id: number;
  company: { id: number; name: string };
  year: number | null;
  week: number | null;
  week_start_date: string | null;
  week_end_date: string | null;
  details_count: number;
  income_total: string;
  commission_total: string;
  return_total: string;
  delivery_date: string | null;
};

export type ReportResponse = {
  data: {
    filters: {
      year: number;
      month: number | null;
      week: number | null;
      company_id: number | null;
    };
    period: { week_start_date: string; week_end_date: string } | null;
    totals: ReportTotals;
    companies: ReportCompanyRow[];
    records: ReportRecordRow[];
  };
  meta: {
    current_page: number;
    per_page: number;
    total: number;
    last_page: number;
  };
};

export type ReportExcel = {
  blob: Blob;
  fileName: string;
};

export type ReportValidationErrors = Record<string, string[]>;

type LaravelErrorResponse = {
  message?: string;
  errors?: ReportValidationErrors;
};

export class ReportsApiError extends Error {
  constructor(
    message: string,
    readonly status: number | null,
    readonly validationErrors: ReportValidationErrors = {},
  ) {
    super(message);
    this.name = "ReportsApiError";
  }
}

export function toReportsApiError(error: unknown): ReportsApiError {
  if (!axios.isAxiosError<LaravelErrorResponse>(error)) {
    return new ReportsApiError("Ocurrió un error inesperado.", null);
  }

  const status = error.response?.status ?? null;
  const response = error.response?.data;

  switch (status) {
    case 401:
      return new ReportsApiError("Tu sesión venció. Inicia sesión nuevamente.", status);
    case 403:
      return new ReportsApiError("No tienes permiso para consultar los reportes.", status);
    case 422:
      return new ReportsApiError(
        response?.message ?? "Revisa los filtros del reporte.",
        status,
        response?.errors ?? {},
      );
    default:
      if (!error.response) {
        return new ReportsApiError("No se pudo conectar con el servidor. Inténtalo nuevamente.", null);
      }

      return new ReportsApiError("No se pudo completar la solicitud. Inténtalo nuevamente.", status);
  }
}
