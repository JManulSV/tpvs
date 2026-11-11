"use client";

import Link from "next/link";
import { useSearchParams } from "next/navigation";
import { useEffect, useState } from "react";
import { Button } from "@/components/ui/button";
import { StatusBadge } from "@/components/status-badge";
import { useCompanies } from "@/features/companies/hooks/use-companies";
import { formatMoney } from "@/lib/format";
import { useRecords } from "../hooks/use-records";
import { parseInitialRecordFilters } from "../record-filters";
import { hasActiveRecordFilters } from "../record-list-utils";
import { RecordsFilterBar, type RecordListFilters } from "./records-filter-bar";

const PAGE_SIZE = 15;

export function RecordsScreen({ canCreate }: { canCreate: boolean }) {
  const [searchInput, setSearchInput] = useState("");
  const [search, setSearch] = useState("");
  const [page, setPage] = useState(1);
  const searchParams = useSearchParams();
  // Read once on entry; later filter changes are not written back to the URL.
  const [filters, setFilters] = useState<RecordListFilters>(
    () => parseInitialRecordFilters(searchParams),
  );
  const companiesQuery = useCompanies({ per_page: 100 });

  useEffect(() => {
    const timer = window.setTimeout(() => {
      setSearch(searchInput.trim());
      setPage(1);
    }, 300);
    return () => window.clearTimeout(timer);
  }, [searchInput]);

  const query = useRecords({ ...filters, ...(search ? { search } : {}), page, per_page: PAGE_SIZE });
  const rows = query.data?.data ?? [];
  const meta = query.data?.meta;

  function patchFilters(patch: Partial<RecordListFilters>) {
    setFilters((current) => ({ ...current, ...patch }));
    setPage(1);
  }

  function clearFilters() {
    setSearchInput("");
    setSearch("");
    setFilters({});
    setPage(1);
  }

  return (
    <section aria-labelledby="records-heading" className="space-y-6">
      <header className="flex flex-wrap items-center justify-between gap-4">
        <div className="space-y-1">
          <h1 className="text-2xl font-semibold tracking-tight" id="records-heading">Registros</h1>
          <p className="text-sm text-muted-foreground">Consulta y revisa los registros del sistema.</p>
        </div>
        {canCreate && <Link className="inline-flex h-9 items-center justify-center rounded-lg bg-primary px-3 text-sm font-medium text-primary-foreground hover:bg-primary/80 focus-visible:outline-2 focus-visible:outline-ring" href="/records/new">+ Nuevo registro</Link>}
      </header>

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

      {query.isPending && <p aria-live="polite" className="py-8 text-center text-sm text-muted-foreground">Cargando registros…</p>}
      {query.isError && (
        <div className="space-y-3 rounded-md border p-5 text-center" role="alert">
          <p>{query.error.message || "No se pudieron cargar los registros."}</p>
          <Button onClick={() => void query.refetch()} type="button" variant="outline">Reintentar</Button>
        </div>
      )}
      {query.isSuccess && rows.length === 0 && (
        <p className="rounded-md border px-4 py-8 text-center text-sm text-muted-foreground">
          {hasActiveRecordFilters(search, filters) ? "No se encontraron registros con esos criterios." : "Todavía no hay registros."}
        </p>
      )}
      {query.isSuccess && rows.length > 0 && (
        <>
          <div className="overflow-x-auto rounded-md border">
            <table className="w-full min-w-[58rem] border-collapse text-left text-sm">
              <caption className="sr-only">Registros, período, total de retorno, estado y origen</caption>
              <thead className="bg-muted/50">
                <tr>
                  <th className="px-4 py-3 font-medium" scope="col">Empresa</th>
                  <th className="px-4 py-3 font-medium" scope="col">Período</th>
                  <th className="px-4 py-3 font-medium" scope="col">Afiliación / sucursal</th>
                  <th className="px-4 py-3 text-right font-medium" scope="col">Retorno</th>
                  <th className="px-4 py-3 font-medium" scope="col">Estado</th>
                  <th className="px-4 py-3 font-medium" scope="col">Origen</th>
                </tr>
              </thead>
              <tbody>
                {rows.map((record) => (
                  <tr className="border-t hover:bg-muted/30" key={record.id}>
                    <th className="px-4 py-3 font-medium" scope="row">
                      <Link className="underline-offset-4 hover:underline focus-visible:outline-2" href={`/records/${record.id}`}>
                        {record.company?.name ?? "Sin empresa"}
                      </Link>
                    </th>
                    <td className="px-4 py-3">{record.week ? `Semana ${record.week}` : "Sin semana"}{record.year ? ` · ${record.year}` : ""}<br /><span className="text-muted-foreground">{record.week_start_date ?? "—"} – {record.week_end_date ?? "—"}</span></td>
                    <td className="px-4 py-3">{record.details.length ? record.details.map((detail) => <span className="block" key={detail.id}>{detail.affiliation ?? "—"}<br /><span className="text-muted-foreground">{detail.branch ?? "—"}</span></span>) : "—"}</td>
                    <td className="px-4 py-3 text-right tabular-nums">{formatMoney(record.return_total)}</td>
                    <td className="px-4 py-3"><StatusBadge status={record.status} /></td>
                    <td className="px-4 py-3">{record.source === "MANUAL" ? "Manual" : "Imagen"}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          {meta && (
            <nav aria-label="Paginación de registros" className="flex items-center justify-between gap-4">
              <p aria-live="polite" className="text-sm text-muted-foreground">Página {meta.current_page} de {meta.last_page} · {meta.total} registros</p>
              <div className="flex gap-2">
                <Button disabled={meta.current_page <= 1 || query.isFetching} onClick={() => setPage((current) => Math.max(1, current - 1))} type="button" variant="outline">Anterior</Button>
                <Button disabled={meta.current_page >= meta.last_page || query.isFetching} onClick={() => setPage((current) => Math.min(meta.last_page, current + 1))} type="button" variant="outline">Siguiente</Button>
              </div>
            </nav>
          )}
        </>
      )}
    </section>
  );
}
