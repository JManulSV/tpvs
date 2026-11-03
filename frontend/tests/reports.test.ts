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
import { reportKeys } from "@/features/reports/hooks/use-reports";
import { reportsService } from "@/features/reports/services/reports.service";
import { ReportsApiError, type ReportResponse } from "@/features/reports/types";
import {
  excelBlockedReason,
  formatDate,
  formatMoney,
  monthName,
  parseReportSearch,
  toReportSearchParams,
  yearOptions,
} from "@/features/reports/utils";

const apiClientMock = vi.mocked(apiClient);

const report: ReportResponse = {
  data: {
    filters: { year: 2026, month: 9, week: 38, company_id: null },
    period: { week_start_date: "2026-08-28", week_end_date: "2026-09-15" },
    totals: { records_count: 2, income_total: "150000.00", commission_total: "3000.00", return_total: "147000.00" },
    companies: [],
    records: [],
  },
  meta: { current_page: 1, per_page: 15, total: 2, last_page: 1 },
};

function axiosError(status: number, body: unknown, asBlob = false): AxiosError {
  const response = {
    status,
    statusText: "",
    headers: {},
    config: {},
    data: asBlob ? new Blob([typeof body === "string" ? body : JSON.stringify(body)], { type: "application/json" }) : body,
  } as AxiosResponse;

  return new AxiosError("Request failed", "ERR_BAD_REQUEST", undefined, undefined, response);
}

function excelResponse(contentDisposition?: string): AxiosResponse<Blob> {
  return {
    data: new Blob(["xlsx"]),
    status: 200,
    statusText: "OK",
    headers: contentDisposition ? { "content-disposition": contentDisposition } : {},
    config: {},
  } as AxiosResponse<Blob>;
}

afterEach(() => {
  apiClientMock.get.mockReset();
});

describe("reportsService.get", () => {
  it("requests the report with the filters and pagination as query parameters", async () => {
    apiClientMock.get.mockResolvedValue({ data: report });

    const result = await reportsService.get({ year: 2026, month: 9, week: 38, company_id: 7, page: 2, per_page: 15 });

    expect(result).toEqual(report);
    expect(apiClientMock.get).toHaveBeenCalledWith("reports", {
      params: { year: 2026, month: 9, week: 38, company_id: 7, page: 2, per_page: 15 },
    });
  });

  it("maps a 422 to its message and field errors", async () => {
    apiClientMock.get.mockRejectedValue(axiosError(422, { message: "The year field is required.", errors: { year: ["The year field is required."] } }));

    const error = await reportsService.get({ year: 2026 }).catch((e: unknown) => e);

    expect(error).toBeInstanceOf(ReportsApiError);
    expect(error).toMatchObject({ status: 422, message: "The year field is required.", validationErrors: { year: ["The year field is required."] } });
  });

  it.each([
    [401, "Tu sesión venció. Inicia sesión nuevamente."],
    [403, "No tienes permiso para consultar los reportes."],
    [500, "No se pudo completar la solicitud. Inténtalo nuevamente."],
  ])("maps a %i to a Spanish message", async (status, message) => {
    apiClientMock.get.mockRejectedValue(axiosError(status, { message: "raw backend text" }));

    await expect(reportsService.get({ year: 2026 })).rejects.toMatchObject({ status, message });
  });

  it("maps a network failure without a status", async () => {
    apiClientMock.get.mockRejectedValue(new AxiosError("Network Error", "ERR_NETWORK"));

    await expect(reportsService.get({ year: 2026 })).rejects.toMatchObject({
      status: null,
      message: "No se pudo conectar con el servidor. Inténtalo nuevamente.",
    });
  });
});

describe("reportsService.downloadExcel", () => {
  it("requests the binary with the filters only and never with pagination", async () => {
    apiClientMock.get.mockResolvedValue(excelResponse('attachment; filename="Reporte_Semana_38_2026.xlsx"'));

    const result = await reportsService.downloadExcel({ year: 2026, month: 9, week: 38, company_id: 7 });

    expect(apiClientMock.get).toHaveBeenCalledWith("documents/excel", {
      params: { year: 2026, month: 9, week: 38, company_id: 7 },
      responseType: "blob",
    });
    expect(apiClientMock.get.mock.calls[0]?.[1]?.params).not.toHaveProperty("page");
    expect(apiClientMock.get.mock.calls[0]?.[1]?.params).not.toHaveProperty("per_page");
    expect(result.blob).toBeInstanceOf(Blob);
    expect(result.fileName).toBe("Reporte_Semana_38_2026.xlsx");
  });

  it("reads quoted and unquoted file names from Content-Disposition", async () => {
    apiClientMock.get.mockResolvedValueOnce(excelResponse('attachment; filename="Reporte_2026_Mes_09.xlsx"'));
    apiClientMock.get.mockResolvedValueOnce(excelResponse("attachment; filename=Reporte_2026.xlsx"));

    await expect(reportsService.downloadExcel({ year: 2026, month: 9 })).resolves.toMatchObject({ fileName: "Reporte_2026_Mes_09.xlsx" });
    await expect(reportsService.downloadExcel({ year: 2026 })).resolves.toMatchObject({ fileName: "Reporte_2026.xlsx" });
  });

  it("falls back to a name built from the year when the header is missing", async () => {
    apiClientMock.get.mockResolvedValue(excelResponse());

    await expect(reportsService.downloadExcel({ year: 2025 })).resolves.toMatchObject({ fileName: "Reporte_2025.xlsx" });
  });

  it("decodes a Blob error body so a 422 is rendered as an error and not as a file", async () => {
    apiClientMock.get.mockRejectedValue(
      axiosError(422, { message: "The company id is invalid.", errors: { company_id: ["The selected company id is invalid."] } }, true),
    );

    const error = await reportsService.downloadExcel({ year: 2026, company_id: 999 }).catch((e: unknown) => e);

    expect(error).toBeInstanceOf(ReportsApiError);
    expect(error).toMatchObject({ status: 422, validationErrors: { company_id: ["The selected company id is invalid."] } });
  });

  it("maps 401 and 500 Blob errors and tolerates a non-JSON body", async () => {
    apiClientMock.get.mockRejectedValueOnce(axiosError(401, { message: "Unauthenticated." }, true));
    apiClientMock.get.mockRejectedValueOnce(axiosError(500, { message: "The Excel report could not be generated." }, true));
    apiClientMock.get.mockRejectedValueOnce(axiosError(500, "<html>boom</html>", true));

    await expect(reportsService.downloadExcel({ year: 2026 })).rejects.toMatchObject({ status: 401, message: "Tu sesión venció. Inicia sesión nuevamente." });
    await expect(reportsService.downloadExcel({ year: 2026 })).rejects.toMatchObject({
      status: 500,
      message: "No se pudo completar la solicitud. Inténtalo nuevamente.",
    });
    await expect(reportsService.downloadExcel({ year: 2026 })).rejects.toMatchObject({ status: 500 });
  });
});

