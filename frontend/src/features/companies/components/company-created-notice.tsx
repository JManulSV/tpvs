import { CircleCheck, X } from "lucide-react";

/**
 * Success notice after creating a company. The live region stays mounted so
 * screen readers announce the message when it appears.
 */
export function CompanyCreatedNotice({
  message,
  onDismiss,
}: {
  message: string | null;
  onDismiss: () => void;
}) {
  return (
    <div aria-live="polite" role="status">
      {message && (
        <div className="flex items-start gap-3 rounded-md bg-status-confirmed-bg px-4 py-3 text-sm font-medium text-status-confirmed">
          <CircleCheck aria-hidden="true" className="size-5 shrink-0" />
          <span className="min-w-0 flex-1">{message}</span>
          <button
            aria-label="Cerrar aviso"
            className="-my-1.5 -mr-2 grid size-8 shrink-0 place-items-center rounded-full transition-colors hover:bg-status-confirmed/10 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
            onClick={onDismiss}
            type="button"
          >
            <X aria-hidden="true" className="size-4" />
          </button>
        </div>
      )}
    </div>
  );
}
