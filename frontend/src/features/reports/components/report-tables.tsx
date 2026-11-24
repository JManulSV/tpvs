import { ArrowDown, ChartColumn, ChevronRight, Percent } from "lucide-react";
import Link from "next/link";
import { Button } from "@/components/ui/button";
import { formatDate, formatMoney } from "@/lib/format";
import { cn } from "@/lib/utils";
import type { ReportCompanyRow, ReportRecordRow, ReportResponse, ReportTotals } from "../types";

export function ReportPeriod({
  period,
  week,
}: {
  period: ReportResponse["data"]["period"];
  week: number | null;
}) {
  if (period === null) return null;

  return (
    <p className="text-sm text-muted-foreground">
      {week !== null ? `Semana ${week} · ` : ""}
      {formatDate(period.week_start_date)} – {formatDate(period.week_end_date)}
    </p>
  );
}

export function ReportTotalsCards({ totals }: { totals: ReportTotals }) {
  const cards = [
    { label: "Ingreso", value: totals.income_total, Icon: ArrowDown, hero: false },
    { label: "Comisión", value: totals.commission_total, Icon: Percent, hero: false },
    { label: "Retorno", value: totals.return_total, Icon: ChartColumn, hero: true },
  ];

  return (
    <dl className="grid gap-4 sm:grid-cols-3">
      {cards.map(({ label, value, Icon, hero }) => (
        <div
          className={cn(
            "flex flex-col gap-3 rounded-lg border px-6 py-5 shadow-card",
            hero ? "border-hero bg-hero text-on-hero" : "bg-card",
          )}
          key={label}
        >
          <div className="flex items-center gap-3">
            <span
              aria-hidden="true"
              className={cn(
                "grid size-10 shrink-0 place-items-center rounded-md",
                hero ? "bg-white/10" : "bg-accent text-accent-foreground",
              )}
            >
              <Icon className="size-5" />
            </span>
            <dt
              className={cn(
                "text-xs font-semibold uppercase tracking-wider",
                hero ? "text-on-hero/70" : "text-muted-foreground",
              )}
            >
              {label}
            </dt>
          </div>
          <dd className="text-[28px] leading-9 font-bold tabular-nums tracking-tight">{formatMoney(value)}</dd>
        </div>
      ))}
    </dl>
  );
}

