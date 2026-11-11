import { describe, expect, it } from "vitest";
import type { Delivery } from "@/features/deliveries/types";
import {
  getDeliveryCardState,
  getHeroNote,
  getHistoryDotClass,
  getRecordBanner,
  getRecordPeriodLine,
  getRecordTitle,
  getReportMonthName,
} from "@/features/records/record-detail-utils";
import { recordStatuses, type RecordListItem } from "@/features/records/types";

function record(overrides: Partial<RecordListItem> = {}): RecordListItem {
  return {
    id: 1,
    company: { id: 1, name: "Company ABC" },
    source: "IMAGE",
    status: "CONFIRMED",
    year: 2026,
    week: 38,
    week_start_date: "2026-09-09",
    week_end_date: "2026-09-15",
    image: null,
    ai_processing: null,
    details: [],
    ...overrides,
  };
}

function delivery(overrides: Partial<Delivery> = {}): Delivery {
  return {
    id: 1,
    record_id: 1,
    delivery_date: "2026-09-16",
    delivered_by_name: "Ana",
    received_by_name: null,
    prepared_return_amount: "9800.00",
    current_return_amount: "9800.00",
    is_stale: false,
    denominations: [],
    created_at: null,
    updated_at: null,
    ...overrides,
  };
}

describe("getRecordTitle", () => {
  it("uses the company name or a fallback", () => {
    expect(getRecordTitle(record())).toBe("Company ABC");
    expect(getRecordTitle(record({ company: null }))).toBe("Registro sin empresa");
  });
});

describe("getRecordPeriodLine", () => {
  it("formats the week range", () => {
    expect(getRecordPeriodLine(record())).toBe("Semana 38 · 9 sep 2026 – 15 sep 2026");
  });

  it("handles a record without week", () => {
    expect(getRecordPeriodLine(record({ week: null }))).toBe("Sin periodo todavía");
  });
});

describe("getReportMonthName", () => {
  it("uses the month of the end date when the week crosses months", () => {
    expect(getReportMonthName("2026-09-02")).toBe("septiembre");
  });

  it("returns null for null or invalid values", () => {
    expect(getReportMonthName(null)).toBeNull();
    expect(getReportMonthName("15/09/2026")).toBeNull();
    expect(getReportMonthName("2026-13-01")).toBeNull();
  });
});

describe("getHeroNote", () => {
  it("returns a note for each of the six statuses", () => {
    expect(recordStatuses).toHaveLength(6);

    for (const status of recordStatuses) {
      expect(getHeroNote(status).length).toBeGreaterThan(0);
    }

    expect(getHeroNote("NEEDS_REVIEW")).toBe("Pendiente de revisión. Todavía no cuenta en los totales.");
    expect(getHeroNote("PENDING")).toBe(getHeroNote("PROCESSING"));
    expect(getHeroNote("ERROR")).toBe("El procesamiento falló y no hay importes.");
  });
});

describe("getRecordBanner", () => {
  it("shows the extraction info banner to the editor on image records to review", () => {
    expect(getRecordBanner({ status: "NEEDS_REVIEW", source: "IMAGE" }, true)).toEqual({
      tone: "info",
      title: "Información extraída automáticamente. Revisa antes de confirmar.",
      text: null,
    });
  });

  it("shows the pending review banner for manual records and for viewers", () => {
    const expected = {
      tone: "review",
      title: "Pendiente de revisión.",
      text: "Este registro todavía no está confirmado y no cuenta en los totales.",
    };

    expect(getRecordBanner({ status: "NEEDS_REVIEW", source: "MANUAL" }, true)).toEqual(expected);
    expect(getRecordBanner({ status: "NEEDS_REVIEW", source: "IMAGE" }, false)).toEqual(expected);
    expect(getRecordBanner({ status: "NEEDS_REVIEW", source: "MANUAL" }, false)).toEqual(expected);
  });

  it("covers processing, error, canceled and confirmed", () => {
    expect(getRecordBanner({ status: "PENDING", source: "IMAGE" }, true)?.title).toBe("El registro se está procesando.");
    expect(getRecordBanner({ status: "PROCESSING", source: "IMAGE" }, false)?.tone).toBe("info");
    expect(getRecordBanner({ status: "ERROR", source: "IMAGE" }, true)?.tone).toBe("error");
    expect(getRecordBanner({ status: "CANCELED", source: "IMAGE" }, true)?.tone).toBe("cancel");
    expect(getRecordBanner({ status: "CONFIRMED", source: "IMAGE" }, true)).toBeNull();
  });
});

describe("getDeliveryCardState", () => {
  it("blocks records that are not confirmed", () => {
    expect(getDeliveryCardState("NEEDS_REVIEW", undefined, true)).toEqual({
      chip: "Bloqueada",
      tone: "none",
      actionLabel: null,
    });
  });

  it("handles a confirmed record without delivery", () => {
    expect(getDeliveryCardState("CONFIRMED", null, true)).toEqual({
      chip: "Sin entrega",
      tone: "none",
      actionLabel: "Preparar entrega",
    });
    expect(getDeliveryCardState("CONFIRMED", null, false).actionLabel).toBeNull();
  });

  it("handles a stale delivery", () => {
    const stale = delivery({ is_stale: true });

    expect(getDeliveryCardState("CONFIRMED", stale, true)).toEqual({
      chip: "Desactualizada",
      tone: "stale",
      actionLabel: "Preparar nueva entrega",
    });
    expect(getDeliveryCardState("CONFIRMED", stale, false).actionLabel).toBe("Ver entrega");
  });

  it("handles a current delivery", () => {
    expect(getDeliveryCardState("CONFIRMED", delivery(), true)).toEqual({
      chip: "Preparada",
      tone: "ready",
      actionLabel: "Editar entrega",
    });
    expect(getDeliveryCardState("CONFIRMED", delivery(), false).actionLabel).toBe("Ver entrega");
  });
});

describe("getHistoryDotClass", () => {
  it("colors record creation and status changes", () => {
    expect(getHistoryDotClass("record_created", null)).toBe("bg-status-pending");
    expect(getHistoryDotClass("status", "CONFIRMED")).toBe("bg-status-confirmed");
    expect(getHistoryDotClass("status", "NEEDS_REVIEW")).toBe("bg-status-needs-review");
    expect(getHistoryDotClass("status", "CANCELED")).toBe("bg-status-canceled");
  });

  it("colors delivery fields", () => {
    for (const field of [
      "document_generated",
      "delivery_date",
      "delivered_by_name",
      "received_by_name",
      "prepared_return_amount",
      "denominations[500.00]",
    ]) {
      expect(getHistoryDotClass(field, "x")).toBe("bg-status-processing");
    }
  });

  it("falls back to the accent color", () => {
    expect(getHistoryDotClass("week", "38")).toBe("bg-accent-foreground");
    expect(getHistoryDotClass("status", "ERROR")).toBe("bg-accent-foreground");
  });
});
