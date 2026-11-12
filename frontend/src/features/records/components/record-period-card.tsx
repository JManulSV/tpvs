import { formatDate } from "@/lib/format";
import { getReportMonthName } from "../record-detail-utils";
import type { RecordListItem } from "../types";

function Field({ label, tabular = false, value }: { label: string; tabular?: boolean; value: string }) {
  return (
    <div className="space-y-1">
      <dt className="text-xs/4 font-semibold text-muted-foreground">{label}</dt>
      <dd className={`text-[15px]/6 font-semibold ${tabular ? "tabular-nums" : ""}`}>{value}</dd>
    </div>
  );
}

export function RecordPeriodCard({
  companyHint,
  record,
}: {
  companyHint: string | null;
  record: RecordListItem;
}) {
  const month = getReportMonthName(record.week_end_date);

  return (
    <section
      aria-labelledby="record-period-heading"
      className="space-y-4 rounded-lg border bg-card p-5 shadow-card"
    >
      <div className="space-y-1">
        <h2 className="text-base/6 font-semibold" id="record-period-heading">Empresa y periodo</h2>
        <p className="text-xs/4 text-muted-foreground">La semana se identifica por semana y año.</p>
      </div>

      <dl className="grid grid-cols-[repeat(auto-fit,minmax(150px,1fr))] gap-4">
        <Field label="Empresa" value={record.company?.name ?? "—"} />
        <Field label="Año" tabular value={record.year?.toString() ?? "—"} />
        <Field label="Semana" tabular value={record.week?.toString() ?? "—"} />
        <Field label="Inicio" tabular value={formatDate(record.week_start_date)} />
        <Field label="Fin" tabular value={formatDate(record.week_end_date)} />
      </dl>

      {companyHint !== null && (
        <p className="rounded-lg bg-accent px-4 py-3 text-[13px]/5 text-accent-foreground" role="note">
          Empresa detectada en la imagen: <strong>{companyHint}</strong>. Selecciónala al corregir el registro si corresponde.
        </p>
      )}

      <p className="text-xs/4 text-muted-foreground">
        {month !== null && <>Mes del reporte: <strong>{month}</strong>. </>}
        Cuando una semana cruza dos meses se usa el mes de la fecha de fin.
      </p>
    </section>
  );
}
