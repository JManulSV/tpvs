import type { RecordStatus } from "@/lib/status";
import type { DashboardStatusCellId } from "./utils";

export type { DashboardStatusCellId };

export type DashboardStatusCounts = Record<DashboardStatusCellId, number>;

export type DashboardActivityItem = {
  id: number;
  company: { id: number; name: string } | null;
  year: number | null;
  week: number | null;
  status: RecordStatus;
  updated_at: string;
};

export type DashboardPeriod = {
  year: number;
  month: number;
  timezone: string;
};

export type DashboardSummary = {
  period: DashboardPeriod;
  /** Only present for EDITOR. */
  status_counts?: DashboardStatusCounts;
  month_summary: { confirmed_count: number; return_total: string };
  recent_activity: DashboardActivityItem[];
};

export type DashboardResponse = {
  data: DashboardSummary;
};
