import { afterEach, describe, expect, it, vi } from "vitest";

vi.mock("@/lib/api-client", () => ({
  apiClient: {
    get: vi.fn(),
    post: vi.fn(),
    put: vi.fn(),
  },
}));

import { apiClient } from "@/lib/api-client";
import { manualRecordFormSchema, updateManualRecordFormSchema } from "@/features/records/schemas/record.schema";
import { recordsService } from "@/features/records/services/records.service";
import { RecordsApiError } from "@/features/records/types";

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
  affiliation: "AFF-123",
  branch: "Guadalajara",
  details: [
    {
      operation_number: "OP-100",
      income: "100.00",
      commission_percentage: "5.00",
    },
  ],
};

describe("manual record schemas", () => {
  it("accepts exact ISO Monday-Sunday dates and one commission mode", () => {
    expect(manualRecordFormSchema.safeParse(validRecord).success).toBe(true);
    expect(updateManualRecordFormSchema.safeParse({ ...validRecord, reason: "Correction" }).success).toBe(true);
    expect(updateManualRecordFormSchema.safeParse({ ...validRecord, reason: "  " }).success).toBe(false);
  });

  it("rejects invalid ISO dates and financial inputs", () => {
    expect(manualRecordFormSchema.safeParse({
      ...validRecord,
      week_start_date: "2026-09-15",
    }).success).toBe(false);
    expect(manualRecordFormSchema.safeParse({
      ...validRecord,
      details: [{ ...validRecord.details[0], commission: "10.00" }],
    }).success).toBe(false);
    expect(manualRecordFormSchema.safeParse({
      ...validRecord,
      details: [{ operation_number: "OP-100", income: "10.00", commission: "10.01" }],
    }).success).toBe(false);
  });

  it("accepts a week crossing the calendar-year boundary when its ISO year is correct", () => {
    expect(manualRecordFormSchema.safeParse({
      ...validRecord,
      year: 2020,
      week: 53,
      week_start_date: "2020-12-28",
      week_end_date: "2021-01-03",
    }).success).toBe(true);
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
});
