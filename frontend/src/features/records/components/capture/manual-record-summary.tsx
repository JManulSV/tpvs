import Link from "next/link";
import { Button, buttonVariants } from "@/components/ui/button";
import { formatDate, formatMoney } from "@/lib/format";
import { cardSurface } from "@/lib/styles";
import { cn } from "@/lib/utils";
import type { RecordDetailView } from "../../types";
import { getReportMonthName } from "../../utils/detail";

type ManualRecordSummaryProps = {
  record: RecordDetailView;
  onCreateAnother: () => void;
};

/** Shown after a manual record is created: it is already confirmed and counts in totals. */
export function ManualRecordSummary({ record, onCreateAnother }: ManualRecordSummaryProps) {
  return (
    <section aria-labelledby="manual-summary-heading" className={cn(cardSurface, "space-y-4 p-6")}>
      <div className="space-y-1">
        <h1 className="text-xl font-semibold" id="manual-summary-heading">
          Registro confirmado
        </h1>
        <p className="text-sm text-muted-foreground">El registro se guardó y ya cuenta en los totales.</p>
      </div>

      <dl className="grid grid-cols-2 gap-4 text-sm sm:grid-cols-4">
        <SummaryItem label="Empresa" value={record.company?.name ?? "—"} />
        <SummaryItem label="Periodo" tabular value={`Semana ${record.week ?? "—"} · ${record.year ?? "—"}`} />
        <SummaryItem label="Movimientos" tabular value={String(record.details.length)} />
        <SummaryItem
          label="Comisión %"
          tabular
          value={record.commission_percentage === null ? "—" : `${record.commission_percentage}%`}
        />
        <SummaryItem label="Mes del reporte" value={getReportMonthName(record.week_end_date) ?? "—"} />
        <SummaryItem label="Inicio" tabular value={formatDate(record.week_start_date)} />
        <SummaryItem label="Fin" tabular value={formatDate(record.week_end_date)} />
      </dl>

      <dl className="grid grid-cols-3 gap-4 rounded-md bg-muted/40 p-4">
        <SummaryTotal label="Ingreso" value={record.income_total} />
        <SummaryTotal label="Comisión" value={record.commission_total} />
        <SummaryTotal label="Retorno" value={record.return_total} />
      </dl>

      <div className="flex flex-wrap justify-end gap-3">
        <Button onClick={onCreateAnother} type="button" variant="outline">
          Crear otro registro
        </Button>
        <Link className={buttonVariants({ variant: "outline" })} href={`/records/${record.id}`}>
          Ver registro
        </Link>
        <Link className={buttonVariants()} href={`/records/${record.id}/delivery`}>
          Preparar entrega
        </Link>
      </div>
    </section>
  );
}

function SummaryItem({ label, value, tabular = false }: { label: string; value: string; tabular?: boolean }) {
  return (
    <div>
      <dt className="text-xs font-semibold text-muted-foreground">{label}</dt>
      <dd className={cn("font-medium", tabular && "tabular-nums")}>{value}</dd>
    </div>
  );
}

function SummaryTotal({ label, value }: { label: string; value: string | null | undefined }) {
  return (
    <div>
      <dt className="text-xs font-semibold text-muted-foreground">{label}</dt>
      <dd className="text-lg font-bold tabular-nums">{formatMoney(value ?? null)}</dd>
    </div>
  );
}
