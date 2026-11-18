"use client";

import { Button } from "@/components/ui/button";
import type { RecordStatus } from "@/features/records/types";
import { useGenerateDeliveryDocument } from "../hooks/use-deliveries";
import {
  DeliveryApiError,
  documentBlockedMessages,
  type Delivery,
  type GenerateDeliveryDocumentInput,
} from "../types";
import { denominationBreakdownKey, getCashCheck, getDeliveryReadiness } from "../utils";

export function DeliveryDocumentAction({
  delivery,
  hasImage,
  onBlocked,
  recordStatus,
  draftCashBreakdownKey,
  documentReturnAmount,
  customerWillGiveChange,
}: {
  delivery: Delivery | null;
  hasImage: boolean;
  onBlocked: () => void;
  recordStatus: RecordStatus;
  draftCashBreakdownKey: string | null;
  documentReturnAmount: string;
  customerWillGiveChange: boolean;
}) {
  const mutation = useGenerateDeliveryDocument();
  const hasUnsavedCashChanges = delivery !== null
    && draftCashBreakdownKey !== null
    && draftCashBreakdownKey !== denominationBreakdownKey(delivery.denominations);
  const readiness = getDeliveryReadiness({
    hasImage,
    cash: getCashCheck({
      officialAmount: delivery?.current_return_amount ?? "0.00",
      documentAmount: documentReturnAmount,
      denominations: delivery?.denominations ?? [],
    }),
    changeDeclared: customerWillGiveChange,
    clean: delivery !== null && !delivery.is_stale && !hasUnsavedCashChanges,
    missingData: false,
    generated: false,
  });
  const blockedReason = recordStatus !== "CONFIRMED"
    ? documentBlockedMessages.INVALID_RECORD_STATE
    : readiness.canGenerate ? null : readiness.hint;
  const error = mutation.error instanceof DeliveryApiError ? mutation.error : null;

  function handleGenerate() {
    if (delivery === null || blockedReason !== null || mutation.isPending) return;

    mutation.mutate(
      {
        deliveryId: delivery.id,
        recordId: delivery.record_id,
        input: {
          document_return_amount: documentReturnAmount,
          customer_will_give_change: customerWillGiveChange,
        } satisfies GenerateDeliveryDocumentInput,
      },
      {
        onError: (mutationError) => {
          if (mutationError instanceof DeliveryApiError && mutationError.status === 409) onBlocked();
        },
      },
    );
  }

  return (
    <section aria-labelledby="delivery-document-heading" className="space-y-3 rounded-md border p-4">
      <h2 className="text-lg font-semibold" id="delivery-document-heading">Documento Word</h2>

      <div className="flex flex-wrap items-center gap-3">
        <Button
          aria-describedby={blockedReason !== null ? "delivery-document-blocked" : undefined}
          disabled={blockedReason !== null || mutation.isPending}
          onClick={handleGenerate}
          type="button"
        >
          {mutation.isPending ? "Generando documento…" : "Generar documento Word"}
        </Button>
      </div>

      {blockedReason !== null && (
        <p className="text-sm text-muted-foreground" id="delivery-document-blocked" role="note">{blockedReason}</p>
      )}

      {mutation.isSuccess && (
        <p className="text-sm" role="status">Documento generado y descargado.</p>
      )}

      {mutation.isError && (
        <p className="rounded-md border border-destructive/40 px-4 py-3 text-sm text-destructive" role="alert">
          {error?.message ?? "No se pudo generar el documento. Inténtalo nuevamente."}
        </p>
      )}
    </section>
  );
}
