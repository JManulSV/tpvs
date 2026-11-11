import { Ban, Check, ChevronLeft, Image as ImageIcon, Pencil, RefreshCw, RotateCcw } from "lucide-react";
import Link from "next/link";
import { StatusBadge } from "@/components/status-badge";
import { Button } from "@/components/ui/button";
import type { RecordActionPermissions } from "../record-actions";
import { getRecordPeriodLine, getRecordTitle } from "../record-detail-utils";
import type { RecordListItem } from "../types";

const actionClass = "h-11 flex-1 md:h-10 md:flex-none";

export function RecordDetailHeader({
  isRetrying,
  onCancel,
  onConfirm,
  onEdit,
  onRetry,
  permissions,
  record,
}: {
  isRetrying: boolean;
  onCancel: () => void;
  onConfirm: () => void;
  onEdit: () => void;
  onRetry: () => void;
  permissions: RecordActionPermissions;
  record: RecordListItem;
}) {
  const { canCancel, canConfirm, canCorrect, canRetryAiProcessing } = permissions;
  const hasActions = canCancel || canConfirm || canCorrect || canRetryAiProcessing;
  const SourceIcon = record.source === "IMAGE" ? ImageIcon : Pencil;
  const EditIcon = record.status === "CONFIRMED" ? RotateCcw : Pencil;

  return (
    <header className="flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
      <div className="min-w-0 space-y-2">
        <Link
          className="inline-flex min-h-11 items-center gap-1 rounded-md text-sm font-medium text-accent-foreground outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background md:min-h-8"
          href="/records"
        >
          <ChevronLeft aria-hidden="true" className="size-4" />
          Registros
        </Link>
        <h1
          className="text-2xl/8 font-bold tracking-[-0.01em] break-words md:text-[28px]/9"
          id="record-detail-heading"
        >
          {getRecordTitle(record)}
        </h1>
        <div className="flex flex-wrap items-center gap-x-3 gap-y-2">
          <StatusBadge status={record.status} />
          <span className="inline-flex items-center gap-1.5 rounded-full border bg-card px-2.5 py-0.5 text-xs font-medium text-muted-foreground">
            <SourceIcon aria-hidden="true" className="size-3.5" />
            {record.source === "IMAGE" ? "Imagen" : "Manual"}
          </span>
          <span className="text-sm tabular-nums text-muted-foreground">{getRecordPeriodLine(record)}</span>
        </div>
      </div>

      {hasActions && (
        <div className="flex flex-wrap gap-2 md:justify-end">
          {canRetryAiProcessing && (
            <Button className={actionClass} disabled={isRetrying} onClick={onRetry} type="button" variant="outline">
              <RefreshCw aria-hidden="true" className={isRetrying ? "animate-spin motion-reduce:animate-none" : undefined} />
              {isRetrying ? "Reintentando…" : "Reintentar procesamiento"}
            </Button>
          )}
          {canCancel && (
            <Button
              className={`${actionClass} border-status-error text-status-error hover:bg-status-error-bg hover:text-status-error`}
              onClick={onCancel}
              type="button"
              variant="outline"
            >
              <Ban aria-hidden="true" />
              Cancelar registro
            </Button>
          )}
          {canConfirm && (
            <Button className={actionClass} onClick={onConfirm} type="button">
              <Check aria-hidden="true" />
              Confirmar registro
            </Button>
          )}
          {canCorrect && (
            <Button
              className={`${actionClass} bg-accent text-accent-foreground hover:bg-accent/80`}
              onClick={onEdit}
              type="button"
            >
              <EditIcon aria-hidden="true" />
              {record.status === "CONFIRMED" ? "Reabrir para corregir" : "Editar registro"}
            </Button>
          )}
        </div>
      )}
    </header>
  );
}
