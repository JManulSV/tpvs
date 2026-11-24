import { formatMoney } from "@/lib/format";
import type { DeliveryFormValues } from "./schemas/delivery.schema";
import {
  officialDenominationValues,
  type Delivery,
  type DeliveryErrorDetails,
  type UpsertDeliveryInput,
} from "./types";

export const MAX_DENOMINATION_QUANTITY = 999;

/** Cash differences up to $1.00 are tolerated. */
const CASH_TOLERANCE_CENTS = 100;

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

function clampQuantity(quantity: number): number {
  return Math.min(Math.max(quantity, 0), MAX_DENOMINATION_QUANTITY);
}

export function stepQuantity(current: string, delta: 1 | -1): string {
  const quantity = Math.trunc(Number(current)) || 0;

  return String(clampQuantity(quantity + delta));
}

export function parseQuantityInput(raw: string): string {
  const digits = raw.replace(/\D/g, "");

  if (digits === "") return "0";

  return String(clampQuantity(Number(digits)));
}

export type CashCheck = {
  cashCents: number;
  shortOfficialCents: number;
  shortDocumentCents: number;
  excessCents: number;
  tone: "ok" | "short" | "excess";
  label: string;
};

function beyondTolerance(differenceCents: number): number {
  return differenceCents > CASH_TOLERANCE_CENTS ? differenceCents : 0;
}

function formatCents(cents: number): string {
  return formatMoney(centsToAmount(cents));
}

export function getCashCheck({
  officialAmount,
  documentAmount,
  denominations,
}: {
  officialAmount: string;
  documentAmount: string;
  denominations: Array<{ value: string; quantity: number | string }>;
}): CashCheck {
  const cashCents = denominationsTotalCents(denominations);
  const shortOfficialCents = beyondTolerance(toCents(officialAmount) - cashCents);
  const shortDocumentCents = beyondTolerance(toCents(documentAmount) - cashCents);
  const excessCents = beyondTolerance(cashCents - toCents(documentAmount));
  const base = { cashCents, shortOfficialCents, shortDocumentCents, excessCents };

  if (shortDocumentCents > 0) {
    return { ...base, tone: "short", label: `Faltan ${formatCents(shortDocumentCents)} frente al monto del documento` };
  }

  if (excessCents > 0) {
    return { ...base, tone: "excess", label: `Excede por ${formatCents(excessCents)} · requiere declarar cambio` };
  }

  return { ...base, tone: "ok", label: "Coincide con el monto del documento" };
}

export function isDeliveryClean(delivery: Delivery | null, values: DeliveryFormValues): boolean {
  if (delivery === null || delivery.is_stale) return false;

  return delivery.delivery_date === values.delivery_date
    && delivery.delivered_by_name.trim() === values.delivered_by_name.trim()
    && (delivery.received_by_name ?? "").trim() === values.received_by_name.trim()
    && denominationBreakdownKey(delivery.denominations) === denominationBreakdownKey(values.denominations);
}

export type ReadinessCheck = {
  id: "image" | "official" | "document" | "change" | "saved";
  label: string;
  ok: boolean;
};

export type DeliveryReadiness = {
  checks: ReadinessCheck[];
  hint: string;
  hintTone: "ok" | "neutral";
  canSave: boolean;
  canGenerate: boolean;
};

export function getDeliveryReadiness({
  hasImage,
  cash,
  changeDeclared,
  clean,
  missingData,
  generated,
}: {
  hasImage: boolean;
  cash: CashCheck;
  changeDeclared: boolean;
  clean: boolean;
  missingData: boolean;
  generated: boolean;
}): DeliveryReadiness {
  const hasExcess = cash.excessCents > 0;
  const checks: ReadinessCheck[] = [
    { id: "image", label: "Imagen asociada", ok: hasImage },
    { id: "official", label: "Efectivo conciliado con el retorno oficial", ok: cash.shortOfficialCents === 0 },
    { id: "document", label: "Efectivo conciliado con el monto del documento", ok: cash.shortDocumentCents === 0 },
    ...(hasExcess ? [{ id: "change" as const, label: "Cambio al cliente declarado", ok: changeDeclared }] : []),
    { id: "saved", label: "Entrega guardada", ok: clean },
  ];
  const canSave = !missingData && cash.shortOfficialCents === 0 && !clean;
  const canGenerate = clean && hasImage && cash.shortDocumentCents === 0 && (!hasExcess || changeDeclared);

  const [hint, hintTone]: [string, DeliveryReadiness["hintTone"]] =
    generated && canGenerate ? ["Recibo generado.", "ok"]
    : missingData ? ["Completa la fecha de entrega y quién entrega.", "neutral"]
    : cash.shortOfficialCents > 0 ? ["Ajusta el efectivo para poder guardar la entrega.", "neutral"]
    : !clean ? ["Guarda la entrega para poder generar el recibo.", "neutral"]
    : !hasImage ? ["Falta la imagen para generar el Word.", "neutral"]
    : cash.shortDocumentCents > 0 ? ["El efectivo no coincide con el monto del documento.", "neutral"]
    : hasExcess && !changeDeclared ? ["Indica que el cliente dará cambio.", "neutral"]
    : ["Todo listo para generar el recibo.", "ok"];

  return { checks, hint, hintTone, canSave, canGenerate };
}

