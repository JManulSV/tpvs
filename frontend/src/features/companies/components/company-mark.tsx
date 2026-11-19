import { Building2 } from "lucide-react";
import { cn } from "@/lib/utils";

/** 40 px company icon badge. On the highlighted (new) row it sits on `bg-card`. */
export function CompanyMark({ isNew = false }: { isNew?: boolean }) {
  return (
    <span
      aria-hidden="true"
      className={cn(
        "grid size-10 shrink-0 place-items-center rounded-md text-accent-foreground",
        isNew ? "bg-card" : "bg-accent",
      )}
    >
      <Building2 className="size-5" />
    </span>
  );
}

/** «Nueva» pill for the company created during this visit. */
export function NewCompanyBadge() {
  return (
    <span className="inline-flex h-6 shrink-0 items-center rounded-full bg-status-confirmed-bg px-2.5 text-xs leading-4 font-semibold whitespace-nowrap text-status-confirmed">
      Nueva
    </span>
  );
}
