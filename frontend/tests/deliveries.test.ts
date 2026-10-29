import { AxiosError, type AxiosResponse } from "axios";
import { afterEach, describe, expect, it, vi } from "vitest";

vi.mock("@/lib/api-client", () => ({
  apiClient: {
    get: vi.fn(),
    put: vi.fn(),
  },
}));

import { apiClient } from "@/lib/api-client";
import { deliveryFormSchema } from "@/features/deliveries/schemas/delivery.schema";
import { deliveriesService } from "@/features/deliveries/services/deliveries.service";
import {
  DeliveryApiError,
  officialDenominationValues,
  type Delivery,
  type UpsertDeliveryInput,
} from "@/features/deliveries/types";

const apiClientMock = vi.mocked(apiClient);

const delivery: Delivery = {
  id: 3,
  record_id: 12,
  delivery_date: "2026-09-18",
  delivered_by_name: "Maria Lopez",
  received_by_name: "Juan Perez",
  prepared_return_amount: "9800.00",
  current_return_amount: "10000.00",
  is_stale: true,
  denominations: [
    { value: "1000.00", quantity: 5 },
    { value: "500.00", quantity: 5 },
    { value: "200.00", quantity: 10 },
    { value: "100.00", quantity: 3 },
  ],
  created_at: "2026-09-18T12:00:00.000000Z",
  updated_at: "2026-09-18T12:00:00.000000Z",
};

const upsertInput: UpsertDeliveryInput = {
  delivery_date: "2026-09-18",
  delivered_by_name: "Maria Lopez",
  received_by_name: null,
  denominations: [{ value: "1000.00", quantity: 10 }],
};

function axiosError(status: number, data: unknown = {}): AxiosError {
  const response = {
    status,
    statusText: "",
    headers: {},
    config: {},
    data,
  } as AxiosResponse;

  return new AxiosError("Request failed", "ERR_BAD_REQUEST", undefined, undefined, response);
}

afterEach(() => {
  apiClientMock.get.mockReset();
  apiClientMock.put.mockReset();
});

describe("deliveriesService contract", () => {
  it("GET returns the delivery from the record endpoint", async () => {
    apiClientMock.get.mockResolvedValueOnce({ data: { data: delivery } });

    await expect(deliveriesService.get(12)).resolves.toEqual(delivery);
    expect(apiClientMock.get).toHaveBeenCalledWith("records/12/delivery");
  });

  it("GET returns null when the record has no delivery", async () => {
    apiClientMock.get.mockResolvedValueOnce({ data: { data: null } });

    await expect(deliveriesService.get(12)).resolves.toBeNull();
  });

  it("PUT sends the input body and returns the saved delivery", async () => {
    apiClientMock.put.mockResolvedValueOnce({ data: { data: delivery } });

    await expect(deliveriesService.upsert(12, upsertInput)).resolves.toEqual(delivery);
    expect(apiClientMock.put).toHaveBeenCalledWith("records/12/delivery", upsertInput);
  });

  it("PUT treats an empty response body as an unexpected error", async () => {
    apiClientMock.put.mockResolvedValueOnce({ data: { data: null } });

    await expect(deliveriesService.upsert(12, upsertInput)).rejects.toMatchObject({
      name: "DeliveryApiError",
      message: "Ocurrió un error inesperado.",
    });
  });
});

