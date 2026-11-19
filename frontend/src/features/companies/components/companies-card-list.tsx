import Link from "next/link";
import { cn } from "@/lib/utils";
import {
  getCompanyMetaLine,
  getCompanyRecordsHref,
  getCompanyRowLabel,
} from "../company-list-utils";
import type { Company } from "../types";
import { CompanyMark, NewCompanyBadge } from "./company-mark";

/** Narrow-container list. Each card is fully clickable through the name link's overlay. */
export function CompaniesCardList({
  companies,
  createdCompanyId,
}: {
  companies: Company[];
  createdCompanyId: number | null;
}) {
  return (
    <ul aria-label="Lista de empresas" className="flex flex-col gap-3">
      {companies.map((company) => {
        const isNew = company.id === createdCompanyId;

        return (
          <li
            className={cn(
              "relative flex items-center gap-3 rounded-lg border border-border bg-card p-4 shadow-card transition-colors hover:bg-muted/50",
              isNew && "bg-accent hover:bg-accent",
            )}
            key={company.id}
          >
            <CompanyMark isNew={isNew} />
            <span className="flex min-w-0 flex-1 flex-col">
              <Link
                aria-label={getCompanyRowLabel(company)}
                className="truncate text-[15px] leading-6 font-semibold text-foreground after:absolute after:inset-0 after:rounded-lg focus-visible:outline-none focus-visible:after:ring-2 focus-visible:after:ring-ring"
                href={getCompanyRecordsHref(company.id)}
              >
                {company.name}
              </Link>
              <span className="text-xs leading-4 font-medium text-muted-foreground tabular-nums">
                {getCompanyMetaLine(company)}
              </span>
            </span>
            {isNew && <NewCompanyBadge />}
          </li>
        );
      })}
    </ul>
  );
}
