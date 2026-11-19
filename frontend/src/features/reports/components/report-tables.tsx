import { ArrowDown, ChartColumn, Percent } from "lucide-react";
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
      <div className="overflow-x-auto">
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
    <section aria-labelledby="report-records-heading" className="space-y-3">
      <h2 className="text-lg font-semibold" id="report-records-heading">Registros incluidos</h2>
      <div className="overflow-x-auto rounded-md border">
        <table className="w-full min-w-[48rem] border-collapse text-left text-sm">
          <caption className="sr-only">Registros confirmados incluidos en el reporte</caption>
          <thead className="bg-muted/50">
            <tr>
              <th className="px-4 py-3 font-medium" scope="col">Empresa</th>
              <th className="px-4 py-3 font-medium" scope="col">Período</th>
              <th className="px-4 py-3 text-right font-medium" scope="col">Ingreso</th>
              <th className="px-4 py-3 text-right font-medium" scope="col">Comisión</th>
              <th className="px-4 py-3 text-right font-medium" scope="col">Retorno</th>
              <th className="px-4 py-3 font-medium" scope="col">Entrega</th>
            </tr>
          </thead>
          <tbody>
            {records.map((record) => (
              <tr className="border-t hover:bg-muted/30" key={record.id}>
                <th className="px-4 py-3 font-medium" scope="row">
                  <Link className="underline-offset-4 hover:underline focus-visible:outline-2" href={`/records/${record.id}`}>
                    {record.company.name}
                  </Link>
                </th>
                <td className="px-4 py-3">
                  {record.week ? `Semana ${record.week}` : "Sin semana"}{record.year ? ` · ${record.year}` : ""}
                  <br />
                  <span className="text-muted-foreground">{formatDate(record.week_start_date)} – {formatDate(record.week_end_date)}</span>
                </td>
                <td className="px-4 py-3 text-right tabular-nums">{formatMoney(record.income_total)}</td>
                <td className="px-4 py-3 text-right tabular-nums">{formatMoney(record.commission_total)}</td>
                <td className="px-4 py-3 text-right tabular-nums">{formatMoney(record.return_total)}</td>
                <td className="px-4 py-3">{formatDate(record.delivery_date)}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      <nav aria-label="Paginación de registros incluidos" className="flex items-center justify-between gap-4">
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