describe("delivery API error mapping", () => {
  it("maps 409 CASH_MISMATCH to reason and cash details", async () => {
    apiClientMock.put.mockRejectedValueOnce(
      axiosError(409, {
        message: "El desglose no cuadra con el retorno.",
        code: "DELIVERY_PREPARATION_BLOCKED",
        details: {
          reason: "CASH_MISMATCH",
          expected_amount: "10000.00",
          cash_amount: "9800.00",
          difference: "-200.00",
        },
      }),
    );

    const error = await deliveriesService.upsert(12, upsertInput).catch((caught: unknown) => caught);

    expect(error).toBeInstanceOf(DeliveryApiError);
    expect(error).toMatchObject({
      status: 409,
      code: "DELIVERY_PREPARATION_BLOCKED",
      reason: "CASH_MISMATCH",
      details: {
        expected_amount: "10000.00",
        cash_amount: "9800.00",
        difference: "-200.00",
      },
    });
  });

  it("maps 409 INVALID_RECORD_STATE to its reason", async () => {
    apiClientMock.put.mockRejectedValueOnce(
      axiosError(409, {
        message: "El registro no está confirmado.",
        code: "DELIVERY_PREPARATION_BLOCKED",
        details: { reason: "INVALID_RECORD_STATE" },
      }),
    );

    const error = await deliveriesService.upsert(12, upsertInput).catch((caught: unknown) => caught);

    expect(error).toMatchObject({ status: 409, reason: "INVALID_RECORD_STATE" });
  });

  it("keeps per-field validation errors from 422 responses", async () => {
    apiClientMock.put.mockRejectedValueOnce(
      axiosError(422, {
        message: "Los datos no son válidos.",
        errors: {
          "denominations.0.value": ["La denominación no es oficial."],
        },
      }),
    );

    const error = await deliveriesService.upsert(12, upsertInput).catch((caught: unknown) => caught);

    expect(error).toMatchObject({
      status: 422,
      validationErrors: {
        "denominations.0.value": ["La denominación no es oficial."],
      },
    });
  });

  it.each([
    [401, "Tu sesión venció. Inicia sesión nuevamente."],
    [403, "No tienes permiso para preparar la entrega."],
    [404, "No se encontró el registro solicitado."],
  ])("maps HTTP %i to a fixed message", async (status, message) => {
    apiClientMock.put.mockRejectedValueOnce(axiosError(status));

    const error = await deliveriesService.upsert(12, upsertInput).catch((caught: unknown) => caught);

    expect(error).toMatchObject({ status, message });
  });

  it("maps a network failure without response to a connection message", async () => {
    apiClientMock.get.mockRejectedValueOnce(
      new AxiosError("Network Error", "ERR_NETWORK"),
    );

    const error = await deliveriesService.get(12).catch((caught: unknown) => caught);

    expect(error).toMatchObject({
      status: null,
      message: "No se pudo conectar con el servidor. Inténtalo nuevamente.",
    });
  });
});

describe("deliveryFormSchema", () => {
  const validValues = {
    delivery_date: "2026-09-18",
    delivered_by_name: "  Maria Lopez  ",
    received_by_name: "",
    denominations: officialDenominationValues.map((value) => ({ value, quantity: "0" })),
  };

  it("accepts a complete form and trims names", () => {
    const result = deliveryFormSchema.safeParse(validValues);

    expect(result.success).toBe(true);
    if (result.success) {
      expect(result.data.delivered_by_name).toBe("Maria Lopez");
    }
  });

  it("requires a valid delivery date", () => {
    const result = deliveryFormSchema.safeParse({ ...validValues, delivery_date: "" });

    expect(result.success).toBe(false);
    expect(result.error?.issues[0]?.path).toEqual(["delivery_date"]);
  });

  it("requires the deliverer name", () => {
    const result = deliveryFormSchema.safeParse({ ...validValues, delivered_by_name: "   " });

    expect(result.success).toBe(false);
    expect(result.error?.issues[0]?.path).toEqual(["delivered_by_name"]);
  });

  it("rejects an unofficial denomination value", () => {
    const denominations = [...validValues.denominations];
    denominations[0] = { value: "750.00" as never, quantity: "1" };

    expect(deliveryFormSchema.safeParse({ ...validValues, denominations }).success).toBe(false);
  });

  it.each(["-1", "1.5", "abc"])("rejects quantity %s", (quantity) => {
    const denominations = [...validValues.denominations];
    denominations[0] = { value: "1000.00", quantity };

    expect(deliveryFormSchema.safeParse({ ...validValues, denominations }).success).toBe(false);
  });

  it("requires all official denominations in the form", () => {
    const result = deliveryFormSchema.safeParse({
      ...validValues,
      denominations: validValues.denominations.slice(1),
    });

    expect(result.success).toBe(false);
  });
});
