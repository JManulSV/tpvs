"use client";

import Link from "next/link";
import { useState } from "react";
import { Button, buttonVariants } from "@/components/ui/button";
import { useCancelRecord, useConfirmRecord, useRecord, useRetryAiProcessing } from "../hooks/use-records";
import { getRecordActionPermissions } from "../record-actions";
import { getRecordBanner } from "../record-detail-utils";
import { RecordsApiError } from "../types";
import { ManualRecordForm } from "./manual-record-form";
import { RecordActionDialog } from "./record-action-dialog";
import { RecordDeliveryCard } from "./record-delivery-card";
import { RecordDetailBanner } from "./record-detail-banner";
import { RecordDetailHeader } from "./record-detail-header";
import { RecordDetailHero } from "./record-detail-hero";
import { RecordImageCard } from "./record-image-card";
import { RecordMovementsCard } from "./record-movements-card";
import { RecordDetailSkeleton } from "./record-detail-skeleton";
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
    return <RecordDetailSkeleton />;
  }

  if (!record) {
    return (
      <section
        className="flex flex-col items-center gap-4 rounded-lg border bg-card p-6 text-center shadow-card"
        role="alert"
      >
        <h1 className="text-xl/7 font-semibold">No se pudo cargar el registro</h1>
        <p className="text-sm text-muted-foreground">{recordQuery.error?.message ?? "El registro no está disponible."}</p>
        <div className="flex flex-wrap justify-center gap-3">
          <Button className="min-h-11 md:min-h-10" onClick={() => void recordQuery.refetch()} type="button" variant="outline">Reintentar</Button>
          <Link className={buttonVariants({ className: "min-h-11 md:min-h-10", variant: "outline" })} href="/records">Volver a registros</Link>
        </div>
      </section>
    );
  }

  const permissions = getRecordActionPermissions(record, canEdit);
  const { canCorrect } = permissions;
  const banner = getRecordBanner(record, canEdit);
  // DOM order matches the single-column visual order (Tab follows it). The
  // side column reuses the same order so both layouts stay consistent.
  const isInReview = record.status === "NEEDS_REVIEW";
  const imageCard = <RecordImageCard key="image" record={record} />;
  const deliveryCard = <RecordDeliveryCard key="delivery" canEdit={canEdit} record={record} />;
  const sideCards = isInReview ? [imageCard, deliveryCard] : [deliveryCard, imageCard];
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
    <section aria-labelledby="record-detail-heading" className="flex flex-col gap-4 md:gap-6">
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

      <div className="@container">
        <div className="flex flex-col gap-4 @[60rem]:grid @[60rem]:grid-cols-[minmax(0,1fr)_340px] @[60rem]:items-start @[60rem]:gap-6">
          <div className="@[60rem]:col-start-1 @[60rem]:row-start-1">
            <RecordDetailHero record={record} />
          </div>
          <div className="@[60rem]:col-start-1 @[60rem]:row-start-2">
            <RecordPeriodCard companyHint={companyHint} record={record} />
          </div>
          <div className="@[60rem]:col-start-1 @[60rem]:row-start-3">
            <RecordMovementsCard details={record.details} />
          </div>
          <div className="flex flex-col gap-4 @[60rem]:col-start-2 @[60rem]:row-span-4 @[60rem]:row-start-1 @[60rem]:gap-6">
            {sideCards}
          </div>
          <div className="@[60rem]:col-start-1 @[60rem]:row-start-4">
            <RecordHistoryPanel recordId={record.id} />
          </div>
        </div>
      </div>

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
