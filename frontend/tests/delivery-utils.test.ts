import { describe, expect, it } from "vitest";
import {
  centsToAmount,
  denominationsTotalCents,
  toCents,
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
