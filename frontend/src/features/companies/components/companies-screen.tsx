"use client";

import { Plus } from "lucide-react";
import { useCallback, useEffect, useRef, useState } from "react";
import { Button } from "@/components/ui/button";
import { Dialog, DialogContent } from "@/components/ui/dialog";
import { Sheet, SheetContent } from "@/components/ui/sheet";
import { useIsMobile } from "@/hooks/use-mobile";
import type { AuthUser } from "@/features/auth/types";
import { useResetOnHide } from "@/hooks/use-reset-on-hide";
import { getCompanyCountLabel } from "../company-list-utils";
import { useCompanies } from "../hooks/use-companies";
import type { CompanyDetail } from "../types";
import { CompaniesCardList } from "./companies-card-list";
import { CompaniesCatalogEmpty, CompaniesSearchEmpty } from "./companies-empty-state";
import { CompaniesHeader } from "./companies-header";
import { CompaniesPagination } from "./companies-pagination";
import { CompaniesSkeleton } from "./companies-skeleton";
import { CompaniesTable } from "./companies-table";
import { CompanyCreatedNotice } from "./company-created-notice";
import { CreateCompanyForm } from "./create-company-form";

const PAGE_SIZE = 15;
const IMMUTABLE_NAMES_NOTE = "Los nombres no se pueden cambiar ni eliminar";

export function CompaniesScreen({ role }: { role: AuthUser["role"] }) {
  const isEditor = role === "EDITOR";
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
  // An empty placeholder belongs to the previous search; show the skeleton instead of a stale empty state.
  const showSkeleton =
    companiesQuery.isPending || (companiesQuery.isPlaceholderData && companies.length === 0);

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

  return (
    <section aria-labelledby="companies-heading" className="flex flex-col gap-4 md:gap-6">
      <CompaniesHeader
        onCreate={openCreate}
        onSearchChange={setSearchInput}
        role={role}
        searchInput={searchInput}
      />

      <CompanyCreatedNotice
        message={successMessage}
        onDismiss={() => {
          setSuccessMessage(null);
        }}
      />

      {showSkeleton ? (
        <div className="@container">
          <CompaniesSkeleton />
        </div>
      ) : companiesQuery.isError && !companiesQuery.data ? (
        <div
          className="flex flex-col items-center gap-3 rounded-lg border border-border bg-card p-6 text-center shadow-card"
          role="alert"
        >
          <p>No se pudieron cargar las empresas.</p>
          <Button onClick={() => void companiesQuery.refetch()} type="button" variant="outline">
            Reintentar
          </Button>
        </div>
      ) : meta && companies.length === 0 ? (
        search ? (
          <CompaniesSearchEmpty onClear={clearSearch} />
        ) : (
          <CompaniesCatalogEmpty onCreate={isEditor ? openCreate : undefined} />
        )
      ) : meta ? (
        /* Both lists render; the container width hides one with `display: none`. */
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
      ) : null}

      {isEditor && (
        <button
          className="fixed right-4 bottom-[calc(5.25rem+env(safe-area-inset-bottom))] z-30 inline-flex h-12 items-center gap-2 rounded-full bg-primary px-5 text-sm font-semibold text-primary-foreground shadow-popover transition-colors hover:bg-primary/90 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring md:hidden"
          onClick={openCreate}
          type="button"
        >
          <Plus aria-hidden="true" className="size-5" />
          Nueva empresa
        </button>
      )}

      {isEditor &&
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

/** «**N** empresas» (`meta.total`), above the mobile cards and in the desktop footer. */
function CompanyCountLabel({ total }: { total: number }) {
  const label = getCompanyCountLabel(total);
  const count = String(total);

  return (
    <p className="text-[13px] leading-5 font-medium text-muted-foreground">
      <strong className="font-bold text-foreground tabular-nums">{count}</strong>
      {label.slice(count.length)}
    </p>
  );
}
