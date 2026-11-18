"use client";

import { useSearchParams } from "next/navigation";
import { FileText } from "lucide-react";
import { useEffect, useState } from "react";
import { Button } from "@/components/ui/button";
import { useResetOnHide } from "@/hooks/use-reset-on-hide";
import { useCompanies } from "@/features/companies/hooks/use-companies";
import { useRecords } from "../hooks/use-records";
import { parseRecordListQuery, recordListHref, type RecordListQuery } from "../record-filters";
import { hasActiveRecordFilters } from "../record-list-utils";
import { RecordsCardList } from "./records-card-list";
import { RecordsPagination } from "./records-pagination";
import { RecordsSkeleton } from "./records-skeleton";
import { RecordsTable } from "./records-table";
import { RecordsFilterBar, type RecordListFilters } from "./records-filter-bar";

const PAGE_SIZE = 15;

/** Updates the URL without a server round trip; Next syncs useSearchParams with it. */
function navigate(next: RecordListQuery) {
  window.history.replaceState(null, "", recordListHref(next));
}

export function RecordsScreen({ role }: { role: "EDITOR" | "VIEWER" }) {
  // The URL is the source of truth. With cacheComponents this screen stays mounted
  // (hidden by <Activity>) between visits, so copying the URL into state would go stale.
  const searchParams = useSearchParams();
  const { filters, search, page } = parseRecordListQuery(searchParams);
  const companiesQuery = useCompanies({ per_page: 100 });

  // Only the text being typed is local; it is synced to the URL after a debounce.
  const [searchInput, setSearchInput] = useState(search);
  const [syncedSearch, setSyncedSearch] = useState(search);
  if (search !== syncedSearch) {
    setSyncedSearch(search);
    setSearchInput(search);
  }

  // Drop an unsent draft when the screen is hidden, so a later visit starts from its URL.
  useResetOnHide(() => setSearchInput(search));

  useEffect(() => {
    const current = parseRecordListQuery(searchParams);
    const trimmed = searchInput.trim();
    if (trimmed === current.search) return;

    const timer = window.setTimeout(
      () => navigate({ filters: current.filters, search: trimmed, page: 1 }),
      300,
    );
    return () => window.clearTimeout(timer);
  }, [searchInput, searchParams]);

  const query = useRecords({ ...filters, ...(search ? { search } : {}), page, per_page: PAGE_SIZE });
  const rows = query.data?.data ?? [];
  const meta = query.data?.meta;

  function patchFilters(patch: Partial<RecordListFilters>) {
    navigate({ filters: { ...filters, ...patch }, search: searchInput.trim(), page: 1 });
  }

  function setPage(next: number) {
    navigate({ filters, search, page: next });
  }

  function clearFilters() {
    setSearchInput("");
    navigate({ filters: {}, search: "", page: 1 });
  }

  const filtered = hasActiveRecordFilters(search, filters);
  const pagination = (variant: "footer" | "stacked") =>
    meta && (
      <RecordsPagination
        currentPage={meta.current_page}
        isFetching={query.isFetching}
        lastPage={meta.last_page}
        onNext={() => setPage(Math.min(meta.last_page, page + 1))}
        onPrevious={() => setPage(Math.max(1, page - 1))}
        total={meta.total}
        variant={variant}
      />
    );

  return (
    <section aria-labelledby="records-heading" className="space-y-4 md:space-y-6">
      <h1 className="text-2xl leading-8 font-bold tracking-[-0.01em]" id="records-heading">Registros</h1>

      <RecordsFilterBar
        companies={companiesQuery.data?.data ?? []}
        filters={filters}
        hasActiveFilters={hasActiveRecordFilters(searchInput, filters)}
        onClear={clearFilters}
        onFiltersChange={patchFilters}
        onSearchChange={setSearchInput}
        searchInput={searchInput}
        total={meta?.total}
      />

      {query.isPending && (
        <div className="@container">
          <RecordsSkeleton />
        </div>
      )}
      {query.isError && (
        <div
          className="flex flex-col items-center gap-3 rounded-lg border border-border bg-card p-6 text-center shadow-card"
          role="alert"
        >
          <p>{query.error.message || "No se pudieron cargar los registros."}</p>
          <Button onClick={() => void query.refetch()} type="button" variant="outline">Reintentar</Button>
        </div>
      )}
      {query.isSuccess && rows.length === 0 && (
        <div className="flex flex-col items-center gap-2 rounded-lg border border-border bg-card px-6 py-14 text-center shadow-card">
          {filtered ? (
            <>
              <span className="grid size-12 place-items-center rounded-md bg-accent text-accent-foreground">
                <FileText aria-hidden="true" className="size-6" />
              </span>
              <h2 className="text-base leading-6 font-semibold">No hay registros con estos filtros</h2>
              <p className="text-muted-foreground">Prueba con otra semana o quita algún filtro.</p>
              <Button className="mt-2 h-11 md:h-10" onClick={clearFilters} type="button" variant="secondary">
                Limpiar filtros
              </Button>
            </>
          ) : (
            <p className="text-muted-foreground">Todavía no hay registros.</p>
          )}
        </div>
      )}
      {query.isSuccess && rows.length > 0 && (
        <div className="@container">
          <div className="hidden @[60rem]:block">
            <RecordsTable footer={pagination("footer")} records={rows} role={role} />
          </div>
          <div className="@[60rem]:hidden">
            <RecordsCardList records={rows} role={role} />
            {pagination("stacked")}
          </div>
        </div>
      )}
    </section>
  );
}
