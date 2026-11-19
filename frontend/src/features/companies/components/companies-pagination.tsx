import { Button } from "@/components/ui/button";
import { cn } from "@/lib/utils";

type CompaniesPaginationProps = {
  currentPage: number;
  lastPage: number;
  isFetching: boolean;
  onPrevious: () => void;
  onNext: () => void;
  /** "footer" sits inside the desktop table card; "stacked" goes below the mobile cards with 44 px buttons. */
  variant: "footer" | "stacked";
};

/** «Página X de Y» with «Anterior» / «Siguiente». Rendered only when there is more than one page. */
export function CompaniesPagination({
  currentPage,
  isFetching,
  lastPage,
  onNext,
  onPrevious,
  variant,
}: CompaniesPaginationProps) {
  const buttonClass = variant === "stacked" ? "h-11 px-5" : undefined;

  return (
    <nav
      aria-label="Paginación de empresas"
      className={cn(
        "flex items-center gap-3 text-[13px] text-muted-foreground",
        variant === "stacked" && "justify-end",
      )}
    >
      <span aria-live="polite" className="tabular-nums">
        Página {currentPage} de {lastPage}
      </span>
      <Button
        className={buttonClass}
        disabled={currentPage <= 1 || isFetching}
        onClick={onPrevious}
        type="button"
        variant="outline"
      >
        Anterior
      </Button>
      <Button
        className={buttonClass}
        disabled={currentPage >= lastPage || isFetching}
        onClick={onNext}
        type="button"
        variant="outline"
      >
        Siguiente
      </Button>
    </nav>
  );
}
