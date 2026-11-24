import { Skeleton } from "@/components/ui/skeleton";

/** Loading placeholder for the record detail. It never paints an amount. */
export function RecordDetailSkeleton() {
  return (
    <div className="flex flex-col gap-4 md:gap-6">
      <p aria-live="polite" className="sr-only">Cargando registro…</p>
      <div aria-hidden="true" className="flex flex-col gap-4 md:gap-6">
        <div className="space-y-3">
          <Skeleton className="h-5 w-24" />
          <Skeleton className="h-8 w-64 max-w-full" />
          <Skeleton className="h-6 w-80 max-w-full" />
        </div>
        <Skeleton className="h-40 w-full rounded-xl" />
        <div className="space-y-3 rounded-lg border bg-card p-5 shadow-card">
          <Skeleton className="h-5 w-40" />
          <Skeleton className="h-4 w-full" />
          <Skeleton className="h-4 w-2/3" />
        </div>
        <div className="space-y-3 rounded-lg border bg-card p-5 shadow-card">
          <Skeleton className="h-5 w-32" />
          <Skeleton className="h-4 w-full" />
          <Skeleton className="h-4 w-3/4" />
        </div>
      </div>
    </div>
  );
}
