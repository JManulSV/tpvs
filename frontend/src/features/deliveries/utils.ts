import type { RecordStatus } from "@/features/records/types";
import { officialDenominationValues, type Delivery } from "./types";

const currencyFormatter = new Intl.NumberFormat("es-MX", {
  style: "currency",
  currency: "MXN",
});

export function toCents(amount: string | number | null | undefined): number {
  if (amount === null || amount === undefined || amount === "") {
    return 0;
  }

  const normalized = String(amount).trim();

  if (!/^-?\d+(?:\.\d{1,2})?$/.test(normalized)) {
    return 0;
  }

  const negative = normalized.startsWith("-");
  const [whole, fraction = ""] = normalized.replace(/^[+-]/, "").split(".");
  const cents = Number(whole) * 100 + Number(fraction.padEnd(2, "0").slice(0, 2));

  return negative ? -cents : cents;
}

export function centsToAmount(cents: number): string {
  const sign = cents < 0 ? "-" : "";
  const absolute = Math.abs(Math.trunc(cents));

  return `${sign}${Math.floor(absolute / 100)}.${String(absolute % 100).padStart(2, "0")}`;
}

export function formatMoney(amount: string | number | null | undefined): string {
  if (amount === null || amount === undefined || amount === "") {
    return "—";
  }

  return currencyFormatter.format(Number(amount));
}

export function denominationsTotalCents(
  denominations: Array<{ value: string; quantity: number | string }>,
): number {
  return denominations.reduce((total, denomination) => {
    const quantity = Math.trunc(Number(denomination.quantity) || 0);

    return total + toCents(denomination.value) * Math.max(quantity, 0);
  }, 0);
}

export function documentReturnOptions(officialReturnAmount: string): [string, string, string] {
  const wholePesoCents = Math.floor(Math.max(toCents(officialReturnAmount), 0) / 100) * 100;

  return [
    centsToAmount(wholePesoCents),
    centsToAmount(wholePesoCents + 50),
    centsToAmount(wholePesoCents + 100),
  ];
}

export function denominationBreakdownKey(
  denominations: Array<{ value: string; quantity: number | string }>,
): string {
  const quantities = new Map(denominations.map(({ value, quantity }) => [value, quantity]));

  return officialDenominationValues
    .map((value) => `${value}:${Math.max(Math.trunc(Number(quantities.get(value) ?? 0)), 0)}`)
    .join("|");
}

export function downloadBlob(blob: Blob, fileName: string): void {
  const url = URL.createObjectURL(blob);
  const link = document.createElement("a");

  link.href = url;
  link.download = fileName;
  document.body.appendChild(link);
  link.click();
  link.remove();
  URL.revokeObjectURL(url);
}

export function documentBlockedReason({
  delivery,
  hasImage,
  recordStatus,
}: {
  delivery: Delivery | null;
  hasImage: boolean;
  recordStatus: RecordStatus;
}): string | null {
  if (delivery === null) return "Prepara la entrega para generar el documento.";
  if (recordStatus !== "CONFIRMED") return "El registro debe estar confirmado para generar el documento.";
  if (!hasImage) return "Adjunta una imagen al registro para generar el documento.";
  if (delivery.is_stale) return "La entrega usa un importe anterior; actualízala antes de generar el documento.";

  return null;
}
