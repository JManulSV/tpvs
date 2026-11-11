import type { ReactNode } from "react";
import Link from "next/link";
import { ChevronRight, ImageIcon, Pencil } from "lucide-react";
import { StatusBadge } from "@/components/status-badge";
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table";
import type { AuthUser } from "@/features/auth/types";
import { formatDate, formatMoney } from "@/lib/format";
import { cn } from "@/lib/utils";
import {
  getRecordRowAction,
  getRecordRowLabel,
  getRecordSubtitle,
} from "../record-list-utils";
import type { RecordListItem } from "../types";

const headClass =
  "h-10 bg-muted px-4 text-[11px] font-semibold uppercase tracking-[0.08em] text-muted-foreground first:px-6 last:px-6";
const cellClass = "px-4 py-2.5 align-middle first:px-6 last:px-6";

/** Wide-container list. The whole row is clickable through the company link's overlay. */
export function RecordsTable({
  footer,
  records,
  role,
}: {
  footer?: ReactNode;
  records: RecordListItem[];
  role: AuthUser["role"];
}) {
  return (
    <div
      aria-label="Lista de registros"
      className="overflow-hidden rounded-lg border border-border bg-card shadow-card"
      role="region"
    >
      <Table className="table-fixed">
        <caption className="sr-only">Registros, semana, fin de semana, fuente, retorno, estado y acción</caption>
        <TableHeader>
          <TableRow className="hover:bg-transparent">
            <TableHead className={headClass} scope="col">Empresa</TableHead>
            <TableHead className={cn(headClass, "w-24")} scope="col">Semana</TableHead>
            <TableHead className={cn(headClass, "w-28")} scope="col">Fin de semana</TableHead>
            <TableHead className={cn(headClass, "w-28")} scope="col">Fuente</TableHead>
            <TableHead className={cn(headClass, "w-28 text-right")} scope="col">Retorno</TableHead>
            <TableHead className={cn(headClass, "w-32")} scope="col">Estado</TableHead>
            <TableHead className={cn(headClass, "w-28")} scope="col"><span className="sr-only">Acción</span></TableHead>
          </TableRow>
        </TableHeader>
        <TableBody>
          {records.map((record) => (
            <RecordRow key={record.id} record={record} role={role} />
          ))}
        </TableBody>
      </Table>
      {footer}
    </div>
  );
}

function RecordRow({ record, role }: { record: RecordListItem; role: AuthUser["role"] }) {
  const subtitle = getRecordSubtitle(record);
  const action = getRecordRowAction(record, role);
  const dimAmount = record.return_total == null || record.status === "CANCELED";
  const SourceIcon = record.source === "IMAGE" ? ImageIcon : Pencil;

  return (
    <TableRow className="relative h-16">
      <TableCell className={cellClass}>
        <Link
          aria-label={getRecordRowLabel(record)}
          className="block truncate text-sm font-semibold text-foreground after:absolute after:inset-0 focus-visible:outline-none focus-visible:after:ring-2 focus-visible:after:ring-inset focus-visible:after:ring-ring"
          href={`/records/${record.id}`}
        >
          {record.company ? (
            record.company.name
          ) : (
            <span className="font-medium text-muted-foreground italic">Sin empresa asignada</span>
          )}
        </Link>
        {subtitle && <span className="block truncate text-xs font-medium text-muted-foreground">{subtitle}</span>}
      </TableCell>
      <TableCell className={cellClass}>
        <span className="block font-semibold tabular-nums">{record.week ?? "—"}</span>
        {record.year !== null && <span className="block text-xs font-medium text-muted-foreground">{record.year}</span>}
      </TableCell>
      <TableCell className={cn(cellClass, "text-muted-foreground tabular-nums")}>
        {formatDate(record.week_end_date)}
      </TableCell>
      <TableCell className={cellClass}>
        <span className="inline-flex items-center gap-2 font-medium text-muted-foreground">
          <SourceIcon aria-hidden="true" className="size-4 shrink-0" />
          {record.source === "IMAGE" ? "Imagen" : "Manual"}
        </span>
      </TableCell>
      <TableCell
        className={cn(cellClass, "text-right font-semibold tabular-nums", dimAmount && "font-medium text-muted-foreground")}
      >
        {formatMoney(record.return_total)}
      </TableCell>
      <TableCell className={cellClass}>
        <StatusBadge status={record.status} />
      </TableCell>
      <TableCell className={cellClass}>
        <span className="flex items-center justify-end gap-1">
          {action ? (
            <span className="inline-flex items-center gap-1 text-[13px] font-semibold text-accent-foreground">
              {action}
              <ChevronRight aria-hidden="true" className="size-4" />
            </span>
          ) : (
            <ChevronRight aria-hidden="true" className="size-5 text-muted-foreground" />
          )}
        </span>
      </TableCell>
    </TableRow>
  );
}
