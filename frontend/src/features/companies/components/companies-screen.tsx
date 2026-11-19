"use client";

import { Plus } from "lucide-react";
import { useCallback, useEffect, useRef, useState } from "react";
import { Button } from "@/components/ui/button";
import { Dialog, DialogContent } from "@/components/ui/dialog";
import { Sheet, SheetContent } from "@/components/ui/sheet";
import { useIsMobile } from "@/hooks/use-mobile";
import { useResetOnHide } from "@/hooks/use-reset-on-hide";
import { useCompanies } from "../hooks/use-companies";
import type { CompanyDetail } from "../types";
import { CreateCompanyForm } from "./create-company-form";

const PAGE_SIZE = 15;

export function CompaniesScreen({ canCreate }: { canCreate: boolean }) {
  const [searchInput, setSearchInput] = useState("");
  const [search, setSearch] = useState("");
  const [page, setPage] = useState(1);
  const [isCreateOpen, setIsCreateOpen] = useState(false);
  const [successMessage, setSuccessMessage] = useState<string | null>(null);
  const [createdCompanyId, setCreatedCompanyId] = useState<number | null>(null);
  const [isCreateSubmitting, setIsCreateSubmitting] = useState(false);
  const isMobile = useIsMobile();
  const nameInputRef = useRef<HTMLInputElement | null>(null);
  const createOpenerRef = useRef<HTMLElement | null>(null);

  // A later visit must not reopen the form, show the previous notice or highlight a row.
  useResetOnHide(() => {
    setIsCreateOpen(false);
    setSuccessMessage(null);
    setCreatedCompanyId(null);
  });

  function openCreate(event: React.MouseEvent<HTMLElement>) {
    // Focus returns to whichever button opened the form (header, FAB or empty state).
    createOpenerRef.current = event.currentTarget;
    setSuccessMessage(null);
    setCreatedCompanyId(null);
    setIsCreateOpen(true);
  }

  function handleCreateOpenChange(open: boolean) {
    // Escape and outside clicks must not close the container mid-request.
    if (!open && isCreateSubmitting) return;
    setIsCreateOpen(open);
  }

  function handleCreated(company: CompanyDetail) {
    setIsCreateOpen(false);
    setSearchInput("");
    setSearch("");
    setPage(1);
    setCreatedCompanyId(company.id);
    setSuccessMessage(`Creaste la empresa ${company.name}.`);
  }

  const handleSubmittingChange = useCallback((submitting: boolean) => {
    setIsCreateSubmitting(submitting);
  }, []);

  const createForm = (
    <CreateCompanyForm
      inputRef={nameInputRef}
      layout={isMobile ? "sheet" : "dialog"}
      onCancel={() => setIsCreateOpen(false)}
      onCreated={handleCreated}
      onSubmittingChange={handleSubmittingChange}
    />
  );

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
          <Button className="hidden md:inline-flex" onClick={openCreate} type="button">
            <Plus aria-hidden="true" />
            Nueva empresa
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

      {canCreate && (
        <button
          className="fixed right-4 bottom-[calc(5.25rem+env(safe-area-inset-bottom))] z-30 inline-flex h-12 items-center gap-2 rounded-full bg-primary px-5 text-sm font-semibold text-primary-foreground shadow-popover transition-colors hover:bg-primary/90 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring md:hidden"
          onClick={openCreate}
          type="button"
        >
          <Plus aria-hidden="true" className="size-5" />
          Nueva empresa
        </button>
      )}

      {canCreate &&
        (isMobile ? (
          <Sheet
            disablePointerDismissal={isCreateSubmitting}
            onOpenChange={handleCreateOpenChange}
            open={isCreateOpen}
          >
            <SheetContent
              className="gap-5 rounded-t-xl px-5 pt-3 pb-[calc(1.5rem+env(safe-area-inset-bottom))]"
              finalFocus={createOpenerRef}
              initialFocus={nameInputRef}
              showCloseButton={false}
              side="bottom"
            >
              <span aria-hidden="true" className="h-1 w-10 self-center rounded-full bg-border" />
              {createForm}
            </SheetContent>
          </Sheet>
        ) : (
          <Dialog
            disablePointerDismissal={isCreateSubmitting}
            onOpenChange={handleCreateOpenChange}
            open={isCreateOpen}
          >
            <DialogContent
              className="max-w-[calc(100%-2rem)] gap-5 rounded-xl p-6 sm:max-w-[440px]"
              finalFocus={createOpenerRef}
              initialFocus={nameInputRef}
              showCloseButton={false}
            >
              {createForm}
            </DialogContent>
          </Dialog>
        ))}
    </section>
  );
}
