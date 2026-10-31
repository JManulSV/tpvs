import { keepPreviousData, useMutation, useQuery } from "@tanstack/react-query";
import { downloadBlob } from "@/lib/file-download";
import { reportsService } from "../services/reports.service";
import type { ReportFilters, ReportListParams, ReportSearch } from "../types";
import { REPORT_PAGE_SIZE } from "../utils";

export const reportKeys = {
  all: ["reports"] as const,
  lists: () => [...reportKeys.all, "list"] as const,
  list: (params: ReportListParams) => [...reportKeys.lists(), params] as const,
};

export function useReport(search: ReportSearch) {
  const params: ReportListParams = { ...search, per_page: REPORT_PAGE_SIZE };

  return useQuery({
    queryKey: reportKeys.list(params),
    queryFn: () => reportsService.get(params),
    placeholderData: keepPreviousData,
  });
}

export function useDownloadReportExcel() {
  return useMutation({
    mutationFn: (filters: ReportFilters) => reportsService.downloadExcel(filters),
    retry: false,
    onSuccess: ({ blob, fileName }) => {
      downloadBlob(blob, fileName);
    },
  });
}
