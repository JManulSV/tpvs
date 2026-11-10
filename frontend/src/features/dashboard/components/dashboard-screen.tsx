"use client";

import { Button } from "@/components/ui/button";
import { useDashboard } from "../hooks/use-dashboard";
import { DashboardHero } from "./dashboard-hero";
import { RecentActivity } from "./recent-activity";
import { StatusStrip } from "./status-strip";

type DashboardScreenProps = {
  role: "EDITOR" | "VIEWER";
  /** Computed on the server in the business time zone to avoid hydration mismatches. */
  greeting: string;
  firstName: string;
};

export function DashboardScreen({ role, greeting, firstName }: DashboardScreenProps) {
  const query = useDashboard();
  const summary = query.data;

  // With cached data, a failed background refetch keeps showing the last values.
  if (query.isError && !summary) {
    return (
      <div
        className="flex flex-col items-start gap-3 rounded-lg border border-border bg-card p-5 text-card-foreground shadow-card md:p-6"
        role="alert"
      >
        <p className="text-sm">No se pudo cargar el resumen del Dashboard.</p>
        <Button disabled={query.isFetching} onClick={() => void query.refetch()} type="button" variant="outline">
          {query.isFetching ? "Reintentando…" : "Reintentar"}
        </Button>
      </div>
    );
  }

  return (
    <div className="flex flex-col gap-4 md:gap-6">
      <DashboardHero firstName={firstName} greeting={greeting} role={role} summary={summary} />
      {role === "EDITOR" && (
        <StatusStrip counts={summary?.status_counts} period={summary?.period} />
      )}
      <RecentActivity items={summary?.recent_activity} />
    </div>
  );
}
