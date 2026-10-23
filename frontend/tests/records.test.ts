import { afterEach, describe, expect, it, vi } from "vitest";

vi.mock("@/lib/api-client", () => ({
  apiClient: {
    get: vi.fn(),
    post: vi.fn(),
    put: vi.fn(),
  },
}));

import { apiClient } from "@/lib/api-client";
import { getRecordActionPermissions } from "@/features/records/record-actions";
import { manualRecordFormSchema, updateManualRecordFormSchema } from "@/features/records/schemas/record.schema";
import { recordsService } from "@/features/records/services/records.service";
import { RecordsApiError, type RecordSource, type RecordStatus } from "@/features/records/types";

const apiClientMock = vi.mocked(apiClient);

afterEach(() => {
  vi.resetAllMocks();
});

const validRecord = {
  company_id: 1,
  year: 2026,
  week: 38,
  week_start_date: "2026-09-14",
  week_end_date: "2026-09-20",
  details: [
    {
      affiliation: "AFF-123",
      branch: "Guadalajara",
      income: "100.00",
      commission_percentage: "5.00",
    },
  ],
};

describe("manual record schemas", () => {
  it("accepts business periods and one commission mode", () => {
    expect(manualRecordFormSchema.safeParse(validRecord).success).toBe(true);
    expect(updateManualRecordFormSchema.safeParse({ ...validRecord, reason: "Correction" }).success).toBe(true);
    expect(updateManualRecordFormSchema.safeParse({ ...validRecord, reason: "  " }).success).toBe(false);
    expect(updateManualRecordFormSchema.safeParse({ ...validRecord, reason: "x".repeat(2000) }).success).toBe(true);
    expect(updateManualRecordFormSchema.safeParse({ ...validRecord, reason: "x".repeat(2001) }).success).toBe(false);
  });

  it("rejects inverted date ranges and invalid financial inputs", () => {
    expect(manualRecordFormSchema.safeParse({
      ...validRecord,
      week_start_date: "2026-09-21",
      week_end_date: "2026-09-20",
    }).success).toBe(false);
    expect(manualRecordFormSchema.safeParse({
      ...validRecord,
      details: [{ ...validRecord.details[0], commission: "10.00" }],
    }).success).toBe(false);
    expect(manualRecordFormSchema.safeParse({
      ...validRecord,
      details: [{ affiliation: "AFF-123", branch: "Guadalajara", income: "10.00", commission: "10.01" }],
    }).success).toBe(false);
  });

  it("accepts week 53 with custom dates without ISO interpretation", () => {
    expect(manualRecordFormSchema.safeParse({
      ...validRecord,
      year: 2025,
      week: 53,
      week_start_date: "2025-09-17",
      week_end_date: "2025-09-25",
    }).success).toBe(true);
  });

  it("requires affiliation and branch on every manual movement", () => {
    expect(manualRecordFormSchema.safeParse({
      ...validRecord,
      details: [{ ...validRecord.details[0], affiliation: "", branch: "" }],
    }).success).toBe(false);
  });
});

