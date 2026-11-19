import { Skeleton } from "@/components/ui/skeleton";

const rows = [0, 1, 2, 3, 4];

/** Loading placeholder for both list presentations. The caller's `@container` decides which one shows. */
export function CompaniesSkeleton() {
  return (
    <>
      <p aria-live="polite" className="sr-only">Cargando empresas…</p>
      <div
        aria-hidden="true"
        className="hidden overflow-hidden rounded-lg border border-border bg-card shadow-card @[40rem]:block"
      >
        <div className="h-10 bg-muted" />
        {rows.map((row) => (
          <div className="flex h-16 items-center gap-4 border-t border-border px-6" key={row}>
            <Skeleton className="size-10 shrink-0" />
            <Skeleton className="h-4 w-48" />
            <Skeleton className="ml-auto h-4 w-8" />
            <Skeleton className="ml-12 h-4 w-16" />
            <Skeleton className="ml-12 h-4 w-24" />
          </div>
        ))}
      </div>
      <div aria-hidden="true" className="flex flex-col gap-3 @[40rem]:hidden">
        <Skeleton className="h-4 w-24" />
        {rows.map((row) => (
          <div
            className="flex items-center gap-3 rounded-lg border border-border bg-card p-4 shadow-card"
            key={row}
          >
            <Skeleton className="size-10 shrink-0" />
            <div className="flex flex-1 flex-col gap-2">
              <Skeleton className="h-5 w-40" />
              <Skeleton className="h-3 w-48" />
            </div>
          </div>
        ))}
      </div>
    </>
  );
}
