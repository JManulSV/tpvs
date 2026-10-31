import Link from "next/link";
import { Button } from "@/components/ui/button";
import type { ReportCompanyRow, ReportRecordRow, ReportResponse, ReportTotals } from "../types";
import { formatDate, formatMoney } from "../utils";

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
    { label: "Ingreso", value: totals.income_total },
    { label: "Comisión", value: totals.commission_total },
    { label: "Retorno", value: totals.return_total },
  ];

  return (
    <dl className="grid gap-4 sm:grid-cols-3">
      {cards.map((card) => (
        <div className="rounded-md border p-4" key={card.label}>
          <dt className="text-sm text-muted-foreground">{card.label}</dt>
          <dd className="text-2xl font-semibold tabular-nums">{formatMoney(card.value)}</dd>
        </div>
      ))}
    </dl>
  );
}

export function CompanyBreakdown({ companies }: { companies: ReportCompanyRow[] }) {
  return (
    <section aria-labelledby="report-companies-heading" className="space-y-3">
      <h2 className="text-lg font-semibold" id="report-companies-heading">Por empresa</h2>
      <div className="overflow-x-auto rounded-md border">
        <table className="w-full min-w-[36rem] border-collapse text-left text-sm">
          <caption className="sr-only">Registros, ingreso, comisión y retorno por empresa</caption>
          <thead className="bg-muted/50">
            <tr>
              <th className="px-4 py-3 font-medium" scope="col">Empresa</th>
              <th className="px-4 py-3 text-right font-medium" scope="col">Registros</th>
              <th className="px-4 py-3 text-right font-medium" scope="col">Ingreso</th>
              <th className="px-4 py-3 text-right font-medium" scope="col">Comisión</th>
              <th className="px-4 py-3 text-right font-medium" scope="col">Retorno</th>
            </tr>
          </thead>
          <tbody>
            {companies.map((row) => (
              <tr className="border-t" key={row.company.id}>
                <th className="px-4 py-3 font-medium" scope="row">{row.company.name}</th>
                <td className="px-4 py-3 text-right tabular-nums">{row.records_count}</td>
                <td className="px-4 py-3 text-right tabular-nums">{formatMoney(row.income_total)}</td>
                <td className="px-4 py-3 text-right tabular-nums">{formatMoney(row.commission_total)}</td>
                <td className="px-4 py-3 text-right tabular-nums">{formatMoney(row.return_total)}</td>
              </tr>
            ))}
          </tbody>
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
