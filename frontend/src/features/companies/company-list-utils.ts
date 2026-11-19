import type { AuthUser } from "@/features/auth/types";
import { recordListHref } from "@/features/records/record-filters";
import { formatShortDate } from "@/lib/format";
import type { Company } from "./types";

type UserRole = AuthUser["role"];

/** Backend text for a duplicate name (pinned by `CompaniesApiTest`). */
const BACKEND_DUPLICATE_MESSAGE = "A company with this name already exists.";

export const COMPANY_DUPLICATE_MESSAGE = "Ya existe una empresa con ese nombre.";

const FALLBACK_NAME_ERROR_MESSAGE = "Revisa el nombre de la empresa.";

export function getCompaniesSubtitle(role: UserRole): string {
  return role === "EDITOR"
    ? "Catálogo de empresas para tus registros"
    : "Catálogo de empresas";
}

/** "1 empresa" · "6 empresas" */
export function getCompanyCountLabel(total: number): string {
  return `${total} ${total === 1 ? "empresa" : "empresas"}`;
}

/** "1 confirmado" · "24 confirmados" */
export function getConfirmedCountLabel(count: number): string {
  return `${count} ${count === 1 ? "confirmado" : "confirmados"}`;
}

/** "24 confirmados · creada 12/01/26" */
export function getCompanyMetaLine(
  company: Pick<Company, "records_count" | "created_at">,
): string {
  return `${getConfirmedCountLabel(company.records_count)} · creada ${formatShortDate(company.created_at)}`;
}

/** Accessible name of the row link. */
export function getCompanyRowLabel(company: Pick<Company, "name">): string {
  return `Ver registros de ${company.name}`;
}

/** "/records?company_id=1" — all statuses, so pending records stay visible. */
export function getCompanyRecordsHref(companyId: number): string {
  return recordListHref({ filters: { company_id: companyId }, search: "", page: 1 });
}

/** Spanish message for the `name` field from the backend's `422` messages. */
export function getCompanyNameErrorMessage(messages: string[]): string {
  if (messages.includes(BACKEND_DUPLICATE_MESSAGE)) return COMPANY_DUPLICATE_MESSAGE;

  return messages[0] ?? FALLBACK_NAME_ERROR_MESSAGE;
}
