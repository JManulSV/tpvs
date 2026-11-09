import { useQuery } from "@tanstack/react-query";
import { recordKeys } from "@/features/records/hooks/use-records";
import { dashboardService } from "../services/dashboard.service";

/**
 * Lives under `recordKeys.lists()` so every existing record mutation
 * (create, update, confirm, cancel, retry, image upload) invalidates it.
 */
export const dashboardKeys = {
  summary: [...recordKeys.lists(), "dashboard"] as const,
};

/**
 * No polling: refreshed on mount, record-list invalidation, and when the tab
 * regains focus (global default is off; staleTime still applies).
 */
export function useDashboard() {
  return useQuery({
    queryKey: dashboardKeys.summary,
    queryFn: () => dashboardService.get(),
    refetchOnWindowFocus: true,
  });
}
