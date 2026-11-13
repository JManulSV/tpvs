import { describe, expect, it } from "vitest";
import {
  SLOW_READING_MS,
  formatElapsed,
  getAiReadingView,
  getDirtyCount,
  getFieldChip,
  getIssueCodes,
  getSaveBarState,
  getUploadProgressLabel,
  validateImageFile,
} from "@/features/records/record-capture-utils";

const MiB = 1024 * 1024;

describe("validateImageFile", () => {
  it("acepta JPEG, PNG y WebP", () => {
    expect(validateImageFile({ type: "image/jpeg", size: 1000 })).toBeNull();
    expect(validateImageFile({ type: "image/png", size: 1000 })).toBeNull();
    expect(validateImageFile({ type: "image/webp", size: 1000 })).toBeNull();
  });

  it("rechaza un tipo no permitido", () => {
    expect(validateImageFile({ type: "image/gif", size: 1000 })).toBe(
      "Selecciona una imagen JPEG, PNG o WebP.",
    );
  });

  it("acepta exactamente 10 MiB y rechaza 10 MiB + 1 byte", () => {
    expect(validateImageFile({ type: "image/png", size: 10 * MiB })).toBeNull();
    expect(validateImageFile({ type: "image/png", size: 10 * MiB + 1 })).toBe(
      "La imagen supera el límite de 10 MiB.",
    );
  });
});

describe("getUploadProgressLabel", () => {
  it("muestra el texto sin porcentaje cuando el progreso es null", () => {
    expect(getUploadProgressLabel(null)).toBe("Subiendo imagen…");
  });

  it("muestra el porcentaje cuando hay progreso", () => {
    expect(getUploadProgressLabel(0)).toBe("Subiendo imagen… 0%");
    expect(getUploadProgressLabel(42)).toBe("Subiendo imagen… 42%");
  });
});

describe("formatElapsed", () => {
  it("formatea m:ss", () => {
    expect(formatElapsed(0)).toBe("0:00");
    expect(formatElapsed(7 * 1000)).toBe("0:07");
    expect(formatElapsed(65 * 1000)).toBe("1:05");
    expect(formatElapsed(12 * 60 * 1000)).toBe("12:00");
  });
});

describe("getAiReadingView", () => {
  it("mapea los seis estados", () => {
    expect(getAiReadingView("PENDING", 0).step).toBe("queued");
    expect(getAiReadingView("PROCESSING", 0).step).toBe("reading");
    expect(getAiReadingView("NEEDS_REVIEW", 0).step).toBe("ready");
    expect(getAiReadingView("ERROR", 0).step).toBe("error");
    expect(getAiReadingView("CANCELED", 0).step).toBe("canceled");
    expect(getAiReadingView("CONFIRMED", 0).step).toBe("error");
  });

  it("muestra el tiempo solo mientras se lee", () => {
    expect(getAiReadingView("PROCESSING", 65 * 1000).elapsedLabel).toBe("1:05");
    expect(getAiReadingView("PENDING", 65 * 1000).elapsedLabel).toBeNull();
  });

  it("marca lenta la lectura solo a partir de SLOW_READING_MS", () => {
    expect(SLOW_READING_MS).toBe(10 * 60 * 1000);
    expect(getAiReadingView("PROCESSING", SLOW_READING_MS - 1).slow).toBe(false);
    expect(getAiReadingView("PROCESSING", SLOW_READING_MS).slow).toBe(true);
    expect(getAiReadingView("PENDING", SLOW_READING_MS).slow).toBe(true);
  });

  it("nunca marca lenta un estado final", () => {
    expect(getAiReadingView("ERROR", SLOW_READING_MS + 1).slow).toBe(false);
  });
});

