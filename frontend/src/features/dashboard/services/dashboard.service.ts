import { apiClient } from "@/lib/api-client";
import type { DashboardResponse, DashboardSummary } from "../types";

export const dashboardService = {
  async get(): Promise<DashboardSummary> {
    const response = await apiClient.get<DashboardResponse>("dashboard");

    return response.data.data;
  },
};
