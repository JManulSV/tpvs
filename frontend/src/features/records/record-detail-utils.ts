import { getMonthName } from "@/features/dashboard/utils";
import type { Delivery } from "@/features/deliveries/types";
import { formatDate } from "@/lib/format";
import type { RecordStatus } from "@/lib/status";
import type { RecordListItem } from "./types";

export type RecordBanner = {
  tone: "info" | "review" | "cancel" | "error";
  title: string;
  text: string | null;
};

export type DeliveryCardState = {
  chip: string;
  tone: "ready" | "stale" | "none";
  actionLabel: string | null;
};

const heroNotes: Record<RecordStatus, string> = {
  CONFIRMED: "Registro confirmado. Se incluye en los totales y reportes oficiales.",
  NEEDS_REVIEW: "Pendiente de revisión. Todavía no cuenta en los totales.",
  CANCELED: "Registro cancelado. No se incluye en los reportes.",
  PENDING: "Se mostrará cuando termine el procesamiento.",
  PROCESSING: "Se mostrará cuando termine el procesamiento.",
  ERROR: "El procesamiento falló y no hay importes.",
};

const pendingReviewBanner: RecordBanner = {
  tone: "review",
  title: "Pendiente de revisión.",
  text: "Este registro todavía no está confirmado y no cuenta en los totales.",
};

export function getRecordTitle(record: Pick<RecordListItem, "company">): string {
  return record.company?.name ?? "Registro sin empresa";
}

export function getRecordPeriodLine(record: RecordListItem): string {
  if (record.week === null) return "Sin periodo todavía";

  return `Semana ${record.week} · ${formatDate(record.week_start_date)} – ${formatDate(record.week_end_date)}`;
}

/** Month name of a `YYYY-MM-DD` string, read from its digits (no `Date`). */
export function getReportMonthName(weekEndDate: string | null): string | null {
  if (weekEndDate === null) return null;

  const match = /^\d{4}-(\d{2})-\d{2}$/.exec(weekEndDate);
  if (!match) return null;

  return getMonthName(Number(match[1])) || null;
}

export function getHeroNote(status: RecordStatus): string {
  return heroNotes[status];
}

export function getRecordBanner(
  record: Pick<RecordListItem, "status" | "source">,
  canEdit: boolean,
): RecordBanner | null {
  switch (record.status) {
    case "NEEDS_REVIEW":
      if (canEdit && record.source === "IMAGE") {
        return {
          tone: "info",
          title: "Información extraída automáticamente. Revisa antes de confirmar.",
          text: null,
        };
      }

      return pendingReviewBanner;
    case "PENDING":
    case "PROCESSING":
      return {
        tone: "info",
        title: "El registro se está procesando.",
        text: "Esta pantalla se actualiza automáticamente hasta que termine.",
      };
    case "ERROR":
      return {
        tone: "error",
        title: "El procesamiento falló.",
        text: "Un Editor puede reintentar el procesamiento o cancelar el registro.",
      };
    case "CANCELED":
      return {
        tone: "cancel",
        title: "Registro cancelado.",
        text: "No afecta los totales ni los reportes. La imagen ya no está disponible, pero los datos se conservan.",
      };
    case "CONFIRMED":
      return null;
  }
}

export function getDeliveryCardState(
  status: RecordStatus,
  delivery: Delivery | null | undefined,
  canEdit: boolean,
): DeliveryCardState {
  if (status !== "CONFIRMED") {
    return { chip: "Bloqueada", tone: "none", actionLabel: null };
  }

  if (!delivery) {
    return {
      chip: "Sin entrega",
      tone: "none",
      actionLabel: canEdit ? "Preparar entrega" : null,
    };
  }

  if (delivery.is_stale) {
    return {
      chip: "Desactualizada",
      tone: "stale",
      actionLabel: canEdit ? "Preparar nueva entrega" : "Ver entrega",
    };
  }

  return {
    chip: "Preparada",
    tone: "ready",
    actionLabel: canEdit ? "Editar entrega" : "Ver entrega",
  };
}

const deliveryFields = new Set([
  "document_generated",
  "delivery_date",
  "delivered_by_name",
  "received_by_name",
  "prepared_return_amount",
]);

export function getHistoryDotClass(fieldName: string, newValue: string | null): string {
  if (fieldName === "record_created") return "bg-status-pending";

  if (fieldName === "status") {
    if (newValue === "CONFIRMED") return "bg-status-confirmed";
    if (newValue === "NEEDS_REVIEW") return "bg-status-needs-review";
    if (newValue === "CANCELED") return "bg-status-canceled";
  }

  if (deliveryFields.has(fieldName) || fieldName.startsWith("denominations")) {
    return "bg-status-processing";
  }

  return "bg-accent-foreground";
}
