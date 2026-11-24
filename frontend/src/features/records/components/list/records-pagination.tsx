import { Button } from "@/components/ui/button";
import { cn } from "@/lib/utils";

type RecordsPaginationProps = {
  currentPage: number;
  lastPage: number;
  total: number;
  isFetching: boolean;
  onPrevious: () => void;
  onNext: () => void;
  /** "footer" sits inside the desktop table card; "stacked" goes below the mobile list, where the filter bar already shows the count. */
  variant: "footer" | "stacked";
};

export function RecordsPagination({
  currentPage,
  isFetching,
  lastPage,
  onNext,
  onPrevious,
  total,
  variant,
}: RecordsPaginationProps) {
  const footer = variant === "footer";

  return (
    <nav
      aria-label="Paginación de registros"
      className={cn(
        "flex flex-wrap items-center justify-between gap-x-6 gap-y-3 text-[13px] text-muted-foreground",
        footer ? "border-t border-border bg-muted px-6 py-3" : "mt-4",
      )}
    >
      {footer && (
        <span>
          <strong className="font-bold text-foreground tabular-nums">{total}</strong>{" "}
          {total === 1 ? "registro" : "registros"}
        </span>
      )}
      <div className={cn("flex items-center gap-3", !footer && "ml-auto")}>
        <span aria-live="polite" className="tabular-nums">
          Página {currentPage} de {lastPage}
        </span>
        <Button
          className={footer ? undefined : "h-11 px-5"}
          disabled={currentPage <= 1 || isFetching}
          onClick={onPrevious}
          type="button"
          variant="outline"
        >
          Anterior
        </Button>
        <Button
          className={footer ? undefined : "h-11 px-5"}
          disabled={currentPage >= lastPage || isFetching}
          onClick={onNext}
          type="button"
          variant="outline"
        >
          Siguiente
        </Button>
      </div>
    </nav>
  );
}
