import { describe, expect, it } from "vitest";
import type { DeliveryFormValues } from "@/features/deliveries/schemas/delivery.schema";
import { officialDenominationValues, type Delivery } from "@/features/deliveries/types";
import {
  applyPercentageCents,
  buildDeliveryFormValues,
  centsToAmount,
  deliverySplitPreview,
  denominationsTotalCents,
  documentReturnOptions,
  getCashCheck,
  getDeliveryHeading,
  getDeliveryReadiness,
  isDeliveryClean,
  parseQuantityInput,
  sanitizeAmountInput,
  stepQuantity,
  toCents,
  toUpsertDeliveryInput,
  type CashCheck,
  type DeliverySplitPreview,
} from "@/features/deliveries/utils";

describe("toCents", () => {
  it("converts decimal amounts without floating point drift", () => {
    expect(toCents("9800.00")).toBe(980000);
    expect(toCents("0.10")).toBe(10);
    expect(toCents("0.30")).toBe(30);
    expect(toCents("0.5")).toBe(50);
  });

  it("keeps the sign of negative amounts", () => {
    expect(toCents("-1.50")).toBe(-150);
  });
});

describe("centsToAmount", () => {
  it("formats cents with two decimals", () => {
    expect(centsToAmount(980000)).toBe("9800.00");
    expect(centsToAmount(5)).toBe("0.05");
    expect(centsToAmount(-150)).toBe("-1.50");
  });
});

describe("denominationsTotalCents", () => {
  it("sums quantity times value in cents", () => {
    const total = denominationsTotalCents([
      { value: "1000.00", quantity: 5 },
      { value: "500.00", quantity: 5 },
      { value: "200.00", quantity: 10 },
      { value: "100.00", quantity: 3 },
      { value: "0.50", quantity: "3" },
    ]);

    // 1000×5 + 500×5 + 200×10 + 100×3 = 9800.00, plus 0.50×3 = 1.50
    expect(total).toBe(980150);
  });

  it("treats negative and non-numeric quantities as zero", () => {
    expect(
      denominationsTotalCents([
        { value: "1000.00", quantity: -3 },
        { value: "20.00", quantity: "abc" },
      ]),
    ).toBe(0);
  });

  it("returns zero for an empty list", () => {
    expect(denominationsTotalCents([])).toBe(0);
  });
});

// Template scenarios (DeliveryPrepare.dc.html), official return $9,800.00.
const scenarios = {
  coincide: [5, 5, 10, 3, 0, 0, 0, 0, 0, 0, 0],
  cambio: [5, 5, 10, 3, 0, 1, 1, 0, 0, 0, 0],
  faltante: [5, 5, 9, 3, 1, 1, 1, 0, 0, 0, 0],
};

function breakdown(quantities: number[]) {
  return officialDenominationValues.map((value, index) => ({ value, quantity: quantities[index] ?? 0 }));
}

/** All cash, no extra commission: the cash to deliver is the official return. */
function allCash(returnAmount = "9800.00"): DeliverySplitPreview {
  return deliverySplitPreview({ returnAmount, requestedCashAmount: returnAmount, cashCommissionPercentage: "0" });
}

function cashFor(cashAmount: string, documentAmount = "9800.00", split = allCash()): CashCheck {
  return getCashCheck({ split, documentAmount, denominations: [{ value: "1.00", quantity: toCents(cashAmount) / 100 }] });
}

