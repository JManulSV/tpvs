import { formatMoney } from "@/lib/format";
import { recordStatusMeta, type RecordStatus } from "@/lib/status";

const eventLabels: Record<string, string> = {
  record_created: "Registro creado",
  record_updated: "Registro actualizado",
  document_generated: "Documento Word generado",
};

const recordFieldLabels: Record<string, string> = {
  company: "Empresa",
  year: "Año",
  week: "Semana",
  week_start_date: "Inicio de semana",
  week_end_date: "Fin de semana",
  // The record's global percentage. Its derived commission and return have no events (SPEC 27).
  commission_percentage: "Porcentaje de comisión",
  status: "Estado",
};

const detailFieldLabels: Record<string, string> = {
  order: "Orden",
  affiliation: "Afiliación",
  bank: "Banco",
  income: "Ingreso",
};

const deliveryFieldLabels: Record<string, string> = {
  delivery_date: "Fecha de entrega",
  delivered_by_name: "Entrega realizada por",
  received_by_name: "Recibido por",
  prepared_return_amount: "Importe preparado",
};

const denominationPattern = /^denominations\[(\d+(?:\.\d{1,2})?)\]$/;

/** Spanish label for a history `field_name`, including movements and cash denominations. */
export function historyFieldLabel(fieldName: string): string {
  if (deliveryFieldLabels[fieldName] !== undefined) return deliveryFieldLabels[fieldName];

  const denominationMatch = denominationPattern.exec(fieldName);
  if (denominationMatch !== null) return `Denominación ${formatMoney(denominationMatch[1])}`;

  if (eventLabels[fieldName] !== undefined) return eventLabels[fieldName];
  if (recordFieldLabels[fieldName] !== undefined) return recordFieldLabels[fieldName];

  const detailMatch = /^details\[(\d+)\]\.(.+)$/.exec(fieldName);
  if (detailMatch === null) return fieldName;

  const [, order, field] = detailMatch;
  return `Movimiento ${order} · ${detailFieldLabels[field] ?? field}`;
}

/** Display text for a previous or new value of `fieldName`. */
export function historyValue(fieldName: string, value: string | null): string {
  if (value === null) return "—";
  if (fieldName === "status" || fieldName === "record_created") {
    return recordStatusMeta[value as RecordStatus]?.label ?? value;
  }
  if (fieldName === "prepared_return_amount") return formatMoney(value);
  if (denominationPattern.test(fieldName)) return value;
  if (fieldName.endsWith(".income")) return formatMoney(value);
  if (fieldName === "commission_percentage") return `${value}%`;

  return value;
}
