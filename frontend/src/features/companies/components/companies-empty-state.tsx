import type { ReactNode } from "react";
import { Building2, Plus } from "lucide-react";
import { Button } from "@/components/ui/button";

function EmptyCard({ children, icon }: { children: ReactNode; icon: ReactNode }) {
  return (
    <div className="flex flex-col items-center gap-2 rounded-lg border border-border bg-card px-4 py-10 text-center shadow-card md:px-6 md:py-14">
      <span
        aria-hidden="true"
        className="grid size-12 place-items-center rounded-md bg-accent text-accent-foreground"
      >
        {icon}
      </span>
      {children}
    </div>
  );
}

/** The catalog has no companies at all (no search applied). */
export function CompaniesCatalogEmpty({
  onCreate,
}: {
  /** Only for the EDITOR. */
  onCreate?: (event: React.MouseEvent<HTMLElement>) => void;
}) {
  return (
    <EmptyCard icon={<Building2 className="size-6" />}>
      <p className="text-muted-foreground">Todavía no hay empresas en el catálogo.</p>
      {onCreate && (
        <Button className="mt-2 h-11 px-5 font-semibold md:h-10" onClick={onCreate} type="button">
          <Plus aria-hidden="true" />
          Nueva empresa
        </Button>
      )}
    </EmptyCard>
  );
}

/** The applied search matched no company. */
export function CompaniesSearchEmpty({ onClear }: { onClear: () => void }) {
  return (
    <EmptyCard icon={<Building2 className="size-6" />}>
      <h2 className="text-base leading-6 font-semibold">No encontramos empresas con ese nombre</h2>
      <p className="text-muted-foreground">Revisa la ortografía o prueba con otra palabra.</p>
      <Button
        className="mt-2 h-11 bg-accent px-5 font-semibold text-accent-foreground hover:bg-accent/80 md:h-10"
        onClick={onClear}
        type="button"
      >
        Limpiar búsqueda
      </Button>
    </EmptyCard>
  );
}