export function CompanyBreakdown({
  companies,
  totals,
}: {
  companies: ReportCompanyRow[];
  totals: ReportTotals;
}) {
  const count = companies.length;

  return (
    <section aria-labelledby="report-companies-heading" className="overflow-hidden rounded-lg border bg-card shadow-card">
      <div className="flex items-baseline justify-between gap-3 px-6 pt-5 pb-4">
        <h2 className="text-base font-semibold" id="report-companies-heading">Por empresa</h2>
        <span className="text-[13px] text-muted-foreground">
          {count} {count === 1 ? "empresa" : "empresas"}
        </span>
      </div>
      <ul aria-label="Totales por empresa" className="divide-y md:hidden">
        {companies.map((row) => (
          <li className="space-y-3 px-6 py-4" key={row.company.id}>
            <div className="flex items-baseline justify-between gap-3">
              <span className="font-semibold">{row.company.name}</span>
              <span className="text-xs text-muted-foreground">{row.records_count} {row.records_count === 1 ? "registro" : "registros"}</span>
            </div>
            <dl className="grid grid-cols-3 gap-3 text-sm">
              <div><dt className="text-xs text-muted-foreground">Ingreso</dt><dd className="tabular-nums">{formatMoney(row.income_total)}</dd></div>
              <div><dt className="text-xs text-muted-foreground">Comisión</dt><dd className="tabular-nums">{formatMoney(row.commission_total)}</dd></div>
              <div><dt className="text-xs text-muted-foreground">Retorno</dt><dd className="font-semibold tabular-nums">{formatMoney(row.return_total)}</dd></div>
            </dl>
          </li>
        ))}
        <li className="space-y-3 bg-muted/30 px-6 py-4">
          <div className="flex items-baseline justify-between gap-3">
            <span className="font-bold">Total</span>
            <span className="text-xs text-muted-foreground">{totals.records_count} {totals.records_count === 1 ? "registro" : "registros"}</span>
          </div>
          <dl className="grid grid-cols-3 gap-3 text-sm">
            <div><dt className="text-xs text-muted-foreground">Ingreso</dt><dd className="font-bold tabular-nums">{formatMoney(totals.income_total)}</dd></div>
            <div><dt className="text-xs text-muted-foreground">Comisión</dt><dd className="font-bold tabular-nums">{formatMoney(totals.commission_total)}</dd></div>
            <div><dt className="text-xs text-muted-foreground">Retorno</dt><dd className="font-bold tabular-nums">{formatMoney(totals.return_total)}</dd></div>
          </dl>
        </li>
      </ul>
      <div className="relative hidden overflow-x-auto md:block">
        <table className="w-full min-w-[36rem] border-collapse text-left text-sm">
          <caption className="sr-only">Registros, ingreso, comisión y retorno por empresa</caption>
          <thead className="border-y bg-muted/50 text-[11px] uppercase tracking-wider text-muted-foreground">
            <tr>
              <th className="h-10 px-6 font-semibold" scope="col">Empresa</th>
              <th className="h-10 px-4 text-right font-semibold" scope="col">Registros</th>
              <th className="h-10 px-4 text-right font-semibold" scope="col">Ingreso</th>
              <th className="h-10 px-4 text-right font-semibold" scope="col">Comisión</th>
              <th className="h-10 px-6 text-right font-semibold" scope="col">Retorno</th>
            </tr>
          </thead>
          <tbody>
            {companies.map((row) => (
              <tr className="border-b transition-colors hover:bg-muted/50" key={row.company.id}>
                <th className="h-14 px-6 font-semibold" scope="row">{row.company.name}</th>
                <td className="px-4 text-right text-muted-foreground tabular-nums">{row.records_count}</td>
                <td className="px-4 text-right text-muted-foreground tabular-nums">{formatMoney(row.income_total)}</td>
                <td className="px-4 text-right text-muted-foreground tabular-nums">{formatMoney(row.commission_total)}</td>
                <td className="px-6 text-right font-semibold tabular-nums">{formatMoney(row.return_total)}</td>
              </tr>
            ))}
          </tbody>
          <tfoot>
            <tr className="h-12 bg-muted/30 font-bold">
              <th className="px-6 text-left" scope="row">Total</th>
              <td className="px-4 text-right tabular-nums">{totals.records_count}</td>
              <td className="px-4 text-right tabular-nums">{formatMoney(totals.income_total)}</td>
              <td className="px-4 text-right tabular-nums">{formatMoney(totals.commission_total)}</td>
              <td className="px-6 text-right tabular-nums">{formatMoney(totals.return_total)}</td>
            </tr>
          </tfoot>
        </table>
      </div>
    </section>
  );
}

