import { afterEach, describe, expect, it } from "vitest";
import {
  formatDate,
  formatDateTime,
  formatFileSize,
  formatMoney,
} from "@/lib/format";

const originalTimezone = process.env.TZ;

afterEach(() => {
  if (originalTimezone === undefined) {
    delete process.env.TZ;
  } else {
    process.env.TZ = originalTimezone;
  }
});

describe("formatMoney", () => {
  it("formats numbers and numeric strings as Mexican pesos with two decimals", () => {
    expect(formatMoney("9800")).toBe("$9,800.00");
    expect(formatMoney(0)).toBe("$0.00");
  });

  it.each([null, undefined, ""])("renders %s as an em dash", (value) => {
    expect(formatMoney(value)).toBe("—");
  });
});

describe("formatDate", () => {
  it("formats a calendar date without shifting it across local time zones", () => {
    for (const timezone of ["UTC", "America/Los_Angeles", "Pacific/Kiritimati"]) {
      process.env.TZ = timezone;
      expect(formatDate("2026-09-01")).toMatch(/^1 sep\.? 2026$/);
    }
  });

  it("returns an em dash for null and the original value for invalid dates", () => {
    expect(formatDate(null)).toBe("—");
    expect(formatDate("2026-02-30")).toBe("2026-02-30");
    expect(formatDate("unknown")).toBe("unknown");
  });
});

describe("formatDateTime", () => {
  it("formats valid ISO date-time strings and preserves invalid input", () => {
    expect(formatDateTime("2026-09-01T12:30:00.000Z")).toMatch(/1 sep\.? 2026/);
    expect(formatDateTime("not a date")).toBe("not a date");
  });
});

describe("formatFileSize", () => {
  it("formats bytes, kilobytes, and megabytes like the previous record helpers", () => {
    expect(formatFileSize(512)).toBe("512 B");
    expect(formatFileSize(1536)).toBe("1.5 KB");
    expect(formatFileSize(1_572_864)).toBe("1.5 MB");
  });
});
