import { apiClient } from "@/lib/api-client";
import { decodeBlobError, fileNameFrom } from "@/lib/file-download";
import {
  toReportsApiError,
  type ReportExcel,
  type ReportFilters,
  type ReportListParams,
  type ReportResponse,
} from "../types";

export const reportsService = {
  async get(params: ReportListParams): Promise<ReportResponse> {
    try {
      const response = await apiClient.get<ReportResponse>("reports", { params });

      return response.data;
    } catch (error) {
      throw toReportsApiError(error);
    }
  },

  /** The export always covers the full filtered set, so pagination is never sent. */
  async downloadExcel(filters: ReportFilters): Promise<ReportExcel> {
    try {
      const response = await apiClient.get<Blob>("documents/excel", {
        params: {
          year: filters.year,
          month: filters.month,
          week: filters.week,
          company_id: filters.company_id,
        },
        responseType: "blob",
      });

      return {
        blob: response.data,
        fileName: fileNameFrom(response.headers["content-disposition"], `Reporte_${filters.year}.xlsx`),
      };
    } catch (error) {
      throw toReportsApiError(await decodeBlobError(error));
    }
  },
};
