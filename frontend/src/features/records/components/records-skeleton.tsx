import { Skeleton } from "@/components/ui/skeleton";

const rows = [0, 1, 2, 3, 4];

/** Loading placeholder for both list presentations. The caller's `@container` decides which one shows. */
export function RecordsSkeleton() {
  return (
    <>
      <p aria-live="polite" className="sr-only">Cargando registros…</p>
      <div aria-hidden="true" className="hidden overflow-hidden rounded-lg border border-border bg-card shadow-card @[60rem]:block">
        <div className="h-10 bg-muted" />
        {rows.map((row) => (
          <div className="flex h-16 items-center gap-6 border-t border-border px-6" key={row}>
            <div className="flex flex-1 flex-col gap-2">
              <Skeleton className="h-4 w-48" />
              <Skeleton className="h-3 w-64" />
            </div>
            <Skeleton className="h-4 w-12" />
            <Skeleton className="h-4 w-20" />
            <Skeleton className="h-4 w-16" />
            <Skeleton className="h-4 w-20" />
            <Skeleton className="h-6 w-24 rounded-full" />
          </div>
        ))}
      </div>
      <div aria-hidden="true" className="flex flex-col gap-3 @[60rem]:hidden">
        {rows.map((row) => (
          <div className="flex flex-col gap-2 rounded-lg border border-border bg-card p-4 shadow-card" key={row}>
            <div className="flex items-start justify-between gap-3">
              <Skeleton className="h-5 w-40" />
              <Skeleton className="h-6 w-20 rounded-full" />
            </div>
            <Skeleton className="h-3 w-56" />
            <Skeleton className="h-3 w-44" />
            <Skeleton className="mt-2 h-6 w-28" />
          </div>
        ))}
      </div>
    </>
  );
}