describe("deliverySplitPreview", () => {
  // The SPEC 28 table, the same cases as Laravel's DeliverySplitTest.
  it.each([
    ["mixed split", "200.00", "100.00", "10.00", 10000, 1000, 9000],
    ["all cash", "200.00", "200.00", "10.00", 0, 2000, 18000],
    ["all transfer", "200.00", "0.00", "10.00", 20000, 0, 0],
    ["no extra commission", "200.00", "100.00", "0.00", 10000, 0, 10000],
    ["6.50325 rounds down", "100.05", "100.05", "6.50", 0, 650, 9355],
    ["one hundred percent", "200.00", "150.00", "100.00", 5000, 15000, 0],
    ["half a cent rounds up", "0.10", "0.10", "5.00", 0, 1, 9],
  ] as const)("%s", (_case, returnAmount, requestedCashAmount, cashCommissionPercentage, transfer, commission, cash) => {
    expect(deliverySplitPreview({ returnAmount, requestedCashAmount, cashCommissionPercentage })).toMatchObject({
      transferCents: transfer,
      commissionCents: commission,
      cashCents: cash,
      errors: [],
      valid: true,
      hasCash: cash > 0,
    });
  });

  it("canonicalizes the percentage", () => {
    expect(deliverySplitPreview({ returnAmount: "200", requestedCashAmount: "100", cashCommissionPercentage: "6.5" }))
      .toMatchObject({ percentage: "6.50", commissionCents: 650, cashCents: 9350 });
  });

  it("applies the percentage exactly on large amounts", () => {
    // 9,999,999,999.99 × 6.5% = 649,999,999.9999… → 650,000,000.00
    expect(applyPercentageCents(999999999999, "6.50")).toBe(65000000000);
  });

  it("reports a transfer above the official return", () => {
    const split = deliverySplitPreview({ returnAmount: "200.00", requestedCashAmount: "-50.00", cashCommissionPercentage: "10" });

    expect(split).toMatchObject({ transferCents: 25000, valid: false, hasCash: false });
    expect(split.errors).toEqual([{
      code: "TRANSFER_EXCEEDS_RETURN",
      text: "La transferencia supera el retorno oficial por $50.00. Reduce la transferencia.",
    }]);
  });

  it("reports requested cash above the official return", () => {
    const split = deliverySplitPreview({ returnAmount: "200.00", requestedCashAmount: "200.01", cashCommissionPercentage: "10" });

    expect(split.valid).toBe(false);
    expect(split.errors).toEqual([{
      code: "CASH_REQUEST_EXCEEDS_RETURN",
      text: "El efectivo supera el retorno oficial por $0.01. Reduce el efectivo; el resto va por transferencia.",
    }]);
  });

  it.each(["", "100.01", "-1", "6.505", "abc"])("requires an extra commission between 0 and 100 (%j)", (percentage) => {
    const split = deliverySplitPreview({ returnAmount: "200.00", requestedCashAmount: "100.00", cashCommissionPercentage: percentage });

    expect(split).toMatchObject({ percentage: null, commissionCents: 0, valid: false, hasCash: false });
    expect(split.errors.map((error) => error.code)).toEqual(["EXTRA_COMMISSION_INVALID"]);
  });
});

describe("documentReturnOptions", () => {
  it("derives the three options from the cash to deliver", () => {
    expect(documentReturnOptions("93.55")).toEqual(["93.00", "93.50", "94.00"]);
  });

  it("offers only 0.00 without cash", () => {
    expect(documentReturnOptions("0.00")).toEqual(["0.00"]);
  });
});

describe("stepQuantity", () => {
  it("does not go below 0", () => {
    expect(stepQuantity("0", -1)).toBe("0");
  });

  it("does not go above 999", () => {
    expect(stepQuantity("999", 1)).toBe("999");
  });

  it("steps up and down, treating an empty value as 0", () => {
    expect(stepQuantity("4", 1)).toBe("5");
    expect(stepQuantity("4", -1)).toBe("3");
    expect(stepQuantity("", 1)).toBe("1");
  });
});

describe("parseQuantityInput", () => {
  it("keeps only digits", () => {
    expect(parseQuantityInput("1a2b")).toBe("12");
    expect(parseQuantityInput("abc")).toBe("0");
  });

  it("turns an empty value into 0", () => {
    expect(parseQuantityInput("")).toBe("0");
  });

  it("caps at 999", () => {
    expect(parseQuantityInput("1500")).toBe("999");
  });

  it("drops leading zeros", () => {
    expect(parseQuantityInput("007")).toBe("7");
  });
});

