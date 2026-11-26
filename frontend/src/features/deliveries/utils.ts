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

/**
 * The three document amounts of SPEC 14, from the cash to deliver (SPEC 28).
 * Without cash the only document amount is 0.00. Laravel validates the choice.
 */
export function documentReturnOptions(cashAmount: string): string[] {
  const cashCents = Math.max(toCents(cashAmount), 0);

  if (cashCents === 0) return [centsToAmount(0)];

  const wholePesoCents = Math.floor(cashCents / 100) * 100;

  return [
    centsToAmount(wholePesoCents),
    centsToAmount(wholePesoCents + 50),
    centsToAmount(wholePesoCents + 100),
  ];
}

export type SplitErrorCode = "TRANSFER_EXCEEDS_RETURN" | "CASH_REQUEST_EXCEEDS_RETURN" | "EXTRA_COMMISSION_INVALID";

export type SplitError = { code: SplitErrorCode; text: string };

export type DeliverySplitPreview = {
  returnCents: number;
  requestedCashCents: number;
  transferCents: number;
  /** Canonical "10.00", or null when the percentage is empty or invalid. */
  percentage: string | null;
  commissionCents: number;
  cashCents: number;
  errors: SplitError[];
  valid: boolean;
  /** True when the split is valid and there is cash to deliver. */
  hasCash: boolean;
};

const PERCENTAGE_PATTERN = /^\d{1,3}(?:\.\d{1,2})?$/;

/**
 * Half-up to the cent, the same integer formula as Laravel's Money::applyPercentage:
 * (cents × hundredths + 5000) / 10000. BigInt keeps large amounts exact.
 */
export function applyPercentageCents(cents: number, percentage: string): number {
  const result = (BigInt(cents) * BigInt(toCents(percentage)) + BigInt(5000)) / BigInt(10000);

  return Number(result);
}

/**
 * Preview of the split while the Editor types. Laravel recalculates, validates and stores it;
 * after saving, the screen shows the amounts of the response.
 *
 *   transfer = return − requested cash
 *   commission = requested cash × percentage (half-up)
 *   cash to deliver = requested cash − commission
 */
export function deliverySplitPreview({
  returnAmount,
  requestedCashAmount,
  cashCommissionPercentage,
}: {
  returnAmount: string;
  requestedCashAmount: string;
  cashCommissionPercentage: string;
}): DeliverySplitPreview {
  const returnCents = Math.max(toCents(returnAmount), 0);
  const requestedCashCents = toCents(requestedCashAmount.trim());
  const transferCents = returnCents - requestedCashCents;
  const rawPercentage = cashCommissionPercentage.trim();
  const percentageValid = PERCENTAGE_PATTERN.test(rawPercentage) && toCents(rawPercentage) <= 10000;
  const percentage = percentageValid ? centsToAmount(toCents(rawPercentage)) : null;

  const errors: SplitError[] = [];
  if (requestedCashCents < 0) {
    errors.push({
      code: "TRANSFER_EXCEEDS_RETURN",
      text: `La transferencia supera el retorno oficial por ${formatCents(-requestedCashCents)}. Reduce la transferencia.`,
    });
  }
  if (requestedCashCents > returnCents) {
    errors.push({
      code: "CASH_REQUEST_EXCEEDS_RETURN",
      text: `El efectivo supera el retorno oficial por ${formatCents(requestedCashCents - returnCents)}. Reduce el efectivo; el resto va por transferencia.`,
    });
  }
  if (percentage === null) {
    errors.push({ code: "EXTRA_COMMISSION_INVALID", text: "La comisión extra debe estar entre 0.00% y 100.00%." });
  }

  const commissionCents = percentage !== null && requestedCashCents > 0
    ? applyPercentageCents(requestedCashCents, percentage)
    : 0;
  const cashCents = Math.max(requestedCashCents - commissionCents, 0);
  const valid = errors.length === 0;

  return {
    returnCents,
    requestedCashCents,
    transferCents,
    percentage,
    commissionCents,
    cashCents,
    errors,
    valid,
    hasCash: valid && cashCents > 0,
  };
}

