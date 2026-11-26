"use client";

import { useState } from "react";
import { LoadError } from "@/components/load-error";
import { useResetOnHide } from "@/hooks/use-reset-on-hide";
import { useCancelRecord, useConfirmRecord, useRecord, useRetryAiProcessing } from "../../hooks/use-records";
import { RecordsApiError, type RecordStatus } from "../../types";
import { getRecordActionPermissions } from "../../utils/actions";
import { getRecordBanner } from "../../utils/detail";
import { ManualRecordForm } from "../capture/manual-record-form";
import { RecordActionDialog } from "../record-action-dialog";
import { RecordEditor } from "../record-editor";
import { RecordCancelPanel } from "./record-cancel-panel";
import { RecordDeliveryCard } from "./record-delivery-card";
import { RecordDetailBanner } from "./record-detail-banner";
import { RecordDetailHeader } from "./record-detail-header";
import { RecordDetailHero } from "./record-detail-hero";
import { RecordDetailSkeleton } from "./record-detail-skeleton";
import { RecordHistoryPanel } from "./record-history-panel";
import { RecordImageCard } from "./record-image-card";
import { RecordMovementsCard } from "./record-movements-card";
import { RecordPeriodCard } from "./record-period-card";
import { RecordProcessingCard } from "./record-processing-card";

export function RecordDetailScreen({ canEdit, recordId }: { canEdit: boolean; recordId: number }) {
  const recordQuery = useRecord(recordId);
  const confirmRecord = useConfirmRecord();
  const cancelRecord = useCancelRecord();
  const retryAiProcessing = useRetryAiProcessing();
  const [isEditing, setIsEditing] = useState(false);
  const [isConfirmOpen, setIsConfirmOpen] = useState(false);
  const [isCancelOpen, setIsCancelOpen] = useState(false);
  // Status when editing started (for the reopen notice) and unsaved changes (they block confirming).
  const [editStartStatus, setEditStartStatus] = useState<RecordStatus | null>(null);
  const [editDirtyCount, setEditDirtyCount] = useState(0);
  const [reopenNotice, setReopenNotice] = useState(false);
  const [confirmError, setConfirmError] = useState<string | null>(null);
  const [cancelError, setCancelError] = useState<string | null>(null);
  const [retryError, setRetryError] = useState<string | null>(null);
  const [cancelReason, setCancelReason] = useState("");
  const [successMessage, setSuccessMessage] = useState<string | null>(null);
  const record = recordQuery.data;

  // Dialogs and action messages belong to one visit. An edit in progress is a draft and stays.
  useResetOnHide(() => {
    setIsConfirmOpen(false);
    setIsCancelOpen(false);
    setCancelReason("");
    setConfirmError(null);
    setCancelError(null);
    setRetryError(null);
    setSuccessMessage(null);
    setReopenNotice(false);
  });

  if (recordQuery.isPending) {
    return <RecordDetailSkeleton />;
  }

  if (!record) {
    return (
      <LoadError
        back={{ href: "/records", label: "Volver a registros" }}
        message={recordQuery.error?.message ?? "El registro no está disponible."}
        onRetry={() => void recordQuery.refetch()}
        title="No se pudo cargar el registro"
      />
    );
  }

  const permissions = getRecordActionPermissions(record, canEdit);
  const { canCorrect } = permissions;
  // IMAGE records awaiting review show the extracted data with field chips.
  const usesEditor = record.source === "IMAGE" && record.status === "NEEDS_REVIEW";
  const banner = usesEditor ? null : getRecordBanner(record, canEdit);
  // Side column order: image, AI processing, delivery. DOM order matches the single-column layout.
  const imageCard = record.image !== null ? <RecordImageCard key="image" record={record} /> : null;
  const processingCard = record.source === "IMAGE" ? <RecordProcessingCard key="processing" record={record} /> : null;
  const deliveryCard = <RecordDeliveryCard key="delivery" canEdit={canEdit} record={record} />;
  const sideCards = [imageCard, processingCard, deliveryCard];
  const companyHint = record.company === null
    ? record.ai_processing?.normalized_extraction?.record.company_name?.value ?? null
    : null;

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

  function startEditing() {
    setEditStartStatus(record?.status ?? null);
    setIsEditing(true);
  }

  function cancelEditing() {
    setEditDirtyCount(0);
    setIsEditing(false);
  }

  function finishEditing() {
    // Saving an edit moves a CONFIRMED record back to NEEDS_REVIEW; say so once.
    if (editStartStatus === "CONFIRMED") setReopenNotice(true);
    setEditStartStatus(null);
    setEditDirtyCount(0);
    setIsEditing(false);
  }

  function closeCancelPanel() {
    setIsCancelOpen(false);
    setCancelError(null);
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
        confirmBlocked={isEditing && editDirtyCount > 0}
        onCancel={openCancelDialog}
        onConfirm={openConfirmDialog}
        onEdit={startEditing}
        onRetry={() => void handleRetry()}
        permissions={permissions}
        record={record}
      />

      {isCancelOpen && (
        <RecordCancelPanel
          error={cancelError}
          isPending={cancelRecord.isPending}
          onBack={closeCancelPanel}
          onConfirm={() => void handleCancel()}
          onReasonChange={setCancelReason}
          reason={cancelReason}
        />
      )}

      {banner !== null && <RecordDetailBanner banner={banner} />}
      {successMessage && <RecordDetailBanner banner={{ tone: "info", title: successMessage, text: null }} />}
      {retryError && <RecordDetailBanner banner={{ tone: "error", title: retryError, text: null }} />}
      {reopenNotice && record.status === "NEEDS_REVIEW" && (
        <RecordDetailBanner
          banner={{
            tone: "review",
            title: "Este registro se reabrió para corregirlo.",
            text: "Mientras esté Por revisar no cuenta en los reportes. Al confirmarlo vuelve a incluirse.",
          }}
        />
      )}

      <div className="@container">
        <div className="flex flex-col gap-4 @[60rem]:grid @[60rem]:grid-cols-[minmax(0,1fr)_340px] @[60rem]:items-start @[60rem]:gap-6">
          {isEditing && canCorrect ? (
            <div className="@[60rem]:col-start-1 @[60rem]:row-span-3 @[60rem]:row-start-1">
              <ManualRecordForm
                mode="edit"
                onCancelEdit={cancelEditing}
                onDirtyChange={setEditDirtyCount}
                onEditComplete={finishEditing}
                record={record}
              />
            </div>
          ) : usesEditor ? (
            <div className="@[60rem]:col-start-1 @[60rem]:row-span-3 @[60rem]:row-start-1">
              <RecordEditor canEdit={canEdit} record={record} />
            </div>
          ) : (
            <>
              <div className="@[60rem]:col-start-1 @[60rem]:row-start-1">
                <RecordDetailHero record={record} />
              </div>
              <div className="@[60rem]:col-start-1 @[60rem]:row-start-2">
                <RecordPeriodCard companyHint={companyHint} record={record} />
              </div>
              <div className="@[60rem]:col-start-1 @[60rem]:row-start-3">
                <RecordMovementsCard record={record} />
              </div>
            </>
          )}
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
    </section>
  );
}
