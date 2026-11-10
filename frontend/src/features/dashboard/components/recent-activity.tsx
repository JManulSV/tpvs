"use client";

import Link from "next/link";
import { ChevronRight } from "lucide-react";
import { StatusBadge } from "@/components/status-badge";
import { Skeleton } from "@/components/ui/skeleton";
import { formatDateTime } from "@/lib/format";
import type { DashboardActivityItem } from "../types";
import { formatRelativeTime } from "../utils";

const HEADING_ID = "dashboard-activity";
const SKELETON_ROWS = 4;

type RecentActivityProps = {
  /** Undefined while loading. */
  items: DashboardActivityItem[] | undefined;
};

export function RecentActivity({ items }: RecentActivityProps) {
  // Activity is fetched on the client, so relative times never meet server HTML.
  const now = new Date();

  return (
    <section
      aria-busy={items === undefined}
      aria-labelledby={HEADING_ID}
      className="overflow-hidden rounded-lg border border-border bg-card text-card-foreground shadow-card"
    >
      <div className="flex items-center justify-between gap-3 px-5 pt-2 md:px-6 md:pb-4 md:pt-5">
        <h2 className="text-base font-semibold leading-6" id={HEADING_ID}>
          Actividad reciente
        </h2>
        <Link
          className="inline-flex min-h-11 items-center gap-1 rounded-sm text-sm font-semibold text-accent-foreground hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring md:min-h-0"
          href="/records"
        >
          <span className="hidden md:inline">Ver registros</span>
          <span className="md:hidden">Ver todo</span>
          <ChevronRight aria-hidden="true" className="size-4" />
        </Link>
      </div>

      {items === undefined ? (
        <ActivitySkeleton />
      ) : items.length === 0 ? (
        <p className="px-5 pb-5 pt-2 text-sm text-muted-foreground md:px-6 md:pt-0">
          Todavía no hay actividad.
        </p>
      ) : (
        <>
          <ActivityTable items={items} now={now} />
          <ActivityList items={items} now={now} />
        </>
      )}
    </section>
  );
}

function CompanyName({ item }: { item: DashboardActivityItem }) {
  return item.company ? (
    <>{item.company.name}</>
  ) : (
    <span className="text-muted-foreground">Sin empresa</span>
  );
}

function ActivityTime({ item, now }: { item: DashboardActivityItem; now: Date }) {
  return (
    <time dateTime={item.updated_at} title={formatDateTime(item.updated_at)}>
      {formatRelativeTime(item.updated_at, now)}
    </time>
  );
}

const thClass =
  "border-y border-border bg-muted px-6 py-2.5 text-left text-[11px] font-semibold uppercase leading-4 tracking-[0.08em] text-muted-foreground";
const tdClass = "border-b border-border px-6 py-3.5 text-sm font-medium group-last/row:border-b-0";

function ActivityTable({ items, now }: { items: DashboardActivityItem[]; now: Date }) {
  return (
    <div className="hidden overflow-x-auto md:block">
      <table className="w-full min-w-[520px] border-collapse">
        <thead>
          <tr>
            <th className={thClass} scope="col">Empresa</th>
            <th className={thClass} scope="col">Semana</th>
            <th className={thClass} scope="col">Estado</th>
            <th className={thClass} scope="col">Actividad</th>
          </tr>
        </thead>
        <tbody>
          {items.map((item) => (
            <tr className="group/row relative transition-colors hover:bg-muted/60" key={item.id}>
              <td className={`${tdClass} font-semibold`}>
                {/* The pseudo-element stretches the link over the whole row. */}
                <Link
                  className="rounded-sm after:absolute after:inset-0 after:content-[''] focus-visible:outline-none focus-visible:after:outline-2 focus-visible:after:-outline-offset-2 focus-visible:after:outline-ring"
                  href={`/records/${item.id}`}
                >
                  <CompanyName item={item} />
                </Link>
              </td>
              <td className={`${tdClass} text-muted-foreground tabular-nums`}>{item.week ?? "—"}</td>
              <td className={tdClass}>
                <StatusBadge status={item.status} />
              </td>
              <td className={`${tdClass} text-muted-foreground`}>
                <ActivityTime item={item} now={now} />
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

function ActivityList({ items, now }: { items: DashboardActivityItem[]; now: Date }) {
  return (
    <ul className="px-5 pb-3 md:hidden">
      {items.map((item) => (
        <li className="border-b border-border last:border-b-0" key={item.id}>
          <Link
            className="flex items-center justify-between gap-3 rounded-sm py-3 focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-ring"
            href={`/records/${item.id}`}
          >
            <span className="min-w-0">
              <strong className="block truncate text-sm font-semibold">
                <CompanyName item={item} />
              </strong>
              <span className="block text-xs font-medium leading-4 text-muted-foreground">
                Semana {item.week ?? "—"} · <ActivityTime item={item} now={now} />
              </span>
            </span>
            <StatusBadge status={item.status} />
          </Link>
        </li>
      ))}
    </ul>
  );
}

function ActivitySkeleton() {
  return (
    <div className="flex flex-col gap-3 px-5 pb-5 pt-2 md:px-6 md:pt-0">
      {Array.from({ length: SKELETON_ROWS }, (_, index) => (
        <div className="flex items-center justify-between gap-4" key={index}>
          <Skeleton className="h-5 w-40" />
          <Skeleton className="h-6 w-24 rounded-full" />
        </div>
      ))}
    </div>
  );
}