describe("getCashCheck", () => {
  const base = { split: allCash(), documentAmount: "9800.00" };

  it("matches in the coincide scenario", () => {
    expect(getCashCheck({ ...base, denominations: breakdown(scenarios.coincide) })).toEqual({
      cashCents: 980000,
      shortCashCents: 0,
      shortDocumentCents: 0,
      excessCents: 0,
      tone: "ok",
      label: "El efectivo cuadra con lo que se entrega",
    });
  });

  it("reports the excess in the cambio scenario", () => {
    expect(getCashCheck({ ...base, denominations: breakdown(scenarios.cambio) })).toEqual({
      cashCents: 983000,
      shortCashCents: 0,
      shortDocumentCents: 0,
      excessCents: 3000,
      tone: "excess",
      label: "Excede por $30.00 · requiere declarar cambio",
    });
  });

  it("reports the shortfall in the faltante scenario", () => {
    expect(getCashCheck({ ...base, denominations: breakdown(scenarios.faltante) })).toEqual({
      cashCents: 968000,
      shortCashCents: 12000,
      shortDocumentCents: 0,
      excessCents: 0,
      tone: "short",
      label: "Faltan $120.00 en efectivo para cubrir $9,800.00",
    });
  });

  it("tolerates a difference of exactly $1.00", () => {
    expect(cashFor("9799.00")).toMatchObject({ shortCashCents: 0, shortDocumentCents: 0, tone: "ok" });
    expect(cashFor("9801.00")).toMatchObject({ excessCents: 0, tone: "ok" });
  });

  it("flags a difference of more than $1.00", () => {
    const short = getCashCheck({ ...base, denominations: [{ value: "1.00", quantity: 9798 }, { value: "0.50", quantity: 1 }] });
    expect(short).toMatchObject({ shortCashCents: 150, shortDocumentCents: 0, tone: "short" });

    const excess = getCashCheck({ ...base, denominations: [{ value: "1.00", quantity: 9801 }, { value: "0.50", quantity: 1 }] });
    expect(excess).toMatchObject({ excessCents: 150, tone: "excess" });
  });

  it("checks the cash to deliver and the document amount separately", () => {
    expect(cashFor("9800.00", "9801.50")).toMatchObject({ shortCashCents: 0, shortDocumentCents: 150, tone: "short" });
  });

  it("reconciles against the cash to deliver, not the official return", () => {
    // 200.00 return, 100.00 requested at 10% → 90.00 to deliver.
    const split = deliverySplitPreview({ returnAmount: "200.00", requestedCashAmount: "100.00", cashCommissionPercentage: "10" });

    const short = [{ value: "50.00", quantity: 1 }, { value: "20.00", quantity: 1 }, { value: "10.00", quantity: 1 },
      { value: "5.00", quantity: 1 }, { value: "2.00", quantity: 1 }, { value: "1.00", quantity: 1 }, { value: "0.50", quantity: 1 }];

    expect(cashFor("89.00", "90.00", split)).toMatchObject({ shortCashCents: 0, tone: "ok" });
    expect(getCashCheck({ split, documentAmount: "90.00", denominations: short })).toMatchObject({
      shortCashCents: 150,
      tone: "short",
      label: "Faltan $1.50 en efectivo para cubrir $90.00",
    });
  });

  it("has nothing to reconcile without cash", () => {
    const split = deliverySplitPreview({ returnAmount: "200.00", requestedCashAmount: "0", cashCommissionPercentage: "10" });

    expect(cashFor("50.00", "0.00", split)).toEqual({
      cashCents: 0,
      shortCashCents: 0,
      shortDocumentCents: 0,
      excessCents: 0,
      tone: "ok",
      label: "Todo el retorno va por transferencia",
    });
  });

  it("flags an invalid split", () => {
    const split = deliverySplitPreview({ returnAmount: "200.00", requestedCashAmount: "100", cashCommissionPercentage: "" });

    expect(cashFor("90.00", "90.00", split)).toMatchObject({
      tone: "short",
      label: "El reparto no es válido · corrígelo en «Forma de retorno»",
    });
  });
});

describe("isDeliveryClean", () => {
  const delivery: Delivery = {
    id: 3,
    record_id: 12,
    delivery_date: "2026-11-18",
    delivered_by_name: "Maria Lopez",
    received_by_name: null,
    prepared_return_amount: "9800.00",
    current_return_amount: "9800.00",
    is_stale: false,
    requested_cash_amount: "9500.00",
    transfer_amount: "300.00",
    cash_commission_percentage: "0.00",
    cash_commission: "0.00",
    cash_amount: "9500.00",
    denominations: [{ value: "1000.00", quantity: 9 }, { value: "500.00", quantity: 1 }],
    created_at: null,
    updated_at: null,
  };
  const values: DeliveryFormValues = {
    delivery_date: "2026-11-18",
    delivered_by_name: "Maria Lopez",
    received_by_name: "",
    requested_cash_amount: "9500",
    cash_commission_percentage: "0",
    denominations: officialDenominationValues.map((value) => ({
      value,
      quantity: value === "1000.00" ? "9" : value === "500.00" ? "1" : "0",
    })),
  };

  it("is not clean without a saved delivery", () => {
    expect(isDeliveryClean(null, values)).toBe(false);
  });

  it("is clean when the form matches the saved delivery", () => {
    expect(isDeliveryClean(delivery, values)).toBe(true);
  });

  it("ignores extra spaces around the names", () => {
    expect(isDeliveryClean(delivery, { ...values, delivered_by_name: "  Maria Lopez ", received_by_name: "  " })).toBe(true);
  });

  it("is not clean when a field or a quantity changes", () => {
    expect(isDeliveryClean(delivery, { ...values, received_by_name: "Juan" })).toBe(false);
    expect(isDeliveryClean(delivery, { ...values, delivery_date: "2026-11-19" })).toBe(false);
    expect(isDeliveryClean(delivery, {
      ...values,
      denominations: values.denominations.map((row) => (row.value === "20.00" ? { ...row, quantity: "1" } : row)),
    })).toBe(false);
  });

  it("is not clean when the split changes", () => {
    expect(isDeliveryClean(delivery, { ...values, requested_cash_amount: "9000" })).toBe(false);
    expect(isDeliveryClean(delivery, { ...values, cash_commission_percentage: "1" })).toBe(false);
  });

  it("is never clean for a stale delivery", () => {
    expect(isDeliveryClean({ ...delivery, is_stale: true }, values)).toBe(false);
  });
});

