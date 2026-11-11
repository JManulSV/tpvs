import Link from "next/link";
import { ChevronRight } from "lucide-react";
import { StatusBadge } from "@/components/status-badge";
import type { AuthUser } from "@/features/auth/types";
import { formatMoney } from "@/lib/format";
import { cn } from "@/lib/utils";
import {
  getRecordMetaLine,
  getRecordRowAction,
  getRecordRowLabel,
  getRecordSubtitle,
} from "../record-list-utils";
import type { RecordListItem } from "../types";

/** Narrow-container list. Each card is fully clickable through the company link's overlay. */
export function RecordsCardList({ records, role }: { records: RecordListItem[]; role: AuthUser["role"] }) {
  return (
    <ul aria-label="Lista de registros" className="flex flex-col gap-3">
      {records.map((record) => {
        const subtitle = getRecordSubtitle(record);
        const action = getRecordRowAction(record, role);
        const dimAmount = record.return_total == null || record.status === "CANCELED";

        return (
          <li
            className="relative flex flex-col gap-1 rounded-lg border border-border bg-card p-4 shadow-card transition-colors hover:bg-muted/50"
            key={record.id}
          >
            <div className="flex items-start justify-between gap-3">
              <Link
                aria-label={getRecordRowLabel(record)}
                className="min-w-0 text-[15px] leading-6 font-semibold text-foreground after:absolute after:inset-0 after:rounded-lg focus-visible:outline-none focus-visible:after:ring-2 focus-visible:after:ring-ring"
                href={`/records/${record.id}`}
              >
                {record.company ? (
                  record.company.name
                ) : (
                  <span className="font-medium text-muted-foreground italic">Sin empresa asignada</span>
                )}
              </Link>
              <StatusBadge status={record.status} />
            </div>
            <span className="text-xs font-medium text-muted-foreground">{getRecordMetaLine(record)}</span>
            {subtitle && <span className="text-xs font-medium text-muted-foreground">{subtitle}</span>}
            <div className="mt-2 flex items-center justify-between gap-3">
              <span
                className={cn(
                  "text-lg leading-6 font-bold tracking-[-0.01em] tabular-nums",
                  dimAmount && "font-medium text-muted-foreground",
                )}
              >
                {formatMoney(record.return_total)}
              </span>
              {action ? (
                <span className="inline-flex items-center gap-0.5 text-[13px] font-semibold text-accent-foreground">
                  {action}
                  <ChevronRight aria-hidden="true" className="size-4" />
                </span>
              ) : (
                <ChevronRight aria-hidden="true" className="size-5 text-muted-foreground" />
              )}
            </div>
          </li>
        );
      })}
    </ul>
  );
}
