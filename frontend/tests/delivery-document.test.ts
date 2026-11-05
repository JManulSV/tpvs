import { AxiosError, type AxiosResponse } from "axios";
import { afterEach, describe, expect, it, vi } from "vitest";

vi.mock("@/lib/api-client", () => ({
  apiClient: {
    get: vi.fn(),
    put: vi.fn(),
    post: vi.fn(),
  },
}));

import { apiClient } from "@/lib/api-client";
import { fieldLabel } from "@/features/records/components/record-history-panel";
import { deliveriesService } from "@/features/deliveries/services/deliveries.service";
import { documentBlockedMessages, type Delivery } from "@/features/deliveries/types";
import { documentBlockedReason, documentReturnOptions } from "@/features/deliveries/utils";

const apiClientMock = vi.mocked(apiClient);

const delivery: Delivery = {
  id: 3,
  record_id: 12,
  delivery_date: "2026-09-18",
  delivered_by_name: "Maria Lopez",
  received_by_name: null,
  prepared_return_amount: "9800.00",
  current_return_amount: "9800.00",
  is_stale: false,
  denominations: [{ value: "1000.00", quantity: 9 }],
  created_at: null,
  updated_at: null,
};

function blobError(status: number, body: unknown): AxiosError {
  const response = {
    status,
    statusText: "",
    headers: {},
    config: {},
    data: new Blob([JSON.stringify(body)], { type: "application/json" }),
  } as AxiosResponse;

  return new AxiosError("Request failed", "ERR_BAD_REQUEST", undefined, undefined, response);
}

afterEach(() => {
  apiClientMock.post.mockReset();
});

describe("deliveriesService.generateDocument", () => {
  const documentInput = {
    document_return_amount: "920.50",
    customer_will_give_change: true,
  };

  it("posts the ephemeral document options, requests a blob, and reads Content-Disposition", async () => {
    const blob = new Blob(["docx"]);
    apiClientMock.post.mockResolvedValueOnce({
      data: blob,
      headers: { "content-disposition": 'attachment; filename="entrega-registro-12-semana-38-2026.docx"' },
    });

    const result = await deliveriesService.generateDocument(3, documentInput);

    expect(apiClientMock.post).toHaveBeenCalledWith("deliveries/3/document", documentInput, { responseType: "blob" });
    expect(result.blob).toBe(blob);
    expect(result.fileName).toBe("entrega-registro-12-semana-38-2026.docx");
  });

  it("reads an unquoted file name", async () => {
    apiClientMock.post.mockResolvedValueOnce({
      data: new Blob(["docx"]),
      headers: { "content-disposition": "attachment; filename=entrega-registro-12-semana-38-2026.docx" },
    });

    await expect(deliveriesService.generateDocument(3, documentInput)).resolves.toMatchObject({
      fileName: "entrega-registro-12-semana-38-2026.docx",
    });
  });

  it("falls back to a generic file name when the header is missing", async () => {
    apiClientMock.post.mockResolvedValueOnce({ data: new Blob(["docx"]), headers: {} });

    await expect(deliveriesService.generateDocument(3, documentInput)).resolves.toMatchObject({ fileName: "entrega-3.docx" });
  });

  it.each(Object.entries(documentBlockedMessages))(
    "maps the blocked reason %s from a Blob error body to a Spanish message",
    async (reason, message) => {
      apiClientMock.post.mockRejectedValueOnce(
        blobError(409, {
          message: "The delivery document cannot be generated.",
          code: "DOCUMENT_GENERATION_BLOCKED",
          details: { reason },
        }),
      );

      const error = await deliveriesService.generateDocument(3, documentInput).catch((caught: unknown) => caught);

      expect(error).toMatchObject({ status: 409, code: "DOCUMENT_GENERATION_BLOCKED", reason, message });
    },
  );

  it("keeps the stale amounts reported by the backend", async () => {
    apiClientMock.post.mockRejectedValueOnce(
      blobError(409, {
        code: "DOCUMENT_GENERATION_BLOCKED",
        details: { reason: "STALE_DELIVERY", prepared_amount: "9800.00", current_amount: "10000.00" },
      }),
    );

    const error = await deliveriesService.generateDocument(3, documentInput).catch((caught: unknown) => caught);

    expect(error).toMatchObject({ details: { prepared_amount: "9800.00", current_amount: "10000.00" } });
  });

  it.each([
    [401, "Tu sesión venció. Inicia sesión nuevamente."],
    [403, "No tienes permiso para preparar la entrega."],
    [404, "No se encontró el registro solicitado."],
  ])("maps HTTP %i from a Blob error body to a fixed message", async (status, message) => {
    apiClientMock.post.mockRejectedValueOnce(blobError(status, { message: "ignored" }));

    const error = await deliveriesService.generateDocument(3, documentInput).catch((caught: unknown) => caught);

    expect(error).toMatchObject({ status, message });
  });

  it("survives an error body that is not JSON", async () => {
    const response = {
      status: 500,
      statusText: "",
      headers: {},
      config: {},
      data: new Blob(["<html>boom</html>"]),
    } as AxiosResponse;
    apiClientMock.post.mockRejectedValueOnce(
      new AxiosError("Request failed", "ERR_BAD_RESPONSE", undefined, undefined, response),
    );

    const error = await deliveriesService.generateDocument(3, documentInput).catch((caught: unknown) => caught);

    expect(error).toMatchObject({
      status: 500,
      message: "No se pudo completar la solicitud. Inténtalo nuevamente.",
    });
  });
});

describe("documentBlockedReason", () => {
  it("allows a confirmed, current delivery with an image", () => {
    expect(documentBlockedReason({ delivery, hasImage: true, recordStatus: "CONFIRMED" })).toBeNull();
  });

  it("explains the missing delivery first", () => {
    expect(documentBlockedReason({ delivery: null, hasImage: false, recordStatus: "NEEDS_REVIEW" })).toBe(
      "Prepara la entrega para generar el documento.",
    );
  });

  it("explains a record that is not confirmed", () => {
    expect(documentBlockedReason({ delivery, hasImage: true, recordStatus: "NEEDS_REVIEW" })).toBe(
      documentBlockedMessages.INVALID_RECORD_STATE,
    );
  });

  it("asks for an image before reporting a stale delivery, matching the backend order", () => {
    expect(
      documentBlockedReason({ delivery: { ...delivery, is_stale: true }, hasImage: false, recordStatus: "CONFIRMED" }),
    ).toBe(documentBlockedMessages.MISSING_IMAGE);
  });

  it("blocks a stale delivery", () => {
    expect(
      documentBlockedReason({ delivery: { ...delivery, is_stale: true }, hasImage: true, recordStatus: "CONFIRMED" }),
    ).toBe(documentBlockedMessages.STALE_DELIVERY);
  });
});

describe("documentReturnOptions", () => {
  it("offers the same-peso .00, same-peso .50 and next-peso .00 values", () => {
    expect(documentReturnOptions("1298.57")).toEqual(["1298.00", "1298.50", "1299.00"]);
    expect(documentReturnOptions("1298.99")).toEqual(["1298.00", "1298.50", "1299.00"]);
    expect(documentReturnOptions("0.50")).toEqual(["0.00", "0.50", "1.00"]);
  });
});

describe("history labels", () => {
  it("labels the document generation event", () => {
    expect(fieldLabel("document_generated")).toBe("Documento Word generado");
  });

  it("keeps labelling delivery fields and denominations", () => {
    expect(fieldLabel("delivered_by_name")).toBe("Entrega realizada por");
    expect(fieldLabel("denominations[1000.00]")).toContain("Denominación");
  });
});
