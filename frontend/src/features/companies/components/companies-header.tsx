import { Plus, Search } from "lucide-react";
import { Button } from "@/components/ui/button";
import type { AuthUser } from "@/features/auth/types";
import { getCompaniesSubtitle } from "../company-list-utils";

/** `<h1>`, subtitle, search and «Nueva empresa» (EDITOR only, from `md`). */
export function CompaniesHeader({
  onCreate,
  onSearchChange,
  role,
  searchInput,
}: {
  onCreate: (event: React.MouseEvent<HTMLElement>) => void;
  onSearchChange: (value: string) => void;
  role: AuthUser["role"];
  searchInput: string;
}) {
  return (
    <header className="flex flex-wrap items-center gap-x-4 gap-y-3">
      <div className="mr-auto flex flex-col gap-1">
        <h1 className="text-2xl leading-8 font-bold tracking-[-0.01em]" id="companies-heading">
          Empresas
        </h1>
        <p className="hidden text-sm text-muted-foreground md:block">{getCompaniesSubtitle(role)}</p>
      </div>
      <label className="flex h-12 w-full items-center gap-2 rounded-full border border-input bg-card px-4 text-muted-foreground focus-within:ring-2 focus-within:ring-ring focus-within:ring-offset-2 focus-within:ring-offset-background md:h-11 md:w-auto md:max-w-[360px] md:flex-[1_1_260px]">
        <Search aria-hidden="true" className="size-4 shrink-0" />
        <input
          aria-label="Buscar empresas"
          className="h-full min-w-0 flex-1 border-0 bg-transparent text-base text-foreground outline-none placeholder:text-muted-foreground md:text-sm"
          id="company-search"
          maxLength={255}
          onChange={(event) => onSearchChange(event.target.value)}
          placeholder="Buscar empresa…"
          type="search"
          value={searchInput}
        />
      </label>
      {role === "EDITOR" && (
        <Button className="hidden px-5 font-semibold md:inline-flex" onClick={onCreate} type="button">
          <Plus aria-hidden="true" />
          Nueva empresa
        </Button>
      )}
    </header>
  );
}