describe("parseReportSearch", () => {
  const now = new Date(2026, 9, 30);

  it("defaults to the current year, page 1, and no other filter", () => {
    expect(parseReportSearch(new URLSearchParams(), now)).toEqual({ year: 2026, page: 1 });
  });

  it("reads every filter and the page from the URL", () => {
    const params = new URLSearchParams("year=2025&month=9&week=38&company_id=7&page=3");

    expect(parseReportSearch(params, now)).toEqual({ year: 2025, month: 9, week: 38, company_id: 7, page: 3 });
  });

  it("drops invalid or out-of-range values instead of sending them to the API", () => {
    const params = new URLSearchParams("year=abc&month=13&week=54&company_id=0&page=-2");

    expect(parseReportSearch(params, now)).toEqual({ year: 2026, page: 1 });
    expect(parseReportSearch(new URLSearchParams("month=0&week=1.5&company_id=x"), now)).toEqual({ year: 2026, page: 1 });
  });
});

describe("toReportSearchParams", () => {
  it("always includes the year, omits empty filters, and leaves page 1 implicit", () => {
    expect(toReportSearchParams({ year: 2026 }).toString()).toBe("year=2026");
    expect(toReportSearchParams({ year: 2026, month: 9, week: 38, company_id: 7 }).toString()).toBe(
      "year=2026&month=9&week=38&company_id=7",
    );
    expect(toReportSearchParams({ year: 2026 }, 3).toString()).toBe("year=2026&page=3");
  });

  it("round-trips through the parser", () => {
    const filters = { year: 2025, month: 12, week: 53, company_id: 4 };

    expect(parseReportSearch(toReportSearchParams(filters, 2))).toEqual({ ...filters, page: 2 });
  });
});

describe("report formatting", () => {
  it("formats amounts as Mexican pesos from the backend decimal strings", () => {
    expect(formatMoney("150000.00")).toBe("$150,000.00");
    expect(formatMoney("0.00")).toBe("$0.00");
  });

  it("formats Y-m-d dates without shifting the day by time zone", () => {
    expect(formatDate("2026-09-01")).toMatch(/^1 sep\.? 2026$/);
    expect(formatDate("2026-12-31")).toMatch(/^31 dic\.? 2026$/);
    expect(formatDate(null)).toBe("—");
  });

  it("names months in Spanish", () => {
    expect(monthName(1)).toBe("enero");
    expect(monthName(9)).toBe("septiembre");
  });

  it("offers a year range around today and keeps an out-of-range selection", () => {
    const now = new Date(2026, 9, 30);

    expect(yearOptions(2026, now)).toEqual([2027, 2026, 2025, 2024, 2023, 2022, 2021, 2020, 2019, 2018]);
    expect(yearOptions(2010, now)).toContain(2010);
    expect(yearOptions(2010, now)[yearOptions(2010, now).length - 1]).toBe(2010);
  });
});

describe("excelBlockedReason", () => {
  it("blocks while the report has not loaded", () => {
    expect(excelBlockedReason({ isSuccess: false, recordsCount: null })).toBe(
      "Espera a que se cargue el reporte para generar el Excel.",
    );
  });

  it("blocks an empty result", () => {
    expect(excelBlockedReason({ isSuccess: true, recordsCount: 0 })).toBe(
      "No hay registros confirmados para exportar con estos filtros.",
    );
  });

  it("allows an export when there are records", () => {
    expect(excelBlockedReason({ isSuccess: true, recordsCount: 3 })).toBeNull();
  });
});

describe("reportKeys", () => {
  it("keys the cache by the filters and the page", () => {
    expect(reportKeys.list({ year: 2026, page: 1 })).not.toEqual(reportKeys.list({ year: 2026, page: 2 }));
    expect(reportKeys.list({ year: 2026, page: 1 })).toEqual(reportKeys.list({ year: 2026, page: 1 }));
    expect(reportKeys.list({ year: 2026 })[0]).toBe("reports");
  });
});