describe("records service", () => {
  it("requests filtered list and record detail through the same-origin client", async () => {
    const listResponse = { data: [], links: {}, meta: {} };
    const record = { id: 9, status: "NEEDS_REVIEW" };
    apiClientMock.get
      .mockResolvedValueOnce({ data: listResponse })
      .mockResolvedValueOnce({ data: { data: record } });

    await expect(recordsService.list({ search: "OP-1", year: 2026, page: 2 })).resolves.toEqual(listResponse);
    expect(apiClientMock.get).toHaveBeenNthCalledWith(1, "records", {
      params: { search: "OP-1", year: 2026, page: 2 },
    });

    await expect(recordsService.get(9)).resolves.toEqual(record);
    expect(apiClientMock.get).toHaveBeenNthCalledWith(2, "records/9");
  });

  it("sends manual creation, full replacement and explicit confirmation contracts", async () => {
    const record = { id: 9, status: "NEEDS_REVIEW" };
    apiClientMock.post.mockResolvedValueOnce({ data: { data: record } });
    apiClientMock.put.mockResolvedValueOnce({ data: { data: record } });
    apiClientMock.post.mockResolvedValueOnce({ data: { data: { ...record, status: "CONFIRMED" } } });

    await expect(recordsService.create(validRecord)).resolves.toEqual(record);
    expect(apiClientMock.post).toHaveBeenNthCalledWith(1, "records", validRecord);

    const updateInput = { ...validRecord, reason: "Correction" };
    await expect(recordsService.update(9, updateInput)).resolves.toEqual(record);
    expect(apiClientMock.put).toHaveBeenCalledWith("records/9", updateInput);

    await expect(recordsService.confirm(9)).resolves.toEqual({ ...record, status: "CONFIRMED" });
    expect(apiClientMock.post).toHaveBeenNthCalledWith(2, "records/9/confirm", {});
  });

  it("preserves Laravel validation and domain transition details", async () => {
    apiClientMock.post.mockRejectedValueOnce({
      isAxiosError: true,
      response: {
        status: 422,
        data: { message: "Invalid record", errors: { "details.0.income": ["Required"] } },
      },
    });

    await expect(recordsService.create(validRecord)).rejects.toMatchObject({
      status: 422,
      validationErrors: { "details.0.income": ["Required"] },
    });

    apiClientMock.post.mockRejectedValueOnce({
      isAxiosError: true,
      response: {
        status: 409,
        data: {
          message: "Cannot confirm",
          code: "RECORD_CONFIRMATION_BLOCKED",
          details: { reason: "INVALID_STATE" },
        },
      },
    });

    const confirmation = recordsService.confirm(9);
    await expect(confirmation).rejects.toBeInstanceOf(RecordsApiError);
    await expect(confirmation).rejects.toMatchObject({ status: 409 });
  });

  it("uploads the image with multipart form data", async () => {
    const file = new File(["image-bytes"], "capture.png", { type: "image/png" });
    const record = { id: 11, status: "PENDING" };
    apiClientMock.post.mockResolvedValueOnce({ data: { data: record } });

    await expect(recordsService.createFromImage(file)).resolves.toEqual(record);

    const [url, body] = apiClientMock.post.mock.calls[0];
    expect(url).toBe("records/from-image");
    expect(body).toBeInstanceOf(FormData);
    expect((body as FormData).get("image")).toBe(file);
  });

  it("sends cancellation with a reason and retries AI processing", async () => {
    const record = { id: 9, status: "CANCELED" };
    apiClientMock.post.mockResolvedValueOnce({ data: { data: record } });
    apiClientMock.post.mockResolvedValueOnce({ data: { data: { ...record, status: "PROCESSING" } } });

    const cancelInput = { reason: "La imagen no es legible" };
    await expect(recordsService.cancel(9, cancelInput)).resolves.toEqual(record);
    expect(apiClientMock.post).toHaveBeenNthCalledWith(1, "records/9/cancel", cancelInput);

    await expect(recordsService.retryAiProcessing(9)).resolves.toEqual({ ...record, status: "PROCESSING" });
    expect(apiClientMock.post).toHaveBeenNthCalledWith(2, "records/9/ai-processing/retry", {});
  });

  it("uses the authorized BFF path for the record image", () => {
    expect(recordsService.imageUrl(9)).toBe("/api/records/9/image");
  });

  it("maps forbidden actions to a permission error", async () => {
    apiClientMock.post.mockRejectedValueOnce({
      isAxiosError: true,
      response: { status: 403, data: { message: "Forbidden" } },
    });

    await expect(recordsService.cancel(9, { reason: "No aplica" })).rejects.toMatchObject({
      status: 403,
      message: "No tienes permiso para realizar esta acción.",
    });
  });
});

describe("record action permissions", () => {
  function record(status: RecordStatus, source: RecordSource = "MANUAL") {
    return { status, source };
  }

  it("lets an editor correct, confirm and cancel a record in review", () => {
    expect(getRecordActionPermissions(record("NEEDS_REVIEW"), true)).toEqual({
      canCorrect: true,
      canConfirm: true,
      canCancel: true,
      canRetryAiProcessing: false,
    });
  });

  it("lets an editor retry or cancel an IMAGE record in error", () => {
    expect(getRecordActionPermissions(record("ERROR", "IMAGE"), true)).toEqual({
      canCorrect: false,
      canConfirm: false,
      canCancel: true,
      canRetryAiProcessing: true,
    });
  });

  it("does not retry AI for a MANUAL record in error", () => {
    expect(getRecordActionPermissions(record("ERROR", "MANUAL"), true)).toEqual({
      canCorrect: false,
      canConfirm: false,
      canCancel: true,
      canRetryAiProcessing: false,
    });
  });

  it.each<RecordStatus>(["PENDING", "PROCESSING", "CONFIRMED", "CANCELED"])(
    "offers no mutations for an editor when the record is %s",
    (status) => {
      expect(getRecordActionPermissions(record(status), true)).toEqual({
        canCorrect: false,
        canConfirm: false,
        canCancel: false,
        canRetryAiProcessing: false,
      });
    },
  );

  it("offers no mutations to a viewer in any state", () => {
    for (const status of ["PENDING", "PROCESSING", "NEEDS_REVIEW", "ERROR", "CONFIRMED", "CANCELED"] as const) {
      expect(getRecordActionPermissions(record(status), false)).toEqual({
        canCorrect: false,
        canConfirm: false,
        canCancel: false,
        canRetryAiProcessing: false,
      });
    }

    expect(getRecordActionPermissions(null, true)).toEqual({
      canCorrect: false,
      canConfirm: false,
      canCancel: false,
      canRetryAiProcessing: false,
    });
  });
});
