import { describe, expect, it } from "vitest";
import {
  getRecordMetaLine,
  getRecordRowAction,
  getRecordRowLabel,
  getRecordSubtitle,
  hasActiveRecordFilters,
  selectValueToFilter,
} from "@/features/records/record-list-utils";
import { recordStatuses, type RecordListItem } from "@/features/records/types";

function detail(id: number, affiliation: string | null, branch: string | null) {
  return {
    id,
    affiliation,
    branch,
    income: null,
    commission_percentage: null,
    commission: null,
    return_amount: null,
    order: id,
  };
}

function record(overrides: Partial<RecordListItem> = {}): RecordListItem {
  return {
    id: 1,
    company: { id: 1, name: "Company ABC" },
    source: "IMAGE",
    status: "CONFIRMED",
    year: 2026,
    week: 38,
    week_start_date: "2026-08-28",
    week_end_date: "2026-09-15",
    image: null,
    ai_processing: null,
    return_total: "9800.00",
    details: [],
    ...overrides,
  };
}

describe("getRecordRowAction", () => {
  it("gives the editor an action only for NEEDS_REVIEW and ERROR", () => {
    const actions = Object.fromEntries(
      recordStatuses.map((status) => [status, getRecordRowAction({ status }, "EDITOR")]),
    );

    expect(actions).toEqual({
      PENDING: null,
      PROCESSING: null,
      NEEDS_REVIEW: "Revisar",
      ERROR: "Ver error",
      CONFIRMED: null,
      CANCELED: null,
    });
  });

  it("never gives the viewer an action", () => {
    for (const status of recordStatuses) {
      expect(getRecordRowAction({ status }, "VIEWER")).toBeNull();
    }
  });
});

describe("getRecordSubtitle", () => {
  it("shows affiliation and branch for a single movement", () => {
    const item = record({ details: [detail(1, "123456", "Guadalajara")] });

    expect(getRecordSubtitle(item)).toBe("Afil. 123456 · Guadalajara");
  });

  it("counts extra movements with singular and plural", () => {
    const one = record({ details: [detail(1, "123456", "Guadalajara"), detail(2, "999", "Zapopan")] });
    const many = record({
      details: [detail(1, "123456", "Guadalajara"), detail(2, "999", "Zapopan"), detail(3, "777", "Tonalá")],
    });

    expect(getRecordSubtitle(one)).toBe("Afil. 123456 · Guadalajara · +1 movimiento");
    expect(getRecordSubtitle(many)).toBe("Afil. 123456 · Guadalajara · +2 movimientos");
  });

  it("uses a waiting text when there are no movements", () => {
    expect(getRecordSubtitle(record({ status: "PENDING" }))).toBe("En cola para procesar");
    expect(getRecordSubtitle(record({ status: "PROCESSING" }))).toBe("Extrayendo datos de la imagen");
    expect(getRecordSubtitle(record({ status: "ERROR" }))).toBe("No se pudo leer la imagen");
  });

  it("is empty for other states without movements", () => {
    expect(getRecordSubtitle(record({ status: "NEEDS_REVIEW" }))).toBe("");
    expect(getRecordSubtitle(record({ status: "CANCELED" }))).toBe("");
  });
});

describe("getRecordMetaLine", () => {
  it("shows source, week and end date", () => {
    expect(getRecordMetaLine(record())).toBe("Imagen · Semana 38 · fin 15 sep 2026");
    expect(getRecordMetaLine(record({ source: "MANUAL" }))).toContain("Manual · Semana 38 · fin ");
  });

  it("explains a record without period", () => {
    expect(getRecordMetaLine(record({ week: null, week_end_date: null }))).toBe(
      "Imagen · Sin periodo todavía",
    );
  });
});

describe("getRecordRowLabel", () => {
  it("includes company, week and status", () => {
    expect(getRecordRowLabel(record({ status: "NEEDS_REVIEW" }))).toBe(
      "Abrir registro de Company ABC, semana 38, Por revisar",
    );
  });

  it("handles a record without company or week", () => {
    expect(getRecordRowLabel(record({ company: null, week: null, status: "PENDING" }))).toBe(
      "Abrir registro de Sin empresa asignada, Pendiente",
    );
  });
});

describe("hasActiveRecordFilters", () => {
  it("is false without search or filter values", () => {
    expect(hasActiveRecordFilters("", {})).toBe(false);
    expect(hasActiveRecordFilters("  ", { status: undefined, year: undefined })).toBe(false);
  });

  it("is true with a search or any filter", () => {
    expect(hasActiveRecordFilters("abc", {})).toBe(true);
    expect(hasActiveRecordFilters("", { status: "ERROR" })).toBe(true);
    expect(hasActiveRecordFilters("", { company_id: 3, year: undefined })).toBe(true);
  });
});

describe("selectValueToFilter", () => {
  it("turns the all option into no filter", () => {
    expect(selectValueToFilter("all")).toBeUndefined();
    expect(selectValueToFilter("")).toBeUndefined();
    expect(selectValueToFilter(null)).toBeUndefined();
    expect(selectValueToFilter(undefined)).toBeUndefined();
  });

  it("keeps real values", () => {
    expect(selectValueToFilter("NEEDS_REVIEW")).toBe("NEEDS_REVIEW");
    expect(selectValueToFilter("38")).toBe("38");
  });
});
