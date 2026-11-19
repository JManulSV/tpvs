import Link from "next/link";
import type { ReactNode } from "react";
import { ChevronRight } from "lucide-react";
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table";
import { formatShortDate } from "@/lib/format";
import { cn } from "@/lib/utils";
import { getCompanyRecordsHref, getCompanyRowLabel } from "../company-list-utils";
import type { Company } from "../types";
import { CompanyMark, NewCompanyBadge } from "./company-mark";

const headClass =
  "h-10 bg-muted px-4 text-[11px] leading-4 font-semibold uppercase tracking-[0.08em] text-muted-foreground first:pl-6 last:pr-6";
const cellClass = "px-4 py-3 align-middle first:pl-6 last:pr-6";

/** Wide-container list. The whole row is clickable through the name link's overlay. */
export function CompaniesTable({
  companies,
  createdCompanyId,
  footer,
}: {
  companies: Company[];
  createdCompanyId: number | null;
  footer?: ReactNode;
}) {
  return (
    <section
      aria-label="Lista de empresas"
      className="overflow-hidden rounded-lg border border-border bg-card shadow-card"
    >
      <Table className="table-fixed">
        <caption className="sr-only">Empresas y registros confirmados</caption>
        <TableHeader>
          <TableRow className="hover:bg-transparent">
            <TableHead className={headClass} scope="col">Empresa</TableHead>
            <TableHead className={cn(headClass, "w-36 text-right")} scope="col">Confirmados</TableHead>
            <TableHead className={cn(headClass, "w-32")} scope="col">Creada</TableHead>
            <TableHead className={cn(headClass, "w-36")} scope="col">
              <span className="sr-only">Acción</span>
            </TableHead>
          </TableRow>
        </TableHeader>
        <TableBody>
          {companies.map((company) => (
            <CompanyRow company={company} isNew={company.id === createdCompanyId} key={company.id} />
          ))}
        </TableBody>
      </Table>
      {footer}
    </section>
  );
}

function CompanyRow({ company, isNew }: { company: Company; isNew: boolean }) {
  return (
    <TableRow className={cn("relative h-16", isNew && "bg-accent hover:bg-accent")}>
      <TableCell className={cellClass}>
        <span className="flex min-w-0 items-center gap-3">
          <CompanyMark isNew={isNew} />
          <Link
            aria-label={getCompanyRowLabel(company)}
            className="min-w-0 truncate text-sm font-semibold text-foreground after:absolute after:inset-0 focus-visible:outline-none focus-visible:after:ring-2 focus-visible:after:ring-inset focus-visible:after:ring-ring"
            href={getCompanyRecordsHref(company.id)}
          >
            {company.name}
          </Link>
          {isNew && <NewCompanyBadge />}
        </span>
      </TableCell>
      <TableCell
        className={cn(
          cellClass,
          "text-right font-semibold tabular-nums",
          company.records_count === 0 && "font-medium text-muted-foreground",
        )}
      >
        {company.records_count}
      </TableCell>
      <TableCell className={cn(cellClass, "text-muted-foreground tabular-nums")}>
        {formatShortDate(company.created_at)}
      </TableCell>
      <TableCell className={cellClass}>
        <span className="flex items-center justify-end gap-1 text-[13px] leading-4 font-semibold text-accent-foreground">
          Ver registros
          <ChevronRight aria-hidden="true" className="size-4" />
        </span>
      </TableCell>
    </TableRow>
  );
}
