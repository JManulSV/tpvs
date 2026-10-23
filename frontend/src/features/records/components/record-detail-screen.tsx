"use client";

import Image from "next/image";
import Link from "next/link";
import { useState } from "react";
import { Button } from "@/components/ui/button";
import { useCancelRecord, useConfirmRecord, useRecord, useRetryAiProcessing } from "../hooks/use-records";
import { getRecordActionPermissions } from "../record-actions";
import { recordsService } from "../services/records.service";
import { RecordsApiError, type AiProcessingStatus, type RecordStatus } from "../types";
import { ManualRecordForm } from "./manual-record-form";
import { RecordActionDialog } from "./record-action-dialog";

const statusLabels: Record<RecordStatus, string> = {
  PENDING: "Pendiente",
  PROCESSING: "Procesando",
  NEEDS_REVIEW: "Necesita revisión",
  ERROR: "Error",
  CONFIRMED: "Confirmado",
  CANCELED: "Cancelado",
};

const aiStatusLabels: Record<AiProcessingStatus, string> = {
  PENDING: "Pendiente",
  PROCESSING: "Procesando",
  NEEDS_REVIEW: "Revisión necesaria",
  ERROR: "Error",
  COMPLETED: "Completado",
};

function money(value: string | null | undefined) {
  if (value === null || value === undefined) return "—";
  return new Intl.NumberFormat("es-MX", { style: "currency", currency: "MXN" }).format(Number(value));
}

function formatFileSize(bytes: number) {
  if (bytes < 1024) return `${bytes} B`;

  const kilobytes = bytes / 1024;
  if (kilobytes < 1024) return `${kilobytes.toFixed(1)} KB`;

  return `${(kilobytes / 1024).toFixed(1)} MB`;
}

