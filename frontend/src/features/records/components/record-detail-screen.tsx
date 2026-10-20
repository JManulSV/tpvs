"use client";

import Link from "next/link";
import { useState } from "react";
import { Button } from "@/components/ui/button";
import { useConfirmRecord, useRecord } from "../hooks/use-records";
import { ManualRecordForm } from "./manual-record-form";
import { RecordsApiError, type RecordStatus } from "../types";

const statusLabels: Record<RecordStatus, string> = {
  PENDING: "Pendiente",
  PROCESSING: "Procesando",
  NEEDS_REVIEW: "Necesita revisión",
  ERROR: "Error",
  CONFIRMED: "Confirmado",
  CANCELED: "Cancelado",
};

function money(value: string | null) {
  if (value === null) return "—";
  return new Intl.NumberFormat("es-MX", { style: "currency", currency: "MXN" }).format(Number(value));
}

export function RecordDetailScreen({ canEdit, recordId }: { canEdit: boolean; recordId: number }) {
  const recordQuery = useRecord(recordId);
  const confirmRecord = useConfirmRecord();
  const [isEditing, setIsEditing] = useState(false);
  const [confirmError, setConfirmError] = useState<string | null>(null);
  const record = recordQuery.data;
  const canMutateManualRecord = canEdit && record?.source === "MANUAL" && record.status === "NEEDS_REVIEW";

  if (recordQuery.isPending) {
    return <p aria-live="polite" className="py-10 text-center text-sm text-muted-foreground">Cargando registro…</p>;
  }

  if (recordQuery.isError || !record) {
    return (
      <section className="space-y-4 rounded-md border p-6 text-center" role="alert">
        <h1 className="text-xl font-semibold">No se pudo cargar el registro</h1>
        <p className="text-sm text-muted-foreground">{recordQuery.error?.message ?? "El registro no está disponible."}</p>
        <div className="flex justify-center gap-3">
          <Button onClick={() => void recordQuery.refetch()} type="button" variant="outline">Reintentar</Button>
          <Link className="inline-flex h-8 items-center justify-center rounded-lg border px-3 text-sm font-medium hover:bg-muted focus-visible:outline-2 focus-visible:outline-ring" href="/records">Volver a registros</Link>
        </div>
      </section>
    );
  }

  if (isEditing && canMutateManualRecord) {
    return <ManualRecordForm mode="edit" record={record} />;
  }

  async function handleConfirm() {
    if (!record) return;
    setConfirmError(null);
    confirmRecord.reset();
    try {
      await confirmRecord.mutateAsync(record.id);
    } catch (error) {
      setConfirmError(error instanceof RecordsApiError ? error.message : "No se pudo confirmar el registro.");
    }
  }

  return (
    <section aria-labelledby="record-detail-heading" className="space-y-6">
      <header className="flex flex-wrap items-start justify-between gap-4">
        <div className="space-y-2">
          <Link className="inline-flex h-8 items-center justify-center rounded-lg border px-3 text-sm font-medium hover:bg-muted focus-visible:outline-2 focus-visible:outline-ring" href="/records">← Registros</Link>
          <h1 className="text-2xl font-semibold tracking-tight" id="record-detail-heading">{record.company?.name ?? "Registro sin empresa"}</h1>
          <p className="text-sm text-muted-foreground">
            Registro #{record.id} · {record.source === "MANUAL" ? "Captura manual" : "Origen imagen"} · Semana {record.week ?? "—"}, {record.year ?? "—"}
          </p>
        </div>
        <span className="rounded-full border px-3 py-1 text-sm font-medium" role="status">{statusLabels[record.status]}</span>
      </header>

      <div className="grid gap-4 rounded-md border p-4 sm:grid-cols-2 lg:grid-cols-3">
        <Info label="Empresa" value={record.company?.name ?? "—"} />
        <Info label="Año / semana del negocio" value={`${record.year ?? "—"} / ${record.week ?? "—"}`} />
        <Info label="Inicio y fin" value={`${record.week_start_date ?? "—"} – ${record.week_end_date ?? "—"}`} />
        <Info label="Origen" value={record.source === "MANUAL" ? "Manual" : "Imagen"} />
      </div>

      <section aria-labelledby="record-movements-heading" className="space-y-3">
        <h2 className="text-lg font-semibold" id="record-movements-heading">Movimientos</h2>
        <div className="overflow-x-auto rounded-md border">
          <table className="w-full min-w-[64rem] border-collapse text-left text-sm">
            <caption className="sr-only">Movimientos y cantidades calculadas por el servidor</caption>
            <thead className="bg-muted/50">
              <tr>
                <th className="px-4 py-3 font-medium" scope="col">#</th>
                <th className="px-4 py-3 font-medium" scope="col">Operación</th>
                <th className="px-4 py-3 font-medium" scope="col">Afiliación</th>
                <th className="px-4 py-3 font-medium" scope="col">Sucursal</th>
                <th className="px-4 py-3 text-right font-medium" scope="col">Ingreso</th>
                <th className="px-4 py-3 text-right font-medium" scope="col">Comisión</th>
                <th className="px-4 py-3 text-right font-medium" scope="col">Retorno</th>
              </tr>
            </thead>
            <tbody>
              {record.details.map((detail) => (
                <tr className="border-t" key={detail.id}>
                  <td className="px-4 py-3 tabular-nums">{detail.order}</td>
                  <th className="px-4 py-3 font-medium" scope="row">{detail.operation_number ?? "—"}</th>
                  <td className="px-4 py-3">{detail.affiliation ?? "—"}</td>
                  <td className="px-4 py-3">{detail.branch ?? "—"}</td>
                  <td className="px-4 py-3 text-right tabular-nums">{money(detail.income)}</td>
                  <td className="px-4 py-3 text-right tabular-nums">
                    {money(detail.commission)}{detail.commission_percentage !== null && <span className="block text-xs text-muted-foreground">{detail.commission_percentage}%</span>}
                  </td>
                  <td className="px-4 py-3 text-right tabular-nums">{money(detail.return_amount)}</td>
                </tr>
              ))}
            </tbody>
            <tfoot className="border-t bg-muted/30 font-semibold">
              <tr>
                <th className="px-4 py-3" colSpan={4} scope="row">Totales calculados por el servidor</th>
                <td className="px-4 py-3 text-right tabular-nums">{money(record.income_total)}</td>
                <td className="px-4 py-3 text-right tabular-nums">{money(record.commission_total)}</td>
                <td className="px-4 py-3 text-right tabular-nums">{money(record.return_total)}</td>
              </tr>
            </tfoot>
          </table>
        </div>
      </section>

      {record.source === "IMAGE" && (
        <p className="rounded-md border px-4 py-3 text-sm text-muted-foreground" role="note">
          Este registro proviene de imagen. Las acciones de imagen, IA y revisión automatizada se habilitarán en fases posteriores.
        </p>
      )}

      {record.source === "MANUAL" && record.status === "CONFIRMED" && (
        <p className="rounded-md border px-4 py-3 text-sm text-muted-foreground" role="note">
          Este registro fue capturado y confirmado por el Editor. La edición auditada de registros confirmados estará disponible en una fase posterior.
        </p>
      )}

      {confirmError && <p className="rounded-md border border-destructive/40 px-4 py-3 text-sm text-destructive" role="alert">{confirmError}</p>}
      {confirmRecord.isSuccess && record.status === "CONFIRMED" && <p className="rounded-md border px-4 py-3 text-sm" role="status">El registro se confirmó correctamente.</p>}

      {canMutateManualRecord && (
        <div className="flex flex-wrap justify-end gap-3 rounded-md border p-4">
          <Button disabled={confirmRecord.isPending} onClick={() => setIsEditing(true)} type="button" variant="outline">Editar registro</Button>
          <Button disabled={confirmRecord.isPending} onClick={() => {
            if (window.confirm("¿Confirmas que revisaste este registro y que los datos son correctos?")) void handleConfirm();
          }} type="button">
            {confirmRecord.isPending ? "Confirmando…" : "Confirmar registro"}
          </Button>
        </div>
      )}

      {record.status === "NEEDS_REVIEW" && record.source === "MANUAL" && !canEdit && (
        <p className="rounded-md border px-4 py-3 text-sm text-muted-foreground" role="note">Puedes consultar este registro. Solo un Editor puede editarlo o confirmarlo.</p>
      )}
    </section>
  );
}

function Info({ label, value }: { label: string; value: string }) {
  return (
    <div className="space-y-1">
      <p className="text-xs font-medium uppercase tracking-wide text-muted-foreground">{label}</p>
      <p className="text-sm">{value}</p>
    </div>
  );
}
