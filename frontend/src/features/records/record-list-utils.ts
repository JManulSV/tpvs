import type { AuthUser } from "@/features/auth/types";
import { formatDate } from "@/lib/format";
import { recordStatusMeta } from "@/lib/status";
import type { RecordListItem } from "./types";

type UserRole = AuthUser["role"];

const NO_COMPANY_LABEL = "Sin empresa asignada";

const waitingText: Partial<Record<RecordListItem["status"], string>> = {
  PENDING: "En cola para procesar",
  PROCESSING: "Extrayendo datos de la imagen",
  ERROR: "No se pudo leer la imagen",
};

function sourceLabel(source: RecordListItem["source"]): string {
  return source === "IMAGE" ? "Imagen" : "Manual";
}

/** Row action shown only to the EDITOR; the whole row already opens the record. */
export function getRecordRowAction(
  record: Pick<RecordListItem, "status">,
  role: UserRole,
): "Revisar" | "Ver error" | null {
  if (role !== "EDITOR") return null;
  if (record.status === "NEEDS_REVIEW") return "Revisar";
  if (record.status === "ERROR") return "Ver error";

  return null;
}

/** Affiliation · branch of the first movement (+N more), or a waiting text by status. */
export function getRecordSubtitle(record: RecordListItem): string {
  const first = record.details.find((detail) => detail.affiliation);

  if (first) {
    const extra = record.details.length - 1;
    const more = extra > 0 ? ` · +${extra} ${extra === 1 ? "movimiento" : "movimientos"}` : "";

    return `Afil. ${first.affiliation} · ${first.branch ?? "—"}${more}`;
  }

  return waitingText[record.status] ?? "";
}

/** Mobile meta line: "Imagen · Semana 38 · fin 15 sep 2026". */
export function getRecordMetaLine(record: RecordListItem): string {
  const source = sourceLabel(record.source);

  if (record.week === null) return `${source} · Sin periodo todavía`;

  return `${source} · Semana ${record.week} · fin ${formatDate(record.week_end_date)}`;
}

/** Accessible name of the link that opens the record from a row or card. */
export function getRecordRowLabel(record: RecordListItem): string {
  const company = record.company?.name ?? NO_COMPANY_LABEL;
  const week = record.week !== null ? `, semana ${record.week}` : "";

  return `Abrir registro de ${company}${week}, ${recordStatusMeta[record.status].label}`;
}

/** True when the search text or any filter narrows the list. */
export function hasActiveRecordFilters(search: string, filters: object): boolean {
  if (search.trim() !== "") return true;

  return Object.values(filters).some(
    (value) => value !== undefined && value !== null && value !== "",
  );
}