export function IncludedRecords({
  isFetching,
  meta,
  onPageChange,
  records,
}: {
  isFetching: boolean;
  meta: ReportResponse["meta"];
  onPageChange: (page: number) => void;
  records: ReportRecordRow[];
}) {
  return (
    <section aria-labelledby="report-records-heading" className="overflow-hidden rounded-lg border bg-card shadow-card">
      <div className="flex items-baseline justify-between gap-3 px-6 pt-5 pb-4">
        <h2 className="text-base font-semibold" id="report-records-heading">Registros incluidos</h2>
        <span className="text-[13px] text-muted-foreground">Solo confirmados</span>
      </div>
      <ul aria-label="Registros confirmados incluidos en el reporte" className="divide-y md:hidden">
        {records.map((record) => (
          <li className="group relative flex items-center gap-3 px-6 py-4 transition-colors hover:bg-muted/50 focus-within:bg-muted/50" key={record.id}>
            <div className="min-w-0 flex-1 space-y-1">
              {/* The link overlay makes the whole card open the record. */}
              <Link
                aria-label={`Abrir registro de ${record.company.name}, semana ${record.week ?? "sin semana"}`}
                className="block truncate font-semibold after:absolute after:inset-0 after:content-[''] focus-visible:outline-none"
                href={`/records/${record.id}`}
              >
                {record.company.name}
              </Link>
              <p className="text-sm text-muted-foreground">
                Semana {record.week ?? "sin semana"}{record.year ? ` · ${record.year}` : ""} · Fin {formatDate(record.week_end_date)}
              </p>
            </div>
            <p className="font-semibold tabular-nums">{formatMoney(record.return_total)}</p>
            <ChevronRight aria-hidden="true" className="size-4 shrink-0 text-muted-foreground" />
          </li>
        ))}
      </ul>
      <div className="relative hidden overflow-x-auto md:block">
        <table className="w-full min-w-[40rem] border-collapse text-left text-sm">
          <caption className="sr-only">Registros confirmados incluidos en el reporte</caption>
          <thead className="border-y bg-muted/50 text-[11px] uppercase tracking-wider text-muted-foreground">
            <tr>
              <th className="h-10 w-full px-6 font-semibold" scope="col">Empresa</th>
              <th className="h-10 w-px whitespace-nowrap px-6 font-semibold" scope="col">Semana</th>
              <th className="h-10 w-px whitespace-nowrap px-6 font-semibold" scope="col">Fin de semana</th>
              <th className="h-10 w-px whitespace-nowrap px-6 text-right font-semibold" scope="col">Retorno</th>
              <th className="h-10 w-px px-4" scope="col"><span className="sr-only">Abrir</span></th>
            </tr>
          </thead>
          <tbody className={cn(isFetching && "opacity-60 transition-opacity")}>
            {records.map((record) => (
              <tr className="group relative cursor-pointer border-b transition-colors hover:bg-muted/50 focus-within:bg-muted/50" key={record.id}>
                <td className="h-14 px-6 font-semibold">
                  {/* The link overlay makes the whole row open the record. */}
                  <Link
                    aria-label={`Abrir registro de ${record.company.name}, semana ${record.week ?? "sin semana"}`}
                    className="font-semibold after:absolute after:inset-0 after:content-[''] focus-visible:outline-none"
                    href={`/records/${record.id}`}
                  >
                    {record.company.name}
                  </Link>
                </td>
                <td className="whitespace-nowrap px-6">
                  <span className="block font-semibold tabular-nums">{record.week ?? "Sin semana"}</span>
                  <span className="block text-xs text-muted-foreground">{record.year ?? ""}</span>
                </td>
                <td className="whitespace-nowrap px-6 text-muted-foreground">{formatDate(record.week_end_date)}</td>
                <td className="whitespace-nowrap px-6 text-right font-semibold tabular-nums">{formatMoney(record.return_total)}</td>
                <td className="px-4 text-right">
                  <span
                    aria-hidden="true"
                    className="inline-grid size-8 place-items-center rounded-full text-muted-foreground transition-colors group-hover:bg-accent group-hover:text-accent-foreground group-focus-within:bg-accent group-focus-within:text-accent-foreground"
                  >
                    <ChevronRight className="size-4" />
                  </span>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      <nav aria-label="Paginación de registros incluidos" className="flex items-center justify-between gap-4 px-6 py-4">
        <p aria-live="polite" className="text-sm text-muted-foreground">
          Página {meta.current_page} de {meta.last_page} · {meta.total} registros
        </p>
        <div className="flex gap-2">
          <Button disabled={meta.current_page <= 1 || isFetching} onClick={() => onPageChange(meta.current_page - 1)} type="button" variant="outline">Anterior</Button>
          <Button disabled={meta.current_page >= meta.last_page || isFetching} onClick={() => onPageChange(meta.current_page + 1)} type="button" variant="outline">Siguiente</Button>
        </div>
      </nav>
    </section>
  );
}
