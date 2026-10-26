"use client";

import { useState } from "react";
import { Button } from "@/components/ui/button";
import { useRecordHistory } from "../hooks/use-records";
import type { RecordStatus } from "../types";

const PAGE_SIZE = 20;

const statusLabels: Record<RecordStatus, string> = {
  PENDING: "Pendiente",
  PROCESSING: "Procesando",
  NEEDS_REVIEW: "Necesita revisión",
  ERROR: "Error",
  CONFIRMED: "Confirmado",
  CANCELED: "Cancelado",
};

const eventLabels: Record<string, string> = {
  record_created: "Registro creado",
  record_updated: "Registro actualizado",
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

function fieldLabel(fieldName: string): string {
  if (eventLabels[fieldName] !== undefined) return eventLabels[fieldName];
  if (recordFieldLabels[fieldName] !== undefined) return recordFieldLabels[fieldName];

  const detailMatch = /^details\[(\d+)\]\.(.+)$/.exec(fieldName);
  if (detailMatch === null) return fieldName;

  const [, order, field] = detailMatch;
  return `Movimiento ${order} · ${detailFieldLabels[field] ?? field}`;
}

function money(value: string): string {
  return new Intl.NumberFormat("es-MX", { style: "currency", currency: "MXN" }).format(Number(value));
}

function displayValue(fieldName: string, value: string | null): string {
  if (value === null) return "—";
  if (fieldName === "status" || fieldName === "record_created") {
    return statusLabels[value as RecordStatus] ?? value;
  }
  if (fieldName.endsWith(".income") || fieldName.endsWith(".commission") || fieldName.endsWith(".return_amount")) {
    return money(value);
  }
  if (fieldName.endsWith(".commission_percentage")) return `${value}%`;

  return value;
}

function formatDate(value: string): string {
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return value;

  return new Intl.DateTimeFormat("es-MX", { dateStyle: "medium", timeStyle: "short" }).format(date);
}

export function RecordHistoryPanel({ recordId }: { recordId: number }) {
  const [page, setPage] = useState(1);
  const historyQuery = useRecordHistory(recordId, { page, per_page: PAGE_SIZE });

  if (historyQuery.isPending) {
    return (
      <section aria-labelledby="record-history-heading" className="space-y-3">
        <h2 className="text-lg font-semibold" id="record-history-heading">Historial de cambios</h2>
        <p aria-live="polite" className="rounded-md border px-4 py-3 text-sm text-muted-foreground" role="status">
          Cargando historial…
        </p>
      </section>
    );
  }

  if (historyQuery.isError) {
    return (
      <section aria-labelledby="record-history-heading" className="space-y-3">
        <h2 className="text-lg font-semibold" id="record-history-heading">Historial de cambios</h2>
        <div className="space-y-3 rounded-md border px-4 py-3" role="alert">
          <p className="text-sm text-destructive">{historyQuery.error.message}</p>
          <Button onClick={() => void historyQuery.refetch()} type="button" variant="outline">Reintentar</Button>
        </div>
      </section>
    );
  }

  const { data: entries, meta } = historyQuery.data;

  return (
    <section aria-labelledby="record-history-heading" className="space-y-3">
      <h2 className="text-lg font-semibold" id="record-history-heading">Historial de cambios</h2>

      {entries.length === 0 ? (
        <p className="rounded-md border px-4 py-3 text-sm text-muted-foreground">Sin cambios registrados.</p>
      ) : (
        <>
          <div className="overflow-x-auto rounded-md border">
            <table className="w-full min-w-[64rem] border-collapse text-left text-sm">
              <caption className="sr-only">Historial de cambios del registro, más reciente primero</caption>
              <thead className="bg-muted/50">
                <tr>
                  <th className="px-4 py-3 font-medium" scope="col">Fecha</th>
                  <th className="px-4 py-3 font-medium" scope="col">Usuario</th>
                  <th className="px-4 py-3 font-medium" scope="col">Campo</th>
                  <th className="px-4 py-3 font-medium" scope="col">Anterior</th>
                  <th className="px-4 py-3 font-medium" scope="col">Nuevo</th>
                  <th className="px-4 py-3 font-medium" scope="col">Motivo</th>
                </tr>
              </thead>
              <tbody>
                {entries.map((entry) => (
                  <tr className="border-t align-top" key={entry.id}>
                    <td className="px-4 py-3 whitespace-nowrap">{formatDate(entry.created_at)}</td>
                    <td className="px-4 py-3">
                      <span className="block">{entry.user.name}</span>
                      <span className="block text-xs text-muted-foreground">{entry.user.email}</span>
                    </td>
                    <td className="px-4 py-3">{fieldLabel(entry.field_name)}</td>
                    <td className="px-4 py-3 tabular-nums">{displayValue(entry.field_name, entry.previous_value)}</td>
                    <td className="px-4 py-3 tabular-nums">{displayValue(entry.field_name, entry.new_value)}</td>
                    <td className="px-4 py-3">{entry.reason}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          {meta.last_page > 1 && (
            <nav aria-label="Paginación del historial" className="flex flex-wrap items-center justify-end gap-3">
              <Button
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
    </section>
  );
}
