"use client";

import { useEffect, useState } from "react";
import { Button } from "@/components/ui/button";
import { useCompanies } from "../hooks/use-companies";
import { CreateCompanyDialog } from "./create-company-dialog";

const PAGE_SIZE = 15;

export function CompaniesScreen({ canCreate }: { canCreate: boolean }) {
  const [searchInput, setSearchInput] = useState("");
  const [search, setSearch] = useState("");
  const [page, setPage] = useState(1);
  const [isCreateDialogOpen, setIsCreateDialogOpen] = useState(false);
  const [successMessage, setSuccessMessage] = useState<string | null>(null);

  useEffect(() => {
    const timer = window.setTimeout(() => {
      const nextSearch = searchInput.trim();
      setSearch(nextSearch);
      setPage(1);
    }, 300);

    return () => window.clearTimeout(timer);
  }, [searchInput]);

  const companiesQuery = useCompanies({
    ...(search ? { search } : {}),
    page,
    per_page: PAGE_SIZE,
  });
  const companies = companiesQuery.data?.data ?? [];
  const meta = companiesQuery.data?.meta;

  return (
    <section aria-labelledby="companies-heading" className="space-y-6">
      <header className="flex flex-wrap items-center justify-between gap-4">
        <div className="space-y-1">
          <h1 className="text-2xl font-semibold tracking-tight" id="companies-heading">
            Empresas
          </h1>
          <p className="text-sm text-muted-foreground">
            Consulta y administra el catálogo de empresas.
          </p>
        </div>
        {canCreate && (
          <Button onClick={() => setIsCreateDialogOpen(true)} type="button">
            + Nueva empresa
          </Button>
        )}
      </header>

      {successMessage && (
        <p aria-live="polite" className="rounded-md border px-4 py-3 text-sm" role="status">
          {successMessage}
        </p>
      )}

      <div className="space-y-4">
        <div className="max-w-md space-y-2">
          <label className="text-sm font-medium" htmlFor="company-search">
            Buscar empresa
          </label>
          <input
            className="flex h-10 w-full rounded-md border bg-background px-3 py-2 text-sm outline-none transition focus-visible:ring-2 focus-visible:ring-ring"
            id="company-search"
            maxLength={255}
            onChange={(event) => setSearchInput(event.target.value)}
            placeholder="Escribe el nombre de una empresa"
            type="search"
            value={searchInput}
          />
        </div>

        {companiesQuery.isPending && (
          <p aria-live="polite" className="py-8 text-center text-sm text-muted-foreground">
            Cargando empresas…
          </p>
        )}

        {companiesQuery.isError && (
          <div className="space-y-3 rounded-md border p-5 text-center" role="alert">
            <p className="text-sm">No se pudieron cargar las empresas.</p>
            <Button onClick={() => void companiesQuery.refetch()} type="button" variant="outline">
              Reintentar
            </Button>
          </div>
        )}

        {companiesQuery.isSuccess && companies.length === 0 && (
          <p className="rounded-md border px-4 py-8 text-center text-sm text-muted-foreground">
            {search
              ? "No se encontraron empresas con esa búsqueda."
              : "Todavía no hay empresas en el catálogo."}
          </p>
        )}

        {companiesQuery.isSuccess && companies.length > 0 && (
          <>
            <div className="overflow-x-auto rounded-md border">
              <table className="w-full min-w-[28rem] border-collapse text-left text-sm">
                <caption className="sr-only">Empresas y cantidad de registros confirmados</caption>
                <thead className="bg-muted/50">
                  <tr>
                    <th className="px-4 py-3 font-medium" scope="col">Empresa</th>
                    <th className="px-4 py-3 text-right font-medium" scope="col">
                      Registros confirmados
                    </th>
                  </tr>
                </thead>
                <tbody>
                  {companies.map((company) => (
                    <tr className="border-t" key={company.id}>
                      <th className="px-4 py-3 font-normal" scope="row">{company.name}</th>
                      <td className="px-4 py-3 text-right tabular-nums">
                        {company.records_count}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>

            {meta && meta.last_page > 1 && (
              <nav aria-label="Paginación de empresas" className="flex items-center justify-between gap-4">
                <p aria-live="polite" className="text-sm text-muted-foreground">
                  Página {meta.current_page} de {meta.last_page} · {meta.total} empresas
                </p>
                <div className="flex gap-2">
                  <Button
                    disabled={meta.current_page <= 1 || companiesQuery.isFetching}
                    onClick={() => setPage((current) => Math.max(1, current - 1))}
                    type="button"
                    variant="outline"
                  >
                    Anterior
                  </Button>
                  <Button
                    disabled={meta.current_page >= meta.last_page || companiesQuery.isFetching}
                    onClick={() => setPage((current) => Math.min(meta.last_page, current + 1))}
                    type="button"
                    variant="outline"
                  >
                    Siguiente
                  </Button>
                </div>
              </nav>
            )}
          </>
        )}
      </div>

      {isCreateDialogOpen && canCreate && (
        <CreateCompanyDialog
          onClose={() => setIsCreateDialogOpen(false)}
          onCreated={(companyName) => {
            setIsCreateDialogOpen(false);
            setSearchInput("");
            setSearch("");
            setPage(1);
            setSuccessMessage(`La empresa “${companyName}” se creó correctamente.`);
          }}
        />
      )}
    </section>
  );
}