describe("getDeliveryReadiness", () => {
  const ok = cashFor("9800.00");
  const split = allCash();
  const ready = { hasImage: true, split, cash: ok, changeDeclared: false, clean: true, missingData: false, generated: false };
  const invalidSplit = deliverySplitPreview({ returnAmount: "9800.00", requestedCashAmount: "9800.00", cashCommissionPercentage: "" });
  const allTransfer = deliverySplitPreview({ returnAmount: "9800.00", requestedCashAmount: "0", cashCommissionPercentage: "0" });

  it.each([
    ["Recibo generado.", "ok", { generated: true }],
    ["Completa la fecha de entrega y quién entrega.", "neutral", { clean: false, missingData: true, cash: cashFor("9000.00") }],
    ["Corrige el reparto entre transferencia y efectivo.", "neutral", { clean: false, split: invalidSplit }],
    ["Ajusta el efectivo para poder guardar la entrega.", "neutral", { clean: false, cash: cashFor("9000.00") }],
    ["Guarda la entrega para poder generar el recibo.", "neutral", { clean: false, hasImage: false }],
    ["Falta la imagen para generar el Word.", "neutral", { hasImage: false, cash: cashFor("9800.00", "9801.50") }],
    ["El efectivo no coincide con el monto del documento.", "neutral", { cash: cashFor("9800.00", "9801.50") }],
    ["Indica que el cliente dará cambio.", "neutral", { cash: cashFor("9830.00") }],
    ["Todo listo para generar el recibo.", "ok", {}],
  ] as const)("hints «%s»", (hint, hintTone, overrides) => {
    expect(getDeliveryReadiness({ ...ready, ...overrides })).toMatchObject({ hint, hintTone });
  });

  it("does not report a generated receipt once something blocks it", () => {
    expect(getDeliveryReadiness({ ...ready, generated: true, clean: false }).hint).toBe(
      "Guarda la entrega para poder generar el recibo.",
    );
  });

  it("lists the checks in order without the change item when there is no excess", () => {
    expect(getDeliveryReadiness(ready).checks).toEqual([
      { id: "image", label: "Imagen asociada", ok: true },
      { id: "split", label: "Reparto válido: transferencia + efectivo", ok: true },
      { id: "cash", label: "Efectivo conciliado con lo que se entrega", ok: true },
      { id: "document", label: "Efectivo conciliado con el monto del documento", ok: true },
      { id: "saved", label: "Entrega guardada", ok: true },
    ]);
  });

  it("adds the change item only with an excess", () => {
    const checks = getDeliveryReadiness({ ...ready, cash: cashFor("9830.00") }).checks;

    expect(checks.map((check) => check.id)).toEqual(["image", "split", "cash", "document", "change", "saved"]);
    expect(checks[4]).toEqual({ id: "change", label: "Cambio al cliente declarado", ok: false });
    expect(getDeliveryReadiness({ ...ready, cash: cashFor("9830.00"), changeDeclared: true }).checks[4]?.ok).toBe(true);
  });

  it("drops the cash checks when everything goes by transfer", () => {
    const cash = getCashCheck({ split: allTransfer, documentAmount: "0.00", denominations: [] });

    expect(getDeliveryReadiness({ ...ready, split: allTransfer, cash }).checks.map((check) => check.id))
      .toEqual(["image", "split", "saved"]);
    expect(getDeliveryReadiness({ ...ready, split: allTransfer, cash }).canGenerate).toBe(true);
  });

  it("allows saving only with complete data, enough cash and unsaved changes", () => {
    expect(getDeliveryReadiness({ ...ready, clean: false }).canSave).toBe(true);
    expect(getDeliveryReadiness(ready).canSave).toBe(false);
    expect(getDeliveryReadiness({ ...ready, clean: false, missingData: true }).canSave).toBe(false);
    expect(getDeliveryReadiness({ ...ready, clean: false, cash: cashFor("9798.50") }).canSave).toBe(false);
    expect(getDeliveryReadiness({ ...ready, clean: false, cash: cashFor("9799.00") }).canSave).toBe(true);
    expect(getDeliveryReadiness({ ...ready, clean: false, split: invalidSplit }).canSave).toBe(false);
  });

  it("allows generating only when saved, with image, enough cash and declared change", () => {
    expect(getDeliveryReadiness(ready).canGenerate).toBe(true);
    expect(getDeliveryReadiness({ ...ready, clean: false }).canGenerate).toBe(false);
    expect(getDeliveryReadiness({ ...ready, hasImage: false }).canGenerate).toBe(false);
    expect(getDeliveryReadiness({ ...ready, cash: cashFor("9800.00", "9801.50") }).canGenerate).toBe(false);
    expect(getDeliveryReadiness({ ...ready, cash: cashFor("9830.00") }).canGenerate).toBe(false);
    expect(getDeliveryReadiness({ ...ready, cash: cashFor("9830.00"), changeDeclared: true }).canGenerate).toBe(true);
    expect(getDeliveryReadiness({ ...ready, split: invalidSplit }).canGenerate).toBe(false);
  });
});