export function getDeliveryHeading(delivery: Delivery | null): {
  title: "Preparar entrega" | "Preparar nueva entrega";
  saveLabel: "Guardar entrega" | "Actualizar entrega";
} {
  if (delivery === null) return { title: "Preparar entrega", saveLabel: "Guardar entrega" };
  if (delivery.is_stale) return { title: "Preparar nueva entrega", saveLabel: "Actualizar entrega" };

  return { title: "Preparar entrega", saveLabel: "Actualizar entrega" };
}

function todayIsoDate(): string {
  const now = new Date();
  const month = String(now.getMonth() + 1).padStart(2, "0");
  const day = String(now.getDate()).padStart(2, "0");

  return `${now.getFullYear()}-${month}-${day}`;
}

/** Form values from the saved delivery; a new one starts today, delivered by the current user. */
export function buildDeliveryFormValues(delivery: Delivery | null, userName: string): DeliveryFormValues {
  const quantities = new Map(
    (delivery?.denominations ?? []).map((denomination) => [denomination.value, denomination.quantity]),
  );

  return {
    delivery_date: delivery?.delivery_date ?? todayIsoDate(),
    delivered_by_name: delivery?.delivered_by_name ?? userName,
    received_by_name: delivery?.received_by_name ?? "",
    denominations: officialDenominationValues.map((value) => ({
      value,
      quantity: String(quantities.get(value) ?? 0),
    })),
  };
}

export function toUpsertDeliveryInput(values: DeliveryFormValues): UpsertDeliveryInput {
  const received = values.received_by_name.trim();

  return {
    delivery_date: values.delivery_date,
    delivered_by_name: values.delivered_by_name.trim(),
    received_by_name: received === "" ? null : received,
    denominations: values.denominations.map((denomination) => ({
      value: denomination.value,
      quantity: Math.trunc(Number(denomination.quantity) || 0),
    })),
  };
}

const formFields = new Set(["delivery_date", "delivered_by_name", "received_by_name"]);

/** True when a `422` field has a visible control, so its message goes next to it. */
export function isDeliveryFormField(field: string): boolean {
  return formFields.has(field) || /^denominations\.\d+\.quantity$/.test(field);
}

export type DeliveryBlock = { text: string; code?: string };

/** Messages that block saving or generating, in the order the banner shows them. */
export function getDeliveryBlocks({
  cash,
  hasImage,
  mismatch,
  errors,
}: {
  cash: CashCheck;
  hasImage: boolean;
  mismatch: DeliveryErrorDetails | null;
  errors: Array<string | null>;
}): DeliveryBlock[] {
  const blocks: DeliveryBlock[] = [];

  if (cash.shortOfficialCents > 0) {
    blocks.push({
      text: `Faltan ${formatCents(cash.shortOfficialCents)} en efectivo frente al retorno oficial. Ajusta las denominaciones.`,
      code: "CASH_MISMATCH",
    });
  } else if (cash.shortDocumentCents > 0) {
    blocks.push({
      text: `Faltan ${formatCents(cash.shortDocumentCents)} en efectivo frente al monto del documento. Ajusta las denominaciones o elige otro monto del documento.`,
      code: "CASH_MISMATCH",
    });
  }

  if (!hasImage) blocks.push({ text: "Adjunta la imagen del registro para generar el Word." });

  if (mismatch) {
    blocks.push({
      text: `El servidor rechazó el efectivo: esperado ${formatMoney(mismatch.expected_amount)}, contado ${formatMoney(mismatch.cash_amount)}, diferencia ${formatMoney(mismatch.difference)}.`,
      code: "CASH_MISMATCH",
    });
  }

  for (const error of errors) {
    if (error) blocks.push({ text: error });
  }

  return blocks;
}
