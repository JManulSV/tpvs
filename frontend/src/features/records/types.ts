import axios from "axios";

export const recordSources = ["IMAGE", "MANUAL"] as const;
export type RecordSource = (typeof recordSources)[number];

export const recordStatuses = [
  "PENDING",
  "PROCESSING",
  "NEEDS_REVIEW",
  "ERROR",
  "CONFIRMED",
  "CANCELED",
] as const;
export type RecordStatus = (typeof recordStatuses)[number];

export const aiProcessingStatuses = [
  "PENDING",
  "PROCESSING",
  "NEEDS_REVIEW",
  "ERROR",
  "COMPLETED",
] as const;
export type AiProcessingStatus = (typeof aiProcessingStatuses)[number];

export type RecordCompany = {
  id: number;
  name: string;
};

export type RecordImage = {
  id: number;
  original_file_name: string;
  mime_type: string;
  file_size: number;
  created_at: string | null;
};

export type AiProcessingIssue = {
  path: string;
  code: string;
  severity: "review" | "error";
  message: string;
  value?: unknown;
};

export type NormalizedValue<T> = {
  value: T | null;
  confidence: number;
};

export type RecordNormalizedExtraction = {
  record: {
    company_name: NormalizedValue<string>;
    year: NormalizedValue<number>;
    week: NormalizedValue<number>;
    week_start_date: NormalizedValue<string>;
    week_end_date: NormalizedValue<string>;
  };
  details: Array<{
    affiliation: NormalizedValue<string>;
    branch: NormalizedValue<string>;
    income: NormalizedValue<string>;
    commission_percentage: NormalizedValue<string>;
    source_commission: NormalizedValue<string>;
  }>;
  source_totals: {
    income: NormalizedValue<string>;
    commission: NormalizedValue<string>;
    return_amount: NormalizedValue<string>;
  };
};

export type RecordAiProcessing = {
  status: AiProcessingStatus;
  provider: string;
  model: string;
  started_at: string | null;
  completed_at: string | null;
  normalized_extraction: RecordNormalizedExtraction | null;
  issues: AiProcessingIssue[];
  unconvertible_numeric_count: number;
};

export type RecordDetail = {
  id: number;
  affiliation: string | null;
  branch: string | null;
  income: string | null;
  commission_percentage: string | null;
  commission: string | null;
  return_amount: string | null;
  order: number;
};

export type RecordDetailInput = {
  affiliation: string;
  branch: string;
  income: string;
} & (
  | { commission_percentage: string; commission?: never }
  | { commission: string; commission_percentage?: never }
);

export type RecordListItem = {
  id: number;
  company: RecordCompany | null;
  source: RecordSource;
  status: RecordStatus;
  year: number | null;
  week: number | null;
  week_start_date: string | null;
  week_end_date: string | null;
  image: RecordImage | null;
  ai_processing: RecordAiProcessing | null;
  income_total?: string | null;
  commission_total?: string | null;
  return_total?: string | null;
  details: RecordDetail[];
};

export type RecordDetailView = RecordListItem & {
  details: RecordDetail[];
};

export type RecordListParams = {
  search?: string;
  company_id?: number;
  year?: number;
  month?: number;
  week?: number;
  status?: RecordStatus;
  source?: RecordSource;
  page?: number;
  per_page?: number;
};

export type LaravelPagination<T> = {
  data: T[];
  links: {
    first: string | null;
    last: string | null;
    prev: string | null;
    next: string | null;
  };
  meta: {
    current_page: number;
    from: number | null;
    last_page: number;
    links: Array<{ url: string | null; label: string; active: boolean }>;
    path: string;
    per_page: number;
    to: number | null;
    total: number;
  };
};

export type RecordListResponse = LaravelPagination<RecordListItem>;

export type RecordResponse = {
  data: RecordDetailView;
};

export type RecordHistoryUser = {
  id: number;
  name: string;
  email: string;
};

export type RecordHistoryEntry = {
  id: number;
  field_name: string;
  previous_value: string | null;
  new_value: string | null;
  reason: string;
  created_at: string;
  user: RecordHistoryUser;
};

export type RecordHistoryParams = {
  page?: number;
  per_page?: number;
};

export type RecordHistoryResponse = LaravelPagination<RecordHistoryEntry>;

export type ManualRecordInput = {
  company_id: number;
  year: number;
  week: number;
  week_start_date: string;
  week_end_date: string;
  details: RecordDetailInput[];
};

export type UpdateManualRecordInput = ManualRecordInput & {
  reason: string;
};

export type CancelRecordInput = {
  reason: string;
};

export type RecordValidationErrors = Record<string, string[]>;

type LaravelErrorResponse = {
  message?: string;
  errors?: RecordValidationErrors;
  code?: string;
  details?: { reason?: string };
};

export class RecordsApiError extends Error {
  constructor(
    message: string,
    readonly status: number | null,
    readonly validationErrors: RecordValidationErrors = {},
    readonly code: string | null = null,
    readonly reason: string | null = null,
  ) {
    super(message);
    this.name = "RecordsApiError";
  }
}

export function toRecordsApiError(error: unknown): RecordsApiError {
  if (!axios.isAxiosError<LaravelErrorResponse>(error)) {
    return new RecordsApiError("Ocurrió un error inesperado.", null);
  }

  const status = error.response?.status ?? null;
  const response = error.response?.data;

  switch (status) {
    case 401:
      return new RecordsApiError("Tu sesión venció. Inicia sesión nuevamente.", status);
    case 403:
      return new RecordsApiError("No tienes permiso para realizar esta acción.", status);
    case 404:
      return new RecordsApiError("No se encontró el registro solicitado.", status);
    case 409:
      return new RecordsApiError(
        response?.message ?? "El registro ya no se encuentra en un estado que permita esta acción.",
        status,
        {},
        response?.code ?? null,
        response?.details?.reason ?? null,
      );
    case 422:
      return new RecordsApiError(
        response?.message ?? "Revisa los datos ingresados.",
        status,
        response?.errors ?? {},
        response?.code ?? null,
        response?.details?.reason ?? null,
      );
    default:
      if (!error.response) {
        return new RecordsApiError("No se pudo conectar con el servidor. Inténtalo nuevamente.", null);
      }

      return new RecordsApiError("No se pudo completar la solicitud. Inténtalo nuevamente.", status);
  }
}
