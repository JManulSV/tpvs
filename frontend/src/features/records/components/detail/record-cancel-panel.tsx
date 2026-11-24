import { FormField } from "@/components/form-field";
import { Button } from "@/components/ui/button";
import { textareaControl } from "@/lib/styles";

type RecordCancelPanelProps = {
  reason: string;
  onReasonChange: (reason: string) => void;
  error: string | null;
  isPending: boolean;
  onBack: () => void;
  onConfirm: () => void;
};

/** Inline region (not a dialog) that asks for the required cancellation reason. */
export function RecordCancelPanel({ reason, onReasonChange, error, isPending, onBack, onConfirm }: RecordCancelPanelProps) {
  return (
    <section
      aria-labelledby="cancel-record-heading"
      className="space-y-3 rounded-lg border border-status-error bg-card p-5 shadow-card"
      role="region"
    >
      <div className="space-y-1">
        <h2 className="text-base/6 font-semibold" id="cancel-record-heading">
          Cancelar este registro
        </h2>
        <p className="text-sm text-muted-foreground">
          Un registro cancelado no cuenta en reportes ni totales. Esta acción queda en el historial.
        </p>
      </div>
      <FormField id="record-cancel-reason" label="Motivo (obligatorio)">
        <textarea
          className={textareaControl}
          disabled={isPending}
          id="record-cancel-reason"
          maxLength={2000}
          onChange={(event) => onReasonChange(event.target.value)}
          value={reason}
        />
      </FormField>
      {error && (
        <p className="text-sm text-destructive" role="alert">
          {error}
        </p>
      )}
      <div className="flex flex-wrap justify-end gap-3">
        <Button disabled={isPending} onClick={onBack} type="button" variant="outline">
          Volver
        </Button>
        <Button disabled={isPending || reason.trim() === ""} onClick={onConfirm} type="button" variant="destructive">
          {isPending ? "Cancelando…" : "Confirmar cancelación"}
        </Button>
      </div>
    </section>
  );
}
