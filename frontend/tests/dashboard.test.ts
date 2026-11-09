import { afterEach, describe, expect, it } from "vitest";
import {
  formatRelativeTime,
  getGreeting,
  getMonthName,
  getReviewHeadline,
  getStatusCellHref,
} from "@/features/dashboard/utils";
import { BUSINESS_TIME_ZONE, getZonedDateParts, toZonedDateString } from "@/lib/business-time";

// Mexico City is UTC-6 all year (no DST since 2022).
const mx = (local: string) => new Date(`${local}-06:00`);

const originalTimezone = process.env.TZ;

afterEach(() => {
  if (originalTimezone === undefined) {
    delete process.env.TZ;
  } else {
    process.env.TZ = originalTimezone;
  }
});

describe("business time", () => {
  it("reads calendar parts in Mexico City regardless of the runtime zone", () => {
    process.env.TZ = "Asia/Tokyo";
    const instant = new Date("2026-12-01T03:00:00Z");

    expect(getZonedDateParts(instant)).toEqual({ year: 2026, month: 11, day: 30, hour: 21, minute: 0 });
    expect(toZonedDateString(instant)).toBe("2026-11-30");
  });
});

describe("getGreeting", () => {
  it.each([
    ["2026-11-09T04:59:00", "Buenas noches"],
    ["2026-11-09T05:00:00", "Buenos días"],
    ["2026-11-09T11:59:00", "Buenos días"],
    ["2026-11-09T12:00:00", "Buenas tardes"],
    ["2026-11-09T18:59:00", "Buenas tardes"],
    ["2026-11-09T19:00:00", "Buenas noches"],
    ["2026-11-09T00:00:00", "Buenas noches"],
  ])("at %s in Mexico City returns %s", (local, expected) => {
    expect(getGreeting(mx(local), BUSINESS_TIME_ZONE)).toBe(expected);
  });

  it("uses the given time zone, not UTC", () => {
    // 15:00 UTC is 09:00 in Mexico City.
    expect(getGreeting(new Date("2026-11-09T15:00:00Z"), BUSINESS_TIME_ZONE)).toBe("Buenos días");
  });
});

describe("getReviewHeadline", () => {
  it("handles zero, singular and plural", () => {
    expect(getReviewHeadline(0)).toBe("No tienes registros por revisar");
    expect(getReviewHeadline(1)).toBe("Tienes 1 registro por revisar");
    expect(getReviewHeadline(4)).toBe("Tienes 4 registros por revisar");
  });
});

describe("formatRelativeTime", () => {
  const now = mx("2026-11-09T15:00:00");

  it("returns 'hace un momento' under one minute", () => {
    expect(formatRelativeTime(mx("2026-11-09T14:59:30").toISOString(), now)).toBe("hace un momento");
  });

  it("returns minutes under one hour", () => {
    expect(formatRelativeTime(mx("2026-11-09T14:48:00").toISOString(), now)).toBe("hace 12 min");
    expect(formatRelativeTime(mx("2026-11-09T14:00:01").toISOString(), now)).toBe("hace 59 min");
  });

  it("returns hours under one day", () => {
    expect(formatRelativeTime(mx("2026-11-09T14:00:00").toISOString(), now)).toBe("hace 1 h");
    expect(formatRelativeTime(mx("2026-11-08T15:00:01").toISOString(), now)).toBe("hace 23 h");
  });

  it("returns 'ayer' for the previous calendar day in Mexico City", () => {
    expect(formatRelativeTime(mx("2026-11-08T08:00:00").toISOString(), now)).toBe("ayer");
  });

  it("uses the Mexico City calendar day, not UTC", () => {
    // 2026-11-07 05:00 UTC is 2026-11-06 23:00 in Mexico City: not yesterday.
    // 2026-11-08 07:00 UTC is 2026-11-08 01:00 in Mexico City: yesterday, though it is the 8th in UTC too.
    const lateNight = mx("2026-11-09T09:00:00");
    expect(formatRelativeTime("2026-11-07T05:00:00Z", lateNight)).not.toBe("ayer");
    expect(formatRelativeTime("2026-11-08T07:00:00Z", lateNight)).toBe("ayer");
  });

  it("returns the formatted date for older activity", () => {
    expect(formatRelativeTime(mx("2026-11-05T10:00:00").toISOString(), now)).toBe("5 nov 2026");
  });

  it("treats future timestamps as just now", () => {
    expect(formatRelativeTime(mx("2026-11-09T15:00:20").toISOString(), now)).toBe("hace un momento");
  });
});

describe("getMonthName", () => {
  it("returns Spanish month names", () => {
    expect(getMonthName(1)).toBe("enero");
    expect(getMonthName(11)).toBe("noviembre");
    expect(getMonthName(13)).toBe("");
  });
});

describe("getStatusCellHref", () => {
  const period = { year: 2026, month: 11 };

  it("links work-queue cells by status only", () => {
    expect(getStatusCellHref("needs_review", period)).toBe("/records?status=NEEDS_REVIEW");
    expect(getStatusCellHref("error", period)).toBe("/records?status=ERROR");
    expect(getStatusCellHref("pending", period)).toBe("/records?status=PENDING");
    expect(getStatusCellHref("processing", period)).toBe("/records?status=PROCESSING");
  });

  it("links monthly cells with year and month", () => {
    expect(getStatusCellHref("confirmed_month", period)).toBe("/records?status=CONFIRMED&year=2026&month=11");
    expect(getStatusCellHref("canceled_month", period)).toBe("/records?status=CANCELED&year=2026&month=11");
  });
});
