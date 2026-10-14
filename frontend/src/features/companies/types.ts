export type Company = {
  id: number;
  name: string;
  records_count: number;
};

export type CompanyDetail = {
  id: number;
  name: string;
};

export type CompanyListParams = {
  search?: string;
  page?: number;
  per_page?: number;
};

export type CompanyListResponse = {
  data: Company[];
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
    links: Array<{
      url: string | null;
      label: string;
      active: boolean;
    }>;
    path: string;
    per_page: number;
    to: number | null;
    total: number;
  };
};

export type CompanyResponse = {
  data: CompanyDetail;
};

export type CompanyValidationErrors = Record<string, string[]>;

type LaravelErrorResponse = {
  message?: string;
  errors?: CompanyValidationErrors;
};

export class CompaniesApiError extends Error {
  constructor(
    message: string,
    readonly status: number | null,
    readonly validationErrors: CompanyValidationErrors = {},
  ) {
    super(message);
    this.name = "CompaniesApiError";
  }
}

export function toCompaniesApiError(error: unknown): CompaniesApiError {
  if (!isAxiosError(error)) {
    return new CompaniesApiError("Ocurrió un error inesperado.", null);
  }

  const status = error.response?.status ?? null;
  const response = error.response?.data as LaravelErrorResponse | undefined;

  switch (status) {
    case 401:
      return new CompaniesApiError(
        "Tu sesión venció. Inicia sesión nuevamente.",
        status,
      );
    case 403:
      return new CompaniesApiError(
        "No tienes permiso para realizar esta acción.",
        status,
      );
    case 422:
      return new CompaniesApiError(
        response?.message ?? "Revisa los datos ingresados.",
        status,
        response?.errors ?? {},
      );
    default:
      if (!error.response) {
        return new CompaniesApiError(
          "No se pudo conectar con el servidor. Inténtalo nuevamente.",
          null,
        );
      }

      return new CompaniesApiError(
        "No se pudo completar la solicitud. Inténtalo nuevamente.",
        status,
      );
  }
}

function isAxiosError(
  error: unknown,
): error is {
  response?: { status: number; data?: unknown };
} {
  return typeof error === "object" && error !== null && "isAxiosError" in error;
}
