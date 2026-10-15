"use client";

import Link from "next/link";
import { useEffect, useState } from "react";
import { Button } from "@/components/ui/button";
import { useCompanies } from "@/features/companies/hooks/use-companies";
import { recordSources, recordStatuses, type RecordListParams, type RecordStatus } from "../types";
import { useRecords } from "../hooks/use-records";

const PAGE_SIZE = 15;

const statusLabels: Record<RecordStatus, string> = {
  PENDING: "Pendiente",
  PROCESSING: "Procesando",
  NEEDS_REVIEW: "Necesita revisión",
  ERROR: "Error",
  CONFIRMED: "Confirmado",
  CANCELED: "Cancelado",
};

function money(value: string | null) {
  if (value === null) return "—";
  return new Intl.NumberFormat("es-MX", { style: "currency", currency: "MXN" }).format(Number(value));
}

export function RecordsScreen({ canCreate }: { canCreate: boolean }) {
  const [searchInput, setSearchInput] = useState("");
  const [search, setSearch] = useState("");
  const [page, setPage] = useState(1);
  const [filters, setFilters] = useState<Omit<RecordListParams, "search" | "page" | "per_page">>({});
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

  function setFilter<K extends keyof typeof filters>(key: K, value: (typeof filters)[K] | undefined) {
    setFilters((current) => ({ ...current, [key]: value }));
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

      <div className="grid gap-4 rounded-md border p-4 sm:grid-cols-2 lg:grid-cols-4">
        <div className="space-y-2 sm:col-span-2">
          <label className="text-sm font-medium" htmlFor="record-search">Buscar registros</label>
          <input
            className="h-10 w-full rounded-md border bg-background px-3 text-sm"
            id="record-search"
            maxLength={255}
            onChange={(event) => setSearchInput(event.target.value)}
            placeholder="Empresa, operación, afiliación o sucursal"
            type="search"
            value={searchInput}
          />
        </div>
        <FilterSelect label="Empresa" onChange={(value) => setFilter("company_id", value ? Number(value) : undefined)} value={filters.company_id?.toString() ?? ""}>
          <option value="">Todas</option>
          {(companiesQuery.data?.data ?? []).map((company) => <option key={company.id} value={company.id}>{company.name}</option>)}
        </FilterSelect>
        <FilterSelect label="Año" onChange={(value) => setFilter("year", value ? Number(value) : undefined)} value={filters.year?.toString() ?? ""}>
          <option value="">Todos</option>
          {Array.from({ length: 8 }, (_, index) => new Date().getFullYear() - index).map((year) => <option key={year} value={year}>{year}</option>)}
        </FilterSelect>
        <FilterSelect label="Mes (fecha final)" onChange={(value) => setFilter("month", value ? Number(value) : undefined)} value={filters.month?.toString() ?? ""}>
          <option value="">Todos</option>
          {Array.from({ length: 12 }, (_, index) => index + 1).map((month) => <option key={month} value={month}>{new Intl.DateTimeFormat("es-MX", { month: "long", timeZone: "UTC" }).format(new Date(Date.UTC(2026, month - 1, 1)))}</option>)}
        </FilterSelect>
        <FilterSelect label="Semana del negocio" onChange={(value) => setFilter("week", value ? Number(value) : undefined)} value={filters.week?.toString() ?? ""}>
          <option value="">Todas</option>
          {Array.from({ length: 53 }, (_, index) => index + 1).map((week) => <option key={week} value={week}>{week}</option>)}
        </FilterSelect>
        <FilterSelect label="Estado" onChange={(value) => setFilter("status", (value || undefined) as RecordStatus | undefined)} value={filters.status ?? ""}>
          <option value="">Todos</option>
          {recordStatuses.map((status) => <option key={status} value={status}>{statusLabels[status]}</option>)}
        </FilterSelect>
        <FilterSelect label="Origen" onChange={(value) => setFilter("source", (value || undefined) as "IMAGE" | "MANUAL" | undefined)} value={filters.source ?? ""}>
          <option value="">Todos</option>
          {recordSources.map((source) => <option key={source} value={source}>{source === "MANUAL" ? "Manual" : "Imagen"}</option>)}
        </FilterSelect>
      </div>

      {query.isPending && <p aria-live="polite" className="py-8 text-center text-sm text-muted-foreground">Cargando registros…</p>}
      {query.isError && (
        <div className="space-y-3 rounded-md border p-5 text-center" role="alert">
          <p>{query.error.message || "No se pudieron cargar los registros."}</p>
          <Button onClick={() => void query.refetch()} type="button" variant="outline">Reintentar</Button>
        </div>
      )}
      {query.isSuccess && rows.length === 0 && (
        <p className="rounded-md border px-4 py-8 text-center text-sm text-muted-foreground">
          {search || Object.values(filters).some(Boolean) ? "No se encontraron registros con esos criterios." : "Todavía no hay registros."}
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
                        {record.company.name}
                      </Link>
                    </th>
                    <td className="px-4 py-3">Semana {record.week} · {record.year}<br /><span className="text-muted-foreground">{record.week_start_date} – {record.week_end_date}</span></td>
                    <td className="px-4 py-3">{record.affiliation}<br /><span className="text-muted-foreground">{record.branch}</span></td>
                    <td className="px-4 py-3 text-right tabular-nums">{money(record.return_total)}</td>
                    <td className="px-4 py-3">{statusLabels[record.status]}</td>
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

function FilterSelect({
  children,
  label,
  onChange,
  value,
}: {
  children: React.ReactNode;
  label: string;
  onChange: (value: string) => void;
  value: string;
}) {
  const id = `record-filter-${label.toLowerCase().replace(/[^a-z]+/g, "-")}`;
  return (
    <div className="space-y-2">
      <label className="text-sm font-medium" htmlFor={id}>{label}</label>
      <select className="h-10 w-full rounded-md border bg-background px-3 text-sm" id={id} onChange={(event) => onChange(event.target.value)} value={value}>
        {children}
      </select>
    </div>
  );
}
