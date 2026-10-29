import axios from "axios";

export const officialDenominationValues = [
  "1000.00",
  "500.00",
  "200.00",
  "100.00",
  "50.00",
  "20.00",
  "10.00",
  "5.00",
  "2.00",
  "1.00",
  "0.50",
] as const;

export type OfficialDenomination = (typeof officialDenominationValues)[number];

export type DeliveryDenomination = {
  value: string;
  quantity: number;
};

export type Delivery = {
  id: number;
  record_id: number;
  delivery_date: string;
  delivered_by_name: string;
  received_by_name: string | null;
  prepared_return_amount: string;
  current_return_amount: string;
  is_stale: boolean;
  denominations: DeliveryDenomination[];
  created_at: string | null;
  updated_at: string | null;
};

export type DeliveryResponse = {
  data: Delivery | null;
};

export type UpsertDeliveryInput = {
  delivery_date: string;
  delivered_by_name: string;
  received_by_name: string | null;
  denominations: DeliveryDenomination[];
};

export type DeliveryValidationErrors = Record<string, string[]>;

export type DeliveryErrorDetails = {
  reason?: string;
  expected_amount?: string;
  cash_amount?: string;
  difference?: string;
};

type LaravelErrorResponse = {
  message?: string;
  errors?: DeliveryValidationErrors;
  code?: string;
  details?: DeliveryErrorDetails;
};

export class DeliveryApiError extends Error {
  constructor(
    message: string,
    readonly status: number | null,
    readonly validationErrors: DeliveryValidationErrors = {},
    readonly code: string | null = null,
    readonly details: DeliveryErrorDetails = {},
  ) {
    super(message);
    this.name = "DeliveryApiError";
  }

  get reason(): string | null {
    return this.details.reason ?? null;
  }
}

export function toDeliveryApiError(error: unknown): DeliveryApiError {
  if (!axios.isAxiosError<LaravelErrorResponse>(error)) {
    return new DeliveryApiError("Ocurrió un error inesperado.", null);
  }

  const status = error.response?.status ?? null;
  const response = error.response?.data;

  switch (status) {
    case 401:
      return new DeliveryApiError("Tu sesión venció. Inicia sesión nuevamente.", status);
    case 403:
      return new DeliveryApiError("No tienes permiso para preparar la entrega.", status);
    case 404:
      return new DeliveryApiError("No se encontró el registro solicitado.", status);
    case 409:
      return new DeliveryApiError(
        response?.message ?? "La preparación de entrega está bloqueada.",
        status,
        {},
        response?.code ?? null,
        response?.details ?? {},
      );
    case 422:
      return new DeliveryApiError(
        response?.message ?? "Revisa los datos de la entrega.",
        status,
        response?.errors ?? {},
        response?.code ?? null,
        response?.details ?? {},
      );
    default:
      if (!error.response) {
        return new DeliveryApiError("No se pudo conectar con el servidor. Inténtalo nuevamente.", null);
      }

      return new DeliveryApiError("No se pudo completar la solicitud. Inténtalo nuevamente.", status);
  }
}