describe("getDeliveryHeading", () => {
  const delivery = { is_stale: false } as Delivery;

  it("prepares a new delivery when none exists", () => {
    expect(getDeliveryHeading(null)).toEqual({ title: "Preparar entrega", saveLabel: "Guardar entrega" });
  });

  it("prepares a new delivery over a stale one", () => {
    expect(getDeliveryHeading({ ...delivery, is_stale: true })).toEqual({
      title: "Preparar nueva entrega",
      saveLabel: "Actualizar entrega",
    });
  });

  it("updates a current delivery", () => {
    expect(getDeliveryHeading(delivery)).toEqual({ title: "Preparar entrega", saveLabel: "Actualizar entrega" });
  });
});

describe("buildDeliveryFormValues", () => {
  it("starts a new delivery all in cash with an empty extra commission", () => {
    expect(buildDeliveryFormValues(null, "Maria", "200.00")).toMatchObject({
      requested_cash_amount: "200.00",
      cash_commission_percentage: "",
    });
  });

  it("keeps the saved requested cash, even when the return changed", () => {
    const delivery = { requested_cash_amount: "100.00", cash_commission_percentage: "10.00", denominations: [] } as unknown as Delivery;

    expect(buildDeliveryFormValues(delivery, "Maria", "250.00")).toMatchObject({
      requested_cash_amount: "100.00",
      cash_commission_percentage: "10.00",
    });
  });
});

describe("toUpsertDeliveryInput", () => {
  it("sends only the requested cash and the canonical percentage, never the derived amounts", () => {
    const input = toUpsertDeliveryInput({
      delivery_date: "2026-11-26",
      delivered_by_name: "Maria",
      received_by_name: "",
      requested_cash_amount: "100.5",
      cash_commission_percentage: "6.5",
      denominations: [],
    });

    expect(input).toMatchObject({ requested_cash_amount: "100.50", cash_commission_percentage: "6.50" });
    expect(Object.keys(input).sort()).toEqual([
      "cash_commission_percentage",
      "delivered_by_name",
      "delivery_date",
      "denominations",
      "received_by_name",
      "requested_cash_amount",
    ]);
  });
});

describe("sanitizeAmountInput", () => {
  it.each([
    ["100", "100"],
    ["1,250.50", "1250.50"],
    ["$90.999", "90.99"],
    ["-50", "50"],
    ["6.5.5", "6.55"],
    ["", ""],
    [".5", ".5"],
  ])("%j → %j", (raw, expected) => {
    expect(sanitizeAmountInput(raw)).toBe(expected);
  });
});
