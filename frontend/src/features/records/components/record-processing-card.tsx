import { formatDateTime } from "@/lib/format";
import type { AiProcessingStatus, RecordListItem } from "../types";

const statusLabels: Record<AiProcessingStatus, string> = {
  PENDING: "En cola",
  PROCESSING: "Procesando",
  NEEDS_REVIEW: "Lista para revisión",
  ERROR: "Con error",
  COMPLETED: "Completada",
};

function Field({ label, value }: { label: string; value: string }) {
  return (
    <div className="space-y-1">
      <dt className="text-xs/4 font-semibold text-muted-foreground">{label}</dt>
      <dd className="text-[13px]/5 font-medium break-words">{value}</dd>
    </div>
  );
}

// Solo para registros IMAGE. Muestra la última extracción; no hay lista de intentos (SPEC 20).
export function RecordProcessingCard({ record }: { record: RecordListItem }) {
  const processing = record.ai_processing;

  return (
    <section aria-labelledby="record-processing-heading" className="space-y-4 rounded-lg border bg-card p-5 shadow-card">
      <h2 className="text-base/6 font-semibold" id="record-processing-heading">Procesamiento automático</h2>

      {processing === null ? (
        <p className="text-sm text-muted-foreground">Todavía no hay procesamiento para esta imagen.</p>
      ) : (
        <>
          <dl className="grid grid-cols-2 gap-x-4 gap-y-3">
            <Field label="Estado" value={statusLabels[processing.status]} />
            <Field label="Proveedor" value={processing.provider || "—"} />
            <Field label="Modelo" value={processing.model || "—"} />
            <Field label="Inicio" value={processing.started_at ? formatDateTime(processing.started_at) : "—"} />
            <Field label="Fin" value={processing.completed_at ? formatDateTime(processing.completed_at) : "—"} />
          </dl>

          {processing.issues.length === 0 ? (
            <p className="text-sm text-muted-foreground">La última extracción no tiene observaciones.</p>
          ) : (
            <ul className="space-y-2">
              {processing.issues.map((issue, index) => (
                <li className="rounded-md border px-3 py-2 text-sm" key={`${issue.code}-${index}`}>
                  <span className="block font-mono text-xs text-muted-foreground">{issue.code}</span>
                  <span className="block">{issue.message}</span>
                </li>
              ))}
            </ul>
          )}
        </>
      )}
    </section>
  );
}
