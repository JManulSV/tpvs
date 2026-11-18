import { FileText } from "lucide-react";
import Link from "next/link";
import { Button, buttonVariants } from "@/components/ui/button";
import { cn } from "@/lib/utils";
import type { DeliveryReadiness } from "../utils";

const actionClass = "h-11 flex-1 px-5 md:h-10 md:flex-none";

export function DeliveryActionBar({
  recordId,
  readiness,
  clean,
  saveLabel,
  isSaving,
  isGenerating,
  onGenerate,
}: {
  recordId: number;
  readiness: DeliveryReadiness;
  clean: boolean;
  saveLabel: string;
  isSaving: boolean;
  isGenerating: boolean;
  onGenerate: () => void;
}) {
  return (
    <div
      aria-label="Acciones de la entrega"
      className={cn(
        "fixed inset-x-4 bottom-[calc(5.25rem+env(safe-area-inset-bottom))] z-40",
        "flex flex-wrap items-center justify-between gap-x-4 gap-y-3 rounded-lg border bg-card px-4 py-3 shadow-popover",
        "md:sticky md:inset-x-auto md:bottom-4 md:px-5 md:py-3.5",
      )}
      role="region"
    >
      <p
        className={cn(
          "min-w-0 text-[13px]/5",
          readiness.hintTone === "ok" ? "font-semibold text-status-confirmed" : "font-medium text-muted-foreground",
        )}
        role="status"
      >
        {readiness.hint}
      </p>
      <div className="flex w-full flex-wrap gap-2 md:w-auto">
        <Link className={buttonVariants({ className: actionClass, variant: "ghost" })} href={`/records/${recordId}`}>
          Cancelar
        </Link>
        {!clean && (
          <Button className={actionClass} disabled={!readiness.canSave || isSaving} type="submit">
            {isSaving ? "Guardando…" : saveLabel}
          </Button>
        )}
        <Button
          className={actionClass}
          disabled={!readiness.canGenerate || isGenerating}
          onClick={onGenerate}
          type="button"
          variant={clean ? "default" : "outline"}
        >
          <FileText aria-hidden="true" />
          {isGenerating ? "Generando…" : "Generar recibo (Word)"}
        </Button>
      </div>
    </div>
  );
}
