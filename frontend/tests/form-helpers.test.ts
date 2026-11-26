import { describe, expect, it } from "vitest";
import type { Delivery } from "@/features/deliveries/types";
import {
  buildDeliveryFormValues,
  getCashCheck,
  getDeliveryBlocks,
  isDeliveryFormField,
  toUpsertDeliveryInput,
} from "@/features/deliveries/utils";
import type { RecordDetailView } from "@/features/records/types";
import * as manualForm from "@/features/records/utils/manual-form";
import { initialManualRecordValues, toRecordDetailInputs } from "@/features/records/utils/manual-form";

const savedDelivery = {
  delivery_date: "2026-09-15",
  delivered_by_name: "Ana",
  received_by_name: null,
  denominations: [{ value: "500.00", quantity: 2 }],
} as unknown as Delivery;

describe("buildDeliveryFormValues", () => {
  it("starts a new delivery today, delivered by the current user, with every denomination at zero", () => {
    const values = buildDeliveryFormValues(null, "Luis");

    expect(values.delivered_by_name).toBe("Luis");
    expect(values.delivery_date).toMatch(/^\d{4}-\d{2}-\d{2}$/);
    expect(values.denominations.every((row) => row.quantity === "0")).toBe(true);
  });

  it("loads the saved delivery", () => {
    const values = buildDeliveryFormValues(savedDelivery, "Luis");

    expect(values).toMatchObject({ delivery_date: "2026-09-15", delivered_by_name: "Ana", received_by_name: "" });
    expect(values.denominations.find((row) => row.value === "500.00")?.quantity).toBe("2");
  });
});

describe("toUpsertDeliveryInput", () => {
  it("trims names, sends an empty receiver as null and quantities as integers", () => {
    const input = toUpsertDeliveryInput({
      delivery_date: "2026-09-15",
      delivered_by_name: "  Ana ",
      received_by_name: "  ",
      denominations: [{ value: "500.00", quantity: "3" }, { value: "0.50", quantity: "" }],
    });

    expect(input).toEqual({
      delivery_date: "2026-09-15",
      delivered_by_name: "Ana",
      received_by_name: null,
      denominations: [{ value: "500.00", quantity: 3 }, { value: "0.50", quantity: 0 }],
    });
  });
});

describe("isDeliveryFormField", () => {
  it("accepts visible fields and denomination quantities only", () => {
    expect(isDeliveryFormField("delivered_by_name")).toBe(true);
    expect(isDeliveryFormField("denominations.3.quantity")).toBe(true);
    expect(isDeliveryFormField("denominations")).toBe(false);
    expect(isDeliveryFormField("record")).toBe(false);
  });
});

describe("getDeliveryBlocks", () => {
  const cashOk = getCashCheck({ officialAmount: "0.00", documentAmount: "0.00", denominations: [] });

  it("is empty when nothing blocks", () => {
    expect(getDeliveryBlocks({ cash: cashOk, hasImage: true, mismatch: null, errors: [null, null] })).toEqual([]);
  });

  it("reports cash short of the official return before the document amount", () => {
    const cash = getCashCheck({ officialAmount: "500.00", documentAmount: "500.00", denominations: [] });
    const [first] = getDeliveryBlocks({ cash, hasImage: true, mismatch: null, errors: [] });

    expect(first.text).toContain("frente al retorno oficial");
    expect(first.code).toBe("CASH_MISMATCH");
  });

  it("lists missing image, server mismatch and request errors in order", () => {
    const blocks = getDeliveryBlocks({
      cash: cashOk,
      hasImage: false,
      mismatch: { expected_amount: "10.00", cash_amount: "5.00", difference: "5.00" },
      errors: ["Error al guardar", null],
    });

    expect(blocks.map((block) => block.code ?? block.text)).toEqual([
      "Adjunta la imagen del registro para generar el Word.",
      "CASH_MISMATCH",
      "Error al guardar",
    ]);
  });
});

const editedRecord = {
  company: { id: 4, name: "Comercial" },
  year: 2026,
  week: 38,
  week_start_date: "2026-09-14",
  week_end_date: "2026-09-20",
  commission_percentage: "6.50",
  details: [
    { id: 1, affiliation: "A1", bank: "LUUM", income: "100.00", order: 1 },
    { id: 2, affiliation: "A2", bank: "BBVA", income: "50.00", order: 2 },
  ],
} as unknown as RecordDetailView;

describe("manual record form helpers", () => {
  it("starts a new record with an empty global percentage and one empty movement", () => {
    const values = initialManualRecordValues();

    expect(values.company_id).toBe(0);
    expect(values.commission_percentage).toBe("");
    expect(values.details).toEqual([{ affiliation: "", bank: "", income: "" }]);
  });

  it("loads the global percentage and each movement's affiliation, bank and income", () => {
    const values = initialManualRecordValues(editedRecord);

    expect(values.commission_percentage).toBe("6.50");
    expect(values.details).toEqual([
      { affiliation: "A1", bank: "LUUM", income: "100.00" },
      { affiliation: "A2", bank: "BBVA", income: "50.00" },
    ]);
  });

  it("leaves the percentage empty for a provisional image record without one", () => {
    const values = initialManualRecordValues({ ...editedRecord, commission_percentage: null });

    expect(values.commission_percentage).toBe("");
  });

  it("sends trimmed affiliation and bank with the income", () => {
    expect(toRecordDetailInputs([
      { affiliation: " A1 ", bank: "LUUM ", income: "100.00" },
      { affiliation: "A2", bank: " BBVA", income: "50.00" },
    ])).toEqual([
      { affiliation: "A1", bank: "LUUM", income: "100.00" },
      { affiliation: "A2", bank: "BBVA", income: "50.00" },
    ]);
  });

  it("has no fixed-commission mode", () => {
    expect(manualForm).not.toHaveProperty("commissionModesFor");
  });
});
