import { FormField } from "@/components/form-field";
import { Button } from "@/components/ui/button";
import { aboveBottomNav, textareaControl } from "@/lib/styles";
import { cn } from "@/lib/utils";

type CorrectionSaveBarProps = {
  dirtyCount: number;
  reason: string;
  onReasonChange: (reason: string) => void;
  onDiscard: () => void;
  canSave: boolean;
  isSubmitting: boolean;
};

/** Fixed bar for a record edit: unsaved change count, required reason and save. Submits the parent form. */
export function CorrectionSaveBar({
  dirtyCount,
  reason,
  onReasonChange,
  onDiscard,
  canSave,
  isSubmitting,
}: CorrectionSaveBarProps) {
  return (
    <>
      <div aria-hidden className="h-44" />
      <div
        aria-label="Guardar corrección"
        className={cn(
          "fixed inset-x-4 z-40 mx-auto max-w-6xl space-y-3 rounded-lg border bg-card p-4 shadow-card md:bottom-4",
          aboveBottomNav,
        )}
        role="region"
      >
        <div className="flex flex-wrap items-center justify-between gap-3">
          <p aria-live="polite" className="text-sm font-medium">
            {dirtyCount} {dirtyCount === 1 ? "cambio sin guardar" : "cambios sin guardar"}
          </p>
          <div className="flex gap-2">
            <Button disabled={isSubmitting} onClick={onDiscard} type="button" variant="outline">
              Descartar
            </Button>
            <Button disabled={isSubmitting || !canSave} type="submit">
              {isSubmitting ? "Guardando…" : "Guardar corrección"}
            </Button>
          </div>
        </div>
        <FormField id="manual-record-reason" label="Motivo de la corrección (obligatorio)">
          <textarea
            className={cn(textareaControl, "min-h-16")}
            id="manual-record-reason"
            maxLength={2000}
            onChange={(event) => onReasonChange(event.target.value)}
            value={reason}
          />
        </FormField>
      </div>
    </>
  );
}
