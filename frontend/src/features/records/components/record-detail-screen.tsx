"use client";

import Link from "next/link";
import { useState } from "react";
import { Button } from "@/components/ui/button";
import { useDelivery } from "@/features/deliveries/hooks/use-deliveries";
import { useCancelRecord, useConfirmRecord, useRecord, useRetryAiProcessing } from "../hooks/use-records";
import { getRecordActionPermissions } from "../record-actions";
import { getRecordBanner } from "../record-detail-utils";
import { RecordsApiError } from "../types";
import { ManualRecordForm } from "./manual-record-form";
import { RecordActionDialog } from "./record-action-dialog";
import { RecordDetailBanner } from "./record-detail-banner";
import { RecordDetailHeader } from "./record-detail-header";
import { RecordDetailHero } from "./record-detail-hero";
import { RecordImageCard } from "./record-image-card";
import { RecordMovementsCard } from "./record-movements-card";
import { RecordPeriodCard } from "./record-period-card";
import { RecordHistoryPanel } from "./record-history-panel";

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

  const permissions = getRecordActionPermissions(record, canEdit);
  const { canCorrect } = permissions;
  const banner = getRecordBanner(record, canEdit);
  const companyHint = record.company === null
    ? record.ai_processing?.normalized_extraction?.record.company_name?.value ?? null
    : null;

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
      <RecordDetailHeader
        isRetrying={retryAiProcessing.isPending}
        onCancel={openCancelDialog}
        onConfirm={openConfirmDialog}
        onEdit={() => setIsEditing(true)}
        onRetry={() => void handleRetry()}
        permissions={permissions}
        record={record}
      />

      {banner !== null && <RecordDetailBanner banner={banner} />}
      {successMessage && <RecordDetailBanner banner={{ tone: "info", title: successMessage, text: null }} />}
      {retryError && <RecordDetailBanner banner={{ tone: "error", title: retryError, text: null }} />}

      <RecordDetailHero record={record} />

      <RecordPeriodCard companyHint={companyHint} record={record} />

      <RecordMovementsCard details={record.details} />

      <RecordImageCard record={record} />

      {record.status === "CONFIRMED" && <DeliveryLink canEdit={canEdit} recordId={record.id} />}

      <RecordHistoryPanel recordId={record.id} />

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

function DeliveryLink({ canEdit, recordId }: { canEdit: boolean; recordId: number }) {
  const deliveryQuery = useDelivery(recordId);
  const label = !canEdit
    ? "Ver entrega"
    : deliveryQuery.data ? "Actualizar entrega" : "Preparar entrega";

  return (
    <section aria-labelledby="record-delivery-heading" className="flex flex-wrap items-center justify-between gap-3 rounded-md border p-4">
      <h2 className="text-lg font-semibold" id="record-delivery-heading">Preparación de entrega</h2>
      <Link className="inline-flex h-8 items-center justify-center rounded-lg border px-3 text-sm font-medium hover:bg-muted focus-visible:outline-2 focus-visible:outline-ring" href={`/records/${recordId}/delivery`}>{label}</Link>
    </section>
  );
}