/** Digits and one dot with up to two decimals; no sign. */
export function sanitizeAmountInput(raw: string): string {
  const cleaned = raw.replace(/[^\d.]/g, "");
  const [whole, ...rest] = cleaned.split(".");

  return rest.length === 0 ? whole : `${whole}.${rest.join("").slice(0, 2)}`;
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
  /** Missing cash beyond the tolerance against the cash to deliver; blocks saving. */
  shortCashCents: number;
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

/**
 * The breakdown against the cash to deliver and the document amount. Without a valid
 * split or without cash there is nothing to reconcile: the breakdown is hidden and counts 0.
 */
export function getCashCheck({
  split,
  documentAmount,
  denominations,
}: {
  split: DeliverySplitPreview;
  documentAmount: string;
  denominations: Array<{ value: string; quantity: number | string }>;
}): CashCheck {
  if (!split.hasCash) {
    return {
      cashCents: 0,
      shortCashCents: 0,
      shortDocumentCents: 0,
      excessCents: 0,
      tone: split.valid ? "ok" : "short",
      label: split.valid
        ? "Todo el retorno va por transferencia"
        : "El reparto no es válido · corrígelo en «Forma de retorno»",
    };
  }

  const cashCents = denominationsTotalCents(denominations);
  const shortCashCents = beyondTolerance(split.cashCents - cashCents);
  // Like the template, the document shortfall only counts once the cash to deliver is covered.
  const shortDocumentCents = shortCashCents > 0 ? 0 : beyondTolerance(toCents(documentAmount) - cashCents);
  const excessCents = beyondTolerance(cashCents - toCents(documentAmount));
  const base = { cashCents, shortCashCents, shortDocumentCents, excessCents };

  if (shortCashCents > 0) {
    return {
      ...base,
      tone: "short",
      label: `Faltan ${formatCents(shortCashCents)} en efectivo para cubrir ${formatCents(split.cashCents)}`,
    };
  }

  if (shortDocumentCents > 0) {
    return { ...base, tone: "short", label: `Faltan ${formatCents(shortDocumentCents)} frente al monto del documento` };
  }

  if (excessCents > 0) {
    return { ...base, tone: "excess", label: `Excede por ${formatCents(excessCents)} · requiere declarar cambio` };
  }

  return { ...base, tone: "ok", label: "El efectivo cuadra con lo que se entrega" };
}

/** "100.5" → "100.50"; anything that is not a two-decimal amount stays as typed. */
function canonicalAmountInput(raw: string): string {
  const trimmed = raw.trim();

  return /^-?\d+(?:\.\d{1,2})?$/.test(trimmed) ? centsToAmount(toCents(trimmed)) : trimmed;
}

export function isDeliveryClean(delivery: Delivery | null, values: DeliveryFormValues): boolean {
  if (delivery === null || delivery.is_stale) return false;

  return delivery.delivery_date === values.delivery_date
    && delivery.delivered_by_name.trim() === values.delivered_by_name.trim()
    && (delivery.received_by_name ?? "").trim() === values.received_by_name.trim()
    && canonicalAmountInput(values.requested_cash_amount) === delivery.requested_cash_amount
    && canonicalAmountInput(values.cash_commission_percentage) === delivery.cash_commission_percentage
    && denominationBreakdownKey(delivery.denominations) === denominationBreakdownKey(values.denominations);
}

export type ReadinessCheck = {
  id: "image" | "split" | "cash" | "document" | "change" | "saved";
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
  split,
  cash,
  changeDeclared,
  clean,
  missingData,
  generated,
}: {
  hasImage: boolean;
  split: DeliverySplitPreview;
  cash: CashCheck;
  changeDeclared: boolean;
  clean: boolean;
  missingData: boolean;
  generated: boolean;
}): DeliveryReadiness {
  const hasExcess = cash.excessCents > 0;
  const checks: ReadinessCheck[] = [
    { id: "image", label: "Imagen asociada", ok: hasImage },
    { id: "split", label: "Reparto válido: transferencia + efectivo", ok: split.valid },
    ...(split.hasCash
      ? [
        { id: "cash" as const, label: "Efectivo conciliado con lo que se entrega", ok: cash.shortCashCents === 0 },
        {
          id: "document" as const,
          label: "Efectivo conciliado con el monto del documento",
          ok: cash.shortCashCents === 0 && cash.shortDocumentCents === 0,
        },
      ]
      : []),
    ...(hasExcess ? [{ id: "change" as const, label: "Cambio al cliente declarado", ok: changeDeclared }] : []),
    { id: "saved", label: "Entrega guardada", ok: clean },
  ];
  const canSave = !missingData && split.valid && cash.shortCashCents === 0 && !clean;
  const canGenerate = clean && hasImage && split.valid && cash.shortDocumentCents === 0
    && (!hasExcess || changeDeclared);

  const [hint, hintTone]: [string, DeliveryReadiness["hintTone"]] =
    generated && canGenerate ? ["Recibo generado.", "ok"]
    : missingData ? ["Completa la fecha de entrega y quién entrega.", "neutral"]
    : !split.valid ? ["Corrige el reparto entre transferencia y efectivo.", "neutral"]
    : cash.shortCashCents > 0 ? ["Ajusta el efectivo para poder guardar la entrega.", "neutral"]
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

/**
 * Form values from the saved delivery; a new one starts today, delivered by the current user,
 * all in cash and with the extra commission empty. A stale delivery keeps its requested cash,
 * so the transfer absorbs the new return.
 */
export function buildDeliveryFormValues(
  delivery: Delivery | null,
  userName: string,
  currentReturnAmount: string,
): DeliveryFormValues {
  const quantities = new Map(
    (delivery?.denominations ?? []).map((denomination) => [denomination.value, denomination.quantity]),
  );

  return {
    delivery_date: delivery?.delivery_date ?? todayIsoDate(),
    delivered_by_name: delivery?.delivered_by_name ?? userName,
    received_by_name: delivery?.received_by_name ?? "",
    requested_cash_amount: delivery?.requested_cash_amount ?? currentReturnAmount,
    cash_commission_percentage: delivery?.cash_commission_percentage ?? "",
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
    requested_cash_amount: canonicalAmountInput(values.requested_cash_amount),
    cash_commission_percentage: canonicalAmountInput(values.cash_commission_percentage),
    denominations: values.denominations.map((denomination) => ({
      value: denomination.value,
      quantity: Math.trunc(Number(denomination.quantity) || 0),
    })),
  };
}

const formFields = new Set([
  "delivery_date",
  "delivered_by_name",
  "received_by_name",
  "requested_cash_amount",
  "cash_commission_percentage",
]);

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

  if (cash.shortCashCents > 0) {
    blocks.push({
      text: `Faltan ${formatCents(cash.shortCashCents)} en efectivo frente al efectivo a entregar. Ajusta las denominaciones.`,
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
