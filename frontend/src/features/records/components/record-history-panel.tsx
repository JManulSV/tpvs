"use client";

import { useState, type ReactNode } from "react";
import { Button } from "@/components/ui/button";
import { formatDateTime, formatMoney } from "@/lib/format";
import { recordStatusMeta, type RecordStatus } from "@/lib/status";
import { useRecordHistory } from "../hooks/use-records";
import { getHistoryDotClass } from "../record-detail-utils";

const PAGE_SIZE = 20;

const eventLabels: Record<string, string> = {
  record_created: "Registro creado",
  record_updated: "Registro actualizado",
  document_generated: "Documento Word generado",
};

const recordFieldLabels: Record<string, string> = {
  company: "Empresa",
  year: "Año",
  week: "Semana",
  week_start_date: "Inicio de semana",
  week_end_date: "Fin de semana",
  status: "Estado",
};

const detailFieldLabels: Record<string, string> = {
  order: "Orden",
  affiliation: "Afiliación",
  branch: "Sucursal",
  income: "Ingreso",
  commission_percentage: "Porcentaje de comisión",
  commission: "Comisión",
  return_amount: "Retorno",
};

const deliveryFieldLabels: Record<string, string> = {
  delivery_date: "Fecha de entrega",
  delivered_by_name: "Entrega realizada por",
  received_by_name: "Recibido por",
  prepared_return_amount: "Importe preparado",
};

const denominationPattern = /^denominations\[(\d+(?:\.\d{1,2})?)\]$/;

export function fieldLabel(fieldName: string): string {
  if (deliveryFieldLabels[fieldName] !== undefined) return deliveryFieldLabels[fieldName];

  const denominationMatch = denominationPattern.exec(fieldName);
   if (denominationMatch !== null) return `Denominación ${formatMoney(denominationMatch[1])}`;

  if (eventLabels[fieldName] !== undefined) return eventLabels[fieldName];
  if (recordFieldLabels[fieldName] !== undefined) return recordFieldLabels[fieldName];

  const detailMatch = /^details\[(\d+)\]\.(.+)$/.exec(fieldName);
  if (detailMatch === null) return fieldName;

  const [, order, field] = detailMatch;
  return `Movimiento ${order} · ${detailFieldLabels[field] ?? field}`;
}

function displayValue(fieldName: string, value: string | null): string {
  if (value === null) return "—";
  if (fieldName === "status" || fieldName === "record_created") {
    return recordStatusMeta[value as RecordStatus]?.label ?? value;
  }
  if (fieldName === "prepared_return_amount") return formatMoney(value);
  if (denominationPattern.test(fieldName)) return value;
  if (fieldName.endsWith(".income") || fieldName.endsWith(".commission") || fieldName.endsWith(".return_amount")) {
    return formatMoney(value);
  }
  if (fieldName.endsWith(".commission_percentage")) return `${value}%`;

  return value;
}

function HistoryCard({ children }: { children: ReactNode }) {
  return (
    <section
      aria-labelledby="record-history-heading"
      className="space-y-4 rounded-lg border bg-card p-5 shadow-card"
    >
      <div className="space-y-1">
        <h2 className="text-base/6 font-semibold" id="record-history-heading">Historial de cambios</h2>
        <p className="text-xs/4 text-muted-foreground">Quién cambió qué y por qué. No se puede editar.</p>
      </div>
      {children}
    </section>
  );
}

export function RecordHistoryPanel({ recordId }: { recordId: number }) {
  const [page, setPage] = useState(1);
  const historyQuery = useRecordHistory(recordId, { page, per_page: PAGE_SIZE });

  if (historyQuery.isPending) {
    return (
      <HistoryCard>
        <p aria-live="polite" className="text-sm text-muted-foreground" role="status">
          Cargando historial…
        </p>
      </HistoryCard>
    );
  }

  if (historyQuery.isError) {
    return (
      <HistoryCard>
        <div className="space-y-3" role="alert">
          <p className="text-sm text-status-error">{historyQuery.error.message}</p>
          <Button className="min-h-11 md:min-h-10" onClick={() => void historyQuery.refetch()} type="button" variant="outline">Reintentar</Button>
        </div>
      </HistoryCard>
    );
  }

  const { data: entries, meta } = historyQuery.data;

  return (
    <HistoryCard>
      {entries.length === 0 ? (
        <p className="text-sm text-muted-foreground">Sin cambios registrados.</p>
      ) : (
        <>
          <ol>
            {entries.map((entry, index) => (
              <li className="relative flex gap-3 pb-5 last:pb-0" key={entry.id}>
                {index < entries.length - 1 && (
                  <span aria-hidden="true" className="absolute top-4 bottom-0 left-[5px] w-px bg-border" />
                )}
                <span
                  aria-hidden="true"
                  className={`relative mt-1 size-3 shrink-0 rounded-full ${getHistoryDotClass(entry.field_name, entry.new_value)}`}
                />
                <div className="min-w-0 flex-1 space-y-1">
                  <div className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-0.5">
                    <p className="text-sm font-semibold">{fieldLabel(entry.field_name)}</p>
                    <p className="text-xs/4 text-muted-foreground">
                      {entry.user.name} · {formatDateTime(entry.created_at)}
                    </p>
                  </div>
                  <p className="text-sm tabular-nums break-words">
                    {displayValue(entry.field_name, entry.previous_value)} → {displayValue(entry.field_name, entry.new_value)}
                  </p>
                  {entry.reason !== "" && (
                    <p className="text-sm text-muted-foreground">Motivo: {entry.reason}</p>
                  )}
                </div>
              </li>
            ))}
          </ol>

          {meta.last_page > 1 && (
            <nav aria-label="Paginación del historial" className="flex flex-wrap items-center justify-end gap-3">
              <Button
                className="min-h-11 md:min-h-10"
                disabled={meta.current_page <= 1}
                onClick={() => setPage((current) => Math.max(1, current - 1))}
                type="button"
                variant="outline"
              >
                Anterior
              </Button>
              <span className="text-sm text-muted-foreground">
                Página {meta.current_page} de {meta.last_page}
              </span>
              <Button
                className="min-h-11 md:min-h-10"
                disabled={meta.current_page >= meta.last_page}
                onClick={() => setPage((current) => Math.min(meta.last_page, current + 1))}
                type="button"
                variant="outline"
              >
                Siguiente
              </Button>
            </nav>
          )}
        </>
      )}
    </HistoryCard>
  );
}