describe("getFieldChip", () => {
  const imageReview = { isImageRecord: true, status: "NEEDS_REVIEW" as const };

  it("devuelve MISSING para valor vacío o nulo", () => {
    expect(getFieldChip({ ...imageReview, value: null, issueCodes: [] })).toBe("MISSING");
    expect(getFieldChip({ ...imageReview, value: "", issueCodes: [] })).toBe("MISSING");
    expect(
      getFieldChip({ ...imageReview, value: "x", issueCodes: ["COMPANY_NAME_MISSING"] }),
    ).toBe("MISSING");
  });

  it("devuelve INCONSISTENT para discrepancias de totales o fechas", () => {
    expect(
      getFieldChip({ ...imageReview, value: 1200, issueCodes: ["SOURCE_TOTAL_MISMATCH"] }),
    ).toBe("INCONSISTENT");
    expect(
      getFieldChip({ ...imageReview, value: "2026-09-15", issueCodes: ["INVERTED_WEEK_DATE_RANGE"] }),
    ).toBe("INCONSISTENT");
  });

  it("devuelve REVIEW para cualquier otro issue del campo", () => {
    expect(getFieldChip({ ...imageReview, value: 0.5, issueCodes: ["LOW_CONFIDENCE"] })).toBe("REVIEW");
    expect(getFieldChip({ ...imageReview, value: "x", issueCodes: ["INVALID_DATE"] })).toBe("REVIEW");
  });

  it("devuelve HIGH_CONFIDENCE para un valor sin issues", () => {
    expect(getFieldChip({ ...imageReview, value: "Empresa", issueCodes: [] })).toBe("HIGH_CONFIDENCE");
  });

  it("devuelve null para registros manuales o confirmados", () => {
    const base = { value: "Empresa", issueCodes: ["LOW_CONFIDENCE"] };
    expect(getFieldChip({ ...base, isImageRecord: false, status: "NEEDS_REVIEW" })).toBeNull();
    expect(getFieldChip({ ...base, isImageRecord: true, status: "CONFIRMED" })).toBeNull();
    expect(getFieldChip({ ...base, isImageRecord: false, status: "CONFIRMED" })).toBeNull();
  });
});

describe("getDirtyCount", () => {
  const initial = {
    company: "ABC",
    year: 2026,
    details: [
      { affiliation: "A", income: "100" },
      { affiliation: "B", income: "200" },
    ],
  };

  it("es 0 sin cambios", () => {
    expect(getDirtyCount(initial, structuredClone(initial))).toBe(0);
  });

  it("cuenta cada campo cambiado", () => {
    const current = structuredClone(initial);
    current.year = 2025;
    current.details[1].income = "250";
    expect(getDirtyCount(initial, current)).toBe(2);
  });

  it("cuenta un movimiento agregado o quitado como 1", () => {
    const added = structuredClone(initial);
    added.details.push({ affiliation: "C", income: "300" });
    expect(getDirtyCount(initial, added)).toBe(1);

    const removed = structuredClone(initial);
    removed.details.pop();
    expect(getDirtyCount(initial, removed)).toBe(1);
  });
});

describe("getSaveBarState", () => {
  it("no aparece sin cambios", () => {
    expect(getSaveBarState({ dirtyCount: 0, reason: "motivo", canEdit: true })).toEqual({
      visible: false,
      canSave: false,
    });
  });

  it("no permite guardar sin motivo", () => {
    expect(getSaveBarState({ dirtyCount: 1, reason: "   ", canEdit: true })).toEqual({
      visible: true,
      canSave: false,
    });
  });

  it("permite guardar con cambios y motivo", () => {
    expect(getSaveBarState({ dirtyCount: 1, reason: "Corrige la comisión", canEdit: true })).toEqual({
      visible: true,
      canSave: true,
    });
  });

  it("no permite guardar sin permiso de edición", () => {
    expect(getSaveBarState({ dirtyCount: 1, reason: "motivo", canEdit: false }).canSave).toBe(false);
  });
});

describe("getIssueCodes", () => {
  it("devuelve solo los códigos de la ruta pedida", () => {
    const issues = [
      { path: "details.0.income", code: "LOW_CONFIDENCE", severity: "review" as const, message: "" },
      { path: "details.0.income", code: "MISSING_VALUE", severity: "review" as const, message: "" },
      { path: "details.1.income", code: "UNCONVERTIBLE_NUMERIC", severity: "review" as const, message: "" },
    ];

    expect(getIssueCodes(issues, "details.0.income")).toEqual(["LOW_CONFIDENCE", "MISSING_VALUE"]);
    expect(getIssueCodes(issues, "record.year")).toEqual([]);
  });
});
