import { describe, expect, it } from "vitest";
import type { DeliveryFormValues } from "@/features/deliveries/schemas/delivery.schema";
import { officialDenominationValues, type Delivery } from "@/features/deliveries/types";
import {
  centsToAmount,
  denominationsTotalCents,
  getCashCheck,
  getDeliveryHeading,
  getDeliveryReadiness,
  isDeliveryClean,
  parseQuantityInput,
  stepQuantity,
  toCents,
  type CashCheck,
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

function cashFor(cashAmount: string, documentAmount = "9800.00", officialAmount = "9800.00"): CashCheck {
  return getCashCheck({ officialAmount, documentAmount, denominations: [{ value: "1.00", quantity: toCents(cashAmount) / 100 }] });
}

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
  const base = { officialAmount: "9800.00", documentAmount: "9800.00" };

  it("matches in the coincide scenario", () => {
    expect(getCashCheck({ ...base, denominations: breakdown(scenarios.coincide) })).toEqual({
      cashCents: 980000,
      shortOfficialCents: 0,
      shortDocumentCents: 0,
      excessCents: 0,
      tone: "ok",
      label: "Coincide con el monto del documento",
    });
  });

  it("reports the excess in the cambio scenario", () => {
    expect(getCashCheck({ ...base, denominations: breakdown(scenarios.cambio) })).toEqual({
      cashCents: 983000,
      shortOfficialCents: 0,
      shortDocumentCents: 0,
      excessCents: 3000,
      tone: "excess",
      label: "Excede por $30.00 · requiere declarar cambio",
    });
  });

  it("reports the shortfall in the faltante scenario", () => {
    expect(getCashCheck({ ...base, denominations: breakdown(scenarios.faltante) })).toEqual({
      cashCents: 968000,
      shortOfficialCents: 12000,
      shortDocumentCents: 12000,
      excessCents: 0,
      tone: "short",
      label: "Faltan $120.00 frente al monto del documento",
    });
  });

  it("tolerates a difference of exactly $1.00", () => {
    expect(cashFor("9799.00")).toMatchObject({ shortOfficialCents: 0, shortDocumentCents: 0, tone: "ok" });
    expect(cashFor("9801.00")).toMatchObject({ excessCents: 0, tone: "ok" });
  });

  it("flags a difference of more than $1.00", () => {
    const short = getCashCheck({ ...base, denominations: [{ value: "1.00", quantity: 9798 }, { value: "0.50", quantity: 1 }] });
    expect(short).toMatchObject({ shortOfficialCents: 150, shortDocumentCents: 150, tone: "short" });

    const excess = getCashCheck({ ...base, denominations: [{ value: "1.00", quantity: 9801 }, { value: "0.50", quantity: 1 }] });
    expect(excess).toMatchObject({ excessCents: 150, tone: "excess" });
  });

  it("checks the official return and the document amount separately", () => {
    expect(cashFor("9800.00", "9801.50")).toMatchObject({ shortOfficialCents: 0, shortDocumentCents: 150, tone: "short" });
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
    denominations: [{ value: "1000.00", quantity: 9 }, { value: "500.00", quantity: 1 }],
    created_at: null,
    updated_at: null,
  };
  const values: DeliveryFormValues = {
    delivery_date: "2026-11-18",
    delivered_by_name: "Maria Lopez",
    received_by_name: "",
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

  it("is never clean for a stale delivery", () => {
    expect(isDeliveryClean({ ...delivery, is_stale: true }, values)).toBe(false);
  });
});

describe("getDeliveryReadiness", () => {
  const ok = cashFor("9800.00");
  const ready = { hasImage: true, cash: ok, changeDeclared: false, clean: true, missingData: false, generated: false };

  it.each([
    ["Recibo generado.", "ok", { generated: true }],
    ["Completa la fecha de entrega y quién entrega.", "neutral", { clean: false, missingData: true, cash: cashFor("9000.00") }],
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
      { id: "official", label: "Efectivo conciliado con el retorno oficial", ok: true },
      { id: "document", label: "Efectivo conciliado con el monto del documento", ok: true },
      { id: "saved", label: "Entrega guardada", ok: true },
    ]);
  });

  it("adds the change item only with an excess", () => {
    const checks = getDeliveryReadiness({ ...ready, cash: cashFor("9830.00") }).checks;

    expect(checks.map((check) => check.id)).toEqual(["image", "official", "document", "change", "saved"]);
    expect(checks[3]).toEqual({ id: "change", label: "Cambio al cliente declarado", ok: false });
    expect(getDeliveryReadiness({ ...ready, cash: cashFor("9830.00"), changeDeclared: true }).checks[3]?.ok).toBe(true);
  });

  it("allows saving only with complete data, enough cash and unsaved changes", () => {
    expect(getDeliveryReadiness({ ...ready, clean: false }).canSave).toBe(true);
    expect(getDeliveryReadiness(ready).canSave).toBe(false);
    expect(getDeliveryReadiness({ ...ready, clean: false, missingData: true }).canSave).toBe(false);
    expect(getDeliveryReadiness({ ...ready, clean: false, cash: cashFor("9798.50") }).canSave).toBe(false);
    expect(getDeliveryReadiness({ ...ready, clean: false, cash: cashFor("9799.00") }).canSave).toBe(true);
  });

  it("allows generating only when saved, with image, enough cash and declared change", () => {
    expect(getDeliveryReadiness(ready).canGenerate).toBe(true);
    expect(getDeliveryReadiness({ ...ready, clean: false }).canGenerate).toBe(false);
    expect(getDeliveryReadiness({ ...ready, hasImage: false }).canGenerate).toBe(false);
    expect(getDeliveryReadiness({ ...ready, cash: cashFor("9800.00", "9801.50") }).canGenerate).toBe(false);
    expect(getDeliveryReadiness({ ...ready, cash: cashFor("9830.00") }).canGenerate).toBe(false);
    expect(getDeliveryReadiness({ ...ready, cash: cashFor("9830.00"), changeDeclared: true }).canGenerate).toBe(true);
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
