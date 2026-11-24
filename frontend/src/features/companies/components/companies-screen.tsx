"use client";

import { Plus } from "lucide-react";
import { useEffect, useRef, useState, type MouseEvent } from "react";
import { LoadError } from "@/components/load-error";
import type { AuthUser } from "@/features/auth/types";
import { useResetOnHide } from "@/hooks/use-reset-on-hide";
import { floatingActionButton } from "@/lib/styles";
import { getCompanyCountLabel } from "../utils";
import { useCompanies } from "../hooks/use-companies";
import type { CompanyDetail } from "../types";
import { CompaniesCardList } from "./companies-card-list";
import { CompaniesCatalogEmpty, CompaniesSearchEmpty } from "./companies-empty-state";
import { CompaniesHeader } from "./companies-header";
import { CompaniesPagination } from "./companies-pagination";
import { CompaniesSkeleton } from "./companies-skeleton";
import { CompaniesTable } from "./companies-table";
import { CompanyCreatedNotice } from "./company-created-notice";
import { CreateCompanyContainer } from "./create-company-container";

const PAGE_SIZE = 15;
const SEARCH_DEBOUNCE_MS = 300;
const IMMUTABLE_NAMES_NOTE = "Los nombres no se pueden cambiar ni eliminar";

export function CompaniesScreen({ role }: { role: AuthUser["role"] }) {
  const isEditor = role === "EDITOR";
  const [searchInput, setSearchInput] = useState("");
  const [search, setSearch] = useState("");
  const [page, setPage] = useState(1);
  const [isCreateOpen, setIsCreateOpen] = useState(false);
  const [successMessage, setSuccessMessage] = useState<string | null>(null);
  const [createdCompanyId, setCreatedCompanyId] = useState<number | null>(null);
  const createOpenerRef = useRef<HTMLElement | null>(null);

  // A later visit must not reopen the form, show the previous notice or highlight a row.
  useResetOnHide(() => {
    setIsCreateOpen(false);
    setSuccessMessage(null);
    setCreatedCompanyId(null);
  });

  useEffect(() => {
    const timer = window.setTimeout(() => {
      setSearch(searchInput.trim());
      setPage(1);
    }, SEARCH_DEBOUNCE_MS);

    return () => window.clearTimeout(timer);
  }, [searchInput]);

  const companiesQuery = useCompanies({ ...(search ? { search } : {}), page, per_page: PAGE_SIZE });
  const companies = companiesQuery.data?.data ?? [];
  const meta = companiesQuery.data?.meta;
  // An empty placeholder belongs to the previous search; show the skeleton instead of a stale empty state.
  const showSkeleton = companiesQuery.isPending || (companiesQuery.isPlaceholderData && companies.length === 0);

  function openCreate(event: MouseEvent<HTMLElement>) {
    createOpenerRef.current = event.currentTarget;
    setSuccessMessage(null);
    setCreatedCompanyId(null);
    setIsCreateOpen(true);
  }

  function handleCreated(company: CompanyDetail) {
    setIsCreateOpen(false);
    clearSearch();
    setCreatedCompanyId(company.id);
    setSuccessMessage(`Creaste la empresa ${company.name}.`);
  }

  function clearSearch() {
    setSearchInput("");
    setSearch("");
    setPage(1);
  }

  function pagination(variant: "footer" | "stacked") {
    if (!meta || meta.last_page <= 1) return null;

    return (
      <CompaniesPagination
        currentPage={meta.current_page}
        isFetching={companiesQuery.isFetching}
        lastPage={meta.last_page}
        onNext={() => setPage((current) => Math.min(meta.last_page, current + 1))}
        onPrevious={() => setPage((current) => Math.max(1, current - 1))}
        variant={variant}
      />
    );
  }

  function content() {
    if (showSkeleton) {
      return (
        <div className="@container">
          <CompaniesSkeleton />
        </div>
      );
    }

    if (companiesQuery.isError && !companiesQuery.data) {
      return <LoadError message="No se pudieron cargar las empresas." onRetry={() => void companiesQuery.refetch()} />;
    }

    if (!meta) return null;

    if (companies.length === 0) {
      return search ? (
        <CompaniesSearchEmpty onClear={clearSearch} />
      ) : (
        <CompaniesCatalogEmpty onCreate={isEditor ? openCreate : undefined} />
      );
    }

    // Both lists render; the container width hides one with `display: none`.
    return (
      <div className="@container">
        <div className="hidden @[40rem]:block">
          <CompaniesTable
            companies={companies}
            createdCompanyId={createdCompanyId}
            footer={
              <div className="flex flex-wrap items-center justify-between gap-x-6 gap-y-2 border-t border-border bg-muted px-6 py-3.5 text-[13px] leading-5 font-medium text-muted-foreground">
                <CompanyCountLabel total={meta.total} />
                <div className="flex flex-wrap items-center gap-x-6 gap-y-2">
                  <span>{IMMUTABLE_NAMES_NOTE}</span>
                  {pagination("footer")}
                </div>
              </div>
            }
          />
        </div>
        <div className="flex flex-col gap-3 @[40rem]:hidden">
          <CompanyCountLabel total={meta.total} />
          <CompaniesCardList companies={companies} createdCompanyId={createdCompanyId} />
          <p className="text-xs leading-4 font-medium text-muted-foreground">{IMMUTABLE_NAMES_NOTE}</p>
          {pagination("stacked")}
        </div>
      </div>
    );
  }

  return (
    <section aria-labelledby="companies-heading" className="flex flex-col gap-4 md:gap-6">
      <CompaniesHeader onCreate={openCreate} onSearchChange={setSearchInput} role={role} searchInput={searchInput} />

      <CompanyCreatedNotice message={successMessage} onDismiss={() => setSuccessMessage(null)} />

      {content()}

      {isEditor && (
        <>
          <button className={floatingActionButton} onClick={openCreate} type="button">
            <Plus aria-hidden="true" className="size-5" />
            Nueva empresa
          </button>
          <CreateCompanyContainer
            onCreated={handleCreated}
            onOpenChange={setIsCreateOpen}
            open={isCreateOpen}
            openerRef={createOpenerRef}
          />
        </>
      )}
    </section>
  );
}

/** «**N** empresas», above the mobile cards and in the desktop footer. */
function CompanyCountLabel({ total }: { total: number }) {
  const count = String(total);

  return (
    <p className="text-[13px] leading-5 font-medium text-muted-foreground">
      <strong className="font-bold text-foreground tabular-nums">{count}</strong>
      {getCompanyCountLabel(total).slice(count.length)}
    </p>
  );
}
