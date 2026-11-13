import { formatDate, formatMoney } from "@/lib/format";
import { getFieldChip, getIssueCodes } from "../record-capture-utils";
import type { FieldChip, RecordDetail, RecordDetailView } from "../types";
import { RecordDetailBanner } from "./record-detail-banner";
import { RecordDetailHero } from "./record-detail-hero";

const chipStyles: Record<FieldChip, { label: string; className: string }> = {
  HIGH_CONFIDENCE: { label: "Alta confianza", className: "bg-status-confirmed-bg text-status-confirmed" },
  REVIEW: { label: "Revisar", className: "bg-status-needs-review-bg text-status-needs-review" },
  MISSING: { label: "Falta información", className: "bg-status-error-bg text-status-error" },
  INCONSISTENT: { label: "Inconsistencia", className: "bg-status-error-bg text-status-error" },
};

function FieldChipBadge({ chip }: { chip: FieldChip | null }) {
  if (chip === null) return null;

  const style = chipStyles[chip];
  return (
    <span className={`inline-flex rounded-full px-2 py-0.5 text-[11px]/4 font-semibold ${style.className}`}>
      {style.label}
    </span>
  );
}

function ReviewField({ chip, label, value, tabular = false }: { chip: FieldChip | null; label: string; value: string; tabular?: boolean }) {
  return (
    <div className="space-y-1">
      <div className="flex flex-wrap items-center gap-2">
        <dt className="text-xs/4 font-semibold text-muted-foreground">{label}</dt>
        <FieldChipBadge chip={chip} />
      </div>
      <dd className={`text-[15px]/6 font-semibold ${tabular ? "tabular-nums" : ""}`}>{value}</dd>
    </div>
  );
}

export function RecordEditor({ canEdit, record }: { canEdit: boolean; record: RecordDetailView }) {
  const issues = record.ai_processing?.issues ?? [];
  const companyHint = record.company === null
    ? record.ai_processing?.normalized_extraction?.record.company_name?.value ?? null
    : null;

  // Solo se usa para registros IMAGE en NEEDS_REVIEW, que es el único caso donde se muestra.
  const chipFor = (value: unknown, path: string) => getFieldChip({
    value,
    issueCodes: getIssueCodes(issues, path),
    isImageRecord: true,
    status: record.status,
  });

  return (
    <section aria-labelledby="record-editor-heading" className="space-y-4">
      <h2 className="sr-only" id="record-editor-heading">Datos extraídos del registro</h2>

      <RecordDetailBanner
        banner={{
          tone: "review",
          title: canEdit
            ? "Información extraída automáticamente. Revisa antes de confirmar."
            : "Pendiente de revisión.",
          text: null,
        }}
      />

      <RecordDetailHero record={record} />

      <section aria-labelledby="record-editor-period-heading" className="space-y-4 rounded-lg border bg-card p-5 shadow-card">
        <div className="space-y-1">
          <h3 className="text-base/6 font-semibold" id="record-editor-period-heading">Empresa y periodo</h3>
          <p className="text-xs/4 text-muted-foreground">La semana se identifica por semana y año.</p>
        </div>

        <dl className="grid grid-cols-[repeat(auto-fit,minmax(150px,1fr))] gap-4">
          <ReviewField chip={chipFor(record.company?.name, "record.company_name")} label="Empresa" value={record.company?.name ?? "—"} />
          <ReviewField chip={chipFor(record.year, "record.year")} label="Año" tabular value={record.year?.toString() ?? "—"} />
          <ReviewField chip={chipFor(record.week, "record.week")} label="Semana" tabular value={record.week?.toString() ?? "—"} />
          <ReviewField chip={chipFor(record.week_start_date, "record.week_start_date")} label="Inicio" tabular value={formatDate(record.week_start_date)} />
          <ReviewField chip={chipFor(record.week_end_date, "record.week_end_date")} label="Fin" tabular value={formatDate(record.week_end_date)} />
        </dl>

        {companyHint !== null && (
          <p className="rounded-lg bg-accent px-4 py-3 text-[13px]/5 text-accent-foreground" role="note">
            Empresa detectada en la imagen: <strong>{companyHint}</strong>. Selecciónala si corresponde.
          </p>
        )}
      </section>

      <section aria-labelledby="record-editor-movements-heading" className="space-y-3">
        <h3 className="text-base/6 font-semibold" id="record-editor-movements-heading">Movimientos</h3>
        {record.details.length === 0 && <p className="text-sm text-muted-foreground">Todavía no hay movimientos.</p>}
        {record.details.map((detail) => (
          <MovementReviewCard chipFor={chipFor} detail={detail} key={detail.id} />
        ))}
      </section>
    </section>
  );
}

function MovementReviewCard({
  chipFor,
  detail,
}: {
  chipFor: (value: unknown, path: string) => FieldChip | null;
  detail: RecordDetail;
}) {
  // Los issues usan el índice de la extracción (0 a N-1); order es 1 a N.
  const path = (field: string) => `details.${detail.order - 1}.${field}`;

  return (
    <article aria-label={`Movimiento ${detail.order}`} className="space-y-3 rounded-lg border bg-card p-5 shadow-card">
      <h4 className="font-bold">Movimiento {detail.order}</h4>
      <dl className="grid grid-cols-2 gap-x-4 gap-y-3 md:grid-cols-3">
        <ReviewField chip={chipFor(detail.affiliation, path("affiliation"))} label="Afiliación" value={detail.affiliation ?? "—"} />
        <ReviewField chip={chipFor(detail.branch, path("branch"))} label="Sucursal" value={detail.branch ?? "—"} />
        <ReviewField chip={chipFor(detail.income, path("income"))} label="Ingreso" tabular value={formatMoney(detail.income)} />
        <ReviewField
          chip={chipFor(detail.commission_percentage, path("commission_percentage"))}
          label="Comisión %"
          tabular
          value={detail.commission_percentage === null ? "—" : `${detail.commission_percentage}%`}
        />
        <ReviewField chip={null} label="Comisión" tabular value={formatMoney(detail.commission)} />
        <ReviewField chip={null} label="Retorno" tabular value={formatMoney(detail.return_amount)} />
      </dl>
    </article>
  );
}