export function RecordDetailScreen({ canEdit, recordId }: { canEdit: boolean; recordId: number }) {
  const recordQuery = useRecord(recordId);
  const confirmRecord = useConfirmRecord();
  const cancelRecord = useCancelRecord();
  const retryAiProcessing = useRetryAiProcessing();
  const [isEditing, setIsEditing] = useState(false);
  const [isConfirmOpen, setIsConfirmOpen] = useState(false);
  const [isCancelOpen, setIsCancelOpen] = useState(false);
  const [confirmError, setConfirmError] = useState<string | null>(null);
  const [cancelError, setCancelError] = useState<string | null>(null);
  const [retryError, setRetryError] = useState<string | null>(null);
  const [cancelReason, setCancelReason] = useState("");
  const [successMessage, setSuccessMessage] = useState<string | null>(null);
  const record = recordQuery.data;

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

  const {
    canCancel,
    canConfirm,
    canCorrect,
    canRetryAiProcessing: canRetry,
  } = getRecordActionPermissions(record, canEdit);
  const companyHint = record.company === null
    ? record.ai_processing?.normalized_extraction?.record.company_name?.value ?? null
    : null;
  const isProcessing = record.status === "PENDING" || record.status === "PROCESSING";

  if (isEditing && canCorrect) {
    return <ManualRecordForm mode="edit" onEditComplete={() => setIsEditing(false)} record={record} />;
  }

  async function reportActionError(
    error: unknown,
    setError: (message: string | null) => void,
    fallback: string,
  ) {
    if (error instanceof RecordsApiError) {
      setError(error.message);
      if (error.status === 409) {
        await recordQuery.refetch();
      }

      return;
    }

    setError(fallback);
  }

  async function handleConfirm() {
    if (!record) return;
    setConfirmError(null);
    setSuccessMessage(null);
    confirmRecord.reset();

    try {
      await confirmRecord.mutateAsync(record.id);
      setIsConfirmOpen(false);
      setSuccessMessage("El registro se confirmó correctamente.");
    } catch (error) {
      await reportActionError(error, setConfirmError, "No se pudo confirmar el registro.");
    }
  }

  async function handleCancel() {
    if (!record) return;
    const reason = cancelReason.trim();
    if (reason === "") {
      setCancelError("El motivo de la cancelación es obligatorio.");
      return;
    }

    setCancelError(null);
    setSuccessMessage(null);
    cancelRecord.reset();

    try {
      await cancelRecord.mutateAsync({ id: record.id, input: { reason } });
      setIsCancelOpen(false);
      setCancelReason("");
      setSuccessMessage("El registro se canceló correctamente.");
    } catch (error) {
      await reportActionError(error, setCancelError, "No se pudo cancelar el registro.");
    }
  }

  async function handleRetry() {
    if (!record) return;
    setRetryError(null);
    setSuccessMessage(null);
    retryAiProcessing.reset();

    try {
      await retryAiProcessing.mutateAsync(record.id);
      setSuccessMessage("El procesamiento con IA se reinició. La pantalla se actualizará automáticamente.");
    } catch (error) {
      await reportActionError(error, setRetryError, "No se pudo reintentar el procesamiento con IA.");
    }
  }

  function openConfirmDialog() {
    setConfirmError(null);
    confirmRecord.reset();
    setIsConfirmOpen(true);
  }

  function openCancelDialog() {
    setCancelError(null);
    cancelRecord.reset();
    setCancelReason("");
    setIsCancelOpen(true);
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

      {isProcessing && (
        <p className="rounded-md border px-4 py-3 text-sm text-muted-foreground" role="status">
          El registro se está procesando. Esta pantalla se actualiza automáticamente hasta que termine.
        </p>
      )}

      {record.image !== null && record.status !== "CANCELED" && (
        <section aria-labelledby="record-image-heading" className="space-y-3">
          <h2 className="text-lg font-semibold" id="record-image-heading">Imagen del registro</h2>
          <Image
            alt={`Imagen del registro ${record.id}`}
            className="h-auto max-h-[32rem] w-auto rounded-md border object-contain"
            height={1600}
            src={recordsService.imageUrl(record.id)}
            unoptimized
            width={1200}
          />
          <p className="text-sm text-muted-foreground">
            {record.image.original_file_name} · {record.image.mime_type} · {formatFileSize(record.image.file_size)}
          </p>
        </section>
      )}

      {record.status === "CANCELED" && (
        <p className="rounded-md border px-4 py-3 text-sm text-muted-foreground" role="note">
          El registro fue cancelado. La imagen ya no está disponible, pero los datos permanecen consultables.
        </p>
      )}

      <div className="grid gap-4 rounded-md border p-4 sm:grid-cols-2 lg:grid-cols-3">
        <Info label="Empresa" value={record.company?.name ?? "—"} />
        <Info label="Año / semana del negocio" value={`${record.year ?? "—"} / ${record.week ?? "—"}`} />
        <Info label="Inicio y fin" value={`${record.week_start_date ?? "—"} – ${record.week_end_date ?? "—"}`} />
        <Info label="Origen" value={record.source === "MANUAL" ? "Manual" : "Imagen"} />
      </div>

      {companyHint !== null && (
        <p className="rounded-md border px-4 py-3 text-sm" role="note">
          Empresa detectada en la imagen: <strong>{companyHint}</strong>. Selecciónala al corregir el registro si corresponde.
        </p>
      )}

      {record.ai_processing !== null ? (
        <section aria-labelledby="ai-diagnostics-heading" className="space-y-3 rounded-md border p-4">
          <div className="flex flex-wrap items-center justify-between gap-3">
            <h2 className="text-lg font-semibold" id="ai-diagnostics-heading">Diagnóstico de IA</h2>
            <span className="rounded-full border px-3 py-1 text-sm font-medium">{aiStatusLabels[record.ai_processing.status]}</span>
          </div>

          {record.ai_processing.issues.length === 0 ? (
            <p className="text-sm text-muted-foreground">Sin observaciones de revisión.</p>
          ) : (
            <ul className="space-y-2">
              {record.ai_processing.issues.map((issue, index) => (
                <li className="rounded-md border px-3 py-2 text-sm" key={`${issue.path}-${issue.code}-${index}`}>
                  <p>{issue.message}</p>
                  <p className="text-xs text-muted-foreground">{issue.path} · {issue.code}</p>
                </li>
              ))}
            </ul>
          )}
        </section>
      ) : record.status === "ERROR" ? (
        <p className="rounded-md border px-4 py-3 text-sm text-destructive" role="alert">
          El procesamiento falló y no hay un diagnóstico de IA disponible. Puedes reintentar o cancelar el registro.
        </p>
      ) : null}

      <section aria-labelledby="record-movements-heading" className="space-y-3">
        <h2 className="text-lg font-semibold" id="record-movements-heading">Movimientos</h2>
        <div className="overflow-x-auto rounded-md border">
          <table className="w-full min-w-[64rem] border-collapse text-left text-sm">
            <caption className="sr-only">Movimientos y cantidades calculadas por el servidor</caption>
            <thead className="bg-muted/50">
              <tr>
                <th className="px-4 py-3 font-medium" scope="col">#</th>
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
                <th className="px-4 py-3" colSpan={3} scope="row">Totales calculados por el servidor</th>
                <td className="px-4 py-3 text-right tabular-nums">{money(record.income_total)}</td>
                <td className="px-4 py-3 text-right tabular-nums">{money(record.commission_total)}</td>
                <td className="px-4 py-3 text-right tabular-nums">{money(record.return_total)}</td>
              </tr>
            </tfoot>
          </table>
        </div>
      </section>

      {record.source === "MANUAL" && record.status === "CONFIRMED" && (
        <p className="rounded-md border px-4 py-3 text-sm text-muted-foreground" role="note">
          Este registro fue capturado y confirmado por el Editor. La edición auditada de registros confirmados estará disponible en una fase posterior.
        </p>
      )}

      {successMessage && <p className="rounded-md border px-4 py-3 text-sm" role="status">{successMessage}</p>}
      {retryError && <p className="rounded-md border border-destructive/40 px-4 py-3 text-sm text-destructive" role="alert">{retryError}</p>}

      {(canCorrect || canConfirm || canCancel || canRetry) && (
        <div className="flex flex-wrap justify-end gap-3 rounded-md border p-4">
          {canRetry && (
            <Button disabled={retryAiProcessing.isPending} onClick={() => void handleRetry()} type="button" variant="outline">
              {retryAiProcessing.isPending ? "Reintentando…" : "Reintentar procesamiento"}
            </Button>
          )}
          {canCorrect && <Button onClick={() => setIsEditing(true)} type="button" variant="outline">Editar registro</Button>}
          {canCancel && <Button onClick={openCancelDialog} type="button" variant="destructive">Cancelar registro</Button>}
          {canConfirm && <Button onClick={openConfirmDialog} type="button">Confirmar registro</Button>}
        </div>
      )}

      {(record.status === "NEEDS_REVIEW" || record.status === "ERROR") && !canEdit && (
        <p className="rounded-md border px-4 py-3 text-sm text-muted-foreground" role="note">
          Puedes consultar este registro. Solo un Editor puede corregirlo, confirmarlo, cancelarlo o reintentar el procesamiento.
        </p>
      )}

      {isConfirmOpen && (
        <RecordActionDialog
          confirmLabel="Confirmar registro"
          description="¿Confirmas que revisaste este registro y que los datos son correctos? La confirmación no modifica empresa, periodo ni movimientos."
          error={confirmError}
          isPending={confirmRecord.isPending}
          onClose={() => setIsConfirmOpen(false)}
          onConfirm={() => void handleConfirm()}
          title="Confirmar registro"
        />
      )}

      {isCancelOpen && (
        <RecordActionDialog
          confirmDisabled={cancelReason.trim() === ""}
          confirmLabel="Cancelar registro"
          description="El registro quedará cancelado y ya no podrá editarse ni confirmarse. La imagen y el diagnóstico de IA se conservan, pero la imagen dejará de estar disponible."
          destructive
          error={cancelError}
          isPending={cancelRecord.isPending}
          onClose={() => setIsCancelOpen(false)}
          onConfirm={() => void handleCancel()}
          title="Cancelar registro"
        >
          <div className="space-y-2">
            <label className="text-sm font-medium" htmlFor="record-cancel-reason">Motivo de la cancelación</label>
            <textarea
              className="min-h-24 w-full rounded-md border bg-background px-3 py-2 text-sm"
              data-autofocus
              disabled={cancelRecord.isPending}
              id="record-cancel-reason"
              maxLength={2000}
              onChange={(event) => setCancelReason(event.target.value)}
              value={cancelReason}
            />
            <p className="text-xs text-muted-foreground">Obligatorio, máximo 2000 caracteres.</p>
          </div>
        </RecordActionDialog>
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
