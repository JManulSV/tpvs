import Link from "next/link";
import { Skeleton } from "@/components/ui/skeleton";
import type { DashboardPeriod, DashboardStatusCellId, DashboardStatusCounts } from "../types";
import { getStatusCellHref } from "../utils";

type StatusCell = {
  id: DashboardStatusCellId;
  label: string;
  dotClass: string;
};

const cells: readonly StatusCell[] = [
  { id: "needs_review", label: "Por revisar", dotClass: "bg-status-needs-review" },
  { id: "error", label: "Con error", dotClass: "bg-status-error" },
  { id: "pending", label: "Pendientes", dotClass: "bg-status-pending" },
  { id: "processing", label: "Procesando", dotClass: "bg-status-processing" },
  { id: "confirmed_month", label: "Confirmados del mes", dotClass: "bg-status-confirmed" },
  { id: "canceled_month", label: "Cancelados del mes", dotClass: "bg-status-canceled" },
];

const cellClass =
  "flex min-h-[84px] flex-col gap-1.5 bg-card p-4 text-card-foreground md:min-h-0 md:gap-2 md:p-5";

type StatusStripProps = {
  /** Undefined while loading. */
  counts: DashboardStatusCounts | undefined;
  period: Pick<DashboardPeriod, "year" | "month"> | undefined;
};

export function StatusStrip({ counts, period }: StatusStripProps) {
  return (
    <section
      aria-busy={counts === undefined}
      aria-label="Registros por estado"
      className="grid grid-cols-2 gap-px overflow-hidden rounded-lg border border-border bg-border shadow-card md:grid-cols-[repeat(auto-fit,minmax(150px,1fr))]"
    >
      {cells.map((cell) => {
        const label = (
          <span className="flex items-center gap-2 text-xs font-semibold leading-4 text-muted-foreground">
            <span aria-hidden="true" className={`size-2.5 shrink-0 rounded-full ${cell.dotClass}`} />
            {cell.label}
          </span>
        );

        if (counts === undefined || period === undefined) {
          return (
            <div className={cellClass} key={cell.id}>
              {label}
              <Skeleton className="h-9 w-12" />
            </div>
          );
        }

        const count = counts[cell.id];

        return (
          <Link
            aria-label={`${cell.label}: ${count}. Ver registros`}
            className={`${cellClass} transition-colors hover:bg-muted focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-ring`}
            href={getStatusCellHref(cell.id, period)}
            key={cell.id}
          >
            {label}
            <span className="text-[28px] font-bold leading-9 tracking-[-0.02em] tabular-nums">{count}</span>
          </Link>
        );
      })}
    </section>
  );
}
