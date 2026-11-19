import { afterEach, describe, expect, it, vi } from "vitest";
import {
  formatDate,
  formatDateTime,
  formatFileSize,
  formatMoney,
  formatShortDate,
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

describe("formatShortDate", () => {
  it("formats an ISO instant as dd/mm/aa, including Laravel microseconds", () => {
    expect(formatShortDate("2026-01-12T17:04:11.000000Z")).toBe("12/01/26");
    expect(formatShortDate("2026-09-05T12:00:00.000Z")).toBe("05/09/26");
  });

  it("uses the browser time zone because the value is an instant", async () => {
    for (const [timezone, expected] of [
      ["UTC", "01/01/26"],
      ["America/Mexico_City", "31/12/25"],
    ] as const) {
      process.env.TZ = timezone;
      vi.resetModules();
      const format = await import("@/lib/format");

      expect(format.formatShortDate("2026-01-01T03:00:00.000Z")).toBe(expected);
    }
  });

  it.each([null, "", "not a date"])("renders %s as an em dash", (value) => {
    expect(formatShortDate(value)).toBe("—");
  });
});

describe("formatFileSize", () => {
  it("formats bytes, kilobytes, and megabytes like the previous record helpers", () => {
    expect(formatFileSize(512)).toBe("512 B");
    expect(formatFileSize(1536)).toBe("1.5 KB");
    expect(formatFileSize(1_572_864)).toBe("1.5 MB");
  });
});
